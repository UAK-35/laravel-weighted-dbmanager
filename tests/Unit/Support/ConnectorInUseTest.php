<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\Connectors\SQLiteConnector;
use PHPUnit\Framework\TestCase;
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
    }

    public function test_the_driver_decides_the_connector_so_a_second_connection_is_a_second_class(): void
    {
        $reading = $this->read(['app' => 'sqlite']);

        $this->assertSame('app', $reading['connection']);
        $this->assertSame('sqlite', $reading['driver']);
        $this->assertSame(SQLiteConnector::class, $reading['connector']);
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
     * @return array{connection: string, source: string, driver: string, binding: string|null, factory: string|null, factory_error: string|null, connector: string|null, error: string|null}
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
            $config->set("database.connections.{$connection}.driver", $driver);
        }

        return $config;
    }
}
