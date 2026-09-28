<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * One audit record with several boots in it at once, on processes rather than on copies of one.
 *
 * WHY THIS IS PROCESSES AND NOT TWO BootAudit OBJECTS
 * --------------------------------------------------
 * The record is safe under concurrency because of two mechanisms, and neither can be shown by a
 * test that runs inside one process. The merge is a read of the file *at the write site*, so
 * exercising it needs a write that lands between another boot's read and its write — which a
 * test can stage by hand, and `BootAuditTest` does. The lock is `flock()` on a companion file,
 * and what it excludes is *other processes*: a same-process test passes against a design that
 * locked a path no other writer shares, or that took the lock and never released it, because
 * the failures those cause are only visible between processes. So each boot here is a real
 * process — `tests/Support/boot-audit-child.php` — reading, merging and renaming the one record
 * the way a boot of an installation does, with nothing shared but the filesystem.
 *
 * WHAT THE RUNS MEASURE
 * ---------------------
 * Every boot starts at the same instant, records a key of its own, and reports two windows it
 * timed inside itself, around the *shipped* lock closures:
 *
 *   window   its read of the record to the rename that replaced it. That is the window a merge
 *            closes, and the boot stage stands in for the work a real boot does between the two
 *            — the store probe, whose cost is a connect timeout.
 *   hold     its taking of the lock to the rename landing. That is the window the lock closes,
 *            and it is meant to be the read, the merge and the write. A lock held across the
 *            boot stage would put that stage's cost on every other boot, so the two numbers are
 *            asserted against each other rather than merely printed.
 *
 * Two regimes, because they fail for different reasons. With a boot stage between the read and
 * the write, a stale copy is what a boot would otherwise write back over another boot's work —
 * that is the merge. With no stage at all, every boot is inside its critical section at the same
 * instant, so a read-merge-write that were merely *narrow* would still collapse to the last
 * writer: the reads happen together, so the merge alone cannot help, and the run is the lock's.
 *
 * The contract asserted is the one this decision exists for: **a recorded entry is never dropped
 * without a line saying the record's own write could not be serialised.** In practice it is
 * stronger than that — with the lock taken by every boot nothing can be lost at all, and that is
 * asserted too, with the line counts in the message so a reader can tell a silent drop from a
 * reported one.
 */
final class BootAuditConcurrencyTest extends TestCase
{
    /** How many boots share the record in a run. Enough to collide, few enough to serialise fast. */
    private const BOOTS = 4;

    /**
     * What one boot does between reading the record and writing it back, in ms — the stand-in for
     * the boot stage that makes its copy stale. Long enough that the lock's own window is two
     * orders of magnitude smaller than it.
     */
    private const STAGE_MS = 400;

    /** The key prefix this test's boots record, one key per boot. */
    private const KEY = 'concurrent.boot.';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * The merge, over a stale copy: every boot read the record a boot stage before it wrote it,
     * so every one of them was decided from a copy another boot had already moved on from.
     */
    public function test_a_boot_stage_between_reading_the_record_and_writing_it_costs_no_entry(): void
    {
        $run = $this->runConcurrently(self::STAGE_MS);

        $this->assertNothingWasLost($run);

        $windows = array_column($run['boots'], 'window_us');
        $holds = array_column($run['boots'], 'hold_us');

        // The run only means something if the copies really were stale: a boot that read and wrote
        // with nothing in between would be exercising the lock and not the merge.
        foreach ($windows as $index => $window) {
            $this->assertGreaterThanOrEqual(
                self::STAGE_MS * 1000 - 1000,
                $window,
                sprintf(
                    'boot %d replaced the record %dµs after reading it, so its copy was not a boot stage old and this run did not exercise the merge',
                    $index,
                    $window,
                ),
            );
        }

        // And the lock covered the write, not the stage: a boot holds the record for its own
        // read-merge-rename, so a hold anywhere near the stage is the lock being taken too early
        // — at boot, rather than at the write.
        $this->assertLessThan(
            intdiv(self::STAGE_MS * 1000, 2),
            max($holds),
            sprintf(
                'the record was held for %dµs at its widest, which is the size of the boot stage (%dms) rather than of the write: something that is not the read, the merge and the rename is inside the lock. Holds, in µs: %s',
                max($holds),
                self::STAGE_MS,
                implode(', ', $holds),
            ),
        );
    }

    /**
     * The lock, in the regime where it is the only mechanism that can save the run: no work
     * between the read and the write, so every boot reaches its critical section at the same
     * instant. The merge does not help there — all the reads happen before any of the writes —
     * and a design without the exclusion would leave the record holding whichever boot wrote last.
     */
    public function test_boots_that_read_and_write_at_the_same_instant_still_leave_every_key(): void
    {
        $run = $this->runConcurrently(0);

        $this->assertNothingWasLost($run);

        $holds = array_column($run['boots'], 'hold_us');
        $widest = max($holds);

        // What the lock covers when there is no boot stage to hide it: a generous ceiling, because
        // the point is that it is a few file operations and not something slow. This is the number
        // the stage-based test compares against, measured where it is smallest.
        $this->assertLessThan(
            100_000,
            $widest,
            sprintf(
                'the record was held for %dµs at its widest, so something slow is inside the lock: it is meant to cover the read, the merge and the rename. Holds, in µs: %s',
                $widest,
                implode(', ', $holds),
            ),
        );
    }

    /**
     * Both assertions the runs are for, in the order a failure should be read in.
     *
     * @param array{boots: list<array{key: string, window_us: int, hold_us: int, recorded: bool, lines: int, about_the_record: int}>, keys: list<string>} $run
     */
    private function assertNothingWasLost(array $run): void
    {
        $produced = array_column($run['boots'], 'key');
        $aboutTheRecord = array_sum(array_column($run['boots'], 'about_the_record'));

        // The precondition, and the reason the two mechanisms can be told apart at all: with the
        // lock taken by every boot no entry can be lost, so a run where a boot could not take it
        // is a different run — the merge without the exclusion, where a loss is possible and is
        // reported. A failure here is about the machine, and the message says which machine.
        $this->assertSame(
            0,
            $aboutTheRecord,
            sprintf(
                'a boot logged %d line(s) about the record\'s own write, so at least one boot could not take the lock it retries for 105ms: this run measured the merge without the exclusion, and a loss in it is the residual rather than a defect. Keys before the run were %s.',
                $aboutTheRecord,
                implode(', ', $produced),
            ),
        );

        // Every boot saw its own key in the record just after writing it, which is what a write
        // replaced by a later boot would not be able to say.
        foreach ($run['boots'] as $boot) {
            $this->assertTrue(
                $boot['recorded'],
                sprintf(
                    'boot %s wrote its finding and then did not find it in the record, so another boot replaced the entry it had just written',
                    $boot['key'],
                ),
            );
        }

        // And none of them is gone at the end. A dropped entry is the failure this decision is
        // against, and the count of lines about the record's own write is in the message so that
        // a silent drop and a reported one are not read the same way.
        $lost = array_values(array_diff($produced, $run['keys']));

        $this->assertSame([], $lost, sprintf(
            '%d of the %d keys a concurrent boot recorded are missing from the record: %s. The boots logged %d line(s) about the record\'s own write, so the entry was dropped %s.',
            count($lost),
            count($produced),
            implode(', ', $lost),
            $aboutTheRecord,
            $aboutTheRecord > 0 ? 'by a write that said it could not serialise — reported rather than prevented' : 'in silence, which is the defect this test exists for',
        ));
    }

    /**
     * One run: every boot waiting for the same wall-clock instant, so they all read the record
     * before any of them has written it, and each reporting what it measured.
     *
     * @return array{boots: list<array{key: string, window_us: int, hold_us: int, recorded: bool, lines: int, about_the_record: int}>, keys: list<string>}
     */
    private function runConcurrently(int $stageMs): array
    {
        if (!self::canSpawn()) {
            $this->markTestSkipped('this machine cannot start a PHP process, so there is no second boot to race the first.');
        }

        $this->assertFileExists(self::child(), 'the boot this harness runs is part of the suite');

        $dir = $this->tempDir();
        $record = $dir . '/audit.json';
        $startAt = microtime(true) + 1.0;

        $processes = [];

        for ($index = 0; $index < self::BOOTS; $index++) {
            $process = new Process([
                PHP_BINARY,
                self::child(),
                $record,
                $dir . '/boot-' . $index . '.jsonl',
                sprintf('%.6F', $startAt),
                self::KEY . $index,
                (string) $stageMs,
            ]);

            $process->setTimeout(30);
            $process->start();

            $processes[] = $process;
        }

        $boots = [];
        $failed = [];

        foreach ($processes as $index => $process) {
            try {
                $process->wait();
            } catch (Throwable $e) {
                $failed[] = sprintf('boot %d: %s', $index, $e->getMessage());

                continue;
            }

            if (!$process->isSuccessful()) {
                $failed[] = sprintf(
                    'boot %d exited %s: %s',
                    $index,
                    (string) $process->getExitCode(),
                    trim($process->getErrorOutput() . $process->getOutput()),
                );

                continue;
            }

            $decoded = json_decode(trim($process->getOutput()), true);

            if (!is_array($decoded)) {
                $failed[] = sprintf('boot %d printed something that is not a result: %s', $index, trim($process->getOutput()));

                continue;
            }

            $boots[] = $decoded;
        }

        $this->assertSame([], $failed, "a boot of the record did not finish, so the run says nothing about the others:\n" . implode("\n", $failed));
        $this->assertCount(self::BOOTS, $boots, 'every boot has to report before a run means anything');

        return [
            'boots' => $boots,
            'keys' => array_keys((new BootAudit($record, 0))->read()['findings']),
        ];
    }

    /**
     * The boot the harness runs, by path: `tests/Support/boot-audit-child.php`.
     *
     * A script rather than a class, because what it does is run — once per boot here, and by hand
     * when a measurement needs checking.
     */
    private static function child(): string
    {
        return dirname(__DIR__, 3) . '/tests/Support/boot-audit-child.php';
    }

    /**
     * Whether this machine starts a PHP process at all. A skipped run is the honest answer for the
     * same reason the tests that run `jq` or a shell skip them: the harness is processes, and a
     * machine that refuses to start one has nothing to say about a lock between them.
     */
    private static function canSpawn(): bool
    {
        try {
            $process = new Process([PHP_BINARY, '-r', 'echo "ok";']);
            $process->setTimeout(20);
            $process->run();
        } catch (ProcessRuntimeException) {
            return false;
        }

        return $process->getExitCode() === 0;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/swrr-concurrent-boot-' . bin2hex(random_bytes(6));

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }
}
