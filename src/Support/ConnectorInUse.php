<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * The connector class a connection is built through, and where that answer came from.
 *
 * WHY THIS EXISTS
 * ---------------
 *   Every connection's PDO is built by
 *   `Illuminate\Database\Connectors\ConnectionFactory`, which picks the connector from the
 *   connection's own `driver` — and the first branch `createConnector()` takes is the
 *   container: a host application that binds `db.connector.pgsql` has replaced the
 *   framework's PostgreSQL connector for every connection of that driver, and nothing in
 *   `config/database.php` says so.
 *
 *   That is not a curiosity. An application replaces the PostgreSQL connector to add the
 *   option path its own connections are written with, so which class is in use decides
 *   whether those options reach PDO at all — while a configuration, a boot and a whole
 *   test suite read exactly as they would with the framework's own. The connector is
 *   therefore read the way the framework reads it: the binding first, the framework's own
 *   map second. A report can then name the class without anybody opening a repository.
 *
 *   Read here once rather than inside the row that prints it, because it is an answer about
 *   a *connection* rather than about a report: `ActiveConnection` names the connection this
 *   package follows, and this names what will build it.
 *
 *   The factory is only half the answer. Laravel's `createConnection()` consults a second,
 *   global registry before its own class map — a host application that calls
 *   `Connection::resolverFor('pgsql', …)` replaces the class every pgsql connection is built
 *   as, and that replacement is invisible to `createConnector()`: the connector only makes the
 *   PDO the connection is constructed with. So the reading also *builds* the followed connection
 *   and names the class it resolves to, then says whether an installation's resolver is
 *   registered and whether the connection in use was built through it. A class that has been
 *   replaced while the object in use is the framework's own is the state a boot-time cache
 *   produces, and nothing else reports it.
 *
 *   The connection is built from the followed connection's config with the read list removed.
 *   That is not a convenience: building the read/write pair makes the weighted factory choose a
 *   replica, which reads the store, and a preflight that opened a socket to answer a question
 *   about a class would stop being the reading `--connection-only` promises. The class does not
 *   depend on that choice — `createConnection()` receives the driver and the registry and
 *   nothing else — so the connection built here is the class the real one would be.
 *
 * THE ONE THING IT TOUCHES, AND WHY THAT IS THE FRAMEWORK'S OWN
 * ------------------------------------------------------------
 *   `createConnector()` constructs the connector it names, so this reading does too — there
 *   is no other way to ask the question, and answering it a second way (re-deriving the
 *   framework's `match ($driver)`) would be a copy of a rule that is the framework's to
 *   change, which is how a reading drifts from the thing it describes. The framework's own
 *   connectors construct nothing and open nothing; a replacement's constructor belongs to
 *   the application that wrote it, and that is the price of the question being answerable
 *   at all. The connection object is constructed for the same reason and with the same
 *   limit: `make()` builds the object and the lazy PDO closure, and resolving that closure
 *   — the one step that would open a socket — is left to whoever asks for a query. Nothing
 *   else here touches an installation: no connection is made and nothing is written.
 *
 * @phpstan-type Reading array{
 *     connection: string,
 *     source: string,
 *     driver: string,
 *     binding: string|null,
 *     factory: string|null,
 *     factory_error: string|null,
 *     connector: string|null,
 *     error: string|null,
 *     connection_class: string|null,
 *     connection_driver: string|null,
 *     connection_error: string|null,
 *     resolver: string|null,
 *     resolver_applied: bool|null,
 * }
 */
final class ConnectorInUse
{
    /**
     * The container key the framework consults before its own map, with the driver appended.
     *
     * Spelled here because it is half of the answer: a row that names the class but not the
     * binding leaves an operator to guess whether the framework's connector or the
     * application's is in use, which is the question the reading exists to answer.
     */
    public const BINDING_PREFIX = 'db.connector.';

    /** The binding that builds every connection this package's own factory hands out. */
    private const FACTORY = 'db.factory';

    private function __construct()
    {
    }

    /**
     * The connector a connection would be built through, read out of the factory that builds it.
     *
     * `connection` and `source` are `ActiveConnection`'s: the connection the pgcat surfaces
     * follow, and the setting its name was read from, so a sentence about a connector that
     * cannot be built can name what to edit rather than only what is wrong.
     *
     * The last three keys are the answer, per state, and each of them is `null` in the states
     * it does not describe:
     *
     *   - `connector` — the class the factory would build, once it answered;
     *   - `error` — why it would build nothing: no driver on the connection, or a driver no
     *     connector is registered for. The framework's own message, because it is the
     *     sentence the connection would fail with;
     *   - `factory` and `factory_error` — what `db.factory` was, and what it said when it
     *     could not be asked at all: a binding that threw, or one that resolved to something
     *     that is not a `ConnectionFactory`. The factory is the framework's own extension
     *     point for the whole class, so a host application can replace it — the state
     *     `db:doctor`'s `weighted factory` row exists for — and a reading that guessed past
     *     that would be describing a factory this installation does not have.
     *
     * A `db.connector.<driver>` binding is claimed only when the container holds that key, and
     * the container is the one the caller passes: the factory it resolved is built with the
     * application, so the two read the same place. A binding that is there is what the
     * framework's own `createConnector()` would take, and a `null` binding is the framework's
     * map — the row's sentence says which of the two it found rather than leaving a class name
     * to be interpreted.
     *
     * @return Reading
     *
     * The last five are the resolved connection and are null in the states where no connection was
     * built: a factory that could not be asked, a connector that could not be built, or a build
     * that threw. `resolver` is the driver whose class an installation replaced (null when none
     * is registered), and `resolver_applied` is null with it — a replacement that was never
     * registered is not a replacement that failed to apply. Where a connection had already been
     * built in this process, its class is the one reported and `resolver_applied` compares it with
     * what a connection built now resolves to: a class that differs means the connection in use
     * predates the resolver, which is the only state in which the answer is `false`.
     */
    public static function resolve(Repository $config, Container $container): array
    {
        $active = ActiveConnection::resolve($config);
        $driver = $active['driver'];
        $key = self::BINDING_PREFIX.$driver;

        $reading = [
            'connection' => $active['connection'],
            'source' => $active['source'],
            'driver' => $driver,
            'binding' => $driver !== '' && $container->bound($key) ? $key : null,
            'factory' => null,
            'factory_error' => null,
            'connector' => null,
            'error' => null,
            'connection_class' => null,
            'connection_driver' => null,
            'connection_error' => null,
            'resolver' => null,
            'resolver_applied' => null,
        ];

        try {
            $factory = $container->make(self::FACTORY);
        } catch (Throwable $e) {
            $reading['factory_error'] = $e->getMessage();

            return $reading;
        }

        $reading['factory'] = get_debug_type($factory);

        if (!$factory instanceof ConnectionFactory) {
            $reading['factory_error'] = $reading['factory'].' is not a ConnectionFactory';

            return $reading;
        }

        try {
            // The driver alone. `createConnector()` reads no other key of a connection's config,
            // so passing the driver is passing everything the answer depends on — and a driver
            // that read as nothing is passed as *no* driver rather than as an empty string, which
            // is the shape the connection has when its name is not declared at all. The framework
            // tells those two apart ("A driver must be specified." against "Unsupported driver
            // []."), and the empty string is an artifact of reading a missing key rather than
            // something a configuration writes.
            $connector = $factory->createConnector($driver === '' ? [] : ['driver' => $driver]);
        } catch (Throwable $e) {
            $reading['error'] = $e->getMessage();

            return $reading;
        }

        $reading['connector'] = $connector::class;

        return self::connection($reading, $config, $container, $factory);
    }

    /**
     * The class the followed connection resolves to, and whether an installation's resolver built it.
     *
     * The connection is built with the read list removed so the weighted factory does not choose a
     * replica for a question about a class — see the class docblock. Where a connection of that
     * name has already been built in this process, that object is the one reported, because it is
     * the connection the application is actually using; a connection built now is the comparison
     * that says whether the installation's resolver reached it.
     *
     * @param Reading $reading
     * @return Reading
     */
    private static function connection(array $reading, Repository $config, Container $container, ConnectionFactory $factory): array
    {
        $name = $reading['connection'];
        $driver = $reading['driver'];
        $declared = $config->get('database.connections.'.$name);
        $declared = is_array($declared) ? $declared : [];

        // The read and write lists are a replica choice rather than part of the class: keeping them
        // out of the config `make()` is given keeps it on its single-connection path, which is the
        // path that consults the connection resolver and never the read picker. The settings they
        // *hold* are another question, and dropping them with the lists was a defect: a connection
        // written as a read/write pair can leave its base block with nothing in it but the driver,
        // so the build failed on the first key the connector reads — `Undefined array key
        // "database"` — and a correctly configured installation was reported as one that cannot
        // connect. The write half is the one taken, because it is the connection rather than a
        // replica pick; the read half is used only when there is no write half at all.
        $settings = self::settingsIn($declared);
        unset($declared['read'], $declared['write']);
        $declared = [...$declared, ...$settings];

        try {
            $built = $factory->make($declared, $name);
        } catch (Throwable $e) {
            $reading['connection_error'] = $e->getMessage();

            return $reading;
        }

        $inUse = self::inUse($container, $name);
        $connection = $inUse ?? $built;

        $reading['connection_class'] = $connection::class;
        $reading['connection_driver'] = $connection->getDriverName();

        if (Connection::getResolver($driver) === null) {
            return $reading;
        }

        $reading['resolver'] = $driver;
        $reading['resolver_applied'] = $inUse === null || $inUse::class === $built::class;

        return $reading;
    }

    /**
     * The connection settings a read/write block keeps inside its halves.
     *
     * Laravel's own `ConnectionFactory` merges one of the two halves over the base block before it
     * builds; this is that merge for the write half only, so the class is read without a replica
     * being chosen. A half that is a *list* of configurations — the framework's other shape, one
     * entry per host — is read the way the framework reads it: one entry is the connection.
     *
     * @param array<array-key, mixed> $declared the connection's block as the configuration writes it
     * @return array<string, mixed>
     */
    private static function settingsIn(array $declared): array
    {
        $write = $declared['write'] ?? null;

        if (is_array($write) && $write !== []) {
            return self::oneSettings($write);
        }

        $read = $declared['read'] ?? null;

        return is_array($read) && $read !== [] ? self::oneSettings($read) : [];
    }

    /**
     * One settings map out of a half that is either a map or a list of maps.
     *
     * `array_is_list()` is the reading that tells them apart, not the shape of the first value: a
     * single map's `host` is itself an array of hosts, so the first value says nothing.
     *
     * @param array<array-key, mixed> $half
     * @return array<string, mixed>
     */
    private static function oneSettings(array $half): array
    {
        if (array_is_list($half) && is_array($half[0] ?? null)) {
            /** @var array<string, mixed> $first */
            $first = $half[0];

            return $first;
        }

        /** @var array<string, mixed> $half */
        return $half;
    }

    /**
     * The connection of that name the application has already built, if there is one.
     *
     * Read out of the manager rather than built, because a built connection is the one in use and
     * its class is the fact a resolver registered after it was built would be invisible to. A
     * `db` that is not bound, or not a manager, is not this reading's fault to report — `provider
     * swap` is the row for the `db` binding — so it is simply no cached connection.
     */
    private static function inUse(Container $container, string $name): ?Connection
    {
        try {
            $manager = $container->make('db');
        } catch (Throwable) {
            return null;
        }

        if (! $manager instanceof DatabaseManager) {
            return null;
        }

        $connection = $manager->getConnections()[$name] ?? null;

        return $connection instanceof Connection ? $connection : null;
    }
}
