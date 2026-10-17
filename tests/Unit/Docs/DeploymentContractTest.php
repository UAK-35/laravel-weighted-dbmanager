<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uak35\WeightedDbManager\Support\DeploymentContract;
use Uak35\WeightedDbManager\Tests\Support\Readme;

/**
 * `docs/deployment-contract.md` against the requirements `db:doctor --contract` builds.
 *
 * WHY THIS EXISTS
 * ---------------
 *   The record states what a deployment of this package has to have, and the class turns that into
 *   rows. Two files that say the same thing are two files that can disagree, and the disagreement
 *   is silent in the direction that costs most: a requirement the record states and the class does
 *   not is a deployment fact no row fails on, while a key the class builds and the record does not
 *   name is a row an operator meets without reading what it is for. So the record's `Key` column is
 *   read as data and compared with the class in both directions, by name and in order.
 *
 *   The second half is the one that keeps the mode honest as the command grows. `--contract`
 *   replaces the installation's row list with the requirements', so a row that no requirement
 *   names would be a reading a deploy stops asking — silently, and while every document still says
 *   the preflight asks it. Every row in the README's `db:doctor` table therefore has to be behind
 *   at least one requirement, and every reading an entry names has to be a row there: the two
 *   directions make the mapping exhaustive rather than merely valid.
 *
 *   Nothing here runs the command — `DbDoctorTest` holds the mode's behaviour, including that a
 *   failing reading fails its requirement. What this file holds is that the mapping is complete and
 *   that the record and the code state the same one.
 */
final class DeploymentContractTest extends TestCase
{
    private const RECORD = 'docs/deployment-contract.md';

    /** The heading the requirements table sits under, and the column its keys are written in. */
    private const HEADING = '## The requirements';

    private const COLUMN = 'Key';

    public function test_the_records_keys_are_the_requirements_in_order(): void
    {
        self::assertSame(
            DeploymentContract::keys(),
            self::documentedKeys(),
            'The record and DeploymentContract::requirements() name different requirements, or the same ones in a different order.',
        );
    }

    public function test_every_reading_is_a_row_the_preflight_documents(): void
    {
        $documented = self::documentedRows();

        foreach (DeploymentContract::requirements() as $key => $requirement) {
            foreach ($requirement['readings'] as $reading) {
                self::assertContains(
                    $reading,
                    $documented,
                    sprintf(
                        'The [%s] requirement names [%s], which the README\'s db:doctor table does not document, '
                        .'so a gate could wait for a row no run has ever built.',
                        $key,
                        $reading,
                    ),
                );
            }
        }
    }

    public function test_every_documented_row_is_behind_a_requirement(): void
    {
        $covered = [];

        foreach (DeploymentContract::requirements() as $requirement) {
            foreach ($requirement['readings'] as $reading) {
                $covered[$reading] = true;
            }
        }

        $orphans = array_values(array_diff(self::documentedRows(), array_keys($covered)));

        self::assertSame(
            [],
            $orphans,
            'These rows are asked by a plain db:doctor run and by no requirement, so --contract would stop asking them '
            .'without any document saying so. Place each behind the requirement it answers.',
        );
    }

    public function test_a_requirement_no_row_answers_says_what_would_have_to_be_read(): void
    {
        foreach (DeploymentContract::requirements() as $key => $requirement) {
            self::assertMatchesRegularExpression(
                '/^[a-z]+(-[a-z]+)*$/',
                $key,
                'A requirement key is the row name a gate selects on: lower case, hyphenated, no prose.',
            );

            self::assertStringEndsNotWith(
                '.',
                $requirement['statement'],
                sprintf(
                    'The [%s] statement is joined to the rest of the detail with a full stop of its own, '
                    .'so it must not carry one.',
                    $key,
                ),
            );

            if ($requirement['readings'] === []) {
                self::assertNotNull(
                    $requirement['unread'],
                    sprintf(
                        'No row answers [%s], and the entry does not say what would have to be read instead — '
                        .'a contract row that cannot say why it is not judged is a pass that reads as a check.',
                        $key,
                    ),
                );

                continue;
            }

            self::assertNull(
                $requirement['unread'],
                sprintf(
                    'The [%s] requirement has readings, so it is judged; an unread sentence would be a second, '
                    .'contradictory answer to the same question.',
                    $key,
                ),
            );
        }
    }

    /**
     * The keys the record's requirement table states, in the order it states them.
     *
     * Read from the table rather than from a list in this file, because the record *is* the list a
     * reader opens; a constant here would be a third copy and the guard would compare two of them.
     *
     * @return list<string>
     */
    private static function documentedKeys(): array
    {
        $rows = self::rows();

        $keys = [];

        foreach ($rows as $row) {
            $keys[] = Readme::plain($row[self::COLUMN] ?? '');
        }

        return $keys;
    }

    /**
     * The rows the README documents for `db:doctor`, in the order the table lists them.
     *
     * @return list<string>
     */
    private static function documentedRows(): array
    {
        return array_map(
            [Readme::class, 'plain'],
            array_column(Readme::table('### Preflight: `db:doctor`', 'Row'), 'Row'),
        );
    }

    /**
     * The requirement table's rows, keyed by their header cells.
     *
     * A local reader rather than `Readme::table()` because that helper reads the README and says so
     * in every failure; this one reads the record and has to fail about the record. It is the same
     * shape and the same discipline: a heading that is gone, a table that is gone, a column that
     * moved or a row with the wrong number of cells raises rather than returning an empty answer,
     * because a guard that reads nothing agrees with everything.
     *
     * @return list<array<string, string>>
     */
    private static function rows(): array
    {
        $path = dirname(__DIR__, 3).'/'.self::RECORD;
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("The deployment contract record could not be read: {$path}");
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $raw));
        $at = null;

        foreach ($lines as $index => $line) {
            if (trim($line) === self::HEADING) {
                $at = $index + 1;

                break;
            }
        }

        if ($at === null) {
            throw new RuntimeException(sprintf('%s has no [%s] heading to read the requirements from.', self::RECORD, self::HEADING));
        }

        $table = [];
        $header = null;

        foreach (array_slice($lines, $at) as $line) {
            $trimmed = trim($line);

            if (!str_starts_with($trimmed, '|')) {
                if ($table !== []) {
                    break;
                }

                continue;
            }

            $cells = self::cells($trimmed);

            if ($header === null) {
                $header = $cells;

                continue;
            }

            if (self::isSeparator($cells)) {
                continue;
            }

            if (count($cells) !== count($header)) {
                throw new RuntimeException(sprintf(
                    '%s has a requirement row with %d cells where its header has %d: [%s]',
                    self::RECORD,
                    count($cells),
                    count($header),
                    $trimmed,
                ));
            }

            $table[] = array_combine($header, $cells);
        }

        if ($table === []) {
            throw new RuntimeException(sprintf('%s has no table under [%s].', self::RECORD, self::HEADING));
        }

        if (!array_key_exists(self::COLUMN, $table[0] ?? [])) {
            throw new RuntimeException(sprintf(
                '%s\'s requirements table has no [%s] column. It has: %s',
                self::RECORD,
                self::COLUMN,
                implode(', ', array_keys($table[0] ?? [])),
            ));
        }

        return $table;
    }

    /**
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
        if ($cells === []) {
            return false;
        }

        foreach ($cells as $cell) {
            if (preg_match('/^:?-{2,}:?$/', $cell) !== 1) {
                return false;
            }
        }

        return true;
    }
}
