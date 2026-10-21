<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use RuntimeException;

/**
 * `site-to-package-port-plan.md`'s status section, read as data.
 *
 * WHY THIS EXISTS
 * ---------------
 *   The status table is the one place in the plan that claims something about *this tree* rather than
 *   about work: which commit each phase landed as. A table of seven-character shas is the easiest
 *   prose in the repository to leave behind — a rebase, a squashed branch or a rewritten history turns
 *   every cell into a string that reads like a commit and resolves to nothing, and nothing in a diff of
 *   the plan shows it. The phases are the other half: a phase added to the plan above without a row
 *   below is work the reading does not name, and a row for a phase that no longer exists is a reading
 *   of a plan somebody has moved on from.
 *
 *   So the section is read here rather than trusted, and `PortPlanStatusTest` compares what it finds
 *   with the document's own phase headings and with `git`. The parser is deliberately strict: a
 *   status heading that is not there, a table whose columns were renamed, a row with a different
 *   number of cells, and a section with no `at <sha>` snapshot all raise — a guard that returned an
 *   empty list for any of them would report agreement with a table it never found.
 *
 * WHAT IS READ
 * ------------
 *   The section starts at `## Status` — the heading gains a trailing `at <sha>` whenever it is
 *   re-taken, so only the opening words are matched — and ends at the next top-level heading. Inside
 *   it, the first table is the status table. Cells keep their backticks: the `Landed in` column is
 *   `bbfd422` and `1ce9e2f`, `53d6352`, and the backticks are what tells a sha apart from a word that
 *   happens to be hexadecimal.
 */
final class PortPlan
{
    /** The plan lives in the package root, whatever depth the calling test sits at. */
    private const FILE = __DIR__.'/../../site-to-package-port-plan.md';

    /** The heading the table sits under, matched on its opening words — a re-take moves the sha. */
    private const STATUS = '/^##\s+Status\b/';

    /** A phase the plan declares: `## Phase 3 — the doctor gets a mode a build container can run`. */
    private const PHASE = '/^##\s+Phase\s+(\d+)\s/m';

    /** A backticked abbreviated commit, the only shape the columns write one in. */
    private const COMMIT = '/`([0-9a-f]{7,40})`/';

    /** The snapshot the section is taken at, in its heading: `## Status — where the work stands at `sha``. */
    private const SNAPSHOT = '/`([0-9a-f]{7,40})`/';

    /**
     * The status table's rows, keyed by their own header cells.
     *
     * @return list<array<string, string>>
     *
     * @throws RuntimeException when the section, the table, a column or a row's shape is not there
     */
    public static function rows(): array
    {
        $lines = self::lines();
        $header = null;
        $rows = [];

        foreach (array_slice($lines, self::statusAt($lines)) as $line) {
            $trimmed = trim($line);

            // The status section is the last one in the plan today; a top-level heading after it still
            // ends it, so a section added below does not become rows of this table.
            if (preg_match('/^##\s/', $trimmed) === 1) {
                break;
            }

            if (! str_starts_with($trimmed, '|')) {
                if ($header !== null) {
                    break;      // the table has ended: the prose under it is not rows
                }

                continue;       // the paragraphs between the heading and its table
            }

            $cells = self::cells($trimmed);

            if ($header === null) {
                $header = $cells;

                continue;
            }

            if (self::isSeparator($cells)) {
                continue;
            }

            $rows[] = self::keyed($cells, $header);
        }

        if ($header === null) {
            throw new RuntimeException(
                'site-to-package-port-plan.md has no table under its status heading, so a guard reading '
                .'the phases it landed has nothing to compare with.',
            );
        }

        foreach (['Phase', 'Landed in'] as $column) {
            if (! in_array($column, $header, true)) {
                throw new RuntimeException(sprintf(
                    'the port plan\'s status table has no [%s] column; it has: %s.',
                    $column,
                    implode(', ', $header),
                ));
            }
        }

        if ($rows === []) {
            throw new RuntimeException(
                'the port plan\'s status table has a header and no rows, which is a reading of no phases at all.',
            );
        }

        return $rows;
    }

    /**
     * The phases the plan declares above the status table, in the order they are written.
     *
     * @return list<int>
     *
     * @throws RuntimeException when the plan declares none
     */
    public static function phases(): array
    {
        preg_match_all(self::PHASE, implode("\n", self::lines()), $matches);

        if ($matches[1] === []) {
            throw new RuntimeException(
                'site-to-package-port-plan.md declares no `## Phase N — …` heading, so the status table '
                .'cannot be held to the phases it is about.',
            );
        }

        return array_map(static fn (string $phase): int => (int) $phase, $matches[1]);
    }

    /**
     * Every commit the section names: one per backticked sha in a `Landed in` cell, and the one its own
     * heading is taken at.
     *
     * @return list<array{sha: string, where: string}>
     *
     * @throws RuntimeException when the section's heading names no snapshot at all
     */
    public static function commits(): array
    {
        $commits = [];

        foreach (self::rows() as $row) {
            $landed = $row['Landed in'];

            preg_match_all(self::COMMIT, $landed, $matches);

            foreach ($matches[1] as $sha) {
                $commits[] = ['sha' => $sha, 'where' => sprintf('the [%s] row', self::label($row['Phase']))];
            }
        }

        $commits[] = [
            'sha' => self::snapshot(),
            'where' => 'the status heading',
        ];

        return $commits;
    }

    /**
     * The commit the status section is taken at — the sha its heading names.
     *
     * Required rather than optional: the heading's whole job is to say which tree the table describes,
     * and a guard that shrugged at its absence would let the section stop being a snapshot without
     * anything failing.
     *
     * @throws RuntimeException when the heading names no commit
     */
    public static function snapshot(): string
    {
        $heading = self::heading();

        if (preg_match(self::SNAPSHOT, $heading, $matches) !== 1) {
            throw new RuntimeException(sprintf(
                'the port plan\'s status heading names no commit: [%s]. The section is a snapshot of the '
                .'tree at one commit, and a heading without one is a reading with no basis.',
                trim($heading),
            ));
        }

        return $matches[1];
    }

    /** The status heading's line, so a failure can quote what it read. */
    private static function heading(): string
    {
        $lines = self::lines();

        return $lines[self::statusAt($lines) - 1];
    }

    /**
     * The index of the first line after the status heading.
     *
     * @param list<string> $lines
     *
     * @throws RuntimeException when the plan has no status heading
     */
    private static function statusAt(array $lines): int
    {
        foreach ($lines as $index => $line) {
            if (preg_match(self::STATUS, trim($line)) === 1) {
                return $index + 1;
            }
        }

        throw new RuntimeException(
            'site-to-package-port-plan.md has no `## Status` section, which is where the phases are '
            .'read back against the tree.',
        );
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException when the plan is not there
     */
    private static function lines(): array
    {
        $raw = @file_get_contents(self::FILE);

        if ($raw === false) {
            throw new RuntimeException('No port plan to read the phases out of: '.self::FILE);
        }

        return explode("\n", str_replace("\r\n", "\n", $raw));
    }

    /** A phase cell as a person reads it: `1`, `Small items`. */
    private static function label(string $cell): string
    {
        return trim(str_replace('`', '', $cell));
    }

    /**
     * One row's cells, trimmed and left as written — the backticks are read for the shas, so unlike a
     * prose table they are kept.
     *
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        return array_map(
            static fn (string $cell): string => trim($cell),
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
     * @param list<string> $cells
     * @param list<string> $header
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when the row and the header disagree about the column count
     */
    private static function keyed(array $cells, array $header): array
    {
        if (count($cells) !== count($header)) {
            throw new RuntimeException(sprintf(
                'the port plan\'s status table has a row with %d cells where its header has %d: [%s]',
                count($cells),
                count($header),
                implode(' | ', $cells),
            ));
        }

        $row = [];

        foreach ($header as $index => $column) {
            $row[$column] = $cells[$index];
        }

        return $row;
    }
}
