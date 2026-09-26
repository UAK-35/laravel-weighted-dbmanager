<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

/**
 * The boot-time self-audit: log every setting that reads as on but cannot act, and
 * remember them until they stop.
 *
 * WHAT IT COVERS
 * --------------
 * Settings that fail *silently* — nothing throws, nothing fails, the installation
 * simply behaves as if the setting were off:
 *
 *   swrr.reader_fallback.*        a windowed reader fallback that can never apply
 *   swrr.primary_store.*          a primary store that is not the one running, or
 *                                 one that cannot be reached
 *   swrr.default_weight_formula   a formula that silently runs as linear
 *   swrr.pgcat.*                  pgcat switched on where it cannot act
 *
 * A misconfiguration that throws on first use is deliberately out of scope: the
 * runtime logs it, `db:doctor` reports it, and a flip reports it as a failure. The
 * audit exists for the states nothing else would ever mention.
 *
 * WHY IT REMEMBERS THINGS ON DISK
 * -------------------------------
 * A warning that is never closed out leaves a log where the last word is the
 * problem. The boot that sees the fix is a different process — configuration is
 * read once per process — so this process cannot tell "the gate just opened" from
 * "it was always open". The findings that still stand are therefore written to
 * `swrr.audit.file`, and a boot that finds one gone logs its resolution instead.
 * Nothing is written on steady state, and the file is removed when there is nothing
 * left to remember.
 *
 * A finding's `resolution` therefore has to be true however it stops applying. The
 * boot that closes it out knows only that its key is no longer reported, and there is
 * usually more than one way out: a setting can be repaired, switched off, or dropped
 * entirely because the feature is no longer wanted. A sentence that assumes the
 * repair — "pgcat flipping is active" — would be a lie for the other two.
 *
 * THE STORE PROBE, UNDER PHP-FPM
 * ------------------------------
 * Store reachability is the one finding that needs the network, and under FPM the
 * application boots per request, so "once per process" would mean "once per
 * request". `swrr.audit.store_probe_seconds` bounds it instead: the PING runs only
 * when the last one is older than that interval, so the cost is one probe per
 * installation per interval however many requests arrive. `0` never probes, and a
 * probe that cannot be recorded is not attempted at all — an unwritable directory
 * must not turn into a slow request every time. Otherwise the store's silence is
 * covered by the runtime (degrading logs an error, recovering logs a warning) and
 * by `db:doctor`, which probes on demand.
 *
 * WHAT A RECORD REMEMBERS ABOUT A FINDING
 * ----------------------------------------
 * The sentence the log carried while it stood, how loudly it was logged, what it
 * stopped meaning when it cleared, and when it was first seen. The first two are
 * what make a standing finding legible away from the log it was written to: a reader
 * that only has the key knows *which* setting is wrong and not *how*. Records
 * written before those fields existed still read, as warnings with no sentence — the
 * failure mode is a line too few, never an invented claim.
 *
 * @phpstan-type Finding array{resolution: string, warning: string, level: string, context: array<string, mixed>, first_reported_at: string}
 * @phpstan-type Record array{findings: array<string, Finding>, store_probed_at: int|null}
 * @phpstan-type Standing array{key: string, level: string, warning: string, resolution: string, first_reported_at: string, age_seconds: int|null, age: string, context: array<string, mixed>}
 * @phpstan-type Summary array{severity: string, counts: array{error: int, warning: int}}
 * @phpstan-type Block array{available: bool, count: int, severity: string, counts: array{error: int, warning: int}, oldest: string|null, findings: list<Standing>, error: string|null}
 */
final class BootAudit
{
    /**
     * The levels a finding is logged at, most severe first, and the vocabulary
     * `BootAuditFinding::$level`, `Standing['level']` and `standingSummary()`'s
     * `severity` are all drawn from.
     *
     * The list is closed at two members on purpose. A finding is either a value the
     * package *refuses* to interpret — malformed input it will not guess at — or a
     * setting it understands and that describes nothing which can happen. Nothing
     * logs at a third level, so a reader that has the level has the whole claim, and
     * `none` belongs to the summary rather than to any finding: it means "nothing is
     * standing", not "this one is fine".
     *
     * `severity` is also the name every line this class writes carries the level under —
     * see `logContext()` — so a monitor reads the level out of the payload beside the
     * finding key instead of out of the transport that happened to carry the line.
     */
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_NONE = 'none';

    /**
     * @param string $file where findings that still stand are remembered
     * @param int $storeProbeSeconds seconds between store probes; 0 never probes
     */
    public function __construct(
        private readonly string $file,
        private readonly int $storeProbeSeconds = 60,
    ) {
    }

    /**
     * The findings a previous boot reported, and when the store was last probed.
     * Every unreadable state — no file, a truncated one, a hand-edited one — reads
     * as "nothing recorded": the failure mode is one line too few, never a claim
     * about a warning that was never logged.
     *
     * @return Record
     */
    public function read(): array
    {
        $findings = [];
        $probedAt = null;

        $raw = is_file($this->file) ? @file_get_contents($this->file) : false;

        if (is_string($raw) && $raw !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $findings = $this->readFindings($decoded['findings'] ?? null);

                $probed = $decoded['store_probed_at'] ?? null;
                $probedAt = is_int($probed) ? $probed : null;
            }
        }

        return ['findings' => $findings, 'store_probed_at' => $probedAt];
    }

    /**
     * The findings still standing as this installation remembers them, oldest first.
     *
     * This is the one place the readers of the record agree: `/health/db` embeds these
     * entries and `db:replica-status` prints them, so a setting that reads as on but
     * cannot act is visible where an operator already looks instead of only in the boot
     * log. The order is most of the value — a finding that has stood since last week is
     * the one that has been missed longest — and a timestamp that cannot be read sorts
     * last, because it is still a finding but not evidence of having stood longer than
     * the rest.
     *
     * Nothing is cached. The record is written by a *previous* boot, and a long-lived
     * worker resolving this once would keep answering from the file as it was when the
     * worker started.
     *
     * @return list<Standing>
     */
    public function standing(): array
    {
        $standing = [];

        foreach ($this->read()['findings'] as $key => $finding) {
            $seconds = self::ageSeconds($finding['first_reported_at']);

            $standing[] = [
                'key' => $key,
                'level' => $finding['level'],
                'warning' => $finding['warning'],
                'resolution' => $finding['resolution'],
                'first_reported_at' => $finding['first_reported_at'],
                'age_seconds' => $seconds,
                'age' => self::describeAge($seconds),
                'context' => $finding['context'],
            ];
        }

        // Oldest first: the largest age leads. An unreadable timestamp sorts last rather
        // than first, which is why it is scored below every real age (they are never
        // negative) instead of above them — "this one has stood since the beginning of
        // time" is the one claim a missing date cannot support.
        usort($standing, static function (array $a, array $b): int {
            $left = is_int($a['age_seconds'] ?? null) ? $a['age_seconds'] : PHP_INT_MIN;
            $right = is_int($b['age_seconds'] ?? null) ? $b['age_seconds'] : PHP_INT_MIN;

            return $right <=> $left ?: strcmp((string) $a['key'], (string) $b['key']);
        });

        return $standing;
    }

    /**
     * The record as a surface that has to say something about it sees it: what stands, how
     * much of it, and — when there is nothing to report — why not.
     *
     * `report()` writes what a boot found. This reads back what is on record, for the two
     * surfaces that publish it, and it is the one place either of them decides *whether*
     * there is a record to read: `/health/db` embeds this array and `db:replica-status`
     * prints from it, so a payload and a terminal cannot disagree about it. They did — the
     * command printed nothing at all for the one state the payload names `available: false`,
     * which left an operator unable to tell an installation with no audit from one whose
     * record happened to be empty.
     *
     * `available` and `error` are the pair that says which of those it is, and the pair is
     * the point: a record that could be read is `available` with `error` null — including an
     * empty one, which is a fact about the installation rather than a failure — and a surface
     * with nothing to read is `available: false` with `error` naming which of the two reasons
     * it was. `null` is the audit that was never registered, and a message is a `standing()`
     * that threw.
     *
     * The summary is built from an empty list rather than from an assumption in the
     * unavailable case: `severity` is `none` because nothing is *known* to stand, not because
     * nothing is wrong. `error` is present either way so a rule never has to guard for a
     * field that might be missing, and a consumer that has to know whether the audit is
     * reporting at all alerts on `available`, or on `error` being set — a different question
     * from how severe what it reports is.
     *
     * Nothing is probed, written or restarted: reading the record is the whole cost.
     *
     * @param self|null $audit the audit the caller resolved — null when the application has
     *        none registered, which is a fact about the installation and not about the reader
     * @return Block
     */
    public static function reported(?self $audit): array
    {
        if ($audit === null) {
            return self::nothingToReport(null);
        }

        try {
            $findings = $audit->standing();
        } catch (\Throwable $e) {
            return self::nothingToReport($e->getMessage());
        }

        $summary = self::standingSummary($findings);

        return [
            'available' => true,
            'count' => count($findings),
            'severity' => $summary['severity'],
            'counts' => $summary['counts'],
            // The list is oldest first, so the first entry is the oldest finding.
            'oldest' => $findings[0]['first_reported_at'] ?? null,
            'findings' => $findings,
            'error' => null,
        ];
    }

    /**
     * The block for a reader that has no record: the application registered no audit, or the
     * one it registered could not be read. Neither is a claim about the installation.
     *
     * @return Block
     */
    private static function nothingToReport(?string $error): array
    {
        $summary = self::standingSummary([]);

        return [
            'available' => false,
            'count' => 0,
            'severity' => $summary['severity'],
            'counts' => $summary['counts'],
            'oldest' => null,
            'findings' => [],
            'error' => $error,
        ];
    }

    /**
     * The loudest level standing, and how many findings stand at each level — the two
     * fields a monitor needs so that alerting on a finding does not mean walking a list
     * and comparing strings.
     *
     * `severity` is the worst of what is standing: `error` when at least one finding is
     * a value the package refused, `warning` when the loudest is a setting that cannot
     * act, and `none` when nothing stands. The distinction is the whole point of the
     * levels: a refused value is input the installation is running without, which is
     * worth waking somebody for, while a setting that cannot act is a feature quietly
     * not applying — a ticket, not a page. `counts` says how many of each, with both
     * keys always present so a rule can be written as `counts.error > 0` rather than as
     * a lookup that might be missing.
     *
     * A level that is neither of the two the audit logs at is counted as the quieter
     * one, matching what a record is read as: an entry that cannot be substantiated is
     * never upgraded into a louder claim than the record supports.
     *
     * @param list<Standing> $standing
     * @return Summary
     */
    public static function standingSummary(array $standing): array
    {
        $counts = [self::SEVERITY_ERROR => 0, self::SEVERITY_WARNING => 0];

        foreach ($standing as $finding) {
            $counts[$finding['level'] === self::SEVERITY_ERROR ? self::SEVERITY_ERROR : self::SEVERITY_WARNING]++;
        }

        return [
            'severity' => match (true) {
                $counts[self::SEVERITY_ERROR] > 0 => self::SEVERITY_ERROR,
                $counts[self::SEVERITY_WARNING] > 0 => self::SEVERITY_WARNING,
                default => self::SEVERITY_NONE,
            },
            'counts' => $counts,
        ];
    }

    /**
     * The probe as this configuration leaves it: the interval, the record that
     * throttles it, and whether that record can be written.
     *
     * `enabled` and `writable` together are the difference between a probe that runs
     * on a schedule and one that never runs at all — the second is silent, because a
     * probe that is skipped logs nothing. `db:doctor` reports this so an installation
     * that can never check its store is visible rather than merely quiet.
     *
     * @return array{seconds: int, enabled: bool, writable: bool, file: string}
     */
    public function storeProbeStatus(): array
    {
        return [
            'seconds' => $this->storeProbeSeconds,
            'enabled' => $this->storeProbeSeconds > 0,
            'writable' => $this->recordIsWritable(),
            'file' => $this->file,
        ];
    }

    /**
     * True when a store probe is due. False when probing is switched off, and false
     * — rather than "every time" — when the record cannot be written, because the
     * throttle lives in that file: without it a dead store would cost its connect
     * timeout on every single request.
     *
     * @param Record $record
     */
    public function storeProbeDue(array $record): bool
    {
        $status = $this->storeProbeStatus();

        if (!$status['enabled'] || !$status['writable']) {
            return false;
        }

        $lastProbe = $record['store_probed_at'];

        return $lastProbe === null || (time() - $lastProbe) >= $this->storeProbeSeconds;
    }

    /**
     * Log the findings this boot produced, close out the ones it checked and did not
     * find, and remember what still stands.
     *
     * A finding is logged on every boot while it stands: the alternative is a
     * warning from three weeks ago with nothing to say whether it is still true.
     * A resolution is logged once, because it is a transition.
     *
     * Every finding handed in is logged, including one that shares its key with another:
     * the record holds one entry per key, and a fold that ran before the log would drop the
     * replaced sentence before anyone read it. `fold()` is where that is enforced.
     *
     * @param Record $record what a previous boot reported
     * @param list<BootAuditFinding> $findings the settings that cannot act now
     * @param list<string> $checked the finding keys this boot actually evaluated —
     *        including the ones that came back clean. A recorded key outside this
     *        set was not looked at (a throttled store probe) and is carried over
     *        untouched rather than reported as resolved.
     * @param int|null $probedAt when the store was probed for this report, if it was
     */
    public function report(array $record, array $findings, array $checked, ?int $probedAt = null): void
    {
        $now = self::timestamp();

        // Logging and folding happen in one pass, so a key two findings agree on cannot
        // cost one of them its log line — see fold().
        $current = $this->fold($findings, $record, $now);

        $standing = [];
        $next = [];

        foreach ($record['findings'] as $key => $reported) {
            if (isset($current[$key])) {
                continue;   // still failing: its warning was just logged again
            }

            if (!in_array($key, $checked, true)) {
                $standing[$key] = $reported;   // not looked at this boot: left alone

                continue;
            }

            Log::warning('[WeightedDB] '.$reported['resolution'], self::logContext(self::SEVERITY_WARNING, [
                'finding' => $key,
                'first_reported_at' => $reported['first_reported_at'],
                'resolved' => true,
            ] + $reported['context']));
        }

        foreach ($current as $key => $finding) {
            $next[$key] = [
                'resolution' => $finding->resolution,
                // Remembered so a reader that has the record and not the log still knows
                // what the finding says, and how loudly it was said.
                'warning' => $finding->warning,
                'level' => $finding->level,
                'context' => $finding->context,
                'first_reported_at' => $record['findings'][$key]['first_reported_at'] ?? $now,
            ];
        }

        foreach ($standing as $key => $reported) {
            $next[$key] = $reported;
        }

        $updated = [
            'findings' => $next,
            'store_probed_at' => $probedAt ?? $record['store_probed_at'],
        ];

        $this->persist($record, $updated);
    }

    /**
     * The findings of this boot as one entry per finding key, each one logged as it is
     * taken.
     *
     * The record is keyed by finding key — that is what makes "still standing" and
     * "resolved" comparable across boots — so a second finding under a key already taken
     * cannot be stored beside the first. Folding first and logging the survivors, which is
     * what this used to do, lost the replaced finding entirely: a problem the installation
     * has, reported by nobody, while the payload still looked complete. It also quoted the
     * key's recorded age beside a sentence that had not stood that long, because
     * `first_reported_at` belongs to the key rather than to the sentence.
     *
     * So every finding is logged here, in the order it was handed in, and a shared key is
     * named for what it is: two branches of the package producing one key. That is a defect
     * in the package rather than a state of the installation, which is why it is logged and
     * not remembered — the record's entries are states an operator repairs, and this one has
     * no resolution sentence anybody could trigger. The record keeps the louder of the two,
     * because the level is what a monitor pages on; two findings of the same level keep the
     * first, so the rule is total and order decides.
     *
     * Nothing here can stop a boot: a diagnostic never takes the process down, and a package
     * defect is still a diagnostic.
     *
     * @param list<BootAuditFinding> $findings the settings that cannot act now
     * @param Record $record what a previous boot reported
     * @return array<string, BootAuditFinding> one finding per key, the one to remember
     */
    private function fold(array $findings, array $record, string $now): array
    {
        $current = [];

        foreach ($findings as $finding) {
            $key = $finding->key;

            // The level is the finding's own: a value the package refuses is an error,
            // a setting that cannot act is a warning. See BootAuditFinding.
            Log::log($finding->level, '[WeightedDB] '.$finding->warning, self::logContext($finding->level, [
                'finding' => $key,
                'first_reported_at' => $record['findings'][$key]['first_reported_at'] ?? $now,
            ] + $finding->context));

            $kept = $current[$key] ?? null;

            if ($kept === null) {
                $current[$key] = $finding;

                continue;
            }

            $equallyLoud = $kept->level === $finding->level;

            if (!$equallyLoud && $finding->level === self::SEVERITY_ERROR) {
                $current[$key] = $finding;
            }

            Log::error(sprintf(
                '[WeightedDB] Two findings this boot share the key "%s", so they are one entry in the record: it keeps the %s. Both sentences were logged above, and neither setting is wrong — one key is one finding, so this is two branches of the package producing the same key, which is a defect in the package.',
                $key,
                $equallyLoud ? 'first of the two' : 'louder of the two',
            ), self::logContext(self::SEVERITY_ERROR, [
                'finding' => $key,
                'levels' => [$kept->level, $finding->level],
                'findings_in_boot' => count($findings),
            ]));
        }

        return $current;
    }

    /**
     * @param mixed $value
     * @return array<string, Finding>
     */
    private function readFindings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $findings = [];

        foreach ($value as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                continue;
            }

            $resolution = $entry['resolution'] ?? null;

            // Without the sentence to log there is nothing this entry can say on the
            // boot that resolves it, so it is not a finding worth remembering.
            if (!is_string($resolution) || $resolution === '') {
                continue;
            }

            $rawContext = $entry['context'] ?? null;
            /** @var array<string, mixed> $context */
            $context = is_array($rawContext) ? $rawContext : [];

            $firstReportedAt = $entry['first_reported_at'] ?? null;

            $warning = $entry['warning'] ?? null;

            $findings[$key] = [
                'resolution' => $resolution,
                'warning' => is_string($warning) ? $warning : '',
                // A record written before the level was remembered reads as a warning:
                // it is the level every finding but the refused values is logged at, so
                // the entries that can be older are the ones that guess right.
                'level' => ($entry['level'] ?? null) === 'error' ? 'error' : 'warning',
                'context' => $context,
                'first_reported_at' => is_string($firstReportedAt) && $firstReportedAt !== ''
                    ? $firstReportedAt
                    : self::timestamp(),
            ];
        }

        return $findings;
    }

    /**
     * Replace the record when it changed, and remove it when there is nothing left
     * to remember. Written beside the target and renamed over it, so a concurrent
     * reader never sees half a record.
     *
     * A concurrent *writer* is the other half of that, and the record is one file shared by
     * every boot of an installation — per installation, not per worker, which is what makes
     * the probe cheap and this write contended. This boot decided what to remember from the
     * copy it read, so if another boot has written the file since, replacing it now discards
     * entries no reader has taken out of it yet. The file is therefore read once more as it
     * is about to be replaced, and a record that is no longer the one this boot was decided
     * from is reported with the keys this write drops.
     *
     * Reported, not refused, and not locked. Refusing would leave the settings this boot
     * checked unrecorded over a race, and a lock is the mechanism the store-probe decision
     * already rejected for this file: one left behind by a killed worker would stop the
     * record from ever being written again. A dropped entry, named, is louder than either.
     *
     * @param Record $previous
     * @param Record $updated
     */
    private function persist(array $previous, array $updated): void
    {
        if (self::canonical($previous) === self::canonical($updated)) {
            return;
        }

        $this->reportLostUpdate($previous, $updated);

        if ($updated['findings'] === [] && $updated['store_probed_at'] === null) {
            @unlink($this->file);

            return;
        }

        $encoded = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return;
        }

        $directory = dirname($this->file);

        if (!is_dir($directory)) {
            @mkdir($directory, 0o755, true);
        }

        $temporary = $this->file.'.tmp.'.getmypid();

        if (@file_put_contents($temporary, $encoded) === false) {
            return;
        }

        @rename($temporary, $this->file);
    }

    /**
     * Say so when the record about to be replaced is no longer the one this boot read.
     *
     * The keys the other boot added — and that this write does not carry — are named,
     * because those are the entries that go nowhere: recorded by a boot that has since
     * exited, never taken out of the file by anything, and now held by a copy that is being
     * overwritten. Every other difference is this boot's own work: the keys it resolved are
     * meant to leave, and the ones it is writing are meant to arrive.
     *
     * The sentences themselves are not lost — the boot that produced them logged them, which
     * is where a finding is written — so the loss is the record: the next boot cannot resolve
     * a key it never saw, and cannot date a finding from when it was first reported.
     *
     * @param Record $previous what this boot was decided from
     * @param Record $updated  what is about to be written
     */
    private function reportLostUpdate(array $previous, array $updated): void
    {
        $onDisk = $this->read();

        if (self::canonical($onDisk) === self::canonical($previous)) {
            return;
        }

        $appeared = array_diff_key($onDisk['findings'], $previous['findings']);
        $discarded = array_keys(array_diff_key($appeared, $updated['findings']));

        Log::error(
            $discarded === []
                ? '[WeightedDB] The audit record changed while this boot was running, so this write replaces the copy another process left. That copy holds no entry this write does not, so nothing goes unread.'
                : sprintf(
                    '[WeightedDB] The audit record changed while this boot was running, so this write replaces the copy another process left, discarding %d entr%s another boot recorded and nothing has read: %s. The sentences survive in the log that boot wrote them to — what is lost is the record, and with it the age the next boot would have dated them from.',
                    count($discarded),
                    count($discarded) === 1 ? 'y' : 'ies',
                    implode(', ', $discarded),
                ),
            self::logContext(self::SEVERITY_ERROR, [
                'record' => $this->file,
                'discarded' => $discarded,
                'keys_on_disk' => array_keys($onDisk['findings']),
                'keys_this_boot_read' => array_keys($previous['findings']),
                'keys_this_boot_writes' => array_keys($updated['findings']),
            ]),
        );
    }

    /**
     * The record can only be written where its directory accepts a file — the same
     * test the probe is gated on, because a probe whose timestamp has nowhere to go
     * would run on every request.
     */
    private function recordIsWritable(): bool
    {
        $directory = dirname($this->file);

        if (is_file($this->file)) {
            return is_writable($this->file);
        }

        return is_dir($directory) && is_writable($directory);
    }

    /**
     * How long a finding has stood, in seconds — null when the recorded timestamp cannot
     * be read at all, which a hand-edited record can produce and which a payload should
     * not turn into a confident "just now". A timestamp in the future reads as zero,
     * because a clock that moved is not a finding that has stood for negative time.
     */
    private static function ageSeconds(string $reportedAt): ?int
    {
        try {
            $when = new DateTimeImmutable($reportedAt);
        } catch (\Throwable) {
            return null;
        }

        return max(0, time() - $when->getTimestamp());
    }

    /**
     * A finding's age in words. A payload and a CLI report both have to answer "how long
     * has this been wrong", and neither is a place a reader should be subtracting
     * timestamps.
     */
    private static function describeAge(?int $seconds): string
    {
        if ($seconds === null) {
            return 'unknown age';
        }

        if ($seconds < 60) {
            return 'less than a minute';
        }

        if ($seconds < 3600) {
            return self::plural(intdiv($seconds, 60), 'minute');
        }

        if ($seconds < 86400) {
            return self::plural(intdiv($seconds, 3600), 'hour');
        }

        return self::plural(intdiv($seconds, 86400), 'day');
    }

    private static function plural(int $count, string $unit): string
    {
        return $count.' '.$unit.($count === 1 ? '' : 's');
    }

    /**
     * Arrays compared with their keys in a canonical order, so a record read from
     * JSON is not judged "changed" because of key order alone.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::canonical($entry);
            }
        }

        return $value;
    }

    /**
     * A line's context, with the level the line is being written at named inside it.
     *
     * The channel's record carries a level too, but how that level is spelled belongs to
     * whatever handler is configured — Laravel's default line log writes `production.ERROR`,
     * a JSON handler may write `level_name` or `monolog_level`, syslog writes a number — and a
     * reader that has to know which one it is looking at cannot be written once and used
     * everywhere. `severity` puts the level in the payload, beside the finding it is about,
     * under a name the package owns: a refused value is selected with `severity` and `finding`
     * out of the same object, on every line this class writes, whichever channel the operator
     * routed it through. That is the rule a log-based monitor pages on — the same rule
     * `/health/db` publishes as `severity`, so the two surfaces are read the same way.
     *
     * The line's own level wins a collision. `severity` is a claim about the line, not one of
     * the finding's fields, so a context that happened to carry the key cannot make a line
     * disagree with the level it was logged at.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function logContext(string $level, array $context): array
    {
        return ['severity' => $level] + $context;
    }

    private static function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
