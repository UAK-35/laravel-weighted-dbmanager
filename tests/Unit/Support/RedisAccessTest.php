<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use Illuminate\Container\Container;
use stdClass;
use Uak35\WeightedDbManager\Support\RedisAccess;
use Uak35\WeightedDbManager\Tests\Support\FakeRedis;
use Uak35\WeightedDbManager\Tests\TestCase;

final class RedisAccessTest extends TestCase
{
    public function test_an_unbound_redis_key_is_reported_before_anything_is_built(): void
    {
        $container = new Container();

        [$factory, $error] = RedisAccess::factory($container);

        $this->assertNull($factory);
        $this->assertSame('the container has no "redis" binding yet', $error);

        // The point of the whole class: reaching for the binding is what would have
        // built something, so an unbound key must stop before that.
        $this->assertFalse($container->resolved('redis'));

        $this->assertSame($error, RedisAccess::ping($container, 'default'));
    }

    public function test_an_unbound_key_really_does_build_a_class_of_that_name(): void
    {
        // Why the guard exists rather than a try/catch: the phpredis extension ships a
        // class called `Redis` and PHP class names are case-insensitive, so resolving
        // the unbound key does not fail — it succeeds with the wrong object, which a
        // facade then caches process-wide.
        if (!class_exists('Redis')) {
            $this->markTestSkipped('The phpredis extension is not loaded.');
        }

        $this->assertInstanceOf('Redis', (new Container())->make('redis'));
    }

    public function test_a_binding_that_cannot_open_connections_is_reported_by_shape(): void
    {
        $container = new Container();
        $container->instance('redis', new stdClass());

        [$factory, $error] = RedisAccess::factory($container);

        $this->assertNull($factory);
        $this->assertStringContainsString('stdClass', $error);
        $this->assertStringContainsString('cannot open connections', $error);

        // Asking for a connection says the same thing instead of calling into it.
        $this->assertSame($error, RedisAccess::ping($container, 'default'));
    }

    public function test_ping_reports_the_reason_instead_of_throwing(): void
    {
        $container = new Container();
        $container->instance('redis', new FakeRedis(reachable: false));

        $this->assertSame(
            'Connection refused [tcp://127.0.0.1:6379]',
            RedisAccess::ping($container, 'cache'),
        );

        $container->instance('redis', new FakeRedis(reachable: true));

        $this->assertNull(RedisAccess::ping($container, 'cache'));
    }

    public function test_it_defaults_to_the_container_instance(): void
    {
        $fake = new FakeRedis(reachable: true);
        $this->app->instance('redis', $fake);

        [$factory, $error] = RedisAccess::factory();

        $this->assertSame($fake, $factory);
        $this->assertNull($error);
    }
}
