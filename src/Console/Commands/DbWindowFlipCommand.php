<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Database\Weighted\ReadinessProbe;
use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Pgcat\FlipResult;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\WindowFlipSchedule;
use Uak35\WeightedDbManager\Support\ActiveConnection;
use Uak35\WeightedDbManager\Support\ConfigValue;

/**
 * php artisan db:pgcat-window-flip --mode=readers|writer --at=HH:MM[:SS]
 *
 * One firing of a reader-window task: ask the mode's target whether it is there, and apply the mode
 * at the window boundary. Registered by {@see WindowFlipSchedule} as `activate_readers` and
 * `deactivate_readers`, once a minute across the lead time before each boundary — so this command
 * is normally run eight or nine times per boundary and acts on one of them.
 *
 * WHY THE TARGET IS ASKED BEFORE THE MODE IS APPLIED
 *   A mode change rewrites what pgcat points at. Pointing the pool at replicas that are gone turns
 *   every read into a failure, and the flip itself cannot tell: it swaps a file and signals
 *   supervisord, which is why the check is here rather than in the flipper. The question is asked
 *   of the target directly ({@see ReadinessProbe}) — one `SELECT 1` per candidate, on a throwaway
 *   connection — so it cannot be answered by the pooled connection the change is about to be
 *   applied to.
 *
 * WHY THE MODE IS APPLIED THROUGH THE FORCED PATH
 *   `applyCurrentState()` refuses to act once the container's boot window has closed, and it is
 *   right to: the window bounds *converging at boot*, and `/health/db` reads the same bound to
 *   decide whether this container failed to come up. A boundary in the middle of the day is not
 *   that, so it uses `forceMode()`, which has no window — and which records no run either, so a
 *   mode this task applies cannot move the convergence verdict a deployment gate reads. The two
 *   are deliberately separate paths, and this is the one for "the time of day says so".
 *
 * THE ROUTES, AND THE EXIT CODE EACH ONE CARRIES
 *   early       a firing outside this task's own lead window — the cron expression covers a whole
 *               hour's worth of minutes, so these exist; nothing was asked, exit 0.
 *   waiting     inside the lead window and before the boundary: the target was asked, nothing is
 *               applied yet, exit 0. This is the probing the task is for.
 *   flipped     the boundary had arrived and the target answered: the mode was applied, exit 0.
 *   no_change   the flipper already has that mode applied, so there was nothing to do, exit 0.
 *   deferred    the boundary had arrived and the target did not answer: nothing was applied, exit
 *               1. The task exists to change the mode and this run could not, which is the one
 *               outcome a scheduler should be able to see; the next firing asks again until the
 *               grace runs out.
 *   expired     past the boundary and the grace: the chance has gone, exit 0. Nothing was asked,
 *               and a run that is no longer allowed to act is not a failure of that run.
 *   not_my_day  the boundary resolved to a day that is not a reader day, exit 0.
 *   disabled    the window tasks are switched off, or pgcat is: nothing to do, exit 0.
 *   refused     `--mode` or `--at` is not a value this command has a meaning for, exit 1.
 *   unbound     the container has no weighted manager, or no flipper, so the gate cannot be asked,
 *               exit 1.
 *   failed      the flip itself threw, exit 1.
 *
 * JSON
 *   `--json` writes one object and nothing else — the envelope `db:pgcat-flip`, `db:probe-replicas`
 *   and `db:replica-status` write — carrying the probe's own rows, so a pipeline can assert on what
 *   was asked and what answered rather than parsing a sentence.
 */
class DbWindowFlipCommand extends Command
{
    /**
     * The verdicts this command answers itself. The rest are the flipper's own kinds, so a job
     * reading `kind` has one vocabulary rather than two nested questions.
     */
    public const KIND_EARLY = 'early';

    public const KIND_WAITING = 'waiting';

    public const KIND_DEFERRED = 'deferred';

    public const KIND_EXPIRED = 'expired';

    public const KIND_NOT_MY_DAY = 'not_my_day';

    public const KIND_DISABLED = 'disabled';

    public const KIND_REFUSED = 'refused';

    public const KIND_UNBOUND = 'unbound';

    /**
     * The route where there was nothing to do — reported as the flipper's own `no_change`, because
     * the verdict is the same one and a job that reads `kind` should not have to learn a second word
     * for it.
     */
    public const KIND_NO_CHANGE = FlipResult::KIND_NO_CHANGE;

    /**
     * The evidence every report carries, and the value an absent one takes — the envelope's rule,
     * declared once rather than assembled per route, so a job can read `.probe.answered` on every
     * verdict without asking whether the field is there.
     */
    private const EVIDENCE = [
        'mode' => null,
        'at' => null,
        'boundary' => null,
        'now' => null,
        'mode_before' => null,
        'target' => null,
        'probe' => null,
        'result' => null,
        'warning' => null,
    ];

    protected $signature = 'db:pgcat-window-flip
                            {--mode= : The mode this firing is for — readers or writer}
                            {--at= : The window boundary, as a time of day (HH:MM or HH:MM:SS) in swrr.timezone}
                            {--json : Print one JSON object — the verdict, the probe and the exit code — instead of the rendered line}';

    protected $description = 'Probe a reader-window boundary\'s target, then apply that mode';

    public function handle(): int
    {
        $mode = $this->option('mode');
        $at = $this->option('at');

        if (!is_string($mode) || !in_array($mode, [WindowFlipSchedule::MODE_READERS, WindowFlipSchedule::MODE_WRITER], true)) {
            return $this->refuse("--mode must be 'readers' or 'writer'");
        }

        if (!is_string($at) || !WindowFlipSchedule::isTime($at)) {
            return $this->refuse('--at must be a time of day (HH:MM or HH:MM:SS)');
        }

        $settings = WindowFlipSchedule::settings($this->config());

        if (!$settings['enabled']) {
            return $this->disabled(
                'The reader-window tasks are off (' . WindowFlipSchedule::CONFIG_KEY . '.enabled).',
                $mode,
                $at,
            );
        }

        // The docblock widens what the container returns, which is typed as the class it is asked
        // for: the binding is the application's to replace, and this is the guard that says so.
        /** @var PgcatConfigFlipper|null $flipper */
        $flipper = app(PgcatConfigFlipper::class);

        if (!$flipper instanceof PgcatConfigFlipper) {
            return $this->unbound('PgcatConfigFlipper is not registered. Check WeightedDatabaseServiceProvider.');
        }

        if ($reason = $flipper->disabledReason()) {
            return $this->disabled($reason, $mode, $at);
        }

        $now = new DateTimeImmutable('now', $settings['timezone']);
        $boundary = WindowFlipSchedule::boundary($at, $now, $settings['timezone']);
        $warning = $this->timezoneWarning($settings['timezone']->getName());

        if (!WindowFlipSchedule::onReaderDay($boundary, $settings['days'])) {
            return $this->report(self::KIND_NOT_MY_DAY, $mode, $at, $boundary, $now, null, null, null, $warning, self::SUCCESS);
        }

        $opens = $boundary->modify('-' . $settings['lead_seconds'] . ' seconds');
        $closes = $boundary->modify('+' . $settings['grace_seconds'] . ' seconds');

        if ($now < $opens) {
            return $this->report(self::KIND_EARLY, $mode, $at, $boundary, $now, null, null, null, $warning, self::SUCCESS);
        }

        if ($now > $closes) {
            return $this->report(self::KIND_EXPIRED, $mode, $at, $boundary, $now, null, null, null, $warning, self::SUCCESS);
        }

        $manager = $this->laravel->make('db');

        if (!$manager instanceof WeightedDatabaseManager) {
            return $this->unbound('WeightedDatabaseManager is not registered, so the mode\'s target cannot be asked.');
        }

        $applied = $this->appliedMode($flipper);

        // Already in the mode this firing is for: nothing to ask and nothing to apply. This is what
        // makes the grace harmless — every firing after a successful flip lands here.
        if (WindowFlipSchedule::alreadyIn($mode, $applied)) {
            return $this->report(self::KIND_NO_CHANGE, $mode, $at, $boundary, $now, $applied, null, null, $warning, self::SUCCESS);
        }


        $connection = ConfigValue::string(ActiveConnection::resolve($this->config())['connection'], 'pgsql');
        $probe = ReadinessProbe::forMode($mode, $manager, $connection);

        // Inside the lead window: the probe has run and been recorded — that is the probing this
        // task is scheduled for — and the mode is not applied until the boundary arrives.
        if ($now < $boundary) {
            return $this->report(self::KIND_WAITING, $mode, $at, $boundary, $now, $applied, $probe, null, $warning, self::SUCCESS);
        }

        if (!$probe['available']) {
            return $this->report(self::KIND_DEFERRED, $mode, $at, $boundary, $now, $applied, $probe, null, $warning, self::FAILURE);
        }

        try {
            $result = $flipper->forceMode($mode);
        } catch (Throwable $e) {
            return $this->report(FlipResult::KIND_FAILED, $mode, $at, $boundary, $now, $applied, $probe, null, $e->getMessage(), self::FAILURE);
        }

        return $this->report($result->kind(), $mode, $at, $boundary, $now, $applied, $probe, $result, $warning, $result->exitCode());
    }

    /**
     * The mode the flipper had when this run started, or null when nothing has ever applied one.
     *
     * Read from the flipper's own status rather than from the state file, so "what is applied" has
     * one answer in the package and this command cannot disagree with `--status` about it — and read
     * *before* the flip, because that is the fact the decision turns on: the mode the run is not
     * already in. What a flip then applied is `result.mode`, which is the flipper's own report of
     * what it did rather than this command's reading of what it found.
     */
    private function appliedMode(PgcatConfigFlipper $flipper): ?string
    {
        $status = $flipper->status();
        $applied = $status['last_mode'] ?? null;

        return is_string($applied) && $applied !== '' ? $applied : null;
    }

    /**
     * A line when the container's own clock and the configured zone disagree.
     *
     * The cron expression a task carries is evaluated in `swrr.timezone` while the *system* clock is
     * whatever the container was built with, and only one of those decides what "today" means for a
     * wall-clock boundary. They agree in a container that sets the zone it configures; when they do
     * not, the run still cannot apply a mode outside its own bounds — but it is worth saying, on the
     * run that noticed, rather than leaving an operator to work it out from a boundary that fires at
     * the wrong minute.
     */
    private function timezoneWarning(string $configured): ?string
    {
        $system = date_default_timezone_get();

        return $system === $configured ? null : sprintf(
            'the container clock is %s and swrr.timezone is %s, so the scheduled minute and the boundary minute are different clocks',
            $system,
            $configured,
        );
    }

    /**
     * Render (or report) one firing.
     *
     * Both channels come from the same arguments, which is the point of passing them rather than
     * formatting at each site: a line and an object that disagreed about the boundary would be two
     * answers to one question.
     *
     * @param array{target: string, available: bool, probed: int, answered: int, failed: int, hosts: list<array{host: string, port: int, healthy: bool, error: string|null}>, note: string|null}|null $probe
     */
    private function report(
        string $kind,
        string $mode,
        string $at,
        DateTimeImmutable $boundary,
        DateTimeImmutable $now,
        ?string $applied,
        ?array $probe,
        ?FlipResult $result,
        ?string $warning,
        int $exitCode,
    ): int {
        $reason = $this->reason($kind, $boundary, $now, $probe, $result, $applied);

        if ($this->option('json')) {
            return JsonEnvelope::write($this->output, 'db:pgcat-window-flip', self::EVIDENCE, [
                'kind' => $kind,
                'reason' => $reason,
                'mode' => $mode,
                'at' => $at,
                'boundary' => $boundary->format(DATE_ATOM),
                'now' => $now->format(DATE_ATOM),
                'mode_before' => $applied,
                'target' => $probe['target'] ?? ReadinessProbe::targetFor($mode),
                'probe' => $probe,
                'result' => $result?->toArray(),
                'warning' => $warning,
            ], $exitCode);
        }

        $this->line(
            sprintf(
                '[pgcat-window-flip] %s %s: %s',
                $mode,
                $kind,
                $reason,
            )
        );

        if ($warning !== null) {
            $this->warn('[pgcat-window-flip] ' . $warning);
        }

        $this->probeRows($probe);
        $this->resultLine($kind, $result, $exitCode);

        return $exitCode;
    }

    /**
     * The sentence the exit code follows from — the same rule the doctor's and the sweep's matrices
     * use: a verdict a reader cannot tell from another verdict is a verdict that will be read wrong.
     *
     * @param array{target: string, available: bool, probed: int, answered: int, failed: int, hosts: list<array{host: string, port: int, healthy: bool, error: string|null}>, note: string|null}|null $probe
     */
    private function reason(
        string $kind,
        DateTimeImmutable $boundary,
        DateTimeImmutable $now,
        ?array $probe,
        ?FlipResult $result,
        ?string $applied,
    ): string {
        $boundaryAt = $boundary->format(DATE_ATOM);
        $away = $boundary->getTimestamp() - $now->getTimestamp();

        return match ($kind) {
            // `$away` is positive here and negative below, which is the whole difference between the
            // two sentences: a firing before its boundary is a distance *until* the window opens, and
            // a firing after the grace is a distance *since* it closed. Printing one as the other
            // would be a negative number of seconds in a sentence, which no operator can act on.
            self::KIND_EARLY => sprintf(
                'the boundary is at %s, %ds away, which is before this task\'s lead window opens — nothing to do',
                $boundaryAt,
                $away,
            ),
            self::KIND_WAITING => sprintf(
                'probing: the boundary is at %s, %ds away, and %s',
                $boundaryAt,
                $away,
                $this->probeSentence($probe),
            ),
            // Two routes share this verdict and the sentences differ, so which one it is comes from
            // the evidence: the already-applied route carries no result, and the flipper's own
            // `no_change` — a driver pgcat cannot front — carries one.
            self::KIND_NO_CHANGE => $result === null
                ? sprintf(
                    'the flipper already has mode %s applied, so there is nothing to do for the %s boundary',
                    $applied ?? 'that',
                    $boundaryAt,
                )
                : $result->summary(),
            self::KIND_DEFERRED => sprintf(
                'the boundary at %s has arrived and %s — the mode was not applied',
                $boundaryAt,
                $this->probeSentence($probe),
            ),
            self::KIND_EXPIRED => sprintf(
                'the boundary at %s and its grace passed %ds ago — the chance to act is gone',
                $boundaryAt,
                -$away,
            ),
            self::KIND_NOT_MY_DAY => sprintf(
                'the boundary resolves to %s, which is not a reader day',
                $boundary->format('l'),
            ),
            FlipResult::KIND_FLIPPED => sprintf(
                'the boundary at %s arrived and %s — mode applied',
                $boundaryAt,
                $this->probeSentence($probe),
            ),
            FlipResult::KIND_FAILED => 'the flip threw' . ($result?->error !== null ? ': ' . $result->error : ''),
            default => $result?->summary() ?? sprintf('the flipper did not change the mode for the boundary at %s', $boundaryAt),
        };
    }

    /**
     * @param array{target: string, available: bool, probed: int, answered: int, failed: int, hosts: list<array{host: string, port: int, healthy: bool, error: string|null}>, note: string|null}|null $probe
     */
    private function probeSentence(?array $probe): string
    {
        if ($probe === null) {
            return 'the target was not asked';
        }

        if ($probe['note'] !== null) {
            return $probe['note'];
        }

        return sprintf(
            '%d of %d %s answered',
            $probe['answered'],
            $probe['probed'],
            $probe['target'],
        );
    }

    /**
     * The per-host rows, at `-v` only, and never in the JSON mode — the rows are that mode's
     * `probe.hosts`, and a line beside the object would make the whole of stdout two things.
     *
     * @param array{target: string, available: bool, probed: int, answered: int, failed: int, hosts: list<array{host: string, port: int, healthy: bool, error: string|null}>, note: string|null}|null $probe
     */
    private function probeRows(?array $probe): void
    {
        if ($probe === null || $this->option('json')) {
            return;
        }

        if ($this->getOutput()->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE) {
            return;
        }

        foreach ($probe['hosts'] as $row) {
            $this->line(
                sprintf(
                    '  %s %s:%d  %s',
                    $row['healthy'] ? '✓' : '✗',
                    $row['host'],
                    $row['port'],
                    $row['error'] ?? 'SELECT 1 answered',
                )
            );
        }
    }

    /**
     * The line the flipper's own result deserves, when there was one: a mode that was applied is
     * information, the flipper declining to act is a warning, and a failure is an error. Three
     * shades rather than one, because an operator reading an 8-minute window of these lines needs
     * to see the one that is not routine.
     */
    private function resultLine(string $kind, ?FlipResult $result, int $exitCode): void
    {
        if ($result === null) {
            return;
        }

        $summary = $result->summary();

        match (true) {
            $kind === FlipResult::KIND_FLIPPED => $this->info($summary),
            $exitCode === self::SUCCESS => $this->warn($summary),
            default => $this->error($summary),
        };
    }

    /**
     * A request refused before anything was asked of the flipper or the database — a flag that has
     * no meaning here. Refused in both channels: `--json` gets the sentence in the object, because
     * a pipeline has to tell "the run never started" from "the run started and failed".
     */
    private function refuse(string $reason): int
    {
        if ($this->option('json')) {
            return JsonEnvelope::write($this->output, 'db:pgcat-window-flip', self::EVIDENCE, [
                'kind' => self::KIND_REFUSED,
                'reason' => $reason,
                'mode' => $this->option('mode'),
                'at' => $this->option('at'),
            ], self::FAILURE);
        }

        $this->error('[pgcat-window-flip] ' . $reason);

        return self::FAILURE;
    }

    /**
     * Nothing for this run to do, and nothing wrong with it: the window tasks are switched off, or
     * pgcat is, or this driver cannot be fronted by pgcat. Exits 0 — a task that is off is not a
     * task that failed — and says which of those it was, because the repairs differ.
     */
    private function disabled(string $reason, string $mode, string $at): int
    {
        if ($this->option('json')) {
            return JsonEnvelope::write($this->output, 'db:pgcat-window-flip', self::EVIDENCE, [
                'kind' => self::KIND_DISABLED,
                'reason' => $reason,
                'mode' => $mode,
                'at' => $at,
            ], self::SUCCESS);
        }

        $this->warn('[pgcat-window-flip] ' . $reason);

        return self::SUCCESS;
    }

    /**
     * A dependency this command cannot work without. Exits 1: the run was asked to act and could
     * not even ask the question, which is not the same as being switched off.
     */
    private function unbound(string $reason): int
    {
        if ($this->option('json')) {
            return JsonEnvelope::write($this->output, 'db:pgcat-window-flip', self::EVIDENCE, [
                'kind' => self::KIND_UNBOUND,
                'reason' => $reason,
                'mode' => $this->option('mode'),
                'at' => $this->option('at'),
            ], self::FAILURE);
        }

        $this->error('[pgcat-window-flip] ' . $reason);

        return self::FAILURE;
    }

    /**
     * The configuration repository, through the contract the rest of the package reads it with.
     */
    private function config(): \Illuminate\Contracts\Config\Repository
    {
        $config = $this->laravel->make('config');

        if (!$config instanceof \Illuminate\Contracts\Config\Repository) {
            throw new InvalidArgumentException('The configuration repository is not bound.');
        }

        return $config;
    }
}
