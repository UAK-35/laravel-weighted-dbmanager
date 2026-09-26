<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Support\BootAuditFinding;
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
     * The other half of the same hazard, one level up: the record is one file, and the
     * boots that write it are several processes.
     *
     * A boot decides what to remember from the copy it read and then replaces the whole
     * file. Two boots in flight together therefore both write from the same starting point,
     * and the later one discards whatever the earlier recorded — an entry nothing has taken
     * out of the record, dropped by a write that never knew it was there. The atomic rename
     * covers the reader; this is the writer.
     *
     * So the file is read once more as it is about to be replaced, and the entries this
     * write would drop are named. It is reported and not refused — the newest settings are
     * still the ones worth recording, and a diagnostic does not stop a boot — and not locked,
     * for the reason the store probe decision already gave: a lock left behind by a killed
     * worker would stop the record being written at all.
     */
    public function test_an_entry_another_boot_recorded_mid_boot_is_named_rather_than_replaced_in_silence(): void
    {
        $audit = $this->audit();

        // What this boot was decided from: an installation with nothing on record.
        $stale = $audit->read();

        // Another boot records a finding while this one is still running.
        file_put_contents($this->auditFile, (string) json_encode([
            'findings' => ['swrr.reader_days.refused' => $this->finding('2026-09-20T08:15:00+00:00', 'error')],
            'store_probed_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $records = [];
        $this->collectLogs($records);

        $audit->report($stale, [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        )], checked: ['swrr.pgcat.gate']);

        $this->assertCount(2, $records, 'the finding this boot logged, and the entry this write drops');
        $this->assertStringContainsString('pgcat will never act.', $records[0]['message']);

        // The dropped entry is named — not the ones this boot is deliberately writing or
        // resolving, which are its own work rather than somebody else's loss.
        $this->assertSame('error', $records[1]['level']);
        $this->assertStringContainsString('changed while this boot was running', $records[1]['message']);
        $this->assertStringContainsString('discarding 1 entry', $records[1]['message']);
        $this->assertStringContainsString('swrr.reader_days.refused', $records[1]['message']);
        $this->assertSame(['swrr.reader_days.refused'], $records[1]['context']['discarded']);
        $this->assertSame($this->auditFile, $records[1]['context']['record']);
        $this->assertSame(['swrr.reader_days.refused'], $records[1]['context']['keys_on_disk']);
        $this->assertSame(['swrr.pgcat.gate'], $records[1]['context']['keys_this_boot_writes']);

        $this->assertSeverityStamped($records);

        // Reported is not repaired: the write still went ahead, because a race must not
        // leave the settings this boot checked unrecorded.
        $this->assertSame(['swrr.pgcat.gate'], array_keys($audit->read()['findings']));
    }

    /**
     * A record nobody else touched writes in silence, which is the ordinary case and has
     * to stay quiet: an installation that logged a lost update on every boot would be
     * crying wolf about its own writes.
     */
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

    public function test_a_record_nobody_else_wrote_writes_without_a_lost_update_line(): void
    {
        $audit = $this->audit();

        $records = [];
        $this->collectLogs($records);

        $audit->report($audit->read(), [new BootAuditFinding(
            key: 'swrr.pgcat.gate',
            warning: 'pgcat will never act.',
            resolution: 'The pgcat mismatch no longer applies.',
        )], checked: ['swrr.pgcat.gate']);

        foreach ($records as $record) {
            $this->assertStringNotContainsString('changed while this boot was running', $record['message']);
        }
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
