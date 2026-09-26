<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Exceptions;

use RuntimeException;

/**
 * The configured primary store cannot be used at all — the container has no `redis`
 * binding yet, or it is bound to something that cannot open connections.
 *
 * Distinct from a store *failure* on purpose: a store that cannot be resolved may
 * simply be early (providers are still registering, so a database connection built
 * during `register()` asks for a replica before Redis exists). It falls back to the
 * in-process store for that call, but it must not count toward the consecutive
 * failure threshold — otherwise a worker that picked one replica too early would
 * stop using Redis for the rest of its life.
 */
final class RedisUnavailable extends RuntimeException
{
}
