<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Database\Weighted\SingleHostProbe;
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
 *
 * JSON
 * ----
 * `--json` writes one object and nothing else — the same envelope `db:pgcat-flip` writes, so a
 * job that reads a flip's report reads this one the same way — and changes nothing about the
 * run: the same probes open, the same marks reach the health monitor, the same code is
 * returned. The per-replica rows `-v` prints are the object's `replicas` in that mode, so
 * `--json -v` is not two channels: the detail is a field, and the whole of stdout is the
 * report.
 *
 * Each route has a `kind` of its own, because the commands' two vocabularies agree on the word
 * for a shared meaning: `unbound` is "the container has no weighted manager", the same repair
 * `db:pgcat-flip` names `unbound` when its own dependency is missing. The five kinds are the
 * five cases the README's exit table documents, one to one — a case that exits differently has
 * to be a different verdict, or `kind` would be a word a job cannot branch on.
 */
class DbProbeReplicas extends Command
{
    /** At least one replica answered — a partial sweep is still this verdict. */
    public const KIND_ANSWERED = 'answered';

    /** The connection has no `read` list, so nothing was asked of it. */
    public const KIND_NO_READ_LIST = 'no_read_list';

    /** The `read` list holds no replica map, so nothing could be probed. */
    public const KIND_NO_REPLICA_MAPS = 'no_replica_maps';

    /** Every replica failed: the sweep reached nothing. */
    public const KIND_NONE_ANSWERED = 'none_answered';

    /** The container has no weighted manager, so there was no sweep. */
    public const KIND_UNBOUND = 'unbound';

    /**
     * The evidence a sweep's report carries, and the value an absent one takes.
     *
     * `counts` is a map of zeros rather than `null` for the routes that swept nothing, which is
     * the same rule the doctor's object follows with its own counts: an absent count is zero, and
     * a job should be able to read `.counts.failed` on every route without asking whether the
     * field is there. `replicas` is one entry per replica the sweep attempted, in the order the
     * read list gives them, with the reason a failed one failed — the half of this command that
     * is otherwise invisible, because the probe's own connection is purged after every attempt.
     */
    private const EVIDENCE = [
        'connection' => null,
        'counts' => ['probed' => 0, 'answered' => 0, 'failed' => 0],
        'replicas' => [],
    ];

    protected $signature = 'db:probe-replicas
                            {connection=pgsql : Database connection name}
                            {--json : Print one JSON object — the verdict, the exit code and the per-replica results — instead of the rendered lines}';

    protected $description = 'Probe every read replica with SELECT 1; mark failures';

    public function handle(): int
    {
        $argument = $this->argument('connection');
        $connection = is_string($argument) ? $argument : 'pgsql';

        $manager = $this->manager();

        if ($manager === null) {
            return $this->unbound($connection);
        }

        $config = $manager->connectionConfig($connection);
        $replicas = $config['read'] ?? [];

        if (!is_array($replicas) || $replicas === []) {
            return $this->noReadList($connection);
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

        /** @var list<array{host: string, port: int, healthy: bool, error: string|null}> $swept */
        $swept = [];

        foreach ($replicas as $rawReplica) {
            if (!is_array($rawReplica)) {
                continue;
            }

            $replica = ConfigValue::assoc($rawReplica);

            [$host, $port] = SingleHostProbe::address($replica, $defaultPort);

            try {
                // The probe itself — one host, its own connection, a bare `SELECT 1`, purged on the
                // way out — lives in SingleHostProbe, where the reader-window flip's readiness gate
                // asks the same question of the same target.
                SingleHostProbe::run($manager, $config, $replica);

                $manager->markReplicaHealthy($host, $port);
                $okCount++;
                $swept[] = ['host' => $host, 'port' => $port, 'healthy' => true, 'error' => null];
                $this->verbose("  ✓ {$host}:{$port}");
            } catch (Throwable $e) {
                $manager->markReplicaFailed($host, $port, 'active probe');
                $failCount++;
                $swept[] = ['host' => $host, 'port' => $port, 'healthy' => false, 'error' => $e->getMessage()];
                $this->verbose("  ✗ {$host}:{$port}  ({$e->getMessage()})");
            }
        }

        $counts = ['probed' => $probeable, 'answered' => $okCount, 'failed' => $failCount];

        // The rows above say what happened; this line says what the exit code means, the way
        // db:doctor's closing line does — an operator (or a scheduler) reading a non-zero
        // code should not have to work out which of the two ways the sweep failed.
        if ($okCount === 0) {
            $reason = $probeable > 0
                ? sprintf(
                    'No replica answered: %d probed, all failed — nothing was marked healthy.',
                    $probeable,
                )
                : sprintf(
                    'Nothing could be probed: the read list on [%s] holds no replica array, so nothing was marked healthy.',
                    $connection,
                );

            $kind = $probeable > 0 ? self::KIND_NONE_ANSWERED : self::KIND_NO_REPLICA_MAPS;

            if ($this->option('json')) {
                return $this->report($kind, $connection, $reason, self::FAILURE, $counts, $swept);
            }

            $this->line("<fg=red>{$reason}</>");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            return $this->report(self::KIND_ANSWERED, $connection, null, self::SUCCESS, $counts, $swept);
        }

        $this->info("Probed {$connection}: {$okCount} healthy, {$failCount} failed");

        return self::SUCCESS;
    }

    /**
     * The three routes that never swept: nothing to report but the verdict and why.
     *
     * Each one is written once because each one has two channels — the line an operator reads and
     * the object a job reads — and a route that wrote its own pair would be the place the two
     * could disagree. The evidence is empty in all three, which the envelope fills with the
     * declared defaults: no replicas were attempted, so there is nothing per-replica to carry.
     */
    private function unbound(string $connection): int
    {
        $reason = 'WeightedDatabaseManager is not registered.';

        if ($this->option('json')) {
            return $this->report(self::KIND_UNBOUND, $connection, $reason, self::FAILURE);
        }

        $this->error($reason);

        return self::FAILURE;
    }

    /**
     * A connection with no read list. Not a failure — nothing was asked of it — and the caller that
     * schedules this command should not be woken up by a connection that routes no reads at all.
     */
    private function noReadList(string $connection): int
    {
        $reason = "No replicas configured for [{$connection}].";

        if ($this->option('json')) {
            return $this->report(self::KIND_NO_READ_LIST, $connection, $reason, self::SUCCESS);
        }

        $this->warn($reason);

        return self::SUCCESS;
    }

    /**
     * One JSON object on stdout, and nothing else — written by `JsonEnvelope`, the same envelope
     * `db:pgcat-flip` and `db:replica-status` write.
     *
     * `reason` carries the sentence the rendered run would have printed for a route that swept
     * nothing, and is `null` for a sweep: the counts and the rows are the answer there, and a
     * sentence beside them would be the summary the object does not need.
     *
     * @param array{probed: int, answered: int, failed: int} $counts
     * @param list<array{host: string, port: int, healthy: bool, error: string|null}> $replicas
     */
    private function report(
        string $kind,
        string $connection,
        ?string $reason,
        int $exitCode,
        array $counts = ['probed' => 0, 'answered' => 0, 'failed' => 0],
        array $replicas = [],
    ): int {
        return JsonEnvelope::write($this->output, 'db:probe-replicas', self::EVIDENCE, [
            'kind' => $kind,
            'reason' => $reason,
            'connection' => $connection,
            'counts' => $counts,
            'replicas' => $replicas,
        ], $exitCode);
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
     * Print only when -v is set, and never in the JSON mode: the row a verbose run prints is that
     * mode's `replicas`, and a line beside the object would make the whole of stdout two things.
     */
    private function verbose(string $line): void
    {
        if ($this->option('json')) {
            return;
        }

        if ($this->getOutput()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->line($line);
        }
    }
}
