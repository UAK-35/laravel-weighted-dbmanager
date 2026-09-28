<?php

namespace Uak35\WeightedDbManager\Tests\Unit\Weighted;

use Uak35\WeightedDbManager\Database\Weighted\WeightResolver;
use Uak35\WeightedDbManager\Support\ReplicaMetadata;
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
 *   • Exclusions: which replicas are not in the pool, and which of the three reasons each is.
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

    public function test_a_disabled_replica_is_reported_as_an_exclusion(): void
    {
        // `weight: 0` is how a replica is taken out on purpose. The pool is shorter for it, and
        // before this the only way to know which replica went was to compare two lists by hand.
        $r = new WeightResolver();

        $resolved = $r->resolveWithExclusions('pgsql', [
            ['host' => '10.0.1.11', 'weight' => 0],
            ['host' => '10.0.1.12', 'weight' => 50],
        ], 3.0, 0.5, 'linear');

        $this->assertSame(['10.0.1.12:5432'], array_column($resolved['pool'], 'key'));
        $this->assertCount(1, $resolved['excluded']);
        $this->assertSame('10.0.1.11:5432', $resolved['excluded'][0]['key']);
        $this->assertSame(WeightResolver::EXCLUDED_DISABLED, $resolved['excluded'][0]['reason']);
        $this->assertSame(ReplicaMetadata::DISABLED, $resolved['excluded'][0]['detail']);
        $this->assertSame(0, $resolved['excluded'][0]['weight']);
        $this->assertSame('10.0.1.11', $resolved['excluded'][0]['config']['host']);
    }

    public function test_a_weight_it_cannot_read_is_reported_as_a_refusal_rather_than_a_disable(): void
    {
        // The two are different claims about the same reading — one is a value the package will
        // not interpret, the other is one it interprets and means — and the difference is the
        // whole reason the exclusion carries a reason at all.
        $r = new WeightResolver();

        $resolved = $r->resolveWithExclusions('pgsql', [
            ['host' => '10.0.1.11', 'weight' => 'heavy'],
            ['host' => '10.0.1.12', 'weight' => -5],
            ['host' => '10.0.1.13', 'weight' => 50],
        ], 3.0, 0.5, 'linear');

        $this->assertSame(['10.0.1.13:5432'], array_column($resolved['pool'], 'key'));
        $this->assertSame([
            '10.0.1.11:5432',
            '10.0.1.12:5432',
        ], array_column($resolved['excluded'], 'key'));

        foreach ($resolved['excluded'] as $exclusion) {
            $this->assertSame(WeightResolver::EXCLUDED_REFUSED, $exclusion['reason']);
        }

        $this->assertSame('weight is "heavy", which the resolver reads as 0', $resolved['excluded'][0]['detail']);
        $this->assertSame('weight is -5, which the resolver reads as 0', $resolved['excluded'][1]['detail']);
    }

    public function test_a_replica_the_health_filter_rejects_is_reported_with_the_reason_for_it(): void
    {
        // The third reason, and the one that is not a property of the configuration: the pool
        // holds this replica, and this *call* excluded it. A filter exclusion is therefore never
        // remembered — the next call without a filter sees the replica in the pool again.
        $r = new WeightResolver();

        $replicas = [
            ['host' => '10.0.1.11', 'weight' => 10],
            ['host' => '10.0.1.12', 'weight' => 20],
        ];

        $filtered = $r->resolveWithExclusions(
            'pgsql',
            $replicas,
            3.0,
            0.5,
            'linear',
            healthFilter: fn (array $replica) => ($replica['host'] ?? null) !== '10.0.1.12',
        );

        $this->assertSame(['10.0.1.11:5432'], array_column($filtered['pool'], 'key'));
        $this->assertCount(1, $filtered['excluded']);
        $this->assertSame('10.0.1.12:5432', $filtered['excluded'][0]['key']);
        $this->assertSame(WeightResolver::EXCLUDED_FILTERED, $filtered['excluded'][0]['reason']);
        $this->assertSame(20, $filtered['excluded'][0]['weight'], 'the weight it would have carried');

        $unfiltered = $r->resolveWithExclusions('pgsql', $replicas, 3.0, 0.5, 'linear');

        $this->assertCount(2, $unfiltered['pool']);
        $this->assertSame([], $unfiltered['excluded'], 'a filter exclusion is not remembered as a property of the installation');
    }

    public function test_the_pool_and_the_exclusions_partition_the_read_list(): void
    {
        // The property a report needs: every configured replica appears exactly once across the
        // two lists, so "the pool is one shorter than the read list" is answered by reading the
        // reason rather than by comparing lengths and guessing.
        $r = new WeightResolver();

        $replicas = [
            ['host' => '10.0.1.11', 'cpu_cores' => 16, 'ram_gb' => 64],
            ['host' => '10.0.1.12', 'weight' => 0],
            ['host' => '10.0.1.13', 'weight' => 'heavy'],
            ['host' => '10.0.1.14', 'weight' => 5],
        ];

        $resolved = $r->resolveWithExclusions('pgsql', $replicas, 3.0, 0.5, 'linear');

        $configured = array_map(
            static fn (array $replica): string => $replica['host'].':5432',
            $replicas,
        );

        $seen = [...array_column($resolved['pool'], 'key'), ...array_column($resolved['excluded'], 'key')];
        sort($seen);
        sort($configured);

        $this->assertSame($configured, $seen);
        $this->assertSame(2, count($resolved['pool']));
        $this->assertSame(2, count($resolved['excluded']));
    }

    public function test_the_exclusions_are_cached_with_the_pool_and_sorted_by_key(): void
    {
        $r = new WeightResolver();

        // Passed out of order: both lists are sorted by the replica's stable key, so a report does
        // not reorder between calls and read as if it had changed.
        $replicas = [
            ['host' => '10.0.1.13', 'weight' => 'heavy'],
            ['host' => '10.0.1.11', 'weight' => 0],
            ['host' => '10.0.1.12', 'weight' => 40],
        ];

        $first = $r->resolveWithExclusions('pgsql', $replicas, 3.0, 0.5, 'linear');
        $second = $r->resolveWithExclusions('pgsql', $replicas, 3.0, 0.5, 'linear');

        $this->assertSame(['10.0.1.11:5432', '10.0.1.13:5432'], array_column($first['excluded'], 'key'));
        $this->assertSame($first['excluded'], $second['excluded']);
        $this->assertSame(1, $r->size(), 'the exclusions come out of the same cached resolution as the pool');
    }

    public function test_resolve_is_the_pool_alone(): void
    {
        // The read path asks for the pool on every read; the report is for the surfaces that
        // explain it. Same cached computation either way — `resolve()` is a read of one field.
        $r = new WeightResolver();

        $replicas = [
            ['host' => '10.0.1.11', 'weight' => 0],
            ['host' => '10.0.1.12', 'weight' => 40],
        ];

        $this->assertSame(
            $r->resolve('pgsql', $replicas, 3.0, 0.5, 'linear'),
            $r->resolveWithExclusions('pgsql', $replicas, 3.0, 0.5, 'linear')['pool'],
        );
        $this->assertSame(1, $r->size());
    }

    /**
     * The property the floors exist for: the arithmetic and the classification are one boundary.
     *
     * Declaring them once is not enough on its own — a constant can be ignored, so a `max()`
     * argument that stopped matching `ReplicaMetadata::CORES_FLOOR` would leave every report
     * describing a boundary routing does not have. That was the documented hole in this rule ("no
     * test can catch without reading the resolver"), and driving the resolver to each constant is
     * what closes it: both halves are asked about `floor - 1` and about `floor`, and they have to
     * answer the same way.
     */
    public function test_the_floors_the_classifier_reads_at_are_the_floors_the_resolver_clamps_at(): void
    {
        $r = new WeightResolver();

        // A weight *on* the floor is the disable — not in the pool, and not a refusal. A weight under
        // it is the same absence for a different reason, which is the distinction the reasons carry.
        $underWeight = ['host' => 'weight.example', 'weight' => ReplicaMetadata::WEIGHT_FLOOR - 1];
        $atWeight = ['host' => 'weight.example', 'weight' => ReplicaMetadata::WEIGHT_FLOOR];

        $this->assertSame([], $r->resolve('pgsql', [$underWeight], 3.0, 0.5, 'linear'));
        $this->assertSame([], $r->resolve('pgsql', [$atWeight], 3.0, 0.5, 'linear'));
        $this->assertCount(1, $r->resolve('pgsql', [['host' => 'weight.example', 'weight' => ReplicaMetadata::WEIGHT_FLOOR + 1]], 3.0, 0.5, 'linear'));
        $this->assertSame([], ReplicaMetadata::refusals($atWeight), 'the floor is the disable the read list means');
        $this->assertSame(['weight'], array_column(ReplicaMetadata::refusals($underWeight), 'setting'));

        // A core count under the floor is read as the floor, and the weight is the one that many
        // cores produce — 1 core × 3.0 + 0 GB × 0.5 = 3. Written out rather than compared with the
        // replica at the floor, because a clamp that moved to 2 would move *both* of those and leave
        // an equality between them passing.
        $coresAtFloor = ['host' => 'cores.example', 'cpu_cores' => ReplicaMetadata::CORES_FLOOR, 'ram_gb' => ReplicaMetadata::RAM_FLOOR];
        $coresUnderFloor = ['host' => 'cores.example', 'cpu_cores' => ReplicaMetadata::CORES_FLOOR - 1, 'ram_gb' => ReplicaMetadata::RAM_FLOOR];

        $this->assertSame(3, $r->resolve('pgsql', [$coresAtFloor], 3.0, 0.5, 'linear')[0]['weight']);
        $this->assertSame(3, $r->resolve('pgsql', [$coresUnderFloor], 3.0, 0.5, 'linear')[0]['weight']);
        $this->assertSame([], ReplicaMetadata::refusals($coresAtFloor));
        $this->assertSame(['cpu_cores'], array_column(ReplicaMetadata::refusals($coresUnderFloor), 'setting'));

        // And memory, where the floor is a size rather than a switch: 4 cores × 3.0 + 0 GB × 0.5 = 12,
        // and a floor that had moved off zero would show up here as the memory term.
        $ramAtFloor = ['host' => 'ram.example', 'cpu_cores' => 4, 'ram_gb' => ReplicaMetadata::RAM_FLOOR];
        $ramUnderFloor = ['host' => 'ram.example', 'cpu_cores' => 4, 'ram_gb' => ReplicaMetadata::RAM_FLOOR - 1];

        $this->assertSame(12, $r->resolve('pgsql', [$ramAtFloor], 3.0, 0.5, 'linear')[0]['weight']);
        $this->assertSame(12, $r->resolve('pgsql', [$ramUnderFloor], 3.0, 0.5, 'linear')[0]['weight']);
        $this->assertSame([], ReplicaMetadata::refusals($ramAtFloor));
        $this->assertSame(['ram_gb'], array_column(ReplicaMetadata::refusals($ramUnderFloor), 'setting'));
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
