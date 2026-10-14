<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

use Uak35\WeightedDbManager\Support\ConfigValue;

/**
 * The beat the per-minute entry leaves behind, and when its silence becomes a fault.
 *
 * WHY THIS NEEDED DECIDING AT ALL
 *   Every other fact this package reports is about a *setting* — a switch written as something that
 *   is not on or off, a path a flip cannot write, a store that does not answer. Those are read when
 *   something asks. A schedule is different: the failure is that something *stops happening*, and
 *   the surface that could report it is the one the schedule was running. A container whose cron
 *   line dies keeps serving, keeps answering `select 1`, and keeps reporting the last mode a flip
 *   applied; nothing is malformed, nothing refuses, and every reading is of a world that stopped
 *   moving. That is how one of these sat unnoticed for 85 minutes.
 *
 *   The package had a partial answer already — the boot window — and it is worth saying exactly
 *   where that answer ends, because the beat exists for the rest. `FlipWindow` judges whether a
 *   *booting* container converged: it closes eight minutes after boot, and the convergence it
 *   records is written by the runs that happen inside it. After that the per-minute entry is a
 *   deliberate no-op that records nothing at all, so the state file goes on describing the first
 *   eight minutes of a container's life for as long as it lives. A schedule that ran then and was
 *   killed an hour later leaves a state file indistinguishable from a healthy one.
 *
 * WHAT THE BEAT IS, AND WHAT IT IS NOT
 *   It is the one fact a run writes whatever it decides: `beat_at`, stamped by
 *   `PgcatConfigFlipper::applyCurrentState()` before the window, the lock or the resolver are
 *   consulted. It is not the convergence record — `runs`, `last_run_at` and `kind` stay what they
 *   were, because those are the window's evidence and a run after the window has closed must not be
 *   able to change the answer it gave.
 *
 *   It is also not a liveness check of Laravel's scheduler in general, and the difference matters
 *   when this class is read by an operator. What is watched is the entry *this package* registers:
 *   if that entry is not scheduled, nothing here is judged, and an application's own tasks are its
 *   own to watch. Which is also why the beat is written by the entry rather than by a task of its
 *   own — a package that registered a second per-minute task to see whether the first one ran would
 *   be watching its own watchdog.
 *
 * THE THRESHOLD IS THE CADENCE, NOT A SECOND NUMBER
 *   `swrr.pgcat.schedule.stale_after_missed_runs` counts *missed runs* (five by default) and the
 *   bound is that many cadences: five minutes at the shipped one-minute interval, twenty-five at
 *   five. An installation that slows the entry down therefore slows the watch down with it and
 *   cannot leave the two disagreeing — which is the failure this shape is chosen to remove, because
 *   a fixed number of seconds would have to be raised by hand every time the interval was, and the
 *   value that was forgotten would page somebody at 3 a.m. about a schedule that was doing exactly
 *   what it was configured to do.
 *
 *   `0` (or a negative, or a non-number that is not the default either way) switches the judgement
 *   off, the way `swrr.audit.store_probe_seconds = 0` switches the store probe off. That is a
 *   documented choice rather than a disabled check nobody can see: `db:doctor` fails the row for it
 *   and `--strict` fails a release on it.
 *
 * WHERE IT IS JUDGED, AND WHERE IT IS NOT
 *   Only where the answer means something, and each of those silences is reported rather than
 *   implied, because a verdict of "fine" and a verdict of "not asked" must never look the same:
 *
 *   - **The entry is not scheduled.** A flipper that is not armed has nothing for a schedule to
 *     keep in step, and an installation whose entry is off has said so. Nothing ages a beat that
 *     nothing writes.
 *   - **The judgement is switched off** by the setting above.
 *   - **This container is still inside its boot window.** A container one minute past boot whose
 *     flip has not run yet is a container starting, which is the line `FlipWindow` already draws
 *     and this class reuses rather than re-argues.
 *   - **No boot time was recorded** — a local run, or an image whose entrypoint predates the
 *     stamp. The window is not judged there, and neither is the beat: inventing a deadline for a
 *     container nobody stamped is how a health endpoint starts failing healthy installations.
 *
 *   Everywhere else the question is answered, and it is answered the same way whichever surface
 *   asks: `PgcatConfigFlipper::scheduleStatus()`, `/health/db`, `db:doctor` and
 *   `db:scheduler-watchdog` all read this one verdict rather than measuring the age themselves.
 *
 * A BEAT THAT WAS NEVER WRITTEN IS STALE
 *   A container that has been past its window and has no beat is not "unknown": the entry is
 *   scheduled, the window has closed, and nothing has run it — which is the same fault as a beat
 *   that stopped, one interval earlier. Reporting it as stale is what makes the incident's shape
 *   (a schedule that never started) and its neighbour (a schedule that stopped) one verdict with
 *   two sentences rather than one verdict and one silence.
 *
 * KNOWN LIMITATIONS
 *   - **A hand run stamps it.** `php artisan db:pgcat-flip` reaches the same method the entry does,
 *     so an operator repairing the pooler by hand keeps the beat alive while the schedule stays
 *     dead. It is not worth a second marker to tell them apart — the operator is *there*, reading
 *     the output — and the alternative (stamping only under `schedule:run`) would make the beat
 *     depend on a Laravel internal rather than on the command having run.
 *   - **A run the flipper is told to make does not stamp it.** `--force-mode`, which the
 *     reader-window tasks use, is a request from outside the schedule rather than the entry firing.
 *   - **One beat can be lost to a concurrent write.** The stamp is written without the flipper's
 *     lock — a run that has not yet decided anything is not something to serialise — so a flip
 *     writing its own state in the same instant can replace it from a copy read a moment earlier.
 *     The cost is one interval of age, which the five-run tolerance absorbs.
 *   - **The clock is the container's.** A beat stamped by a container whose clock is wrong is
 *     judged against this one's; nothing here can tell a stopped schedule from a moved clock, which
 *     is the same thing every interval in this package already assumes.
 */
final class ScheduleBeat
{
    /**
     * The state key every run of the entry stamps.
     *
     * In the flip's own state file, beside the convergence record it deliberately does not touch:
     * one file per container, which is the scope of the question — two containers of one
     * installation have two schedules, two crons and two beats.
     */
    public const STATE_KEY = 'beat_at';

    /**
     * Five missed runs.
     *
     * Four would leave no room for the ordinary casualties — a run that loses the cache mutex to
     * the previous one, a container busy for a minute, a clock adjusted between two runs — and
     * would turn a slow minute into a page. Ten would let a stopped schedule sit for ten minutes
     * while the pooler it maintains is wrong, and the failure this exists for was noticed at 85.
     * Five is also where the cadence and the count stop being confusable in prose: "five missed
     * runs" is a sentence an operator can check against `schedule:list` without arithmetic.
     */
    public const DEFAULT_MISSED_RUNS = 5;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param bool       $scheduled       whether the per-minute entry is registered in this installation
     * @param int        $intervalMinutes the cadence it runs at
     * @param int        $missedRuns      how many of those runs may go missing; 0 (or less) never judges
     * @param FlipWindow $window          the container's boot window, which is where judging begins
     * @param \Closure|null $clock        returns the current unix time in seconds, fractional allowed
     */
    public function __construct(
        private readonly bool $scheduled,
        private readonly int $intervalMinutes,
        private readonly int $missedRuns,
        private readonly FlipWindow $window,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * The beat as the same config slice the flipper holds describes it.
     *
     * @param array<string, mixed> $config the `db-manager.swrr.pgcat` block
     */
    public static function fromConfig(array $config, ?FlipWindow $window = null, ?\Closure $clock = null): self
    {
        $entry = FlipSchedule::settings(
            ConfigValue::assoc($config['schedule'] ?? null),
            $config['enabled'] ?? null,
        );

        return new self(
            scheduled: $entry['enabled'],
            intervalMinutes: $entry['interval_minutes'],
            missedRuns: self::missedRuns($config['schedule'] ?? null),
            window: $window ?? FlipWindow::fromConfig($config, $clock),
            clock: $clock,
        );
    }

    /**
     * How many missed runs the installation tolerates, or the default for a value this cannot read.
     *
     * Read as a number rather than as a switch: the setting is a count, and every spelling of the
     * switch vocabulary (`'off'`, `'no'`, `false`) is a value this cannot read and therefore the
     * default, not an accidental zero. A zero written down *is* zero, and means what the class
     * docblock says it means.
     */
    public static function missedRuns(mixed $schedule): int
    {
        $block = ConfigValue::assoc($schedule);

        return ConfigValue::int($block['stale_after_missed_runs'] ?? null, self::DEFAULT_MISSED_RUNS);
    }

    /**
     * How long a beat may go unmoved before it counts as stopped, in seconds — 0 when switched off.
     *
     * Exposed rather than private because two surfaces print it: the doctor's row says what the
     * bound is in the sentence that names the age, and a reader comparing the two numbers should be
     * able to check them against the setting rather than against this class.
     */
    public function staleAfterSeconds(): int
    {
        return $this->missedRuns <= 0 ? 0 : $this->missedRuns * $this->intervalMinutes * 60;
    }

    /** Whether the judgement is switched off by configuration rather than unanswerable. */
    public function off(): bool
    {
        return $this->missedRuns <= 0;
    }

    /**
     * The schedule as a verdict, from the state file as the flipper read it.
     *
     * `stale` answers the question — `true` for a schedule that has stopped, `false` for one that is
     * producing runs — and is `null` exactly when nothing was judged at all, which is the one state
     * an exit code cannot carry: a consumer that read a `null` as "fine" would be reading "not
     * asked" as the answer, so `not_judged` carries the sentence for it and `applicable` says
     * whether a verdict was reached. Two fields for one absence, because a healthcheck branches on
     * the code and an operator reads the sentence, and the two must not be able to disagree.
     *
     * @param array<string, mixed> $state the flip state file, as `readState()` returned it
     *
     * @return array{
     *   applicable: bool,
     *   stale: bool|null,
     *   not_judged: string|null,
     *   last_beat_at: string|null,
     *   age_seconds: int|null,
     *   stale_after_seconds: int,
     *   reason: string|null,
     * }
     */
    public function verdict(array $state): array
    {
        $beat = $state[self::STATE_KEY] ?? null;
        $beatAt = is_string($beat) && $beat !== '' ? $beat : null;
        $age = $beatAt === null ? null : $this->ageOf($beatAt);

        // A stamp that cannot be read is no stamp: an operator editing the state file by hand, or a
        // value written by a version that spelled it another way. Reporting the string beside an
        // age of nothing would be a payload that contradicts itself.
        if ($age === null) {
            $beatAt = null;
        }

        $verdict = [
            'applicable' => $this->scheduled && !$this->off(),
            'stale' => null,
            'not_judged' => null,
            'last_beat_at' => $beatAt,
            'age_seconds' => $age,
            'stale_after_seconds' => $this->staleAfterSeconds(),
            'reason' => null,
        ];

        if (!$this->scheduled) {
            return [...$verdict, 'not_judged' => sprintf(
                'the per-minute entry is not scheduled (swrr.pgcat.schedule.enabled is off), so nothing '
                .'stamps a beat and there is nothing for one to be late',
            )];
        }

        if ($this->off()) {
            return [...$verdict, 'not_judged' => sprintf(
                'the watchdog is switched off (swrr.pgcat.schedule.stale_after_missed_runs is %d), so '
                .'nothing ages the beat',
                $this->missedRuns,
            )];
        }

        if (!$this->window->closed()) {
            return [...$verdict, 'not_judged' => $this->window->toArray()['source'] === FlipWindow::SOURCE_NO_STAMP
                ? 'this container recorded no boot time (swrr.pgcat.boot_file is not there), so the boot '
                    .'window is not judged and neither is the beat'
                : 'this container is still inside its boot window, where a flip that has not run yet is a '
                    .'container starting rather than a schedule that has stopped'];
        }

        if ($age === null) {
            return [...$verdict, 'stale' => true, 'reason' => sprintf(
                'no run of %s has been recorded in this container, and the entry is scheduled every %s: '
                .'the scheduler is not running it — check the container\'s cron, or schedule:work',
                FlipSchedule::COMMAND,
                $this->cadence(),
            )];
        }

        // Answered, and the answer is no: a run inside the tolerance is a schedule that is working,
        // which is a different verdict from the three silences above and has to be a different value
        // in the one field a consumer reads.
        if ($age < (int) $verdict['stale_after_seconds']) {
            return [...$verdict, 'stale' => false];
        }

        return [...$verdict, 'stale' => true, 'reason' => sprintf(
            'the scheduler has not run %s for %s: the entry is scheduled every %s, so %d run(s) have been '
            .'missed — check that the container\'s cron, or schedule:work, is still running it',
            FlipSchedule::COMMAND,
            self::ageText($age),
            $this->cadence(),
            $this->missedRuns,
        )];
    }

    /**
     * The cadence as prose, for the two sentences that name it.
     *
     * The noun is singular at one minute because that is the shipped value and the sentence an
     * operator reads first; every other cadence takes the plural, which is also what the setting's
     * own documentation does.
     */
    private function cadence(): string
    {
        return $this->intervalMinutes === 1 ? 'minute' : $this->intervalMinutes . ' minutes';
    }

    /**
     * An age in seconds as the unit a person reads it in: minutes under an hour, then hours, then
     * days — the same progression the reader-window and boot-window sentences use, so an
     * installation whose schedule died over a weekend is not described as "4318 minutes".
     *
     * Public because a second surface prints it: `db:doctor`'s `schedule beat` row names the age of
     * a beat that is *fine* as well as one that is not, and a row that formatted it its own way
     * would describe the same state in two units — which is how an operator reading a healthy row
     * and a failing one concludes they are looking at different things.
     */
    public static function ageText(int $seconds): string
    {
        if ($seconds < 3600) {
            return max(1, intdiv($seconds, 60)) . ' minute(s)';
        }

        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' hour(s)';
        }

        return intdiv($seconds, 86400) . ' day(s)';
    }

    /**
     * How old a stamp is, in whole seconds, never negative.
     *
     * A stamp in the future is a clock that moved rather than a beat that is early, and the honest
     * reading of it is "no age at all": a negative age would make every comparison below pass, which
     * would report a schedule as fresh because its container's clock was readjusted.
     */
    private function ageOf(string $stamp): ?int
    {
        $at = strtotime($stamp);

        if ($at === false) {
            return null;
        }

        return (int) max(0, ($this->clock)() - (float) $at);
    }
}
