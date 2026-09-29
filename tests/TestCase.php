<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests;

use Illuminate\Database\DatabaseServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;
use Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider;

/**
 * Boots the package the way a real application has to: by taking the place of
 * Illuminate\Database\DatabaseServiceProvider. Two providers binding `db` would
 * mean whichever registers last wins, so the framework's own is swapped out
 * here rather than added to alongside.
 */
abstract class TestCase extends Orchestra
{
    /** A connection with two weighted replicas and one writer. */
    public const CONNECTION = 'weighted';

    /** Proves the configured key prefix reaches every store. */
    public const KEY_PREFIX = 'swrr-test';

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return array<class-string, class-string|false>
     */
    protected function overrideApplicationProviders($app): array
    {
        return [
            DatabaseServiceProvider::class => WeightedDatabaseServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $replicas = [
            ['host' => '10.1.0.1', 'port' => 5432, 'cpu_cores' => 16, 'ram_gb' => 64],
            ['host' => '10.1.0.2', 'port' => 5432, 'cpu_cores' => 8, 'ram_gb' => 32],
        ];

        $writer = ['host' => '10.1.0.9', 'port' => 5432];

        $app['config']->set('database.default', self::CONNECTION);

        $app['config']->set('database.connections.'.self::CONNECTION, [
            'driver' => 'pgsql',
            'database' => 'app',
            'read' => $replicas,
            'write' => $writer,
        ]);

        // Same hardware, but the connection asks for the linear formula.
        $app['config']->set('database.connections.weighted_linear', [
            'driver' => 'pgsql',
            'database' => 'app',
            'weight_formula' => 'linear',
            'read' => $replicas,
            'write' => $writer,
        ]);

        // No read list at all — what db:probe-replicas has to cope with.
        $app['config']->set('database.connections.writer_only', [
            'driver' => 'pgsql',
            'database' => 'app',
        ]);

        $app['config']->set('db-manager.swrr', [
            'primary_store' => 'redis',
            'redis_connection' => 'default',
            'state_ttl' => 86400,
            'key_prefix' => self::KEY_PREFIX,
            'allow_local_fallback' => true,
            'default_weight_cpu_factor' => 3.0,
            'default_weight_ram_factor' => 3.375,
            'default_weight_formula' => 'diminishing',
        ]);

        // Every boot audits the configuration and remembers the findings that still
        // stand, and the flipper keeps its state file, so both are pointed at the
        // suite's own directory: the defaults are single paths in the system temp
        // directory, shared with every other process on the machine, which would let a
        // developer's own installation decide what these tests read.
        //
        // The audit record is unique per boot. A record left behind by one test method
        // would otherwise be resolved — and the resolution logged — by the next one,
        // which is the cross-process behaviour the tests assert deliberately rather
        // than by accident.
        //
        // The boot this fixture describes is an installation whose probe is on and has
        // already run: the interval is the shipped default, and the record carries a stamp
        // from a moment ago, so no boot in this suite issues a PING and none has anything
        // to say about the store. A probe that is switched off is a standing finding of its
        // own now (`swrr.audit.store_probe_seconds.off`, switched off with
        // `store_probe_seconds = 0`), and a suite that left it off would have that finding
        // in every boot it asserts about; the provider tests that *are* about the probe set
        // the interval themselves and backdate the stamp with their `rewindStoreProbe()`.
        $boot = self::nextBoot();
        $record = self::stateDir() . '/audit-' . $boot . '.json';

        $app['config']->set('db-manager.swrr.audit', [
            'file' => $record,
            'store_probe_seconds' => 60,
        ]);

        file_put_contents($record, (string) json_encode([
            'findings' => [],
            'store_probed_at' => time(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Both switches are declared on purpose. Their documented defaults are the ones the
        // shipped config prints — `enabled` off, `use_reload` on — and a suite that left them
        // out would be exercising those defaults rather than the flipper: pgcat is what most
        // of these tests are about, so the suite arms it and says so.
        $app['config']->set('db-manager.swrr.pgcat', [
            'enabled' => true,
            'use_reload' => false,
            'state_file' => self::stateDir() . '/pgcat-flip-state-' . $boot . '.json',
            'lock_file' => self::stateDir() . '/pgcat-flip.lock',
        ]);

        // The one real query /health/db runs on the connection the package follows is switched
        // off for this fixture, because the fixture's connections declare replicas that do not
        // exist (10.1.0.1, 10.1.0.9): a test must not open a socket to ask one of them. It is a
        // documented switch rather than a fixture-only default so the endpoint's shipped default
        // stays the one the sample config prints — on — and the tests that are *about* the query
        // turn it back on and point the connection at SQLite, which answers without a server.
        // See tests/Unit/Http/DatabaseHealthControllerTest.php.
        $app['config']->set('db-manager.swrr.health', ['pinned_query' => false]);
    }

    /**
     * A counter, not a random value: a test that reads the record it just wrote can
     * name the same file, and the numbers make a leftover file obvious.
     */
    private static function nextBoot(): int
    {
        static $boots = 0;

        return $boots++;
    }

    /**
     * One state directory per test process, created on first use and emptied when
     * the process ends, so a run leaves nothing behind and cannot read the records
     * the previous run left in the system temp directory.
     */
    private static function stateDir(): string
    {
        static $registered = false;

        $dir = sys_get_temp_dir() . '/swrr-tests-' . getmypid();

        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create the test state directory {$dir}");
        }

        if (!$registered) {
            $registered = true;

            register_shutdown_function(static function () use ($dir): void {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    @unlink($file);
                }

                @rmdir($dir);
            });
        }

        return $dir;
    }
}
