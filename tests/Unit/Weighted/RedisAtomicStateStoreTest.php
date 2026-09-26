<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Weighted;

use Illuminate\Container\Container;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use stdClass;
use Uak35\WeightedDbManager\Database\Weighted\RedisAtomicStateStore;
use Uak35\WeightedDbManager\Exceptions\RedisUnavailable;
use Uak35\WeightedDbManager\Tests\TestCase;

final class RedisAtomicStateStoreTest extends TestCase
{
    /** The pool every case here steps through. */
    private const POOL = ['10.1.0.1:5432'];

    protected function setUp(): void
    {
        parent::setUp();

        self::resetUnavailableReport();
    }

    public function test_a_pick_before_redis_is_bound_falls_back_and_stays_healthy(): void
    {
        $container = new Container();
        $store = new RedisAtomicStateStore(app: $container, keyPrefix: 'shared');

        try {
            $store->next('pgsql', self::POOL, [1]);

            $this->fail('A store with no Redis behind it must not answer.');
        } catch (RedisUnavailable $e) {
            $this->assertStringContainsString('no "redis" binding', $e->getMessage());
        }

        // A connection is built while providers register, which can be before the
        // application binds `redis`. That pick falls back like any other failure, but
        // it must not count toward the unhealthy threshold — a worker would otherwise
        // abandon Redis for the rest of its life over a timing problem.
        $this->assertTrue($store->isHealthy());

        // And nothing was built in the binding's place.
        $this->assertFalse($container->resolved('redis'));
    }

    public function test_a_binding_that_is_not_a_connection_factory_is_not_called(): void
    {
        $container = new Container();
        $container->instance('redis', new stdClass());
        $store = new RedisAtomicStateStore(app: $container);

        try {
            $store->next('pgsql', self::POOL, [1]);

            $this->fail('A store whose `redis` cannot open connections must not answer.');
        } catch (RedisUnavailable $e) {
            $this->assertStringContainsString('stdClass', $e->getMessage());
            $this->assertStringContainsString('cannot open connections', $e->getMessage());
        }

        $this->assertTrue($store->isHealthy());
    }

    public function test_the_fallback_is_reported_once_per_process(): void
    {
        $store = new RedisAtomicStateStore(app: new Container());

        $records = [];
        Log::listen(function (MessageLogged $message) use (&$records): void {
            $records[] = $message->message;
        });

        for ($pick = 0; $pick < 3; $pick++) {
            try {
                $store->next('pgsql', self::POOL, [1]);
            } catch (RedisUnavailable) {
                // Expected: the manager falls back to the in-process store.
            }
        }

        // Under PHP-FPM the application boots per request, so a line per occurrence
        // would be a line per request. The boot audit reports the installation-wide
        // view; this covers a single worker that asked too early.
        $this->assertCount(1, $records);
        $this->assertStringContainsString('This pick fell back to the in-process store.', $records[0]);
    }

    /**
     * The report is held by a static, so one process writes it once however many
     * stores failed in it.
     */
    private static function resetUnavailableReport(): void
    {
        (new ReflectionProperty(RedisAtomicStateStore::class, 'unavailableReported'))
            ->setValue(null, false);
    }
}
