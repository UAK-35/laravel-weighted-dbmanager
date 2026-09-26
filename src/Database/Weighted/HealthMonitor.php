<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

use Illuminate\Support\Facades\Log;

/**
 * Replica health tracker with exponential cool-down.
 *
 * Why exponential cool-down?
 * --------------------------
 * v1 and v2 both use a fixed cool-down window (30s / 60s). That's wrong for
 * real failures: a replica that just came back up is still warming up its
 * connection pool, replaying its WAL, recovering from network blips, etc.
 * Hitting it again immediately just makes things worse.
 *
 * Strategy:
 *   • First failure      → cool-down 30 s.
 *   • Second failure     → cool-down 60 s.
 *   • Third+             → cool-down doubles up to 30 minutes.
 *
 * After cool-down elapses, the replica is retried (passive recovery). On
 * subsequent success the failure count resets.
 *
 * Active probing (scheduled) also writes through this monitor — a probe
 * failure has the same effect as a passive one.
 */
final class HealthMonitor
{
    /** @var array<string, array{failed_at:int, count:int, cooling_until:int}> */
    private array $state = [];

    /** Base cool-down in seconds. */
    private int $baseCooldown = 30;

    /** Maximum cool-down in seconds. */
    private int $maxCooldown = 1800;   // 30 min

    /** Clock source — overridable in tests. */
    private int $clock;

    public function __construct(?int $clock = null)
    {
        $this->clock = $clock ?? time();
    }

    /**
     * Mark a replica as failed. The replica is excluded from the routing pool
     * for the returned cool-down duration.
     */
    public function markFailed(string $key, ?string $reason = null): int
    {
        $now = $this->clock;

        $prev = $this->state[$key] ?? ['count' => 0];

        $count = $prev['count'] + 1;
        $cooldown = $this->computeCooldown($count);
        $coolingUntil = $now + $cooldown;

        $this->state[$key] = [
            'failed_at' => $now,
            'count' => $count,
            'cooling_until' => $coolingUntil,
        ];

        Log::warning('[WeightedDB] Replica marked failed.', [
            'key' => $key,
            'count' => $count,
            'cooldown' => $cooldown . 's',
            'reason' => $reason,
        ]);

        return $cooldown;
    }

    /**
     * Mark a replica as healthy — clears any failure state.
     */
    public function markHealthy(string $key): void
    {
        if (isset($this->state[$key])) {
            unset($this->state[$key]);
        }
    }

    /**
     * True if the replica is currently in cool-down.
     */
    public function isFailing(string $key): bool
    {
        if (!isset($this->state[$key])) {
            return false;
        }

        $entry = $this->state[$key];

        if ($this->clock >= $entry['cooling_until']) {
            // Cool-down elapsed; treat as healthy and let the next failure re-arm.
            unset($this->state[$key]);

            return false;
        }

        return true;
    }

    /**
     * Snapshot of every replica's current failure state.
     *
     * @return array<string, array{count:int, cooling_until:int, healthy:bool}>
     */
    public function snapshot(): array
    {
        $out = [];

        foreach ($this->state as $key => $entry) {
            $out[$key] = [
                'count' => $entry['count'],
                'cooling_until' => $entry['cooling_until'],
                'healthy' => !$this->isFailing($key),
            ];
        }

        return $out;
    }

    /**
     * Clear all failure state. Used when every replica is unhealthy and we
     * reset to retry.
     */
    public function resetAll(): void
    {
        $this->state = [];
    }

    private function computeCooldown(int $count): int
    {
        // 30, 60, 120, 240, ..., capped at maxCooldown.
        $cooldown = $this->baseCooldown * (2 ** max(0, $count - 1));

        return min($this->maxCooldown, $cooldown);
    }
}
