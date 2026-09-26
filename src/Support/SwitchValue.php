<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

/**
 * An on/off setting, read as the value it was written as.
 *
 * WHY THIS EXISTS
 * ---------------
 * `ConfigValue::bool()` narrows a value with PHP's cast semantics, and the class docblock
 * says what that means: `(bool) 'false'` is `true`. That is the right rule for a value this
 * package computed and the wrong one for a switch a person wrote — `'false'`, `'off'` and
 * `'no'` are how off is written, and not one of them is off under a cast. The other half of
 * the same hazard is `'maybe'`, `''` or `2`: nothing about them says on or off, and a cast
 * decides anyway.
 *
 * A switch is therefore read from a closed list of spellings, and a value that is not one of
 * them is *refused*: the setting resolves to the default it documents, and the refusal is
 * reported — a boot finding at `error` level and a `db:doctor` row — rather than decided in
 * silence. That is the rule the reader-window settings already follow, for the same reason: a
 * typo must not turn into the opposite of what was meant without anybody being told.
 *
 * The spellings are the ones an operator is already writing in `.env`, in yaml and in a
 * published config file, so the refusal catches mistakes rather than accents. Case and
 * surrounding space are not part of the value: `'On'` and `' on '` are the same switch.
 */
final class SwitchValue
{
    /** The spellings that mean on, compared after trimming and lower-casing. */
    private const ON = ['1', 'true', 'on', 'yes'];

    /** The spellings that mean off. */
    private const OFF = ['0', 'false', 'off', 'no'];

    /** The shape a switch reads, quoted in every report that refuses one. */
    public const ACCEPTED = "a switch is on or off — true/false, 1/0, or the strings '1'/'0', 'true'/'false', 'on'/'off', 'yes'/'no'";

    private function __construct()
    {
    }

    /**
     * A switch read as on or off, and the value that could not be read if there was one.
     *
     * `$value` is `null` for a setting that is not there, and that is not a refusal: nothing
     * was written, so there is nothing to read and the documentation's default stands. A
     * value that *is* there and is not a spelling resolves to the same default — the package
     * degrades a malformed setting to the value it documents rather than to a garbage value,
     * which is the promise `ConfigValue` already makes — and is reported, which that path
     * was not.
     *
     * The default is the caller's, because a switch's default is a fact about the setting
     * rather than about switches: `swrr.pgcat.enabled` documents `false`, `use_reload`
     * documents `true`, and neither is derivable from "this is a switch". Each caller passes
     * the value its own setting's documentation prints, so a refusal lands where an absent
     * value would rather than on a value the setting never promised.
     *
     * @return array{on: bool, refused: string|null} `refused` is the value as it was written
     */
    public static function read(mixed $value, bool $default): array
    {
        if ($value === null) {
            return ['on' => $default, 'refused' => null];
        }

        $on = self::resolve($value);

        if ($on !== null) {
            return ['on' => $on, 'refused' => null];
        }

        return ['on' => $default, 'refused' => ReaderWindows::describe($value)];
    }

    /**
     * The value as on or off, or `null` when it is neither.
     *
     * Public because a report asks this question without reading the switch: `db:doctor` and
     * the boot audit both quote the value they refused, and a status array can then be asked
     * whether a switch is readable without a second spelling of the rule.
     */
    public static function resolve(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        // A number is on at 1 and off at 0. The comparison is loose on purpose: 0.0 and 0 are
        // the same switch, and 0.5 is neither.
        if (is_int($value) || is_float($value)) {
            return match (true) {
                $value == 0 => false,
                $value == 1 => true,
                default => null,
            };
        }

        if (is_string($value)) {
            $spelling = strtolower(trim($value));

            if (in_array($spelling, self::ON, true)) {
                return true;
            }

            if (in_array($spelling, self::OFF, true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * The refused switches as one sentence, one clause each, in the order given —
     * `swrr.pgcat.enabled is "flase"`.
     *
     * Shared so that the boot finding and the preflight name the same settings the same way,
     * the way `ReaderWindows::describeRejected()` is shared by the windows finding, the days
     * finding and the row that reports both.
     *
     * @param array<string, string> $refused setting => the value as it was written
     */
    public static function describeRefused(array $refused): string
    {
        return implode(', ', array_map(
            static fn (string $setting, string $value): string => $setting.' is '.$value,
            array_keys($refused),
            array_values($refused),
        ));
    }
}
