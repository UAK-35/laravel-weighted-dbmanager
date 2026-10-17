<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Uak35\WeightedDbManager\Support\ActiveConnection;
use Uak35\WeightedDbManager\Support\ConnectorInUse;

/**
 * Which connector builds a connection, read the way the framework reads it.
 *
 * The class that builds a connection is one container binding away from being somebody else's
 * — `db.connector.pgsql` — and an application that replaces the framework's PostgreSQL
 * connector usually has a reason: it is where the option path its own connection config is
 * written with is added. Nothing in configuration shows that, so the reading is what makes it
 * visible, and these tests pin the reading rather than any row's prose: the class, the binding
 * that answered, and the states where no answer exists.
 *
 * The two halves are asserted against the framework's own rule as well as against the reading,
 * because the claim worth pinning is the one the reading *makes about the framework*: a binding
 * the container holds is a binding `createConnector()` takes, so a case here binds one and then
 * asks the framework directly. A container and a config repository are the whole fixture — the
 * reading is `(Repository, Container)`, and testing it through an application would test the
 * application.
 */
final class ConnectorInUseTest extends TestCase
{
    public function test_the_frameworks_own_connector_is_named_when_nothing_is_bound(): void
    {
        $reading = $this->read(['pgsql' => 'pgsql']);

        $this->assertSame('pgsql', $reading['connection']);
        $this->assertSame(ActiveConnection::DEFAULT_SOURCE, $reading['source']);
        $this->assertSame('pgsql', $reading['driver']);
        $this->assertNull($reading['binding'], 'nothing is bound for this driver, and the reading says so');
        $this->assertSame(PostgresConnector::class, $reading['connector']);
        $this->assertNull($reading['error']);

        // The factory it asked is the one it was given, named as it reads.
        $this->assertSame(ConnectionFactory::class, $reading['factory']);

        // The other half of what builds a connection: the class it resolves to. Nothing has
        // replaced it, so the framework's own class for the driver is the whole answer.
        $this->assertNull($reading['connection_error']);
        $this->assertSame(PostgresConnection::class, $reading['connection_class']);
        $this->assertSame('pgsql', $reading['connection_driver']);
        $this->assertNull($reading['resolver'], 'no connection resolver is registered, and the reading says so');
        $this->assertNull($reading['resolver_applied'], 'nothing was registered, so nothing failed to apply');
    }

    public function test_the_driver_decides_the_connector_so_a_second_connection_is_a_second_class(): void
    {
        $reading = $this->read(['app' => 'sqlite']);

        $this->assertSame('app', $reading['connection']);
        $this->assertSame('sqlite', $reading['driver']);
        $this->assertSame(SQLiteConnector::class, $reading['connector']);
        $this->assertSame(SQLiteConnection::class, $reading['connection_class']);
    }

    /**
     * The class replacement: `Connection::resolverFor()` is consulted by `createConnection()`
     * before its own map, so an installation that registers one replaces the class every
     * connection of that driver is built as. The connector reading cannot see it — the connector
     * only makes the PDO — which is why the connection is built and read here.
     *
     * The registry is static and has no unregister, so the test puts back what it found; a suite
     * that leaked a resolver would make every later class expectation in this file someone else's.
     */
    public function test_an_installations_connection_resolver_is_named_and_reported_as_applied(): void
    {
        // Any class is a replacement, so the test brings its own: built once to learn the name
        // PHP gives the anonymous class, which is the name the reading must report.
        $class = (new class ('', 'database', '', ['driver' => 'pgsql']) extends PostgresConnection {})::class;

        $resolvers = self::resolvers();

        try {
            Connection::resolverFor('pgsql', static fn ($pdo, $database, $prefix, $config) => new $class($pdo, $database, $prefix, $config));

            $reading = $this->read(['pooler' => 'pgsql']);
        } finally {
            self::restoreResolvers($resolvers);
        }

        $this->assertSame($class, $reading['connection_class']);
        $this->assertSame('pgsql', $reading['connection_driver']);
        $this->assertSame('pgsql', $reading['resolver'], 'the driver whose class was replaced travels with the answer');
        $this->assertTrue($reading['resolver_applied'], 'the connection built here went through the registered resolver');
        $this->assertSame(PostgresConnector::class, $reading['connector'], 'the connector is a separate answer and stays the framework\'s');
    }

    /**
     * A connection built before the resolver was registered: the object the application holds is
     * the framework's class, and a replacement that arrived later has not reached it. This is the
     * state the resolved connection exists to show — a registry says a replacement is registered,
     * and only the object says whether it is in effect.
     */
    public function test_a_connection_built_before_the_resolver_is_reported_as_not_applied(): void
    {
        $config = $this->config(['pooler' => 'pgsql']);
        $container = $this->container();
        $container->instance('config', $config);

        $manager = new DatabaseManager($container, $container->make('db.factory'));
        $container->instance('db', $manager);

        // The connection the application is using, built while nothing was replaced.
        $inUse = $manager->connection('pooler');

        $this->assertSame(PostgresConnection::class, $inUse::class);

        $class = (new class ('', 'database', '', ['driver' => 'pgsql']) extends PostgresConnection {})::class;

        $resolvers = self::resolvers();

        try {
            Connection::resolverFor('pgsql', static fn ($pdo, $database, $prefix, $config) => new $class($pdo, $database, $prefix, $config));

            $reading = ConnectorInUse::resolve($config, $container);
        } finally {
            self::restoreResolvers($resolvers);
        }

        $this->assertSame(PostgresConnection::class, $reading['connection_class'], 'the class reported is the connection in use, not one built for the reading');
        $this->assertSame('pgsql', $reading['resolver']);
        $this->assertFalse($reading['resolver_applied'], 'a resolver registered after the build did not build this connection');
    }

    /**
     * The case the reading exists for: an installation's own connector, and the binding it came
     * from. The framework is asked the same question in the same breath, because that is the
     * claim — the binding this reading names is the binding `createConnector()` takes.
     */
    public function test_a_bound_connector_is_named_with_the_binding_that_answered_it(): void
    {
        $stub = new class () extends PostgresConnector {};
        $container = new Container();
        $container->instance('db.factory', new ConnectionFactory($container));
        $container->instance('db.connector.pgsql', $stub);

        $reading = ConnectorInUse::resolve($this->config(['pooler' => 'pgsql']), $container);

        $this->assertSame('db.connector.pgsql', $reading['binding']);
        $this->assertSame($stub::class, $reading['connector']);
        $this->assertNull($reading['error']);

        // The same key, read by the class that builds connections: a reading that named a
        // binding the framework does not consult would be describing a different installation.
        $this->assertSame(
            $stub::class,
            (new ConnectionFactory($container))->createConnector(['driver' => 'pgsql'])::class,
        );
    }

    public function test_the_binding_is_named_only_for_the_driver_it_was_written_for(): void
    {
        $container = new Container();
        $container->instance('db.factory', new ConnectionFactory($container));
        $container->instance('db.connector.pgsql', new class () extends PostgresConnector {});

        $reading = ConnectorInUse::resolve($this->config(['app' => 'sqlite']), $container);

        $this->assertNull($reading['binding'], 'a pgsql binding says nothing about a sqlite connection');
        $this->assertSame(SQLiteConnector::class, $reading['connector']);
    }

    /**
     * The connection the package follows rather than the default: `swrr.connection` names it,
     * and the connector read is *that* connection's — which is the whole reason the reading
     * takes `ActiveConnection`'s answer rather than `database.default`.
     */
    public function test_the_connection_the_package_follows_is_the_one_read(): void
    {
        $config = $this->config(['app' => 'sqlite', 'pooler' => 'pgsql']);
        $config->set('db-manager.swrr.connection', 'pooler');

        $reading = ConnectorInUse::resolve($config, $this->container());

        $this->assertSame('pooler', $reading['connection']);
        $this->assertSame(ActiveConnection::CONFIGURED_SOURCE, $reading['source']);
        $this->assertSame(PostgresConnector::class, $reading['connector']);
    }

    public function test_a_connection_the_configuration_does_not_declare_resolves_nothing(): void
    {
        $config = $this->config(['app' => 'sqlite']);
        $config->set('db-manager.swrr.connection', 'typo');

        $reading = ConnectorInUse::resolve($config, $this->container());

        $this->assertSame('typo', $reading['connection']);
        $this->assertSame(ActiveConnection::CONFIGURED_SOURCE, $reading['source'], 'the setting the name came from travels with the refusal');
        $this->assertSame('', $reading['driver']);
        $this->assertNull($reading['binding'], 'no driver, so no connector binding to look for');
        $this->assertNull($reading['connector']);
        $this->assertSame('A driver must be specified.', $reading['error'], 'the framework\'s own sentence, not a second way of saying it');
    }

    public function test_a_driver_no_connector_is_registered_for_is_refused_in_the_frameworks_words(): void
    {
        $reading = $this->read(['warehouse' => 'oracle']);

        $this->assertNull($reading['connector']);
        $this->assertSame('Unsupported driver [oracle].', $reading['error']);
    }

    /**
     * The registry as it stands, and the way back to it: Laravel offers no unregister, and a
     * static left behind would describe the next case's installation rather than this one's.
     *
     * @return array<string, \Closure>
     */
    private static function resolvers(): array
    {
        $resolvers = (new ReflectionProperty(Connection::class, 'resolvers'))->getValue();

        return is_array($resolvers) ? $resolvers : [];
    }

    /**
     * @param array<string, \Closure> $resolvers
     */
    private static function restoreResolvers(array $resolvers): void
    {
        (new ReflectionProperty(Connection::class, 'resolvers'))->setValue(null, $resolvers);
    }

    /**
     * A `db.factory` that is not a factory at all: the binding is the framework's extension
     * point for the class, so a reading that kept going would be describing a factory this
     * installation does not have. It says it was not asked instead.
     */
    public function test_a_factory_that_is_not_a_factory_is_reported_rather_than_asked(): void
    {
        $container = $this->container();
        $container->instance('db.factory', new stdClass());

        $reading = ConnectorInUse::resolve($this->config(['app' => 'pgsql']), $container);

        $this->assertSame(stdClass::class, $reading['factory']);
        $this->assertSame('stdClass is not a ConnectionFactory', $reading['factory_error']);
        $this->assertNull($reading['connector'], 'nothing was asked, so nothing is claimed');
        $this->assertNull($reading['error']);
    }

    public function test_a_factory_that_cannot_be_resolved_is_reported_with_what_it_said(): void
    {
        $container = $this->container();
        $container->bind('db.factory', static function (): never {
            throw new RuntimeException('db is not bound yet');
        });

        $reading = ConnectorInUse::resolve($this->config(['app' => 'pgsql']), $container);

        $this->assertNull($reading['factory']);
        $this->assertSame('db is not bound yet', $reading['factory_error']);
        $this->assertNull($reading['connector']);
    }

    /**
     * @param array<string, string> $drivers connection => driver
     * @return array{
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
    private function read(array $drivers): array
    {
        return ConnectorInUse::resolve($this->config($drivers), $this->container());
    }

    /** A container whose factory is the framework's own, so a case about a connector is about that. */
    private function container(): Container
    {
        $container = new Container();
        $container->instance('db.factory', new ConnectionFactory($container));

        return $container;
    }

    /**
     * A config repository over one connection per entry, whose `database.default` is the one
     * named — so a case that does not name a connection is still a case about one connection.
     *
     * @param array<string, string> $drivers connection => driver
     */
    private function config(array $drivers, ?string $default = null): Repository
    {
        $config = new Repository(['database' => ['default' => $default ?? array_key_first($drivers), 'connections' => []]]);

        foreach ($drivers as $connection => $driver) {
            // `database` is not decoration: the class reading builds the connection, and a
            // connection with no database is not the one an installation declares.
            $config->set("database.connections.{$connection}", ['driver' => $driver, 'database' => 'app']);
        }

        return $config;
    }
}
