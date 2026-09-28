<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Pgcat\FlipWindow;

/**
 * The boot window: when it starts, how long it is, and what "no stamp" means.
 *
 * This is the primitive both halves of the repair rest on — the flipper stops when it closes
 * and `/health/db` fails when it closed unconverged — so it is tested on its own rather than
 * only through them. The clock is injected, which is why these tests run in microseconds
 * rather than waiting eight minutes to watch a deadline pass.
 *
 * The one rule worth stating twice, because it is the one most likely to be "fixed" by
 * accident: **no stamp is not a closed window**. A local run, a container whose entrypoint
 * predates the stamp, or an unreadable clock all produce no boot time, and none of them is
 * evidence that this container failed to come up. `source` is what tells the two apart, and
 * the test below pins it.
 */
final class FlipWindowTest extends TestCase
{
    /** A boot stamp every test shares, so the arithmetic is readable: 2026-09-28T00:00:00Z. */
    private const BOOTED_AT = 1790553600;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/flip-window-test-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @chmod($file, 0o666);
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    /**
     * A window reading a stamp of `$bootedAt`, with "now" pinned to `$now`.
     */
    private function window(?int $bootedAt = self::BOOTED_AT, float $now = self::BOOTED_AT, ?int $seconds = null): FlipWindow
    {
        $file = $this->dir.'/container-booted-at';

        if ($bootedAt !== null) {
            file_put_contents($file, (string) $bootedAt);
        }

        return new FlipWindow(
            bootFile: $file,
            seconds: $seconds,
            clock: static fn (): float => $now,
        );
    }

    public function test_the_window_defaults_to_the_eight_minutes_the_container_has_to_come_up(): void
    {
        $this->assertSame(480, FlipWindow::DEFAULT_SECONDS);

        $window = new FlipWindow(bootFile: $this->dir.'/nothing-here');

        $this->assertSame(480, $window->seconds());
    }

    /**
     * A configured window is used as given; a zero, a negative and an absent one are the
     * default rather than "no window". "No window" would silently switch off the only check
     * that notices the flip never ran, and an operator who wants that has `enabled`.
     */
    public function test_a_configured_window_is_used_and_a_nonsense_one_falls_back_to_the_default(): void
    {
        $file = $this->dir.'/container-booted-at';

        $this->assertSame(600, (new FlipWindow($file, 600))->seconds());
        $this->assertSame(1, (new FlipWindow($file, 1))->seconds());
        $this->assertSame(480, (new FlipWindow($file, 0))->seconds());
        $this->assertSame(480, (new FlipWindow($file, -60))->seconds());
        $this->assertSame(480, (new FlipWindow($file, null))->seconds());
    }

    public function test_from_config_reads_the_boot_file_and_the_window(): void
    {
        $window = FlipWindow::fromConfig([
            'boot_file' => '/var/run/container-booted-at',
            'flip_window_seconds' => 600,
        ]);

        $this->assertSame('/var/run/container-booted-at', $window->bootFile());
        $this->assertSame(600, $window->seconds());

        // Absent keys are the documented defaults rather than a second reading of them.
        $bare = FlipWindow::fromConfig([]);

        $this->assertSame('', $bare->bootFile());
        $this->assertSame(FlipWindow::DEFAULT_SECONDS, $bare->seconds());
    }

    public function test_the_boot_time_is_read_from_the_stamp_whatever_surrounds_it(): void
    {
        $file = $this->dir.'/container-booted-at';

        // A bare `date +%s` with a trailing newline, which is what entrypoint.sh writes.
        file_put_contents($file, self::BOOTED_AT."\n");
        $this->assertSame((float) self::BOOTED_AT, (new FlipWindow($file))->bootedAt());

        // Whitespace and a comment after it: the first token is the stamp and the rest is
        // ignored, so a human annotating the file cannot break the reading.
        file_put_contents($file, '  '.self::BOOTED_AT."  # container boot\n");
        $this->assertSame((float) self::BOOTED_AT, (new FlipWindow($file))->bootedAt());
    }

    /**
     * Every way of having nothing to read. A file that exists but holds something that is not a
     * positive number is reported as absent rather than guessed at, and the window is then not
     * judged at all — see the class docblock for why silence is the right answer there.
     */
    public function test_without_a_stamp_nothing_is_judged(): void
    {
        $missing = new FlipWindow($this->dir.'/never-written', 480, static fn (): float => self::BOOTED_AT);

        $this->assertNull($missing->bootedAt());
        $this->assertNull($missing->deadline());
        $this->assertNull($missing->remaining());
        $this->assertFalse($missing->closed());
        $this->assertFalse($missing->contains(self::BOOTED_AT));
        $this->assertSame(FlipWindow::SOURCE_NO_STAMP, $missing->toArray()['source']);

        $file = $this->dir.'/container-booted-at';

        foreach (['', "   \n", 'not-a-number', '0', '-1'] as $written) {
            file_put_contents($file, $written);

            $window = new FlipWindow($file, 480, static fn (): float => self::BOOTED_AT);

            $this->assertNull(
                $window->bootedAt(),
                sprintf('A stamp of %s is not a boot time this window can use.', var_export($written, true)),
            );
            $this->assertFalse($window->closed());
        }

        // An empty boot_file — no path configured at all — is the same fact, and must not make
        // `is_file('')` the thing that decides it.
        $this->assertNull((new FlipWindow('', 480))->bootedAt());
    }

    public function test_a_stamped_container_is_measured_from_its_boot_time(): void
    {
        $window = $this->window(self::BOOTED_AT, self::BOOTED_AT + 120, 480);

        $this->assertSame((float) self::BOOTED_AT, $window->bootedAt());
        $this->assertSame((float) (self::BOOTED_AT + 480), $window->deadline());
        $this->assertSame(360.0, $window->remaining());
        $this->assertFalse($window->closed());
        $this->assertSame(FlipWindow::SOURCE_STAMPED, $window->toArray()['source']);
    }

    public function test_the_window_closes_at_its_deadline_and_not_a_second_before(): void
    {
        $this->assertFalse($this->window(self::BOOTED_AT, self::BOOTED_AT + 479.999)->closed());
        $this->assertTrue($this->window(self::BOOTED_AT, self::BOOTED_AT + 480)->closed());
        $this->assertTrue($this->window(self::BOOTED_AT, self::BOOTED_AT + 4800)->closed());
    }

    /**
     * Containment is how "did the flip converge in time" is answered, and both ends are
     * inclusive: a flip that reached a usable mode at the boot instant and one that reached it
     * on the deadline are both inside the window that was given to them.
     */
    public function test_containment_covers_the_whole_window_including_both_ends(): void
    {
        $window = $this->window(self::BOOTED_AT, self::BOOTED_AT, 480);

        $this->assertTrue($window->contains((float) self::BOOTED_AT));
        $this->assertTrue($window->contains((float) (self::BOOTED_AT + 240)));
        $this->assertTrue($window->contains((float) (self::BOOTED_AT + 480)));

        $this->assertFalse($window->contains((float) (self::BOOTED_AT - 1)));
        $this->assertFalse($window->contains((float) (self::BOOTED_AT + 481)));
    }

    /**
     * The block `/health/db`, `--status` and `--json` all publish. The fields are pinned by
     * name because they are read by name in three places, and the ones that are `null` when
     * there is no stamp are pinned too: a reader has to be able to tell "not judged" from
     * "judged and passed".
     */
    public function test_the_block_reports_the_window_and_where_it_came_from(): void
    {
        $block = $this->window(self::BOOTED_AT, self::BOOTED_AT + 120, 480)->toArray();

        $this->assertSame([
            'boot_file',
            'source',
            'booted_at',
            'booted_at_unix',
            'window_seconds',
            'deadline',
            'deadline_unix',
            'remaining_seconds',
            'closed',
        ], array_keys($block));

        $this->assertSame($this->dir.'/container-booted-at', $block['boot_file']);
        $this->assertSame(FlipWindow::SOURCE_STAMPED, $block['source']);
        $this->assertSame('2026-09-28T00:00:00Z', $block['booted_at']);
        $this->assertSame((float) self::BOOTED_AT, $block['booted_at_unix']);
        $this->assertSame(480, $block['window_seconds']);
        $this->assertSame('2026-09-28T00:08:00Z', $block['deadline']);
        $this->assertSame((float) (self::BOOTED_AT + 480), $block['deadline_unix']);
        $this->assertSame(360.0, $block['remaining_seconds']);
        $this->assertFalse($block['closed']);

        // And the same block for a container nothing recorded a boot time for: every derived
        // field is null and the source says why, so a reader never has to infer it.
        $unstamped = (new FlipWindow($this->dir.'/never-written'))->toArray();

        $this->assertSame(FlipWindow::SOURCE_NO_STAMP, $unstamped['source']);
        $this->assertNull($unstamped['booted_at']);
        $this->assertNull($unstamped['booted_at_unix']);
        $this->assertNull($unstamped['deadline']);
        $this->assertNull($unstamped['deadline_unix']);
        $this->assertNull($unstamped['remaining_seconds']);
        $this->assertFalse($unstamped['closed']);
        $this->assertSame(FlipWindow::DEFAULT_SECONDS, $unstamped['window_seconds']);
    }

    /**
     * The stamp is written by `date +%s`, which is a bare unix timestamp, so there is one
     * format on disk rather than two. A file written by something else — an ISO string, say —
     * is refused rather than parsed on a guess, because a guessed boot time is a guessed
     * deadline.
     */
    public function test_a_stamp_that_is_not_a_unix_timestamp_is_not_read_as_one(): void
    {
        $file = $this->dir.'/container-booted-at';
        file_put_contents($file, '2026-09-28T00:00:00Z');

        $this->assertNull((new FlipWindow($file))->bootedAt());
    }
}
