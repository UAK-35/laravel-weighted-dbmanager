<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Weighted;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Database\Weighted\LocalStateStore;
use Uak35\WeightedDbManager\Database\Weighted\RedisAtomicStateStore;

/**
 * The in-process fallback store.
 *
 * Its pool key must be built from swrr.key_prefix so it lines up with the Redis
 * store, and it must advertise that it is not coordinating anything — the whole
 * point of the degradation defect was that nothing said so.
 */
class LocalStateStoreTest extends TestCase
{
    public function test_it_defaults_to_the_swrr_prefix(): void
    {
        $this->assertSame('swrr:weighted:'.self::poolSignature(), $this->poolKey(new LocalStateStore()));
    }

    public function test_it_honours_the_configured_key_prefix(): void
    {
        $store = new LocalStateStore('tenant-42');

        $this->assertSame('tenant-42:weighted:'.self::poolSignature(), $this->poolKey($store));
    }

    public function test_its_key_matches_the_redis_store_for_the_same_pool(): void
    {
        $pool = ['10.0.0.2:5432', '10.0.0.1:5432'];
        $local = new LocalStateStore('shared');
        $redis = new RedisAtomicStateStore(keyPrefix: 'shared');

        $this->assertSame(
            $this->poolKeyFor($redis, $pool),
            $this->poolKeyFor($local, $pool),
        );
    }

    public function test_it_announces_that_it_is_uncoordinated(): void
    {
        $this->assertSame('local(in-process, uncoordinated)', (new LocalStateStore())->name());
        $this->assertTrue((new LocalStateStore())->isHealthy());
    }

    public function test_it_distributes_exactly_over_the_weight_sum(): void
    {
        $store = new LocalStateStore();

        $weights = [5, 1, 1];
        $picked = [];

        for ($i = 0; $i < array_sum($weights); $i++) {
            $picked[] = $store->next('weighted', ['a:5432', 'b:5432', 'c:5432'], $weights);
        }

        sort($picked);

        $this->assertSame([0, 0, 0, 0, 0, 1, 2], $picked);
    }

    public function test_it_reinitialises_when_the_pool_size_changes(): void
    {
        $store = new LocalStateStore();

        $store->next('weighted', ['a:5432', 'b:5432'], [1, 1]);

        // A third weight for the same pool signature: the stored state no longer
        // matches the pool size, so it must be reinitialised rather than reused.
        $this->assertSame(0, $store->next('weighted', ['a:5432', 'b:5432'], [5, 1, 1]));
    }

    public function test_reset_clears_state_for_one_pool_only(): void
    {
        $store = new LocalStateStore();
        $pool = ['a:5432', 'b:5432'];

        // Two draws leave the state at [-1, 1], so without the reset the next
        // draw would land on index 1.
        $store->next('weighted', $pool, [1, 1]);
        $store->reset('weighted', $pool);

        $this->assertSame(0, $store->next('weighted', $pool, [1, 1]));
    }

    private function poolKey(LocalStateStore $store): string
    {
        return $this->poolKeyFor($store, ['10.0.0.1:5432', '10.0.0.2:5432']);
    }

    /**
     * @param string[] $replicaKeys
     */
    private function poolKeyFor(object $store, array $replicaKeys): string
    {
        $method = new \ReflectionMethod($store, 'poolKey');

        return (string) $method->invoke($store, 'weighted', $replicaKeys);
    }

    private static function poolSignature(): string
    {
        return substr(md5('10.0.0.1:5432,10.0.0.2:5432'), 0, 12);
    }
}
