<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Database\Weighted;

use Closure;
use Illuminate\Database\Connectors\ConnectionFactory;
use Uak35\WeightedDbManager\Support\ConfigValue;

/**
 * Connection factory that hands read-replica selection to a weighted resolver.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * Laravel decides which read replica to use inside
 * Illuminate\Database\Connectors\ConnectionFactory — `getReadConfig()` calls
 * `getReadWriteConfig()`, which is just `Arr::random($config['read'])`.
 * DatabaseManager (the class WeightedDatabaseManager extends) never sees that
 * decision, so overriding a read method there has no effect at all: the code
 * would be unreachable.
 *
 * The provider therefore registers this factory as `db.factory` and installs a
 * resolver on it. The resolver runs once per read PDO Laravel creates, so SWRR
 * selection happens at exactly the point the framework used to pick at random.
 *
 * With no resolver installed the factory behaves exactly like Laravel's own,
 * which keeps the class safe to bind on its own.
 */
class WeightedConnectionFactory extends ConnectionFactory
{
    /**
     * Resolver returning the merged read config for a connection config.
     *
     * @var (Closure(array<string, mixed>): array<string, mixed>)|null
     */
    private ?Closure $readConfigResolver = null;

    /**
     * Install the read-config resolver, or pass null to restore Laravel's
     * `Arr::random()` behaviour.
     *
     * @param (Closure(array<string, mixed>): array<string, mixed>)|null $resolver
     */
    public function resolveReadConfigUsing(?Closure $resolver): void
    {
        $this->readConfigResolver = $resolver;
    }

    /**
     * Get the read configuration for a read / write connection.
     *
     * The signature intentionally matches the framework's untyped one.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function getReadConfig($config)
    {
        if ($this->readConfigResolver === null) {
            return ConfigValue::assoc(parent::getReadConfig($config));
        }

        return ($this->readConfigResolver)($config);
    }
}
