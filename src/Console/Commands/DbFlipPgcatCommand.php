<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console\Commands;

use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Pgcat\DryRunResult;
use Uak35\WeightedDbManager\Pgcat\FlipResult;
use Uak35\WeightedDbManager\Pgcat\FlipWindow;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Illuminate\Console\Command;

/**
 * php artisan db:pgcat-flip [options]
 *
 * Routes:
 *   db:pgcat-flip                       — one-shot, swap if mode changed
 *   db:pgcat-flip --watch --interval=N  — daemon mode (loops every N seconds, default 30)
 *   db:pgcat-flip --status              — show current state without flipping
 *   db:pgcat-flip --status --pool-probe — …and ask the running pooler about its pools
 *   db:pgcat-flip --force-mode=readers  — force into readers config
 *   db:pgcat-flip --force-mode=writer   — force into writer-only config
 *   db:pgcat-flip --dry-run             — rehearse the flip: every step except the
 *                                         rename and the supervisor command, reported
 *                                         as what a flip would have done
 *   db:pgcat-flip --dry-run --json      — the same rehearsal as one JSON object, for a
 *                                         pipeline to assert on instead of parsing a report
 *
 * `--status` answers "where is the flipper now" from configuration and the state file;
 * `--dry-run` answers "would a flip work", by taking every step that can be taken without
 * changing anything. `--status` wins when both are passed, because a state report is what
 * was asked for. `--dry-run --force-mode=readers` rehearses the forced flip, exactly as
 * `--force-mode` would apply it.
 *
 * `--pool-probe` is the status report's one live reading and needs `--status`: it asks the
 * running pooler whether it holds the pools the configuration declares, which is the reading
 * `/health/db` and `db:doctor` already make. The default status stays offline — the report is
 * run on hosts that may be the wrong side of the pooler, and a diagnostic that waits on a
 * connect timeout is a different contract — so the socket is opened only when an operator
 * asks for it, and the flag changes the report rather than the exit code.
 *
 * `--json` changes the report and nothing else: one object on stdout, the same exit code as
 * the run it describes, the same file untouched. It is refused beside `--watch`, because a
 * report a machine reads is one object and a daemon emits one every interval forever.
 *
 * Typical deployment: schedule every minute in routes/console.php:
 *
 *     Schedule::command('db:pgcat-flip')->everyMinute()
 *              ->withoutOverlapping(60)
 *              ->runInBackground();
 *
 * Or use --watch under supervisor (Octane-friendly) for sub-minute precision.
 */
class DbFlipPgcatCommand extends Command
{
    /**
     * The verdicts this command answers without asking the flipper — the four kinds that are
     * not a `FlipResult` or a `DryRunResult`. They live beside the flipper's own kinds in the
     * JSON report's `kind`, so a pipeline reading it has one vocabulary rather than two nested
     * questions.
     */
    public const KIND_STATUS = 'status';

    public const KIND_DISABLED = 'disabled';

    public const KIND_REFUSED = 'refused';

    public const KIND_UNBOUND = 'unbound';

    /**
     * The evidence a flip's report carries, and the value an absent one takes — the envelope's
     * rule, declared once instead of assembled per route.
     *
     * `mode` and `previous_mode` are the modes the run is about. `status` is the flipper's own
     * `status()` array, not the table's substitutions: `(not set)` is how a table prints a `null`,
     * and a machine has to be able to test a path for being unset without matching an English
     * sentence. `steps` is a rehearsal's pipeline, and empty on every other route. `pool_probe` is
     * `PgcatConfigFlipper::poolProbeVerdict()`, present only on a `--status --pool-probe` run — a
     * `null` is the offline report rather than a probe that found nothing.
     */
    private const EVIDENCE = [
        'mode' => null,
        'previous_mode' => null,
        'steps' => [],
        'status' => null,
        'pool_probe' => null,
    ];

    protected $signature = 'db:pgcat-flip
                            {--watch : Run continuously, polling every --interval seconds}
                            {--interval=30 : Polling interval in seconds when --watch is set}
                            {--force-mode= : Force a specific mode (readers|writer) instead of consulting the resolver}
                            {--status : Show current state without flipping}
                            {--pool-probe : With --status, ask the running pooler which of the declared pools it holds}
                            {--dry-run : Rehearse a flip — every step but the rename and the supervisor command — and report what it would have done}
                            {--json : Print one JSON object — the verdict, the exit code and the evidence — instead of the rendered report}';

    protected $description = 'Sync pgcat.toml with the SWRR reader/writer window';

    public function handle(): int
    {
        /** @var PgcatConfigFlipper|null $flipper */
        $flipper = app(PgcatConfigFlipper::class);

        if (! $flipper instanceof PgcatConfigFlipper) {
            return $this->refuse(
                'PgcatConfigFlipper is not registered. Check WeightedDatabaseServiceProvider.',
                self::KIND_UNBOUND,
            );
        }

        // `--pool-probe` is the status report's one live reading, so it is refused anywhere else
        // rather than ignored: a flip has its own pool check, and a run that asked for neither a
        // report nor a flip has nowhere to print an answer. Refused before the status branch, so
        // `--status` is the only route that can carry it.
        if ($this->option('pool-probe') && ! $this->option('status')) {
            return $this->refuse(
                '--pool-probe needs --status: it is the state report\'s one live reading, and a run '
                .'that is not printing the state report has nowhere to put the pooler\'s answer'
            );
        }

        // --status: just print and exit
        if ($this->option('status')) {
            return $this->renderStatus($flipper);
        }

        // An empty --force-mode is treated as absent, the way the flag itself reads.
        $forced = $this->option('force-mode');
        $forced = is_string($forced) && $forced !== '' ? $forced : null;

        if ($forced !== null && ! in_array($forced, ['readers', 'writer'], true)) {
            return $this->refuse("--force-mode must be 'readers' or 'writer'");
        }

        // --dry-run: rehearse, and never start a loop. A watch daemon whose every pass is a
        // rehearsal looks exactly like one that is flipping, which is the one way this flag
        // could mislead, so the pair is refused rather than half-honoured.
        if ($this->option('dry-run')) {
            if ($this->option('watch')) {
                return $this->refuse(
                    '--dry-run and --watch are mutually exclusive: a watch daemon that never flips '
                    .'would look like one that does'
                );
            }

            return $this->renderDryRun($flipper->dryRun($forced));
        }

        // A machine-readable report is one object; a watch daemon would emit one every interval
        // forever, and the exit code of the loop is not the verdict of any pass. Refused rather
        // than half-honoured, like the pair above — and after it, so a run that passed both
        // flag combinations is told about the one a rehearsal cannot survive.
        if ($this->option('json') && $this->option('watch')) {
            return $this->refuse(
                '--json and --watch are mutually exclusive: a JSON report is one object, and a watch '
                .'daemon would print one every interval without ever reporting a single verdict for the run'
            );
        }

        // Nothing to flip: either swrr.pgcat.enabled is off, or the current
        // connection is not PostgreSQL and pgcat cannot front it. Say why once
        // and exit 0 — running on would print the same no-change line every
        // interval under --watch, and the flipper would refuse each time anyway.
        if ($reason = $flipper->disabledReason()) {
            return $this->disabled($reason);
        }

        // --force-mode: bypass the resolver
        if ($forced !== null) {
            return $this->renderResult($flipper->forceMode($forced, PgcatConfigFlipper::VIA_MANUAL));
        }

        // --watch: daemon loop
        if ($this->option('watch')) {
            return $this->runWatch($flipper, (int) $this->option('interval'));
        }

        // default: one-shot
        return $this->renderResult($flipper->applyCurrentState());
    }

    /**
     * Daemon mode — checks every interval and applies state changes.
     * Honours SIGTERM/SIGINT for graceful shutdown.
     */
    private function runWatch(PgcatConfigFlipper $flipper, int $interval): int
    {
        $stop = false;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);

            $handler = function () use (&$stop): void {
                $stop = true;
            };

            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }

        $this->info("[pgcat-flip] watching — polling every {$interval}s (Ctrl+C to stop)");

        // The handler above flips $stop from another stack frame, which static
        // analysis cannot see — widen the type so the loop is not mistaken for
        // an infinite one.
        /** @var bool $stop */
        while (! $stop) {
            $result = $flipper->applyCurrentState();
            $this->line('['.date('H:i:s').'] '.$result->summary());

            // Sleep in 1-second slices so signals are checked promptly.
            /** @var bool $stop */
            for ($i = 0; $i < $interval && ! $stop; $i++) {
                sleep(1);
            }
        }

        $this->info('[pgcat-flip] stopped');

        return self::SUCCESS;
    }

    /**
     * One flip, reported: the JSON object when `--json` asked for it, the colour-coded
     * summary line otherwise. Both return the result's own exit code, so the report and the
     * code cannot come from different places.
     */
    private function renderResult(FlipResult $result): int
    {
        if ($this->option('json')) {
            return $this->report($result->toArray(), $result->exitCode());
        }

        $line = $result->summary();
        $this->match(
            $result->kind(),
            fn () => $this->info($line),
            fn () => $this->info($line),
            fn () => $this->warn($line),
            fn () => $this->error($line),
        );

        return $result->exitCode();
    }

    /**
     * The rehearsal's report: the summary line, then every step of a flip in the order a flip
     * takes them — performed, one a flip *would* take, or the step that stopped the run.
     *
     * The detail column carries the evidence an operator needs and nothing else can supply:
     * which file was read and how many bytes, which temp file the directory accepted, the
     * command a flip would run, and whether the target's bytes would actually change.
     */
    private function renderDryRun(DryRunResult $result): int
    {
        if ($this->option('json')) {
            // The rehearsal's own array already carries the pipeline — `steps`, with each
            // step's outcome — which is the evidence a pipeline asserts on, so nothing is
            // reshaped on the way out.
            return $this->report($result->toArray(), $result->exitCode());
        }

        $line = '[dry run] '.$result->summary();

        match ($result->kind()) {
            DryRunResult::KIND_WOULD_FLIP => $this->info($line),
            DryRunResult::KIND_WOULD_NOT_FLIP, DryRunResult::KIND_SKIPPED => $this->warn($line),
            // KIND_FAILED, plus anything a newer version might write.
            default => $this->error($line),
        };

        foreach ($result->steps as $step) {
            $this->line(sprintf(
                '  %-14s %s  %s',
                $step['step'],
                $this->stepOutcome($step['outcome']),
                $step['detail'],
            ));
        }

        return $result->exitCode();
    }

    /**
     * The outcome word, padded before the colour tags are added so the columns line up: the
     * padding is part of the text an operator reads, and tags would be counted as characters.
     */
    private function stepOutcome(string $outcome): string
    {
        $padded = str_pad($outcome, 7);

        return match ($outcome) {
            DryRunResult::STEP_DONE => "<fg=green>{$padded}</>",
            DryRunResult::STEP_WOULD => "<fg=yellow>{$padded}</>",
            DryRunResult::STEP_FAILED => "<fg=red>{$padded}</>",
            default => "<fg=gray>{$padded}</>",
        };
    }

    /**
     * The state report, and — when `--pool-probe` asked for it — the one reading in it that
     * leaves the process.
     *
     * The probe is asked *after* the status array is built and never folded into it: `status`
     * stays the flipper's own array, which is the contract `--json` documents, and the pooler's
     * answer travels beside it as `pool_probe`. A probe that failed does not change the exit
     * code — this is a report, and the run's code is not one of the facts the flag was asked
     * to establish. An installation that wants a verdict with a code has `db:doctor`'s row.
     */
    private function renderStatus(PgcatConfigFlipper $flipper): int
    {
        $s = $flipper->status();
        $probe = $this->option('pool-probe') ? $flipper->poolProbeVerdict() : null;

        if ($this->option('json')) {
            // The status array as the flipper computed it, with none of the table's
            // substitutions: `(not set)` is a way of printing an absent path, and a machine
            // wants the absent path. The one thing added is the verdict, which is what every
            // report in this mode leads with — and the probe's answer beside it, present only
            // when the flag asked for it, so `null` is the offline report rather than a probe
            // that found nothing.
            return $this->report([
                'kind' => self::KIND_STATUS,
                'status' => $s,
                'pool_probe' => $probe,
            ], self::SUCCESS);
        }

        $this->table(
            ['key', 'value'],
            [
                ['enabled',           $s['enabled'] ? 'yes' : 'no'],
                ['connection',        $s['connection'] ?: '(not set)'],
                ['driver',            $s['driver'] ?: '(unknown)'],
                ['pgcat applicable',  $s['driver_supported'] ? 'yes (postgresql)' : 'no — this driver has no pgcat pool'],
                ['configured switch', $s['configured_enabled'] ? 'on' : 'off (swrr.pgcat.enabled)'],
                ['inactive reason',   $s['reason'] ?? '(none — flipping is active)'],
                ['armed reason',      $s['armed_reason'] ?? '(not armed)'],
                ['warning',           $s['warning'] ?? '(none)'],
                ['resolver_mode',     $s['resolver_mode']],
                ['last_applied_mode', $s['last_mode'] ?? '(never applied)'],
                ['config_path',       $s['config_path'] ?: '(not set)'],
                ['readers_path',      $s['readers_path'] ?: '(not set)'],
                ['no_readers_path',   $s['no_readers_path'] ?: '(not set)'],
                ['restart_command',   $s['restart_command']],
                ['reload_command',    $s['reload_command']],
                ['use_reload',        $s['use_reload'] ? 'yes (HUP signal)' : 'no (full restart)'],
                ['state_file',        $s['state_file']],
                ['lock_file',         $s['lock_file']],
                ...$this->windowRows($s['window']),
                ...$this->readerWindowRows($s['reader_window']),
                ...$this->poolProbeRows($probe, $s['enabled']),
            ],
        );

        return self::SUCCESS;
    }

    /**
     * The pooler's own answer as table rows, and no rows at all when the flag was not given.
     *
     * The default status is the offline report, and a row saying "not asked" would be a new
     * line in a table operators read on hosts that may not reach the pooler. Rows appear only
     * on the run that asked, and they carry the same facts `db:doctor`'s `pool probe answer` row and
     * `/health/db`'s block do — the verdict, the pools and what the pooler said about each, the
     * address the packets went to, and the console's answer where there was one — because three
     * surfaces describing one exchange differently is the failure the reading was extracted for.
     *
     * The vocabulary is the flipper's: `failed` and `warning` wait for the boot window to close
     * (a starting container is not a broken one), `unverified` is an `answered` reading the
     * control could not make proof, and `no answer` is a probe that was asked and established
     * nothing either way.
     *
     * @param array<string, mixed>|null $verdict `PgcatConfigFlipper::poolProbeVerdict()`, or
     *        null when `--pool-probe` was not passed
     * @param bool $armed whether the flipper applies at all — an inert one has no pooler to
     *        ask about, because nothing swaps its file or restarts it
     *
     * @return list<array{0: string, 1: string}>
     */
    private function poolProbeRows(?array $verdict, bool $armed): array
    {
        if ($verdict === null) {
            return [];
        }

        $text = static fn (mixed $value, string $fallback = '?'): string => is_scalar($value)
            ? (string) $value
            : $fallback;

        // The row the doctor draws for the same state: nothing asks the pooler anything when the
        // flipper is inert, whatever the probe's own switch says — an answer here would be about
        // a process nothing in this package is moving.
        if (!$armed) {
            return [['pool_probe', 'the flipper is not armed — nothing asks the running pooler about its pools']];
        }

        if (($verdict['enabled'] ?? false) !== true) {
            return [[
                'pool_probe',
                'not armed (swrr.pgcat.pool_probe.enabled, and a dialable `[general] port` in the file '
                .'the pooler runs on) — so the running pooler is taken at supervisord\'s word',
            ]];
        }

        if (($verdict['applicable'] ?? false) !== true) {
            return [[
                'pool_probe',
                'nothing to ask — the variant the resolver asks for declares no pool, so the pooler '
                .'has nothing to be answerable for',
            ]];
        }

        $reason = $text($verdict['reason'] ?? null, 'the running pooler answered for every pool the configuration declares');
        $address = $verdict['address'] ?? null;
        $probed = is_string($address) && $address !== '' ? ' — probed '.$address : '';

        $reading = match (true) {
            ($verdict['failed'] ?? false) === true => 'failed',
            ($verdict['warned'] ?? false) === true => 'warning',
            ($verdict['answered'] ?? null) === true && ($verdict['verified'] ?? null) === true => 'answered',
            ($verdict['answered'] ?? null) === true => 'unverified',
            default => 'no answer',
        };

        $rows = [['pool_probe', $reading.' — '.$reason.$probed]];

        $pools = $verdict['pools'] ?? [];

        if (is_array($pools)) {
            foreach ($pools as $pool) {
                if (!is_array($pool)) {
                    continue;
                }

                $rows[] = ['pool_probe_pool', sprintf(
                    '%s — %s (%s)',
                    $text($pool['database'] ?? null),
                    $text($pool['status'] ?? null),
                    $text($pool['evidence'] ?? null, 'no evidence recorded'),
                )];
            }
        }

        $control = $verdict['control'] ?? null;

        if (is_array($control)) {
            $rows[] = ['pool_probe_control', sprintf(
                '%s — %s (%s)',
                $text($control['database'] ?? null),
                $text($control['status'] ?? null),
                $text($control['evidence'] ?? null, 'no evidence recorded'),
            )];
        }

        $admin = $verdict['admin'] ?? null;

        if (is_array($admin)) {
            $pools = $admin['pools'] ?? [];

            $rows[] = ['pool_probe_admin', sprintf(
                '%s — %d pool(s) listed (%s)',
                $text($admin['status'] ?? null),
                is_array($pools) ? count($pools) : 0,
                $text($admin['evidence'] ?? null, 'no evidence recorded'),
            )];
        }

        return $rows;
    }

    /**
     * The pooler's own configuration against the reader windows as table rows — the check the boot
     * window above cannot make, and the one the 2026-10-02 container needed.
     *
     * `last_applied_mode` is what a flip *applied*, and only a flip that got that far writes it
     * down: a task that booted outside a reader window converges to the writer-only config and
     * records nothing to correct, so the state report looks perfect right up to 10:00 — when the
     * windows move the resolver, and nothing moves pgcat with it. These rows read pgcat's file
     * instead, through the same verdict `/health/db` acts on, so a run that never recorded a mode
     * still shows here.
     *
     * The two disagreements are told apart at the front of the state row, because they send an
     * operator to opposite ends of the last window: a writer-only file inside an open window is the
     * boundary flip at the window's *start* that did not land, and the readers config outside every
     * window is the one at its *end* that never moved it back.
     *
     * @param array<mixed> $verdict the `reader_window` block of the status array, as it arrives
     *
     * @return list<array{0: string, 1: string}>
     */
    private function readerWindowRows(array $verdict): array
    {
        if ($verdict === []) {
            return [['reader_window', '(unavailable — no reader_window block in the status array)']];
        }

        // Same defensive cast as `windowRows()`: this is somebody else's array — the flipper's
        // `readerWindowVerdict()` — so a key that arrived with an unexpected type has to print a
        // placeholder rather than `Array` in the middle of a sentence.
        $text = static fn (mixed $value, string $fallback): string => is_scalar($value)
            ? (string) $value
            : $fallback;

        $expected = $text($verdict['expected'] ?? null, '?');
        $applied = $verdict['applied'] ?? null;
        $failed = ($verdict['failed'] ?? false) === true;
        $direction = is_string($verdict['direction'] ?? null) ? $verdict['direction'] : null;
        $reason = is_string($verdict['reason'] ?? null) && $verdict['reason'] !== ''
            ? $verdict['reason']
            : 'pgcat is on a configuration the reader windows are not asking for';

        $rows = [[
            'reader_window',
            $expected === 'readers'
                ? 'open — reads should use the replica pool'
                : 'closed — reads should use the writer',
        ]];

        $rows[] = ['pooler_config', match (true) {
            $applied === 'readers' => 'readers config — the variant a reader window asks for',
            $applied === 'writer' => 'writer-only config — the variant the fallback asks for',
            default => '(not judged — pgcat\'s file was not read, or it holds neither variant)',
        }];

        $rows[] = ['reader_window_state', match (true) {
            $failed && $direction === 'outside_window' => sprintf('STALE (outside the window) — %s', $reason),
            $failed => sprintf('STALE (inside the window) — %s', $reason),
            $applied === null => 'not judged',
            default => 'in step with the windows',
        }];

        return $rows;
    }

    /**
     * One ledger entry's answer to "did the restart clear it", as a cell prints it.
     *
     * Three states rather than two: `cleared` is a measurement the write took, and a state file
     * written before repairs were measured — or one somebody edited — carries no boolean. That is
     * not the same fact as a repair that ran and did not clear: `no` would describe a pooler that
     * failed to recover when nothing had asked it, which is the one answer a ledger must not invent.
     */
    private static function clearedCell(mixed $cleared): string
    {
        return match (true) {
            $cleared === true => 'cleared',
            $cleared === false => 'still refused',
            default => 'not measured',
        };
    }

    /**
     * The boot window as table rows — the four facts that answer "is this container going to be
     * reported as failed, and why", read from the same block `/health/db` publishes.
     *
     * The rows are separate rather than one sentence because the interesting states are
     * combinations: a window that is still open with no runs yet is a container two seconds into
     * its life, a closed one with no runs is a scheduler that never fired, and a closed one with
     * runs that converged is every healthy container in production. One line would have to
     * collapse those, and this report exists to be read when they disagree.
     *
     * @param array<mixed> $window the `window` block of the status array, as it arrives
     *
     * @return list<array{0: string, 1: string}>
     */
    private function windowRows(array $window): array
    {
        if ($window === []) {
            return [['flip_window', '(unavailable — no window block in the status array)']];
        }

        // Read through one small formatter rather than casting each value at the point of use:
        // this is somebody else's array — `PgcatConfigFlipper::windowStatus()` — so a key that
        // arrived with an unexpected type has to print the placeholder the rows document rather
        // than `Array` in the middle of a sentence, and `is_scalar` is what makes the cast safe
        // for the reader as well as for the analyser.
        $text = static fn (mixed $value, string $fallback): string => is_scalar($value)
            ? (string) $value
            : $fallback;

        $seconds = $text($window['window_seconds'] ?? null, '?');
        $stamped = ($window['source'] ?? null) === FlipWindow::SOURCE_STAMPED;
        $closed = ($window['closed'] ?? false) === true;
        $converged = ($window['converged'] ?? false) === true;

        $rows = [[
            'flip_window',
            $stamped
                ? sprintf('%ss from boot (%s)', $seconds, $text($window['booted_at'] ?? null, '?'))
                : sprintf('%ss configured, but no boot stamp was read — the window is not judged', $seconds),
        ]];

        $rows[] = ['flip_window_state', match (true) {
            !$stamped => 'unknown (no boot stamp)',
            $closed => is_string($window['deadline'] ?? null)
                ? sprintf('closed at %s', $window['deadline'])
                : 'closed',
            default => sprintf('open — %ss left', $text($window['remaining_seconds'] ?? null, '?')),
        }];

        if ($closed) {
            $rows[] = [
                'flip_converged',
                $converged
                    ? sprintf(
                        'yes at %s (mode %s)',
                        $text($window['converged_at'] ?? null, '?'),
                        $text($window['converged_mode'] ?? null, '?'),
                    )
                    : 'no — '.$text($window['failed_reason'] ?? null, 'the window closed without a usable mode'),
            ];
        }

        $rows[] = [
            'flip_runs',
            sprintf(
                '%s recorded (last %s at %s)',
                $text($window['runs'] ?? null, '0'),
                $text($window['last_kind'] ?? null, 'never'),
                $text($window['last_run_at'] ?? null, 'never'),
            ),
        ];

        // The pool repairs, as a row of their own rather than folded into `flip_runs`: a run can
        // be a repair (`last_kind` says so) and the count is what tells a one-off from a pooler
        // that keeps being repaired. A window block that does not carry the keys — an older
        // flipper — reads as one that has never repaired anything, which is the same rule the
        // row above follows for `runs`.
        $rows[] = [
            'pool_repairs',
            sprintf(
                '%s recorded (last %s)',
                $text($window['pool_repairs'] ?? null, '0'),
                $text($window['last_pool_repair_at'] ?? null, 'never'),
            ),
        ];

        // What the last repair was for: the count above opens the question — which pool, under
        // which variant — and the run that repaired it answered it in a sentence that is one line
        // among a container's logs by the time anybody asks. The two facts are recorded beside the
        // count, so the table answers them without the archaeology; `never` is the honest reading
        // for a container that has not repaired anything, or a state file written before they were
        // kept, which is the same default the row above uses for its time.
        $rows[] = [
            'last_repair_pool',
            $text($window['last_repair_pool'] ?? null, 'never'),
        ];

        $rows[] = [
            'last_repair_mode',
            $text($window['last_repair_mode'] ?? null, 'never'),
        ];

        $rows[] = [
            'last_repair_cleared',
            self::clearedCell($window['last_repair_cleared'] ?? null),
        ];

        // The ledger, newest first: the rows above name the latest repair, and these name every
        // repair the state file still holds. One row per entry rather than a sentence in a cell,
        // because the question the ledger exists for is a comparison — the same pool repaired three
        // times with every restart clearing it, and three repairs that never cleared, read the same
        // on a count. The rows are labelled by position because a pool may appear more than once,
        // and a table with two rows called `reader_db_2` is one an operator has to count.
        return [...$rows, ...self::repairLedgerRows($window['pool_repair_ledger'] ?? null)];
    }

    /**
     * The ledger as table rows, newest first, from a value the status array carries untyped.
     *
     * The narrowing is here rather than at the call site because `windowStatus()` returns an
     * `array<mixed>` — a caller reading `$window['pool_repair_ledger']` gets `mixed`, and a list this
     * command renders is one an operator may have edited into whatever JSON they liked. An entry
     * that is not an array is skipped rather than printed as a row of placeholders: the ledger's
     * reader already drops malformed entries, so a row saying `? pool none mode unknown` would
     * describe an entry the file does not really hold.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function repairLedgerRows(mixed $ledger): array
    {
        $entries = is_array($ledger) ? array_values(array_filter($ledger, 'is_array')) : [];
        $rows = [];

        foreach (array_reverse($entries) as $index => $entry) {
            $rows[] = [
                'repair_'.($index + 1),
                sprintf(
                    '%s  pool %s  mode %s  %s',
                    is_scalar($entry['at'] ?? null) ? (string) $entry['at'] : '?',
                    is_scalar($entry['pool'] ?? null) ? (string) $entry['pool'] : 'none',
                    is_scalar($entry['mode'] ?? null) ? (string) $entry['mode'] : 'unknown',
                    self::clearedCell($entry['cleared'] ?? null),
                ),
            ];
        }

        return $rows;
    }

    private function match(string $kind, \Closure $noChange, \Closure $flipped, \Closure $skipped, \Closure $failed): void
    {
        match ($kind) {
            FlipResult::KIND_NO_CHANGE => $noChange(),
            FlipResult::KIND_FLIPPED => $flipped(),
            // A closed window is the flipper declining to act, not a run that went wrong: it did
            // exactly what it was built to do at that point. Printed as a warning rather than as
            // an error for that reason, and the container's failure is `/health/db`'s to report.
            //
            // A repair is printed the same way and for a different reason: the run exited 0
            // because it did its job, but it did it because a running pooler was not answering
            // for a pool — a fault worth a line an operator notices, not one that failed the run.
            FlipResult::KIND_SKIPPED,
            FlipResult::KIND_REPAIRED,
            FlipResult::KIND_WINDOW_CLOSED => $skipped(),
            // KIND_FAILED, plus anything a newer version might write.
            default => $failed(),
        };
    }

    /**
     * A request this process refused before it asked the flipper anything — a flag
     * combination, or a container with no flipper in it.
     *
     * `--json` gets the same treatment as the flags that do reach the flipper: a refusal is a
     * verdict like any other, and a pipeline that has to tell "the run never started" from "the
     * run started and failed" needs the sentence in the object it parses rather than on stderr.
     * There is nothing else on stdout in that mode, so a job can read the report and the exit
     * code and know both.
     */
    private function refuse(string $reason, string $kind = self::KIND_REFUSED): int
    {
        if ($this->option('json')) {
            return $this->report(['kind' => $kind, 'reason' => $reason], self::FAILURE);
        }

        $this->error($reason);

        return self::FAILURE;
    }

    /**
     * Flipping is switched off, or cannot act on this driver. Not a failure — nothing was
     * asked of a flipper that has already said it will not act — so the run exits `0` with the
     * reason, which under `--json` is the `disabled` verdict a scheduler can skip on.
     */
    private function disabled(string $reason): int
    {
        if ($this->option('json')) {
            return $this->report(['kind' => self::KIND_DISABLED, 'reason' => $reason], self::SUCCESS);
        }

        $this->warn("[pgcat-flip] {$reason}");

        return self::SUCCESS;
    }

    /**
     * The run as one JSON object on stdout, and nothing else — written by `JsonEnvelope`, which is
     * the same five keys in the same positions that `db:probe-replicas --json` and
     * `db:replica-status --json` write, and the command's own evidence after them.
     *
     * The key set is fixed and always present, `null` or empty where a route has nothing for a
     * key, so a job can write `jq -e '.kind == "would_flip"'` without first asking whether the
     * field exists — the same rule the health payload's `counts` follows. `exit_code` travels
     * inside the report because it is the other half of the answer: the number a scheduler
     * branches on, in the same object as the sentence explaining it, so a run cannot be read one
     * way and acted on another.
     *
     * `kind` is the verdict, from one vocabulary: a flip's (`flipped`, `no_change`, `skipped`,
     * `failed`), a rehearsal's (`would_flip`, `would_not_flip`, `skipped`, `failed`), or one this
     * command answers without asking the flipper (`status`, `disabled`, `refused`, `unbound`).
     *
     * @param array<string, mixed> $payload the route's own evidence, `kind` among it
     */
    private function report(array $payload, int $exitCode): int
    {
        return JsonEnvelope::write($this->output, 'db:pgcat-flip', self::EVIDENCE, $payload, $exitCode);
    }
}
