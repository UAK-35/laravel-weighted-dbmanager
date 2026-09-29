<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use RuntimeException;

/**
 * The words this package's records write numbers with, in both directions.
 *
 * WHY THIS IS NOT IN THE TEST THAT USES IT
 * ----------------------------------------
 *   Two readers need it, and they need it in opposite directions. The guard reads a record and
 *   turns the word it finds into a number, to compare with what the code has. The renderer
 *   (`bin/counts.php`) knows the number the code has and turns it into the word to write. One
 *   vocabulary, spelled once, so a record cannot be readable by one and unwritable by the other.
 *
 * WHY WORDS AT ALL
 * ----------------
 *   The records are prose an operator reads, and a sentence is where the *reason* for a rule
 *   survives. "The matrix has nine cells" is a sentence; "the matrix has `count($cells)` cells" is
 *   a docblock. The cost of that choice is this table — and a number the table does not know is a
 *   failure rather than a skip, in both directions, because a guard that quietly ignores a word it
 *   does not recognise reports agreement with a claim it never read.
 */
final class NumberWords
{
    /**
     * The word for each number the records may state.
     *
     * The list stops at thirty on purpose, and it used to stop at twenty. The ceiling is a
     * judgement about when a count stops being a sentence, and the audit's own key table is what
     * moved it: `boot-audit-surfaces.md` states how many finding keys the provider declares, and
     * that count passed twenty while the sentence stating it stayed a sentence — one key out of the
     * set, which is what the sentence is about. Past thirty the failure ("the record states a number
     * this vocabulary does not have") still stands, because a count that size is a generated document
     * rather than a paragraph, and a failure is a better outcome than silently writing digits into
     * prose that spells its numbers out.
     *
     * @var array<int, string>
     */
    private const WORDS = [
        1 => 'one',
        2 => 'two',
        3 => 'three',
        4 => 'four',
        5 => 'five',
        6 => 'six',
        7 => 'seven',
        8 => 'eight',
        9 => 'nine',
        10 => 'ten',
        11 => 'eleven',
        12 => 'twelve',
        13 => 'thirteen',
        14 => 'fourteen',
        15 => 'fifteen',
        16 => 'sixteen',
        17 => 'seventeen',
        18 => 'eighteen',
        19 => 'nineteen',
        20 => 'twenty',
        21 => 'twenty-one',
        22 => 'twenty-two',
        23 => 'twenty-three',
        24 => 'twenty-four',
        25 => 'twenty-five',
        26 => 'twenty-six',
        27 => 'twenty-seven',
        28 => 'twenty-eight',
        29 => 'twenty-nine',
        30 => 'thirty',
    ];

    /**
     * The number a record's word names.
     *
     * Case is the record's business — a sentence may open with the number — so the lookup folds
     * case and an unknown word raises rather than returning something harmless-looking.
     */
    public static function toInt(string $word): int
    {
        $number = array_search(strtolower($word), self::WORDS, true);

        if ($number === false) {
            throw new RuntimeException(
                "the guard does not know the number word \"{$word}\" — add it to NumberWords, or write the "
                .'number the way the other records write it',
            );
        }

        return $number;
    }

    /**
     * The word the renderer writes for a number the code has.
     *
     * The other direction of the same table, and it raises for the same reason: a count this
     * vocabulary cannot spell is a record that has outgrown its own prose, which somebody has to
     * look at rather than have a generator decide about.
     */
    public static function toWord(int $number): string
    {
        if (! isset(self::WORDS[$number])) {
            throw new RuntimeException(
                "a record's count is {$number}, and this vocabulary spells numbers up to "
                .count(self::WORDS).' — a table that big is a generated document, not a sentence',
            );
        }

        return self::WORDS[$number];
    }

    /**
     * Whether a word is one this vocabulary knows — how a caller tells a claim's number from the
     * other words a pattern's group might have captured.
     */
    public static function knows(string $word): bool
    {
        return in_array(strtolower($word), self::WORDS, true);
    }
}
