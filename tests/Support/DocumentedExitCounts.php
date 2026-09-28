<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use ReflectionMethod;
use RuntimeException;
use Uak35\WeightedDbManager\Tests\Unit\Console\DbDoctorTest;
use Uak35\WeightedDbManager\Tests\Unit\Console\DbFlipPgcatCommandTest;
use Uak35\WeightedDbManager\Tests\Unit\Console\DbProbeReplicasCommandTest;

/**
 * The counts `docs/documented-exit-codes.md` states, each derived from the provider that owns it.
 *
 * WHY THIS EXISTS
 * ---------------
 *   A count written into a sentence is a second copy of a fact the code already holds, and it is
 *   the record — not the code — that a reader trusts. This record is where that cost was paid
 *   first: the flip's matrix sat at "fifteen" from the day the JSON work added a sixteenth cell,
 *   and a sentence beside it still said two ways a command can be refused when there were three.
 *   Nothing failed, because nothing read the sentences.
 *
 *   The counts here are therefore not written down at all. Each one is *derived* — from the data
 *   provider that pins the matrix, from the map from each cell to the documented case it is an
 *   instance of, or from the README table the commands are documented in — and
 *   `bin/counts.php` renders the record from these, so the sentence a reader sees is an
 *   output rather than a claim. `ProseNumbersTest` reads the same table and asserts the record
 *   still says what the derivation says, so the suite fails on drift even when nobody has run the
 *   renderer.
 *
 *   Two of the counts are stated twice, in two files: the record says why sixteen cells are eight
 *   documented cases, and the map's own docblock in `DbFlipPgcatCommandTest` says the same thing —
 *   and the docblock is the copy that had drifted, saying *two* ways a command can be refused
 *   where the map assigns three cells to that case. A number that drifted is a number that needs a
 *   reader in every place it is written, not only in the record, so both statements are claims and
 *   the renderer writes both.
 *
 *   One table for both because they are one rule: the renderer writes what the guard checks. A
 *   second copy of these expectations would be the very thing this file exists to remove.
 *
 * WHAT IS NOT HERE
 * ----------------
 *   Three numbers in the record are deliberately absent, and `docs/prose-numbers.md` names them:
 *   the four failure modes and the two behaviours that are still prose each count a list the
 *   sentence itself writes (a generator counting its own commas is not a fact about the code), and
 *   the "fifteen" and "two" in the known limitations are about a version of this package that no
 *   longer exists — rendering a historical number would be the drift, not the repair.
 */
final class DocumentedExitCounts
{
    /** The record these counts are stated in. */
    public const RECORD = 'docs/documented-exit-codes.md';

    /**
     * The test file whose map the four flip counts describe, and whose docblock states two of them.
     *
     * Written as a path rather than as a class name because one of the two readers — the renderer —
     * has to know which file to open, and a docblock is not reachable through reflection.
     */
    private const FLIP_MAP = 'tests/Unit/Console/DbFlipPgcatCommandTest.php';

    /** The README section the probe's exit table lives under. */
    private const PROBE_HEADING = '### Probing: `db:probe-replicas`';

    /** The README section the doctor's tables live under. */
    private const DOCTOR_HEADING = '### Preflight: `db:doctor`';

    /**
     * The documented case each phrase of the record's "ways" sentence is a paraphrase of.
     *
     * The sentence explains why a table with sixteen cells has eight cases: the cells of one case
     * are the several ways that case can happen. So the number is not a second fact — it is the
     * number of cells assigned to one documented case, which `documentedSituations()` already holds
     * — and what has to be declared is only which case the record's phrase is talking about, which
     * is a judgement, written once, here.
     *
     * Both values are README rows, quoted whole, so the judgement can be checked against the table
     * an operator reads rather than against this file's restatement of it. The first phrase is the
     * record's shorthand, and the row it names is wider than the phrase sounds: its three cells are
     * a flip that applied, a run with nothing to do, and a run another instance had already taken,
     * which the README calls the run doing its job — not the kinds that leave the file alone. That
     * is the reading this table binds, and it is written down here precisely because the shorthand
     * could be read the other way.
     *
     * @var array<string, string> the record's phrase => the documented case it names
     */
    private const FLIP_WAYS = [
        'ways a flip can politely do nothing' => 'a flip applied, nothing to do, or skipped by another instance',
        'ways a command can be refused' => 'a flag combination that is refused',
    ];

    /**
     * The commands whose exit code this record is about, in the order it names them.
     *
     * The record's own sentence — "three commands in this package are scheduled" — is the count of
     * *this* list, which is why it is a list here rather than a second sentence in the record: a
     * command that gains a matrix is added here, and the prose follows. Which commands those are
     * is a decision rather than a derivation (a command with one input does not need a table), so
     * this is the one count in the file that is not read out of the code — and the record says so.
     *
     * @var list<class-string>
     */
    private const MATRICES = [
        DbDoctorTest::class,
        DbProbeReplicasCommandTest::class,
        DbFlipPgcatCommandTest::class,
    ];

    /**
     * Every count the record states about one of those matrices or tables.
     *
     * The shape is `ProseNumbersTest`'s, because both readers consume it: the pattern has to match
     * (or the sentence the count lives in is gone), and *every* place it matches must state the
     * number the derivation has. `(?|…)` — a branch reset — is how one count stated three ways
     * stays one claim: every branch's number is group 1, which is what both readers rewrite and
     * compare. Two branches of one pattern, or two patterns that would match the same word, are
     * kept apart by anchoring on the words around the number rather than by trusting the order.
     *
     * An anchor never contains a number another claim owns. "The flip's table is eight documented
     * cases against sixteen cells" states two counts, and a pattern for the cells that matched on
     * the literal `eight` would stop matching the moment the *documented-case* count changed — one
     * occurrence of one count silently leaving the table, which is the failure this whole
     * mechanism exists to prevent.
     *
     * @return array<string, array{record: string, pinned: string, counts: string, expected: int}>
     */
    public static function claims(): array
    {
        return [
            'the commands whose exit code this record is about, as it counts them' => [
                'record' => self::RECORD,
                'pinned' => '/(\w+)(?: commands in this package are scheduled| are checked by mutation rather than by argument| tables, and the sentences naming the test that reads each one)/',
                'counts' => 'the matrices this class names — which commands have one is a decision, the record counts the list',
                'expected' => count(self::MATRICES),
            ],
            'the doctor\'s exit matrix, as documented-exit-codes.md counts its cells' => [
                'record' => self::RECORD,
                'pinned' => '/a (\w+)-cell matrix \(`test_the_exit_code_is_a_function_of_the_rows_and_the_strict_flag`\)/',
                'counts' => '`DbDoctorTest::exitCodeProvider()`',
                'expected' => self::cells(DbDoctorTest::class),
            ],
            'the doctor\'s documented cases, as documented-exit-codes.md counts them' => [
                'record' => self::RECORD,
                'pinned' => '/the doctor\'s (\w+) documented cases/',
                'counts' => 'the README\'s `db:doctor` exit table, which the doctor\'s matrix is written as',
                'expected' => self::readmeRows(self::DOCTOR_HEADING, 'the rows'),
            ],
            'the checks the doctor judges, as documented-exit-codes.md counts the README table' => [
                'record' => self::RECORD,
                'pinned' => '/the (\w+)-row description of what each check judges/',
                'counts' => 'the README\'s `db:doctor` check table',
                'expected' => self::readmeRows(self::DOCTOR_HEADING, 'Row'),
            ],
            'the probe\'s exit matrix, as documented-exit-codes.md counts its cells' => [
                'record' => self::RECORD,
                'pinned' => '/(?|a (\w+)-cell matrix, and this guard|The matrix has (\w+) cells and the table has|the sweep\'s \w+ documented cases, their numbers, and that every one of its (\w+) cells|\*\*The probe\'s (\w+) cells\.\*\*)/',
                'counts' => '`DbProbeReplicasCommandTest::exitCodeProvider()`',
                'expected' => self::cells(DbProbeReplicasCommandTest::class),
            ],
            'the probe\'s documented cases, as documented-exit-codes.md counts them' => [
                'record' => self::RECORD,
                'pinned' => '/(?|the table has (\w+) documented cases|the sweep\'s (\w+) documented cases|the probe\'s (\w+) rows document `0`)/',
                'counts' => 'the distinct cases `DbProbeReplicasCommandTest::documentedSituations()` names',
                'expected' => self::documentedCases(DbProbeReplicasCommandTest::class),
            ],
            'the probe\'s rows that document a zero, as documented-exit-codes.md counts them' => [
                'record' => self::RECORD,
                'pinned' => '/(\w+) of the probe\'s \w+ rows document `0`/',
                'counts' => 'the README\'s `db:probe-replicas` exit table, rows whose code is 0',
                'expected' => self::readmeRowsWithCode(self::PROBE_HEADING, 0),
            ],
            'the probe\'s rows that document a one, as documented-exit-codes.md counts them' => [
                'record' => self::RECORD,
                'pinned' => '/the probe\'s \w+ rows document `0` and (\w+) document `1`/',
                'counts' => 'the README\'s `db:probe-replicas` exit table, rows whose code is 1',
                'expected' => self::readmeRowsWithCode(self::PROBE_HEADING, 1),
            ],
            'the flip\'s exit matrix, as documented-exit-codes.md counts its cells' => [
                'record' => self::RECORD,
                // `\s+` before `cells` where this record's line wrap falls, so the claim is pinned
                // to the sentence rather than to the column it happens to break at.
                'pinned' => '/(?|a (\w+)-cell matrix, plus named tests|documented cases against (\w+)\s+cells|the flip\'s \w+ documented cases, their numbers, and that every one of its (\w+) cells|\*\*The flip\'s (\w+) cells\.\*\*)/',
                'counts' => '`DbFlipPgcatCommandTest::exitCodeProvider()`',
                'expected' => self::cells(DbFlipPgcatCommandTest::class),
            ],
            'the flip\'s documented cases, as documented-exit-codes.md counts them' => [
                'record' => self::RECORD,
                'pinned' => '/(?|table is (\w+) documented cases against|flip\'s (\w+) documented cases)/',
                'counts' => 'the distinct cases `DbFlipPgcatCommandTest::documentedSituations()` names',
                'expected' => self::documentedCases(DbFlipPgcatCommandTest::class),
            ],
            // The two halves of the sentence that explains the gap between the flip's sixteen cells
            // and its eight documented cases. The pattern carries the words that follow the number
            // ("are one case", "are another") because this record states the refused phrase twice:
            // once here, and once in its known limitations describing the sentence that drifted to
            // "two". Anchoring on the present-tense sentence is what keeps the renderer away from
            // the historical one — which is a number about a version of this package, not a claim.
            'the ways a flip can politely do nothing, as documented-exit-codes.md counts them' => [
                'record' => self::RECORD,
                'pinned' => '/(\w+) ways a flip can politely do nothing are one case/',
                'counts' => 'the cells `DbFlipPgcatCommandTest::documentedSituations()` assigns to the row `a flip applied, nothing to do, or skipped by another instance`',
                'expected' => self::waysIn('ways a flip can politely do nothing'),
            ],
            'the ways a command can be refused, as documented-exit-codes.md counts them' => [
                'record' => self::RECORD,
                // `\s+` where the record's own line wrap falls — the phrase reads as one sentence, and
                // a pattern that required it to sit on one line would be pinned to the record's
                // column size rather than to its wording.
                'pinned' => '/(\w+) ways a command can\s+be refused are another/',
                'counts' => 'the cells `DbFlipPgcatCommandTest::documentedSituations()` assigns to the row `a flag combination that is refused`',
                'expected' => self::waysIn('ways a command can be refused'),
            ],
            // The same two counts, where the map's own docblock states them. A claim is one record
            // and one pattern, so a number written in two files is two claims over one derivation.
            // The docblock is not a record in the same sense — it is prose beside the map it
            // describes, which is why it is the copy that drifted — and the guard treats it the
            // same way, because the failure it exists to catch does not care which file it is in.
            'the ways a flip can politely do nothing, as DbFlipPgcatCommandTest counts them' => [
                'record' => self::FLIP_MAP,
                'pinned' => '/(\w+) ways a flip can politely do nothing are one documented case/',
                'counts' => 'the cells `DbFlipPgcatCommandTest::documentedSituations()` assigns to the row `a flip applied, nothing to do, or skipped by another instance`',
                'expected' => self::waysIn('ways a flip can politely do nothing'),
            ],
            'the ways a command can be refused, as DbFlipPgcatCommandTest counts them' => [
                'record' => self::FLIP_MAP,
                'pinned' => '/(\w+) ways a command can be refused are another/',
                'counts' => 'the cells `DbFlipPgcatCommandTest::documentedSituations()` assigns to the row `a flag combination that is refused`',
                'expected' => self::waysIn('ways a command can be refused'),
            ],
        ];
    }

    /**
     * How many cells a command's matrix has — the provider is the matrix, so its row count is it.
     *
     * @param class-string $class
     */
    private static function cells(string $class): int
    {
        $provider = $class::exitCodeProvider();

        if ($provider === []) {
            throw new RuntimeException("{$class}::exitCodeProvider() is empty, and a matrix with no cells is not one");
        }

        return count($provider);
    }

    /**
     * How many documented cases a command's matrix is written as: the distinct README rows its
     * map names.
     *
     * The map is one entry per cell, so a cell added without a documented case fails the command's
     * own matrix test; what this adds is the number the record states *about* that map.
     *
     * @param class-string $class
     */
    private static function documentedCases(string $class): int
    {
        return count(array_unique(self::situations($class)));
    }

    /**
     * How many cells a matrix assigns to one documented case — the "ways" a case can happen.
     *
     * The phrase is the record's, and the case it paraphrases is declared in `FLIP_WAYS`: a phrase
     * the declaration does not know is a failure rather than a skip, because the alternative is a
     * count of the wrong case passing quietly. A case no cell is assigned to is a failure too — the
     * sentence exists to explain a group of cells, and a group of none explains nothing.
     */
    private static function waysIn(string $phrase): int
    {
        $case = self::FLIP_WAYS[$phrase] ?? throw new RuntimeException(
            "the record's phrase \"{$phrase}\" is not one this guard knows which documented case it paraphrases",
        );

        $ways = 0;

        foreach (self::situations(DbFlipPgcatCommandTest::class) as $situation) {
            if ($situation === $case) {
                $ways++;
            }
        }

        if ($ways === 0) {
            throw new RuntimeException(
                "no cell of `DbFlipPgcatCommandTest` is an instance of \"{$case}\", and the record counts the ways it can happen",
            );
        }

        return $ways;
    }

    /**
     * A matrix's map from each cell to the documented case it is an instance of, read by reflection.
     *
     * Private on purpose — the map is the command test's own business, and widening it for a
     * document would be the wrong reason to change its visibility.
     *
     * @param class-string $class
     *
     * @return array<mixed>
     */
    private static function situations(string $class): array
    {
        $method = new ReflectionMethod($class, 'documentedSituations');
        $situations = $method->invoke(null);

        if (! is_array($situations) || $situations === []) {
            throw new RuntimeException("{$class}::documentedSituations() no longer returns a map, and a count is read from it");
        }

        return $situations;
    }

    /**
     * The rows of a README table — the count behind "the eleven-row description" and its siblings.
     */
    private static function readmeRows(string $heading, string $column): int
    {
        $rows = Readme::table($heading, $column);

        if ($rows === []) {
            throw new RuntimeException("the README has no rows under [{$heading}] with a [{$column}] column to count");
        }

        return count($rows);
    }

    /**
     * The rows of a README exit table that document one code.
     *
     * The probe's table is the one place a record breaks a count down by code — "three of the
     * probe's five rows document `0` and two document `1`" — and both halves of that sentence are
     * read out of the table rather than counted by a person.
     */
    private static function readmeRowsWithCode(string $heading, int $code): int
    {
        $rows = self::readmeRowsWithExitColumn($heading);
        $matching = 0;

        foreach ($rows as $row) {
            if (Readme::code($row['exit']) === $code) {
                $matching++;
            }
        }

        return $matching;
    }

    /**
     * @return list<array<string, string>>
     */
    private static function readmeRowsWithExitColumn(string $heading): array
    {
        return Readme::table($heading, 'exit');
    }
}
