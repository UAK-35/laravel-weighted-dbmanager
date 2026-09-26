<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

use Illuminate\Container\Container as BaseContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory;
use Throwable;

/**
 * The container's Redis connection factory, reached without the `Redis` facade.
 *
 * Why not the facade
 * ------------------
 * `Illuminate\Support\Facades\Redis` resolves the container key `redis`. Until the
 * application registers `Illuminate\Redis\RedisServiceProvider` that key is unbound,
 * and a container resolves an unbound string by building a class of that name. The
 * phpredis extension ships a class called `Redis` and PHP class names are
 * case-insensitive, so `$app['redis']` silently builds `new \Redis()` instead of
 * failing — and the facade caches that as its root for the rest of the process, so
 * every later `Redis::connection()` in that worker throws
 * "Call to undefined method Redis::connection()".
 *
 * That window is reachable: a database connection is built while providers register
 * (any app that touches the schema in `register()` does exactly that), and choosing a
 * replica asks the store. Going through the container means a missing binding is
 * reported as a missing binding instead of poisoning a facade the whole application
 * shares — including Horizon, queues and the cache.
 */
final class RedisAccess
{
    /**
     * The factory bound as `redis`, or the reason there is none.
     *
     * @return array{0: Factory|null, 1: string|null}
     */
    public static function factory(?Container $container = null): array
    {
        $container ??= BaseContainer::getInstance();

        // Checked before resolving, never after: resolving an unbound `redis` is the
        // call that builds the extension class.
        if (!$container->bound('redis')) {
            return [null, 'the container has no "redis" binding yet'];
        }

        try {
            $factory = $container->make('redis');
        } catch (Throwable $e) {
            return [null, sprintf('resolving "redis" threw %s', $e->getMessage())];
        }

        if (!$factory instanceof Factory) {
            return [null, sprintf(
                '"redis" is bound to %s, which cannot open connections',
                get_debug_type($factory),
            )];
        }

        return [$factory, null];
    }

    /**
     * Open a connection and PING it: null when it answered, otherwise why it did not.
     */
    public static function ping(?Container $container, string $connection): ?string
    {
        [$factory, $error] = self::factory($container);

        if ($factory === null) {
            return $error;
        }

        try {
            $factory->connection($connection)->ping();
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}
