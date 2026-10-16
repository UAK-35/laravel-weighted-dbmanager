<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use RuntimeException;

/**
 * Stands in for the `cache` binding so the reading a silent schedule is diagnosed with can be driven
 * without a cache server: it either hands out a repository that answers or refuses to hand one out
 * at all, and it records which stores were asked for, so a test can prove the reading asked for the
 * store the schedule's own mutex names rather than the installation's default.
 *
 * Implementing the contract the runtime requires is the only thing a test has to do to take Redis'
 * place, the same way `FakeRedis` stands in for a store a probe would PING.
 */
final class FakeCache implements Factory
{
    /** @var list<string|null> */
    public array $asked = [];

    /**
     * @param string|null $failure what asking for a store throws, when the store cannot be reached
     */
    public function __construct(private readonly ?string $failure = null)
    {
    }

    public function store($name = null): Repository
    {
        $this->asked[] = is_string($name) ? $name : null;

        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        // The name is what the repository reports back as its own, so a reading that names the
        // resolved store has something to read: the real manager passes the same key.
        return new Repository(new ArrayStore(), ['store' => is_string($name) ? $name : 'array']);
    }
}
