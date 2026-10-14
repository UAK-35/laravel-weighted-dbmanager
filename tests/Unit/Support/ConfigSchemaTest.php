<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use Uak35\WeightedDbManager\Support\ConfigSchema;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The generation of a published config's key set, and what a copy behind it is told.
 *
 * `config/db-manager.php` is copied into an application once and edited there, and nothing writes
 * over it again unless somebody asks for `--force`. So a key added to this package's sample after
 * that moment is a key the installation never receives: it reads the default, nothing is malformed
 * and no row fails, and the package behaves as the release the file was published from while the
 * changelog describes a release that does something else. The generation is what makes that
 * answerable, and these tests pin the two halves that matter — the number the sample declares is
 * the number the class ships, and every shape a reading can come in gets a sentence of its own.
 *
 * The sentences are asserted by what they name rather than word for word, because what a reader
 * acts on is which generations are being compared, which release each is and what to do about it;
 * a test that pinned the whole sentence would fail on a rewording that kept all of that true, and
 * would pass on one that dropped the release it is named for only because the words around it
 * moved.
 */
final class ConfigSchemaTest extends TestCase
{
    /**
     * The literal in the sample is the class's own number, in both directions.
     *
     * This is the binding the whole reading rests on: a generation raised in the class and not in
     * the file would make every freshly published copy read as stale the moment it landed, and a
     * number raised in the file alone would make a copy behind it read as current — the silence the
     * reading exists to break. Neither is visible in a diff of either file, which is why the pair is
     * held here rather than left to whoever raises one of them.
     */
    public function test_the_sample_config_declares_the_generation_this_class_ships(): void
    {
        $sample = require dirname(__DIR__, 3).'/config/db-manager.php';

        $this->assertIsArray($sample, 'the sample config returns an array');
        $this->assertArrayHasKey(
            ConfigSchema::KEY,
            $sample,
            sprintf('the sample config declares no `%s`, so a published copy cannot declare one either', ConfigSchema::KEY),
        );

        $reading = ConfigSchema::of($sample);

        $this->assertSame(
            ConfigSchema::GENERATION,
            $reading['declared'],
            "config/db-manager.php declares {$sample[ConfigSchema::KEY]} where Support\\ConfigSchema ships generation ".ConfigSchema::GENERATION,
        );
    }

    /**
     * Every generation this class ships is one a warning can name the release of.
     *
     * The map is what makes a stale copy's sentence say *when* the key set moved rather than only
     * that it did, and a generation added without an entry would leave that warning naming the
     * number twice. The release is a version the notes weigh, so an entry for a version no tag
     * carries yet is expected — that is the state between the commit that raises the generation and
     * the release that publishes it.
     */
    public function test_every_generation_names_the_release_that_introduced_it(): void
    {
        for ($generation = 1; $generation <= ConfigSchema::GENERATION; $generation++) {
            $this->assertArrayHasKey(
                $generation,
                ConfigSchema::RELEASED_IN,
                sprintf('generation %d ships and no release is named for it', $generation),
            );

            $this->assertMatchesRegularExpression(
                '/^v\d+\.\d+\.\d+/',
                ConfigSchema::RELEASED_IN[$generation],
                sprintf('generation %d names something that is not a version', $generation),
            );
        }

        $this->assertSame(
            'v0.2.0',
            ConfigSchema::release(ConfigSchema::GENERATION),
            'the release a generation is named by is the version the notes weigh, not the number again',
        );
    }

    /**
     * The three shapes a reading comes in, and the fourth that is the same as the first: a value the
     * class cannot read is *not* a file that declares nothing, because the two want different
     * repairs — republish, or fix the value somebody wrote.
     *
     * @return array<string, array{0: mixed, 1: bool, 2: int|null}>
     */
    public static function readings(): array
    {
        return [
            'the number' => [['generation' => 3], true, 3],
            'a numeric string, which is what an env() declaration yields' => [['generation' => '4'], true, 4],
            'a numeric string with the whitespace an env file leaves' => [['generation' => " 5 \n"], true, 5],
            'a negative number' => [['generation' => -1], true, -1],
            'no key at all' => [['swrr' => ['connection' => null]], false, null],
            'the key with a word' => [['generation' => 'five'], true, null],
            'the key with a float' => [['generation' => 1.5], true, null],
            'the key with an array' => [['generation' => [1]], true, null],
            'the key with null' => [['generation' => null], true, null],
            'not an array at all' => ['generation', false, null],
        ];
    }

    #[DataProvider('readings')]
    public function test_a_reading_comes_in_three_shapes(mixed $config, bool $present, ?int $declared): void
    {
        $reading = ConfigSchema::of($config);

        $this->assertSame($present, $reading['present'], 'a file carrying the key is not reported as one that does not');
        $this->assertSame($declared, $reading['declared']);

        if ($present) {
            $this->assertSame(
                $config[ConfigSchema::KEY] ?? null,
                $reading['raw'],
                'the value somebody wrote is quoted back rather than resolved to a default',
            );
        }
    }

    /**
     * A copy at the package's own generation is silent: the row that reads this has already said
     * whether the file exists and whether anything in it was reviewed, and this reading only adds.
     */
    public function test_a_file_at_this_generation_has_nothing_to_say(): void
    {
        $this->assertNull(ConfigSchema::sentence(['present' => true, 'declared' => ConfigSchema::GENERATION, 'raw' => ConfigSchema::GENERATION]));
    }

    /**
     * A file behind the package is told both generations and both releases, and what to do: the
     * number alone would leave an installer comparing two numbers to a changelog they do not have.
     */
    public function test_a_file_behind_the_package_names_both_generations_and_what_to_do(): void
    {
        $sentence = ConfigSchema::sentence(['present' => true, 'declared' => -3, 'raw' => -3]);

        $this->assertNotNull($sentence);
        $this->assertStringContainsString('-3', $sentence);
        $this->assertStringContainsString((string) ConfigSchema::GENERATION, $sentence);
        $this->assertStringContainsString(ConfigSchema::release(ConfigSchema::GENERATION), $sentence);
        $this->assertStringContainsString('republish', $sentence);
        $this->assertStringContainsString('defaults', $sentence, 'the cost of a stale copy is what it reads instead');
    }

    /**
     * A file published before the key existed is the ordinary case rather than an error — every copy
     * published from a release before this one is in it — so the sentence names the release that
     * introduced the key and says the file predates it rather than reporting a number of zero.
     */
    public function test_a_file_published_before_the_key_is_reported_as_predating_it(): void
    {
        $sentence = ConfigSchema::sentence(['present' => false, 'declared' => null, 'raw' => null]);

        $this->assertNotNull($sentence);
        $this->assertStringContainsString(ConfigSchema::KEY, $sentence);
        $this->assertStringContainsString(ConfigSchema::release(ConfigSchema::GENERATION), $sentence);
        $this->assertStringContainsString('before the key existed', $sentence);
    }

    /**
     * The value somebody wrote is quoted back rather than resolved to a default: a file declaring
     * `'generation' => 'five'` has a value to fix, and reporting it as one that declares nothing
     * would send an installer to republish a file whose problem is the line in it.
     */
    public function test_a_value_that_is_not_a_whole_number_is_reported_as_unreadable(): void
    {
        $sentence = ConfigSchema::sentence(['present' => true, 'declared' => null, 'raw' => 'five']);

        $this->assertNotNull($sentence);
        $this->assertStringContainsString("'five'", $sentence);
        $this->assertStringContainsString(ConfigSchema::KEY, $sentence);
        $this->assertStringNotContainsString('before the key existed', $sentence, 'this file has the key; the value is the fault');
    }

    /**
     * A file from a newer release is not a fault of the installation's, and it is not silence
     * either: the extra keys it carries are ignored by this package, which is a fact a reader
     * rolling a package back wants to see rather than infer.
     */
    public function test_a_file_from_a_newer_release_says_the_extra_keys_are_ignored(): void
    {
        $sentence = ConfigSchema::sentence(['present' => true, 'declared' => ConfigSchema::GENERATION + 2, 'raw' => ConfigSchema::GENERATION + 2]);

        $this->assertNotNull($sentence);
        $this->assertStringContainsString((string) (ConfigSchema::GENERATION + 2), $sentence);
        $this->assertStringContainsString('ignored', $sentence);
        $this->assertStringNotContainsString('republish', $sentence, 'there is nothing to repair here');
    }
}
