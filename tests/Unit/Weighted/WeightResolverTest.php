<?php

namespace Uak35\WeightedDbManager\Tests\Unit\Weighted;

use Uak35\WeightedDbManager\Database\Weighted\WeightResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WeightResolver:
 *   • Linear formula matches the documented v1/v2 behaviour.
 *   • Diminishing formula produces a compressed weight distribution.
 *   • Explicit 'weight' key overrides both formulas.
 *   • weight=0 disables a replica.
 *   • Missing metadata → weight=1 (equal treatment).
 *   • Sort order is by host:port and is stable across calls.
 *   • Cache: same input returns same pool without recomputation.
 */
class WeightResolverTest extends TestCase
{
    public function test_linear_formula_matches_v2(): void
    {
        $r = new WeightResolver();

        $pool = $r->resolve('pgsql', [
            ['host' => '10.0.1.11', 'cpu_cores' => 32, 'ram_gb' => 128],
            ['host' => '10.0.1.12', 'cpu_cores' => 16, 'ram_gb' => 64],
            ['host' => '10.0.1.13', 'cpu_cores' => 8, 'ram_gb' => 32],
        ], 3.0, 0.5, 'linear');

        $byHost = $this->indexByHost($pool);

        $this->assertSame(160, $byHost['10.0.1.11']['weight']);
        $this->assertSame(80, $byHost['10.0.1.12']['weight']);
        $this->assertSame(40, $byHost['10.0.1.13']['weight']);
    }

    public function test_diminishing_formula_compresses_weights(): void
    {
        $r = new WeightResolver();

        $pool = $r->resolve('pgsql', [
            ['host' => '10.0.1.11', 'cpu_cores' => 32, 'ram_gb' => 128],
            ['host' => '10.0.1.12', 'cpu_cores' => 16, 'ram_gb' => 64],
            ['host' => '10.0.1.13', 'cpu_cores' => 8, 'ram_gb' => 32],
        ], 3.0, 0.5, 'diminishing');

        $byHost = $this->indexByHost($pool);

        // Weights must be in the same order (biggest replica still biggest)
        // but compressed — diminishing should NOT exceed linear values.
        $this->assertGreaterThan($byHost['10.0.1.12']['weight'], $byHost['10.0.1.11']['weight']);
        $this->assertGreaterThan($byHost['10.0.1.13']['weight'], $byHost['10.0.1.12']['weight']);

        $this->assertLessThanOrEqual(160, $byHost['10.0.1.11']['weight']);
        $this->assertLessThanOrEqual(80, $byHost['10.0.1.12']['weight']);
        $this->assertLessThanOrEqual(40, $byHost['10.0.1.13']['weight']);
    }

    public function test_explicit_weight_overrides_formula(): void
    {
        $r = new WeightResolver();

        $pool = $r->resolve('pgsql', [
            ['host' => '10.0.1.11', 'cpu_cores' => 32, 'ram_gb' => 128],   // → 160
            ['host' => '10.0.1.12', 'weight' => 5],                        // → 5 (explicit)
            ['host' => '10.0.1.13', 'cpu_cores' => 8, 'ram_gb' => 32],    // → 40
        ], 3.0, 0.5, 'linear');

        $byHost = $this->indexByHost($pool);

        $this->assertSame(160, $byHost['10.0.1.11']['weight']);
        $this->assertSame(5, $byHost['10.0.1.12']['weight']);
        $this->assertSame(40, $byHost['10.0.1.13']['weight']);
    }

    public function test_zero_weight_disables_replica(): void
    {
        $r = new WeightResolver();

        $pool = $r->resolve('pgsql', [
            ['host' => '10.0.1.11', 'weight' => 0],
            ['host' => '10.0.1.12', 'weight' => 50],
        ], 3.0, 0.5, 'linear');

        $this->assertCount(1, $pool);
        $this->assertSame('10.0.1.12', $pool[0]['config']['host']);
    }

    public function test_missing_metadata_defaults_to_weight_one(): void
    {
        $r = new WeightResolver();

        $pool = $r->resolve('pgsql', [
            ['host' => '10.0.1.11'],
            ['host' => '10.0.1.12'],
            ['host' => '10.0.1.13'],
        ], 3.0, 0.5, 'linear');

        $this->assertCount(3, $pool);
        foreach ($pool as $entry) {
            $this->assertSame(1, $entry['weight']);
        }
    }

    public function test_pool_is_sorted_by_host_stably(): void
    {
        $r = new WeightResolver();

        // Pass replicas in a non-sorted order.
        $pool1 = $r->resolve('pgsql', [
            ['host' => '10.0.1.13', 'weight' => 40],
            ['host' => '10.0.1.11', 'weight' => 160],
            ['host' => '10.0.1.12', 'weight' => 80],
        ], 3.0, 0.5, 'linear');

        // And again in a different order — should come out the same.
        $pool2 = $r->resolve('pgsql', [
            ['host' => '10.0.1.12', 'weight' => 80],
            ['host' => '10.0.1.13', 'weight' => 40],
            ['host' => '10.0.1.11', 'weight' => 160],
        ], 3.0, 0.5, 'linear');

        $keys1 = array_column($pool1, 'key');
        $keys2 = array_column($pool2, 'key');

        $this->assertSame($keys1, $keys2);
        $this->assertSame(['10.0.1.11:5432', '10.0.1.12:5432', '10.0.1.13:5432'], $keys1);
    }

    public function test_signature_cache_hits_for_same_input(): void
    {
        $r = new WeightResolver();

        $replicas = [
            ['host' => '10.0.1.11', 'cpu_cores' => 32, 'ram_gb' => 128],
            ['host' => '10.0.1.12', 'cpu_cores' => 16, 'ram_gb' => 64],
        ];

        $r->resolve('pgsql', $replicas, 3.0, 0.5, 'linear');
        $r->resolve('pgsql', $replicas, 3.0, 0.5, 'linear');
        $r->resolve('pgsql', $replicas, 3.0, 0.5, 'linear');

        $this->assertSame(1, $r->size());
    }

    public function test_signature_changes_with_pool_size(): void
    {
        $r = new WeightResolver();

        $r->resolve('pgsql', [
            ['host' => '10.0.1.11', 'cpu_cores' => 32, 'ram_gb' => 128],
            ['host' => '10.0.1.12', 'cpu_cores' => 16, 'ram_gb' => 64],
        ], 3.0, 0.5, 'linear');

        $r->resolve('pgsql', [
            ['host' => '10.0.1.11', 'cpu_cores' => 32, 'ram_gb' => 128],
            ['host' => '10.0.1.12', 'cpu_cores' => 16, 'ram_gb' => 64],
            ['host' => '10.0.1.13', 'cpu_cores' => 8, 'ram_gb' => 32],   // new replica
        ], 3.0, 0.5, 'linear');

        $this->assertSame(2, $r->size());
    }

    public function test_signature_changes_with_formula(): void
    {
        $r = new WeightResolver();

        $replicas = [
            ['host' => '10.0.1.11', 'cpu_cores' => 32, 'ram_gb' => 128],
        ];

        $r->resolve('pgsql', $replicas, 3.0, 0.5, 'linear');
        $r->resolve('pgsql', $replicas, 3.0, 0.5, 'diminishing');

        $this->assertSame(2, $r->size());
    }

    public function test_health_filter_excludes_replicas(): void
    {
        $r = new WeightResolver();

        $replicas = [
            ['host' => '10.0.1.11', 'cpu_cores' => 32, 'ram_gb' => 128],
            ['host' => '10.0.1.12', 'cpu_cores' => 16, 'ram_gb' => 64],
        ];

        $pool = $r->resolve(
            'pgsql',
            $replicas,
            3.0,
            0.5,
            'linear',
            healthFilter: fn (array $r) => ($r['host'] ?? null) !== '10.0.1.12',
        );

        $this->assertCount(1, $pool);
        $this->assertSame('10.0.1.11', $pool[0]['config']['host']);
    }

    public function test_flush_clears_cache(): void
    {
        $r = new WeightResolver();

        $r->resolve('pgsql', [['host' => '10.0.1.11']], 3.0, 0.5, 'linear');
        $this->assertSame(1, $r->size());

        $r->flush();
        $this->assertSame(0, $r->size());
    }

    /**
     * Helper: index pool by host:port key.
     */
    private function indexByHost(array $pool): array
    {
        $out = [];
        foreach ($pool as $entry) {
            $out[$entry['config']['host']] = $entry;
        }

        return $out;
    }
}
