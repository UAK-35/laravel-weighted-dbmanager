<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PhpToken;
use ReflectionClass;
use RuntimeException;
use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Database\Weighted\WeightResolver;
use Uak35\WeightedDbManager\Pgcat\VariantShape;
use Uak35\WeightedDbManager\Support\ReplicaMetadata;
use Uak35\WeightedDbManager\Tests\Support\NumberWords;

/**
 * The numbers a comment in `src/` states about the collection it stands beside, held to it.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `ProseNumbersTest` binds every count a record states about this package's code. The other half
 *   of the same problem was unbounded: the docblocks in `src/` state counts too — "the other three
 *   come from the route's report", "there are three reasons a replica is missing from it", "the
 *   three settings, in the order a refusal reads" — and a sentence like that is a restatement of a
 *   table declared ten lines below it. Nothing failed when the table grew a member: the comment
 *   stayed grammatical, the constant stayed correct, and a reader learned the wrong number at the
 *   exact moment they were relying on it. That is the failure `ProseNumbersTest`'s record describes
 *   for prose, one directory closer to the code.
 *
 * THE RULE
 * --------
 *   **A count a comment in `src/` states about a collection is compared with that collection.**
 *   The comment may still say the number — a reader of a class learns its shape from a sentence,
 *   not from `count(...)` — but the sentence is now a claim, and adding a row to the table without
 *   the sentence changes the derivation and fails here.
 *
 * Four properties are deliberate, and the first three are `ProseNumbersTest`'s read from the other
 * side of the package:
 *
 *   1. **The words are the subject.** Each claim pins the sentence it is written in, the pattern
 *      has to match, and *every* match has to state the same number — so a comment saying "three"
 *      in its opening paragraph and "four" in its body fails on the second. A reworded sentence
 *      fails rather than quietly passing, because a guard that has stopped finding its subject
 *      reports agreement with a claim it never read.
 *   2. **The number is a word, and the comparison is numeric.** The comments spell numbers out, so
 *      the capture is mapped through `NumberWords`; a comment that started writing `3` fails here
 *      rather than being skipped.
 *   3. **The expected value is computed, never written.** Every claim below reads a constant, a
 *      constant family or the keys a sentence names — this table holds no number of its own.
 *   4. **It is a comment, and it is that collection's comment.** The sentences are read out of the
 *      file's `T_COMMENT`/`T_DOC_COMMENT` tokens, so a sentence moved into a string literal cannot
 *      satisfy a claim, and each claim names the declaration its comment sits above (`beside`), so
 *      a sentence moved onto a different table — still in a comment, still saying the right number
 *      about the wrong thing — fails instead of passing.
 *
 * WHAT THIS FILE DOES NOT COVER
 * -----------------------------
 *   A count in `src/` that nobody has claimed here. The claims are the guard, and this table is
 *   where the next one is added; the boundary is written down rather than implied because a
 *   sentence about the *design* — "two callers", "three readers", "four documents" — is a statement
 *   about a shape rather than a restatement of a table, and one of those cannot be derived. When a
 *   comment states the size of a table next to it, add a row here in the same change: the
 *   collection is the declaration the comment stands above, and the expectation is a `count()` of
 *   it or a reading of the family it names.
 */
final class CommentCountsTest extends TestCase
{
    /**
     * Every count a comment in `src/` states about the collection beside it.
     *
     * `pinned` captures the number word, `beside` is the declaration the comment is attached to,
     * `counts` says what the number is about, and `expected` is derived — from the constant itself,
     * from the family its name closes, or from the keys a sentence names.
     *
     * @return array<string, array{file: string, beside: string, pinned: string, counts: string, expected: int}>
     */
    public static function claims(): array
    {
        return [
            'the keys `write()` does not supply, as `JsonEnvelope::CORE`\'s comment states them' => [
                'file' => 'src/Console/JsonEnvelope.php',
                'beside' => '/public const CORE = \[/',
                'pinned' => '/the other (\w+) come from the route\'s report when it has them/',
                'counts' => '`JsonEnvelope::CORE` less the two keys `write()` fills itself',
                'expected' => count(JsonEnvelope::CORE) - count(array_intersect(
                    array_keys(JsonEnvelope::CORE),
                    ['command', 'exit_code'],
                )),
            ],
            'the envelope\'s core keys, as `JsonEnvelope::REPORTS`\' comment states them' => [
                'file' => 'src/Console/JsonEnvelope.php',
                'beside' => '/public const REPORTS = \[/',
                'pinned' => '/The (\w+) keys above are one half of this package\'s report contract/',
                'counts' => '`JsonEnvelope::CORE`',
                'expected' => count(JsonEnvelope::CORE),
            ],
            'the reasons a replica leaves its pool, as `WeightResolver`\'s comment states them' => [
                'file' => 'src/Database/Weighted/WeightResolver.php',
                'beside' => '/^final class WeightResolver/',
                'pinned' => '/there are (\w+) reasons a replica is missing from it/',
                'counts' => '`WeightResolver::EXCLUSION_REASONS`',
                'expected' => count(WeightResolver::EXCLUSION_REASONS),
            ],
            'the reasons a report groups, as `EXCLUSION_REASONS`\' comment states them' => [
                'file' => 'src/Database/Weighted/WeightResolver.php',
                'beside' => '/public const EXCLUSION_REASONS = \[/',
                'pinned' => '/The (\w+) reasons, in the order a report that groups them reads/',
                'counts' => '`WeightResolver::EXCLUSION_REASONS`',
                'expected' => count(WeightResolver::EXCLUSION_REASONS),
            ],
            'the settings a replica is sized by, as `ReplicaMetadata`\'s comment states them' => [
                'file' => 'src/Support/ReplicaMetadata.php',
                'beside' => '/^final class ReplicaMetadata/',
                'pinned' => '/A replica is sized by (\w+) settings/',
                'counts' => '`ReplicaMetadata::SETTINGS`',
                'expected' => count(ReplicaMetadata::SETTINGS),
            ],
            'the floors the resolver reads, as `ReplicaMetadata`\'s comment states them' => [
                'file' => 'src/Support/ReplicaMetadata.php',
                'beside' => '/^final class ReplicaMetadata/',
                'pinned' => '/the (\w+) constants declared below/',
                'counts' => 'the `*_FLOOR` constants on `ReplicaMetadata`',
                'expected' => self::familyCount(ReplicaMetadata::class, '_FLOOR'),
            ],
            'the settings a refusal reads, as `SETTINGS`\' comment states them' => [
                'file' => 'src/Support/ReplicaMetadata.php',
                'beside' => '/public const SETTINGS = \[/',
                'pinned' => '/The (\w+) settings, in the order a refusal reads/',
                'counts' => '`ReplicaMetadata::SETTINGS`',
                'expected' => count(ReplicaMetadata::SETTINGS),
            ],
            'the files a flip judges, as `VariantShape`\'s comment states them' => [
                'file' => 'src/Pgcat/VariantShape.php',
                'beside' => '/^final class VariantShape/',
                'pinned' => '/(\w+) files and one connection/',
                'counts' => '`VariantShape`\'s `ROLES` table',
                'expected' => self::constantCount(VariantShape::class, 'ROLES'),
            ],
            'the files a report names, as `ROLES`\' comment states them' => [
                'file' => 'src/Pgcat/VariantShape.php',
                'beside' => '/private const ROLES = \[/',
                'pinned' => '/The (\w+) files, by the setting each is written with/',
                'counts' => '`VariantShape`\'s `ROLES` table',
                'expected' => self::constantCount(VariantShape::class, 'ROLES'),
            ],
            'the paths a reader has to tell apart, as `ROLES`\' comment states them' => [
                'file' => 'src/Pgcat/VariantShape.php',
                'beside' => '/private const ROLES = \[/',
                'pinned' => '/which of the (\w+) paths is the target/',
                'counts' => '`VariantShape`\'s `ROLES` table',
                'expected' => self::constantCount(VariantShape::class, 'ROLES'),
            ],
        ];
    }

    /**
     * The number in the sentence is the number the collection has — and the sentence is still there.
     *
     * Presence is asserted per claim before the comparison, because the failure it describes is the
     * one that hides: a claim whose wording moved would otherwise compare nothing with nothing.
     */
    #[DataProvider('claims')]
    public function test_the_number_a_comment_states_is_the_number_the_collection_has(
        string $file,
        string $beside,
        string $pinned,
        string $counts,
        int $expected,
    ): void {
        $matched = preg_match_all($pinned, self::docblockAbove($file, $beside), $captures);

        $this->assertNotFalse($matched, "{$pinned} is not a pattern this guard can read");
        $this->assertGreaterThan(
            0,
            $matched,
            "{$file} no longer writes the comment this claim is pinned to ({$pinned}), so nothing here "
            ."compares {$counts} with a sentence. Rewording a bound comment is a two-file change.",
        );

        foreach ($captures[1] as $word) {
            $this->assertTrue(
                NumberWords::knows($word),
                "{$file} states [{$word}] where this package's records spell counts as words, so the "
                .'claim cannot be read. The comment is the thing to repair.',
            );

            $this->assertSame(
                $expected,
                NumberWords::toInt($word),
                "{$file} says {$word} where {$counts} has {$expected}. The comment is the thing to repair.",
            );
        }
    }

    /**
     * The sentence a claim names is a comment on that declaration, not a sentence somewhere in the
     * file.
     *
     * This is the half that makes the claim about *a collection*: `docblockAbove()` reads the block
     * attached to the declaration and nothing else, so a sentence that moved to a neighbouring
     * table — still a comment, still saying the right number — fails here instead of passing
     * because the words are still in the file.
     */
    #[DataProvider('claims')]
    public function test_the_comment_is_attached_to_the_collection_it_counts(
        string $file,
        string $beside,
        string $pinned,
        string $counts,
        int $expected,
    ): void {
        $this->assertGreaterThan(
            0,
            preg_match_all($pinned, implode("\n", self::commentTexts($file))),
            "{$file} does not state {$counts}'s count in any comment ({$pinned}).",
        );

        $this->assertGreaterThan(
            0,
            preg_match_all($pinned, self::docblockAbove($file, $beside)),
            "{$file} states {$counts}'s count in a comment somewhere else, but not in the block above ".
            "the declaration the claim names ({$beside}). A sentence beside the wrong table checks the wrong collection.",
        );
    }

    /**
     * The number the collection has, read from the constant itself.
     *
     * A private table is still readable through reflection, and reading it rather than a copy is
     * the point: the claim cannot be satisfied by editing this file.
     *
     * @param class-string $class
     */
    private static function constantCount(string $class, string $name): int
    {
        $constant = (new ReflectionClass($class))->getReflectionConstant($name);

        if ($constant === false) {
            throw new RuntimeException("{$class} declares no {$name} for a comment in src/ to count");
        }

        $value = $constant->getValue();

        if (!is_array($value)) {
            throw new RuntimeException("{$class}::{$name} is not a collection, so a count of it is not a fact");
        }

        return count($value);
    }

    /**
     * How many constants a class declares whose name ends in a family suffix.
     *
     * The sentence this serves says "the constants declared below" and names no class member, so the
     * family is the collection: `WEIGHT_FLOOR`, `CORES_FLOOR` and `RAM_FLOOR` are what it counts.
     *
     * @param class-string $class
     */
    private static function familyCount(string $class, string $suffix): int
    {
        $names = array_keys((new ReflectionClass($class))->getConstants());

        return count(array_filter(
            $names,
            static fn (string $name): bool => str_ends_with($name, $suffix),
        ));
    }

    /**
     * The docblock attached to the first line matching a declaration, with its markers stripped.
     *
     * A marker per line would break a pinned sentence that wraps, which most of these do, so the
     * block is folded to one line of prose before the claim reads it.
     */
    private static function docblockAbove(string $file, string $declaration): string
    {
        $lines = self::lines($file);

        foreach ($lines as $index => $line) {
            if (preg_match($declaration, $line) !== 1) {
                continue;
            }

            $end = $index - 1;

            while ($end >= 0 && trim($lines[$end]) === '') {
                $end--;
            }

            if ($end < 0 || !str_contains($lines[$end], '*/')) {
                throw new RuntimeException("{$file} declares {$declaration} with no comment above it, so a claim about it reads nothing");
            }

            $start = $end;

            while ($start >= 0 && !str_contains($lines[$start], '/**')) {
                $start--;
            }

            if ($start < 0) {
                throw new RuntimeException("{$file} declares {$declaration} with no docblock above it, so a claim about it reads nothing");
            }

            return self::prose(implode("\n", array_slice($lines, $start, $end - $start + 1)));
        }

        throw new RuntimeException("{$file} no longer declares {$declaration}, so a comment claim about it reads nothing");
    }

    /**
     * Every comment in a file, folded to one line each.
     *
     * Read through the tokenizer rather than by matching `*`, so a sentence that ended up in a
     * string literal or a heredoc is not a comment and cannot satisfy a claim.
     *
     * @return list<string>
     */
    private static function commentTexts(string $file): array
    {
        $source = implode('', self::lines($file));
        $comments = [];

        foreach (PhpToken::tokenize($source) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            $comments[] = self::prose($token->text);
        }

        return $comments;
    }

    /**
     * A block's text as one line of prose: markers and indentation go, whitespace collapses.
     */
    private static function prose(string $text): string
    {
        $text = (string) preg_replace('/^[ \t]*(?:\/\*\*?|\*+\/?|\/\/)/m', '', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * @return list<string>
     */
    private static function lines(string $file): array
    {
        $path = dirname(__DIR__, 3).'/'.$file;
        $lines = file($path);

        if ($lines === false) {
            throw new RuntimeException("{$file} is not a file this package can read");
        }

        return $lines;
    }
}
