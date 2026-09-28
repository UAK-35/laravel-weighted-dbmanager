<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * RELEASING.md read as data: the safety rails, and the tests it says prove them.
 *
 * WHY THIS EXISTS
 * ---------------
 * The rails table is the file's most load-bearing claim — every row is a way a release is
 * stopped, and a reader deciding whether a release is safe is reading that list. The column
 * that names the test is what makes the claim checkable, and a naming column rots in the one
 * direction that reads as *more* proof than there is: rename the test and the row goes on
 * quoting a name nobody runs, which is a rail with no evidence wearing the label of one.
 *
 * So the names are parsed out of the file rather than re-typed beside it, and the suite is
 * read for the methods it actually declares. The parser is strict in the same spirit as
 * PushingDoc and Readme: a table whose heading is missing, whose rows have the wrong number of
 * cells, or whose test column is empty raises rather than returning nothing, because a guard
 * that quietly reads nothing reports agreement with a file it never read.
 */
final class ReleasingDoc
{
    /** The file sits in the package root, whatever depth the calling test is at. */
    private const FILE = __DIR__.'/../../RELEASING.md';

    /** The package root, which is what a test name is looked for under. */
    private const ROOT = __DIR__.'/../..';

    /** The heading the rails table lives under. */
    private const HEADING = '## Safety rails';

    /** How many cells a row of the rails table has: rail, why, proved by. */
    private const CELLS = 3;

    /**
     * The package root, for a caller that wants to report a path.
     */
    public static function root(): string
    {
        return self::ROOT;
    }

    /**
     * One entry per rail: what it is, why it exists, and the test the row says proves it.
     *
     * The table is three columns and every row is read as exactly one — a row that has grown
     * or lost a cell is a row whose test column is not where this reader looks, and reading it
     * anyway would report a rail as proved by whatever landed in that position.
     *
     * @return list<array{rail: string, why: string, proved_by: string, source: int}>
     *
     * @throws RuntimeException when the heading, the table or a row's shape is not what this reads
     */
    public static function rails(): array
    {
        $rails = [];
        $inside = false;
        $header = true;

        foreach (self::lines() as $index => $line) {
            $trimmed = trim($line);

            if (! $inside) {
                if ($trimmed === self::HEADING) {
                    $inside = true;
                }

                continue;
            }

            if (! str_starts_with($trimmed, '|')) {
                // Prose sits between the heading and the table, so a line that is not a row
                // only ends it once one has been read; before that it is the paragraph
                // introducing the table.
                if (! $header) {
                    break;
                }

                continue;
            }

            // The header and its separator are not rails: a column name read as a rail would
            // be reported as one that proves nothing, which is true of a header and useless.
            if ($header) {
                if (preg_match('/^\|[\s:|-]+\|$/', $trimmed) === 1) {
                    $header = false;
                }

                continue;
            }

            $cells = array_map('trim', explode('|', trim($trimmed, '|')));

            if (count($cells) !== self::CELLS) {
                throw new RuntimeException(sprintf(
                    'RELEASING.md line %d is a rail row with %d cells; the table is rail, why and proved-by (%d).',
                    $index + 1,
                    count($cells),
                    self::CELLS,
                ));
            }

            $rails[] = [
                'rail' => $cells[0],
                'why' => $cells[1],
                'proved_by' => $cells[2],
                'source' => $index + 1,
            ];
        }

        if ($rails === [] || $header) {
            throw new RuntimeException(sprintf(
                'RELEASING.md has no rails table under "%s" to read — the heading, or the row of column names and its separator under it, is not there. If one of them moved, this reader moves with it.',
                self::HEADING,
            ));
        }

        return $rails;
    }

    /**
     * Every test the file names in backticks, in the order it appears, with the line it is on.
     *
     * The whole file is read rather than only the table: the paragraphs below it name tests too
     * — the escapes, the removed flags, the inventory — and a name is a name wherever it is
     * written down.
     *
     * @return list<array{name: string, source: int}>
     *
     * @throws RuntimeException when the file is not there
     */
    public static function namedTests(): array
    {
        $named = [];

        foreach (self::lines() as $index => $line) {
            preg_match_all('/`(test_[A-Za-z0-9_]+)`/', $line, $matches);

            foreach ($matches[1] as $name) {
                $named[] = ['name' => $name, 'source' => $index + 1];
            }
        }

        return $named;
    }

    /**
     * Every test method this package declares, as name => path relative to the root.
     *
     * Read off the suite rather than asked of the runner: a name is "there" when a class
     * declares it, and a test that is declared but skipped, filtered or broken is still a test
     * somebody can point at. Every file under `tests/` that ends in `Test.php` is read, so a
     * test that moved between files does not read as one that vanished.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when no test file could be read at all, which would make every
     *                          name look missing and every absence look real
     */
    public static function declaredTests(): array
    {
        $declared = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::ROOT.'/tests', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || ! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $raw = @file_get_contents($file->getPathname());

            if ($raw === false) {
                continue;
            }

            preg_match_all(
                '/(?:public|protected)\s+function\s+(test_[A-Za-z0-9_]+)\s*\(/',
                $raw,
                $matches,
            );

            foreach ($matches[1] as $name) {
                $declared[$name] = str_replace('\\', '/', substr($file->getPathname(), strlen(self::ROOT) + 1));
            }
        }

        if ($declared === []) {
            throw new RuntimeException('No test methods were read from '.self::ROOT.'/tests, so nothing could be checked against the file.');
        }

        return $declared;
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException when the file is not there
     */
    private static function lines(): array
    {
        $raw = @file_get_contents(self::FILE);

        if ($raw === false) {
            throw new RuntimeException('No RELEASING.md to read: '.self::FILE);
        }

        return explode("\n", str_replace("\r\n", "\n", $raw));
    }
}
