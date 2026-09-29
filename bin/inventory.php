#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/inventory.php — write files.tsv and methods.tsv from the working tree.
 *
 * WHAT THE TWO FILES ARE FOR
 * --------------------------
 *   `composer release -- --weigh` decides the bump from four signals, and the
 *   inventory is one of them: the files this package ships and the public methods
 *   they declare, written down, so a file that was renamed or a method that
 *   changed shape can be seen rather than remembered.
 *
 * IT IS NOT A SECOND TREE TO KEEP IN STEP BY HAND
 * ----------------------------------------------
 *   `bin/release.php` writes both files itself, in the same commit as the
 *   CHANGELOG promotion, stamped with the tag it is creating. The stamp is the
 *   whole safety property: a file that describes the release being cut is
 *   evidence, and a file regenerated at some other moment is not — so the release
 *   script reads them only when the stamp matches the tag it is releasing from,
 *   and says so when it does not. Run this command by hand only when you mean to
 *   commit what it writes.
 *
 * USAGE
 * -----
 *   php bin/inventory.php                 write both files, stamped with the latest tag
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
 *   0 written, or already current under --check; 1 nothing could be written or the
 *   files are out of step; 2 usage error.
 */

require __DIR__ . '/surface.php';

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

    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(str_replace('\\', '/', substr($argument, 7)), '/');
        continue;
    }

    fwrite(STDERR, "Unknown option: {$argument}" . PHP_EOL . PHP_EOL);
    usage();
    exit(2);
}

if (!is_dir($root . '/src')) {
    fwrite(STDERR, "Not a package root — no src/ under {$root}." . PHP_EOL);
    exit(1);
}

$tag = inventoryTag($root);
$paths = inventoryPaths($root);

// What the files on disk say, read before anything can overwrite them. A rewrite
// that discards a change the last release recorded is worth saying out loud: the
// inventory is only evidence while it still describes an older tree.
$stored = [
    'files' => readInventory($paths['files']),
    'methods' => readInventory($paths['methods']),
];

$result = syncInventory($root, $tag, !$check);

if ($check) {
    if ($result['current']) {
        note(sprintf(
            'inventory is current — %d files, %d public methods, described as %s',
            $result['count']['files'],
            $result['count']['methods'],
            $tag,
        ));

        exit(0);
    }

    $document = inventoryDocument($root, $tag);

    fwrite(STDERR, PHP_EOL . '✗ The inventory is out of step with the tree:' . PHP_EOL . PHP_EOL);

    foreach (['files', 'methods'] as $file) {
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

    fwrite(STDERR, PHP_EOL . 'Run php bin/inventory.php to write them and commit them, or leave them'
        . ' alone and let the next release refresh them.' . PHP_EOL . PHP_EOL);

    exit(1);
}

if (!$result['written']) {
    fwrite(STDERR, '✗ Could not write ' . basename($paths['files']) . ' / ' . basename($paths['methods']) . '.' . PHP_EOL);

    exit(1);
}

// Both of them, or neither: a rewrite can only lose a record it read, and a pair
// that is half there — the state an interrupted release leaves, since the files are
// written by one call and any but the first can fail — has no record on one side.
// Diffing against a side that was never read reports every row of the other side as
// a change this run is discarding, which names a change nothing ever recorded.
$evidence = ($stored['files'] === null || $stored['methods'] === null)
    ? []
    : diffInventory($stored, inventoryRecords($root))['evidence'];

note(sprintf('%s — %d files, described as %s', basename($paths['files']), $result['count']['files'], $tag));
note(sprintf('%s — %d public methods', basename($paths['methods']), $result['count']['methods']));
note('stage them with: git add -- ' . basename($paths['files']) . ' ' . basename($paths['methods']));

if ($evidence !== []) {
    note(sprintf('this rewrite discarded the record of %d change(s):', count($evidence)));

    foreach (array_slice($evidence, 0, 3) as $line) {
        echo '      • ' . $line . PHP_EOL;
    }

    if (count($evidence) > 3) {
        echo '      … and ' . (count($evidence) - 3) . ' more' . PHP_EOL;
    }

    note('the release script is the intended writer: it refreshes both files in the release commit, where they still describe the last release');
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
    bin/inventory.php — write files.tsv and methods.tsv from the working tree.

    Usage:
      php bin/inventory.php [options]

    Options:
          --check          Compare with the tree, write nothing, exit 1 when out of step.
          --root=PATH      Package root to inventory (default: the parent of bin/).
      -h, --help           Show this help.

    The release script refreshes both files in the release commit, so running this
    by hand is for seeing what changed — not for keeping them current.

    TXT;
}
