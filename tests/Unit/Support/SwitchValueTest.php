<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Uak35\WeightedDbManager\Support\SwitchValue;

/**
 * A switch is the one setting a cast cannot be trusted with, and this is the rule that
 * replaces the cast.
 *
 * `ConfigValue::bool()` narrows with PHP's semantics — `(bool) 'false'` is true, as its own
 * docblock says — so `swrr.pgcat.enabled`, `swrr.pgcat.use_reload` and
 * `swrr.allow_local_fallback` used to read the three commonest ways of writing *off* as *on*,
 * and `'maybe'` as on too, without a word. On the pgcat switch that arms a file swap.
 *
 * These tests pin the replacement contract: the spellings an operator actually writes are
 * read, everything else is refused and handed back as what was written, and a refusal lands
 * on the documented default rather than on a decision the package made up. Nothing here
 * decides severity — the boot audit and `db:doctor` do that — so each case is about the
 * classifier telling the truth about the value it was given.
 */
final class SwitchValueTest extends TestCase
{
    /**
     * Every spelling that means on, and every spelling that means off, including the case
     * and spacing an `.env` file or a yaml block will hand over.
     *
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function spellings(): array
    {
        return [
            'bool true' => [true, true],
            'bool false' => [false, false],
            'int one' => [1, true],
            'int zero' => [0, false],
            'float one' => [1.0, true],
            'float zero' => [0.0, false],
            'string one' => ['1', true],
            'string zero' => ['0', false],
            'string true' => ['true', true],
            'string false' => ['false', false],
            'string on' => ['on', true],
            'string off' => ['off', false],
            'string yes' => ['yes', true],
            'string no' => ['no', false],
            'capitalised' => ['True', true],
            'upper case' => ['OFF', false],
            'surrounding space' => ['  on  ', true],
            'a tab either side' => ["\tno\t", false],
        ];
    }

    #[DataProvider('spellings')]
    public function test_every_spelling_an_operator_writes_is_read_as_itself(mixed $value, bool $expected): void
    {
        $reading = SwitchValue::read($value, true);

        $this->assertSame($expected, $reading['on']);
        $this->assertNull($reading['refused'], 'a reading is not a refusal');
    }

    /**
     * The values that are neither on nor off, and which a cast decided anyway.
     *
     * The three ways of writing *off* that `(bool)` reads as true — `'false'`, `'off'`,
     * `'no'` — are deliberately *not* here: they are read, which is the whole reason this
     * class exists, and `spellings()` above is where each one is pinned.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function unreadable(): array
    {
        return [
            'a typo' => ['flase'],
            'a number that is neither' => [2],
            'half a switch' => [0.5],
            'an empty string' => [''],
            'an unknown word' => ['maybe'],
            'a trailing word' => ['true,'],
        ];
    }

    #[DataProvider('unreadable')]
    public function test_a_value_that_is_not_a_switch_is_refused_and_handed_back_as_written(mixed $value): void
    {
        $reading = SwitchValue::read($value, true);

        $this->assertTrue($reading['on'], 'a refusal resolves to the default, not to a decision of its own');
        $this->assertNotNull($reading['refused']);
    }

    public function test_a_refusal_is_described_the_way_every_other_report_describes_a_value(): void
    {
        // The same describer the reader-window refusals use, so `'flase'` is quoted and an
        // array is counted in the same words a windows report would use for it.
        $this->assertSame('"flase"', SwitchValue::read('flase', false)['refused']);
        $this->assertSame('""', SwitchValue::read('', false)['refused']);
        $this->assertSame('an array of one entry', SwitchValue::read(['on'], false)['refused']);
        $this->assertSame('stdClass', SwitchValue::read(new stdClass(), false)['refused']);
    }

    public function test_a_refusal_falls_back_to_the_default_the_caller_gives_it(): void
    {
        // `swrr.pgcat.enabled` documents false, `use_reload` documents true. One classifier,
        // two answers, because the default is a fact about the setting and not about switches.
        $this->assertFalse(SwitchValue::read('flase', false)['on']);
        $this->assertTrue(SwitchValue::read('flase', true)['on']);
    }

    public function test_a_setting_that_is_not_there_is_not_a_refusal(): void
    {
        // Nothing written is nothing to refuse: the documented default stands, and the boot
        // must not log a missing optional switch as a mistake.
        $reading = SwitchValue::read(null, true);

        $this->assertTrue($reading['on']);
        $this->assertNull($reading['refused']);
    }

    /**
     * Values the classifier has to answer without throwing: `ConfigValue` degrades a
     * non-scalar to its fallback, and this has to do the same, because a config file can hold
     * anything.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function notScalar(): array
    {
        return [
            'an array' => [['on']],
            'an object' => [new stdClass()],
        ];
    }

    #[DataProvider('notScalar')]
    public function test_a_value_that_is_not_scalar_is_refused_rather_than_cast(mixed $value): void
    {
        $reading = SwitchValue::read($value, true);

        $this->assertTrue($reading['on']);
        $this->assertNotNull($reading['refused']);
    }

    public function test_refused_switches_are_one_sentence_so_every_surface_names_them_the_same(): void
    {
        // The boot finding and the db:doctor row print this clause and nothing else for the
        // value itself, which is what keeps a log line and a table cell from describing the
        // same setting two ways.
        $this->assertSame(
            'swrr.pgcat.enabled is "flase", swrr.allow_local_fallback is "maybe"',
            SwitchValue::describeRefused([
                'swrr.pgcat.enabled' => '"flase"',
                'swrr.allow_local_fallback' => '"maybe"',
            ]),
        );
    }

    public function test_one_refused_switch_is_a_sentence_of_its_own(): void
    {
        $this->assertSame(
            'swrr.pgcat.use_reload is "yes, please"',
            SwitchValue::describeRefused(['swrr.pgcat.use_reload' => '"yes, please"']),
        );

        $this->assertSame('', SwitchValue::describeRefused([]), 'nothing refused says nothing');
    }

    public function test_the_accepted_shape_names_the_spellings_the_classifier_reads(): void
    {
        // The sentence is quoted by a refusal, so it has to describe what is accepted rather
        // than what was refused. Each spelling it names is read — asserted here, because a
        // message that lists a spelling the package then refuses is worse than no message.
        $accepted = SwitchValue::ACCEPTED;

        foreach (['true', 'false', '1', '0', 'on', 'off', 'yes', 'no'] as $spelling) {
            $this->assertStringContainsString("'".$spelling."'", $accepted);
            $this->assertNotNull(SwitchValue::resolve($spelling), "ACCEPTED names [{$spelling}]");
        }
    }

    public function test_resolve_answers_only_the_question_it_is_asked(): void
    {
        $this->assertTrue(SwitchValue::resolve('on'));
        $this->assertFalse(SwitchValue::resolve('off'));
        $this->assertNull(SwitchValue::resolve('maybe'));
        $this->assertNull(SwitchValue::resolve(null));
        $this->assertNull(SwitchValue::resolve(['on']));
    }
}
