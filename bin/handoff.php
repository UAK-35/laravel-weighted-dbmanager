#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/handoff.php — write the handoff note's pending set and commit listing from git.
 *
 * WHAT THIS IS FOR
 * ----------------
 *   `HANDOFF.md` describes a moment: which files this checkout has that its register does not
 *   carry, which commit the register is, how many commits no remote has, how they break down by
 *   type, and the most recent of them by id and subject. Every one of those is a question git
 *   answers, and until now the answer was copied in by hand — which is why the note's own words
 *   call a count of uncommitted paths a fact about one working tree, and why its guard had to be
 *   told not to bind that number to anything.
 *
 *   So the numbers have one home — git, read through `tests/Support/Handoff` — and this command
 *   writes them into the note. A re-take runs it and the blocks follow the tree; whether a human
 *   remembered to re-read `git status` stops being part of whether the note is true.
 *
 * WHAT IT WRITES, AND WHAT IT LEAVES ALONE
 * ----------------------------------------
 *   Three blocks, each delimited in the note by `<!-- handoff:NAME -->` and `<!-- /handoff:NAME -->`:
 *
 *     pending    section 1's table — the files this checkout has that the register does not carry,
 *                with the words `Handoff::PENDING_DESCRIPTIONS` declares for each
 *     unpushed   section 2's table — the branch, the register, the remote tip, the count and the
 *                breakdown by type
 *     log        section 2's listing — the most recent unpushed commits, and the sentence above
 *                them that counts them
 *
 *   Everything else in the note is prose a session writes: what a batch is, why it was done, what
 *   its controls measured. The renderer touches the bytes between the markers and not one byte
 *   outside them.
 *
 * WHY A GENERATOR IS SAFE HERE
 * ----------------------------
 *   The note's guard renders the same three blocks from the same derivations and asserts the file
 *   says what they say, so a hand-edited block is red in the suite rather than silently believed —
 *   and the register a *check* renders at is the one the note already names, not `HEAD`, because a
 *   note describes a register older than the commit that lands it. That is also why nothing is
 *   written unless every block could be rendered: a half-rendered note is worse than a stale one.
 *
 *   The one input git cannot answer is the prose column of section 1's table, and it is declared
 *   beside the renderer rather than carried over from the table it is about: a file the register
 *   does not carry and that has no line declared for it is refused by name.
 *
 * USAGE
 * -----
 *   php bin/handoff.php                     write the blocks at the commit this checkout is on
 *   php bin/handoff.php --register=<commit> write them at another commit
 *   php bin/handoff.php --check             compare, write nothing, exit 1 when a block is stale
 *   php bin/handoff.php --help
 *
 * EXIT CODES
 * ----------
 *   0 written, or every block already in step under --check; 1 a block is stale, or could not be
 *   rendered, or the note could not be read or written; 2 usage error.
 */

use Uak35\WeightedDbManager\Tests\Support\Handoff;

$root = str_replace('\\', '/', dirname(__DIR__));
$check = false;
$register = null;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        usage();
        exit(0);
    }

    if ($argument === '--check') {
        $check = true;
        continue;
    }

    if (str_starts_with($argument, '--register=')) {
        $register = substr($argument, 11);
        continue;
    }

    fwrite(STDERR, "Unknown option: {$argument}" . PHP_EOL . PHP_EOL);
    usage();
    exit(2);
}

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, 'No vendor/autoload.php — run `composer install` first.' . PHP_EOL);
    exit(1);
}

require $root . '/vendor/autoload.php';

if (!is_file($root . '/HANDOFF.md')) {
    fwrite(STDERR, "Not a package root — no HANDOFF.md under {$root}." . PHP_EOL);
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Deriving: every block, before a byte is written
// ─────────────────────────────────────────────────────────────────────────────

// A write re-takes the note, so its register is the commit this checkout is standing on — the row
// being written is one of the things that moves. A check compares the note with itself, so it
// renders at the register the note already names, and a note committed at one commit is not
// reported stale by every commit made after it.
try {
    $blocks = Handoff::blocks();
    $rendered = [];

    foreach ($blocks as $name) {
        $rendered[$name] = Handoff::rendered($name, $register ?? ($check ? null : Handoff::head()));
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'The blocks could not be rendered: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$source = @file_get_contents($root . '/HANDOFF.md');

if ($source === false) {
    fwrite(STDERR, 'HANDOFF.md could not be read, and nothing was written.' . PHP_EOL);
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Writing
// ─────────────────────────────────────────────────────────────────────────────

$outOfStep = [];
$report = [];
$note = $source;

foreach ($rendered as $name => $block) {
    $spliced = splice($note, $name, $block);

    if ($spliced === null) {
        fwrite(STDERR, sprintf(
            'HANDOFF.md has no `%s` block: the markers that delimit it are gone, so the bytes this would write '
            .'are not marked — put back `<!-- handoff:%s -->` and `<!-- /handoff:%s -->` around the region.'
            .PHP_EOL,
            $name,
            $name,
            $name,
        ));
        exit(1);
    }

    $stale = $spliced !== $note;
    $note = $spliced;

    if ($stale) {
        $outOfStep[] = $name;
    }

    $report[] = sprintf('  %-10s %s', $name, $stale ? 'out of step' : 'in step');
}

echo implode(PHP_EOL, $report) . PHP_EOL;

if ($check) {
    echo $outOfStep === []
        ? sprintf('%d block(s) in HANDOFF.md, all in step with git.', count($rendered)) . PHP_EOL
        : sprintf('%d block(s) out of step — run `php bin/handoff.php` to render them.', count($outOfStep)) . PHP_EOL;

    exit($outOfStep === [] ? 0 : 1);
}

if ($outOfStep === []) {
    echo sprintf('%d block(s) already in step; nothing was written.', count($rendered)) . PHP_EOL;

    exit(0);
}

if (@file_put_contents($root . '/HANDOFF.md', $note) === false) {
    fwrite(STDERR, 'HANDOFF.md could not be written.' . PHP_EOL);
    exit(1);
}

echo sprintf('%d block(s) written from git: %s.', count($outOfStep), implode(', ', $outOfStep)) . PHP_EOL;

exit(0);

/**
 * One block replaced between its markers — or null when either marker is missing.
 *
 * The markers are kept rather than written: they are the note's own, and a renderer that rewrote
 * them would be free to move the region the next run has to find.
 */
function splice(string $source, string $name, string $block): ?string
{
    $open = '<!-- handoff:' . $name . ' -->';
    $close = '<!-- /handoff:' . $name . ' -->';

    $from = strpos($source, $open);
    $to = strpos($source, $close);

    if ($from === false || $to === false || $to < $from) {
        return null;
    }

    $start = $from + strlen($open);

    // `\n` rather than `PHP_EOL`: the note is `eol=lf` in `.gitattributes`, so a block written with
    // the platform's ending would be a rewrite of every line of it on every run — and on Windows it
    // would compare as out of step for ever, since the guard reads the file's lines and renders its
    // own with `\n`.
    return substr($source, 0, $start) . "\n" . $block . "\n" . substr($source, $to);
}

function usage(): void
{
    echo <<<'TEXT'
    bin/handoff.php — write the handoff note's pending set and commit listing from git.

    Usage:
      php bin/handoff.php                     write the blocks at the commit this checkout is on
      php bin/handoff.php --register=<commit> write them at another commit
      php bin/handoff.php --check             compare, write nothing, exit 1 when a block is stale
      php bin/handoff.php --help

    The blocks are the `pending`, `unpushed` and `log` regions of HANDOFF.md, each delimited by
    `<!-- handoff:NAME -->` … `<!-- /handoff:NAME -->`. The derivations are in tests/Support/Handoff,
    which is also what the note's guard reads, so a block this writes is a block the suite checks.

    Exit codes: 0 written or in step, 1 stale or unrenderable, 2 usage error.
    TEXT;
    echo PHP_EOL;
}
