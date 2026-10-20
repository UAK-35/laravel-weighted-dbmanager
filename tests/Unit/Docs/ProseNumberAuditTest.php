<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uak35\WeightedDbManager\Tests\Support\NumberWords;

/**
 * The number words a record marks as a count, and the ones no claim reads, held to the table that
 * declares them prose.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `ProseNumbersTest` asserts that every number a claim *names* is the number the code has. It
 *   cannot say that a number in a record is named by no claim at all — which is how two bold
 *   headings and a "ways" count stayed prose while the sentence under them was rendered, and how
 *   they were found: by hand, in the audit `docs/prose-numbers.md` deferred.
 *
 *   This is that audit's second half. A record bolds a number when it is stating a count — every
 *   sentence in the bound table bolds the number its claim is about, and the renderer writes its
 *   output where that number was — so the mark is the record's own declaration that the number is
 *   a claim. The question "is every marked number read?" is therefore a question about a list of
 *   marks, and the answer is a list of exceptions.
 *
 * THE RULE
 * --------
 *   **A number a record marks is read by a claim, or it is listed in the record's prose table with
 *   the reason it is prose.** Both directions fail: a mark with no row is a claim nobody reads, and
 *   a row whose mark the record no longer writes is an exception that has outlived its sentence.
 *
 *   The reason is one of the categories `docs/prose-numbers.md` gives under "What is deliberately
 *   not bound, and why", quoted by its heading — so a number that fits no category has no row
 *   until the category is written down first. That is the move this file is named for: the bullets
 *   were the boundary described, and the table is the boundary enforced.
 *
 * WHAT THIS DOES NOT COVER, AND WHY
 * ---------------------------------
 *   A number that is not marked. A sweep of every spelled number in the audited records is mostly
 *   grammar — "one of the two", "one per row", "one step later" — and a table of those is an
 *   inventory of English rather than a claim about the code: a check built on it fails on every
 *   prose edit, which is the check somebody switches off. The mark is the record's own line
 *   between the two, and it is the one this guard can read. `docs/prose-numbers.md` records the
 *   gap that leaves, beside the rule it is the exception to.
 *
 *   A numeral. The records spell the counts they state, so `**24**` in a record is a quoted value
 *   (`weight: 0`, a run's total) rather than a count somebody has to keep in step by hand; the
 *   words are the subject here, and `NumberWords` is the vocabulary.
 */
final class ProseNumberAuditTest extends TestCase
{
    /** The record that states the rule, and holds the table this guard reads. */
    private const RECORD = 'docs/prose-numbers.md';

    /** The heading the prose table lives under. */
    private const TABLE = '## The numbers a record marks but does not claim';

    /** The heading whose bullet lead-ins are the reasons a prose row may state. */
    private const REASONS = '## What is deliberately not bound, and why';

    /** How a mark is written: a bold span, which may wrap across a line. */
    private const MARK = '/\*\*(?!\s)(.+?)\*\*/s';

    /**
     * Every number a record marks that no claim captures — the whole of what the table has to cover.
     *
     * Read out of the records rather than listed here, because a list of them would be the
     * restatement this guard exists to catch. The claims come from both tables that bind one: the
     * checked and rendered claims `ProseNumbersTest` reads, and the five that need the command to
     * have run, which are written as `numberClaim('record', 'pattern')` in `DbDoctorTest`.
     */
    public function test_every_number_a_record_marks_is_claimed_or_listed_as_prose(): void
    {
        $marked = self::markedNumbers();
        $rows = self::proseRows();

        $this->assertNotSame(
            [],
            $marked,
            'the audited records mark no number at all, so this guard is reading the wrong files — a check that '
            .'finds nothing reports agreement with a corpus it never read.',
        );

        $unlisted = [];

        foreach ($marked as $entry) {
            if (self::listed($entry, $rows)) {
                continue;
            }

            $unlisted[] = sprintf(
                '| `%s` | `%s` | "%s" | ',
                $entry['record'],
                $entry['number'],
                self::anchor($entry['marked']),
            );
        }

        $stale = [];

        foreach ($rows as $row) {
            $found = false;

            foreach ($marked as $entry) {
                if ($entry['record'] === $row['record']
                    && $entry['number'] === $row['number']
                    && self::states($entry['marked'], $row['marked'])) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                $stale[] = sprintf('| `%s` | `%s` | "%s" |', $row['record'], $row['number'], $row['marked']);
            }
        }

        // Both directions in one assertion, because they are one question and a run should report the
        // whole of it: the repair for a mark with no row and the repair for a row with no mark are
        // edits to the same table, and a reader who fixes one at a time runs the suite twice.
        $this->assertSame(
            [],
            array_merge($unlisted, $stale),
            "the prose table in ".self::RECORD." and the marks the records write have to be the same list:\n"
            ."  a mark no claim reads and no row declares (add the row):\n    "
            .implode("\n    ", $unlisted ?: ['none'])
            ."\n  a row whose sentence the record no longer marks (drop it, or quote the sentence it writes now):\n    "
            .implode("\n    ", $stale ?: ['none']),
        );
    }

    /**
     * A prose row's reason is one of the categories the record gives, quoted by its heading.
     *
     * Without this, the reason column is a sentence somebody writes and nobody reads: a number
     * could be declared prose for a reason the record does not hold, which is the boundary
     * described again with more words. With it, a new kind of prose is a heading first — the
     * category exists in one place, and each number that needs it points at it.
     */
    public function test_every_reason_a_prose_row_states_is_a_category_the_record_gives(): void
    {
        $categories = self::reasons();

        $this->assertNotSame(
            [],
            $categories,
            self::RECORD.' gives no categories under "'.self::REASONS.'", and a prose row states one of them as its reason',
        );

        $unknown = [];

        foreach (self::proseRows() as $row) {
            if (! in_array(self::normalise($row['why']), $categories, true)) {
                $unknown[] = sprintf(
                    '| `%s` | `%s` | %s |',
                    $row['record'],
                    $row['number'],
                    $row['why'],
                );
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "a prose row gives a reason the record does not, so the number is declared prose on nobody's terms: "
            .self::RECORD.' holds these categories, and a new one is a bullet under "'.self::REASONS.'" before it '
            ."is a row:\n  ".implode("\n  ", $categories)."\n".implode("\n", $unknown),
        );
    }

    /**
     * Every claim's pattern, by the record it pins — the whole of what a mark may be read by.
     *
     * Both tables, because a number is claimed wherever its claim is written: the checked and
     * rendered claims live in `ProseNumbersTest`, and the five that need the command to have run
     * live in `DbDoctorTest`, where the fixtures are. Reading the second table out of the test's
     * source is what keeps this a comparison rather than a copy of five patterns that could drift
     * from the ones actually pinned.
     *
     * @return array<string, list<string>>
     */
    private static function claimsByRecord(): array
    {
        $byRecord = [];

        foreach (ProseNumbersTest::proseNumbers() as $claim) {
            $byRecord[$claim['record']][] = $claim['pinned'];
        }

        $source = self::read('tests/Unit/Console/DbDoctorTest.php');

        preg_match_all('/numberClaim\(\s*\'([^\']+)\',\s*\'((?:[^\'\\\\]|\\\\.)+)\'/', $source, $matches);

        if ($matches[1] === []) {
            throw new RuntimeException(
                'DbDoctorTest pins no number claim this guard can read, so the five numbers those claims speak for '
                .'would be counted as prose — the reading is of `numberClaim(\'record\', \'pattern\')` calls',
            );
        }

        foreach ($matches[1] as $index => $record) {
            $byRecord[$record][] = str_replace(['\\\\', "\\'"], ['\\', "'"], $matches[2][$index]);
        }

        return $byRecord;
    }

    /**
     * Every number a record marks and no claim captures: the record, the word, and the span it is
     * marked in.
     *
     * The claim's capture is compared by *offset*, not by the word: a record may state a number
     * twice in one sentence, and only the one the pattern named is read. The whole span is kept
     * rather than the number's neighbours, because that is the sentence the row quotes back and the
     * thing a reader recognises.
     *
     * @return list<array{record: string, number: string, marked: string}>
     */
    private static function markedNumbers(): array
    {
        $marked = [];

        foreach (self::claimsByRecord() as $record => $patterns) {
            if (! str_ends_with($record, '.md')) {
                continue;
            }

            $source = self::read($record);
            $fenced = self::fenced($source);
            $claimed = self::claimedOffsets($source, $patterns, $record);

            preg_match_all(self::MARK, $source, $spans, PREG_OFFSET_CAPTURE);

            foreach ($spans[1] as [$span, $at]) {
                if (self::inside($fenced, $at)) {
                    continue;
                }

                foreach (self::numbersIn($span) as [$word, $offset]) {
                    if (isset($claimed[$at + $offset])) {
                        continue;
                    }

                    $marked[self::key($record, strtolower($word), self::normalise($span))] = [
                        'record' => $record,
                        'number' => strtolower($word),
                        'marked' => self::normalise($span),
                    ];
                }
            }
        }

        return array_values($marked);
    }

    /**
     * Where each claim's captured number sits in the record, as a set of offsets.
     *
     * A pattern that does not match is not a failure here: the claim's own test reports that, and
     * this reader's job is only to know which marks a claim has spoken for.
     *
     * @param list<string> $patterns
     *
     * @return array<int, true>
     */
    private static function claimedOffsets(string $source, array $patterns, string $record): array
    {
        $offsets = [];

        foreach ($patterns as $pattern) {
            $found = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

            if ($found === false) {
                throw new RuntimeException(
                    "the claim [{$pattern}] pinned to {$record} is not a pattern this guard can run, so its number "
                    .'would be read as prose',
                );
            }

            foreach ($matches[1] as $capture) {
                $offsets[$capture[1]] = true;
            }
        }

        return $offsets;
    }

    /**
     * The number words in one span, each with its offset — the same reading `NumberWords` gives a
     * claim's capture.
     *
     * Inline code is masked first, with spaces so every offset stays where it was: a bold span may
     * quote a command (`**`db:doctor`**`), and a word inside that code is not a count in prose. A
     * numeral is not read here for the same reason the vocabulary spells none past a quoted value:
     * the records write their counts as words.
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function numbersIn(string $span): array
    {
        $masked = preg_replace_callback(
            '/`[^`]*`/',
            static fn (array $code): string => str_repeat(' ', strlen($code[0])),
            $span,
        ) ?? $span;

        preg_match_all('/[\w-]+/', $masked, $words, PREG_OFFSET_CAPTURE);

        $numbers = [];

        foreach ($words[0] as [$word, $offset]) {
            if (preg_match('/^\d/', $word) === 1 || ! NumberWords::looksLikeNumber($word)) {
                continue;
            }

            $numbers[] = [$word, $offset];
        }

        return $numbers;
    }

    /**
     * The rows of the record's prose table, read as data.
     *
     * Four cells, all required: the record the number is in, the word, the sentence the record
     * marks, and the reason it is prose. A row that does not have them raises rather than being
     * skipped — a table half read would agree with a record it never finished reading.
     *
     * @return list<array{record: string, number: string, marked: string, why: string}>
     */
    private static function proseRows(): array
    {
        $rows = [];
        $inside = false;

        foreach (explode("\n", self::read(self::RECORD)) as $line) {
            $line = trim($line);

            if (str_starts_with($line, self::TABLE)) {
                $inside = true;

                continue;
            }

            if (! $inside) {
                continue;
            }

            if (str_starts_with($line, '## ')) {
                break;
            }

            if (! str_starts_with($line, '|') || str_starts_with($line, '| Record') || str_starts_with($line, '|---')) {
                continue;
            }

            $cells = array_map(
                static fn (string $cell): string => trim($cell),
                explode('|', trim($line, '|')),
            );

            if (count($cells) !== 4) {
                throw new RuntimeException(
                    'a prose row in '.self::RECORD.' has '.count($cells).' cells where its header has four: '.$line,
                );
            }

            $record = trim(str_replace('`', '', $cells[0]));
            $number = trim(str_replace('`', '', $cells[1]));

            // Unknown words raise here rather than being carried into a comparison that would never
            // meet them: the vocabulary is the table's grammar as well as the records'.
            NumberWords::toInt($number);

            preg_match_all('/"([^"]+)"/u', $cells[2], $quotes);

            if ($record === '' || $number === '' || $quotes[1] === []) {
                throw new RuntimeException(
                    'a prose row in '.self::RECORD.' does not name its record, its number and the sentence the '
                    .'record marks: '.$line,
                );
            }

            foreach ($quotes[1] as $quote) {
                $rows[] = [
                    'record' => $record,
                    'number' => strtolower($number),
                    'marked' => self::normalise($quote),
                    'why' => self::normalise(str_replace('`', '', $cells[3])),
                ];
            }
        }

        if ($rows === []) {
            throw new RuntimeException(
                self::RECORD.' has no prose rows under "'.self::TABLE.'", and the guard reads that table to know '
                .'which marked numbers are allowed to be prose.',
            );
        }

        return $rows;
    }

    /**
     * The categories under "What is deliberately not bound, and why", each as the heading writes it.
     *
     * The bullets are bolded sentences; the bolded part is the category's name, and it is what a
     * prose row quotes. Read from the record rather than kept here, so the two cannot disagree.
     *
     * @return list<string>
     */
    private static function reasons(): array
    {
        $reasons = [];
        $inside = false;

        foreach (explode("\n", self::read(self::RECORD)) as $line) {
            $line = trim($line);

            if (str_starts_with($line, self::REASONS)) {
                $inside = true;

                continue;
            }

            if (! $inside) {
                continue;
            }

            if (str_starts_with($line, '## ')) {
                break;
            }

            if (preg_match('/^- \*\*(.+?)\.\*\*/', $line, $match) === 1) {
                $reasons[] = self::normalise($match[1]);
            }
        }

        return $reasons;
    }

    /**
     * Whether one of the rows declares this mark.
     *
     * @param array{record: string, number: string, marked: string} $entry
     * @param list<array{record: string, number: string, marked: string, why: string}> $rows
     */
    private static function listed(array $entry, array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['record'] === $entry['record']
                && $row['number'] === $entry['number']
                && self::states($entry['marked'], $row['marked'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a quote is a fragment of one marked span.
     *
     * An ellipsis in a quote means "the rest of the span", so the fragments around it have to
     * appear in order — a quote assembled from two different spans is not the sentence the record
     * writes, which is why this is not a substring test on a joined string.
     */
    private static function states(string $marked, string $quote): bool
    {
        $at = 0;

        foreach (self::fragments($quote) as $fragment) {
            $found = strpos($marked, $fragment, $at);

            if ($found === false) {
                return false;
            }

            $at = $found + strlen($fragment);
        }

        return true;
    }

    /**
     * A quote as the fragments a span has to state, in order.
     *
     * @return list<string>
     */
    private static function fragments(string $quote): array
    {
        $fragments = array_map(
            static fn (string $fragment): string => self::normalise($fragment),
            preg_split('/\s*(?:…|\.\.\.)\s*/u', $quote) ?: [],
        );

        return array_values(array_filter($fragments, static fn (string $fragment): bool => $fragment !== ''));
    }

    /**
     * The span ranges a record holds as fenced code.
     *
     * A bold span inside a fence is an example, not prose about the package: it is shown to an
     * operand, and the number in it is the operand's. Offsets are what make the test possible
     * without splitting the record into pieces and losing the position the claims were found at.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function fenced(string $source): array
    {
        preg_match_all('/^```.*?^```/ms', $source, $fences, PREG_OFFSET_CAPTURE);

        return array_map(
            static fn (array $fence): array => [$fence[1], $fence[1] + strlen($fence[0])],
            $fences[0],
        );
    }

    /**
     * @param list<array{0: int, 1: int}> $fenced
     */
    private static function inside(array $fenced, int $offset): bool
    {
        foreach ($fenced as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * A span cut to the length a failure message can carry, at a word boundary.
     */
    private static function anchor(string $marked): string
    {
        // Counted in characters rather than bytes: the records write em dashes, and a byte cut would
        // hand the reader half a character in the one message they have to act on.
        if (preg_match('/^(.{0,90})\s/u', $marked, $match) !== 1) {
            return $marked;
        }

        return rtrim($match[1]).'…';
    }

    private static function key(string $record, string $number, string $marked): string
    {
        return $record.'|'.$number.'|'.$marked;
    }

    /**
     * A record's text as one stream of prose: its line breaks were the file's, not the sentence's.
     */
    private static function normalise(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\r\n", "\n", $text)) ?? $text);
    }

    /**
     * A record or a source file, read from the package root.
     */
    private static function read(string $relative): string
    {
        $source = @file_get_contents(dirname(__DIR__, 3).'/'.$relative);

        if ($source === false) {
            throw new RuntimeException("a guard cannot read {$relative}, and a mark has to be read where it is written");
        }

        return $source;
    }
}
