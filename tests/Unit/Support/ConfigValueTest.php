<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use Uak35\WeightedDbManager\Support\ConfigValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * ConfigValue tests — the narrowing helpers every config read in the package
 * goes through.
 *
 * These lock two things that matter:
 *
 *   1. Scalar conversions keep PHP's cast semantics, so an installation that
 *      stores "86400" in .env behaves exactly as it did with `(int)`.
 *   2. Non-scalars fall back to the caller's default instead of becoming the
 *      literal string "Array" (or fatalling), which is what every raw cast in
 *      this package used to do with a malformed config file.
 *
 * Run: vendor/bin/phpunit tests/Unit/Support/ConfigValueTest.php --testdox
 */
class ConfigValueTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // string()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_string_passes_strings_through_untouched(): void
    {
        $this->assertSame('pgsql', ConfigValue::string('pgsql'));
        $this->assertSame('', ConfigValue::string(''));
    }

    /**
     * @return list<array{0: mixed, 1: string}>
     */
    public static function scalarToStringProvider(): array
    {
        return [
            'int' => [5432, '5432'],
            'negative int' => [-1, '-1'],
            'float' => [3.375, '3.375'],
            'true' => [true, '1'],
            'false' => [false, ''],
        ];
    }

    #[DataProvider('scalarToStringProvider')]
    public function test_string_stringifies_scalars_like_a_cast(mixed $value, string $expected): void
    {
        $this->assertSame($expected, ConfigValue::string($value));
    }

    public function test_string_falls_back_for_non_scalars_instead_of_yielding_array(): void
    {
        $this->assertSame('', ConfigValue::string(['10.0.0.1']));
        $this->assertSame('fallback', ConfigValue::string(['10.0.0.1'], 'fallback'));
        $this->assertSame('fallback', ConfigValue::string(null, 'fallback'));
        $this->assertSame('fallback', ConfigValue::string(new stdClass(), 'fallback'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // int()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array{0: mixed, 1: int}>
     */
    public static function toIntProvider(): array
    {
        return [
            'int' => [86400, 86400],
            'numeric string from env' => ['86400', 86400],
            'float truncates' => [86400.9, 86400],
            'true' => [true, 1],
            'false' => [false, 0],
        ];
    }

    #[DataProvider('toIntProvider')]
    public function test_int_matches_php_casting(mixed $value, int $expected): void
    {
        $this->assertSame($expected, ConfigValue::int($value));
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function nonNumericProvider(): array
    {
        return [
            'null' => [null],
            'array' => [[5432]],
            'object' => [new stdClass()],
            'words' => ['not-a-port'],
            'empty string' => [''],
        ];
    }

    #[DataProvider('nonNumericProvider')]
    public function test_int_uses_the_fallback_rather_than_guessing(mixed $value): void
    {
        $this->assertSame(5432, ConfigValue::int($value, 5432));
        $this->assertSame(0, ConfigValue::int($value));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // float()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array{0: mixed, 1: float}>
     */
    public static function toFloatProvider(): array
    {
        return [
            'float' => [3.375, 3.375],
            'int widens' => [3, 3.0],
            'numeric string from env' => ['3.375', 3.375],
            'true' => [true, 1.0],
        ];
    }

    #[DataProvider('toFloatProvider')]
    public function test_float_matches_php_casting(mixed $value, float $expected): void
    {
        $this->assertSame($expected, ConfigValue::float($value));
    }

    public function test_float_falls_back_for_junk(): void
    {
        $this->assertSame(0.0, ConfigValue::float(null));
        $this->assertSame(3.0, ConfigValue::float(['64'], 3.0));
        $this->assertSame(3.0, ConfigValue::float('64 GB', 3.0));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // bool()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `'false'` is deliberately true: these helpers mirror `(bool)`, and an
     * .env line written as `SWRR_ALLOW_LOCAL_FALLBACK="false"` has always meant
     * true to PHP. Changing that silently is worse than documenting it.
     *
     * @return list<array{0: mixed, 1: bool}>
     */
    public static function toBoolProvider(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            '1' => [1, true],
            '0' => [0, false],
            "'1'" => ['1', true],
            "'0'" => ['0', false],
            "'false' stays truthy" => ['false', true],
            'empty string' => ['', false],
        ];
    }

    #[DataProvider('toBoolProvider')]
    public function test_bool_matches_php_casting(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, ConfigValue::bool($value));
    }

    public function test_bool_falls_back_for_non_scalars(): void
    {
        $this->assertTrue(ConfigValue::bool(null, true));
        $this->assertTrue(ConfigValue::bool([], true), 'An array must take the fallback, not (bool) [] = false.');
        $this->assertFalse(ConfigValue::bool(['enabled'], false));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // assoc()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_assoc_returns_an_empty_array_for_non_arrays(): void
    {
        $this->assertSame([], ConfigValue::assoc(null));
        $this->assertSame([], ConfigValue::assoc('swrr'));
        $this->assertSame([], ConfigValue::assoc(86400));
    }

    public function test_assoc_stringifies_keys_and_keeps_every_value_verbatim(): void
    {
        $nested = ['start' => '10:00', 'end' => '14:20'];

        $result = ConfigValue::assoc([0 => 'zero', 'windows' => [$nested], 'ttl' => 86400]);

        $this->assertSame(['0' => 'zero', 'windows' => [$nested], 'ttl' => 86400], $result);
        $this->assertSame([$nested], $result['windows']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // assocList()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_assoc_list_drops_entries_that_are_not_arrays_and_reindexes(): void
    {
        $result = ConfigValue::assocList([
            ['host' => '10.1.0.1'],
            'not-a-replica',
            3 => ['host' => '10.1.0.2'],
            null,
        ]);

        $this->assertSame([
            ['host' => '10.1.0.1'],
            ['host' => '10.1.0.2'],
        ], $result);
    }

    public function test_assoc_list_of_nothing_is_an_empty_list(): void
    {
        $this->assertSame([], ConfigValue::assocList(null));
        $this->assertSame([], ConfigValue::assocList('whatever'));
        $this->assertSame([], ConfigValue::assocList([]));
    }

    public function test_assoc_list_normalises_key_types_inside_each_entry(): void
    {
        $this->assertSame(
            [['0' => '10.1.0.1', 'port' => 5432]],
            ConfigValue::assocList([['10.1.0.1', 'port' => 5432]]),
        );
    }
}
