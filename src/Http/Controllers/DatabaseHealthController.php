<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Http\Controllers;

use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DatabaseHealthController
 *
 * Exposes /health/db that surfaces:
 *   • Per-connection replica weights, share %, health
 *   • Active SWRR formula
 *   • State-store health (Redis up/down, fallback in use)
 *   • pgcat applicability — the same snapshot db:replica-status and
 *     db:pgcat-flip --status read, so the three cannot disagree: why flipping
 *     is armed or inactive (driver gate included), plus a warning when it is
 *     switched on but cannot act
 *   • the boot audit's standing findings — the settings that read as on but
 *     cannot act, with the sentence the boot log would have carried, so a
 *     misconfiguration is visible on a dashboard instead of only in a log
 *
 * Register in routes/api.php or routes/web.php:
 *
 *   Route::get('/health/db', [DatabaseHealthController::class, 'index'])
 *        ->middleware('auth.basic');     // protect it!
 *
 * The package extends Illuminate\Routing\Controller rather than the host
 * application's App\Http\Controllers\Controller — index() needs neither the
 * AuthorizesRequests, DispatchesJobs nor ValidatesRequests traits.
 */
class DatabaseHealthController extends \Illuminate\Routing\Controller
{
    /**
     * The flipper and the audit are optional so the controller still resolves on an app
     * whose provider is not registered. They are then null, and the payload carries
     * "pgcat": null and "audit": {"available": false} instead of failing to build the
     * route. Both have a default for that reason: the container falls back to it when a
     * dependency cannot be built.
     */
    public function __construct(
        private readonly ?PgcatConfigFlipper $pgcat = null,
        private readonly ?BootAudit $audit = null,
    ) {
    }

    public function index(): JsonResponse
    {
        $manager = DB::getFacadeRoot();

        if (!$manager instanceof WeightedDatabaseManager) {
            return response()->json([
                'status' => 'misconfigured',
                'error' => 'WeightedDatabaseManager is not registered. Check WeightedDatabaseServiceProvider.',
            ], 500);
        }

        $connectionNames = self::connectionNames();
        $replicas = [];
        $errors = [];

        foreach ($connectionNames as $name) {
            // Probe each replica with getPdo() to surface live errors.
            try {
                $config = config("database.connections.{$name}");

                if (is_array($config) && !empty($config['read'])) {
                    // Test the first replica at minimum.
                    DB::connection($name)->getPdo();
                }
            } catch (Throwable $e) {
                $errors[$name] = $e->getMessage();
                $this->markFirstReplicaFailed($manager, $name);
            }

            $replicas[$name] = $manager->healthSummary($name);
        }

        // pgcat being off is not ill health — on a MySQL connection there is
        // simply nothing to flip — so it does not feed $allHealthy.
        $allHealthy = empty($errors) && $this->allReplicasHealthy($replicas);

        return response()->json([
            'status' => $allHealthy ? 'ok' : 'degraded',
            'replicas' => $replicas,
            'pgcat' => $this->pgcat?->healthSummary(),
            'audit' => $this->audit(),
            'errors' => $errors,
        ], $allHealthy ? 200 : 503);
    }

    /**
     * The settings that read as on but cannot act, as the boot audit remembers them.
     *
     * This is deliberately *not* part of the status code. The findings describe a
     * configuration that cannot do what it claims, which is not the same claim as
     * "this database is unreachable right now", and an endpoint a load balancer polls
     * should keep meaning the second one. Reporting them here is what makes them
     * visible on a dashboard at all: the alternative is a boot log — one line per
     * process — and a warning nobody ever greps for.
     *
     * The block itself is `BootAudit::reported()`, which the status command renders too: it is
     * the one place either surface decides whether there is a record to read, so a payload and
     * a terminal cannot disagree about that — the field meanings, including the two ways
     * `available` can be false, are documented there rather than here. This method is
     * the endpoint's half: it embeds the block and leaves `status` alone.
     *
     * @return array{available: bool, count: int, severity: string, counts: array{error: int, warning: int}, oldest: string|null, findings: list<array<string, mixed>>, error: string|null}
     */
    private function audit(): array
    {
        return BootAudit::reported($this->audit);
    }

    private function markFirstReplicaFailed(WeightedDatabaseManager $manager, string $connection): void
    {
        $config = config("database.connections.{$connection}");

        if (!is_array($config)) {
            return;
        }

        $read = $config['read'] ?? [];
        $first = is_array($read) ? ($read[0] ?? null) : null;

        if (!is_array($first)) {
            return;
        }

        $host = $first['host'] ?? null;

        if (is_array($host)) {
            $host = $host[0] ?? null;
        }

        if (is_string($host) && $host !== '') {
            $manager->markReplicaFailed($host, ConfigValue::int($first['port'] ?? null, 5432), 'health probe');
        }
    }

    /**
     * Every connection name in the host app, as strings — `array_keys()` yields
     * int|string and DB::connection() takes a string.
     *
     * @return list<string>
     */
    private static function connectionNames(): array
    {
        $connections = config('database.connections');

        if (!is_array($connections)) {
            return [];
        }

        return array_map(static fn (int|string $name): string => (string) $name, array_keys($connections));
    }

    /**
     * @param array<string, array<string, mixed>> $summaries One healthSummary() result per connection.
     */
    private function allReplicasHealthy(array $summaries): bool
    {
        foreach ($summaries as $summary) {
            $replicas = $summary['replicas'] ?? [];

            foreach (is_array($replicas) ? $replicas : [] as $replica) {
                if (is_array($replica) && !($replica['healthy'] ?? false)) {
                    return false;
                }
            }

            if (!($summary['store_healthy'] ?? true)) {
                return false;
            }
        }

        return true;
    }
}
