<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Decides whether read queries should go to the multi-replica pool
 * or be forced onto the writer.
 *
 * In real production read-replica setups the writer is often "promoted"
 * to serve reads during periods when the replicas are too far behind to
 * be safe to read from — typical examples:
 *
 *   • Nightly ETL windows (replica replication lag spikes)
 *   • Heavy batch jobs running on the writer
 *   • Weekend maintenance periods (Sat/Sun) when replicas may be
 *     rotated, re-seeded, or paused
 *
 * Configuration:
 *
 *   'swrr' => [
 *       ...
 *       'reader_windows' => [           // windows where readers ARE used
 *           ['start' => '10:00:00', 'end' => '14:20:00'],
 *           ['start' => '17:00:00', 'end' => '20:30:00'],
 *       ],
 *       'reader_days'    => [1, 2, 3, 4, 5],   // ISO: 1=Mon … 7=Sun
 *       'timezone'       => 'UTC',
 *   ],
 *
 * Outside any reader_window on a reader_day, isReaderWindow() returns
 * false → the manager short-circuits and serves reads from the writer.
 * On non-reader days (default Sat=6 and Sun=7) it returns false all day.
 *
 * If `reader_windows` is missing or empty, the resolver is permissive:
 * isReaderWindow() always returns true. This is backward-compatible
 * with installations that don't want any fallback — just don't set
 * `reader_windows` and the manager's existing SWRR-only behaviour applies.
 *
 * Boundary semantics
 * ------------------
 *   • Window start is INCLUSIVE (`$time >= $start`)
 *   • Window end   is EXCLUSIVE (`$time <  $end`)
 *   → No gap, no overlap at window edges. Multi-window days like
 *     10:00-14:20 + 17:00-20:30 leave 14:20-17:00 (and any time
 *     outside both windows) on the writer.
 */
final class TimeWindowResolver
{
    /**
     * @param list<array{start?: string, end?: string}> $readerWindows Missing keys fall
     *        back to a whole-day window (00:00:00 … 23:59:59).
     * @param list<int> $readerDays ISO-8601 day numbers (1=Mon … 7=Sun)
     */
    public function __construct(
        private readonly array $readerWindows = [],
        private readonly array $readerDays = [1, 2, 3, 4, 5],
        private readonly string|DateTimeZone $timezone = 'UTC',
    ) {
    }

    /**
     * True if reads should go to the SWRR pool right now.
     * False if reads should be forced onto the writer.
     *
     * @param DateTimeInterface|int|string|null $at Optional: a DateTimeInterface, Unix timestamp, or string parseable by DateTimeImmutable. Defaults to "now".
     */
    public function isReaderWindow(DateTimeInterface|int|string|null $at = null): bool
    {
        if ($this->isAlwaysReaderMode()) {
            return true;
        }

        $now = $this->resolveNow($at);
        $dow = (int) $now->format('N');      // 1=Mon … 7=Sun
        $hms = $now->format('H:i:s');

        if (!in_array($dow, $this->readerDays, true)) {
            return false;
        }

        foreach ($this->readerWindows as $win) {
            $start = $this->normaliseTime($win['start'] ?? '00:00:00');
            $end = $this->normaliseTime($win['end'] ?? '23:59:59');

            if ($hms >= $start && $hms < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Human-readable current mode for diagnostics.
     */
    public function currentModeName(DateTimeInterface|int|string|null $at = null): string
    {
        return $this->isReaderWindow($at) ? 'readers' : 'writer';
    }

    /**
     * When does the next mode transition happen?
     *
     * Returns a DateTimeImmutable for the moment the mode flips, or null
     * if the resolver is in always-reader mode (no transitions to find).
     */
    public function nextTransitionAt(DateTimeInterface|int|string|null $at = null): ?DateTimeImmutable
    {
        if ($this->isAlwaysReaderMode()) {
            return null;
        }

        $tz = $this->timezone();
        $now = $this->resolveNow($at)->setTimezone($tz);

        // Search up to 7 days forward (covers a full week for weekday windows).
        for ($offsetDays = 0; $offsetDays < 8; $offsetDays++) {
            $candidate = $now->modify("+{$offsetDays} days")->setTime(0, 0, 0);
            $dow = (int) $candidate->format('N');

            if (!in_array($dow, $this->readerDays, true)) {
                continue;   // skip non-reader days
            }

            foreach ($this->readerWindows as $win) {
                $start = $this->normaliseTime($win['start'] ?? '00:00:00');
                $end = $this->normaliseTime($win['end'] ?? '23:59:59');

                $startAt = $candidate->modify($start);
                $endAt = $candidate->modify($end);

                // Only future transitions on/after "now" qualify.
                if ($startAt > $now && !$this->isReaderWindow($startAt)) {
                    continue;   // window would already be in writer mode — skip
                }

                if ($startAt > $now) {
                    return $startAt;   // entering reader window
                }

                if ($endAt > $now) {
                    return $endAt;     // leaving reader window
                }
            }
        }

        return null;
    }

    /**
     * The configured windows that can never be entered, as 0-based indices.
     *
     * A window is entered when `start <= now < end`, so one whose start is not
     * before its end is never entered — and a start or end the parser cannot
     * understand normalises to midnight, which can leave a window zero-width. A
     * window missing a start or an end is not reported: the resolver reads those as a
     * whole-day window (00:00:00 … 23:59:59), which can be entered like any other.
     *
     * The rules are the ones isReaderWindow() applies, read from one place, so a
     * caller reporting an unreachable window and the match itself cannot drift.
     *
     * @return list<int>
     */
    public function unreachableWindows(): array
    {
        $unreachable = [];

        foreach ($this->readerWindows as $index => $window) {
            $start = $this->normaliseTime($window['start'] ?? '00:00:00');
            $end = $this->normaliseTime($window['end'] ?? '23:59:59');

            if ($start >= $end) {
                $unreachable[] = $index;
            }
        }

        return $unreachable;
    }

    public function timezone(): DateTimeZone
    {
        return $this->timezone instanceof DateTimeZone
            ? $this->timezone
            : new DateTimeZone($this->timezone);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * True when neither list can decide anything, so every read uses the pool.
     *
     * Public because it is the one place the rule lives: `db:doctor` asks it rather
     * than re-deriving "an empty list means always readers" from the configuration,
     * so a row can never report a fallback that is applying while this says otherwise.
     */
    public function isAlwaysReaderMode(): bool
    {
        return empty($this->readerWindows) || empty($this->readerDays);
    }

    private function resolveNow(DateTimeInterface|int|string|null $at): DateTimeImmutable
    {
        $tz = $this->timezone();

        if ($at === null) {
            return new DateTimeImmutable('now', $tz);
        }
        if ($at instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($at)->setTimezone($tz);
        }
        if (is_int($at)) {
            return new DateTimeImmutable('@' . $at)->setTimezone($tz);
        }

        // String — accept "now", "2026-09-14 12:00:00", ISO 8601, etc.
        return new DateTimeImmutable($at, $tz);
    }

    /**
     * Accept HH:MM or HH:MM:SS and normalise to HH:MM:SS for lexical compare.
     */
    private function normaliseTime(string $t): string
    {
        // Accept "HH:MM" → "HH:MM:00"
        if (preg_match('/^\d{2}:\d{2}$/', $t)) {
            return $t . ':00';
        }
        // Already "HH:MM:SS"
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) {
            return $t;
        }

        // Defensive default.
        return '00:00:00';
    }
}
