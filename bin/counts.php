#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/counts.php — write the counts the records state in prose from the providers that own them.
 *
 * WHAT THIS IS FOR
 * ----------------
 *   Several design records state a number about the code: how many cells a matrix has, how many
 *   documented cases it is written as, how many rows a table has. Those sentences are read by
 *   people, so they stay sentences — but until now a person was the one who kept the number in
 *   step, and the record that names this problem is `docs/documented-exit-codes.md`: the flip's
 *   matrix sat at "fifteen" from the day the JSON work added a sixteenth cell, and nothing failed,
 *   because nothing read the sentence.
 *
 *   So the number has one home — the derivation, in `ProseNumbersTest` and
 *   `tests/Support/DocumentedExitCounts` — and this command writes it into the prose. Run it after
 *   changing a matrix, a documented-case map or a README table; then the sentence is an output.
 *
 * WHY A GENERATOR IS SAFE HERE
 * ----------------------------
 *   A generator that rewrites prose is only as safe as its patterns, and these have a property no
 *   hand-written rewriter has: `ProseNumbersTest` asserts that *every* place each pattern matches
 *   states the derived number. A pattern that also matched an unrelated sentence — a historical
 *   "fifteen" in the same record, say — would already be a failing test, because that sentence's
 *   word would have to equal the number the code has. So the occurrences this command rewrites are
 *   exactly the ones the suite has proven to be claims about the code.
 *
 *   Nothing is written unless every claim could be resolved: a pattern that stops matching, a
 *   record that cannot be read, or two claims that point at one word are reported and the run
 *   stops without touching a file. A half-rendered record is worse than a stale one.
 *
 * WHAT IT WILL NOT DO
 * -------------------
 *   It does not invent a claim, and it does not touch a number no claim covers. A pattern that
 *   stops matching is an error rather than a skip: the sentence the count lived in has been
 *   reworded, and which words to rewrite is then a decision for whoever reworded it.
 *
 * USAGE
 * -----
 *   php bin/counts.php            write every record from its derivations
 *   php bin/counts.php --check    compare, write nothing, exit 1 when a record is out of step
 *   php bin/counts.php --help
 *
 * EXIT CODES
 * ----------
 *   0 written, or already in step under --check; 1 a record is out of step or could not be read or
 *   written; 2 usage error.
 */

use Uak35\WeightedDbManager\Tests\Support\NumberWords;
use Uak35\WeightedDbManager\Tests\Unit\Docs\ProseNumbersTest;

$root = str_replace('\\', '/', dirname(__DIR__));
$check = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        usage();
        exit(0);
    }

    if ($argument === '--check') {
        $check = true;
        continue;
    }

    fwrite(STDERR, "Unknown option: {$argument}" . PHP_EOL . PHP_EOL);
    usage();
    exit(2);
}

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "No vendor/autoload.php — run `composer install` first." . PHP_EOL);
    exit(1);
}

require $root . '/vendor/autoload.php';

if (!is_dir($root . '/src')) {
    fwrite(STDERR, "Not a package root — no src/ under {$root}." . PHP_EOL);
    exit(1);
}

try {
    $claims = ProseNumbersTest::proseNumbers();
} catch (Throwable $e) {
    fwrite(STDERR, 'The claims could not be derived: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($claims === []) {
    fwrite(STDERR, 'No claims to render, and a renderer with nothing to render is a silent one.' . PHP_EOL);
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Reading: one pass per record, so its bytes are read once and written once
// whatever number of claims point at it
// ─────────────────────────────────────────────────────────────────────────────

$records = [];

foreach ($claims as $claim => $definition) {
    $records[$definition['record']][] = [$claim, $definition['pinned'], $definition['expected'], $definition['counts']];
}

$failures = [];
$documents = [];
$counted = 0;

foreach ($records as $record => $recordClaims) {
    $path = $root . '/' . $record;
    $source = @file_get_contents($path);

    if ($source === false) {
        $failures[] = "cannot read {$record}, and a count in it cannot be rendered";

        continue;
    }

    $replacements = [];

    foreach ($recordClaims as [$claim, $pattern, $expected, $counts]) {
        $matches = [];
        $found = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

        if ($found === 0 || $found === false) {
            $failures[] = "{$record} no longer states \"{$claim}\" ({$pattern}), so the sentence its count lives in has been reworded";

            continue;
        }

        $word = NumberWords::toWord($expected);

        foreach ($matches[1] as [$stated, $offset]) {
            $counted++;

            if (!NumberWords::knows($stated)) {
                $failures[] = "{$record} states \"{$stated}\" where the derivation has {$expected} ({$counts})";

                continue;
            }

            // Compared as a number rather than as a string, and rewritten as a word with the
            // record's own capitalisation: a count that opens a sentence ("Three keys, not
            // one …") states the same number as one mid-sentence, and the capital is prose.
            if (NumberWords::toInt($stated) === $expected) {
                continue;
            }

            $replacements[] = [
                'offset' => $offset,
                'length' => strlen($stated),
                'from' => $stated,
                'to' => ctype_upper($stated[0]) ? ucfirst($word) : $word,
                'counts' => $counts,
            ];
        }
    }

    // Descending by offset, so an earlier splice cannot move a later one. Two claims pointing at
    // one word is a mistake in the claim table rather than a rendering decision, and it is
    // reported instead of half-applied.
    usort($replacements, static fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);

    $previous = null;

    foreach ($replacements as $replacement) {
        if ($previous !== null && $replacement['offset'] + $replacement['length'] > $previous) {
            $failures[] = "{$record} has two counts on one word at offset {$replacement['offset']}, so the claims overlap";
        }

        $previous = $replacement['offset'];
    }

    $documents[$record] = ['path' => $path, 'source' => $source, 'replacements' => $replacements];
}

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . PHP_EOL);
}

if ($failures !== []) {
    fwrite(STDERR, 'Nothing was written: a record that cannot be rendered in full is left as it is.' . PHP_EOL);
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Writing
// ─────────────────────────────────────────────────────────────────────────────

$outOfStep = 0;
$rewritten = 0;
$report = [];

foreach ($documents as $record => $document) {
    $replacements = $document['replacements'];
    $total = 0;

    foreach ($records[$record] as [, $pattern]) {
        $total += (int) preg_match_all($pattern, $document['source']);
    }

    if ($replacements === []) {
        $report[] = sprintf('%-42s %d count(s), in step', $record, $total);

        continue;
    }

    $outOfStep += count($replacements);
    $source = $document['source'];

    foreach ($replacements as $replacement) {
        $source = substr_replace($source, $replacement['to'], $replacement['offset'], $replacement['length']);

        // The prefix is untouched by the splices before this one — they are all higher up the
        // file — so the line number is the original line either way.
        $report[] = sprintf(
            '  %s line %d: %s -> %s   (%s)',
            $record,
            substr_count(substr($source, 0, $replacement['offset']), "\n") + 1,
            $replacement['from'],
            $replacement['to'],
            $replacement['counts'],
        );
    }

    if ($check) {
        continue;
    }

    if (@file_put_contents($document['path'], $source) === false) {
        fwrite(STDERR, "could not write {$record}" . PHP_EOL);
        exit(1);
    }

    $rewritten += count($replacements);
}

if ($outOfStep > 0) {
    echo implode(PHP_EOL, $report) . PHP_EOL;
}

if ($check) {
    echo $outOfStep === 0
        ? sprintf('%d count(s) across %d record(s), all in step with their derivations.', $counted, count($documents)) . PHP_EOL
        : sprintf('%d count(s) out of step — run `php bin/counts.php` to render them.', $outOfStep) . PHP_EOL;

    exit($outOfStep === 0 ? 0 : 1);
}

echo sprintf('%d count(s) across %d record(s); %d rewritten.', $counted, count($documents), $rewritten) . PHP_EOL;

exit(0);

function usage(): void
{
    echo <<<'TEXT'
    bin/counts.php — render the counts the records state in prose from the code that owns them.

    Usage:
      php bin/counts.php            write every record from its derivations
      php bin/counts.php --check    compare, write nothing, exit 1 when out of step
      php bin/counts.php --help

    The claims live in ProseNumbersTest and tests/Support/DocumentedExitCounts, which is also
    where the derivations are; this command only writes what they derive.

    Exit codes: 0 in step or written, 1 out of step or unreadable, 2 usage error.
    TEXT;
    echo PHP_EOL;
}
