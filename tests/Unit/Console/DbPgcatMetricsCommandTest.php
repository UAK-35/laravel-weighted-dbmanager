<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Console;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * `db:pgcat-metrics`: the pool probe's verdict as Prometheus text exposition, and the one thing a
 * deploy step reads out of it.
 *
 * The output is read from a real `BufferedOutput` rather than through the console mocks, because
 * what is under test is the bytes a scraper parses: a `# HELP`/`# TYPE` pair per family, a sample
 * per pool the pooler spoke about, and nothing else on stdout. The pooler is never dialled — the
 * flipper is bound with a console answer or a probe closure in the test's place, the way
 * `db:doctor`'s own row tests do it — so the rows here are about the translation and the exit code
 * rather than about a socket.
 *
 * The exit code is the second half of the contract and the reason the command exists at all: a
 * scraper gets the same body whatever the verdict is, and only `--strict` turns a verdict into a
 * code, so a pipeline can gate on the pooler without a monitor having to parse a body to see it.
 */
final class DbPgcatMetricsCommandTest extends TestCase
{
    /** @var list<string> */
    private static array $dirs = [];

    /**
     * Lay out an installation whose console has an answer, and bind a flipper that reads it.
     *
     * `$admin` is the pooler's own `SHOW POOLS` answer, or null for an installation that has no
     * console this probe can read — in which case `$probe` stands in for the front door and the
     * sentinel it is asked about. `$armed` is `swrr.pgcat.pool_probe.enabled`; with it off the
     * flipper builds no closure at all, which is the state an installation that never armed the
     * probe is in. `$windowClosed` writes a boot stamp an hour old, so `failed` and `warned` are
     * judgements rather than descriptions of a container that is still starting.
     *
     * @param array{status: string, pools: list<string>, evidence: string, host?: string, port?: int, address?: string}|null $admin
     * @param list<string> $pools the pools the readers variant declares, in file order
     */
    private function installation(
        ?array $admin,
        array $pools = ['reader_db_1', 'writer_db_1'],
        bool $armed = true,
        bool $windowClosed = true,
        ?\Closure $probe = null,
    ): void {
        $dir = $this->tempDir();

        $toml = "[general]\nport = 5433\n".implode('', array_map(
            static fn (string $pool): string => "[pools.{$pool}]\n",
            $pools,
        ));

        foreach (['config_path' => 'pgcat.toml', 'readers_path' => 'pgcat-readers.toml', 'no_readers_path' => 'pgcat-no-readers.toml'] as $key => $name) {
            file_put_contents($dir.'/'.$name, $toml);
        }

        if ($windowClosed) {
            file_put_contents($dir.'/boot.stamp', (string) (time() - 3600));
        }

        config()->set('db-manager.swrr.pgcat', [
            'enabled' => true,
            'use_reload' => false,
            'config_path' => $dir.'/pgcat.toml',
            'readers_path' => $dir.'/pgcat-readers.toml',
            'no_readers_path' => $dir.'/pgcat-no-readers.toml',
            'state_file' => $dir.'/pgcat-flip-state.json',
            'lock_file' => $dir.'/pgcat-flip.lock',
            'boot_file' => $dir.'/boot.stamp',
            'flip_window_seconds' => 480,
            'pool_probe' => ['enabled' => $armed],
        ]);

        config()->set('db-manager.swrr.reader_windows', [['start' => '00:00:00', 'end' => '23:59:59']]);
        config()->set('db-manager.swrr.reader_days', [1, 2, 3, 4, 5, 6, 7]);

        $this->app->forgetInstance(TimeWindowResolver::class);

        $this->app->instance(PgcatConfigFlipper::class, new PgcatConfigFlipper(
            resolver: $this->app->make(TimeWindowResolver::class),
            config: ConfigValue::assoc(config('db-manager.swrr.pgcat')),
            stateFile: $dir.'/pgcat-flip-state.json',
            lockFile: $dir.'/pgcat-flip.lock',
            timezone: 'UTC',
            connectionName: self::CONNECTION,
            driver: 'pgsql',
            poolProbe: $probe,
            poolAdmin: $admin === null ? null : static fn (): array => $admin,
        ));
    }

    /**
     * One run of the command, as a pipeline runs it: the body it wrote and the code it returned.
     *
     * @return array{0: string, 1: int}
     */
    private function metrics(bool $strict = false): array
    {
        $buffer = new BufferedOutput();

        $exit = $this->app->make(Kernel::class)->call(
            'db:pgcat-metrics',
            $strict ? ['--strict' => true] : [],
            $buffer,
        );

        return [$buffer->fetch(), $exit];
    }

    /**
     * The body a scrape gets when the console lists every declared pool: a `1` per pool, a quiet
     * verdict, and the endpoint the packets went to. Nothing else is on stdout — the body opens on
     * the first `# HELP` and ends on a newline, so a scraper sees exposition and not a report.
     */
    public function test_the_command_writes_the_exposition_and_exits_zero(): void
    {
        $this->installation([
            'status' => 'read',
            'pools' => ['reader_db_1', 'writer_db_1'],
            'evidence' => 'the admin console at tcp://127.0.0.1:5433 listed 2 pool(s)',
            // The endpoint the packets went to, as `PoolProbe::adminPools()` returns it — 5433
            // rather than the front door's 6432, so a body that printed the convention instead of
            // the exchange would be a different string.
            'host' => '127.0.0.1',
            'port' => 5433,
            'address' => 'tcp://127.0.0.1:5433',
        ]);

        [$body, $exit] = $this->metrics();

        $this->assertSame(0, $exit);
        $this->assertStringStartsWith('# HELP swrr_pgcat_pool_probe_enabled ', $body);
        $this->assertStringEndsWith("\n", $body);
        $this->assertSame(1, substr_count($body, 'swrr_pgcat_pool_present{pool="reader_db_1"} 1'));
        $this->assertSame(1, substr_count($body, 'swrr_pgcat_pool_present{pool="writer_db_1"} 1'));
        $this->assertStringContainsString('swrr_pgcat_pool_probe_failed 0', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_warned 0', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_unverified 0', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_judged 1', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_endpoint{address="tcp://127.0.0.1:5433"} 1', $body);

        $this->assertSame(0, $this->metrics(true)[1], 'a clean verdict is a clean gate');
    }

    /**
     * The fault this whole reading exists for: a pool the console does not carry. The body says `0`
     * and `failed 1`; a scrape reads it and says nothing about the exit code, and a pipeline that
     * asked for `--strict` stops — with the same body as the run that did not.
     */
    public function test_a_missing_pool_is_zero_and_strict_stops_the_run(): void
    {
        $this->installation([
            'status' => 'read',
            'pools' => ['reader_db_1'],
            'evidence' => 'the admin console at tcp://127.0.0.1:5433 listed 1 pool(s)',
        ]);

        [$body, $exit] = $this->metrics();

        $this->assertSame(0, $exit, 'writing the exposition is the job; the verdict is in it');
        $this->assertStringContainsString('swrr_pgcat_pool_present{pool="writer_db_1"} 0', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_present{pool="reader_db_1"} 1', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_failed 1', $body);

        [$strictBody, $strictExit] = $this->metrics(true);

        $this->assertSame(1, $strictExit);
        $this->assertSame($body, $strictBody, 'the two readers archive one body; only the code differs');
    }

    /**
     * The reading that proves nothing — the pooler answered every name including the sentinel — is
     * no sample and a warning, never a green pool. `--strict` stops on it because a deploy that
     * promotes while the reading cannot tell a pool from a name is promoting on a guess.
     */
    public function test_an_unproven_reading_is_a_warning_and_strict_stops_the_run(): void
    {
        $this->installation(
            admin: null,
            pools: ['reader_db_1'],
            probe: static fn (string $database): array => [
                'status' => 'answered',
                'evidence' => sprintf('the pooler answered for pool "%s"', $database),
                'host' => '127.0.0.1',
                'port' => 5433,
                'address' => 'tcp://127.0.0.1:5433',
            ],
        );

        [$body, $exit] = $this->metrics(true);

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('swrr_pgcat_pool_present{', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_unverified 1', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_warned 1', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_failed 0', $body);
    }

    /**
     * A container inside its boot window is not judged: the pool it does not hold is still a `0`,
     * which is what an operator watching a rollout wants, and neither verdict is claimed because the
     * repair still has somewhere to run. `--strict` therefore passes — the same line the doctor's
     * row draws.
     */
    public function test_an_open_boot_window_publishes_the_answer_without_the_verdict(): void
    {
        $this->installation(
            admin: [
                'status' => 'read',
                'pools' => [],
                'evidence' => 'the admin console at tcp://127.0.0.1:5433 listed 0 pool(s)',
            ],
            windowClosed: false,
        );

        [$body, $exit] = $this->metrics(true);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('swrr_pgcat_pool_present{pool="writer_db_1"} 0', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_judged 0', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_failed 0', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_warned 0', $body);
    }

    /**
     * An installation that never armed the probe is not a broken one, and its scrape has to say so
     * rather than look like a pooler with nothing in it: `enabled 0` and no pool sample at all, which
     * is the series a monitor alerts on when it wants to know that nothing is watching.
     */
    public function test_an_unarmed_probe_still_writes_a_body_a_scraper_can_read(): void
    {
        $this->installation(admin: null, armed: false);

        [$body, $exit] = $this->metrics(true);

        $this->assertSame(0, $exit, 'nothing is wrong with an installation that did not arm the probe, and `--strict` does not invent one');
        $this->assertStringContainsString('swrr_pgcat_pool_probe_enabled 0', $body);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_applicable 0', $body);
        $this->assertStringNotContainsString('swrr_pgcat_pool_present{', $body);
        $this->assertStringNotContainsString('swrr_pgcat_pool_probe_endpoint', $body);
    }

    /**
     * A container with no flipper cannot ask its pooler anything, and it still owes the scraper a
     * parseable body: `enabled 0` is the honest one. `--strict` is the reader that treats it as a
     * fault — a pipeline gating on this command is gating on a reading that was never taken.
     */
    public function test_a_container_without_a_flipper_writes_the_body_and_only_strict_fails(): void
    {
        $this->app->instance(PgcatConfigFlipper::class, new \stdClass());

        [$body, $exit] = $this->metrics();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('swrr_pgcat_pool_probe_enabled 0', $body);
        $this->assertStringNotContainsString('swrr_pgcat_pool_present{', $body);
        $this->assertStringEndsWith("\n", $body);

        $this->assertSame(1, $this->metrics(true)[1]);
    }

    /**
     * One directory per test, removed when the process ends, so the run leaves nothing behind and a
     * leftover file cannot be read by a later test as this one's state.
     */
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/swrr-metrics-'.getmypid().'-'.count(self::$dirs);

        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }

        if (self::$dirs === []) {
            register_shutdown_function(static function (): void {
                foreach (self::$dirs as $made) {
                    foreach (glob($made.'/*') ?: [] as $file) {
                        @unlink($file);
                    }

                    @rmdir($made);
                }
            });
        }

        self::$dirs[] = $dir;

        return $dir;
    }
}
