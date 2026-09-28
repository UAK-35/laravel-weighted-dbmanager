<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

/**
 * What a replica's size metadata may be, and what happens to a value that is not that.
 *
 * A replica is sized by three settings — `weight`, or `cpu_cores` with `ram_gb` — and
 * `WeightResolver::resolveWeight()` reads each of them at a floor of its own:
 * `max(WEIGHT_FLOOR, ConfigValue::int(…))` for a weight, `CORES_FLOOR` for a core count and
 * `RAM_FLOOR` for memory, the three constants declared below rather than numbers written out
 * in both places. `ConfigValue` falls back for anything that is not a number, so
 * `'weight' => 'heavy'` is read as `0` … and `0` is how a replica is *disabled*, so
 * `buildPool()` drops it. An installation whose weight was a typo therefore lost a replica
 * from the pool without a word.
 *
 * This class is where that reading is decided once. Three callers ask it the same
 * question and get the same answer, which is the point: the boot audit refuses a value
 * at `error` level, `db:doctor`'s `replica metadata` row fails on it, and a flip of
 * either must not be able to call a value readable that the other calls refused. The
 * rule is the resolver's own arithmetic rather than a second opinion about it — the
 * floors below are its `max()` arguments — so the rows cannot disagree with routing
 * about what a value becomes.
 *
 * A written value stops being the value routing uses in exactly two ways:
 *
 *   1. it is not a number, so a fallback is read instead; or
 *   2. it is a number under the floor its key is read at — one of the three constants below,
 *      which the resolver's own arithmetic reads rather than restating.
 *
 * `weight` is the one key whose floor a value is *meant* to sit on: `weight: 0` is the
 * documented way to take a replica out, so for that key condition 2 is stated as its
 * opposite — a reading of `0` out of a value that is not `0` — and an explicit `0` is
 * left to the caller's count. See `disables()`.
 *
 * Nothing here decides policy. The callers do, and they read the same fields, and what a refusal
 * *costs* — which is the one thing this class cannot know, because it is a fact about what the
 * resolver did with the value rather than about the value — is `WeightResolver`'s to report. See
 * [docs/pool-exclusions.md](../../docs/pool-exclusions.md).
 *
 * @phpstan-type Refusal array{setting: string, written: mixed, sentence: string}
 */
final class ReplicaMetadata
{
    /**
     * The three settings, in the order a refusal reads. Public because a report that has to
     * name what it examined draws the list from here rather than from its own copy.
     */
    public const SETTINGS = ['weight', 'cpu_cores', 'ram_gb'];

    /**
     * What a value has to be for the resolver to read it as written, in the words every
     * report uses — one sentence, so a log line and a `db:doctor` row cannot describe the
     * same rule differently.
     */
    public const ACCEPTED = 'only a number is read as written — an int, a float, a bool, or the string form of a number';

    /**
     * What the one weight this setting *means* says, in the words every report uses.
     *
     * `weight: 0` is not a size the resolver substituted for; it is the value that takes a replica
     * out of the pool, written by somebody who meant it. So it is not a refusal and this is not
     * `ACCEPTED`'s sentence: a report that described a deliberate drain the way it describes a
     * typo would be telling an operator to fix something that is working.
     */
    public const DISABLED = 'weight is 0, which is how the read list takes a replica out of the pool';

    /**
     * The floor a weight is read at: `max(WEIGHT_FLOOR, …)`, and the value that means *disabled*.
     *
     * These three are the resolver's `max()` arguments, and they are declared here because this is
     * where the question "is this value the one the resolver uses" is asked. `WeightResolver`
     * clamps at them itself, so a floor is one number that routing and every report read from the
     * same place — which is the difference between a rule that cannot drift and two numbers that
     * agree today. `WeightResolverTest` drives the resolver to each boundary for that reason: a
     * `max()` that stops matching its constant fails a test rather than a routing decision.
     */
    public const WEIGHT_FLOOR = 0;

    /**
     * The floor a core count is read at: `max(CORES_FLOOR, …)`, and the fallback `ConfigValue`
     * gets for a value that is not a number — one core is the smallest box a replica can be.
     */
    public const CORES_FLOOR = 1;

    /**
     * The floor memory is read at: `max(RAM_FLOOR, …)`. Zero is a size, so unlike the weight's
     * floor this one is not a disable — a replica declared with no memory stays in the pool.
     */
    public const RAM_FLOOR = 0.0;

    /**
     * The metadata on one replica that the resolver does not read as written.
     *
     * A weight is one of the three, and it is the only one of them that can also be a value the
     * package means: `refusals()` and `disables()` are exclusive for that setting, which is the
     * property `WeightResolver` reads to say why a replica left its pool. Both cannot hold for one
     * written value — a value `ConfigValue` reads as a number with no substitution cannot be a
     * refusal, and a refusal is a value it substituted for — so a caller that finds no weight
     * refusal under a weight the resolver read as `0` has found a disable, and not an unexplained
     * gap. `ReplicaMetadataTest` asserts the exclusivity rather than leaving it to be inferred.
     *
     * @param array<string, mixed> $replica
     * @return list<Refusal>
     */
    public static function refusals(array $replica): array
    {
        $refusals = [];

        if (array_key_exists('weight', $replica)) {
            $written = $replica['weight'];
            $read = max(self::WEIGHT_FLOOR, ConfigValue::int($written));

            // The whole condition in one clause: a weight is refused exactly when the resolver
            // reads it as the value that means *disabled* and it is not the disable the read list
            // means. `!isReadable()` needs no clause of its own — a value `ConfigValue` will not
            // read is one it falls back to the floor for, and `disables()` will not have it
            // either, so it is refused above. Stated this way, the refusal and the disable cannot
            // be made to overlap by a later edit.
            if ($read === self::WEIGHT_FLOOR && !self::disables($replica)) {
                $refusals[] = [
                    'setting' => 'weight',
                    'written' => $written,
                    'sentence' => sprintf(
                        'weight is %s, which the resolver reads as %d',
                        self::describe($written),
                        $read,
                    ),
                ];
            }
        }

        // A core count below the floor is read as the floor, so a replica declared with none is
        // weighted like a one-core box; memory below its floor is read as none at all. Both keep
        // the replica in the pool — a substitution is not a departure — and the resolver is where
        // that distinction is visible, because it is the thing that acts on the value.
        if (array_key_exists('cpu_cores', $replica) && !self::atOrAboveFloor($replica['cpu_cores'], self::CORES_FLOOR)) {
            $read = max(self::CORES_FLOOR, ConfigValue::int($replica['cpu_cores'], self::CORES_FLOOR));

            $refusals[] = [
                'setting' => 'cpu_cores',
                'written' => $replica['cpu_cores'],
                'sentence' => sprintf(
                    'cpu_cores is %s, which the resolver reads as %d core%s',
                    self::describe($replica['cpu_cores']),
                    $read,
                    $read === self::CORES_FLOOR ? '' : 's',
                ),
            ];
        }

        if (array_key_exists('ram_gb', $replica) && !self::atOrAboveFloor($replica['ram_gb'], self::RAM_FLOOR)) {
            $refusals[] = [
                'setting' => 'ram_gb',
                'written' => $replica['ram_gb'],
                'sentence' => sprintf(
                    'ram_gb is %s, which the resolver reads as %s GB',
                    self::describe($replica['ram_gb']),
                    max(self::RAM_FLOOR, ConfigValue::float($replica['ram_gb'])),
                ),
            ];
        }

        return $refusals;
    }

    /**
     * Whether the read list disables this replica on purpose: a weight that reads as written
     * and is `0`.
     *
     * One value only, because it is the one the package documents as meaning something other
     * than a size, so the callers name such a replica rather than failing on it. A weight that
     * is negative, unreadable, or a fraction the read truncates to `0` is not this — each is a
     * value the resolver could not read, and `refusals()` reports it. `0`, `'0'`, `0.0` and
     * `false` are, because `ConfigValue` reads each of them as `0` without substituting.
     *
     * @param array<string, mixed> $replica
     */
    public static function disables(array $replica): bool
    {
        if (!array_key_exists('weight', $replica)) {
            return false;
        }

        $written = $replica['weight'];

        // Both halves are asked of the floor rather than of a `0` written out again: the reading
        // is the floor, and the value as a float is the floor too — which is what separates `0`,
        // `'0'`, `0.0` and `false` from the values that merely truncate onto it.
        return self::isReadable($written)
            && max(self::WEIGHT_FLOOR, ConfigValue::int($written)) === self::WEIGHT_FLOOR
            && ConfigValue::float($written) === (float) self::WEIGHT_FLOOR;
    }

    /**
     * "host:port", the identity a replica is keyed by — the health monitor keys failures by it
     * and a report names a replica with it, including one the pool has already dropped, which
     * is the case where the status table cannot be asked.
     *
     * It lives here rather than beside either caller because there were two copies of it
     * (`WeightResolver` built the pool key and `WeightedDatabaseManager` answered the question
     * for reports), and a boot that has to name a replica before the manager is resolved would
     * have been a third. Laravel allows `host` to be a list of hosts, so the first one is taken
     * — the same normalisation every reader has always used.
     *
     * @param array<string, mixed> $replica
     */
    public static function key(array $replica): string
    {
        $host = $replica['host'] ?? null;

        if (is_array($host)) {
            $host = $host[0] ?? null;
        }

        return ConfigValue::string($host, 'unknown').':'.ConfigValue::int($replica['port'] ?? null, 5432);
    }

    /**
     * A metadata value as a report reads it: the number it is, or the shape it is instead.
     *
     * `ReaderWindows::describe()` is the package's describer for a value a setting will not
     * read — strings quoted, arrays counted, anything else named by its type — and it is right
     * for every non-number here. A number is printed as itself, because "weight is int" says
     * nothing about *which* int, and the numbers this reports are the ones the resolver changed.
     * A bool is a number to `ConfigValue`, so it is printed as the number it is read as rather
     * than as "bool".
     */
    public static function describe(mixed $value): string
    {
        if (!self::isReadable($value)) {
            return ReaderWindows::describe($value);
        }

        $number = ConfigValue::float($value);

        return $number === floor($number) ? (string) (int) $number : (string) $number;
    }

    /**
     * Whether `ConfigValue` reads a number out of a value rather than falling back.
     *
     * The accepted set is `ConfigValue`'s own — an int, a float, a bool, or the string form of a
     * number — because the question this asks is "does the resolver substitute something for
     * this value", and a value `ConfigValue` reads is one it does not substitute for. A bool is
     * therefore readable: `weight: true` is read as 1 and `weight: false` as the documented 0,
     * and neither is a value anything was substituted for.
     */
    public static function isReadable(mixed $value): bool
    {
        return is_int($value) || is_float($value) || is_bool($value)
            || (is_string($value) && is_numeric($value));
    }

    /**
     * Whether a value is a number at or above the floor its key is read at — the same question as
     * isReadable(), plus the clamp the resolver applies on top of the read.
     */
    public static function atOrAboveFloor(mixed $value, float $floor): bool
    {
        return self::isReadable($value) && ConfigValue::float($value) >= $floor;
    }
}
