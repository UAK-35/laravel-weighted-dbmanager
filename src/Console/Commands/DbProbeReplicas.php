<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Support\ConfigValue;

/**
 * php artisan db:probe-replicas {connection=pgsql}
 *
 * Actively probes every read replica by issuing `SELECT 1` and marks failed
 * replicas through the HealthMonitor. Schedule it every 30 s:
 *
 *   // routes/console.php (Laravel 11) or App\Console\Kernel (Laravel 10)
 *   Schedule::command('db:probe-replicas')->everyThirtySeconds()
 *            ->withoutOverlapping()
 *            ->runInBackground();
 *
 * Output is suppressed by default; use -v for per-replica detail.
 *
 * The manager is resolved from the container rather than through the DB facade,
 * and each probe runs on a throwaway connection created straight from the
 * replica's own config — no pooled read list, so the probe cannot be routed
 * back through the weighted pool it is trying to measure.
 *
 * EXIT CODE
 * ---------
 * 0 when the sweep ran and at least one replica answered, 1 when it could not
 * reach a single one, and 1 when the manager is not the weighted one so there was
 * no sweep at all. A *partial* failure is deliberately still 0: recording a replica
 * that is down is what this command is for, and the health monitor — not the exit
 * code — is that record. A run that reached nothing is the different case: it did
 * not do the job it was scheduled for, and a scheduled job's exit code is the only
 * thing a scheduler reports.
 */
class DbProbeReplicas extends Command
{
    /** Throwaway connection name used for probes. */
    private const PROBE_CONNECTION = '__weighted_db_probe';

    protected $signature = 'db:probe-replicas {connection=pgsql : Database connection name}';

    protected $description = 'Probe every read replica with SELECT 1; mark failures';

    public function handle(): int
    {
        $argument = $this->argument('connection');
        $connection = is_string($argument) ? $argument : 'pgsql';

        $manager = $this->manager();

        if ($manager === null) {
            $this->error('WeightedDatabaseManager is not registered.');

            return self::FAILURE;
        }

        $config = $manager->connectionConfig($connection);
        $replicas = $config['read'] ?? [];

        if (!is_array($replicas) || $replicas === []) {
            $this->warn("No replicas configured for [{$connection}].");

            return self::SUCCESS;
        }

        // Laravel reads a `read` that is not a list as one config map — it is the map the
        // connector is handed, not a list of one — so the probe has to see it the same way.
        // Routing already does: readConfigFor() passes a non-list `read` straight through to
        // the connector. Without this, an installation whose single replica is serving reads
        // perfectly would exit 1 on every scheduled run, and an exit code that is always
        // non-zero is an exit code nobody reads.
        if (!array_is_list($replicas)) {
            $replicas = [$replicas];
        }

        $probeable = count(array_filter($replicas, static fn (mixed $entry): bool => is_array($entry)));

        $defaultPort = ConfigValue::int($config['port'] ?? null, 5432);
        $okCount = 0;
        $failCount = 0;

        foreach ($replicas as $rawReplica) {
            if (!is_array($rawReplica)) {
                continue;
            }

            $replica = ConfigValue::assoc($rawReplica);

            [$host, $port] = $this->replicaAddress($replica, $defaultPort);

            try {
                $this->probe($manager, $config, $replica);

                $manager->markReplicaHealthy($host, $port);
                $okCount++;
                $this->verbose("  ✓ {$host}:{$port}");
            } catch (Throwable $e) {
                $manager->markReplicaFailed($host, $port, 'active probe');
                $failCount++;
                $this->verbose("  ✗ {$host}:{$port}  ({$e->getMessage()})");
            } finally {
                // Never leave the throwaway connection cached on the manager.
                $manager->purge(self::PROBE_CONNECTION);
            }
        }

        $this->info("Probed {$connection}: {$okCount} healthy, {$failCount} failed");

        // The rows above say what happened; this line says what the exit code means, the way
        // db:doctor's closing line does — an operator (or a scheduler) reading a non-zero
        // code should not have to work out which of the two ways the sweep failed.
        if ($okCount === 0) {
            $this->line($probeable > 0
                ? sprintf(
                    '<fg=red>No replica answered: %d probed, all failed — nothing was marked healthy.</>',
                    $probeable,
                )
                : sprintf(
                    '<fg=red>Nothing could be probed: the read list on [%s] holds no replica array, so nothing was marked healthy.</>',
                    $connection,
                ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the weighted manager — WeightedDatabaseServiceProvider binds `db`
     * to it.
     */
    private function manager(): ?WeightedDatabaseManager
    {
        $manager = $this->laravel->make('db');

        return $manager instanceof WeightedDatabaseManager ? $manager : null;
    }

    /**
     * Open one connection straight to a single replica and run SELECT 1.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $replica
     */
    private function probe(WeightedDatabaseManager $manager, array $base, array $replica): void
    {
        $manager->connectUsing(
            self::PROBE_CONNECTION,
            $this->buildProbeConfig($base, $replica),
            true,
        );

        $manager->connection(self::PROBE_CONNECTION)->select('SELECT 1');
    }

    /**
     * Build a single-host config for the probe. The pooled read/write lists are
     * dropped so Laravel connects straight to the replica under test.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $replica
     * @return array<string, mixed>
     */
    private function buildProbeConfig(array $base, array $replica): array
    {
        return ConfigValue::assoc(array_merge(Arr::except($base, ['read', 'write']), $replica));
    }

    /**
     * The host:port a replica is tracked under, matching WeightResolver's key
     * format so probe results and routing agree.
     *
     * @param array<string, mixed> $replica
     * @return array{0: string, 1: int}
     */
    private function replicaAddress(array $replica, int $defaultPort): array
    {
        $host = $replica['host'] ?? null;

        if (is_array($host)) {
            $host = $host[0] ?? 'unknown';
        }

        return [
            ConfigValue::string($host, 'unknown'),
            ConfigValue::int($replica['port'] ?? null, $defaultPort),
        ];
    }

    /**
     * Print only when -v is set.
     */
    private function verbose(string $line): void
    {
        if ($this->getOutput()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->line($line);
        }
    }
}
