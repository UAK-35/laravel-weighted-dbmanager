<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

/**
 * When the audit record's probe stamp has gone too long without a write, and when that is nobody's
 * fault.
 *
 * WHY THIS IS AN OBJECT RATHER THAN A FEW LINES IN THE TWO READERS
 * ---------------------------------------------------------------
 *   `store_probed_at` is the one field in the record that a healthy installation moves on its own:
 *   a boot that finds the probe due sends it and writes the time. Every other field describes a
 *   configuration, and a configuration that stays still is usually a configuration that is fine —
 *   so the silence of the file is the failure, and the age of the stamp is the only fact that can
 *   report it. `db:doctor`'s `probe stamp` row asked the question first, from a terminal;
 *   `/health/db` publishes the same age as `audit.store_probe`, so a monitor sees an audit that
 *   stopped being written without running the command.
 *
 *   Two surfaces reading one rule is why the rule is here rather than copied into each of them. A
 *   second copy is not a duplicate until the first one is edited: a tolerance widened in the row
 *   and not in the payload is a monitor that pages for an installation the command calls well, and
 *   the pair disagreeing is exactly what neither surface can see.
 *
 * THE TOLERANCE IS THE CADENCE'S
 *   The stamp moves when the probe is due and something boots the installation, so the two settings
 *   that decide it are the probe's interval (`swrr.audit.store_probe_seconds`) and the schedule's
 *   cadence (`swrr.pgcat.schedule.interval_minutes`) — and the wider of the two is what the age is
 *   measured against, `MISSED_CADENCES` times over. At the shipped values that is five minutes; on
 *   an installation that probes hourly it is five hours, which is the point: the window is what
 *   this installation says it will do, not a number picked here. A fixed number of seconds would
 *   have to be raised by hand every time either setting was, and the value that was forgotten
 *   would page somebody about an audit that was doing exactly what it was configured to do.
 *
 *   The count is five, the one `swrr.pgcat.schedule.stale_after_missed_runs` ships for the
 *   schedule's own beat, because it is the same question one file over: a beat that has not moved
 *   for five of its runs is a schedule that stopped, and a stamp that has not moved for five of the
 *   runs that should write it is an audit that stopped being written. Four would leave no room for
 *   the ordinary casualties — a run that lost its lock, a container busy for an interval, a clock
 *   adjusted between two runs — and ten would let a record sit unwritten while every surface that
 *   reads it describes an installation that no longer exists.
 *
 * WHICH STATES REMOVE THE EXPECTATION
 *   An age is a fault only where something was expected to move it, so each state that removes the
 *   expectation is named rather than counted as a healthy record:
 *
 *   - {@see self::OFF} — probing is switched off, so no stamp is ever written.
 *   - {@see self::UNWRITABLE} — the record cannot be written, so its stamp is frozen rather than
 *     late; the record is also the throttle, and the row that fails on that is `store probe`'s.
 *   - {@see self::IN_PROCESS} — the store is in-process, so nothing probes the store a stamp would
 *     date.
 *   - {@see self::UNARMED} — the flip schedule is not armed, so no run is guaranteed to boot this
 *     installation; a web installation with no schedule is written when it serves a request, which
 *     is the traffic's pace rather than a fault.
 *   - {@see self::NO_STAMP} — the record holds no stamp at all, which is a fresh installation
 *     rather than a stale one.
 *
 *   None of the five is a finding, and none is dated: the state reported is the *absence* of the
 *   writes that would carry a date. The codes are what this class answers; the sentence each
 *   surface prints for one is that surface's, because the row names the rows beside it (`store
 *   probe` fails on a frozen record) and a payload must not send a reader to a command.
 */
final class ProbeStamp
{
    /** How many cadences may go by without the record being written before the stamp is stale. */
    public const MISSED_CADENCES = 5;

    /** Probing is switched off (`swrr.audit.store_probe_seconds` is 0), so nothing writes a stamp. */
    public const OFF = 'off';

    /** The record cannot be written, so its stamp is frozen rather than late. */
    public const UNWRITABLE = 'unwritable';

    /** The primary store is in-process, so nothing probes the store the stamp would date. */
    public const IN_PROCESS = 'in_process';

    /** The flip schedule is not armed, so no run is guaranteed to boot the installation. */
    public const UNARMED = 'unarmed';

    /** The record holds no stamp — nothing has been probed yet. */
    public const NO_STAMP = 'no_stamp';

    /**
     * The container recorded no boot time of its own.
     *
     * Deliberately a state of its own rather than {@see self::NO_STAMP}, which is about the *record*
     * carrying no probe stamp: this one is about the container, and it is the life the age would be
     * placed against. Without it an old record and a container thirty seconds past boot are the same
     * two numbers, so a state directory persisted into an image — the shape a deploy starts from —
     * would read as a stopped schedule on the first request after every rollout.
     *
     * It is the excuse the beat already takes for the same reason: `ScheduleBeat` refuses to judge a
     * beat without a stamp, because the stamp is what separates "not started yet" from "stopped". A
     * probe that went on judging the record's age in that state would page for what its sibling
     * says it cannot know.
     */
    public const NO_BOOT_STAMP = 'no_boot_stamp';

    /**
     * How long the stamp may stand still: the wider of the probe interval and the schedule's
     * cadence, `MISSED_CADENCES` times over.
     *
     * The wider rather than the sum, because the two are not independent: the stamp moves at the
     * first boot after the probe has been due, and that boot is one cadence apart from the last.
     * What bounds the wait is whichever interval is longer — a probe due every hour on a
     * one-minute schedule waits an hour, and a one-minute probe on a schedule that runs once an
     * hour waits an hour too.
     */
    public static function staleAfterSeconds(int $probeSeconds, int $intervalMinutes): int
    {
        return self::MISSED_CADENCES * max($intervalMinutes * 60, $probeSeconds);
    }

    /**
     * The stamp, judged where it can be and named where it cannot.
     *
     * The tolerance is answered in every state, not only the judged ones: it is what the
     * installation was asked to tolerate, and a monitor plotting an age against a number that
     * vanishes the moment the probe is switched off would have to remember the last value it saw.
     * `stale` is the other half of that: `null` where nothing was judged, because *nothing asked
     * it* is not the same claim as *it reads well* — the distinction `audit.current` already draws
     * for a finding this process did not evaluate.
     *
     * @param array{probed_at: int|null, age_seconds: int|null} $stamp `BootAudit::storeProbeStamp()`
     * @param array{seconds: int, enabled: bool, writable: bool, file: string} $status `BootAudit::storeProbeStatus()`
     * @param bool $storeIsRedis whether `swrr.primary_store` resolves to a store something probes
     * @param array{enabled: bool, interval_minutes: int} $entry `FlipSchedule::options()`
     * @return array{
     *     judged: bool,
     *     not_judged: string|null,
     *     probed_at: int|null,
     *     age_seconds: int|null,
     *     stale: bool|null,
     *     stale_after_seconds: int,
     *     missed_cadences: int,
     *     probe_seconds: int,
     *     interval_minutes: int
     * }
     */
    public static function read(array $stamp, array $status, bool $storeIsRedis, array $entry, bool $containerStamped = true): array
    {
        $probedAt = $stamp['probed_at'] ?? null;
        $age = $stamp['age_seconds'] ?? null;
        $staleAfter = self::staleAfterSeconds($status['seconds'], $entry['interval_minutes']);

        $notJudged = match (true) {
            !$status['enabled'] => self::OFF,
            !$status['writable'] => self::UNWRITABLE,
            !$storeIsRedis => self::IN_PROCESS,
            !$entry['enabled'] => self::UNARMED,
            // Before the container's own state, and this order is the reading: a record holding no
            // stamp at all is a fresh installation whatever the container has done, and that is a
            // stronger statement than "nobody measured the container's life". A record that *does*
            // carry a stamp in a container that never recorded a boot is the state below.
            $probedAt === null || $age === null => self::NO_STAMP,
            !$containerStamped => self::NO_BOOT_STAMP,
            default => null,
        };

        return [
            'judged' => $notJudged === null,
            'not_judged' => $notJudged,
            'probed_at' => $probedAt,
            'age_seconds' => $age,
            'stale' => $notJudged === null ? $age > $staleAfter : null,
            'stale_after_seconds' => $staleAfter,
            'missed_cadences' => self::MISSED_CADENCES,
            'probe_seconds' => $status['seconds'],
            'interval_minutes' => $entry['interval_minutes'],
        ];
    }
}
