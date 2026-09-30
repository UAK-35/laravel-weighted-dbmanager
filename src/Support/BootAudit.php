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
 *   database.read.*               replica metadata the resolver does not read as
 *                                 written, including the weight that quietly
 *                                 takes a replica out of the pool
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
 * One installation has many boots, so the record has more than one writer. The write
 * re-reads the file as it is about to be replaced and merges rather than replacing, and
 * the read-merge-write is taken under an exclusive advisory lock on a companion file —
 * one the kernel releases when a process ends however it ends, so a worker killed
 * mid-write leaves nothing behind that stops the next boot. `persist()` states the rule
 * key by key and why the mechanism is this lock rather than a sentinel, and a record that
 * cannot be written at all is not read again, merged or locked: an unwritable directory
 * costs a stat rather than a wait.
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
 * AND WHAT IT REMEMBERS ABOUT WHERE
 * ----------------------------------
 * A record is per installation, and one installation has many boots: a migration container,
 * a queue worker and the web process all boot the same application, and they do not
 * necessarily resolve the same connection. A finding written by one of them is still true
 * about the setting it names and can be false about the process reading it. Each recorded
 * entry therefore carries the scope it was reported in — the connection the package
 * followed, its driver, the rule that named it, and `app.env`. It changes nothing about the
 * finding's identity; it is what lets a surface say that the process now reading the record
 * is not the one the finding was written by.
 *
 * WHAT THE LIVE HALF IS
 * ---------------------
 * A surface that only reads the record can date a finding and cannot say whether it is
 * still true: the boot that would close it out runs once per process, and a long-lived
 * worker never runs it again. `reported()` therefore publishes the *current* reading beside
 * the record — the same producers, run again in the process answering the request, with the
 * network probe left out, because a failed PING is a connect timeout and a health payload
 * is not the place to spend one. The two halves sit beside each other rather than replacing
 * one another, because they answer different questions: the record says what the
 * installation has been claiming, and the live half says what this process can see now. A
 * key the live half cannot answer without a probe is reported as *not evaluated* rather than
 * as cleared, which is the distinction that makes the pair worth reading.
 *
 * @phpstan-type Finding array{resolution: string, warning: string, level: string, context: array<string, mixed>, first_reported_at: string, scope: array<string, mixed>}
 * @phpstan-type Record array{findings: array<string, Finding>, store_probed_at: int|null}
 * @phpstan-type Standing array{key: string, level: string, warning: string, resolution: string, first_reported_at: string, age_seconds: int|null, age: string, context: array<string, mixed>, scope: array<string, mixed>}
 * @phpstan-type Current array{evaluated: bool, standing: bool|null, scope_matches: bool|null}
 * @phpstan-type Reported array{key: string, level: string, warning: string, resolution: string, first_reported_at: string, age_seconds: int|null, age: string, context: array<string, mixed>, scope: array<string, mixed>, current: Current}
 * @phpstan-type LiveFinding array{key: string, level: string, warning: string, resolution: string, context: array<string, mixed>}
 * @phpstan-type Live array{available: bool, count: int, severity: string, counts: array{error: int, warning: int}, findings: list<LiveFinding>, error: string|null}
 * @phpstan-type LiveReading array{available: bool, error: string|null, scope: array<string, mixed>|null, checked_at: string, evaluated: list<string>, findings: list<LiveFinding>}
 * @phpstan-type Summary array{severity: string, counts: array{error: int, warning: int}}
 * @phpstan-type Block array{available: bool, count: int, severity: string, counts: array{error: int, warning: int}, oldest: string|null, findings: list<Reported>, error: string|null, checked_at: string|null, scope: array<string, mixed>|null, current: Live}
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
     * How many times a write asks for the record's lock, and how long it waits between two
     * attempts. A boot cannot block on a diagnostic: the holder is another boot whose
     * merge-and-write takes microseconds, so short attempts are generous, and a holder that
     * is wedged — a filesystem that stopped answering — must not become an application that
     * never boots. Exhausting them is not a refusal: the write goes ahead and says so.
     */
    private const LOCK_ATTEMPTS = 8;

    private const LOCK_RETRY_MICROSECONDS = 15_000;

    /** @var \Closure(string): array{0: bool, 1: resource|null} */
    private \Closure $lockAcquirer;

    /** @var \Closure(mixed): void */
    private \Closure $lockReleaser;

    /**
     * @param string $file where findings that still stand are remembered
     * @param int $storeProbeSeconds seconds between store probes; 0 never probes
     * @param \Closure(string): array{0: bool, 1: resource|null}|null $lockAcquirer how one
     *        attempt at the record's lock is made — injected so a test can hold it, refuse it,
     *        or watch it being released without a second process
     * @param \Closure(mixed): void|null $lockReleaser what lets the lock go again, called with
     *        whatever the acquirer returned
     * @param \Closure(): array{scope?: array<string, mixed>, evaluated?: list<string>, findings?: list<BootAuditFinding>}|null $currentFindings
     *        how the audited settings read *now*, evaluated by the process answering the
     *        request — the live half of the block. Injected by the provider, which is the
     *        only place that knows every producer; absent for a bare audit (a test, a
     *        hand-built one), and the block then says the live half was not evaluated rather
     *        than inventing one
     */
    public function __construct(
        private readonly string $file,
        private readonly int $storeProbeSeconds = 60,
        ?\Closure $lockAcquirer = null,
        ?\Closure $lockReleaser = null,
        private readonly ?\Closure $currentFindings = null,
    ) {
        $this->lockAcquirer = $lockAcquirer ?? static function (string $file): array {
            $handle = @fopen($file, 'c');

            if ($handle === false) {
                return [false, null];
            }

            return [@flock($handle, LOCK_EX | LOCK_NB), $handle];
        };

        $this->lockReleaser = $lockReleaser ?? static function (mixed $handle): void {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        };
    }

    /**
     * The findings a previous boot reported, and when the store was last probed.
     *
     * The *boot's* reader, and tolerant on purpose: a record this process cannot read is
     * one it cannot carry over or close out, so every unreadable state — no file, a
     * truncated one, a hand-edited one — reads as "nothing recorded", and the failure
     * mode is one line too few rather than a claim about a warning that was never logged.
     * Its callers are the ones where that is the right answer: `report()` writes from it,
     * and `db:doctor` reads one finding out of it to add a clause to a row it is already
     * printing.
     *
     * It is not the reader a *surface* uses. "Nothing recorded" printed to an operator is
     * a claim about the installation — that nothing is standing — and an unreadable file
     * cannot support it. `standing()` is where that question is asked, and it refuses to
     * round one to the other.
     *
     * @return Record
     */
    public function read(): array
    {
        try {
            return $this->decode();
        } catch (UnreadableRecord) {
            return ['findings' => [], 'store_probed_at' => null];
        }
    }

    /**
     * The record as the file holds it, or the reason it is not a record.
     *
     * One parse in one place, with two readers either side of it: `read()` catches what
     * this throws and `standing()` lets it through, so the boot and a surface can disagree
     * about what to *do* with an unreadable file while still agreeing about what one is.
     *
     * A missing file is not unreadable. It is what `persist()` leaves behind when nothing
     * is left to remember, so it means "this installation has nothing standing" — the one
     * state that is genuinely a fact rather than a failure.
     *
     * The shapes that are refused are the file-level ones, and each is named as what it is:
     * a path with something other than a file on it, bytes that could not be read, nothing at
     * all, text that is not JSON, a JSON list (an object is what a record is — `{}` included,
     * being a record with no findings), and an object whose `findings` is not the map of
     * entries it claims to hold. Entries *inside* a readable record are a different question
     * and stay as tolerant as they were: an entry that does not substantiate itself is skipped
     * by `readFindings()`, because the record around it can still be read.
     *
     * @return Record
     * @throws UnreadableRecord when the file is there and is not a record
     */
    private function decode(): array
    {
        if (! is_file($this->file)) {
            // Something that is not a file and is not nothing: a `file:` naming a directory,
            // most plausibly, which can never be a record however many boots run.
            if (file_exists($this->file)) {
                throw UnreadableRecord::of($this->file, 'is not a file');
            }

            return ['findings' => [], 'store_probed_at' => null];
        }

        $raw = @file_get_contents($this->file);

        if (! is_string($raw)) {
            // A type guard as much as a state: the file can be removed between the check and
            // the read, and `persist()` does exactly that when a boot finds nothing left to
            // remember. A read that failed is not a record either way.
            throw UnreadableRecord::of($this->file, 'could not be read');
        }

        if (trim($raw) === '') {
            throw UnreadableRecord::of($this->file, 'is empty');
        }

        // Parsed twice on purpose: the first parse keeps JSON objects as objects, which is
        // the only way to tell `{}` — an empty record — from `[]`, which is not a record at
        // all. The second is the map the rest of this class reads.
        $shape = json_decode($raw);

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw UnreadableRecord::of($this->file, 'is not JSON ('.json_last_error_msg().')');
        }

        if (! $shape instanceof \stdClass || ! is_array($decoded)) {
            throw UnreadableRecord::of($this->file, 'is not the JSON object a record is');
        }

        $findings = $decoded['findings'] ?? [];

        if (! is_array($findings)) {
            throw UnreadableRecord::of($this->file, 'does not hold a map of findings');
        }

        $probed = $decoded['store_probed_at'] ?? null;

        return [
            'findings' => $this->readFindings($findings),
            'store_probed_at' => is_int($probed) ? $probed : null,
        ];
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
     * This is the strict reader: a file that is there and is not a record throws, rather
     * than returning an empty list that a surface would print as "nothing standing". The
     * caller is `reported()`, which turns the throw into the `available: false` block with
     * the reason in it — so "the record could not be read" is a thing both surfaces can say
     * instead of a branch nothing could reach.
     *
     * @return list<Standing>
     * @throws UnreadableRecord when the record exists and cannot be read as one
     */
    public function standing(): array
    {
        $standing = [];

        foreach ($this->decode()['findings'] as $key => $finding) {
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
                // What the finding was written by, so a surface can say that the process
                // reading it now is somewhere else. Empty for a record written before the
                // scope was kept, which reads as "not known" rather than as a match.
                'scope' => $finding['scope'],
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
     * it was. `null` is the audit that was never registered; a message is the record that is
     * there and is not one — `standing()` throws `UnreadableRecord` for a file that could not
     * be read, is empty, is not a JSON object, or does not hold a map of findings, and the
     * `catch` above is what turns that into a reason.
     *
     * That branch is the reason the two readers are split. While `standing()` could not throw
     * the branch could not fire either, and an unreadable record came out the other side as an
     * empty one: `available: true, count: 0` — "nothing is standing" — asserted about a file
     * the reader had just failed to open. Silence would have been closer to true. The tolerant
     * reading belongs to the boot that has to carry the record over (`read()`), and refusing to
     * round one to the other is what makes this reachable.
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
     * The block carries a live half as well, and it is the one thing here that runs
     * producers rather than reads a file — see `live()`. It is still nothing that writes,
     * and the network probe is deliberately not among it, so the cost is a few configuration
     * reads and stats.
     *
     * @param self|null $audit the audit the caller resolved — null when the application has
     *        none registered, which is a fact about the installation and not about the reader
     * @return Block
     */
    public static function reported(?self $audit): array
    {
        $live = $audit?->live();

        if ($audit === null) {
            return self::nothingToReport(null, $live);
        }

        try {
            $findings = $audit->standing();
        } catch (\Throwable $e) {
            return self::nothingToReport($e->getMessage(), $live);
        }

        $summary = self::standingSummary($findings);

        return [
            'available' => true,
            'count' => count($findings),
            'severity' => $summary['severity'],
            'counts' => $summary['counts'],
            // The list is oldest first, so the first entry is the oldest finding.
            'oldest' => $findings[0]['first_reported_at'] ?? null,
            'findings' => self::annotate($findings, $live),
            'error' => null,
            'checked_at' => $live['checked_at'] ?? null,
            'scope' => $live['scope'] ?? null,
            'current' => self::liveBlock($live),
        ];
    }

    /**
     * The audited settings as this process reads them *now*, or null when no evaluator is
     * registered.
     *
     * This is the live half's one reader, and it answers three questions a surface needs:
     * which keys could be evaluated at all, what those keys say now, and what scope the
     * evaluating process is running as. The evaluator is supplied by the provider, so this
     * class can publish the reading without knowing a single producer — the point of the
     * split, since `BootAudit` owns the record and the provider owns the settings.
     *
     * An evaluator that throws is not a surface's problem: a diagnostic must not take down
     * the page it is reporting on, so the failure is caught and published as
     * `current.available: false` with the reason, exactly the way an unreadable record is.
     * The recorded half is still returned either way — a live evaluation that could not run
     * is no reason to withhold what the record already knows.
     *
     * @return LiveReading|null
     */
    private function live(): ?array
    {
        if ($this->currentFindings === null) {
            return null;
        }

        $checkedAt = self::timestamp();

        try {
            $evaluation = ($this->currentFindings)();
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'error' => 'the live reading of the audited settings did not run: '.$e->getMessage(),
                'scope' => null,
                'checked_at' => $checkedAt,
                'evaluated' => [],
                'findings' => [],
            ];
        }

        $scope = $evaluation['scope'] ?? null;
        $evaluated = $evaluation['evaluated'] ?? null;
        $findings = [];

        foreach ($evaluation['findings'] ?? [] as $finding) {
            $findings[] = self::findingAsLive($finding);
        }

        return [
            'available' => true,
            'error' => null,
            'scope' => is_array($scope) ? $scope : [],
            'checked_at' => $checkedAt,
            'evaluated' => is_array($evaluated) ? $evaluated : [],
            'findings' => $findings,
        ];
    }

    /**
     * The live half as the block shaped it: the same summary and vocabulary the recorded
     * half carries, so an alert can be written against either and a reader can hold the two
     * side by side.
     *
     * @param LiveReading|null $live
     * @return Live
     */
    private static function liveBlock(?array $live): array
    {
        if ($live === null || !$live['available']) {
            $summary = self::standingSummary([]);

            return [
                'available' => false,
                'count' => 0,
                'severity' => $summary['severity'],
                'counts' => $summary['counts'],
                'findings' => [],
                'error' => $live['error'] ?? 'no live reading of the audited settings is configured here',
            ];
        }

        $summary = self::standingSummary($live['findings']);

        return [
            'available' => true,
            'count' => count($live['findings']),
            'severity' => $summary['severity'],
            'counts' => $summary['counts'],
            'findings' => $live['findings'],
            'error' => null,
        ];
    }

    /**
     * Every recorded finding with what this process can see about it now.
     *
     * Three answers, and the third is why the first two are separate fields. `evaluated` is
     * false for a key the live half deliberately did not look at — the store's reachability
     * is the one, because a probe is a connect timeout — and only then is `standing` null.
     * That is the difference between "this setting reads well here" and "nothing here asked
     * it", and collapsing the two would let a payload call a setting cleared that nobody
     * checked.
     *
     * `scope_matches` compares the scope the finding was recorded in with the scope
     * evaluating it now. It is null when either side is unknown — a record written before
     * scope was kept, or a live half that did not run — because "the same" is not a claim a
     * missing half can support.
     *
     * @param list<Standing> $findings
     * @param LiveReading|null $live
     * @return list<Reported>
     */
    private static function annotate(array $findings, ?array $live): array
    {
        $liveKeys = $live === null ? [] : array_column($live['findings'], 'key');
        $liveScope = $live['scope'] ?? null;

        foreach ($findings as $index => $finding) {
            $evaluated = $live !== null
                && $live['available']
                && in_array($finding['key'], $live['evaluated'], true);

            $findings[$index]['current'] = [
                'evaluated' => $evaluated,
                'standing' => $evaluated ? in_array($finding['key'], $liveKeys, true) : null,
                'scope_matches' => is_array($liveScope) && $liveScope !== [] && $finding['scope'] !== []
                    ? self::canonical($finding['scope']) === self::canonical($liveScope)
                    : null,
            ];
        }

        return $findings;
    }

    /**
     * One live finding as the block carries it: the setting, the level, the sentence and the
     * context, with no age and no resolution. An age belongs to a record — the live half is
     * now — and the resolution is the sentence a *later* boot logs, which a reading taken
     * this instant has no reason to name.
     *
     * @return LiveFinding
     */
    private static function findingAsLive(BootAuditFinding $finding): array
    {
        return [
            'key' => $finding->key,
            'level' => $finding->level,
            'warning' => $finding->warning,
            'resolution' => $finding->resolution,
            'context' => $finding->context,
        ];
    }

    /**
     * The block for a reader that has no record: the application registered no audit, or the
     * one it registered could not be read. Neither is a claim about the installation.
     *
     * @param LiveReading|null $live the live half, still worth publishing over a record nobody could read
     * @return Block
     */
    private static function nothingToReport(?string $error, ?array $live): array
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
            // The live half is still worth publishing over a record nobody could read: an
            // unreadable record says nothing about whether a setting reads on now, and the
            // process is standing right here.
            'checked_at' => $live['checked_at'] ?? null,
            'scope' => $live['scope'] ?? null,
            'current' => self::liveBlock($live),
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
     * The parameter is the one field the rule reads rather than a whole `Standing`: the
     * recorded half and the live half are different shapes and the summary is about neither,
     * so both can be summarised without one being dressed up as the other.
     *
     * @param list<array{level: string}> $standing
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
     * The sentence for a probe that is switched off, written once for the two surfaces that report
     * it: the boot finding and `db:doctor`'s row. It is the row's sentence because the row is where
     * an operator reads it, and the finding because a standing state is what a record remembers —
     * one setting, one sentence, two readers.
     *
     * It ends without a period on purpose (`README`'s "Disabling the probe is a choice"): the row
     * appends a condition of its own to it, and a row that owns half a sentence cannot have it ended
     * for it here.
     */
    public static function probeOffSentence(int $seconds): string
    {
        return sprintf(
            'switched off (swrr.audit.store_probe_seconds = %d) — an unreachable store is reported by nothing at boot; db:doctor still probes on demand',
            $seconds,
        );
    }

    /**
     * The sentence for a record the probe cannot be remembered in, shared with the row for the same
     * reason. This one is not a choice: the record is the throttle, so nothing runs the PING and
     * nothing reports an unreachable store, on every boot, from the boot that finds the file.
     */
    public static function probeUnwritableSentence(string $file): string
    {
        return sprintf(
            'the record at %s cannot be written, and it is what throttles the probe, so the PING is skipped on every boot — nothing will report an unreachable store',
            $file,
        );
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
     * `$record` is what this boot read when it started, and the write merges into the file as
     * it is now rather than replacing it with the result of that read — so the keys this boot
     * did not evaluate, and the ones another boot recorded while this one was running, are
     * taken from disk. See `persist()`, which is also where the lock is taken.
     *
     * @param Record $record what a previous boot reported
     * @param list<BootAuditFinding> $findings the settings that cannot act now
     * @param list<string> $checked the finding keys this boot actually evaluated —
     *        including the ones that came back clean. A recorded key outside this
     *        set was not looked at (a throttled store probe) and is carried over
     *        untouched rather than reported as resolved.
     * @param int|null $probedAt when the store was probed for this report, if it was
     * @param array<string, mixed> $scope what this boot was running as — the connection the
     *        package followed, its driver and `app.env` — remembered with every finding it
     *        writes, so a later reader can tell this boot's findings from its own. Empty when
     *        the caller knows of no scope, which reads as "not known" rather than as a match
     */
    public function report(array $record, array $findings, array $checked, ?int $probedAt = null, array $scope = []): void
    {
        $now = self::timestamp();

        // Logging and folding happen in one pass, so a key two findings agree on cannot
        // cost one of them its log line — see fold().
        $current = $this->fold($findings, $record, $now);

        $standing = [];
        $next = [];

        /** @var array<string, Finding> $resolutions */
        $resolutions = [];

        foreach ($record['findings'] as $key => $reported) {
            if (isset($current[$key])) {
                continue;   // still failing: its warning was just logged again
            }

            if (!in_array($key, $checked, true)) {
                $standing[$key] = $reported;   // not looked at this boot: left alone

                continue;
            }

            // Collected rather than logged here, because whether this key has actually
            // stopped standing is decided by the write: an entry another boot substantiated
            // is kept, and a line saying the finding cleared would then be false about the
            // record — a monitor closing its page on `resolved: true` would be closing one
            // that still stands. The resolutions are logged below, without the keys the
            // merge kept.
            $resolutions[$key] = $reported;
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
                'scope' => $scope,
            ];
        }

        foreach ($standing as $key => $reported) {
            $next[$key] = $reported;
        }

        $updated = [
            'findings' => $next,
            'store_probed_at' => $probedAt ?? $record['store_probed_at'],
        ];

        // The keys this boot produced, as opposed to the ones it carried over: the merge
        // needs the difference, because a carried-over entry is not this boot's news about a
        // setting and is not this boot's to write.
        $kept = $this->persist($record, $updated, array_keys($current));

        if ($resolutions === []) {
            return;
        }

        foreach ($resolutions as $key => $reported) {
            if (in_array($key, $kept, true)) {
                continue;   // kept: the record still holds it, so it has not cleared
            }

            Log::warning('[WeightedDB] '.$reported['resolution'], self::logContext(self::SEVERITY_WARNING, [
                'finding' => $key,
                'first_reported_at' => $reported['first_reported_at'],
                'resolved' => true,
            ] + $reported['context']));
        }
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

            // A record written before the scope was remembered carries none, and an empty
            // map is the honest reading: the finding is still a fact about a setting, and
            // which boot wrote it is simply not known.
            $rawScope = $entry['scope'] ?? null;
            /** @var array<string, mixed> $scope */
            $scope = is_array($rawScope) ? $rawScope : [];

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
                'scope' => $scope,
            ];
        }

        return $findings;
    }

    /**
     * Merge what this boot produced into the record, and write the result.
     *
     * WHY THIS IS A MERGE, AND WHY IT TAKES A LOCK
     * --------------------------------------------
     *   The record is one file shared by every boot of an installation — per installation,
     *   not per worker, which is what makes the probe cheap and this write contended. This
     *   boot decided what to remember from the copy it read near its start, and the store
     *   probe between the two is a network call, so the window can be a connect timeout wide.
     *   Replacing the file with that copy discards whatever another boot recorded in the
     *   meantime: an entry no reader has taken out of the record yet, and with it the age the
     *   next boot would have dated it from.
     *
     *   Two mechanisms close that, and both are needed. The write re-reads the file as it is
     *   about to be replaced and merges, so a copy that has gone stale is not what lands. And
     *   the read-merge-write is taken under an exclusive advisory lock, so two boots cannot
     *   merge from the same copy and write over each other — the merge narrows the window, the
     *   lock closes it.
     *
     *   The lock is `flock(LOCK_EX)` on a companion file, and *which* lock matters as much as
     *   the mutual exclusion: it lives on an open descriptor, so the kernel releases it when
     *   the process ends however it ends. A worker killed mid-write leaves nothing behind that
     *   stops the next boot from writing — the file it locked may remain, but an empty file is
     *   not a lock — where the sentinel the store-probe decision refused (a lock whose
     *   *existence* is the lock, or one holding a pid) would wait for somebody to find it. A
     *   boot does not wait for this one indefinitely either: `LOCK_ATTEMPTS` short attempts,
     *   then the merge goes ahead from the freshest read it can take — recording the settings
     *   this boot checked matters more than recording them alone — and says it could not
     *   serialise.
     *
     * WHAT THE MERGE DECIDES, KEY BY KEY
     * ----------------------------------
     *   A key takes this boot's verdict only where this boot looked at the file as it now is.
     *   The keys this boot produced are written over whatever is there. An entry this boot
     *   evaluated and found clean is removed — and only while it is still the entry this boot
     *   read: one that *changed* under this boot while it was about to clear that key is
     *   another boot's report, and it is kept, because a boot that just logged a setting
     *   failing is not contradicted by a copy read earlier that was about to close it out.
     *   Every other key is taken as it is on disk at the write, which is what "not looked at
     *   this boot" has always meant — the difference being that it is now read here rather
     *   than from the copy this boot started with.
     *
     *   Nothing is refused and nothing is silent: a write that keeps an entry this boot was
     *   about to clear says so, with the keys, and so does a write that could not take the
     *   lock. The safe path is the quiet one.
     *
     * WHERE THE RECORD CANNOT BE WRITTEN, NONE OF THIS RUNS
     * ----------------------------------------------------
     *   A directory that accepts no file is the same state the store probe is gated on, and it
     *   is answered the same way: the merge is not attempted, because its result has nowhere to
     *   go. The rule is not only about the work — eight attempts at a lock the filesystem will
     *   not let this process open is a fifth of a second added to every boot under FPM, and the
     *   boot already says what it found. It is also about the line above: a write that cannot
     *   land has not "merged without serialising", and there is nothing to serialise it
     *   against, so reporting one would be a claim about a write that never happened.
     *
     * @param Record $previous the record this boot read when it started
     * @param Record $updated  what this boot would write on its own
     * @param list<string> $produced the keys `$updated` holds because *this* boot produced
     *        them, rather than carrying them over from `$previous`
     * @return list<string> the keys kept against this boot's clean verdict, so the caller can
     *         keep quiet about a finding the record still holds
     */
    private function persist(array $previous, array $updated, array $produced): array
    {
        if (self::canonical($previous) === self::canonical($updated)) {
            return [];
        }

        // Nothing can be written where the record's directory takes no file, so none of the
        // read-merge-write is attempted — see the docblock. A resolution is still logged by the
        // caller, which is the honest reading: this boot did evaluate the key and find it clean,
        // and it is the record's repair that has nowhere to go rather than the finding's state.
        if (!$this->recordIsWritable()) {
            return [];
        }

        [$held, $handle, $attempts, $waited] = $this->acquireLock();

        try {
            $onDisk = $this->read();

            [$record, $kept] = $this->merge($previous, $updated, $produced, $onDisk);

            if ($kept !== []) {
                $this->reportKept($kept, $onDisk, $previous, $updated);
            }

            if (!$held) {
                $this->reportUnlocked($attempts, $waited);
            }

            $this->write($record);
        } finally {
            ($this->lockReleaser)($handle);
        }

        return $kept;
    }

    /**
     * The record as it should be written: this boot's findings, this boot's clean verdicts
     * where they still apply, and every other key as the file holds it now.
     *
     * @param Record $previous
     * @param Record $updated
     * @param list<string> $produced
     * @param Record $onDisk
     * @return array{0: Record, 1: list<string>} the record, and the keys kept against this
     *         boot's clean verdict because another boot substantiated them
     */
    private function merge(array $previous, array $updated, array $produced, array $onDisk): array
    {
        $clearing = array_diff(array_keys($previous['findings']), array_keys($updated['findings']));

        $findings = [];
        $kept = [];

        foreach ($onDisk['findings'] as $key => $entry) {
            $read = $previous['findings'][$key] ?? null;

            if ($read !== null && in_array($key, $clearing, true)) {
                if (self::canonical($read) === self::canonical($entry)) {
                    continue;   // this boot evaluated it and found it clean: the news is this boot's
                }

                // Another boot substantiated it after this boot read the file, so the entry it
                // wrote stands and a clean verdict from an older copy does not clear it.
                $kept[] = $key;
            }

            $findings[$key] = $entry;
        }

        foreach ($updated['findings'] as $key => $entry) {
            if (!in_array($key, $produced, true)) {
                continue;   // carried over, not asserted: the file — just read — is its record
            }

            $findings[$key] = $entry;
        }

        return [[
            'findings' => $findings,
            'store_probed_at' => self::latestProbe($onDisk['store_probed_at'], $updated['store_probed_at']),
        ], $kept];
    }

    /**
     * The later of two probe stamps — a probe another boot recorded is not forgotten by this
     * one, which is the difference between merging the record and writing a copy of it: the
     * cost of forgetting is a probe nobody needed.
     */
    private static function latestProbe(?int $left, ?int $right): ?int
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return max($left, $right);
    }

    /**
     * The record to disk, atomically: written beside its target and renamed over it, so a
     * concurrent reader never sees half a record. Removed instead when there is nothing left
     * to remember — the merged record, not this boot's copy of it, because a boot whose own
     * findings all cleared has no business deleting an entry another boot just recorded.
     *
     * @param Record $record
     */
    private function write(array $record): void
    {
        if ($record['findings'] === [] && $record['store_probed_at'] === null) {
            @unlink($this->file);

            return;
        }

        $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

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
     * The record's lock, asked for a bounded number of times.
     *
     * @return array{0: bool, 1: resource|null, 2: int, 3: int} held, the descriptor it is held
     *         on, how many attempts were made, and how long they took in milliseconds
     */
    private function acquireLock(): array
    {
        $started = microtime(true);

        for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
            [$held, $handle] = ($this->lockAcquirer)($this->lockPath());

            if ($held) {
                return [true, $handle, $attempt, self::millisecondsSince($started)];
            }

            if ($attempt < self::LOCK_ATTEMPTS) {
                usleep(self::LOCK_RETRY_MICROSECONDS);
            }
        }

        return [false, null, self::LOCK_ATTEMPTS, self::millisecondsSince($started)];
    }

    private static function millisecondsSince(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * The file the record's lock is taken on: beside the record rather than on it. The write
     * replaces the record by renaming a temp over it, so a descriptor opened on the record
     * would be holding a file nothing else will ever open again — and two boots that opened it
     * either side of a rename would each hold "the record" and neither exclude the other.
     *
     * The file may outlive the lock, and that is the point: an empty file beside the record is
     * not a lock, says nothing, and stops nothing.
     */
    private function lockPath(): string
    {
        return $this->file.'.lock';
    }

    /**
     * Say so when an entry another boot substantiated is kept against this boot's clean verdict
     * on that key.
     *
     * The line is about a disagreement rather than a loss: with the merge, the entry survives
     * this write, and the sentence names the keys that were kept. What it cannot settle is
     * which of the two boots is right — configuration is read once per process, so two boots in
     * flight can genuinely be running different configurations — and the record keeps the
     * finding until a boot evaluates the key cleanly with nothing re-reporting it in between.
     *
     * @param list<string> $kept
     * @param Record $onDisk
     * @param Record $previous
     * @param Record $updated
     */
    private function reportKept(array $kept, array $onDisk, array $previous, array $updated): void
    {
        Log::error(sprintf(
            '[WeightedDB] The audit record changed while this boot was running: this write keeps %d entr%s another boot recorded after this boot read the file, and does not clear %s — a setting another boot has just reported failing is not cleared by a copy read before that. It stands until a boot evaluates the key cleanly with nothing re-reporting it.',
            count($kept),
            count($kept) === 1 ? 'y' : 'ies',
            count($kept) === 1 ? 'it' : 'them',
        ), self::logContext(self::SEVERITY_ERROR, [
            'record' => $this->file,
            'kept' => $kept,
            'keys_on_disk' => array_keys($onDisk['findings']),
            'keys_this_boot_read' => array_keys($previous['findings']),
            'keys_this_boot_writes' => array_keys($updated['findings']),
        ]));
    }

    /**
     * Say so when the record's lock could not be taken: the merge still ran, but nothing
     * serialised it against another boot, so an entry added between its read and its write can
     * be lost — the one write this class still makes that another boot's work can disappear
     * under, and the reason the line is here rather than a refusal.
     *
     * A refusal would leave the settings this boot checked unrecorded, over a lock the operator
     * may not be able to fix from where the line is read; the directory the lock lives in is
     * named, because that is the likeliest cause and the fix is a permission.
     */
    private function reportUnlocked(int $attempts, int $waited): void
    {
        Log::error(sprintf(
            '[WeightedDB] The audit record lock could not be taken in %d attempt(s) over %dms, so this write merged without serialising against another boot — an entry added between its read and its write can be lost. The lock is %s, and a directory that does not accept a file there is the likeliest cause.',
            $attempts,
            $waited,
            $this->lockPath(),
        ), self::logContext(self::SEVERITY_ERROR, [
            'record' => $this->file,
            'lock' => $this->lockPath(),
            'attempts' => $attempts,
            'waited_ms' => $waited,
        ]));
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
