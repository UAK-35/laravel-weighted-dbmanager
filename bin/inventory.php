#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/inventory.php — write the inventory — files.tsv, methods.tsv, surface.tsv —
 * from the working tree, or from the tree a ref holds.
 *
 * WHAT THE THREE FILES ARE FOR
 * ----------------------------
 *   `composer release -- --weigh` decides the bump from four signals, and the
 *   inventory is one of them: the files this package ships, the public methods they
 *   declare, and the keys and members a consumer can name besides them — config
 *   keys, env vars, public constants, enum cases and public properties — written
 *   down, so a file that was renamed, a method that changed shape or a constant or
 *   a config key that was dropped can be seen rather than remembered.
 *
 *   The third file is the one that does not need a tag. The public-API signal diffs
 *   the tree against the last tag, so a tree with no tag has nothing for it to see a
 *   removal in; a stored row is its own "before", which is why the constants and the
 *   config keys are written down at all.
 *
 * IT IS NOT A SECOND TREE TO KEEP IN STEP BY HAND
 * ----------------------------------------------
 *   `bin/release.php` writes all three files itself, in the same commit as the
 *   CHANGELOG promotion, stamped with the tag it is creating. The stamp is the
 *   whole safety property: a file that describes the release being cut is
 *   evidence, and a file regenerated at some other moment is not — so the release
 *   script reads them only when the stamp matches the tag it is releasing from,
 *   and says so when it does not. Run this command by hand only when you mean to
 *   commit what it writes.
 *
 * `--at=REF` IS THE OTHER TREE IT CAN DESCRIBE
 * -------------------------------------------
 *   A run with `--at` reads its rows out of git — `ls-tree` and a `show` per file —
 *   instead of off the disk, and stamps what it writes with the tag that ref is.
 *   That is for the tag with no written record: one cut before there was an
 *   inventory to write, one whose record was never committed, and the two-file
 *   set this package's own repository still carries, written before `surface.tsv`
 *   existed. Reading the ref rather than checking it out is what makes the pair worth
 *   trusting: the rows and the stamp come from one tree, where a hand run against a
 *   moved-on checkout describes the tree in front of it and names the latest tag,
 *   and so describes neither.
 *
 *   A ref is any ref — a tag, a branch, a commit, `HEAD~3` — and only one that names
 *   a tag (or whose commit carries one) is stamped with a release; anything else is
 *   stamped `(no tag)`, which is what the working-tree reader says about a tree with
 *   no tag either. `--check --at=REF` is the read-only half: it compares the record
 *   on disk with the tree that ref holds, which is how a past tag is audited without
 *   writing anything.
 *
 *   One inventory is kept — the one the next weighing reads — so a ref is refused
 *   when the files on disk carry a stamp this run is not writing: replacing one
 *   release's rows with another's costs the second opinion rather than refreshing it.
 *   `--force` writes them anyway, and `--check` reports the difference without
 *   writing — which is also why `--force` needs a run that writes: with `--check`, or
 *   without `--at`, it would be a flag that changed nothing.
 *
 * USAGE
 * -----
 *   php bin/inventory.php                 write all three files, stamped with the latest tag
 *   php bin/inventory.php --at=REF        ...for the tree at REF instead of the checkout
 *   php bin/inventory.php --check         compare, write nothing, exit 1 when out of step
 *   php bin/inventory.php --root=PATH     run against another checkout
 *   php bin/inventory.php --help
 *
 *   `--check` answers "is the inventory current?". It is deliberately not a CI
 *   check: an inventory kept in step with every commit can never witness a
 *   change, and witnessing one is the only thing it is for.
 *
 * EXIT CODES
 * ----------
 *   0 written, or already current under --check; 1 nothing could be written, the
 *   files are out of step, the ref is not one this repository has, or a record of
 *   another release is in the way; 2 usage error.
 */

require __DIR__ . '/surface.php';

$root = str_replace('\\', '/', dirname(__DIR__));
$check = false;
$force = false;
$at = null;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        usage();
        exit(0);
    }

    if ($argument === '--check') {
        $check = true;
        continue;
    }

    if ($argument === '--force') {
        $force = true;
        continue;
    }

    if (str_starts_with($argument, '--at=')) {
        $at = substr($argument, 5);
        continue;
    }

    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(str_replace('\\', '/', substr($argument, 7)), '/');
        continue;
    }

    fwrite(STDERR, "Unknown option: {$argument}" . PHP_EOL . PHP_EOL);
    usage();
    exit(2);
}

// The flags that only mean something together are answered here, before anything is read,
// the way an unknown option is: a run that cannot mean what it was asked has not earned the
// right to write, and a `--force` that quietly did nothing would read as one that did.
if ($at !== null && trim($at) === '') {
    fwrite(STDERR, '--at= needs a ref: a tag, a branch or a commit.' . PHP_EOL . PHP_EOL);
    usage();
    exit(2);
}

if ($force && ($at === null || $check)) {
    fwrite(STDERR, '--force is about a run that writes: it needs --at=REF, and --check writes' . PHP_EOL
        . 'nothing — a plain run rewrites the record of the tree it stamps rather than standing' . PHP_EOL
        . 'in for another release\'s.' . PHP_EOL . PHP_EOL);
    usage();
    exit(2);
}

if (!is_dir($root . '/src')) {
    fwrite(STDERR, "Not a package root — no src/ under {$root}." . PHP_EOL);
    exit(1);
}

if ($at !== null) {
    // Asked before the tree is read, because a ref that does not resolve reads as a tree
    // with no files in it — and an empty inventory written under a tag's name is the one
    // outcome worse than a refusal. git's own words are carried into the message: "no such
    // ref" and "not a repository" are different problems with the same exit code.
    $resolved = git($root, ['rev-parse', '--verify', $at . '^{commit}']);
    $reason = trim(strtok($resolved['output'], "\n") ?: '');

    if ($resolved['exit'] !== 0) {
        fwrite(STDERR, sprintf(
            '✗ Nothing to read: %s is not a ref this repository has%s' . PHP_EOL,
            $at,
            $reason === '' ? '.' : ' — ' . $reason,
        ));

        exit(1);
    }

    note(sprintf('reading the tree at %s out of git, not the checkout', $at));
}

// The rows and the stamp are chosen together, from the same side: the checkout, or the ref.
// That pairing is the whole property `--at` adds — every other rule about a stamp is about
// refusing a pair that describes two different trees.
$records = $at === null ? inventoryRecords($root) : refInventoryRecords($root, $at);
$tag = $at === null ? inventoryTag($root) : inventoryTagAt($root, $at);
$paths = inventoryPaths($root);

// What the files on disk say, read before anything can overwrite them. A rewrite
// that discards a change the last release recorded is worth saying out loud: the
// inventory is only evidence while it still describes an older tree.
//
// All three, or none: a rewrite can only lose a record it read, and one file that was
// never read — the state an interrupted release leaves, since the files are written by
// one call and any but the first can fail — is enough for the whole comparison to be
// skipped. Diffing against a side that was never read reports every row of the other
// two as a change this run is discarding, which names a change nothing ever recorded.
$stored = [
    'files' => readInventory($paths['files']),
    'methods' => readInventory($paths['methods']),
    'surface' => readInventory($paths['surface']),
];

// A ref's rows describe a tree that is not the checkout, so the record already on disk can
// belong to another release — which a plain run's cannot be: that one rewrites the record
// of the tree it is standing in, stamped with the release that tree is being developed from.
// The stamp is what makes the difference visible, and it is read the way `inventorySignal()`
// reads it, `files.tsv` first. A file that is not there recorded nothing to compare and is
// passed over, which is what lets a half-written set be completed by any writer.
$elsewhere = [];

if (!$check && $at !== null && !$force) {
    foreach (['files', 'methods', 'surface'] as $file) {
        $stamp = $stored[$file]['stamp'] ?? null;

        if ($stamp !== null && $stamp !== $tag) {
            $elsewhere[$file] = $stamp;
        }
    }
}

if ($elsewhere !== []) {
    $described = $stored['files']['stamp'] ?? $stored['methods']['stamp'] ?? $stored['surface']['stamp'] ?? 'unknown';

    fwrite(STDERR, PHP_EOL . sprintf(
        '✗ Nothing was written: the record on disk describes %s, and this run describes %s.',
        $described,
        $tag,
    ) . PHP_EOL . PHP_EOL);
    fwrite(STDERR, '    ' . implode(', ', array_map(
        static fn (string $file, string $stamp): string => basename($paths[$file]) . " ({$stamp})",
        array_keys($elsewhere),
        array_values($elsewhere),
    )) . PHP_EOL);
    fwrite(STDERR, '    One inventory is kept, and the next weighing reads it only when its stamp names the' . PHP_EOL);
    fwrite(STDERR, '    tag being released from — so replacing one release\'s rows with another\'s costs the' . PHP_EOL);
    fwrite(STDERR, '    second opinion rather than refreshing it. --force writes this ref\'s rows anyway, and' . PHP_EOL);
    fwrite(STDERR, '    --check reports what differs without writing.' . PHP_EOL . PHP_EOL);

    exit(1);
}

$result = syncInventory($root, $tag, !$check, $records);

if ($check) {
    if ($result['current']) {
        note(sprintf(
            'inventory is current — %d files, %d public methods, %d key(s)/member(s), described as %s',
            $result['count']['files'],
            $result['count']['methods'],
            $result['count']['surface'],
            $tag,
        ));

        exit(0);
    }

    $document = inventoryDocument($root, $tag, $records);

    // Named as the tree that was compared, because with `--at` it is not the tree the
    // reader is standing in: "out of step" is a claim about a pair, and the pair is the
    // record and one of two trees.
    fwrite(STDERR, PHP_EOL . '✗ The inventory is out of step with '
        . ($at === null ? 'the tree:' : 'the tree at ' . $at . ':') . PHP_EOL . PHP_EOL);

    foreach (['files', 'methods', 'surface'] as $file) {
        $name = basename($paths[$file]);
        $onDisk = @file_get_contents($paths[$file]);

        if ($onDisk === false) {
            fwrite(STDERR, '    ' . $name . ' is missing' . PHP_EOL);

            continue;
        }

        // Compared the way syncInventory compares them, so only the files that actually
        // differ are reported: the out-of-step verdict is about the pair, and describing
        // the file that is already right would make a reader open it for nothing.
        $normalised = str_replace(["\r\n", "\r"], "\n", $onDisk);

        if ($normalised === $document[$file]) {
            continue;
        }

        fwrite(STDERR, '    ' . $name . ': ' . describeDrift($normalised, $document[$file]) . PHP_EOL);
    }

    fwrite(STDERR, PHP_EOL . 'Run php bin/inventory.php' . ($at === null ? '' : ' --at=' . $at)
        . ' to write them and commit them, or leave them'
        . ' alone and let the next release refresh them.' . PHP_EOL . PHP_EOL);

    exit(1);
}

if (!$result['written']) {
    fwrite(STDERR, '✗ Could not write ' . basename($paths['files']) . ' / ' . basename($paths['methods'])
        . ' / ' . basename($paths['surface']) . '.' . PHP_EOL);

    exit(1);
}

$evidence = ($stored['files'] === null || $stored['methods'] === null || $stored['surface'] === null)
    ? []
    : diffInventory($stored, $records)['evidence'];

note(sprintf('%s — %d files, described as %s', basename($paths['files']), $result['count']['files'], $tag));
note(sprintf('%s — %d public methods', basename($paths['methods']), $result['count']['methods']));
note(sprintf('%s — %d key(s) and member(s)', basename($paths['surface']), $result['count']['surface']));
note('stage them with: git add -- ' . basename($paths['files']) . ' ' . basename($paths['methods'])
    . ' ' . basename($paths['surface']));

if ($evidence !== []) {
    note(sprintf('this rewrite discarded the record of %d change(s):', count($evidence)));

    foreach (array_slice($evidence, 0, 3) as $line) {
        echo '      • ' . $line . PHP_EOL;
    }

    if (count($evidence) > 3) {
        echo '      … and ' . (count($evidence) - 3) . ' more' . PHP_EOL;
    }

    note('the release script is the intended writer: it refreshes all three files in the release commit, where they still describe the last release');
}

exit(0);

/**
 * How two inventories differ, as counts and the first few lines either side of the
 * change — enough to see what moved without printing a whole file.
 */
function describeDrift(string $onDisk, string $expected): string
{
    $have = lines($onDisk);
    $want = lines($expected);

    // Every line is the one that belongs, so the difference is in the bytes around them.
    // Not line endings — the caller normalised those away, which is why a CRLF checkout is
    // current rather than a rewrite — so it is blank lines or trailing space, and saying
    // "line endings" would send a reader looking for a cause that is already handled.
    if ($have === $want) {
        return 'the rows are right but the bytes are not: a blank line or a trailing space somewhere (rewrite it to pin the file)';
    }

    $removed = array_values(array_diff($have, $want));
    $added = array_values(array_diff($want, $have));

    $parts = [sprintf('%d line(s) stale: %d gone, %d new', max(count($removed), count($added)), count($removed), count($added))];

    foreach (array_slice($removed, 0, 2) as $line) {
        $parts[] = 'gone: ' . shorter($line);
    }

    foreach (array_slice($added, 0, 2) as $line) {
        $parts[] = 'new:  ' . shorter($line);
    }

    return implode('; ', $parts);
}

function shorter(string $line): string
{
    $line = str_replace("\t", ' | ', $line);

    return strlen($line) > 68 ? substr($line, 0, 67) . '…' : $line;
}

function note(string $message): void
{
    echo '• ' . $message . PHP_EOL;
}

function usage(): void
{
    echo <<<'TXT'
    bin/inventory.php — write files.tsv, methods.tsv and surface.tsv from the working tree,
    or from the tree a ref holds.

    Usage:
      php bin/inventory.php [options]

    Options:
          --at=REF         Read the tree at REF — a tag, a branch or a commit — instead of
                           the checkout, and stamp what is written with the tag it names.
                           This is how a tag with no written record is backfilled; with
                           --check it audits one without writing.
          --check          Compare with the tree, write nothing, exit 1 when out of step.
          --force          With --at, and not --check: write even when the record there is
                           another release's.
          --root=PATH      Package root to inventory (default: the parent of bin/).
      -h, --help           Show this help.

    The release script refreshes all three files in the release commit, so running this
    by hand is for seeing what changed — not for keeping them current.

    TXT;
}
