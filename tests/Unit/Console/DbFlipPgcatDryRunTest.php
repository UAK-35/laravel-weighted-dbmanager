<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Tests\Support\PgcatInstallation;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * What `db:pgcat-flip --dry-run` promises, held to the installation rather than to the sentence
 * it ends on.
 *
 * The rehearsal is the one route in this package that *writes* while promising it changes nothing:
 * it writes the swap's temp file into the target's own directory and removes it again, and it takes
 * the lock and releases it. The suite read that promise only the way the report makes it — a test
 * asserting the steps and their outcomes, and the flipper's own tests asserting each step's side of
 * the performed/reported line from a fresh fixture. Neither looks at the installation on both sides
 * of one run, so neither can fail when a rehearsal rewrites the target, records the mode it did not
 * apply, or leaves its probe behind and reports `remove temp done` anyway.
 *
 * So these tests read the installation before and after one `--dry-run` and ask for the readings to
 * be identical: the bytes of every file in the installation — the two variants, the target, the state
 * file, the stand-in supervisor — and the lock's own bytes beside them. The rehearsal's probe would
 * appear in that reading as a file that was not there before, which is why the listing is of the
 * whole directory rather than of the paths a flip is known to touch.
 *
 * The lock is read separately because it is the one trace the design leaves on purpose: a flip takes
 * it and never deletes it, and an installation whose lock directory refuses the lock file is an
 * installation a flip cannot run on, so the rehearsal creating it *is* the precondition being tested.
 * The test therefore holds the lock to being unchanged where it exists, and to being created empty
 * where it does not — rather than excusing the whole file and losing the reading.
 *
 * The reading is not taken on trust. `test_the_reading_notices_a_flip_that_landed` runs the same
 * command for real, in a fixture built the same way, and asserts the snapshot *moves* — so a helper
 * that returned a constant, or a listing that read nothing, cannot make these tests pass.
 */
final class DbFlipPgcatDryRunTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance(PgcatConfigFlipper::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * The state file is the half of a flip nobody thinks about, so it is read on both sides of a
     * rehearsal that would have written it. The record says `writer` while the resolver says
     * `readers`, which is the state a flip exists to correct: a rehearsal that recorded the mode it
     * only reported would make the next real flip believe the file had already been swapped and
     * decline to act — the failure the design record describes by name.
     */
    public function test_a_dry_run_leaves_every_config_file_and_the_state_file_as_it_found_them(): void
    {
        $installation = $this->installation();
        $installation->recordMode('writer');

        // A lock every production installation has: a flip never deletes it, so the fixture that
        // carries one is the state this promise is about, and the installation that has never held
        // one is the case the test beside this one covers.
        file_put_contents($installation->path('lock_file'), '');

        $before = self::snapshot($installation);

        $exit = Artisan::call('db:pgcat-flip', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);

        // The premise: the rehearsal got all the way to the end and named the swap it would take,
        // so the identity below is a reading of a run that had something to report rather than of
        // one refused at the door and still for the wrong reason.
        $this->assertStringContainsString('would flip: writer → readers', $output, $output);
        $this->assertStringContainsString('rename', $output, $output);
        $this->assertStringContainsString('state file', $output, $output);

        $after = self::snapshot($installation);

        // Every file it read, and the record it did not write. The target is in this reading as one
        // of the files, so a rehearsal that renamed the variant over it — the step it exists to
        // report instead of taking — cannot pass as one that did not.
        $this->assertSame($before['files'], $after['files'], 'A dry run rewrote a file it only reports on.' . PHP_EOL . $output);
        $this->assertSame('writer', $this->recordedMode($installation), 'A dry run recorded the mode it did not apply.' . PHP_EOL . $output);

        // The lock, held where it already exists: taken and released, bytes untouched.
        $this->assertSame($before['lock'], $after['lock'], 'A dry run did not leave the lock as it found it.' . PHP_EOL . $output);
        $this->assertNotNull($before['lock'], 'the fixture has to have a lock file for this to be an assertion about one');
    }

    /**
     * The other side of the lock rule, on the installation that has never held one: a rehearsal
     * creates it, empty, and changes nothing else.
     *
     * The two tests are separate because the readings are different claims. Above, the lock exists
     * and must not move; here it does not exist and must not gain contents, which is the fact an
     * operator cares about — the file is a mutex, not a record, and a rehearsal that wrote into it
     * would be writing a state a flip reads.
     */
    public function test_a_dry_run_on_an_installation_with_no_lock_creates_an_empty_one(): void
    {
        $installation = $this->installation();

        $before = self::snapshot($installation);

        $this->assertNull($before['lock'], 'the fixture has to start without a lock for this to be the case under test');
        $this->assertFalse($installation->stateRecorded(), 'the fixture starts with nothing recorded, which is what makes this run flip');

        $exit = Artisan::call('db:pgcat-flip', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('would flip: never → readers', $output, $output);

        $after = self::snapshot($installation);

        $this->assertSame($before['files'], $after['files'], 'A dry run changed a file, and only the lock may appear.' . PHP_EOL . $output);
        $this->assertSame(hash('sha256', ''), $after['lock'], 'The rehearsal wrote something into the lock file.' . PHP_EOL . $output);
        $this->assertFalse($installation->stateRecorded(), 'A dry run recorded a mode on an installation that had never flipped.' . PHP_EOL . $output);
    }

    /**
     * The control, in the sense this repository uses the word: the same reading has to *see* a flip
     * that landed, or a snapshot that returned a constant would satisfy both tests above.
     *
     * The target is the one assertion that cannot be satisfied by an appearing state file alone: the
     * real run replaces `pgcat.toml` with the variant's bytes, which is the step the rehearsal only
     * reports, and the reading has to show it.
     */
    public function test_the_reading_notices_a_flip_that_landed(): void
    {
        $installation = $this->installation();

        $before = self::snapshot($installation);

        $exit = Artisan::call('db:pgcat-flip');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('flipped', $output, $output);

        $after = self::snapshot($installation);

        $this->assertNotSame($before['files'], $after['files'], 'The flip rewrote nothing the reading could see.');
        $this->assertNotSame($before['lock'], $after['lock'], 'The flip took no lock the reading could see.');
        $this->assertSame(
            (string) file_get_contents($installation->path('readers_path')),
            $installation->targetContents(),
            'The target does not hold the variant the flip applied.',
        );
        $this->assertSame('readers', $this->recordedMode($installation), $output);
    }

    /**
     * The shape of what a rehearsal is about to swap in, read as a step of the rehearsal itself and
     * failing the run rather than the pooler.
     *
     * A writer-only variant that still declares a reader is a file whose whole purpose is undone,
     * and a flip to it is a pooler started on a configuration with a pool the file it replaced had
     * left out — which is the failure pgcat reaches by refusing to start, at the window boundary,
     * with nothing looking. The rehearsal is where it is cheapest to find, so it is performed before
     * anything is written and reported as the step it is: the file the flip would take is one this
     * installation cannot serve.
     */
    public function test_a_rehearsal_fails_when_the_variant_it_would_swap_in_has_the_wrong_shape(): void
    {
        $installation = $this->pooledInstallation(noReaders: "[pools.writer_db_1]\n[pools.reader_db_1]\n");

        $before = self::snapshot($installation);

        $exit = Artisan::call('db:pgcat-flip', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('a flip would fail', $output, $output);
        $this->assertStringContainsString('swrr.pgcat.no_readers_path', $output, 'the sentence names the file');
        $this->assertStringContainsString('declares pool "reader_db_1"', $output, 'and the pool it should not');
        $this->assertStringContainsString('a flip would swap in a file this installation cannot serve', $output);

        // The step it stopped at, and the steps it never reached: the failure is before the temp
        // write, because a rehearsal that proved the write and then refused would have written into
        // the target's directory to answer a question the shape had already answered.
        $this->assertStringContainsString('variant shape', $output);
        $this->assertStringNotContainsString('write temp', $output);
        $this->assertStringNotContainsString('rename', $output);

        $this->assertSame($before['files'], self::snapshot($installation)['files'], 'a refused rehearsal still changes nothing');
        $this->assertFalse($installation->stateRecorded());
    }

    /**
     * The same refusal for the other end of the shape: the file in place has lost a pool the pinned
     * connection writes through, so the target pgcat is running on is one the installation cannot
     * serve — the state a pool commented out of a rendered variant leaves behind.
     */
    public function test_a_rehearsal_fails_when_the_file_in_place_lost_a_pool_the_connection_names(): void
    {
        $this->pooledInstallation(inPlace: "[pools.reader_db_1]\n[pools.reader_db_2]\n");

        $exit = Artisan::call('db:pgcat-flip', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('swrr.pgcat.config_path', $output, $output);
        $this->assertStringContainsString('declares no pool "writer_db_1"', $output);
        $this->assertStringContainsString('the file pgcat is running on has no pool for a write to reach', $output);
    }

    /**
     * The other side of the rule, and the reason it is a rule rather than a fixture: an installation
     * whose variants declare the pools the connection is written with rehearses to a flip, and the
     * step says what it compared — the shape is read, not merely present.
     */
    public function test_a_rehearsal_of_a_consistent_tree_reports_the_shape_it_read(): void
    {
        $installation = $this->pooledInstallation();

        $exit = Artisan::call('db:pgcat-flip', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('would flip: never → readers', $output, $output);
        $this->assertStringContainsString('variant shape', $output);
        $this->assertStringContainsString('3 configured variants parse', $output);
        $this->assertStringContainsString('the writer-only variant declares none of the 2 read pools', $output);
        $this->assertStringContainsString('rename', $output, 'a shape that holds still rehearses the swap');

        $this->assertFalse($installation->stateRecorded());
    }

    /**
     * An installation a flip would act on and the rehearsal has a *shape* to read: the three files
     * declare pools, and the connection the package follows is written with the names it may hold
     * them to — a pool to write through and two to weight for reads.
     *
     * The connection is the whole of what makes the reading applicable rather than silent. It is a
     * pooler-fronted connection: its `database` is the pool pgcat addresses, and each read entry
     * names its own, which is what a weighted pick between pools looks like. A connection whose
     * `database` is a database name no variant declares is not judged, which is the line
     * `VariantShapeTest` pins on its own.
     *
     * @param string|null $inPlace the file in place, or null for the shape a flip can serve
     * @param string|null $noReaders the writer-only variant, or null for the shape a flip can serve
     */
    private function pooledInstallation(?string $inPlace = null, ?string $noReaders = null): PgcatInstallation
    {
        $installation = $this->installation();

        $readers = "[pools.writer_db_1]\n[pools.reader_db_1]\n[pools.reader_db_2]\n";

        file_put_contents($installation->path('config_path'), $inPlace ?? $readers);
        file_put_contents($installation->path('readers_path'), $readers);
        file_put_contents($installation->path('no_readers_path'), $noReaders ?? "[pools.writer_db_1]\n");

        config()->set('database.connections.pooler', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5433,
            'database' => 'writer_db_1',
            'read' => [
                ['host' => '127.0.0.1', 'port' => 5433, 'database' => 'reader_db_1'],
                ['host' => '127.0.0.1', 'port' => 5433, 'database' => 'reader_db_2'],
            ],
        ]);
        config()->set('database.default', 'pooler');

        $this->app->forgetInstance(PgcatConfigFlipper::class);

        return $installation;
    }

    /**
     * An installation a flip would act on: the variants and the target exist, supervisor answers, the
     * resolver is in readers mode over a full-day window, and nothing has been recorded yet — so the
     * mode has changed and a flip is exactly what a run would take.
     */
    private function installation(): PgcatInstallation
    {
        $dir = $this->tempDir();

        $installation = PgcatInstallation::make($dir);
        $installation->install();

        return $installation;
    }

    /**
     * The installation as one comparable value: every file under it, by path, as a hash of its bytes,
     * and the lock's bytes beside them.
     *
     * The bytes are hashed rather than asked of a timestamp because a rewrite inside one clock tick
     * looks untouched at second resolution, and a rehearsal runs in milliseconds — that is exactly
     * the case where a stat comparison would hide a write. The lock is lifted out of `files` because
     * its presence is the one change the design allows (see the class docblock); everything else,
     * including the temp file a rehearsal writes and removes, is in the listing by construction.
     *
     * @return array{files: array<string, string>, lock: string|null}
     */
    private static function snapshot(PgcatInstallation $installation): array
    {
        $dir = str_replace('\\', '/', dirname($installation->path('config_path')));
        $lock = str_replace('\\', '/', $installation->path('lock_file'));

        $files = [];

        foreach (self::listing($dir) as $path) {
            if ($path === $lock) {
                continue;
            }

            $files[substr($path, strlen($dir) + 1)] = (string) hash_file('sha256', $path);
        }

        ksort($files);

        return [
            'files' => $files,
            'lock' => is_file($lock) ? (string) hash_file('sha256', $lock) : null,
        ];
    }

    /**
     * Every file under a directory, sorted and with forward slashes — the reading above is a diff of
     * two of these, so it has to be stable across the two calls and readable on both platforms.
     *
     * @return list<string>
     */
    private static function listing(string $dir): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $found[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/pgcat-flip-dry-run-'.bin2hex(random_bytes(6));

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $this->fail("Could not create the temp directory {$dir}");
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/{*,*/*}', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @chmod($file, 0o666);
                @unlink($file);
            }
        }

        @rmdir($dir.'/bin');
        @rmdir($dir);
    }

    /**
     * The mode the record holds, read as the mode rather than as the file's bytes: the state file
     * also carries the run bookkeeping the boot window is judged on (`runs`, `last_kind`,
     * `last_run_at`), which a run may write without flipping.
     */
    private function recordedMode(PgcatInstallation $installation): ?string
    {
        $contents = $installation->stateContents();

        if ($contents === null || $contents === '') {
            return null;
        }

        $state = json_decode($contents, true);

        if (!is_array($state)) {
            return null;
        }

        $mode = $state['last_mode'] ?? null;

        return is_string($mode) ? $mode : null;
    }
}
