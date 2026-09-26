<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console\Commands;

use Uak35\WeightedDbManager\Pgcat\DryRunResult;
use Uak35\WeightedDbManager\Pgcat\FlipResult;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * php artisan db:pgcat-flip [options]
 *
 * Routes:
 *   db:pgcat-flip                       — one-shot, swap if mode changed
 *   db:pgcat-flip --watch --interval=N  — daemon mode (loops every N seconds, default 30)
 *   db:pgcat-flip --status              — show current state without flipping
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

    protected $signature = 'db:pgcat-flip
                            {--watch : Run continuously, polling every --interval seconds}
                            {--interval=30 : Polling interval in seconds when --watch is set}
                            {--force-mode= : Force a specific mode (readers|writer) instead of consulting the resolver}
                            {--status : Show current state without flipping}
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
            return $this->renderResult($flipper->forceMode($forced));
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

    private function renderStatus(PgcatConfigFlipper $flipper): int
    {
        $s = $flipper->status();

        if ($this->option('json')) {
            // The status array as the flipper computed it, with none of the table's
            // substitutions: `(not set)` is a way of printing an absent path, and a machine
            // wants the absent path. The one thing added is the verdict, which is what every
            // report in this mode leads with.
            return $this->report(['kind' => self::KIND_STATUS, 'status' => $s], self::SUCCESS);
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
            ],
        );

        return self::SUCCESS;
    }

    private function match(string $kind, \Closure $noChange, \Closure $flipped, \Closure $skipped, \Closure $failed): void
    {
        match ($kind) {
            FlipResult::KIND_NO_CHANGE => $noChange(),
            FlipResult::KIND_FLIPPED => $flipped(),
            FlipResult::KIND_SKIPPED => $skipped(),
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
     * The run as one JSON object on stdout, and nothing else — written raw, so a detail that
     * happens to contain angle brackets is not read as a console tag and dropped on the way
     * out of a machine-readable channel.
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
        $this->output->writeln(
            (string) json_encode(
                [
                    'command' => 'db:pgcat-flip',
                    'kind' => $payload['kind'] ?? null,
                    'exit_code' => $exitCode,
                    'mode' => $payload['mode'] ?? null,
                    'previous_mode' => $payload['previous_mode'] ?? null,
                    'reason' => $payload['reason'] ?? null,
                    'error' => $payload['error'] ?? null,
                    'steps' => $payload['steps'] ?? [],
                    'status' => $payload['status'] ?? null,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            OutputInterface::OUTPUT_RAW,
        );

        return $exitCode;
    }
}
