<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Support\BootAuditFinding;
use Uak35\WeightedDbManager\Support\UnreadableRecord;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The record and the view over it: what a boot remembers about a finding, and what a
 * reader that has the record and not the log can still say about it.
 *
 * The surfaces that show findings — `/health/db` and `db:replica-status` — read the
 * same list this class produces, so the rules pinned here are the rules both of them
 * follow: oldest first, an age in words, and a level and a sentence that survive the
 * process that logged them.
 */
class BootAuditTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    /** The record the audit under test writes, so a test can assert on the file. */
    private ?string $auditFile = null;

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    public function test_a_report_remembers_the_sentence_and_the_level_it_was_logged_at(): void
    {
        $audit = $this->audit();

        $audit->report($this->emptyRecord(), [
            new BootAuditFinding(
                key: 'swrr.reader_windows.refused',
                warning: 'reader_windows[0] is not a window.',
                resolution: 'swrr.reader_windows is readable again.',
                context: ['windows_in_use' => 0],
                level: 'error',
            ),
        ], checked: ['swrr.reader_windows.refused']);

        // Read back through the class, which is what the endpoint and the command do.
        $standing = $audit->standing();

        $this->assertCount(1, $standing);
        $this->assertSame('swrr.reader_windows.refused', $standing[0]['key']);
        $this->assertSame('error', $standing[0]['level']);
        $this->assertSame('reader_windows[0] is not a window.', $standing[0]['warning']);
        $this->assertSame('swrr.reader_windows is readable again.', $standing[0]['resolution']);
        $this->assertSame(['windows_in_use' => 0], $standing[0]['context']);
        $this->assertNotEmpty($standing[0]['first_reported_at']);

        // And out of the record itself, because the record is what crosses processes.
        $recorded = $audit->read()['findings']['swrr.reader_windows.refused'] ?? [];

        $this->assertSame('error', $recorded['level'] ?? null);
        $this->assertSame('reader_windows[0] is not a window.', $recorded['warning'] ?? null);
        $this->assertSame(0, $recorded['context']['windows_in_use'] ?? null);
    }

    public function test_a_report_remembers_the_scope_the_finding_was_written_in(): void
    {
        $audit = $this->audit();

        $scope = [
            'connection' => 'pgsql_proxy',
            'driver' => 'pgsql',
            'source' => 'db-manager.swrr.connection',
            'app_env' => 'production',
        ];

        $audit->report($this->emptyRecord(), [
            new BootAuditFinding(
                key: 'swrr.pgcat.gate',
                warning: 'pgcat is armed where it cannot act.',
                resolution: 'The pgcat mismatch no longer applies.',
            ),
        ], checked: ['swrr.pgcat.gate'], scope: $scope);

        // Read back through the class, which is what the endpoint and the command do — and out
        // of the record, because the record is what crosses processes.
        $this->assertSame($scope, $audit->standing()[0]['scope']);
        $this->assertSame($scope, $audit->read()['findings']['swrr.pgcat.gate']['scope'] ?? null);
    }

    public function test_a_record_written_before_scopes_were_kept_reads_as_no_scope(): void
    {
        // The scope is not a finding's identity, so an entry that predates it is still a finding:
        // "not known" is what an empty scope says, and a reader must not round it to a match.
        $audit = $this->audit();

        $this->putRecord([
            'swrr.pgcat.gate' => [
                'resolution' => 'The pgcat mismatch no longer applies.',
                'warning' => 'pgcat is armed where it cannot act.',
                'level' => 'warning',
                'context' => [],
                'first_reported_at' => '2026-09-21T08:15:00+00:00',
            ],
        ]);

        $this->assertSame([], $audit->standing()[0]['scope']);
    }

    public function test_the_block_carries_the_live_reading_beside_the_record(): void
    {
        $path = $this->tempDir() . '/audit.json';

        file_put_contents($path, (string) json_encode([
            'findings' => [
                'swrr.reader_windows.refused' => [
                    'resolution' => 'swrr.reader_windows is readable again.',
                    'warning' => 'swrr.reader_windows is "10:00-14:20", not a list of windows.',
                    'level' => 'error',
                    'context' => [],
                    'first_reported_at' => '2026-09-21T08:15:00+00:00',
                    'scope' => [
                        'connection' => 'sqlite',
                        'driver' => 'sqlite',
                        'source' => 'db-manager.swrr.connection',
                        'app_env' => 'sqlite-live',
                    ],
                ],
            ],
            'store_probed_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $scope = [
            'connection' => 'pgsql_proxy',
            'driver' => 'pgsql',
            'source' => 'db-manager.swrr.connection',
            'app_env' => 'production',
        ];

        $audit = new BootAudit($path, 0, currentFindings: static fn (): array => [
            'scope' => $scope,
            'evaluated' => ['swrr.reader_windows.refused'],
            'findings' => [],
        ]);

        $block = BootAudit::reported($audit);

        // The recorded half is untouched: the finding, its sentence, and how long it has stood.
        $this->assertSame(1, $block['count']);
        $this->assertSame('2026-09-21T08:15:00+00:00', $block['oldest']);
        $this->assertSame('error', $block['severity']);

        // The live half is beside it, with the scope it was taken in.
        $this->assertSame($scope, $block['scope']);
        $this->assertNotNull($block['checked_at']);
        $this->assertTrue($block['current']['available']);
        $this->assertSame(0, $block['current']['count']);
        $this->assertSame('none', $block['current']['severity']);

        // And the recorded finding says what this process knows: it was evaluated here, it does
        // not apply here, and the boot that wrote it was somewhere else entirely.
        $this->assertSame(
            ['evaluated' => true, 'standing' => false, 'scope_matches' => false],
            $block['findings'][0]['current'],
        );
    }

    public function test_a_key_the_live_half_did_not_evaluate_is_not_called_cleared(): void
    {
        // The store's reachability is the one key a surface cannot have answered without paying
        // for a probe. "Not evaluated" and "does not apply" are different claims, and this is
        // the field that keeps them apart.
        $path = $this->tempDir() . '/audit.json';

        file_put_contents($path, (string) json_encode([
            'findings' => [
                'swrr.primary_store.unreachable' => [
                    'resolution' => 'The configured primary store is not unreachable any more.',
                    'warning' => 'The primary store [redis(default)] could not serve a read: Connection refused.',
                    'level' => 'warning',
                    'context' => [],
                    'first_reported_at' => '2026-09-27T08:15:00+00:00',
                    'scope' => [
                        'connection' => 'sqlite',
                        'driver' => 'sqlite',
                        'source' => 'db-manager.swrr.connection',
                        'app_env' => 'sqlite-live',
                    ],
                ],
            ],
            'store_probed_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $audit = new BootAudit($path, 0, currentFindings: static fn (): array => [
            'scope' => [
                'connection' => 'pgsql_proxy',
                'driver' => 'pgsql',
                'source' => 'db-manager.swrr.connection',
                'app_env' => 'production',
            ],
            'evaluated' => ['swrr.reader_windows.refused'],
            'findings' => [],
        ]);

        $block = BootAudit::reported($audit);

        $this->assertSame(
            ['evaluated' => false, 'standing' => null, 'scope_matches' => false],
            $block['findings'][0]['current'],
        );
    }

    public function test_a_block_with_no_live_evaluator_says_so_rather_than_inventing_one(): void
    {
        $block = BootAudit::reported($this->audit());

        $this->assertNull($block['scope']);
        $this->assertNull($block['checked_at']);
        $this->assertFalse($block['current']['available']);
        $this->assertNotNull($block['current']['error']);
    }

    public function test_a_live_evaluation_that_throws_leaves_the_record_readable(): void
    {
        // A diagnostic must never be the thing that stops the page it reports on: a live half
        // that could not run is published as unavailable, and the record still crosses.
        $audit = $this->audit();

        $audit->report($this->emptyRecord(), [
            new BootAuditFinding(
                key: 'swrr.pgcat.gate',
                warning: 'pgcat is armed where it cannot act.',
                resolution: 'The pgcat mismatch no longer applies.',
            ),
        ], checked: ['swrr.pgcat.gate'], scope: [
            'connection' => 'pgsql',
            'driver' => 'pgsql',
            'source' => 'database.default',
            'app_env' => 'production',
        ]);

        $exploding = new BootAudit((string) $this->auditFile, 0, currentFindings: static function (): array {
            throw new \RuntimeException('the producers are not registered');
        });

        $block = BootAudit::reported($exploding);

        $this->assertSame(1, $block['count']);
        $this->assertFalse($block['current']['available']);
        $this->assertStringContainsString('the producers are not registered', (string) $block['current']['error']);
        $this->assertSame(
            ['evaluated' => false, 'standing' => null, 'scope_matches' => null],
            $block['findings'][0]['current'],
        );
    }

    public function test_a_finding_that_shares_a_key_is_logged_rather_than_replaced(): void
    {
        // The record is keyed by finding key, so two findings under one key are one entry.
        // Folding before logging would lose the replaced sentence outright — a problem the
        // installation has, reported by nobody — so every finding is logged, and the
        // collision is named as the defect in the package that it is.
        $audit = $this->audit();

        $records = [];
        $this->collectLogs($records);

        $audit->report($this->emptyRecord(), [
            new BootAuditFinding(
                key: 'swrr.pgcat.gate',
                warning: 'the first branch had something to say.',
                resolution: 'The gate opened.',
                level: 'warning',
            ),
            new BootAuditFinding(
                key: 'swrr.pgcat.gate',
                warning: 'the second branch had something to say.',
                resolution: 'The other thing stopped applying.',
                level: 'error',
            ),
        ], checked: ['swrr.pgcat.gate']);

        $this->assertCount(3, $records, 'both findings are logged, and the collision is named after them');
        $this->assertSame('warning', $records[0]['level']);
        $this->assertStringContainsString('the first branch had something to say.', $records[0]['message']);
        $this->assertSame('error', $records[1]['level']);
        $this->assertStringContainsString('the second branch had something to say.', $records[1]['message']);

        // The collision is logged at `error` — a problem nobody reported is louder than
        // either sentence — and it says which of the two the record kept.
        $this->assertSame('error', $records[2]['level']);
        $this->assertStringContainsString('share the key "swrr.pgcat.gate"', $records[2]['message']);
        $this->assertStringContainsString('louder of the two', $records[2]['message']);
        $this->assertStringContainsString('defect in the package', $records[2]['message']);
        $this->assertSame('swrr.pgcat.gate', $records[2]['context']['finding']);
        $this->assertSame(['warning', 'error'], $records[2]['context']['levels']);
        $this->assertSame(2, $records[2]['context']['findings_in_boot']);

        $this->assertSeverityStamped($records);

        // One entry, the louder sentence, and the boot is still up: a diagnostic never
        // takes the process down, a package defect included.
        $standing = $audit->standing();

        $this->assertCount(1, $standing, 'the collision is logged, not remembered: it is not a state an operator repairs');
        $this->assertSame('swrr.pgcat.gate', $standing[0]['key']);
        $this->assertSame('error', $standing[0]['level']);
        $this->assertSame('the second branch had something to say.', $standing[0]['warning']);
        $this->assertSame('The other thing stopped applying.', $standing[0]['resolution']);
    }

    /**
     * The other half of the same hazard, one level up: the record is one file, and the boots
     * that write it are several processes.
     *
     * A boot decides what to remember from the copy it read and then replaces the whole file.
     * Two boots in flight together both write from the same starting point, so the later one
     * used to discard whatever the earlier recorded — an entry nothing had taken out of the
     * record, dropped by a write that never knew it was there. The atomic rename covers the
     * reader; the writer was the same hazard one level up.
     *
     * So the file is read again as it is about to be replaced and the two copies are merged: an
     * entry the writing boot never saw is on disk, so it is in the record the write leaves
     * behind. That is the whole repair, and it is why nothing is logged here — a write that kept
     * an entry has not lost it, and an installation that named a race on every boot would be
     * crying wolf about its own writes.
     */
    public function test_an_entry_another_boot_recorded_mid_boot_survives_this_boots_write(): void
    {
        $audit = $this->audit();

        // What this boot was decided from: an installation with nothing on record.
        $stale = $audit->read();

        // Another boot records a finding while this one is still running.
        $this->putRecord(['swrr.reader_days.refused' => $this->finding('2026-09-20T08:15:00+00:00', 'error')]);

        $records = [];
        $this->collectLogs($records);

        $audit->report($stale, [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        )], checked: ['swrr.pgcat.gate']);

        $this->assertCount(1, $records, 'the finding this boot logged, and nothing else: the entry below is not lost');
        $this->assertStringContainsString('pgcat will never act.', $records[0]['message']);

        // Both settings are remembered, and the entry this boot never saw keeps the age the next
        // boot dates it from — a merged write is not a rewrite of somebody else's news.
        $findings = $audit->read()['findings'];
        $keys = array_keys($findings);
        sort($keys);

        $this->assertSame(['swrr.pgcat.gate', 'swrr.reader_days.refused'], $keys);
        $this->assertSame('2026-09-20T08:15:00+00:00', $findings['swrr.reader_days.refused']['first_reported_at']);
        $this->assertSame('warning', $findings['swrr.pgcat.gate']['level']);
    }

    /**
     * The other side of the merge, and the one place the write still has something to say: an
     * entry this boot evaluated and found clean is not cleared while another boot has just
     * substantiated it.
     *
     * A boot's clean verdict is about the copy it read, and configuration is read once per
     * process — so two boots in flight can genuinely be running different configurations, and one
     * of them finding a key fine is not evidence about the other. The entry stands, and the write
     * says so, because the resolution line it would otherwise log is a claim this record
     * contradicts: a monitor closing its page on `resolved: true` would be closing one for a
     * finding that is still standing.
     */
    public function test_an_entry_another_boot_substantiated_is_kept_against_this_boots_clean_verdict(): void
    {
        $audit = $this->audit();

        // A first boot records the finding; this boot reads that record, and then evaluates the
        // key clean, which is what a repaired configuration looks like from here.
        $audit->report($this->emptyRecord(), [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        )], checked: ['swrr.pgcat.gate']);

        $readByThisBoot = $audit->read();

        // Another boot, still running the configuration that fails, records it again.
        $this->putRecord(['swrr.pgcat.gate' => $this->finding($this->ago('-1 minute'), 'error')]);

        $records = [];
        $this->collectLogs($records);

        $audit->report($readByThisBoot, [], checked: ['swrr.pgcat.gate']);

        // One line: the kept entry is named, and no resolution is logged for a key the record
        // still holds.
        $this->assertCount(1, $records, 'the kept entry, and no `resolved` line the record contradicts');
        $this->assertSame('error', $records[0]['level']);
        $this->assertStringContainsString('changed while this boot was running', $records[0]['message']);
        $this->assertStringContainsString('keeps 1 entry', $records[0]['message']);
        $this->assertSame(['swrr.pgcat.gate'], $records[0]['context']['kept']);
        $this->assertSame($this->auditFile, $records[0]['context']['record']);
        $this->assertSame(['swrr.pgcat.gate'], $records[0]['context']['keys_on_disk']);
        $this->assertSame(['swrr.pgcat.gate'], $records[0]['context']['keys_this_boot_read']);
        $this->assertSame([], $records[0]['context']['keys_this_boot_writes']);

        $this->assertSeverityStamped($records);

        // The other boot's sentence and the age it is dated from are what the record keeps, not
        // this boot's clean verdict.
        $kept = $audit->read()['findings']['swrr.pgcat.gate'];

        $this->assertSame('error', $kept['level']);
        $this->assertSame($this->ago('-1 minute'), $kept['first_reported_at']);
    }

    /**
     * The lock is what makes the re-read a merge: it has to happen *after* this boot has the
     * record to itself, or two boots that each re-read and then each write simply overwrite one
     * another a moment later.
     *
     * The acquirer here is the other boot — it finishes its write and only then hands the lock
     * over, which is the order those two happen in. An implementation that read the file before
     * taking the lock reads the copy from before that write, and loses exactly the entry this
     * looks for.
     */
    public function test_the_record_is_re_read_after_the_lock_is_taken_rather_than_before(): void
    {
        $file = $this->tempDir() . '/audit.json';
        $this->auditFile = $file;

        $audit = new BootAudit($file, 0, lockAcquirer: function (string $path) use ($file): array {
            // The other boot's write, which happened just before it let the lock go.
            file_put_contents($file, (string) json_encode([
                'findings' => ['swrr.reader_days.refused' => $this->finding('2026-09-20T08:15:00+00:00', 'error')],
                'store_probed_at' => null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return [true, fopen($path, 'c')];
        });

        $records = [];
        $this->collectLogs($records);

        // Read first: what this boot was decided from is the file as it was before the write above.
        $audit->report($audit->read(), [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        )], checked: ['swrr.pgcat.gate']);

        $keys = array_keys($audit->read()['findings']);
        sort($keys);

        $this->assertSame(
            ['swrr.pgcat.gate', 'swrr.reader_days.refused'],
            $keys,
            'the entry the other boot wrote before releasing the lock is in the record this boot leaves',
        );

        $this->assertCount(1, $records);
    }

    /**
     * The mechanism cannot be left behind, and that is the property being chosen here rather than
     * the mutual exclusion on its own: `flock` lives on an open descriptor, so the kernel drops it
     * when the process ends however it ends, while a lock whose *existence* is the lock waits for
     * somebody to come and delete it.
     *
     * What a worker killed mid-write leaves on disk is an empty file beside the record. It is not
     * a lock, and this is the test that says so: the file is there and the write proceeds without
     * a word, where a sentinel would have to log that it could not serialise.
     */
    public function test_a_lock_file_left_behind_by_a_killed_worker_stops_nothing(): void
    {
        $audit = $this->audit();

        // All a killed worker leaves: the file it locked, with nothing holding it any more.
        file_put_contents($this->auditFile . '.lock', '');

        $records = [];
        $this->collectLogs($records);

        $audit->report($this->emptyRecord(), [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        )], checked: ['swrr.pgcat.gate']);

        $this->assertCount(1, $records, 'the finding, and no line about a lock the write could not take');
        $this->assertArrayHasKey('swrr.pgcat.gate', $audit->read()['findings']);
    }

    /**
     * Two things the defaults have to get right, and both are load-bearing.
     *
     * The lock is not taken on the record itself: the write renames a temp over the record, so a
     * descriptor on it would be holding the file nothing will ever open again, and two boots
     * either side of a rename would each hold "the record" while excluding nothing. And the lock
     * is free again afterwards, which is what lets the next boot write at all — an advisory lock
     * released only when its descriptor is closed is one a long-lived worker would hold for ever.
     */
    public function test_the_record_is_written_under_a_real_lock_and_left_free_for_the_next_boot(): void
    {
        $audit = $this->audit();

        $audit->report($this->emptyRecord(), [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        )], checked: ['swrr.pgcat.gate']);

        $this->assertFileExists($this->auditFile . '.lock', 'the lock is a companion file, because the record is replaced by a rename');
        $this->assertSame(['swrr.pgcat.gate'], array_keys($audit->read()['findings']));

        $handle = fopen($this->auditFile . '.lock', 'c');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'the write let the record go, so the next boot can take it');

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * A lock another boot holds for longer than this one is willing to wait is the one write left
     * that can still lose an entry, so it is named rather than hidden — and it is named *while*
     * the write goes ahead, because the settings this boot checked are still worth recording and a
     * diagnostic does not stop a boot.
     */
    public function test_a_lock_this_boot_cannot_take_is_reported_and_the_write_still_lands(): void
    {
        $audit = $this->audit();

        // Another boot, holding the record's lock while this one tries.
        $holder = fopen($this->auditFile . '.lock', 'c');
        $this->assertIsResource($holder);
        $this->assertTrue(flock($holder, LOCK_EX | LOCK_NB));

        $records = [];
        $this->collectLogs($records);

        $audit->report($this->emptyRecord(), [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        )], checked: ['swrr.pgcat.gate']);

        $this->assertCount(2, $records, 'the finding, and the line that says the write could not serialise');
        $this->assertSame('error', $records[1]['level']);
        $this->assertStringContainsString('lock could not be taken', $records[1]['message']);
        $this->assertSame($this->auditFile . '.lock', $records[1]['context']['lock']);

        // Derived rather than restated: the number of attempts and the time they took are the
        // class's own constants, and a loop that gave up after one attempt would pass a bare
        // "at least once".
        $attempts = (new ReflectionClass(BootAudit::class))->getConstant('LOCK_ATTEMPTS');
        $retry = (new ReflectionClass(BootAudit::class))->getConstant('LOCK_RETRY_MICROSECONDS');

        $this->assertSame($attempts, $records[1]['context']['attempts']);
        $this->assertGreaterThanOrEqual(
            intdiv(($attempts - 1) * $retry, 1000),
            $records[1]['context']['waited_ms'],
            'the attempts were waited out rather than fired off',
        );

        $this->assertSeverityStamped($records);

        // Merged without serialising is not refused: a race does not cost the boot the settings it
        // checked.
        $this->assertArrayHasKey('swrr.pgcat.gate', $audit->read()['findings']);

        flock($holder, LOCK_UN);
        fclose($holder);
    }

    /**
     * The other field the record holds is merged too, and for the same reason: a probe another
     * boot recorded while this one was running is a probe nobody needs to repeat, so writing the
     * whole record from this boot's own copy would buy the installation one extra connect timeout
     * — the cost the interval exists to bound.
     */
    public function test_a_probe_another_boot_recorded_is_not_forgotten_by_this_boots_write(): void
    {
        $audit = $this->audit();

        $this->putRecord([], 1_000);
        $stale = $audit->read();

        // Another boot probes and records the stamp while this one is still running.
        $this->putRecord([], 2_000);

        $audit->report($stale, [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        )], checked: ['swrr.pgcat.gate']);

        $this->assertSame(2_000, $audit->read()['store_probed_at'], 'the later stamp is the one the record keeps');
    }

    /**
     * A log-based monitor pages on `severity`, which is the whole point of the levels: a
     * refused value is input the installation is running without, worth waking somebody for,
     * while a setting that cannot act is a feature quietly not applying. `/health/db`
     * publishes that choice as one field, and a reader that would rather alert off the log
     * than poll a host needs the same field in the log — so the level is written into the
     * payload beside the finding key, under a name the package owns, rather than left to the
     * channel envelope where every handler spells it differently.
     */
    public function test_a_refused_value_is_selectable_from_the_log_by_its_severity(): void
    {
        $audit = $this->audit();

        $records = [];
        $this->collectLogs($records);

        $audit->report($this->emptyRecord(), [new BootAuditFinding(
            key: 'swrr.pgcat.enabled.refused',
            warning: 'The value "flase" for swrr.pgcat.enabled cannot be read as on or off.',
            resolution: 'swrr.pgcat.enabled reads as on or off again.',
            level: 'error',
        )], checked: ['swrr.pgcat.enabled.refused']);

        $audit->report($audit->read(), [], checked: ['swrr.pgcat.enabled.refused']);

        $this->assertCount(2, $records);

        // The standing refusal: `severity` and `finding` in the same object, so the rule a
        // pager is written from is `severity == "error"` and nothing else is needed to read
        // it — not the transport, not the line's position, not a key list.
        $this->assertSame('error', $records[0]['level']);
        $this->assertSame('error', $records[0]['context']['severity']);
        $this->assertSame('swrr.pgcat.enabled.refused', $records[0]['context']['finding']);

        // The clearing is written at `warning` whatever the finding stood at — arriving at a
        // working configuration is good news either way — so a monitor that tracks the loudest
        // severity standing stops paging on the very line that closes the finding, instead of
        // being handed one more `error` to page about. That the finding stood at `error` is in
        // the record and in the line it wrote while it stood; this line's claim is that it is
        // gone, and `resolved` says so.
        $this->assertSame('warning', $records[1]['level']);
        $this->assertSame('warning', $records[1]['context']['severity']);
        $this->assertTrue($records[1]['context']['resolved']);

        $this->assertSeverityStamped($records);
    }

    /**
     * `severity` is a claim about the line, not a field a finding may fill in. A context that
     * happened to carry the key would otherwise be able to make a line disagree with the level
     * it was logged at — and the monitor trusting the payload would page on the finding's
     * opinion of itself rather than on what the package decided to say.
     */
    public function test_the_line_names_the_level_it_was_written_at_even_when_a_finding_context_carries_the_key(): void
    {
        $audit = $this->audit();

        $records = [];
        $this->collectLogs($records);

        $audit->report($this->emptyRecord(), [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
            context: ['severity' => 'none', 'connection' => 'mysql_app'],
        )], checked: ['swrr.pgcat.gate']);

        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]['level']);
        $this->assertSame('warning', $records[0]['context']['severity']);
        $this->assertSame('mysql_app', $records[0]['context']['connection'], 'the rest of the context is the finding\'s and is logged untouched');
    }

    /**
     * A record nobody else touched writes in silence, which is the ordinary case and has to stay
     * quiet: an installation that logged a race on every boot would be crying wolf about its own
     * writes, and a monitor that paged on it would have a rule that fires on steady state.
     */
    public function test_a_record_nobody_else_wrote_writes_without_a_line_about_the_race(): void
    {
        $audit = $this->audit();

        $records = [];
        $this->collectLogs($records);

        $audit->report($audit->read(), [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        )], checked: ['swrr.pgcat.gate']);

        $this->assertCount(1, $records, 'the finding, and nothing about the write itself');
        $this->assertStringContainsString('pgcat will never act.', $records[0]['message']);
        $this->assertArrayNotHasKey('kept', $records[0]['context']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sharedKeyLevelsProvider(): array
    {
        return [
            'the error arrives first' => ['error', 'warning'],
            'the error arrives second' => ['warning', 'error'],
        ];
    }

    /**
     * Which of two findings under one key the record keeps.
     *
     * The level is what a monitor pages on — `standingSummary()` counts the louder one —
     * so the record cannot keep the quieter half of a pair and let a refused value read as
     * a warning. Order must not decide that, which is why the rule is about level first and
     * the arrival order only ties it.
     */
    #[DataProvider('sharedKeyLevelsProvider')]
    public function test_the_record_keeps_the_louder_of_two_findings_that_share_a_key(string $first, string $second): void
    {
        $audit = $this->audit();

        $audit->report($this->emptyRecord(), [
            new BootAuditFinding(
                key: 'swrr.pgcat.gate',
                warning: 'the '.$first.' finding.',
                resolution: 'The '.$first.' finding stopped applying.',
                level: $first,
            ),
            new BootAuditFinding(
                key: 'swrr.pgcat.gate',
                warning: 'the '.$second.' finding.',
                resolution: 'The '.$second.' finding stopped applying.',
                level: $second,
            ),
        ], checked: ['swrr.pgcat.gate']);

        $standing = $audit->standing();

        $this->assertCount(1, $standing);
        $this->assertSame('error', $standing[0]['level']);
        $this->assertSame('the error finding.', $standing[0]['warning']);
        $this->assertSame('The error finding stopped applying.', $standing[0]['resolution']);
    }

    public function test_two_findings_of_the_same_level_keep_the_first_of_the_pair(): void
    {
        // Equally loud: the rule has to answer something, and the first is the one that has
        // been true for longer in this boot's own list. The line says which rule it used.
        $audit = $this->audit();

        $records = [];
        $this->collectLogs($records);

        $audit->report($this->emptyRecord(), [
            new BootAuditFinding(
                key: 'swrr.reader_fallback.always_writer',
                warning: 'the first window cannot be entered.',
                resolution: 'The windows can be entered.',
            ),
            new BootAuditFinding(
                key: 'swrr.reader_fallback.always_writer',
                warning: 'the second window cannot be entered.',
                resolution: 'The other window can be entered.',
            ),
        ], checked: ['swrr.reader_fallback.always_writer']);

        $this->assertCount(3, $records);
        $this->assertSame('error', $records[2]['level'], 'a problem nobody reported is louder than either sentence');
        $this->assertStringContainsString('first of the two', $records[2]['message']);
        $this->assertSame('the first window cannot be entered.', $audit->standing()[0]['warning']);
        $this->assertSame('The windows can be entered.', $audit->standing()[0]['resolution']);
    }

    public function test_a_record_written_before_levels_were_remembered_reads_as_a_warning(): void
    {
        // The shape a record had before the level and the sentence were kept: a key, the
        // sentence that closes it, and a context. Nothing may be invented to fill the
        // gap — an old refusal entry reads as a warning with no sentence, never as an
        // error it might not have been.
        $file = $this->record([
            'swrr.primary_store.unknown' => [
                'resolution' => 'swrr.primary_store names a store the package knows.',
                'context' => ['configured' => 'memcached'],
                'first_reported_at' => $this->ago('-3 days'),
            ],
        ]);

        $standing = (new BootAudit($file))->standing();

        $this->assertCount(1, $standing);
        $this->assertSame('warning', $standing[0]['level']);
        $this->assertSame('', $standing[0]['warning']);
        $this->assertSame(['configured' => 'memcached'], $standing[0]['context']);

        // And the summary a monitor reads keeps that promise: an entry that cannot be
        // substantiated is never summarised as the louder of the two levels.
        $this->assertSame('warning', BootAudit::standingSummary($standing)['severity']);
    }

    public function test_a_summary_names_the_loudest_level_standing(): void
    {
        $file = $this->record([
            'refused' => $this->finding($this->ago('-1 minute'), 'error'),
            'cannot-act' => $this->finding($this->ago('-2 minutes')),
        ]);

        $this->assertSame(
            ['severity' => 'error', 'counts' => ['error' => 1, 'warning' => 1]],
            BootAudit::standingSummary((new BootAudit($file))->standing()),
        );
    }

    public function test_a_setting_that_cannot_act_is_not_summarised_as_something_to_page_for(): void
    {
        // Both of these are settings the package understands and that describe nothing
        // which can happen — worth a ticket, not a page. A summary that called them
        // `error` would wake somebody up over a fallback nobody is using.
        $file = $this->record([
            'first' => $this->finding($this->ago('-2 days')),
            'second' => $this->finding($this->ago('-1 day')),
        ]);

        $this->assertSame(
            ['severity' => 'warning', 'counts' => ['error' => 0, 'warning' => 2]],
            BootAudit::standingSummary((new BootAudit($file))->standing()),
        );
    }

    public function test_a_summary_of_nothing_standing_is_none(): void
    {
        // `none` belongs to the summary rather than to any finding: it says nothing is
        // standing, not that a particular finding is fine.
        $this->assertSame(
            ['severity' => 'none', 'counts' => ['error' => 0, 'warning' => 0]],
            BootAudit::standingSummary($this->audit()->standing()),
        );
    }

    public function test_a_level_outside_the_vocabulary_is_counted_as_the_quieter_one(): void
    {
        // A hand-edited record can hold anything at all. It reads as a warning when the
        // record is read — see the test above — and the summary is built from the same
        // reading, so the two cannot disagree about how loudly an entry is speaking.
        $file = $this->record([
            'hand-edited' => $this->finding($this->ago('-1 hour'), 'critical'),
        ]);

        $this->assertSame(
            ['severity' => 'warning', 'counts' => ['error' => 0, 'warning' => 1]],
            BootAudit::standingSummary((new BootAudit($file))->standing()),
        );
    }

    public function test_standing_findings_are_listed_oldest_first(): void
    {
        $file = $this->record([
            'recent' => $this->finding($this->ago('-3 hours')),
            'stale' => $this->finding($this->ago('-9 days')),
            'middling' => $this->finding($this->ago('-2 days')),
        ]);

        $keys = array_column((new BootAudit($file))->standing(), 'key');

        $this->assertSame(['stale', 'middling', 'recent'], $keys);
    }

    public function test_a_finding_carries_its_age_in_words(): void
    {
        $file = $this->record([
            'three-days' => $this->finding($this->ago('-3 days')),
            'one-hour' => $this->finding($this->ago('-1 hour')),
            'thirty-seconds' => $this->finding($this->ago('-30 seconds')),
        ]);

        $ages = [];

        foreach ((new BootAudit($file))->standing() as $finding) {
            $ages[$finding['key']] = $finding['age'];
        }

        $this->assertSame('3 days', $ages['three-days']);
        $this->assertSame('1 hour', $ages['one-hour']);
        $this->assertSame('less than a minute', $ages['thirty-seconds']);
    }

    public function test_a_timestamp_that_cannot_be_read_is_not_given_an_age(): void
    {
        $file = $this->record([
            'readable' => $this->finding($this->ago('-1 hour')),
            // The kind of thing a hand-edited or truncated record contains.
            'unreadable' => $this->finding('sometime last Tuesday'),
        ]);

        $standing = (new BootAudit($file))->standing();
        $ages = [];

        foreach ($standing as $finding) {
            $ages[$finding['key']] = [$finding['age'], $finding['age_seconds']];
        }

        $this->assertSame('unknown age', $ages['unreadable'][0]);
        $this->assertNull($ages['unreadable'][1]);

        // Still a finding, but not evidence of having been missed for longer than one
        // whose date can actually be read.
        $this->assertSame(['readable', 'unreadable'], array_column($standing, 'key'));
    }

    public function test_a_future_timestamp_reads_as_new_rather_than_negative(): void
    {
        // A clock that moved, or a record written by a machine whose time was ahead.
        $file = $this->record(['ahead' => $this->finding($this->ago('+2 hours'))]);

        $standing = (new BootAudit($file))->standing();

        $this->assertSame(0, $standing[0]['age_seconds']);
        $this->assertSame('less than a minute', $standing[0]['age']);
    }

    /**
     * The state both surfaces were built to print and could not reach.
     *
     * `/health/db` publishes `available` and `error`, `db:replica-status` renders the same pair
     * as `not registered` / `unreadable` / `nothing standing`, and the middle one needed a
     * `standing()` that throws. It could not throw: an unreadable file was rounded to an empty
     * record on its way through the tolerant reader, so a half-written record — a full disk or
     * a hand edit, with a real finding inside it — came out as `available: true, count: 0`.
     * That is "nothing is standing", asserted about a file the reader had just failed to open,
     * and it is the one answer an unreadable record must not produce: the honest alternative to
     * a reading is silence, not an all-clear.
     */
    public function test_a_record_that_cannot_be_read_is_not_rounded_to_nothing_standing(): void
    {
        $audit = $this->audit();

        // Half a finding: the key and the sentence are on disk, the JSON around them is not.
        file_put_contents($this->auditFile, '{"findings": {"swrr.pgcat.gate": {"warning": "pgcat will never act.');

        $report = BootAudit::reported($audit);

        $this->assertFalse($report['available'], 'an unreadable record is not a record with nothing in it');
        $this->assertSame(0, $report['count']);
        $this->assertSame([], $report['findings'], 'nothing is claimed about findings that could not be read');
        $this->assertNull($report['oldest']);
        $this->assertSame('none', $report['severity'], 'nothing is *known* to stand, which is not the same as nothing standing');
        $this->assertSame(['error' => 0, 'warning' => 0], $report['counts']);

        $error = (string) $report['error'];

        $this->assertNotSame('', $error, 'the reason is the value here: a reader has to know which way to look');
        $this->assertStringContainsString((string) $this->auditFile, $error, 'named, so an operator knows which file to open');
        $this->assertStringContainsString('is not JSON', $error);
    }

    /**
     * Every shape an unreadable record comes in, each named as what it is.
     *
     * "Unreadable" on its own sends an operator to a file with no idea what to look for, and
     * these need different answers: text that is not JSON is a hand edit or a write that never
     * finished, a JSON list is the wrong thing in the right place, an empty file is a write that
     * never landed, `findings` that is not a map is a record edited into a shape nothing can
     * read, and a directory where the record belongs is a `file:` that can never work however
     * many boots run.
     *
     * The one shape missing is a read that failed outright — the file removed between the check
     * and the read, which `persist()` does when a boot finds nothing left to remember. There is
     * no seam to hold a writer inside, so it stays a guard rather than a case.
     *
     * @param string|null $body the file's contents, or null for a directory
     */
    #[DataProvider('unreadableRecordShapes')]
    public function test_each_shape_of_unreadable_record_is_named_for_what_it_is(?string $body, string $reason): void
    {
        $audit = $this->audit();

        if ($body === null) {
            mkdir((string) $this->auditFile, 0o777, true);
        } else {
            file_put_contents((string) $this->auditFile, $body);
        }

        $report = BootAudit::reported($audit);

        $this->assertFalse($report['available'], "a record that is {$reason} is not a record");
        $this->assertStringContainsString((string) $this->auditFile, (string) $report['error']);
        $this->assertStringContainsString($reason, (string) $report['error']);

        // The strict reader throws rather than answering "no findings"; `reported()` is the
        // caller that turns the throw into the block above, and the message travels unchanged.
        try {
            $audit->standing();

            $this->fail('a surface must not be handed an unreadable record as a record with no findings');
        } catch (UnreadableRecord $e) {
            $this->assertSame($report['error'], $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function unreadableRecordShapes(): array
    {
        return [
            'half-written' => ['{"findings": {"swrr.pgcat.gate": {"warning": "pgcat will never act.', 'is not JSON'],
            'never JSON' => ['swrr.pgcat.gate: the gate never opened', 'is not JSON'],
            'a list where an object belongs' => ['["swrr.pgcat.gate"]', 'is not the JSON object a record is'],
            'a write that never landed' => ['', 'is empty'],
            'findings that are not a map' => ['{"findings": "swrr.pgcat.gate"}', 'does not hold a map of findings'],
            'a directory where the record belongs' => [null, 'is not a file'],
        ];
    }

    /**
     * The boundary from the other side: a record that is *gone* is not a failure.
     *
     * `persist()` removes the file when nothing is left to remember, so an installation with
     * nothing standing has no record at all — which is why "there is no file" stays `available`
     * with an empty list instead of becoming the unreadable case. A surface that treated the two
     * alike would report a permanent failure on every installation that ever fixed its
     * configuration, and the reason is the only thing separating them.
     */
    public function test_a_record_that_is_not_there_is_nothing_standing_rather_than_a_failure(): void
    {
        $audit = $this->audit();

        $this->assertFileDoesNotExist((string) $this->auditFile);
        $this->assertSame([], $audit->standing());

        $report = BootAudit::reported($audit);

        $this->assertTrue($report['available']);
        $this->assertNull($report['error']);
        $this->assertSame(0, $report['count']);
        $this->assertSame([], $report['findings']);
        $this->assertSame('none', $report['severity']);
    }

    /**
     * The split itself: the boot keeps its tolerant reader, so an unreadable record cannot stop
     * a boot — and the write that follows repairs the file the surfaces were complaining about.
     *
     * A boot that cannot read the record cannot close out or carry over what is in it, and
     * throwing instead would let a corrupt file stop the diagnostic whose job is to report
     * corrupt states. What it must not do is *print* the empty reading, which is the half
     * `standing()` owns — hence the same file being `nothing` to one reader and a failure to
     * the other, in the same process.
     */
    public function test_the_boot_reads_an_unreadable_record_as_nothing_while_a_surface_refuses_to(): void
    {
        $audit = $this->audit();

        file_put_contents((string) $this->auditFile, '{"findings": ');

        $this->assertSame($this->emptyRecord(), $audit->read(), 'the boot reads what it can and carries nothing over');
        $this->assertFalse(BootAudit::reported($audit)['available']);

        $records = [];
        $this->collectLogs($records);

        $audit->report($audit->read(), [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        )], checked: ['swrr.pgcat.gate']);

        $this->assertCount(1, $records, 'the warning this boot logged, and no resolution it cannot substantiate');
        $this->assertStringContainsString('pgcat will never act.', $records[0]['message']);

        // Written over: the record the surfaces could not read is one they can now, which is how
        // the next `db:replica-status` stops saying `unreadable`. Nothing repairs it by hand.
        $repaired = BootAudit::reported($audit);

        $this->assertTrue($repaired['available']);
        $this->assertSame(1, $repaired['count']);
        $this->assertSame('warning', $repaired['severity']);
    }

    public function test_a_finding_stops_being_remembered_once_a_boot_checks_it_clean(): void
    {
        $audit = $this->audit();
        $finding = new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        );

        $audit->report($this->emptyRecord(), [$finding], checked: ['swrr.pgcat.gate']);
        $this->assertCount(1, $audit->standing());

        // The next boot checks the key and does not report it: the finding is closed out
        // and, with nothing else to remember, the record itself is gone.
        $audit->report($audit->read(), [], checked: ['swrr.pgcat.gate']);

        $this->assertSame([], $audit->standing());
        $this->assertFileDoesNotExist((string) $this->auditFile);
    }

    public function test_a_finding_this_boot_did_not_check_is_carried_over(): void
    {
        // The store probe is the check that can be skipped, and a skipped check is not a
        // fix: the finding stays until a boot looks at it again.
        $audit = $this->audit();

        $audit->report($this->emptyRecord(), [new BootAuditFinding(
            key: 'swrr.primary_store.unreachable',
            warning: 'the store did not answer.',
            resolution: 'The store answers again.',
        )], checked: ['swrr.primary_store.unreachable']);

        $audit->report($audit->read(), [], checked: []);

        $this->assertCount(1, $audit->standing());
    }

    /**
     * Collect the records the code under test writes, deliberately unmocked, so the
     * assertions run against what Laravel really emits.
     *
     * @param list<array{level: string, message: string, context: array<string, mixed>}> $records
     */
    private function collectLogs(array &$records): void
    {
        Log::listen(function (MessageLogged $message) use (&$records): void {
            $records[] = [
                'level' => $message->level,
                'message' => $message->message,
                'context' => $message->context,
            ];
        });
    }

    /**
     * Every line the audit writes names the level it was written at, in its own payload.
     * Stated once as a property of all of them, because that is what makes the field usable:
     * a rule written against `severity` must hold for the finding lines, the line that closes
     * one, and the lines about the audit itself, or a reader has to know which line it is
     * looking at before it can trust the field at all.
     *
     * @param list<array{level: string, message: string, context: array<string, mixed>}> $records
     */
    private function assertSeverityStamped(array $records): void
    {
        foreach ($records as $index => $record) {
            $this->assertSame(
                $record['level'],
                $record['context']['severity'] ?? null,
                "line {$index} (\"{$record['message']}\") does not name the level it was written at",
            );
        }
    }

    /**
     * The record a first boot sees: nothing remembered yet. `report()` is given a
     * record, not fragments of one — the shape is the contract, and a caller that
     * invents a shorter array would be relying on the missing keys never being read.
     *
     * @return array{findings: array<string, array<string, mixed>>, store_probed_at: int|null}
     */
    private function emptyRecord(): array
    {
        return ['findings' => [], 'store_probed_at' => null];
    }

    /**
     * An audit pointed at a file of its own, with the probe off so nothing here needs a
     * store.
     */
    private function audit(): BootAudit
    {
        $this->auditFile = $this->tempDir() . '/audit.json';

        return new BootAudit($this->auditFile, 0);
    }

    /**
     * The record written to *this* test's audit file, which is what a test that injects the
     * lock's closures needs: `record()` writes to a directory of its own and `audit()` builds
     * the audit this file belongs to.
     *
     * @param array<string, mixed> $findings
     */
    private function putRecord(array $findings, ?int $probedAt = null): void
    {
        file_put_contents((string) $this->auditFile, (string) json_encode([
            'findings' => $findings,
            'store_probed_at' => $probedAt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $findings
     */
    private function record(array $findings): string
    {
        $file = $this->tempDir() . '/audit.json';

        file_put_contents($file, (string) json_encode([
            'findings' => $findings,
            'store_probed_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $firstReportedAt, string $level = 'warning'): array
    {
        return [
            'resolution' => 'It stops applying.',
            'warning' => 'It applies.',
            'level' => $level,
            'context' => [],
            'first_reported_at' => $firstReportedAt,
        ];
    }

    private function ago(string $modifier): string
    {
        return (new DateTimeImmutable($modifier, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/swrr-boot-audit-' . bin2hex(random_bytes(6));

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }
}
