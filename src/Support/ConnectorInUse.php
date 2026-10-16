<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
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
 * THE ONE THING IT TOUCHES, AND WHY THAT IS THE FRAMEWORK'S OWN
 * ------------------------------------------------------------
 *   `createConnector()` constructs the connector it names, so this reading does too — there
 *   is no other way to ask the question, and answering it a second way (re-deriving the
 *   framework's `match ($driver)`) would be a copy of a rule that is the framework's to
 *   change, which is how a reading drifts from the thing it describes. The framework's own
 *   connectors construct nothing and open nothing; a replacement's constructor belongs to
 *   the application that wrote it, and that is the price of the question being answerable
 *   at all. Nothing else here touches an installation: no connection is made, no socket is
 *   opened and nothing is written.
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
     * @return array{
     *     connection: string,
     *     source: string,
     *     driver: string,
     *     binding: string|null,
     *     factory: string|null,
     *     factory_error: string|null,
     *     connector: string|null,
     *     error: string|null,
     * }
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

        return $reading;
    }
}
