<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A sentence a record quotes from another record is a sentence that record still writes.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `ProseNumbersTest` holds the examples in `docs/prose-numbers.md`'s own tables to the records
 *   they name, and that is the whole of the reading: every other record may quote every other
 *   record, and nothing checked those. A reference is the cheapest thing to write and the easiest
 *   to forget — `db-doctor-json.md` cited `docs/pgcat-flip-json.md` for a phrase that record does
 *   not contain, and the two records had drifted apart with no diff to show it — and a reader who
 *   follows the citation finds nothing, which is worse than a citation that was never written.
 *
 *   So the same reading is taken over the rest of the records: a line that names a record and
 *   quotes one is a claim that the quoted words are *that record's*, and it is held to it. The
 *   copies that are genuinely a second record's own wording are the case this exists for.
 *
 * WHAT IS READ
 * ------------
 *   The records are the files a commit carries that are Markdown and read by a person: the four at
 *   the package root that state how it is worked on (`README.md`, `RELEASING.md`, `PUSHING.md`,
 *   `STATIC-ANALYSIS.md`) and every `.md` under `docs/`, the two folders of written-up faults
 *   included. `CHANGELOG.md` is left out on purpose: a changelog quotes the sentence a version
 *   published rather than the record beside it, so its quotes are about a tag and not about a file
 *   on disk — the one rule here that a reader has to be told, which is why it is stated.
 *
 *   A *quote* is a `"…"` span of at least `MIN_QUOTE` characters. A *reference* is a backticked
 *   path that ends in `.md` and is not the record itself. The two have to be on one line: a
 *   record that names its sources once and quotes them below is not read, and the rule is stated
 *   as the shape it is — a citation and its quote in one sentence, not two fields a table would
 *   have to be laid out in.
 *
 *   An ellipsis inside a quote (`…`, or three dots) means "the rest of the sentence", so the
 *   fragments around it have to appear in the named record *in that order*. Emphasis (`**`) is
 *   markup rather than words and is dropped from both sides, and the straight and curly quote
 *   marks are flattened so a quote that changed a word's quotation marks still resolves.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 *   It cannot read a paraphrase as a quote: `docs/prose-numbers.md` cites `command-json-envelope.md`
 *   for "two thirds of the family", and if that sentence were reworded to "sixty per cent" the
 *   words this guard looks for would be gone and the citation would pass while meaning nothing.
 *   The reading is exact words, which is the reading a citation makes; a reference that is a
 *   judgement about a record rather than a quotation of it is written without quote marks, and the
 *   records here do write it that way.
 */
final class QuotedRecordsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** The shortest `"…"` span read as a quote rather than as emphasis on a word or two. */
    private const MIN_QUOTE = 12;

    /** The records read: how the package is worked on, and every decision written down. */
    private const ROOT_RECORDS = ['README.md', 'RELEASING.md', 'PUSHING.md', 'STATIC-ANALYSIS.md'];

    /**
     * Every quote a record attributes to another record is a sentence that record writes.
     *
     * One assertion for the corpus rather than one per quote, so a run names every stale citation
     * at once — they are repaired one at a time, and each is a different record.
     */
    public function test_every_quoted_sentence_attributed_to_another_record_is_one_that_record_writes(): void
    {
        $citations = self::citations();

        $this->assertNotSame([], $citations, 'No record attributes a quote to another, so nothing is held to anything and this guard answers nothing.');

        $stale = [];

        foreach ($citations as $citation) {
            $missing = self::missingFragment($citation);

            if ($missing !== null) {
                $stale[] = sprintf(
                    '%s line %d quotes [%s] as %s\'s words, and %s does not write [%s]%s. Quote the words the record has, or write the reference without quote marks if it is a judgement about the record rather than a quotation of it.',
                    $citation['source'],
                    $citation['line'],
                    $citation['quote'],
                    $citation['record'],
                    $citation['record'],
                    $missing,
                    $citation['after'] ? ' after the part before the ellipsis' : '',
                );
            }
        }

        $this->assertSame([], $stale, "a record quotes another record for a sentence it no longer writes:\n  - " . implode("\n  - ", $stale));
    }

    /**
     * The guard's own proof, both directions: a stale citation is reported, and a fresh one is not.
     *
     * The corpus has no fixture — it is the tree — so the reader is driven over a string here. If
     * the two cases ever agree, this guard has stopped reading and the corpus test above would pass
     * for a document of stale citations.
     */
    public function test_the_reader_reports_a_stale_citation_and_clears_a_fresh_one(): void
    {
        $record = "Under FPM that guard is worth nothing for the probe: the application boots per\n"
            . "request, so a static means \"once per request\".\n";

        $fresh = "| `docs/boot-audit-store-probe.md` | it says \"Under FPM that guard is worth nothing for the probe\" |";
        $stale = "| `docs/boot-audit-store-probe.md` | it says \"Under FPM that guard is worth a great deal\" |";

        $this->assertNull(
            self::missingIn($record, self::quoteOf($fresh)),
            'a citation whose words the record writes was reported as stale',
        );

        $this->assertNotNull(
            self::missingIn($record, self::quoteOf($stale)),
            'a citation whose words the record does not write was read as fresh, so this guard answers nothing',
        );
    }

    /**
     * Every quote a record attributes to another, as data.
     *
     * @return list<array{source: string, line: int, record: string, quote: string, after: bool}>
     *
     * @throws RuntimeException when no record could be read, which would make every citation look absent
     */
    private static function citations(): array
    {
        $citations = [];

        foreach (self::records() as $file) {
            $relative = self::relative($file);
            $lines = explode("\n", str_replace("\r\n", "\n", self::read($file)));

            foreach ($lines as $index => $line) {
                if (preg_match_all('/`([A-Za-z0-9_.\/-]+\.md)`/', $line, $refs, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                // The quotes are read with their position, and a citation owns only the quotes that
                // *start after it*: a `"…"` span whose opening mark is before the reference belongs to
                // the sentence before it, which is how a line carrying two citations and an unclosed
                // quote — `… produce it"; \`command-json-envelope.md\`'s "two thirds …` — was read as a
                // quote of the wrong record.
                $spans = [];

                preg_match_all('/"([^"\n]{' . self::MIN_QUOTE . ',})"/u', $line, $quotes, PREG_OFFSET_CAPTURE);

                foreach ($quotes[1] as $position => $captured) {
                    $spans[] = ['text' => $captured[0], 'at' => $quotes[0][$position][1]];
                }

                if ($spans === []) {
                    continue;
                }

                foreach ($refs[1] as $ref) {
                    [$name, $nameAt] = $ref;

                    if (self::record($name) === null || self::record($name) === $relative) {
                        continue;   // not a record, or this record citing itself
                    }

                    foreach ($spans as $span) {
                        if ($span['at'] < $nameAt) {
                            continue;   // the quote starts before this citation
                        }

                        $citations[] = [
                            'source' => $relative,
                            'line' => $index + 1,
                            'record' => self::record($name),
                            'quote' => $span['text'],
                            'after' => count(self::fragments($span['text'])) > 1,
                        ];
                    }
                }
            }
        }

        if ($citations === [] && self::records() === []) {
            throw new RuntimeException('No record could be read under this package, so nothing could be held to anything.');
        }

        return $citations;
    }

    /**
     * The first fragment of a citation that the named record does not write, or null when it writes them all.
     *
     * @param array{source: string, line: int, record: string, quote: string, after: bool} $citation
     */
    private static function missingFragment(array $citation): ?string
    {
        return self::missingIn((string) file_get_contents(self::ROOT . '/' . $citation['record']), self::fragments($citation['quote']));
    }

    /**
     * The first fragment a text does not hold in order, or null.
     *
     * @param list<string> $fragments
     */
    private static function missingIn(string $text, array $fragments): ?string
    {
        if ($fragments === []) {
            return null;
        }

        $source = self::normalise($text);
        $at = 0;

        foreach ($fragments as $fragment) {
            $found = strpos($source, $fragment, $at);

            if ($found === false) {
                return $fragment;
            }

            $at = $found + strlen($fragment);
        }

        return null;
    }

    /**
     * The fragments a quote has to appear as, in order.
     *
     * An ellipsis is the rest of the sentence, so it splits the quote; no ellipsis is one fragment.
     *
     * @return list<string>
     */
    private static function fragments(string $quote): array
    {
        $fragments = [];

        foreach (preg_split('/…|\.\.\./', self::normalise($quote)) ?: [] as $fragment) {
            $fragment = trim($fragment);

            if ($fragment !== '') {
                $fragments[] = $fragment;
            }
        }

        return $fragments;
    }

    /** The quote a line carries, for the guard's own proof. */
    private static function quoteOf(string $line): array
    {
        preg_match_all('/"([^"\n]+)"/u', $line, $quotes);

        return self::fragments($quotes[1][0] ?? '');
    }

    /**
     * A text as it is compared: emphasis dropped, quote marks flattened, whitespace collapsed.
     *
     * A record bolds a number it counts and wraps its sentences, and this package's records write
     * both straight and curly quotes around a name; none of that is a word of the sentence, and a
     * quote is the words.
     */
    private static function normalise(string $text): string
    {
        $text = str_replace(['**', "\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}"], ['', "'", "'", '"', '"'], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The record a cited path names, as this package's own path, or null when it is not one.
     *
     * A citation may be written either way — `README.md` at the root, or `pool-exclusions.md` for a
     * file beside the record quoting it — so both spellings are resolved, and a path that resolves
     * to nothing is not a citation this guard can hold.
     */
    private static function record(string $ref): ?string
    {
        $ref = ltrim($ref, '/');

        foreach ([$ref, 'docs/' . $ref] as $candidate) {
            if (is_file(self::ROOT . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Every record read, as absolute paths. */
    private static function records(): array
    {
        $files = [];

        foreach (self::ROOT_RECORDS as $name) {
            if (is_file(self::ROOT . '/' . $name)) {
                $files[] = self::ROOT . '/' . $name;
            }
        }

        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/docs', \FilesystemIterator::SKIP_DOTS));

        foreach ($walk as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** A record's own path as it is cited, relative to the root. */
    private static function relative(string $file): string
    {
        return ltrim(str_replace('\\', '/', substr($file, strlen(self::ROOT))), '/');
    }

    private static function read(string $file): string
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException('Could not read ' . $file);
        }

        return $contents;
    }
}
