<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * The connection the package's pgcat surfaces follow, and where that name came from.
 *
 * WHY THIS EXISTS
 * ---------------
 *   Historically the pgcat gate, the flipper's health snapshot and `db:doctor` all read
 *   `database.default` on the assumption that it is the connection a query actually runs on.
 *   That assumption does not hold for an application whose PostgreSQL path is a *non-default*
 *   connection — here, the one named by `app.default_api_connection` (env `API_DB_CONNECTION`),
 *   which every model and validation rule pins while `database.default` stays on SQLite.
 *
 *   `db-manager.swrr.connection` lets such an installation name the connection pgcat fronts.
 *   Left unset, the package keeps its historic behaviour and follows `database.default`, so
 *   existing installs and the whole test suite are unaffected.
 *
 *   Read here once, so the gate, the health snapshot, `/health/db`, `db:replica-status` and
 *   `db:doctor` cannot disagree about which connection is in play.
 */
final class ActiveConnection
{
    /** The config key a name was written on, when the installation named one explicitly. */
    public const CONFIGURED_SOURCE = 'db-manager.swrr.connection';

    /** The connection the package falls back to when nothing is named — its historic source. */
    public const DEFAULT_SOURCE = 'database.default';

    private function __construct()
    {
    }

    /**
     * The connection the pgcat surfaces follow, its driver, and the config key it came from.
     *
     * The source travels with the name so a sentence or a report can tell an operator which
     * setting to edit without guessing at one — see `PgcatConfigFlipper::armingWarning()`.
     *
     * @return array{connection: string, driver: string, source: string}
     */
    public static function resolve(Repository $config): array
    {
        $configured = ConfigValue::string($config->get('db-manager.swrr.connection'));

        $connection = $configured !== ''
            ? $configured
            : ConfigValue::string($config->get('database.default'));

        return [
            'connection' => $connection,
            'driver' => ConfigValue::string($config->get("database.connections.{$connection}.driver")),
            'source' => $configured !== '' ? self::CONFIGURED_SOURCE : self::DEFAULT_SOURCE,
        ];
    }
}
