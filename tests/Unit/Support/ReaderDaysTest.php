<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Support\ReaderDays;

/**
 * `swrr.reader_days` arrives from `.env` as a string as often as not, and the two
 * questions asked of it — which days did the setting produce, and which of those can
 * the resolver match at all — are asked by the boot audit and by `db:doctor`. They live
 * here so a row and a warning cannot disagree about whether a configuration can ever
 * put a read on a replica.
 *
 * The leniency is deliberate: `(array) '3'` used to produce `['3']`, which never
 * matched the resolver's integer comparison and silently pushed every read onto the
 * writer. A bare scalar now means that single day.
 *
 * The refusal is deliberate too, and mirrors `reader_windows` one key over: `'1,2,3'`
 * is a list written as one string, which is how a list is written in `.env` and not
 * how this setting is read. Dropping it leaves no day at all, and no day at all makes
 * the resolver permissive — reads use the replica pool all week, the opposite of what
 * a day list is for.
 */
final class ReaderDaysTest extends TestCase
{
    public function test_a_bare_scalar_from_env_means_that_single_day(): void
    {
        $this->assertSame([3], ReaderDays::normalise('3'));
    }

    public function test_numeric_strings_and_ints_both_become_day_numbers(): void
    {
        $this->assertSame([1, 2, 3], ReaderDays::normalise(['1', 2, '3']));
    }

    public function test_entries_that_are_not_day_numbers_are_named_rather_than_left_out(): void
    {
        $split = ReaderDays::split([1, 'monday', null, true, [2]]);

        // The day that is a day still applies...
        $this->assertSame([1], $split['usable']);
        $this->assertSame([1], ReaderDays::normalise([1, 'monday', null, true, [2]]));

        // ...and every entry that is not one is reported with its position and its
        // value, because "no usable day" names neither the mistake nor its fix.
        $this->assertNull($split['shape']);
        $this->assertSame(['[1]', '[2]', '[3]', '[4]'], array_column($split['rejected'], 'at'));
        $this->assertSame('"monday"', $split['rejected'][0]['entry']);
        $this->assertSame('null', $split['rejected'][1]['entry']);
        $this->assertSame('bool', $split['rejected'][2]['entry']);
        $this->assertSame('an array of one entry', $split['rejected'][3]['entry']);

        $this->assertSame(
            'reader_days[1] is "monday"',
            ReaderDays::describeRejected([$split['rejected'][0]]),
        );
    }

    public function test_a_list_written_as_one_string_is_a_shape_problem(): void
    {
        // The `.env` spelling of a list: one string, no array. It is refused as the
        // value's shape rather than as an entry, matching how a flat window string is
        // refused one key over.
        foreach (['1,2,3', '1 2 3', 'mon,tue', 'monday'] as $written) {
            $split = ReaderDays::split($written);

            $this->assertSame([], $split['usable'], $written);
            $this->assertSame([], $split['rejected'], $written);
            $this->assertSame('"'.$written.'"', $split['shape'], $written);
        }
    }

    public function test_a_bare_scalar_day_is_a_single_day_and_not_a_shape_problem(): void
    {
        $split = ReaderDays::split('3');

        $this->assertSame([3], $split['usable']);
        $this->assertNull($split['shape']);
        $this->assertSame([], $split['rejected']);
    }

    public function test_an_array_of_numeric_strings_is_still_a_day_list(): void
    {
        // The other way the same intent is written, and the accepted one.
        $this->assertSame([1, 2, 3], ReaderDays::normalise(['1', '2', '3']));
        $this->assertSame([], ReaderDays::split(['1', '2', '3'])['rejected']);
    }

    public function test_a_list_with_nothing_readable_in_it_comes_back_empty(): void
    {
        // Unset, or an empty array: nothing refused, and nothing to apply.
        $this->assertSame([], ReaderDays::normalise(null));
        $this->assertSame([], ReaderDays::normalise([]));
        $this->assertNull(ReaderDays::split([])['shape']);
        $this->assertSame([], ReaderDays::split([])['rejected']);
    }

    public function test_the_iso_range_keeps_one_to_seven_and_nothing_else(): void
    {
        // Out-of-range days are not empty, which matters: an empty list makes the
        // resolver permissive, while 0 and 8 make it strict — the pool is never used.
        $this->assertSame([1, 7], ReaderDays::inIsoRange([0, 1, 7, 8, -1]));
        $this->assertSame([], ReaderDays::inIsoRange([0, 8]));
    }

    /**
     * The repair a report can print: the days the setting names, written the way the
     * setting reads them, or nothing at all.
     *
     * `'1,2,3'` is the one spelling that reduces, because it is the `.env` way of writing
     * a list and every piece of it is already a day number — the sentence on the row asks
     * for exactly the array that comes out. The `null` rows are the other half of the
     * line: a name is not a day list and neither is a nested array, and guessing at either
     * would move reads to days the operator never chose.
     *
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function suggestionProvider(): array
    {
        return [
            'the whole value written as one string becomes the days it names' => ['1,2,3', '[1, 2, 3]'],
            'the same, beside a day that is already a day' => [['1', '1,2,3'], '[1, 2, 3]'],
            'a day list that is already a day list is spelled the way it reads' => [[1, '2'], '[1, 2]'],
            'the same day twice is the same day' => [[1, '1'], '[1]'],
            'a token is read by the rule a single entry is read by' => ['1.5,3', '[1, 3]'],
            'a name is not guessed at' => [['1', 'mon'], null],
            'a nested list beside a day stops the suggestion' => [[1, [2, 3]], null],
            'nothing configured suggests nothing' => [null, null],
        ];
    }

    #[DataProvider('suggestionProvider')]
    public function test_the_repair_it_can_print_is_the_days_it_names(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, ReaderDays::suggestion($value));
    }
}
