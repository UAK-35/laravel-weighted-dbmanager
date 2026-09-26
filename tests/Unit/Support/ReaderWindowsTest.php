<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Uak35\WeightedDbManager\Support\ReaderWindows;

/**
 * `swrr.reader_windows` is written by hand, and the way a person naturally writes a
 * window — the flat string `'10:00-14:20'` — is not one. It used to be dropped without a
 * word, which left the resolver reading an empty list: permissive, so reads used the
 * replica pool at every hour, the opposite of the fallback the setting described.
 *
 * These tests pin the replacement contract, and they are also what the boot audit and
 * `db:doctor` report from: an entry that is not a window comes back *named*, with the
 * shape it should have had. Nothing here decides severity — the callers do — so each
 * case is about the classifier telling the truth about the value it was given.
 */
final class ReaderWindowsTest extends TestCase
{
    public function test_a_flat_string_entry_is_rejected_and_named_by_position(): void
    {
        $split = ReaderWindows::split(['10:00-14:20']);

        $this->assertSame([], $split['usable']);
        $this->assertNull($split['shape'], 'the list itself is well formed');
        $this->assertSame([['at' => '[0]', 'entry' => '"10:00-14:20"']], $split['rejected']);

        $this->assertSame(
            'reader_windows[0] is "10:00-14:20"',
            ReaderWindows::describeRejected($split['rejected']),
        );
    }

    public function test_the_whole_setting_written_as_one_flat_string_is_refused_as_a_shape(): void
    {
        // The same mistake one level up: the value is there, and it is not something
        // that can be read as windows at all.
        $split = ReaderWindows::split('10:00-14:20');

        $this->assertSame([], $split['usable']);
        $this->assertSame([], $split['rejected'], 'there is no list, so there are no entries to blame');
        $this->assertSame('"10:00-14:20"', $split['shape']);
    }

    public function test_a_window_written_without_its_outer_list_is_refused_entry_by_entry(): void
    {
        // `['start' => …, 'end' => …]` instead of `[['start' => …, 'end' => …]]` — the
        // one-character mistake that used to produce the same silent permissive mode.
        $split = ReaderWindows::split(['start' => '10:00:00', 'end' => '14:20:00']);

        $this->assertSame([], $split['usable']);
        $this->assertSame(
            'reader_windows["start"] is "10:00:00", reader_windows["end"] is "14:20:00"',
            ReaderWindows::describeRejected($split['rejected']),
        );
    }

    public function test_a_refused_entry_does_not_take_the_well_formed_windows_with_it(): void
    {
        $split = ReaderWindows::split([
            ['start' => '10:00:00', 'end' => '14:20:00'],
            '17:00-20:30',
        ]);

        $this->assertSame([['start' => '10:00:00', 'end' => '14:20:00']], $split['usable']);
        $this->assertSame(
            'reader_windows[1] is "17:00-20:30"',
            ReaderWindows::describeRejected($split['rejected']),
        );
    }

    public function test_a_missing_bound_stays_missing_so_the_resolver_keeps_its_default(): void
    {
        $split = ReaderWindows::split([['start' => '10:00:00']]);

        $this->assertSame([['start' => '10:00:00']], $split['usable']);
        $this->assertSame([], $split['rejected']);
        $this->assertArrayNotHasKey('end', $split['usable'][0]);
    }

    public function test_scalar_bounds_are_stringified_the_way_the_resolver_reads_them(): void
    {
        $split = ReaderWindows::split([['start' => '10:00', 'end' => 14]]);

        $this->assertSame([['start' => '10:00', 'end' => '14']], $split['usable']);
    }

    public function test_nothing_configured_is_not_a_rejection(): void
    {
        foreach ([null, []] as $value) {
            $split = ReaderWindows::split($value);

            $this->assertSame([], $split['usable']);
            $this->assertSame([], $split['rejected']);
            $this->assertNull($split['shape'], 'the documented opt-out must stay silent');
        }
    }

    public function test_a_string_value_is_reported_quoted(): void
    {
        // An unquoted `10:00-14:20` in a row reads as prose; quoted, it is visibly the
        // value the operator wrote.
        $this->assertSame('"10:00-14:20"', ReaderWindows::describe('10:00-14:20'));
    }

    /**
     * @return list<array{0: mixed, 1: string}>
     */
    public static function describedValueProvider(): array
    {
        return [
            'int' => [42, 'int'],
            'bool' => [true, 'bool'],
            'object' => [new stdClass(), stdClass::class],
            'empty array' => [[], 'an array of 0 entries'],
            'two-entry array' => [['a', 'b'], 'an array of 2 entries'],
            'null' => [null, 'null'],
        ];
    }

    #[DataProvider('describedValueProvider')]
    public function test_a_non_string_value_is_described_by_its_type(mixed $value, string $expected): void
    {
        $this->assertSame($expected, ReaderWindows::describe($value));
    }

    public function test_the_accepted_sentence_names_the_shape_and_the_flat_string(): void
    {
        // The sentence is embedded verbatim in a log line and in a `db:doctor` row, so
        // it is the one place an operator reads what was expected.
        $this->assertStringContainsString("['start' => '10:00:00', 'end' => '14:20:00']", ReaderWindows::ACCEPTED);
        $this->assertStringContainsString("'10:00-14:20' is not a window", ReaderWindows::ACCEPTED);
    }

    /**
     * The repair a report can print. A refused value reduces to one exact replacement when
     * it is a range carrying both bounds, and to nothing at all when it is a value the
     * package will not interpret — the same line the refusal itself draws.
     *
     * The `null` rows are the load-bearing half. A suggestion for an overnight range would
     * hand back a window whose start is not before its end, which the resolver can never
     * enter: that trades a refusal for a warning, and it is exactly the guess the refusal
     * exists to prevent. A suggestion for `'10:00 to 14:20'` would be the package deciding
     * what a hand-written range meant.
     *
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function suggestionProvider(): array
    {
        return [
            'a flat entry becomes the window it names' => [
                ['10:00-14:20'],
                "[['start' => '10:00:00', 'end' => '14:20:00']]",
            ],
            'the whole setting written flat becomes a list of one window' => [
                '10:00-14:20',
                "[['start' => '10:00:00', 'end' => '14:20:00']]",
            ],
            'a window written without its outer list gets the list back' => [
                ['start' => '10:00:00', 'end' => '14:20:00'],
                "[['start' => '10:00:00', 'end' => '14:20:00']]",
            ],
            'the well-formed windows beside it are left exactly as written' => [
                [['start' => '10:00', 'end' => '14:20', 'note' => 'keep me'], '17:00-20:30'],
                "[['start' => '10:00', 'end' => '14:20', 'note' => 'keep me'], ['start' => '17:00:00', 'end' => '20:30:00']]",
            ],
            'a bound the resolver would silently read as midnight gets nothing' => [['9:00-14:20'], null],
            'an overnight range gets nothing — the resolver cannot express one' => [['22:00-06:00'], null],
            'a range whose start is not before its end gets nothing' => [['14:20-10:00'], null],
            'a range with one side missing gets nothing' => [['10:00-'], null],
            'a string that is not a range gets nothing' => [['10:00 to 14:20'], null],
            'one entry that cannot be re-spelled stops the whole suggestion' => [
                [['start' => '10:00:00', 'end' => '14:20:00'], new stdClass()],
                null,
            ],
        ];
    }

    #[DataProvider('suggestionProvider')]
    public function test_the_repair_it_can_print_is_the_value_re_spelled(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, ReaderWindows::suggestion($value));
    }

    public function test_a_suggestion_is_not_a_repair(): void
    {
        // The classifier still refuses what it refused; the suggestion is printed beside
        // that verdict, never applied in its place.
        $split = ReaderWindows::split(['10:00-14:20']);

        $this->assertSame([], $split['usable']);
        $this->assertCount(1, $split['rejected']);
        $this->assertNotNull(ReaderWindows::suggestion(['10:00-14:20']));
    }

    public function test_a_literal_is_written_the_way_configuration_spells_it(): void
    {
        // One line, short array syntax, keys quoted only when they are strings — the
        // shape a value has in the file the operator is about to edit.
        $this->assertSame(
            "['start' => '10:00:00', 'keep' => true, 'none' => null, 'days' => [1, 2], 'apostrophe' => 'it\\'s']",
            ReaderWindows::literal([
                'start' => '10:00:00',
                'keep' => true,
                'none' => null,
                'days' => [1, 2],
                'apostrophe' => "it's",
            ]),
        );

        $this->assertSame('[]', ReaderWindows::literal([]));
        $this->assertSame("[0 => 'a', 'k' => 'b']", ReaderWindows::literal([0 => 'a', 'k' => 'b']));
    }
}
