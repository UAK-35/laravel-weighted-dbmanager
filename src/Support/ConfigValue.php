<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

/**
 * ConfigValue
 *
 * Narrowing helpers for values read out of the configuration repository.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `config()` and `Repository::get()` are typed `mixed` — correctly, because a
 *   config file can hold anything. Every consumer in this package was therefore
 *   doing `(string) $config->get(...)`, which is both a lie to static analysis
 *   (anything can be cast to string) and a runtime hazard: an array in the
 *   config file casts to the literal "Array" and a nested object to a fatal
 *   error. These helpers make the narrowing explicit and give PHPStan enough
 *   information to check the call sites at level 9 and above.
 *
 * CONVERSION SEMANTICS
 * --------------------
 *   Scalar-to-scalar conversion matches the PHP casts this package used before,
 *   so existing installations behave identically:
 *
 *     ConfigValue::int('86400')   === 86400    (numeric string)
 *     ConfigValue::int(86400.9)   === 86400    (truncates, like (int))
 *     ConfigValue::bool('0')      === false    (like (bool))
 *     ConfigValue::bool('false')  === true     (like (bool) — PHP semantics)
 *
 *   The difference is the fallback: `(string) []` used to produce "Array";
 *   these helpers return the caller's fallback instead, so a malformed config
 *   file degrades to the documented default rather than to a garbage value.
 */
final class ConfigValue
{
    /**
     * Not instantiable — a utility class with only static methods/references
     * these are pure functions over config values.
     */
    private function __construct()
    {
    }

    /**
     * A string config value. Non-scalars fall back instead of casting to
     * "Array".
     */
    public static function string(mixed $value, string $fallback = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $fallback;
    }

    /**
     * An int config value. Accepts numeric strings ("86400") and truncates
     * floats, exactly like `(int)`.
     */
    public static function int(mixed $value, int $fallback = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $fallback;
    }

    /**
     * A float config value. Accepts numeric strings and widens ints, exactly
     * like `(float)`.
     */
    public static function float(mixed $value, float $fallback = 0.0): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value) || is_bool($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $fallback;
    }

    /**
     * A bool config value, using PHP's cast semantics rather than a truthy
     * string parser — see the class docblock.
     */
    public static function bool(mixed $value, bool $fallback = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return (bool) $value;
        }

        return $fallback;
    }

    /**
     * An associative array config value (a `swrr` block, a connection config,
     * a `pgcat` block). Non-arrays yield an empty array.
     *
     * @return array<string, mixed>
     */
    public static function assoc(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    /**
     * A list of associative arrays — a connection's `read` replica list, for
     * example. Entries that are not arrays are dropped and the result is
     * re-indexed, so callers can treat it as a real `list<array>`.
     *
     * @return list<array<string, mixed>>
     */
    public static function assocList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $list[] = self::assoc($item);
            }
        }

        return $list;
    }
}
