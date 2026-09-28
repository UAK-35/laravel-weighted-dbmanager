<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Uak35\WeightedDbManager\Console\Commands\DbDoctor;
use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Database\Weighted\WeightResolver;
use Uak35\WeightedDbManager\Pgcat\SupervisorStep;
use Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider;
use Uak35\WeightedDbManager\Tests\Support\DocumentedExitCounts;
use Uak35\WeightedDbManager\Tests\Support\NumberWords;
use Uak35\WeightedDbManager\Tests\Support\Readme;
use Uak35\WeightedDbManager\Tests\Unit\Support\ReplicaMetadataTest;
use Uak35\WeightedDbManager\Tests\Unit\Weighted\WeightedDatabaseServiceProviderTest;

/**
 * The numbers this package's records state in prose, compared with the numbers the code has.
 *
 * WHY THIS EXISTS
 * ---------------
 * A count written into a sentence is a restatement, and a restatement is not a guard: nothing
 * fails when the thing it describes grows a member, and the reader of the record is the one who
 * finds out. That is not hypothetical here — `docs/documented-exit-codes.md` records the flip's
 * cell count sitting at "fifteen" from the day the JSON work added a sixteenth cell, with a
 * sentence beside it still saying *two* ways a command can be refused when there were three, and
 * two other records were found carrying counts and test names that had been overtaken.
 *
 * The rule this file enforces: **every number a record states about this package's code is
 * either derived from the code or compared with it.** A record may still *say* the number — an
 * operator reads a sentence, not a constant — but the sentence is now a claim something checks,
 * so a member added without the sentence being updated fails the suite instead of shipping.
 *
 * Three properties are deliberate:
 *
 *   1. **The words are the subject.** Each claim is pinned to the sentence it is written in, and
 *      the pattern has to match at least once — and *every* place it matches has to state the same
 *      number, so a record that says "three" in one paragraph and "four" in another fails on the
 *      second. A reworded sentence therefore fails this test rather than quietly passing it,
 *      because a guard that has stopped finding its subject is worse than no guard: it reports
 *      agreement with a claim it never read. Rewording a bound sentence is a two-file change, and
 *      that is the price of the number being checked at all.
 *   2. **The spelling is the record's, and the comparison is numeric.** The records write numbers
 *      as words ("sixteen cells"), so the capture is mapped through `NumberWords` and an unknown
 *      word is a failure rather than a skip. A record that started writing "16" would fail here.
 *   3. **The expected value is computed, never written.** Every claim below derives its number
 *      from a constant, a list or a source file, so this table holds no number of its own — a
 *      guard whose expectation is a literal is the restatement it is supposed to catch.
 *
 * Values that live in the *source* rather than in a constant — how many finding lists a boot
 * spreads, how many `logContext()` call sites there are, which of the supervisor's faults fail —
 * are counted out of the file they are written in. That is a check rather than a derivation: the
 * taxonomy *is* those branches, so the alternative would be a constant somebody has to keep in
 * step with them, which is the restatement this file exists to catch.
 *
 * ## Checked here, or rendered into the record
 *
 * A count can be kept in step two ways, and this file carries both. Some are *checked*: the
 * derivation lives in the table below and the record is asserted to agree with it, which is what
 * this test does. Others are *rendered*: the derivation lives in
 * `tests/Support/DocumentedExitCounts` and `bin/counts.php` writes the record from it, so the
 * sentence a reader sees is an output rather than a claim. Both read the same table — `claims()`
 * merged with `DocumentedExitCounts::claims()` — because a second copy of the derivations would be
 * the restatement this file exists to catch. Which records are rendered is a decision about how
 * much prose a generator should own; `docs/prose-numbers.md` lists both halves.
 *
 * ## What this file does not cover, and why
 *
 * A number that counts *surfaces* or *design kinds* rather than a list the code holds — "one
 * classifier, three callers", "the three steps that cannot be undone", the four states the
 * `Audit:` block is rendered in — is prose about the design rather than a restatement of a fact
 * in the code, and there is nothing to compare it with. Those are named here so the boundary is
 * written down rather than implied; every number that *would* break a build is bound, and the
 * ones that need the command to run (the README's check table, the doctor's row counts, the rows
 * that can carry a suggestion) are bound in `DbDoctorTest`, where the fixtures are.
 *
 * The record that states the rule, the full list of bound claims, and how to add one is
 * [docs/prose-numbers.md](../../../docs/prose-numbers.md).
 */
final class ProseNumbersTest extends TestCase
{
    /**
     * Every claim this package makes about a number in prose, checked or rendered.
     *
     * Two tables, one shape, because both readers can use either: the rows here, and the counts
     * `bin/counts.php` renders from `DocumentedExitCounts`. The guard asserts the records against
     * both; the renderer writes the records from both. A claim that moved from checked to rendered
     * therefore moved between these two files rather than being written down a second time.
     *
     * @return array<string, array{record: string, pinned: string, counts: string, expected: int}>
     */
    public static function proseNumbers(): array
    {
        return array_merge(self::claims(), DocumentedExitCounts::claims());
    }

    /**
     * The claims this file derives itself: which record, the sentence it is written in, what it
     * counts, and the number the code has.
     *
     * The count is derived here — from a constant, a list, a class's own tests, or the source file
     * the thing is implemented in — so a claim cannot be satisfied by editing this table. The
     * failure message names the record and the sentence, because the repair is almost always in
     * the record rather than in the code.
     *
     * @return array<string, array{record: string, pinned: string, counts: string, expected: int}>
     */
    private static function claims(): array
    {
        return [
            'the audit\'s finding keys, as boot-audit-surfaces.md counts them' => [
                'record' => 'docs/boot-audit-surfaces.md',
                'pinned' => '/(?:pgcat is one of|would put) (\w+) keys/',
                'counts' => 'the provider\'s `KEY_*` constants',
                'expected' => count(self::constantsOn(WeightedDatabaseServiceProvider::class, 'KEY_')),
            ],
            'the shapes the log-severity decision was weighed against, as README.md counts them' => [
                'record' => 'README.md',
                'pinned' => '/the (\w+) shapes it was weighed against/',
                'counts' => 'the candidate headings of `boot-audit-log-severity.md`, less the chosen one',
                'expected' => self::recordCandidates('docs/boot-audit-log-severity.md'),
            ],
            'the commands the provider registers, as README.md counts them' => [
                'record' => 'README.md',
                'pinned' => '/All (\w+) are registered by the provider/',
                'counts' => 'the command classes in `src/Console/Commands/`',
                'expected' => self::commandCount(),
            ],
            'the finding lists one boot spreads, as boot-audit-finding-keys.md counts them' => [
                'record' => 'docs/boot-audit-finding-keys.md',
                'pinned' => '/the (\w+) methods whose lists are spread into one boot\'s findings/',
                'counts' => 'the `...$this->` spreads in `reportBootAudit()`',
                'expected' => self::spreadCount(),
            ],
            'the assembly methods the provider spreads today, as boot-audit-finding-keys.md counts them' => [
                'record' => 'docs/boot-audit-finding-keys.md',
                'pinned' => '/it spreads (\w+) today/',
                'counts' => 'the `...$this->` spreads in `reportBootAudit()`',
                'expected' => self::spreadCount(),
            ],
            'the findings in the richest boot, as boot-audit-finding-keys.md counts them' => [
                'record' => 'docs/boot-audit-finding-keys.md',
                'pinned' => '/(\w+) findings, \w+ keys, the exact set/',
                'counts' => '`WeightedDatabaseServiceProviderTest::RICHEST_BOOT_KEYS`',
                'expected' => count(WeightedDatabaseServiceProviderTest::RICHEST_BOOT_KEYS),
            ],
            'the keys in the richest boot, as boot-audit-finding-keys.md counts them' => [
                'record' => 'docs/boot-audit-finding-keys.md',
                'pinned' => '/\w+ findings, (\w+) keys, the exact set/',
                'counts' => '`WeightedDatabaseServiceProviderTest::RICHEST_BOOT_KEYS`',
                'expected' => count(WeightedDatabaseServiceProviderTest::RICHEST_BOOT_KEYS),
            ],
            'the replica settings the audit refuses, as boot-audit-finding-keys.md counts them' => [
                'record' => 'docs/boot-audit-finding-keys.md',
                'pinned' => '/all (\w+) replica settings unreadable/',
                'counts' => 'the provider\'s `REPLICA_METADATA` table',
                'expected' => count(self::privateConstant(WeightedDatabaseServiceProvider::class, 'REPLICA_METADATA')),
            ],
            'the boot lines that carry a severity, as boot-audit-log-severity.md counts them' => [
                'record' => 'docs/boot-audit-log-severity.md',
                'pinned' => '/`logContext\(\)`, the (\w+) call sites/',
                'counts' => 'the `self::logContext(` calls in `BootAudit.php`',
                'expected' => self::countInSource('src/Support/BootAudit.php', '/self::logContext\(/'),
            ],
            // The counts `docs/documented-exit-codes.md` states are *rendered* rather than checked:
            // they live in `DocumentedExitCounts`, which this class merges in above and
            // `bin/counts.php` writes the record from. See the class docblock.
            'the rows a doctor asks, as db-doctor-json.md counts them in its opening claim' => [
                'record' => 'docs/db-doctor-json.md',
                'pinned' => '/A doctor has\s+(\w+) verdicts, one per row/',
                'counts' => 'the README\'s `db:doctor` check table, which `DbDoctorTest` binds to the rows the command builds',
                'expected' => count(self::readmeChecks()),
            ],
            'the rows a doctor asks on a run that resolves, as db-doctor-json.md counts them in its limit' => [
                'record' => 'docs/db-doctor-json.md',
                'pinned' => '/resolve, (\w+) otherwise/',
                'counts' => 'the README\'s `db:doctor` check table, which `DbDoctorTest` binds to the rows the command builds',
                'expected' => count(self::readmeChecks()),
            ],
            'the kinds a flip run can reach, as db-doctor-json.md counts them' => [
                'record' => 'docs/db-doctor-json.md',
                'pinned' => '/one of (\w+)\s+values a run can reach/',
                'counts' => 'the README\'s `kind` table, which `DbFlipPgcatCommandTest` binds to the matrix',
                'expected' => count(Readme::table('### The JSON report: one object for a pipeline', 'kind')),
            ],
            'the reasons a replica is excluded, as pool-exclusions.md counts them' => [
                'record' => 'docs/pool-exclusions.md',
                'pinned' => '/reason vocabulary is closed at (\w+)/',
                'counts' => '`WeightResolver::EXCLUSION_REASONS`',
                'expected' => count(WeightResolver::EXCLUSION_REASONS),
            ],
            'the supervisor faults a flip refuses on, as pgcat-supervisor-preflight.md counts them' => [
                'record' => 'docs/pgcat-supervisor-preflight.md',
                'pinned' => '/catches all (\w+) faults this record lists/',
                'counts' => 'the `usable: false` verdicts in `SupervisorStep::inspect()`',
                'expected' => count(self::failingFaults()),
            ],
            'the keys the envelope writes, as command-json-envelope.md counts them' => [
                'record' => 'docs/command-json-envelope.md',
                'pinned' => '/(?|writes (\w+) keys, in the same order, for every report|holds the (\w+) keys in the order|one of the (\w+)\s+core keys)/',
                'counts' => '`JsonEnvelope::CORE`',
                'expected' => count(JsonEnvelope::CORE),
            ],
            'the metadata floors, as replica-metadata-refusal.md counts them' => [
                'record' => 'docs/replica-metadata-refusal.md',
                'pinned' => '/the (\w+) `\*_FLOOR` constants/',
                'counts' => 'the `*_FLOOR` constants on `ReplicaMetadata`',
                'expected' => self::countInSource('src/Support/ReplicaMetadata.php', '/const \w+_FLOOR =/'),
            ],
            'the classifier\'s cases, as replica-metadata-refusal.md counts them' => [
                'record' => 'docs/replica-metadata-refusal.md',
                'pinned' => '/`ReplicaMetadataTest` \((\w+) cases\)/',
                'counts' => 'the test methods `ReplicaMetadataTest` declares',
                'expected' => self::testMethodCount(ReplicaMetadataTest::class),
            ],
            'the refusal keys for the switches, as switch-values.md counts them' => [
                'record' => 'docs/switch-values.md',
                'pinned' => '/(\w+) keys, not one `switches.refused`/',
                'counts' => 'the provider\'s `SWITCHES` table',
                'expected' => count(self::privateConstant(WeightedDatabaseServiceProvider::class, 'SWITCHES')),
            ],
        ];
    }

    /**
     * The number in the sentence is the number the code has — and the sentence is still there.
     *
     * The presence of the claim is asserted first and separately, because the failure it describes
     * is the one that hides: a claim whose wording moved would otherwise compare nothing with
     * nothing and pass, which is how a guard becomes a decoration. Every statement of a claim is
     * then compared, so a number copied into a second paragraph is checked there too rather than
     * being counted and forgotten.
     */
    #[DataProvider('proseNumbers')]
    public function test_the_number_a_record_states_is_the_number_the_code_has(
        string $record,
        string $pinned,
        string $counts,
        int $expected,
    ): void {
        $source = self::read($record);

        $matches = [];

        $this->assertGreaterThanOrEqual(
            1,
            preg_match_all($pinned, $source, $matches),
            "{$record} no longer states the claim this guard is pinned to ({$pinned}). Rewording a bound "
            .'sentence is a change to this test as well as to the record — otherwise the number stops '
            .'being checked without anything failing.',
        );

        foreach ($matches[1] as $word) {
            $this->assertSame(
                $expected,
                NumberWords::toInt($word),
                "{$record} states {$word} where {$counts} has {$expected}. The record is the thing to repair.",
            );
        }
    }

    /**
     * Every claim the guard makes is written down in the record, with the number the guard derives.
     *
     * This guard keeps the records in step with the code; without this test, the guard's own record
     * could fall behind the guard — a claim added to the table above and never written down, or a
     * number typed into the record that the code disagrees with. That is the same defect one level
     * up, and it is checked the same way: the record is read as data, the claim names are compared
     * with the provider, and every bolded number beside a claim is compared with the claim's
     * expectation.
     *
     * The record states the numbers on purpose — a reader looking up *which* claims are bound wants
     * to see them — and stating them is exactly what makes them something to check.
     */
    public function test_every_claim_the_guard_makes_is_written_down_in_the_record(): void
    {
        $documented = self::documentedClaims();

        $this->assertNotSame([], $documented, 'the record lists the claims it describes, and a guard reads them');

        $this->assertEqualsCanonicalizing(
            array_keys(self::proseNumbers()),
            array_keys($documented),
            'docs/prose-numbers.md lists the claims the guard makes, and nothing the guard does not: '.implode(', ', array_diff(
                array_keys($documented),
                array_keys(self::proseNumbers()),
            )),
        );

        foreach ($documented as $claim => $stated) {
            $expected = self::proseNumbers()[$claim]['expected'];

            $this->assertNotSame(
                [],
                $stated,
                "docs/prose-numbers.md states no number against \"{$claim}\", so nothing in it is checked",
            );

            foreach ($stated as $word) {
                $this->assertSame(
                    $expected,
                    NumberWords::toInt($word),
                    "docs/prose-numbers.md says {$word} against \"{$claim}\" where the guard derives {$expected}.",
                );
            }
        }
    }

    /**
     * The claims that need the command to have run are written down in the record as well.
     *
     * They live in `DbDoctorTest` rather than here because they need the fixtures; the record is
     * the one place a reader can see the whole set, so a claim pinned there and not written down
     * here would be a rule with no index. The patterns are read out of the test's source, which is
     * what makes this a comparison rather than a second copy: the doc has to name the pattern the
     * test actually pins, so renaming either failure mode is caught.
     */
    public function test_the_record_lists_the_claims_that_need_the_command_to_run(): void
    {
        $record = self::read('docs/prose-numbers.md');

        preg_match_all(
            "/numberClaim\('([^']+)', '([^']+)'\)/",
            self::read('tests/Unit/Console/DbDoctorTest.php'),
            $matches,
        );

        $this->assertNotSame([], $matches[2], 'DbDoctorTest pins no number claims, and this guard reads them');

        foreach ($matches[2] as $index => $pattern) {
            // The one pattern with a backtick in it is written in the record with the backtick
            // escaped, because a raw one would end the code span the pattern sits in. Nothing
            // else is normalised: a `\w` in the pattern is a `\w` in the record.
            $this->assertStringContainsString(
                $pattern,
                str_replace('\\`', '`', $record),
                "docs/prose-numbers.md does not write down the claim DbDoctorTest pins in {$matches[1][$index]} ({$pattern})",
            );
        }
    }

    /**
     * The three tables that know what a switch refusal is agree about which switches there are.
     *
     * `docs/switch-values.md` named this as an unchecked restatement: the provider files a finding
     * per switch, `db:doctor` renders its row from its own `SWITCH_KEYS`, and each states the
     * consequence in its own words. What the two tables hold is not the record's to keep in step —
     * it is compared here, so a switch added to one and forgotten in another fails rather than
     * becoming a setting with a finding and no row, or a row whose key nothing writes.
     */
    public function test_the_three_switch_tables_list_the_same_settings(): void
    {
        $provider = array_keys(self::privateConstant(WeightedDatabaseServiceProvider::class, 'SWITCHES'));
        $keys = array_keys(self::privateConstant(DbDoctor::class, 'SWITCH_KEYS'));
        $consequences = array_keys(self::privateConstant(DbDoctor::class, 'SWITCH_CONSEQUENCES'));

        $this->assertNotSame([], $provider, 'the tables have to name the switches for this to assert anything');

        $this->assertSame($provider, $keys, 'the row names the settings the boot files findings for');
        $this->assertSame($provider, $consequences, 'every setting a row names has a consequence to state');
    }

    /**
     * The constants of one class whose names start with a prefix, as a list.
     *
     * @return list<string>
     */
    private static function constantsOn(string $class, string $prefix): array
    {
        $constants = (new ReflectionClass($class))->getConstants();

        return array_values(array_filter(
            array_keys($constants),
            static fn (string $name): bool => str_starts_with($name, $prefix),
        ));
    }

    /**
     * A class constant that is `private`, read without widening the production API for a test.
     */
    private static function privateConstant(string $class, string $name): mixed
    {
        $constant = (new ReflectionClass($class))->getReflectionConstant($name);

        if ($constant === false) {
            throw new RuntimeException("{$class}::{$name} is gone, and a guard is pinned to it");
        }

        return $constant->getValue();
    }

    /**
     * How many lists one boot's findings are assembled from — counted in the method that does it.
     *
     * The spread is the marker: each assembly is `...$this->xFindings(…)` inside `reportBootAudit()`,
     * and that method's body is taken out of the file so a spread elsewhere cannot inflate the count.
     */
    private static function spreadCount(): int
    {
        return self::countInSource(
            'src/Providers/WeightedDatabaseServiceProvider.php',
            '/\.\.\.\$this->/',
            'private function reportBootAudit(): void',
        );
    }

    /**
     * The supervisor faults a flip refuses on: the verdicts `inspect()` builds with `usable: false`.
     *
     * @return list<string>
     */
    private static function failingFaults(): array
    {
        preg_match_all('/usable: false,\s+fault: self::FAULT_(\w+)/', self::read('src/Pgcat/SupervisorStep.php'), $matches);

        $faults = $matches[1];

        self::assertFaultTaxonomyIsWhole($faults);

        return $faults;
    }

    /**
     * The faults the step refuses on are exactly the `usable: false` ones, and every fault the
     * class declares is one it builds.
     *
     * Without the second half, a fault added to the class and wired into a refusal would leave the
     * record's sentence describing a taxonomy that has grown — "the six faults" would be comparing
     * a stale subset against itself. The check is against the *branches*, not against a second
     * list written here: a hardcoded "and these two pass" is the very thing this guard exists to
     * catch, and it would have to be edited by whoever adds a fault that passes, which is a rule
     * nobody reads. Every `fault:` value the file builds — refused, passed, or the two halves of
     * the one conditional verdict — must be a declared `FAULT_*`, and every declared fault must be
     * built somewhere, so a typo, an orphan or a rename fails here.
     *
     * Which half a fault is in is then a fact about the code rather than about this file: the
     * record counts the refused ones, and a fault that does not refuse changes no sentence.
     *
     * @param list<string> $failing
     */
    private static function assertFaultTaxonomyIsWhole(array $failing): void
    {
        $declared = array_map(
            static fn (string $name): string => substr($name, strlen('FAULT_')),
            self::constantsOn(SupervisorStep::class, 'FAULT_'),
        );

        // Every `fault:` the class builds: the plain verdicts, and the one ternary whose two
        // branches are two faults.
        preg_match_all(
            '/fault: (?:self::FAULT_(\w+)|\$drivesSupervisorctl \? self::FAULT_(\w+) : self::FAULT_(\w+))/',
            self::read('src/Pgcat/SupervisorStep.php'),
            $matches,
        );

        $built = array_values(array_unique(array_filter(array_merge($matches[1], $matches[2], $matches[3]))));

        sort($declared);
        sort($built);

        if ($declared !== $built) {
            throw new RuntimeException(
                'SupervisorStep declares faults this guard cannot account for: declared ['.implode(', ', $declared)
                .'], built ['.implode(', ', $built).']. Every declared fault is one the step builds somewhere, and '
                .'every fault it builds is one it declares — a refused one is `usable: false` and is what '
                .'docs/pgcat-supervisor-preflight.md counts.',
            );
        }
    }

    private static function testMethodCount(string $class): int
    {
        return count(array_filter(
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => str_starts_with($method->getName(), 'test'),
        ));
    }

    /**
     * The claims `docs/prose-numbers.md` writes down, and the numbers it states beside each.
     *
     * The record's bound table is the guard's own list as prose, keyed by the claim name the data
     * provider uses — that is what lets the two be compared. A row that does not have three cells,
     * or a number word the guard does not know, raises: a guard that silently read half the table
     * would agree with a record it never finished reading.
     *
     * @return array<string, list<string>>
     */
    private static function documentedClaims(): array
    {
        $claims = [];
        $inside = false;

        foreach (explode("\n", self::read('docs/prose-numbers.md')) as $line) {
            $line = trim($line);

            if (str_starts_with($line, '## The claims that are bound')) {
                $inside = true;

                continue;
            }

            if (! $inside) {
                continue;
            }

            if (str_starts_with($line, 'The claims that need the command to have run')) {
                break;
            }

            if (! str_starts_with($line, '|') || str_starts_with($line, '| Claim') || str_starts_with($line, '|---')) {
                continue;
            }

            $cells = array_map(
                static fn (string $cell): string => trim(str_replace('`', '', $cell)),
                explode('|', trim($line, '| ')),
            );

            if (count($cells) !== 3) {
                throw new RuntimeException(
                    'a claim row in docs/prose-numbers.md has '.count($cells).' cells where its header has three: '.$line,
                );
            }

            preg_match_all('/\*\*(\w+)\*\*/', $cells[1], $matches);

            foreach ($matches[1] as $word) {
                NumberWords::toInt($word);
            }

            $claims[$cells[0]] = $matches[1];
        }

        return $claims;
    }

    /**
     * The README's `db:doctor` check table, which is the list of rows the command builds.
     *
     * @return list<array<string, string>>
     */
    private static function readmeChecks(): array
    {
        return Readme::table('### Preflight: `db:doctor`', 'Row');
    }

    /**
     * The candidates one design record names, less the one it chose.
     *
     * A record's candidate headings *are* the record — there is no constant behind "the seven
     * shapes it was weighed against", because the shapes are the headings the record writes. So
     * the README's sentence is compared with them, which is what makes adding a shape a change to
     * both files rather than a sentence that quietly counts the wrong thing.
     */
    private static function recordCandidates(string $record): int
    {
        $source = self::read($record);

        $all = preg_match_all('/^### [A-Z] — .*$/m', $source);
        $chosen = preg_match_all('/^### [A-Z] — .*\(chosen\)$/m', $source);

        if ($all < 2 || $chosen !== 1) {
            throw new RuntimeException(
                "{$record} no longer reads as a candidate list with one chosen heading ({$all} candidates, "
                ."{$chosen} chosen), and a guard counts the shapes it was weighed against.",
            );
        }

        return $all - $chosen;
    }

    /**
     * The command classes the package ships — the four the README says the provider registers.
     */
    private static function commandCount(): int
    {
        $commands = glob(dirname(__DIR__, 3).'/src/Console/Commands/*Command.php');
        $reports = glob(dirname(__DIR__, 3).'/src/Console/Commands/Db*.php');

        if ($commands === false || $reports === false || $commands === []) {
            throw new RuntimeException('the command directory is not where a guard expects it');
        }

        return count($reports);
    }

    /**
     * How often a pattern appears in one file — or in one method's body, when a method is named.
     */
    private static function countInSource(string $relative, string $pattern, ?string $method = null): int
    {
        $source = self::read($relative);

        if ($method !== null) {
            $start = strpos($source, $method);

            if ($start === false) {
                throw new RuntimeException("{$relative} no longer declares {$method}, which a guard counts in");
            }

            $end = strpos($source, "\n    }", $start);

            if ($end === false) {
                throw new RuntimeException("the body of {$method} does not end where this guard expects");
            }

            $source = substr($source, $start, $end - $start);
        }

        return preg_match_all($pattern, $source);
    }

    /**
     * A record or a source file, read from the package root.
     */
    private static function read(string $relative): string
    {
        $source = @file_get_contents(dirname(__DIR__, 3).'/'.$relative);

        if ($source === false) {
            throw new RuntimeException("a guard cannot read {$relative}, and a bound claim needs its file to exist");
        }

        return $source;
    }

}
