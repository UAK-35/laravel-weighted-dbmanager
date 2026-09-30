<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/bin/surface.php';

/**
 * The shared symbol reader's method shape, on its own.
 *
 * `bin/surface.php` is read through the commands that use it almost everywhere — a fixture package
 * is driven and its rows asserted — and the shape is the one part a fixture cannot state plainly: a
 * wrong count is a *plausible* string, so a case that only asserts "the row changed" passes whichever
 * count is written. It has been wrong once already, in the direction that reads as success: every
 * method was recorded as a single argument, so `1/1` stood for a five-argument signature and
 * `describeChange()`'s "needs N required argument(s)" could never move.
 *
 * The shape is `required/total`, then each argument as it is declared with a default rendered as
 * `=`. These cases are the boundaries a comma can be found on: a list where everything is required,
 * one where nothing is, a default that holds a comma of its own, and an attribute.
 */
final class SurfaceReaderTest extends TestCase
{
    public function test_a_shape_counts_the_arguments_of_a_method(): void
    {
        $this->assertSame('2/3 string $a, int $b, ?string $c=', self::shape('string $a, int $b, ?string $c = null'));
        $this->assertSame('0/0 ', self::shape(''));
        $this->assertSame('1/2 array $x=, int ...$rest', self::shape('array $x = [], int ...$rest'));
    }

    /**
     * A comma inside a nested call, inside a string, or after an attribute is not the comma between
     * two arguments — which is the whole of what the depth in the reader is for.
     */
    public function test_a_nested_call_or_an_attribute_stays_one_argument(): void
    {
        $this->assertSame('1/2 array $x=, string $y', self::shape('array $x = array_fill(0, 1, 2), string $y'));
        $this->assertSame('1/2 string $a=, int $b', self::shape("string \$a = 'x, y', int \$b"));
        $this->assertSame('2/2 #[Attr] string $a, int $b', self::shape('#[Attr] string $a, int $b'));
    }

    /**
     * A default is a fact about whether the argument can be omitted, not about what it falls back
     * to: the value itself is replaced by `=`, so two methods that differ only in a default compare
     * equal and the caller-visible property — this argument is optional — is the one recorded.
     */
    public function test_a_default_is_marked_rather_than_valued(): void
    {
        $this->assertSame('0/1 int $n=', self::shape('int $n = 1'));
        $this->assertSame('0/1 int $n=', self::shape('int $n = 99'));
        $this->assertSame('1/1 int $n', self::shape('int $n'));
    }

    /**
     * The shape of one method of a fixture class, read the way the inventory reads it.
     */
    private static function shape(string $parameters): string
    {
        $surface = fileSurface("<?php\n\nfinal class Fixture\n{\n    public function probe({$parameters}): void\n    {\n    }\n}\n");

        foreach ($surface as $key => $description) {
            if (str_starts_with($key, 'method:')) {
                return $description;
            }
        }

        self::fail('The reader found no method in the fixture, so this case asserts nothing.');
    }
}
