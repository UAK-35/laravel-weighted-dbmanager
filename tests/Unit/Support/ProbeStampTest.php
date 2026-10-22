<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Support\ProbeStamp;

/**
 * The one rule both readers of the probe stamp decide by: the window, and the states that are not
 * judged at all.
 *
 * The two surfaces assert their own halves — `db:doctor`'s row its sentences, `/health/db` its keys
 * — and neither of those can see the arithmetic they share. A tolerance widened in one of them and
 * not the other is a monitor that pages for an installation the command calls well, so the rule is
 * pinned here, against no record and no application: `ProbeStamp::read()` is a function of the
 * stamp, the probe's status, the store and the schedule, and every claim below is about one of
 * those four.
 */
class ProbeStampTest extends TestCase
{
    /**
     * The window is the wider of the two settings, five times over, and the pair is stated rather
     * than assumed: an installation that probes hourly is watched hourly, whatever its schedule is.
     *
     * @param int $probeSeconds `swrr.audit.store_probe_seconds`
     * @param int $intervalMinutes `swrr.pgcat.schedule.interval_minutes`
     * @param int $expected the age, in seconds, past which the stamp is stale
     */
    #[DataProvider('cadences')]
    public function test_the_window_is_the_wider_of_the_two_settings_five_misses_over(
        int $probeSeconds,
        int $intervalMinutes,
        int $expected,
    ): void {
        $this->assertSame($expected, ProbeStamp::staleAfterSeconds($probeSeconds, $intervalMinutes));
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function cadences(): array
    {
        return [
            'the shipped values: a probe every minute on a one-minute entry' => [60, 1, 300],
            'a probe that runs hourly outlasts the entry, so it is the bound' => [3600, 1, 18000],
            'a slow entry outlasts the probe, so it is the bound' => [60, 25, 7500],
            'equal cadences are one cadence' => [120, 2, 600],
        ];
    }

    /**
     * The boundary is inclusive, which is the row's own rule: five cadences of silence are still
     * within the tolerance, and the sixth is not. Off by one here moves the line both surfaces page
     * on, in opposite directions depending on which one was edited.
     */
    public function test_a_stamp_inside_the_window_reads_as_written_and_the_one_past_it_as_stale(): void
    {
        $status = $this->probeStatus(60, true, true);
        $entry = ['enabled' => true, 'interval_minutes' => 1];

        $inside = ProbeStamp::read($this->stamp(300), $status, true, $entry);
        $outside = ProbeStamp::read($this->stamp(301), $status, true, $entry);

        $this->assertTrue($inside['judged']);
        $this->assertNull($inside['not_judged']);
        $this->assertFalse($inside['stale']);
        $this->assertSame(300, $inside['age_seconds']);
        $this->assertSame(300, $inside['stale_after_seconds']);

        $this->assertTrue($outside['judged']);
        $this->assertTrue($outside['stale']);
        $this->assertSame(301, $outside['age_seconds']);
    }

    /**
     * Each state that removes the expectation, in the order the row asks them: a record that can
     * never be written beats a probe that is switched off, and both are answered before the store
     * and the schedule are.
     *
     * The order is not incidental — an installation with everything wrong at once is told the one
     * thing that would still be wrong after the others were fixed, and both surfaces read the same
     * first cause because the order is here rather than in either of them.
     */
    public function test_each_state_that_removes_the_expectation_is_named_and_the_first_one_wins(): void
    {
        $stamp = $this->stamp(0);
        $armed = ['enabled' => true, 'interval_minutes' => 1];

        $states = [
            'probing is switched off' => ProbeStamp::read($stamp, $this->probeStatus(0, false, true), true, $armed),
            'the record cannot be written' => ProbeStamp::read($stamp, $this->probeStatus(60, true, false), true, $armed),
            'the store is in-process' => ProbeStamp::read($stamp, $this->probeStatus(60, true, true), false, $armed),
            'the schedule is not armed' => ProbeStamp::read($stamp, $this->probeStatus(60, true, true), true, [
                'enabled' => false,
                'interval_minutes' => 1,
            ]),
            'the record holds no stamp' => ProbeStamp::read(['probed_at' => null, 'age_seconds' => null], $this->probeStatus(60, true, true), true, $armed),
        ];

        $this->assertSame(ProbeStamp::OFF, $states['probing is switched off']['not_judged']);
        $this->assertSame(ProbeStamp::UNWRITABLE, $states['the record cannot be written']['not_judged']);
        $this->assertSame(ProbeStamp::IN_PROCESS, $states['the store is in-process']['not_judged']);
        $this->assertSame(ProbeStamp::UNARMED, $states['the schedule is not armed']['not_judged']);
        $this->assertSame(ProbeStamp::NO_STAMP, $states['the record holds no stamp']['not_judged']);

        foreach ($states as $state => $reading) {
            $this->assertFalse($reading['judged'], $state);
            $this->assertNull($reading['stale'], $state.' must not claim anything about the age');
        }

        // Everything wrong at once: the probe being switched off is the answer, because the record
        // that cannot be written is the setting `store probe` fails on and this one has nothing to
        // judge either way.
        $both = ProbeStamp::read($stamp, $this->probeStatus(0, false, false), false, [
            'enabled' => false,
            'interval_minutes' => 1,
        ]);

        $this->assertSame(ProbeStamp::OFF, $both['not_judged']);
    }

    /**
     * The tolerance and the two settings it is made of are answered where nothing is judged, because
     * a monitor plotting an age against a number that vanishes with the verdict has to remember the
     * last value it saw — and the number is what the installation was asked to tolerate, not a
     * conclusion about it.
     */
    public function test_the_window_is_published_where_nothing_is_judged(): void
    {
        $reading = ProbeStamp::read(
            ['probed_at' => 1_700_000_000, 'age_seconds' => 42],
            $this->probeStatus(0, false, true),
            true,
            ['enabled' => true, 'interval_minutes' => 25],
        );

        $this->assertFalse($reading['judged']);
        $this->assertSame(ProbeStamp::OFF, $reading['not_judged']);
        $this->assertSame(0, $reading['probe_seconds']);
        $this->assertSame(25, $reading['interval_minutes']);
        $this->assertSame(7_500, $reading['stale_after_seconds'], 'twenty-five minutes, five times over');
        $this->assertSame(5, $reading['missed_cadences']);
        $this->assertSame(42, $reading['age_seconds'], 'the age is still the stamp\'s, judged or not');
    }

    /**
     * @return array{probed_at: int|null, age_seconds: int|null}
     */
    private function stamp(int $age): array
    {
        return ['probed_at' => time() - $age, 'age_seconds' => $age];
    }

    /**
     * @return array{seconds: int, enabled: bool, writable: bool, file: string}
     */
    private function probeStatus(int $seconds, bool $enabled, bool $writable): array
    {
        return [
            'seconds' => $seconds,
            'enabled' => $enabled,
            'writable' => $writable,
            'file' => '/tmp/audit.json',
        ];
    }
}
