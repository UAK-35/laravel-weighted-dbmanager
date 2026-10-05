<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uak35\WeightedDbManager\Tests\Support\Readme;

/**
 * `bin/tools.php` against the two places that restate it: the README's tools table and
 * `composer.json`'s `require-dev`.
 *
 * WHY THIS EXISTS
 * ---------------
 *   The manifest is where a tool's package, the constraint it is on and the sentence on what it is
 *   for are written down, and both other files are readers of those same facts: the README table is
 *   what a person opens instead of a PHP file, and `composer.json` is what Composer itself enforces
 *   when the tools are installed. A restatement is not a guard — nothing fails when a pin is raised
 *   in one file and left alone in the other, or when a purpose is reworded in the manifest and the
 *   README keeps the old sentence, and both of those read as agreed in a diff because the two are
 *   never in it together.
 *
 *   So the README's table is read as data and compared cell by cell, the way the exit tables above
 *   it are, and the pins are compared with the constraints Composer resolves against. What this
 *   cannot see is the machine: whether the *installed* version is inside a pin depends on what a
 *   checkout has rather than on this tree, so that half is asked by the gate's own `tools` check in
 *   `bin/checks.php`, which reads the same records.
 *
 * THE PIN COLUMN
 * --------------
 *   A pin is the constraint `composer.json` states for the package, or `none` for a tool that
 *   arrives with something else — symfony/yaml comes in with testbench rather than being required
 *   here — which the manifest writes as `null`. Both directions are checked: a tool with a pin has
 *   to be in `require-dev` with exactly that constraint, and a tool without one has not to be in
 *   `require-dev` at all, so a pin cannot go missing by being dropped from either file on its own.
 */
final class ToolTableTest extends TestCase
{
    /** The README's tools table, named as `Readme` reads a heading: the hashes included. */
    private const TABLE = '### The tools this package installs';

    /** How the table writes the `null` a manifest uses for a tool nothing in this package requires. */
    private const NO_PIN = 'none';

    public function test_the_readme_table_is_the_manifest(): void
    {
        $tools = self::tools();
        $rows = Readme::table(self::TABLE, 'Tool');

        self::assertCount(
            count($tools),
            $rows,
            'The README tools table and bin/tools.php do not hold the same number of tools.',
        );

        foreach ($tools as $name => $tool) {
            $row = Readme::row($rows, 'Tool', $name);

            self::assertSame(
                Readme::plain($tool['package']),
                Readme::plain($row['Package']),
                "The package the README gives for {$name} is not the one bin/tools.php names.",
            );

            self::assertSame(
                Readme::plain($tool['pin'] ?? self::NO_PIN),
                Readme::plain($row['Pin']),
                "The pin the README gives for {$name} is not the one bin/tools.php states.",
            );

            self::assertSame(
                Readme::plain($tool['purpose']),
                Readme::plain($row['What it is for']),
                "What the README says {$name} is for is not the sentence bin/tools.php holds.",
            );
        }
    }

    public function test_every_pin_is_the_constraint_composer_json_asks_for(): void
    {
        $require = self::requireDev();

        foreach (self::tools() as $name => $tool) {
            if ($tool['pin'] === null) {
                self::assertArrayNotHasKey(
                    $tool['package'],
                    $require,
                    "bin/tools.php says {$name} has no pin, and composer.json requires the package.",
                );

                continue;
            }

            self::assertArrayHasKey(
                $tool['package'],
                $require,
                "bin/tools.php pins {$name}, and composer.json does not require the package.",
            );

            self::assertSame(
                $tool['pin'],
                $require[$tool['package']],
                "The pin bin/tools.php states for {$name} is not the constraint composer.json asks for.",
            );
        }
    }

    /**
     * The manifest, read with the `require` both of its readers use — so a record this test is
     * happy with and a reader is not cannot exist.
     *
     * @return array<string, array{package: string, pin: string|null, entry: string, purpose: string}>
     */
    private static function tools(): array
    {
        return require dirname(__DIR__, 3) . '/bin/tools.php';
    }

    /**
     * `require-dev` as `composer.json` states it, which is what Composer resolves an install against.
     *
     * @return array<string, string>
     */
    private static function requireDev(): array
    {
        $path = dirname(__DIR__, 3) . '/composer.json';
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException('No composer.json to compare the pins with: ' . $path);
        }

        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($manifest) || !isset($manifest['require-dev']) || !is_array($manifest['require-dev'])) {
            throw new RuntimeException('composer.json has no require-dev for the pins to be compared with.');
        }

        /** @var array<string, string> $require */
        $require = $manifest['require-dev'];

        return $require;
    }
}
