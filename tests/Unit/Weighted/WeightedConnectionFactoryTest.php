<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Weighted;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Database\Weighted\WeightedConnectionFactory;

/**
 * Guards the wiring that makes weighted routing reachable at all.
 *
 * Laravel chooses the read host inside Illuminate\Database\Connectors\
 * ConnectionFactory, so a read hook on DatabaseManager can never run. These
 * tests pin the factory's delegation both ways: with a resolver installed it
 * decides, and without one Laravel's own random pick is untouched.
 */
class WeightedConnectionFactoryTest extends TestCase
{
    public function test_the_installed_resolver_decides_the_read_config(): void
    {
        $factory = $this->factory();
        $factory->resolveReadConfigUsing(fn (array $config): array => [
            'host' => 'weighted.example',
            'port' => 5433,
        ]);

        $read = $factory->readConfig($this->connectionConfig());

        $this->assertSame(['host' => 'weighted.example', 'port' => 5433], $read);
    }

    public function test_it_defers_to_laravels_random_pick_without_a_resolver(): void
    {
        $read = $this->factory()->readConfig($this->connectionConfig());

        $this->assertContains($read['host'], ['10.0.0.1', '10.0.0.2']);
        $this->assertArrayNotHasKey('read', $read);
        $this->assertArrayNotHasKey('write', $read);
    }

    public function test_a_null_resolver_restores_laravels_behaviour(): void
    {
        $factory = $this->factory();
        $factory->resolveReadConfigUsing(fn (array $config): array => ['host' => 'weighted.example']);

        $this->assertSame('weighted.example', $factory->readConfig($this->connectionConfig())['host']);

        $factory->resolveReadConfigUsing(null);

        $this->assertContains($factory->readConfig($this->connectionConfig())['host'], ['10.0.0.1', '10.0.0.2']);
    }

    private function factory(): WeightedConnectionFactory
    {
        return new class (new Container()) extends WeightedConnectionFactory {
            /** Expose the protected hook the framework calls. */
            public function readConfig(array $config): array
            {
                return $this->getReadConfig($config);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(): array
    {
        return [
            'driver' => 'pgsql',
            'database' => 'app',
            'prefix' => '',
            'name' => 'weighted',
            'read' => [
                ['host' => '10.0.0.1', 'port' => 5432],
                ['host' => '10.0.0.2', 'port' => 5432],
            ],
            'write' => ['host' => '10.0.0.9', 'port' => 5432],
        ];
    }
}
