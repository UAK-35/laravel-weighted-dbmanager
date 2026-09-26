<?php

namespace Uak35\WeightedDbManager\Tests\Unit\Weighted;

use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TimeWindowResolver tests — covers weekday windows, weekend writer-mode,
 * boundary semantics (start inclusive / end exclusive), multi-window days,
 * and the nextTransitionAt() helper used by diagnostics.
 *
 * Reference dates (UTC):
 *   2026-09-14  Mon   2026-09-17  Thu   2026-09-19  Sat
 *   2026-09-15  Tue   2026-09-18  Fri   2026-09-20  Sun
 *
 * Run: vendor/bin/phpunit tests/Unit/Weighted/TimeWindowResolverTest.php --testdox
 */
class TimeWindowResolverTest extends TestCase
{
    private const UTC_TIMEZONE = 'UTC';

    /** Default config: two windows Mon-Fri, writer outside, weekends writer-only. */
    private function defaultResolver(?string $tz = self::UTC_TIMEZONE): TimeWindowResolver
    {
        return new TimeWindowResolver(
            readerWindows: [
                ['start' => '10:00:00', 'end' => '14:20:00'],
                ['start' => '17:00:00', 'end' => '20:30:00'],
            ],
            readerDays: [1, 2, 3, 4, 5],
            timezone: $tz,
        );
    }

    public function test_unreachable_windows_are_the_ones_that_can_never_be_entered(): void
    {
        // A window is entered when start <= now < end, so an overnight window and a
        // zero-width one are never entered however long the process runs. A window
        // missing a start or an end is not reported: the resolver reads those as a
        // whole-day window, which can be entered.
        $resolver = new TimeWindowResolver(
            readerWindows: [
                ['start' => '10:00:00', 'end' => '14:20:00'],   // usable
                ['start' => '22:00:00', 'end' => '06:00:00'],   // overnight
                ['start' => '12:00:00', 'end' => '12:00:00'],   // zero-width
                ['start' => '17:00:00'],                        // no end: 17:00 → 23:59:59
                [],                                             // neither: the whole day
            ],
            readerDays: [1, 2, 3, 4, 5],
        );

        $this->assertSame([1, 2], $resolver->unreachableWindows());
    }

    public function test_a_window_reported_unreachable_is_one_the_match_never_enters(): void
    {
        // The report and the match read the same rules, which is the whole reason the
        // resolver answers this rather than a caller re-deriving it.
        $resolver = new TimeWindowResolver(
            readerWindows: [['start' => '22:00:00', 'end' => '06:00:00']],
            readerDays: [1, 2, 3, 4, 5],
        );

        $this->assertSame([0], $resolver->unreachableWindows());

        foreach (['22:00:00', '23:59:59', '00:00:00', '05:59:59', '12:00:00'] as $time) {
            $this->assertFalse($resolver->isReaderWindow('2026-09-14 ' . $time), $time);
        }
    }

    private function dt(string $iso, string $tz = self::UTC_TIMEZONE): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone($tz));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Inside a window
    // ─────────────────────────────────────────────────────────────────────────

    public function test_inside_first_window_returns_true(): void
    {
        $r = $this->defaultResolver();
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 12:00:00')));
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 10:00:00')));
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 13:00:00')));
    }

    public function test_inside_second_window_returns_true(): void
    {
        $r = $this->defaultResolver();
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 18:30:00')));
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 17:00:00')));
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 20:29:59')));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Outside any window (still on a weekday)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_before_first_window_returns_false(): void
    {
        $r = $this->defaultResolver();
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-14 09:59:59')));
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-14 00:00:00')));
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-14 08:00:00')));
    }

    public function test_gap_between_windows_returns_false(): void
    {
        $r = $this->defaultResolver();
        // 14:20 is exclusive end → falls into gap. 17:00 is exclusive start of next window.
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-14 14:20:00')));
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-14 15:00:00')));
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-14 16:59:59')));
    }

    public function test_after_last_window_returns_false(): void
    {
        $r = $this->defaultResolver();
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-14 20:30:00')));
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-14 22:00:00')));
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-14 23:59:59')));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Weekend (Sat/Sun) — always writer mode
    // ─────────────────────────────────────────────────────────────────────────

    public function test_saturday_is_always_writer(): void
    {
        $r = $this->defaultResolver();
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-19 00:00:00')));
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-19 12:00:00')));
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-19 23:59:59')));
        $this->assertSame('writer', $r->currentModeName($this->dt('2026-09-19 12:00:00')));
    }

    public function test_sunday_is_always_writer(): void
    {
        $r = $this->defaultResolver();
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-20 12:00:00')));
        $this->assertSame('writer', $r->currentModeName($this->dt('2026-09-20 12:00:00')));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Per-day override
    // ─────────────────────────────────────────────────────────────────────────

    public function test_weekend_readers_enabled_when_configured(): void
    {
        $r = new TimeWindowResolver(
            readerWindows: [['start' => '00:00:00', 'end' => '23:59:59']],
            readerDays: [1, 2, 3, 4, 5, 6, 7],   // every day
            timezone: self::UTC_TIMEZONE,
        );
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-19 12:00:00')));   // Sat
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-20 03:00:00')));   // Sun
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Boundary semantics — start inclusive / end exclusive
    // ─────────────────────────────────────────────────────────────────────────

    public function test_window_start_is_inclusive(): void
    {
        $r = $this->defaultResolver();
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 10:00:00')));
    }

    public function test_window_end_is_exclusive(): void
    {
        $r = $this->defaultResolver();
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-14 14:20:00')));
    }

    public function test_one_second_before_window_end_is_still_inside(): void
    {
        $r = $this->defaultResolver();
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 14:19:59')));
    }

    public function test_one_second_after_window_start_is_still_inside(): void
    {
        $r = $this->defaultResolver();
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 10:00:01')));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Backward-compat: empty config → always readers (no fallback)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_no_windows_configured_means_always_readers(): void
    {
        $r = new TimeWindowResolver(readerWindows: [], readerDays: [1, 2, 3, 4, 5]);
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 03:00:00')));
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-19 12:00:00')));   // even on Sat
    }

    public function test_no_days_configured_means_always_readers(): void
    {
        $r = new TimeWindowResolver(
            readerWindows: [['start' => '10:00:00', 'end' => '14:20:00']],
            readerDays: [],
        );
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 12:00:00')));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Time format tolerance
    // ─────────────────────────────────────────────────────────────────────────

    public function test_window_accepts_hh_mm_format(): void
    {
        $r = new TimeWindowResolver(
            readerWindows: [['start' => '10:00', 'end' => '14:20']],
            readerDays: [1, 2, 3, 4, 5],
        );
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 12:00:00')));
        $this->assertFalse($r->isReaderWindow($this->dt('2026-09-14 14:20:00')));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Timezone correctness
    // ─────────────────────────────────────────────────────────────────────────

    public function test_timezone_is_applied(): void
    {
        // Same UTC instant: 12:00 UTC == 17:00 Karachi (UTC+5) in Sep.
        $r = $this->defaultResolver(tz: 'Asia/Karachi');

        // 17:00 in Karachi is INSIDE the 17:00-20:30 window.
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 12:00:00', 'Asia/Karachi')));
        $this->assertTrue($r->isReaderWindow($this->dt('2026-09-14 17:00:00', 'Asia/Karachi')));
    }

    public function test_timezone_does_not_silently_drop_a_window(): void
    {
        // Same UTC instant (14:30 UTC) but different local-clock readings:
        //   • UTC resolver        → 14:30 → gap (14:20–17:00) → writer
        //   • Asia/Karachi resolver → 19:30 → second window (17:00–20:30) → readers
        $utc = $this->defaultResolver(tz: 'UTC');
        $karachi = $this->defaultResolver(tz: 'Asia/Karachi');

        $this->assertSame('writer', $utc->currentModeName($this->dt('2026-09-14 14:30:00')));
        $this->assertSame('readers', $karachi->currentModeName($this->dt('2026-09-14 19:30:00', 'Asia/Karachi')));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Argument types — int (timestamp), string, DateTimeInterface
    // ─────────────────────────────────────────────────────────────────────────

    public function test_accepts_unix_timestamp(): void
    {
        $r = $this->defaultResolver();
        // Compute the timestamp at test time so we don't hardcode a wrong number.
        $ts = (new DateTimeImmutable('2026-09-14 12:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $this->assertTrue($r->isReaderWindow($ts));
    }

    public function test_accepts_string(): void
    {
        $r = $this->defaultResolver();
        $this->assertTrue($r->isReaderWindow('2026-09-14 12:00:00'));
    }

    public function test_accepts_datetime_interface(): void
    {
        $r = $this->defaultResolver();
        $dt = $this->dt('2026-09-14 12:00:00');
        $this->assertTrue($r->isReaderWindow($dt));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Diagnostics: nextTransitionAt
    // ─────────────────────────────────────────────────────────────────────────

    public function test_next_transition_inside_window_finds_window_end(): void
    {
        $r = $this->defaultResolver();
        $next = $r->nextTransitionAt($this->dt('2026-09-14 12:00:00'));
        $this->assertNotNull($next);
        $this->assertSame('14:20:00', $next->format('H:i:s'));
        $this->assertSame('2026-09-14', $next->format('Y-m-d'));
    }

    public function test_next_transition_outside_window_finds_next_window_start(): void
    {
        $r = $this->defaultResolver();
        // At 15:00 Mon (in the gap), next transition is 17:00 Mon.
        $next = $r->nextTransitionAt($this->dt('2026-09-14 15:00:00'));
        $this->assertNotNull($next);
        $this->assertSame('17:00:00', $next->format('H:i:s'));
    }

    public function test_next_transition_skips_weekends(): void
    {
        $r = $this->defaultResolver();
        // At 22:00 Friday — next reader window is Monday 10:00.
        $next = $r->nextTransitionAt($this->dt('2026-09-18 22:00:00'));
        $this->assertNotNull($next);
        $this->assertSame('10:00:00', $next->format('H:i:s'));
        $this->assertSame('2026-09-21', $next->format('Y-m-d'));   // Monday
    }

    public function test_next_transition_returns_null_in_always_reader_mode(): void
    {
        $r = new TimeWindowResolver(readerWindows: []);
        $this->assertNull($r->nextTransitionAt($this->dt('2026-09-14 12:00:00')));
    }

    public function test_next_transition_exact_at_boundary_finds_next_window(): void
    {
        // At 14:20:00 (exclusive end of first window), the very next moment is in writer mode.
        $r = $this->defaultResolver();
        $next = $r->nextTransitionAt($this->dt('2026-09-14 14:20:00'));
        $this->assertNotNull($next);
        $this->assertSame('17:00:00', $next->format('H:i:s'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Multi-window day with various DOWs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array{0:string, 1:string}>
     */
    public static function dayProvider(): array
    {
        return [
            ['2026-09-14 12:00:00', '2026-09-14'],   // Mon noon — readers
            ['2026-09-15 12:00:00', '2026-09-15'],   // Tue noon — readers
            ['2026-09-16 12:00:00', '2026-09-16'],   // Wed noon — readers
            ['2026-09-17 12:00:00', '2026-09-17'],   // Thu noon — readers
            ['2026-09-18 12:00:00', '2026-09-18'],   // Fri noon — readers
            ['2026-09-19 12:00:00', '2026-09-19'],   // Sat noon — writer
            ['2026-09-20 12:00:00', '2026-09-20'],   // Sun noon — writer
        ];
    }

    #[DataProvider('dayProvider')]
    public function test_every_day_of_week_at_noon(string $iso, string $expectedDate): void
    {
        $r = $this->defaultResolver();
        $isWeekend = in_array((int) (new DateTimeImmutable($iso))->format('N'), [6, 7], true);

        $this->assertSame(
            $isWeekend ? 'writer' : 'readers',
            $r->currentModeName($this->dt($iso)),
            "{$expectedDate} at noon should be " . ($isWeekend ? 'writer' : 'readers'),
        );
    }
}
