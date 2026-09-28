<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Support\ConfigValue;
use Uak35\WeightedDbManager\Support\ReplicaMetadata;

/**
 * A replica is sized by `weight`, or by `cpu_cores` with `ram_gb`, and the resolver reads each
 * through `ConfigValue` at a floor declared in `ReplicaMetadata` — `WEIGHT_FLOOR`, `CORES_FLOOR`
 * and `RAM_FLOOR`, the arguments its own `max()` reads. `ConfigValue` falls back rather than
 * throwing, so a value
 * that is not a number becomes one anyway — and for `weight` the fallback is `0`, which is the
 * value that *disables* the replica, so a typo silently removes a member of the read pool.
 *
 * The rule about which values those are lives here, and three callers read it: the boot audit
 * refuses them at `error` level, `db:doctor`'s `replica metadata` row fails on them, and
 * `WeightResolver` reads the same refusal to say *why* a replica is not in its pool. The
 * replica's identity — which all three use to name the replica that was lost — is spelled here
 * too. Two spellings of either would be two reports that can disagree about a configuration that
 * is already behaving differently from how it reads.
 *
 * The one thing this class does not say is what a refusal *costs* the pool: whether a replica
 * left it is a fact about what the resolver did, and `WeightResolver::resolveWithExclusions()`
 * reports it rather than predicting it here. What this class does say is that the question always
 * has an answer — see `test_a_weight_is_either_a_refusal_or_the_documented_disable_and_never_both`.
 */
final class ReplicaMetadataTest extends TestCase
{
    public function test_a_value_the_resolver_reads_as_written_is_not_refused(): void
    {
        $this->assertSame([], ReplicaMetadata::refusals([
            'host' => '10.1.0.1',
            'port' => 5432,
            'cpu_cores' => 16,
            'ram_gb' => 64,
        ]));

        // The `.env` spelling of both numbers, and the two `ConfigValue` reads that are not
        // strings at all: a float, and a bool, which is a number to `ConfigValue` whatever it
        // looks like.
        $this->assertSame([], ReplicaMetadata::refusals(['weight' => '4']));
        $this->assertSame([], ReplicaMetadata::refusals(['weight' => 4.0]));
        $this->assertSame([], ReplicaMetadata::refusals(['weight' => true]));
        $this->assertSame([], ReplicaMetadata::refusals(['cpu_cores' => '2', 'ram_gb' => '0']));
        $this->assertSame([], ReplicaMetadata::refusals(['ram_gb' => 0]));
        $this->assertSame([], ReplicaMetadata::refusals(['host' => '10.1.0.1']));
    }

    public function test_the_documented_disable_is_not_a_refusal_and_is_named_as_one(): void
    {
        // `weight: 0` is how a replica is taken out on purpose, and the package means it. It is
        // the one value where the reading and the writing differ in a way the package intends, so
        // it is the one value a refusal must leave alone — and the one the callers name instead.
        foreach ([0, '0', 0.0, false] as $disabled) {
            $replica = ['host' => '10.1.0.1', 'weight' => $disabled];

            $this->assertSame([], ReplicaMetadata::refusals($replica), var_export($disabled, true));
            $this->assertTrue(ReplicaMetadata::disables($replica), var_export($disabled, true));
        }

        // Nothing written at all is not a disable: the resolver reads it as the equal-treatment
        // fallback, and neither caller has anything to say about it.
        $this->assertFalse(ReplicaMetadata::disables(['host' => '10.1.0.1']));
        $this->assertFalse(ReplicaMetadata::disables(['host' => '10.1.0.1', 'weight' => 4]));
    }

    public function test_a_weight_read_as_zero_out_of_a_value_that_is_not_zero_is_refused(): void
    {
        // Four spellings, one outcome: the resolver reads 0, which drops the replica, and none of
        // the four was written as the disable. A negative weight is clamped up to the disable, a
        // fraction is truncated onto it, a value that is not a number falls back to it, and a key
        // that is *present* with nothing in it reads as absent to `isset()` — which is how it used
        // to be counted as "no weight metadata", a report about a different fault entirely.
        $cases = [
            [-5, 'weight is -5, which the resolver reads as 0'],
            ['0.5', 'weight is 0.5, which the resolver reads as 0'],
            ['heavy', 'weight is "heavy", which the resolver reads as 0'],
            [null, 'weight is null, which the resolver reads as 0'],
        ];

        foreach ($cases as [$written, $sentence]) {
            $refusals = ReplicaMetadata::refusals(['host' => '10.1.0.1', 'weight' => $written]);

            $this->assertCount(1, $refusals, var_export($written, true));
            $this->assertSame('weight', $refusals[0]['setting']);
            $this->assertSame($sentence, $refusals[0]['sentence']);
            $this->assertFalse(ReplicaMetadata::disables(['host' => '10.1.0.1', 'weight' => $written]));
        }
    }

    public function test_a_core_count_under_the_floor_is_refused_and_read_as_one_core(): void
    {
        // Cores are read at a floor of one, so whatever was written the resolver substitutes a
        // one-core box — a size the read list does not describe, and the replica stays in the pool.
        foreach ([0, 0.5, -2, 'cores'] as $written) {
            $refusals = ReplicaMetadata::refusals(['host' => '10.1.0.1', 'cpu_cores' => $written]);

            $this->assertCount(1, $refusals, var_export($written, true));
            $this->assertSame('cpu_cores', $refusals[0]['setting']);
            $this->assertStringEndsWith(
                'which the resolver reads as 1 core',
                $refusals[0]['sentence'],
                var_export($written, true),
            );
        }
    }

    public function test_memory_under_the_floor_is_refused_and_read_as_zero_gigabytes(): void
    {
        foreach ([-1, '-0.5', 'lots', []] as $written) {
            $refusals = ReplicaMetadata::refusals(['host' => '10.1.0.1', 'ram_gb' => $written]);

            $this->assertCount(1, $refusals, var_export($written, true));
            $this->assertSame('ram_gb', $refusals[0]['setting']);
            $this->assertStringEndsWith(
                'which the resolver reads as 0 GB',
                $refusals[0]['sentence'],
                var_export($written, true),
            );
        }
    }

    public function test_all_three_settings_are_reported_from_one_replica(): void
    {
        $refusals = ReplicaMetadata::refusals([
            'host' => '10.1.0.1',
            'weight' => 'heavy',
            'cpu_cores' => 0,
            'ram_gb' => -1,
        ]);

        $this->assertSame(['weight', 'cpu_cores', 'ram_gb'], array_column($refusals, 'setting'));
    }

    /**
     * The property `WeightResolver` reads instead of guessing: a weight the resolver puts nothing
     * in the pool for is *either* a value the package refuses *or* the disable the read list means
     * — never both, never neither. It is what lets the resolver name a departure (a refusal) and a
     * decision (a drain) without asking about them in a second place, and what makes falling
     * through to `DISABLED` a fact rather than a default.
     */
    public function test_a_weight_is_either_a_refusal_or_the_documented_disable_and_never_both(): void
    {
        $written = [0, '0', 0.0, false, -5, '0.5', 0.5, 'heavy', null, '', true, 4, '4', 100];

        foreach ($written as $value) {
            $replica = ['host' => '10.1.0.1', 'weight' => $value];

            $refusesWeight = in_array('weight', array_column(ReplicaMetadata::refusals($replica), 'setting'), true);
            $disables = ReplicaMetadata::disables($replica);

            $this->assertFalse(
                $refusesWeight && $disables,
                'a refused weight is not also the disable: '.var_export($value, true),
            );

            // Exactly one holds for every weight the resolver reads as 0, and neither holds for
            // every weight it puts in the pool.
            $this->assertSame(
                max(ReplicaMetadata::WEIGHT_FLOOR, ConfigValue::int($value)) === ReplicaMetadata::WEIGHT_FLOOR,
                $refusesWeight || $disables,
                'a weight the resolver reads as 0 is refused or is the disable, and nothing else: '.var_export($value, true),
            );
        }
    }

    public function test_a_value_is_described_as_the_number_it_reads_as_or_the_shape_it_is(): void
    {
        // A number is printed as itself, because "weight is int" says nothing about *which* int,
        // and the numbers this reports are the ones the resolver changed. A bool is a number to
        // `ConfigValue`, so it is printed as the number it is read as rather than as "bool".
        $this->assertSame('4', ReplicaMetadata::describe(4));
        $this->assertSame('4', ReplicaMetadata::describe(4.0));
        $this->assertSame('4.5', ReplicaMetadata::describe('4.5'));
        $this->assertSame('1', ReplicaMetadata::describe(true));

        // Everything else is the shape it is instead, through `ReaderWindows::describe()` — the
        // package's one describer for a value a setting will not read.
        $this->assertSame('"heavy"', ReplicaMetadata::describe('heavy'));
        $this->assertSame('null', ReplicaMetadata::describe(null));
        $this->assertSame('0', ReplicaMetadata::describe(false));
        $this->assertSame('an array of 2 entries', ReplicaMetadata::describe([1, 2]));
    }

    public function test_a_replica_is_keyed_by_its_first_host_and_its_port(): void
    {
        // Laravel lets `host` be a list, so the first one is the address a replica is known by —
        // the same normalisation the health monitor's failure counts are keyed with.
        $this->assertSame('10.1.0.2:5433', ReplicaMetadata::key(['host' => '10.1.0.2', 'port' => 5433]));
        $this->assertSame('10.1.0.2:5432', ReplicaMetadata::key(['host' => ['10.1.0.2', '10.1.0.3']]));
        $this->assertSame('unknown:5432', ReplicaMetadata::key([]));
        $this->assertSame('h:5433', ReplicaMetadata::key(['host' => 'h', 'port' => '5433']));
    }
}
