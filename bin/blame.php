#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/blame.php — which signal names one symbol, and what bump it contributed.
 *
 * THE QUESTION IT ANSWERS
 * -----------------------
 *   A bump is decided by four signals, and once a release is being weighed the only
 *   thing left to ask is which of them a particular change reached. A `feat:` commit
 *   with no changelog entry, a changelog entry with no surface change, a public
 *   method that vanished: each is caught by a different reader, each weighs
 *   differently, and none of it is visible from the version number the weighing
 *   produced.
 *
 *       php bin/blame.php Uak35\\WeightedDbManager\\WeightedServiceProvider
 *       php bin/blame.php WeightedServiceProvider::boot
 *       php bin/blame.php configuration
 *       php bin/blame.php 'weighted-db'
 *
 *   The answer is deliberately mechanical: every signal is asked whether any line of
 *   its evidence contains the name, and the two surface maps are asked whether any
 *   key a consumer can name contains it. Nothing is parsed out of the query, so
 *   `weight` matches `weighed`, `weighting` and `weigh()` alike — which is what
 *   somebody who remembers half a name wants. A name that reaches no signal is
 *   therefore a real answer rather than an error, and it is the one this command
 *   exists to give: the bump moved, and it did not move because of this.
 *
 * WHY IT DOES NOT RE-DERIVE ANYTHING
 * ----------------------------------
 *   `weigh()` in bin/weighing.php is called exactly as `bin/release.php --weigh
 *   --dry-run` calls it — the same CHANGELOG body, the same tag, the same base — and
 *   the report reads the very signals that call produced. So the two cannot disagree
 *   about what the bump is, and "this signal carried it" is a fact about the release
 *   decision rather than a second opinion about it. A signal that names the symbol
 *   and holds the top severity is the bump's cause; one that names it below the top
 *   is evidence the bump moved past, which is a different sentence and gets said as
 *   one.
 *
 *   Both halves of the surface — `src/` and `config/` — are asked twice: once about
 *   the lines they changed, and once about whether they hold the name at all. A
 *   symbol that is at the tag and unchanged since it is in neither signal's evidence,
 *   and that is the answer too. Without the second question a name nothing had
 *   changed would look the same as a name nothing could find.
 *
 *   They are read with the files behind each name as well, which is the one thing the
 *   maps cannot say about a name they hold: a name two files declare is in the map
 *   once, from the first of them, so "the surface holds it" is true and incomplete.
 *   A `Shadowed names` section names the files behind each such name the query
 *   reached — the same sentence the release plan prints about the whole surface,
 *   narrowed to the names asked about, which is why the plan's version of it is not
 *   among the notes reprinted above.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 *   No preconditions. It does not read the branch, refuse a dirty tree or ask CI:
 *   those questions belong to bin/release.php, and a question about one symbol is
 *   asked mid-edit, which is exactly when a dirty tree is the normal state. It
 *   writes nothing, commits nothing, and needs no tag — with no tag at all the
 *   commits and public-API signals have no base to diff against, which the report
 *   says rather than inventing one.
 *
 * USAGE
 * -----
 *   php bin/blame.php SYMBOL            which signal names it, and what it weighed
 *   php bin/blame.php SYMBOL --root=PATH  ask about another checkout
 *   php bin/blame.php --help
 *
 * EXIT CODES
 * ----------
 *   0 something in the weighing or in either surface names it, 1 nothing does, 2 a
 *   usage error. The exit code is the scripted form of the answer, so a shell can
 *   branch on it without reading the report.
 */

require __DIR__ . '/weighing.php';

$root = str_replace('\\', '/', dirname(__DIR__));
$query = null;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        echo usage();

        exit(0);
    }

    if (str_starts_with($argument, '--root=')) {
        $root = str_replace('\\', '/', substr($argument, strlen('--root=')));

        if ($root === '') {
            usageError('--root needs a path: --root=/some/checkout');
        }

        continue;
    }

    if (str_starts_with($argument, '-')) {
        // Refused rather than ignored. This command reads the same weighing whatever
        // it is passed — there is no --weigh and no --dry-run to accept — so an
        // argument it does not know is a typo, and answering anyway would hide it
        // behind a report that looks like the one that was wanted.
        usageError("Unknown option {$argument}.");
    }

    if ($query !== null) {
        usageError("One symbol at a time: {$query} and {$argument} were both given.");
    }

    $query = $argument;
}

if ($query === null) {
    usageError('Name a class, a method, a constant or a config key to ask about.');
}

$query = normaliseQuery($query);

if ($query === '') {
    usageError('The name to ask about is empty once its quotes, its leading \\ and its () are stripped.');
}

// A package root is one with something to read. Both halves of the surface may be
// empty on purpose, but a root with neither directory is not this package, and
// pointing --root at the wrong folder should stop here rather than weigh an empty
// tree and report that nothing is wrong.
if (!is_dir($root . '/src') && !is_dir($root . '/config')) {
    fwrite(STDERR, "✗ {$root} has no src/ or config/ directory, so there is no surface to weigh." . PHP_EOL);

    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// The same weighing the release plan shows
// ─────────────────────────────────────────────────────────────────────────────

$latestTag = latestTag($root);
$base = $latestTag === null ? '0.0.0' : ltrim($latestTag, 'vV');

$changelog = @file_get_contents($root . '/CHANGELOG.md');
$section = $changelog === false ? null : unreleasedSection($changelog);
$unreleased = $section['body'] ?? '';

$weighing = weigh($root, $latestTag, $unreleased, $base);
$top = severityRank($weighing['severity']);

// One registry per side, because the two sides hold every path twice on a tree that renamed
// nothing: folding them into one registry would make every symbol in the package read as
// declared by two files. Within one side, two paths for one name is the question.
$declaredNow = [];
$declaredThen = [];

$now = workingSurfaceAll($root, $declaredNow);
$then = taggedSurfaceAll($root, $latestTag, $declaredThen);

$reading = surfaceReading($now, $then, $query);
$presentNow = $reading['now'];
$presentThen = $reading['then'];

// ─────────────────────────────────────────────────────────────────────────────
// Which signal names it
// ─────────────────────────────────────────────────────────────────────────────

$destination = $latestTag === null ? '(no tag yet)' : $latestTag;

printf('Weighing HEAD against %s for "%s"%s', $destination, $query, PHP_EOL);
echo str_repeat('─', 72) . PHP_EOL . PHP_EOL;

$named = [];
$caughtLoud = [];
$caughtQuiet = [];

foreach ($weighing['signals'] as $signal) {
    $lines = namedLines($signal['evidence'], $query);

    // The notes signal weighs its `###` headings, so an entry that names the symbol is
    // not in its evidence at all. It is still what a reader means by "the changelog
    // caught this", which is why the bullets are searched separately and reported with
    // the heading they sit under — the heading is the part that carried a weight.
    if ($signal['source'] === notesSource()) {
        $lines = [...$lines, ...notesNaming($unreleased, $query)];
    }

    $carries = severityRank($signal['severity']) === $top;

    if ($lines === []) {
        printf(
            "  %-7s %-9s %-11s nothing in it names \"%s\"%s",
            'quiet',
            $signal['severity'],
            $signal['source'],
            $query,
            PHP_EOL,
        );

        continue;
    }

    if ($carries) {
        $caughtLoud[] = $signal['source'];
    } else {
        $caughtQuiet[] = $signal['source'];
    }

    $named = [...$named, ...$lines];

    printf(
        '  %-7s %-9s %-11s names it in %d line(s)%s',
        'caught',
        $signal['severity'],
        $signal['source'],
        count($lines),
        PHP_EOL,
    );

    foreach (array_slice($lines, 0, 6) as $line) {
        echo '            • ' . clip($line, $query) . PHP_EOL;
    }

    if (count($lines) > 6) {
        printf("            … and %d more%s", count($lines) - 6, PHP_EOL);
    }
}

echo PHP_EOL;

foreach ($weighing['notes'] as $weighingNote) {
    echo '  note: ' . $weighingNote . PHP_EOL;
}

if ($section === null) {
    echo '  note: ' . ($changelog === false
        ? 'CHANGELOG.md could not be read, so the notes signal had nothing to weigh'
        : 'CHANGELOG.md has no `## Unreleased` heading, so the notes signal had nothing to weigh') . PHP_EOL;
}

// ─────────────────────────────────────────────────────────────────────────────
// What it contributed
// ─────────────────────────────────────────────────────────────────────────────

echo PHP_EOL . 'Contribution' . PHP_EOL;

if ($caughtLoud !== []) {
    printf(
        '  It contributed: the weighing came out at %s, and it is named in what carried that — %s. %s.%s',
        $weighing['severity'],
        implode(' and ', $caughtLoud),
        sentence(bumpLine($weighing)),
        PHP_EOL,
    );
} elseif ($named !== []) {
    printf(
        '  It contributed nothing to the bump: the weighing came out at %s, and no line naming "%s" is in what carried it. %s.%s',
        $weighing['severity'],
        $query,
        sentence(bumpLine($weighing)),
        PHP_EOL,
    );
} else {
    printf(
        '  It contributed nothing: no signal names it, so nothing about it was weighed. %s.%s',
        sentence(bumpLine($weighing)),
        PHP_EOL,
    );
}

// The bump moved for a reason, and a reader who has just been told their change did not
// move it is owed the reason: the same list the weighing itself prints, narrowed to the
// signals that carry the top severity.
if ($caughtLoud === []) {
    if ($caughtQuiet !== []) {
        printf('  It is named below the top, in: %s.%s', implode(', ', $caughtQuiet), PHP_EOL);
    }

    echo '  What did carry it:' . PHP_EOL;

    foreach ($weighing['signals'] as $signal) {
        if (severityRank($signal['severity']) === $top) {
            printf('    • %s — %s%s', $signal['source'], $signal['summary'], PHP_EOL);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Where it sits in the surface
// ─────────────────────────────────────────────────────────────────────────────

echo PHP_EOL;

reportSurface('In the surface now', array_map(describeSymbol(...), $presentNow), $query);
reportSurface(
    $latestTag === null ? 'In the surface at the tag' : 'In the surface at ' . $latestTag,
    array_map(describeSymbol(...), $presentThen),
    $query,
);

// The one thing the two reports above cannot say about a name they hold: whose declaration
// it is. A name two files declare is in the surface once, from the first of them, so the
// other file is absent from both maps — and a reader who has just been told the surface
// holds the name has been told something true and incomplete.
$shadowed = shadowedNames($root, [$declaredNow, $declaredThen], [...$presentNow, ...$presentThen]);

if ($shadowed !== []) {
    echo PHP_EOL . 'Shadowed names' . PHP_EOL;
    echo '  The surface keys a name once, so where two files declare one, only the first of them is'
        . ' in it — and a change to the other is a change no signal above can name:' . PHP_EOL;

    foreach ($shadowed as $line) {
        echo '    • ' . $line . PHP_EOL;
    }
}

echo PHP_EOL . 'Diagnosis' . PHP_EOL;
echo '  ' . clip(diagnosis($query, $latestTag, $reading, $named !== []), '', 460) . PHP_EOL;

exit($named === [] && $presentNow === [] && $presentThen === [] ? 1 : 0);

// ─────────────────────────────────────────────────────────────────────────────
// Reading one name
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The name as a person types it, reduced to the part every signal has in common.
 *
 * Quoting, a leading `\` and a trailing `()` or `;` are all ways of writing the same
 * name in a shell, and none of them is in a signal's evidence. They are stripped
 * because a query that found nothing for a reason the reader cannot see is worse than
 * a query that searched for less than was meant.
 */
function normaliseQuery(string $query): string
{
    $query = trim($query, " \t\n\r\0\x0B'\"");

    return rtrim(ltrim($query, '\\'), '();');
}

/**
 * Whether a line of evidence contains the name. Plain `str_contains` on purpose: the
 * query is a fragment, not a pattern, so this is a grep and not a matcher.
 */
function names(string $haystack, string $query): bool
{
    return str_contains($haystack, $query);
}

/**
 * The evidence lines that name it.
 *
 * @param list<string> $evidence
 * @return list<string>
 */
function namedLines(array $evidence, string $query): array
{
    return array_values(array_filter(
        $evidence,
        static fn (string $line): bool => names($line, $query),
    ));
}

/**
 * The Unreleased entries whose text names it, each labelled with the `###` heading it
 * sits under. The heading is carried along because it is the part the notes signal
 * weighs: an entry under `### Fixed` is a patch however loudly it is written, and a
 * reader deciding whether to move an entry needs to see which one it is in.
 *
 * @return list<string>
 */
function notesNaming(string $unreleased, string $query): array
{
    $mentions = [];

    foreach (notesBlocks($unreleased) as $block) {
        if (!names($block['text'], $query)) {
            continue;
        }

        $mentions[] = sprintf(
            'the notes %s: %s',
            $block['heading'] === null ? 'name it outside any heading' : "name it under ### {$block['heading']}",
            $block['text'],
        );
    }

    return $mentions;
}

/**
 * The Unreleased body as the units a reader sees: one bullet — its wrapped lines joined into
 * one string — and one paragraph of anything that is not a bullet.
 *
 * A bullet is searched as a unit rather than a line at a time because a wrapped entry is one
 * entry. Matching its third line on its own reports a fragment that starts mid-word, reads as
 * a different bullet, and hides the sentence the name is actually in.
 *
 * @return list<array{heading: string|null, text: string}>
 */
function notesBlocks(string $unreleased): array
{
    $blocks = [];
    $heading = null;
    $open = null;

    foreach (preg_split('/\R/', $unreleased) ?: [] as $line) {
        if (preg_match('/^###[ \t]+(.+?)[ \t]*$/', $line, $match) === 1) {
            $heading = trim($match[1]);
            $open = null;

            continue;
        }

        $trimmed = trim($line);

        if ($trimmed === '') {
            $open = null;

            continue;
        }

        // A bullet always opens a block; a line that is not one joins the block above it,
        // which is what makes a wrapped entry one entry. A blank line closes the block, so
        // two paragraphs are two units rather than one.
        if ($open !== null && preg_match('/^[-*]\s+/', $trimmed) !== 1) {
            $blocks[$open]['text'] .= ' ' . $trimmed;

            continue;
        }

        $blocks[] = [
            'heading' => $heading,
            'text' => trim((string) preg_replace('/^[-*]\s+/', '', $trimmed)),
        ];
        $open = array_key_last($blocks);
    }

    return $blocks;
}

/**
 * The symbol keys a surface holds that name the query.
 *
 * Both spellings are searched: the key itself, which is how a member reads when it is
 * copied out of a plan, and the sentence `describeSymbol()` makes of it, which is how it
 * reads in one. A member that moved between the two is found either way.
 *
 * @param array<string, string> $surface
 * @return list<string>
 */
function namedKeys(array $surface, string $query): array
{
    $keys = [];

    foreach (array_keys($surface) as $key) {
        if (names($key, $query) || names(describeSymbol($key), $query)) {
            $keys[] = $key;
        }
    }

    sort($keys);

    return $keys;
}

/**
 * What the two surfaces make of the name, key by key: the keys each one holds, the ones
 * only the tree has, the ones only the tag had, and the ones both have at a different
 * shape.
 *
 * This is read off the two maps rather than off the evidence the signals produced, and
 * the difference matters: a commit subject or a changelog entry can contain a symbol's
 * name without that symbol having changed at all, so "a signal line mentions it" is not
 * an answer to "what happened to it". The maps are.
 *
 * @param array<string, string> $now
 * @param array<string, string> $then
 * @return array{now: list<string>, then: list<string>, added: list<string>, removed: list<string>, changed: list<string>}
 */
function surfaceReading(array $now, array $then, string $query): array
{
    $hereNow = namedKeys($now, $query);
    $thereThen = namedKeys($then, $query);

    $added = [];
    $changed = [];

    foreach ($hereNow as $key) {
        if (!array_key_exists($key, $then)) {
            $added[] = $key;

            continue;
        }

        if ($then[$key] !== $now[$key]) {
            $changed[] = $key;
        }
    }

    return [
        'now' => $hereNow,
        'then' => $thereThen,
        'added' => $added,
        'removed' => array_values(array_filter(
            $thereThen,
            static fn (string $key): bool => !array_key_exists($key, $now),
        )),
        'changed' => $changed,
    ];
}

/**
 * The names the query reached that more than one file declares, as the lines that say so.
 *
 * The surface keys a name once, so a name two files declare is in the map from the first
 * file read. That is the right answer to "is this name public" and the wrong one to "why is
 * this name like this", which is the question this command exists for: a config key two
 * files both return is one entry in the surface and two keys at runtime, and the second
 * file is in neither map at all.
 *
 * The registries are searched in order and the first that holds two distinct paths answers
 * for the name. Distinct, because one registry per side: a name one file declares on each
 * side is the same path twice, not two files. The keys are the two readings' own key lists,
 * so a name only the tag held is asked about too.
 *
 * @param list<array<string, list<string>>> $registries the files behind each name, per side
 * @param list<string> $keys the surface keys the query reached
 * @return list<string>
 */
function shadowedNames(string $root, array $registries, array $keys): array
{
    $lines = [];

    foreach (array_unique($keys) as $key) {
        foreach ($registries as $declaredBy) {
            $paths = array_values(array_unique($declaredBy[$key] ?? []));

            if (count($paths) < 2) {
                continue;
            }

            $lines[] = sprintf(
                '%s — declared by %s; the surface holds the first of them',
                describeSymbol($key),
                implode(' and ', array_map(
                    static fn (string $path): string => relativeTo($root, $path),
                    $paths,
                )),
            );

            break;
        }
    }

    return $lines;
}

/**
 * What the two surface maps make of the name, in one paragraph.
 *
 * This is the part that answers "why is every signal quiet about the thing I just
 * changed". A symbol that is in both maps with the same description is not missing: it is
 * unchanged, and the surface has nothing to say about a change that did not move it. A
 * symbol in neither map is not a public name at all, which is the answer for a private
 * member, a local variable or a string.
 *
 * @param array{now: list<string>, then: list<string>, added: list<string>, removed: list<string>, changed: list<string>} $reading
 */
function diagnosis(string $query, ?string $latestTag, array $reading, bool $named): string
{
    $tag = $latestTag ?? 'the tag';

    if ($reading['now'] === [] && $reading['then'] === []) {
        return $named
            ? "No symbol key contains \"{$query}\", but a line of signal evidence does: it was named by a path, a commit subject or a stored inventory row rather than as a member a consumer can call. The signals that read those are the ones to look at above."
            : "Nothing in the weighing names \"{$query}\", and no symbol the surface reader can see contains it. It is not a class, public method, constant, enum case, property, config key or env var of this package — a private member, a local variable, a string, or a name from somewhere else entirely. The bump moved for reasons of its own, and this is not one of them.";
    }

    if ($reading['removed'] !== [] && $reading['now'] === []) {
        return $latestTag === null
            ? "\"{$query}\" is in the stored surface but not in the tree's: the surface signal reads that as a removal, and a removal is breaking however the notes describe it."
            : "\"{$query}\" was in {$tag} and is not in the tree now: the surface signal reads that as a removal, which is breaking however the notes describe it. If it only moved, the inventory signal is the one that can still tell a move from a removal.";
    }

    if ($reading['added'] !== [] && $reading['then'] === []) {
        return $latestTag === null
            ? "\"{$query}\" is in the tree, and there is no tag yet, so there is no base for the surface signal to have diffed it against. Without a tag the notes decide, and the inventory can only witness what changed after it was written — so a fresh name arrives without the surface saying so."
            : "\"{$query}\" is in the tree and not in {$tag}: its arrival is one of the additions the surface signal weighs, and an addition is a minor. Nothing about a new name can be breaking on its own.";
    }

    if ($reading['changed'] !== []) {
        return sprintf(
            "\"%s\" is in both %s and its description moved: what changed is the shape of it rather than its presence. %s. A public method that gained a required argument is breaking; a signature that changed any other way, a class that gained `final`, a constant whose value or case is read differently — each of those is a different line above.",
            $query,
            $latestTag === null ? 'surfaces' : "the tree and {$tag}",
            implode(', ', array_map(describeSymbol(...), $reading['changed'])),
        );
    }

    return "\"{$query}\" is in both " . ($latestTag === null ? 'surfaces' : "the tree and {$tag}") . ", and no signal line names it: it is unchanged, so the public API and config signals have nothing to say about it, and the inventory only reads the rows that moved. A change that does not move the surface has to be caught by the notes or by the commits, and if neither of them names it either, nothing weighed it at all.";
}

/**
 * One surface's matches, or the fact that there are none.
 *
 * @param list<string> $symbols
 */
function reportSurface(string $title, array $symbols, string $query): void
{
    if ($symbols === []) {
        printf('%s: nothing%s', $title, PHP_EOL);

        return;
    }

    printf('%s: %d symbol(s)%s', $title, count($symbols), PHP_EOL);

    foreach (array_slice($symbols, 0, 8) as $symbol) {
        echo '    • ' . clip($symbol, $query) . PHP_EOL;
    }

    if (count($symbols) > 8) {
        printf("    … and %d more%s", count($symbols) - 8, PHP_EOL);
    }
}

/**
 * The bump a weighed severity asks for, in words.
 *
 * `severity` and `bump` are the same word except below 1.0, where `bumpFor()` calls a
 * breaking change a minor because there is no major line to break yet. Saying "the
 * weighing came out at breaking" and "the bump is a minor" is therefore not a
 * contradiction, and the sentence has to carry the reason or it reads like one.
 *
 * @param array{severity: string, bump: string} $weighing
 */
function bumpLine(array $weighing): string
{
    return $weighing['severity'] === $weighing['bump']
        ? "the bump is a {$weighing['bump']}"
        : "the bump is a {$weighing['bump']}, because 0.x takes a breaking change as a minor until there is a 1.0 line to break";
}

/**
 * A sentence fragment as a sentence: capitalised, and with the full stop this file's
 * `printf` calls leave to the caller's `%s`.
 */
function sentence(string $fragment): string
{
    return ucfirst($fragment);
}

/**
 * One line of evidence, short enough to sit in a bullet without wrapping. The width is
 * the same kind of cut the inventory makes, and for the same reason: enough to
 * recognise the line, not the whole row.
 *
 * The window follows the query when the query does not fit in the head. A fully
 * qualified name is the case this is for: `...\WeightedDatabaseServiceProvider::KEY_PGCAT_…`
 * cut at the head shows the namespace for the twenty-fifth time and hides the one
 * part that differs between the bullets.
 */
function clip(string $line, string $query = '', int $width = 100): string
{
    $line = trim((string) preg_replace('/\s+/', ' ', $line));

    if ($line === '' || strlen($line) <= $width) {
        return $line;
    }

    $at = $query === '' ? false : strpos($line, $query);

    if ($at === false || $at < $width) {
        return substr($line, 0, $width - 1) . '…';
    }

    $from = $at - 24;
    $window = substr($line, $from, $width - 2);

    return '…' . $window . (strlen($line) > $from + strlen($window) ? '…' : '');
}

function usageError(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL . PHP_EOL . usage());

    exit(2);
}

function usage(): string
{
    return <<<'TXT'
    bin/blame.php — which release signal names one symbol, and what it weighed.

    Usage:
      php bin/blame.php SYMBOL [options]

    Arguments:
      SYMBOL               A class, a method, a constant, a config key or a fragment
                           of one. Quoting, a leading \ and a trailing () are
                           stripped, and the match is a plain `contains`: `weight`
                           finds `weighed`, `weighing` and `weigh()` alike.

    Options:
          --root=PATH      Package root to ask about (default: the parent of bin/).
      -h, --help           Show this help.

    It reads the same weighing as `php bin/release.php --weigh --dry-run`, from the
    same tree, and says which signal names the symbol and whether that signal is the
    one the bump came from. It writes nothing, needs no tag and has no preconditions:
    a question about one symbol is asked mid-edit.

    Exit codes:
      0  something in the weighing or in either surface names it
      1  nothing does
      2  usage error

    TXT;
}
