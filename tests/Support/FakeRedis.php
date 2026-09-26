<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use Illuminate\Contracts\Redis\Factory;
use RuntimeException;

/**
 * Stands in for the `redis` binding so the doctor's store probe can be driven
 * without a Redis server: it either answers PING or refuses to connect, and it
 * records which connection names were asked for, so a test can prove the probe
 * used `swrr.redis_connection` rather than a hardcoded name.
 *
 * Implements the contract the runtime requires, so binding this in place of a real
 * manager is the only thing a test has to do to take Redis' place.
 */
final class FakeRedis implements Factory
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly bool $reachable)
    {
    }

    public function connection($name = null): object
    {
        $this->asked[] = is_string($name) ? $name : 'default';

        if (!$this->reachable) {
            throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
        }

        return new class () {
            public function ping(): string
            {
                return 'PONG';
            }
        };
    }
}
