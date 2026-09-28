<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use RuntimeException;

/**
 * The README's own tables, read as data.
 *
 * WHY THIS EXISTS
 * ---------------
 * A documented exit code is a contract with whoever schedules the command, and a
 * matrix can only check half of it: the rows can enforce `1` while the table an
 * operator reads says `0`. The README tables that state an exit code are therefore
 * parsed here and compared, cell by cell, with the data provider the matrix is
 * written as — so changing one without the other fails the suite instead of
 * shipping a command whose behaviour and documentation disagree.
 *
 * The parser is deliberately dumb, and strict about it: find the heading, find the
 * first table under it that has the column the caller names, hand back its rows
 * keyed by their own header. Everything that can go wrong — a renamed heading, a
 * column that moved, a row with a different number of cells — raises rather than
 * returning an empty answer, because a guard that quietly reads nothing is worse
 * than no guard: it reports agreement with a table it never found.
 */
final class Readme
{
    /** The tables live in the package root, whatever depth the calling test sits at. */
    private const FILE = __DIR__.'/../../README.md';

    /**
     * The rows of the first table under `$heading` that has a `$column` header.
     *
     * Rows are keyed by their own header cells, so a caller reads a value by the
     * column's name — and a renamed column is reported by the caller's own lookup
     * rather than silently shifting a positional index.
     *
     * @return list<array<string, string>>
     *
     * @throws RuntimeException when the file, the heading, the column or a row's shape is not there
     */
    public static function table(string $heading, string $column): array
    {
        $lines = self::lines();

        foreach (self::tables($lines, self::headingAt($lines, $heading)) as $table) {
            $headers = self::cells($table[0]);

            if (self::indexOf($headers, $column) === null) {
                continue;
            }

            return array_map(
                static fn (string $line): array => self::keyedRow($line, $headers),
                array_slice($table, 1),
            );
        }

        throw new RuntimeException(sprintf(
            'README.md has no table under [%s] with a [%s] column.',
            $heading,
            $column,
        ));
    }

    /**
     * Everything under a heading, to the next heading of the same level or higher.
     *
     * The unit a reader reads a section as, and the unit a guard has to read it as too: an
     * alert recipe is prose, a table and a fenced block that only mean anything together, so
     * checking the fenced block alone would check the half of it that a rename breaks last.
     *
     * @throws RuntimeException when no heading reads as the given one
     */
    public static function section(string $heading): string
    {
        $lines = self::lines();
        $at = self::headingAt($lines, $heading);
        $level = self::levelOf($lines[$at - 1]);
        $section = [];
        $fence = null;

        foreach (array_slice($lines, $at) as $line) {
            $trimmed = trim($line);

            // A block is not read for headings: a `#` inside one is a shell comment or
            // output, and reading it as a heading would end the section at it — which is
            // exactly what a fenced `jq` program full of `#` comments does.
            if (str_starts_with($trimmed, '```')) {
                $fence = $fence === null ? $trimmed : null;
                $section[] = $line;

                continue;
            }

            if ($fence === null && self::levelOf($line) > 0 && self::levelOf($line) <= $level) {
                break;
            }

            $section[] = $line;
        }

        return implode("\n", $section);
    }

    /**
     * The bodies of the fenced blocks under a heading whose info string is `$language`.
     *
     * A fence with no language is read as an empty `$language`, which is how the README writes
     * a block that is output or a snippet rather than something to run — and a fence left open
     * raises, because a guard that silently read half a block would agree with a file it never
     * finished reading.
     *
     * @return list<string>
     *
     * @throws RuntimeException when the heading, a fence's language, or the closing of a fence is not what this reads
     */
    public static function fenced(string $heading, string $language = ''): array
    {
        $blocks = [];
        $body = [];
        $inside = null;
        $openedAt = 0;

        foreach (explode("\n", self::section($heading)) as $index => $line) {
            $trimmed = trim($line);

            if (! str_starts_with($trimmed, '```')) {
                if ($inside !== null) {
                    $body[] = $line;
                }

                continue;
            }

            if ($inside === null) {
                $inside = trim(substr($trimmed, 3));
                $body = [];
                $openedAt = $index + 1;

                continue;
            }

            if ($inside === $language) {
                $blocks[] = implode("\n", $body);
            }

            $inside = null;
        }

        if ($inside !== null) {
            throw new RuntimeException(sprintf(
                'README.md opens a %s fence under [%s] that is never closed (line %d of the section).',
                $inside === '' ? 'no-language' : $inside,
                $heading,
                $openedAt,
            ));
        }

        return $blocks;
    }

    /**
     * The documented row whose `$column` cell reads as `$label` — the label being how a
     * table names one case, and the one thing a test can hold on to. A label that is no
     * longer there fails with the labels that are, so a reworded row is a decision to
     * make rather than a comparison that quietly stops matching.
     *
     * @param list<array<string, string>> $rows
     * @return array<string, string>
     *
     * @throws RuntimeException when no row reads as the label
     */
    public static function row(array $rows, string $column, string $label): array
    {
        foreach ($rows as $row) {
            if (self::plain($row[$column] ?? '') === self::plain($label)) {
                return $row;
            }
        }

        throw new RuntimeException(sprintf(
            'README.md has no row reading [%s] in the [%s] column. It has: %s',
            $label,
            $column,
            implode('; ', array_map(
                static fn (array $row): string => $row[$column] ?? '(none)',
                $rows,
            )),
        ));
    }

    /**
     * The exit code a documented cell names: the number it opens with, so a cell can
     * say why in the same breath (`1 — nothing could be probed`).
     *
     * @throws RuntimeException when the cell names no number at all
     */
    public static function code(string $cell): int
    {
        if (preg_match('/^(-?\d+)/', trim($cell), $matches) !== 1) {
            throw new RuntimeException(sprintf(
                'The README cell [%s] does not name an exit code.',
                $cell,
            ));
        }

        return (int) $matches[1];
    }

    /**
     * The form two labels are compared in. Backticks, spacing and capitalisation are
     * prose; the words are the row's identity, which is what makes the comparison
     * survive a reflow and fail on a different case.
     *
     * `strtolower()` rather than the multibyte one: the package declares no `ext-mbstring`,
     * and every label this compares is ASCII — a table cell in an English README.
     */
    public static function plain(string $text): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $text))));
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
            throw new RuntimeException('No README.md to check the documented exit codes against: '.self::FILE);
        }

        return explode("\n", str_replace("\r\n", "\n", $raw));
    }

    /**
     * The index of the heading line, with the lines after it.
     *
     * @param list<string> $lines
     *
     * @throws RuntimeException when no heading reads as the given one
     */
    private static function headingAt(array $lines, string $heading): int
    {
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (!str_starts_with($trimmed, '#') || self::plain($trimmed) !== self::plain($heading)) {
                continue;
            }

            return $index + 1;
        }

        throw new RuntimeException(sprintf(
            'README.md has no heading [%s] to read a table from.',
            $heading,
        ));
    }

    /**
     * Every markdown table after `$from`, each as its own lines — the header first, the
     * dash row folded away because it is punctuation rather than a row of the table.
     *
     * @param list<string> $lines
     * @return list<list<string>>
     */
    private static function tables(array $lines, int $from): array
    {
        $tables = [];
        $current = [];
        $separated = false;

        foreach (array_slice($lines, $from) as $line) {
            $trimmed = trim($line);

            if (!str_starts_with($trimmed, '|')) {
                if ($current !== []) {
                    $tables[] = $current;
                    $current = [];
                    $separated = false;
                }

                continue;
            }

            if (!$separated && self::isSeparator(self::cells($trimmed))) {
                $separated = true;

                continue;
            }

            $current[] = $trimmed;
        }

        if ($current !== []) {
            $tables[] = $current;
        }

        return $tables;
    }

    /**
     * @param list<string> $headers
     */
    /**
     * The markdown heading level of a line: `0` for a line that is not a heading.
     *
     * A fence's info string can open with `#` (a shell comment inside a block, as the README's
     * alert patterns do), so only a line whose leading `#` run is followed by a space is a
     * heading — which is also the only shape markdown reads as one.
     */
    private static function levelOf(string $line): int
    {
        if (preg_match('/^(#{1,6})\s/', ltrim($line), $matches) !== 1) {
            return 0;
        }

        return strlen($matches[1]);
    }

    private static function indexOf(array $headers, string $column): ?int
    {
        foreach ($headers as $index => $header) {
            if (self::plain($header) === self::plain($column)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * One markdown row's cells, trimmed and stripped of the code backticks — the text a
     * reader sees, not the markup.
     *
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        return array_map(
            static fn (string $cell): string => trim(str_replace('`', '', $cell)),
            explode('|', trim(trim($line), '|')),
        );
    }

    /**
     * @param list<string> $cells
     */
    private static function isSeparator(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (preg_match('/^:?-{2,}:?$/', $cell) !== 1) {
                return false;
            }
        }

        return $cells !== [];
    }

    /**
     * @param list<string> $headers
     * @return array<string, string>
     *
     * @throws RuntimeException when the row and the header disagree about the column count
     */
    private static function keyedRow(string $line, array $headers): array
    {
        $cells = self::cells($line);

        if (count($cells) !== count($headers)) {
            throw new RuntimeException(sprintf(
                'README.md has a table row with %d cells where its header has %d: [%s]',
                count($cells),
                count($headers),
                $line,
            ));
        }

        $row = [];

        foreach ($headers as $index => $header) {
            $row[$header] = $cells[$index];
        }

        return $row;
    }
}
