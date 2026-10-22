#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/entry.php — the commit, the changelog entry it wrote, and the release that published it.
 *
 * THE PAIR IT SHOWS
 * -----------------
 *   `pkg-commit` reads a commit back with four git reads and a look at the changelog, and a
 *   release asks a fifth: whether the notes a commit wrote are still under `## Unreleased` or are
 *   published by a tag that carries the commit. Done by hand that is `git log`, `git show
 *   --name-only`, `git show <sha> -- CHANGELOG.md`, `git tag --contains` and a read of the tag's
 *   own changelog — five commands whose output has to be held together in a head to answer one
 *   question, and which are rebuilt every time the question is asked.
 *
 *       php bin/entry.php                     the commit this checkout is on
 *       php bin/entry.php 30109fc             another one
 *       php bin/entry.php v0.2.0-alpha10      a tag's release commit
 *
 *   The answer is a verdict rather than a screenful of git: the notes the commit added are
 *   published by a tag (exit 0), they are still unreleased (exit 1), or the commit added none —
 *   which is a pass when nothing a release weighs was touched, and a refusal when `src/` or
 *   `config/` was, because that is the commit a release cannot account for.
 *
 * WHY IT READS THE TAG'S CHANGELOG RATHER THAN THE CHECKOUT'S
 * -----------------------------------------------------------
 *   A note can be rewritten after it ships — a correction to a sentence, a bullet moved between
 *   sections — so "the notes are in the changelog" is not the same question as "the release
 *   published them". The tag's own file is the one a consumer can read, and it is asked
 *   directly: each note this commit added is looked for under a released heading in that file,
 *   and the section that holds it is printed.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 *   It writes nothing, checks nothing out, and touches no ref. It has no preconditions: no
 *   branch, no clean tree, no CI — a question about one commit is asked mid-edit as often as
 *   after a release, which is when a dirty tree is the normal state. It reads the commit's own
 *   diff, so a note that was added by a later commit is that commit's answer and not this one's.
 *
 * EXIT CODES
 * ----------
 *   0  the notes this commit wrote are published by a tag that carries it, or it wrote none and
 *      touched nothing the weighing reads
 *   1  the notes are still unreleased, no tag carries the commit, or the commit changed `src/`
 *      or `config/` without a note
 *   2  usage error — an unknown option, or a revision this repository does not have
 */

$root = str_replace('\\', '/', dirname(__DIR__));

/** The paths a release weighs: a commit that changes one without a note is one nothing can weigh. */
const WEIGHED = ['src/', 'config/'];

/** How much of a note is compared: enough to be unique, short enough to print on one line. */
const NEEDLE = 80;

/**
 * Run git in the package root. An argument list rather than a shell string: no quoting rules to
 * get wrong on Windows, and no shell profile to depend on.
 *
 * @param list<string> $arguments
 * @return array{exit: int, output: string, error: string}
 */
function git(string $root, array $arguments): array
{
    $process = @proc_open(
        ['git', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
    );

    if (! is_resource($process)) {
        return ['exit' => 127, 'output' => '', 'error' => 'git could not be started'];
    }

    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'output' => $output, 'error' => $error];
}

/**
 * @return list<string>
 */
function lines(string $output): array
{
    return array_values(array_filter(
        array_map('trim', explode("\n", str_replace("\r", '', $output))),
        static fn (string $line): bool => $line !== '',
    ));
}

/**
 * The notes one commit added to CHANGELOG.md, with the `###` heading each sits under.
 *
 * The diff is read with no context, so a note is a line this commit added rather than one it
 * happened to sit beside; a heading that is context rather than an addition still attributes the
 * notes under it, because a commit can add a bullet to a section that already exists.
 *
 * @return list<array{heading: string, needle: string}>
 */
function notes(string $diff): array
{
    $notes = [];
    $heading = 'unclassified';

    foreach (lines($diff) as $line) {
        if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
            continue;
        }

        if (preg_match('/^[+ ]###\s+(\w[\w ]*)$/', $line, $matches) === 1) {
            $heading = strtolower(trim($matches[1]));

            continue;
        }

        if (preg_match('/^\+\s*-\s+\*\*(.+)$/', $line, $matches) !== 1) {
            continue;
        }

        $needle = trim($matches[1]);

        $notes[] = [
            'heading' => $heading,
            'needle' => mb_substr($needle, 0, NEEDLE),
        ];
    }

    return $notes;
}

/**
 * The release section a commit promoted its notes into, or null when it promoted none.
 *
 * A release commit does not add an entry — it moves the whole section under a version heading,
 * so every bullet in its diff is a context line and only the heading is an addition. That is a
 * promotion rather than an absence, and naming it is the difference between "this commit wrote
 * no note" and "this commit is the release of the notes above it".
 */
function promoted(string $diff): ?string
{
    foreach (lines($diff) as $line) {
        if (preg_match('/^\+##\s+(\d[\w.-]*)(.*)$/', $line, $matches) === 1) {
            return trim($matches[1].$matches[2]);
        }
    }

    return null;
}

/**
 * The bullets a section body holds, as the notes a reader sees.
 *
 * @return list<string>
 */
function bullets(string $body): array
{
    preg_match_all('/^\s*-\s+\*\*(.+)$/m', $body, $matches);

    return array_values(array_map('trim', $matches[1]));
}

/**
 * The released sections of a changelog, as heading => body. `## Unreleased` is not one of them:
 * a note under it is a note no consumer can read yet.
 *
 * @return array<string, string>
 */
function released(string $changelog): array
{
    $sections = [];
    $heading = null;

    foreach (explode("\n", str_replace("\r", '', $changelog)) as $line) {
        if (preg_match('/^##\s+(.+?)\s*$/', $line, $matches) === 1) {
            $heading = trim($matches[1]);
            $sections[$heading] ??= '';

            continue;
        }

        if ($heading !== null) {
            $sections[$heading] .= $line."\n";
        }
    }

    unset($sections['Unreleased']);

    return $sections;
}

/**
 * The released section holding a note, or null when no release carries it.
 *
 * @param array<string, string> $sections
 */
function publishedIn(array $sections, string $needle): ?string
{
    foreach ($sections as $heading => $body) {
        if (str_contains($body, $needle)) {
            return $heading;
        }
    }

    return null;
}

/**
 * @param list<string> $paths
 */
function touchesWeighed(array $paths): bool
{
    foreach ($paths as $path) {
        foreach (WEIGHED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
    }

    return false;
}

function usage(): void
{
    echo <<<'TEXT'
    bin/entry.php — the commit, the changelog entry it wrote, and the release that published it.

    Usage:
      php bin/entry.php               the commit this checkout is on
      php bin/entry.php <rev>         any revision git resolves to a commit
      php bin/entry.php --help

    Exit codes: 0 published, 1 not published or weighed without a note, 2 usage error.
    TEXT.PHP_EOL;
}

$arguments = array_slice($argv, 1);

if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    usage();
    exit(0);
}

if (count($arguments) > 1 || (isset($arguments[0]) && str_starts_with($arguments[0], '-'))) {
    fwrite(STDERR, 'Usage: php bin/entry.php [<rev>] — one revision, or none for HEAD.' . PHP_EOL);
    exit(2);
}

$rev = $arguments[0] ?? 'HEAD';
$resolved = git($root, ['rev-parse', '--verify', $rev.'^{commit}']);

if ($resolved['exit'] !== 0) {
    fwrite(STDERR, sprintf('Not a revision this repository has: %s%s', $rev, PHP_EOL));
    exit(2);
}

$sha = trim($resolved['output']);

$log = trim(git($root, ['log', '-1', '--format=%h %G? %s', $sha])['output']);
$paths = lines(git($root, ['show', '--name-only', '--format=', $sha])['output']);
$diff = git($root, ['show', '--format=', '--unified=0', $sha, '--', 'CHANGELOG.md'])['output'];
$notes = notes($diff);

$shown = array_slice($paths, 0, 4);
$pathList = $shown === [] ? 'none' : implode(', ', $shown).(count($paths) > count($shown) ? ', …' : '');

echo "  commit   ".$log.PHP_EOL;
echo sprintf('  paths    %d — %s%s', count($paths), $pathList, PHP_EOL);

if ($notes === [] && ($promoted = promoted($diff)) !== null) {
    $sections = released(git($root, ['show', $sha.':CHANGELOG.md'])['output']);
    $bullets = bullets($sections[$promoted] ?? '');
    $tags = lines(git($root, ['tag', '--contains', $sha, '--sort=v:refname'])['output']);
    $tag = $tags === [] ? null : $tags[count($tags) - 1];

    echo sprintf('  notes    %d — promoted into ## %s%s', count($bullets), $promoted, PHP_EOL);
    echo sprintf('  release  %s carries this commit%s', $tag ?? 'no tag', PHP_EOL);

    if ($tag === null) {
        echo PHP_EOL.'  verdict  the promotion is on no tag, so no consumer can read the section it wrote.'
            .PHP_EOL;

        exit(1);
    }

    echo PHP_EOL.sprintf(
        '  verdict  this commit is the release of %d note(s), published by %s.%s',
        count($bullets),
        $tag,
        PHP_EOL,
    );

    exit(0);
}

if ($notes === []) {
    echo '  notes    none — this commit adds no changelog entry' . PHP_EOL;

    if (touchesWeighed($paths)) {
        echo PHP_EOL.'  verdict  it changes what a release weighs (`src/` or `config/`) and writes no note, '
            .'so the release cannot account for it.' . PHP_EOL;

        exit(1);
    }

    echo PHP_EOL.'  verdict  nothing a release weighs was touched, so there is no entry to publish.' . PHP_EOL;

    exit(0);
}

$byHeading = [];
foreach ($notes as $note) {
    $byHeading[$note['heading']] = ($byHeading[$note['heading']] ?? 0) + 1;
}

$parts = [];
foreach ($byHeading as $heading => $count) {
    $parts[] = sprintf('%s %d', $heading, $count);
}

echo sprintf('  notes    %d — %s%s', count($notes), implode(', ', $parts), PHP_EOL);

$tags = lines(git($root, ['tag', '--contains', $sha, '--sort=v:refname'])['output']);
$tag = $tags === [] ? null : $tags[count($tags) - 1];

if ($tag === null) {
    echo '  release  none — no tag carries this commit' . PHP_EOL;
    echo PHP_EOL.'  verdict  the notes are in no release: nothing a consumer can install holds them.' . PHP_EOL;

    exit(1);
}

$changelog = git($root, ['show', $tag.':CHANGELOG.md'])['output'];

if (trim($changelog) === '') {
    echo sprintf('  release  %s carries this commit, and its tree has no CHANGELOG.md%s', $tag, PHP_EOL);
    echo PHP_EOL.'  verdict  the release cannot hold notes it did not ship.' . PHP_EOL;

    exit(1);
}

$sections = released($changelog);
$missing = [];
$sectionsHolding = [];

foreach ($notes as $note) {
    $section = publishedIn($sections, $note['needle']);

    if ($section === null) {
        $missing[] = $note['needle'];

        continue;
    }

    $sectionsHolding[$section] = true;
}

$where = implode(', ', array_map(static fn (string $heading): string => '## '.$heading, array_keys($sectionsHolding)));

echo sprintf(
    '  release  %s carries this commit; %d of %d note(s) under %s%s',
    $tag,
    count($notes) - count($missing),
    count($notes),
    $where === '' ? 'no released heading' : $where,
    PHP_EOL,
);

if ($missing === []) {
    echo PHP_EOL.sprintf('  verdict  the notes this commit wrote are published by %s.%s', $tag, PHP_EOL);

    exit(0);
}

foreach ($missing as $needle) {
    fwrite(STDERR, '  missing  '.$needle.PHP_EOL);
}

echo PHP_EOL.'  verdict  the release does not hold every note this commit wrote: a note that was '
    .'rewritten after it shipped is the usual cause, and the tag is what a consumer reads.' . PHP_EOL;

exit(1);
