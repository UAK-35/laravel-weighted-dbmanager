<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 3) . '/bin/api.php';

/**
 * The published API page, checked against the record it claims to be.
 *
 * API.md is generated from `files.tsv`, `methods.tsv` and `surface.tsv`, which is the whole argument
 * for having it: the page cannot describe a different package from the one the release weighing
 * reads. That property is only true while the page is regenerated, and a page is exactly the kind of
 * artefact nobody notices has stopped matching — it renders, it reads plausibly, and the method that
 * was renamed six releases ago is simply still on it.
 *
 * So the guard is the generator run backwards. The page on disk is compared with the bytes the
 * record renders, every class and every method row is asserted to have reached it, and the counts
 * the page prints are the record's own — a page that dropped a row would disagree with itself in the
 * same table a reader trusts.
 *
 * What is not covered: the reading itself. Whether a symbol belongs on the page is
 * `bin/surface.php`'s decision, and it is pinned where it lives.
 */
final class PublicApiReportTest extends TestCase
{
    /**
     * The release whose published page is not what its own tree renders — the control the test
     * above reads, and the reason a page cannot be checked against itself alone.
     *
     * `v0.2.0-alpha2` rewrote the record and left `API.md` describing the release before it; the
     * re-render landed in a follow-up commit, so the tag holds a page its tree does not. Naming it
     * is what keeps the control a control: the tag before the newest is a good release now, and
     * reading it as the broken one made this test fail on a page that was right.
     */
    private const STALE_PAGE_TAG = 'v0.2.0-alpha2';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function page(): string
    {
        $page = @file_get_contents(self::root() . '/API.md');

        if ($page === false) {
            self::fail('API.md is not there, so there is no published page to check.');
        }

        return str_replace(["\r\n", "\r"], "\n", $page);
    }

    /**
     * The one assertion everything else here is a detail of: the page is what the record renders.
     */
    public function test_the_published_page_is_the_one_the_record_renders(): void
    {
        $this->assertNotSame('', apiReport(self::root()), 'the record rendered nothing at all');

        $this->assertSame(
            str_replace(["\r\n", "\r"], "\n", apiReport(self::root())),
            self::page(),
            'API.md is not the page files.tsv, methods.tsv and surface.tsv render — run `php bin/api.php`',
        );
    }

    /**
     * Every row of the record reached the page: each class has its own section, and each method the
     * exact row the record's own shape produces.
     *
     * A method's row is rebuilt here from its stored shape through the same `apiShape()` the page is
     * written with, so the two cannot disagree about a `requires` cell — the cell that used to be
     * wrong for every method with more than one argument.
     */
    public function test_every_row_of_the_record_reaches_the_page(): void
    {
        $record = apiRecord(self::root());
        $page = self::page();

        foreach ($record['files'] as $file) {
            $symbol = (string) ($file['symbol'] ?? '');

            if ($symbol === '' || $symbol === '(none)') {
                continue;
            }

            $this->assertStringContainsString(
                '#### `' . $symbol . '`',
                $page,
                "the record ships {$symbol} and the page has no section for it",
            );
        }

        foreach ($record['methods'] as $method) {
            $shape = apiShape((string) $method['signature']);

            $row = sprintf(
                '| `%s()` | %s | %s |',
                $method['method'],
                $shape['requires'],
                $shape['arguments'] === '' ? '—' : '`' . $shape['arguments'] . '`',
            );

            $this->assertStringContainsString(
                $row,
                $page,
                "the record declares {$method['class']}::{$method['method']}() and the page does not hold its row",
            );
        }
    }

    /**
     * The summary table is the record's own counts, which is what makes it evidence a reader can use
     * instead of counting: one kind that reached the page with a row missing would print a number the
     * rows of the page contradict.
     */
    public function test_the_summary_counts_are_the_records_own(): void
    {
        $record = apiRecord(self::root());
        $page = self::page();

        $counts = [
            'files shipped' => count($record['files']),
            'public methods' => count($record['methods']),
        ];

        foreach ([
            'config' => 'config keys',
            'const' => 'public constants',
            'env' => 'environment variables read',
            'property' => 'public properties',
            'case' => 'enum cases',
        ] as $kind => $label) {
            $counts[$label] = count(apiOfKind($record['surface'], $kind));
        }

        foreach ($counts as $label => $count) {
            $this->assertStringContainsString(
                sprintf('| %s | %d |', $label, $count),
                $page,
                "the page does not state the record's own [{$label}] count",
            );
        }
    }

    /**
     * The page says which tree it describes, because the record is per release and a page that did
     * not name one would read as the package today whatever it was rendered from.
     */
    public function test_the_page_names_the_tree_the_record_describes(): void
    {
        $record = apiRecord(self::root());

        $this->assertStringContainsString('**' . $record['stamp'] . '**', self::page(), 'the page does not name the tree it describes');
    }

    /**
     * No record, no page. An empty page is the failure mode this rules out: a renderer that treated
     * three missing files as three empty lists would publish a package with no API, and exit 0.
     */
    public function test_a_root_without_a_record_is_refused_rather_than_rendered_empty(): void
    {
        $directory = sys_get_temp_dir() . '/swrr-api-' . bin2hex(random_bytes(6));

        if (!mkdir($directory, 0o777, true) && !is_dir($directory)) {
            $this->fail("Could not create the temp directory {$directory}");
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('files.tsv is not there');

            apiReport($directory);
        } finally {
            @rmdir($directory);
        }
    }

    /**
     * ...and the command agrees, run the way a pipeline would run it: `--check` is the half that
     * writes nothing, and it is the one a CI job can call.
     */
    public function test_the_command_reports_the_page_current(): void
    {
        $process = new Process([PHP_BINARY, self::root() . '/bin/api.php', '--check'], self::root());
        $process->setTimeout(60);

        $exit = $process->run();

        $this->assertSame(0, $exit, 'php bin/api.php --check refused the committed page:' . PHP_EOL . $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString('api report is current', $process->getOutput());
    }

    /**
     * The pages of *this* repository's tags, read back off the trees that published them.
     *
     * WHY THE REAL HISTORY RATHER THAN A FIXTURE
     * -----------------------------------------
     *   A fixture pins the reader against a repository it built itself, which says nothing about
     *   whether a *real* old tree can be rebuilt at all — a layout that moved, a `surface.tsv`
     *   written by an older generator, a tag cut before there was a record to render. Those are
     *   properties of this history rather than of the reader.
     *
     *   The two tags are read for different reasons. The newest is the closed loop: `git show
     *   <tag>:API.md` is the page that release actually shipped, and the run has to produce those
     *   bytes from that tree — the property every release relies on, asserted against the blob
     *   rather than against a second render of the same reader, which would agree with itself while
     *   both were wrong. The control is the release whose published page is *not* what its tree
     *   holds: `v0.2.0-alpha2` rewrote the record and left the page describing the release before
     *   it, and the re-render was a follow-up commit — `PublishedPageTest` says the same in its own
     *   words. So a reader that printed the checkout's page, or the tag's blob, would pass the first
     *   assertion and fail this one, which is the whole reason both tags are here.
     *
     *   The control is named rather than taken as "the tag before the newest": that reading stops
     *   being a control the moment a release after it is cut, because the release before a good tag
     *   is a good one. Naming the tag that is known to have shipped a page its tree does not render
     *   says which property is under test instead of assuming the newest two have different ones.
     *
     *   Every derived file is hashed before and after, because the mode's promise is that a read is
     *   a read: a refactor that wrote the page — or the record — it rendered would leave the rest of
     *   this green.
     */
    public function test_the_page_a_tag_published_is_the_page_a_run_at_that_tag_renders(): void
    {
        [$newest, $stale] = self::pageTags();

        $before = self::digests();

        $published = self::api('--at=' . $newest);
        $older = self::api('--at=' . $stale);

        $this->assertSame(0, $published->getExitCode(), 'php bin/api.php --at=' . $newest . ' refused:' . PHP_EOL . $published->getErrorOutput());
        $this->assertSame(0, $older->getExitCode(), 'php bin/api.php --at=' . $stale . ' refused:' . PHP_EOL . $older->getErrorOutput());

        // The closed loop: the page printed for the newest tag is the one that tag published, byte
        // for byte. This is the assertion a stale release page fails — as `v0.2.0-alpha2`'s did.
        $this->assertSame(
            self::published($newest),
            self::normalise($published->getOutput()),
            "the page printed for {$newest} is not the page that tag published",
        );

        // ...and the control: that release published a page its own tree does not render, so the
        // two runs cannot have printed one page between them, and neither is the checkout's.
        $this->assertNotSame(
            self::published($stale),
            self::normalise($older->getOutput()),
            "the page printed for {$stale} is the blob at that tag, which is the page its release left behind",
        );
        $this->assertNotSame(self::normalise($published->getOutput()), self::normalise($older->getOutput()), 'two tags, one page');
        $this->assertSame(self::published($newest), self::page(), 'the checkout\'s page has moved on from the newest tag, which this test assumes');

        // Each page says which tree it describes, and the note about where it came from is on the
        // other stream — a note in front of the bytes is a page that does not render.
        $this->assertStringContainsString('describes the tree at **' . $newest . '**', $published->getOutput());
        $this->assertStringContainsString('describes the tree at **' . $stale . '**', $older->getOutput());
        $this->assertStringContainsString('reading the tree at ' . $stale . ' out of git', $older->getErrorOutput());
        $this->assertStringNotContainsString('reading the tree at', $older->getOutput());

        $this->assertSame($before, self::digests(), 'a read wrote one of the derived files in this checkout');
    }

    /**
     * The newest version tag, and the tag whose release shipped a page its own tree does not render.
     *
     * The control is named rather than read off the list beside the newest: a release after it does
     * not make it a good one, but taking "the tag before the newest" made it look like one, and the
     * assertion then failed on a page that was correct. Two tags are still needed — one tag would
     * let a reader that printed the tree it was standing in pass — and a checkout without the named
     * tag or with it as the newest is a history this test cannot ask about, which is said rather
     * than asserted on an empty string.
     *
     * @return array{0: string, 1: string}
     */
    private static function pageTags(): array
    {
        $process = new Process(['git', 'tag', '--list', 'v*', '--sort=-v:refname'], self::root());
        $process->setTimeout(30);
        $process->run();

        $tags = array_values(array_filter(preg_split('/\R/', $process->getOutput()) ?: []));
        $newest = $tags[0] ?? '';

        if ($newest === '') {
            self::markTestSkipped('this checkout names no version tag, so there is no page to read');
        }

        if (!in_array(self::STALE_PAGE_TAG, $tags, true)) {
            self::markTestSkipped(self::STALE_PAGE_TAG . ' is not in this checkout, so the control page this test reads is not here');
        }

        if ($newest === self::STALE_PAGE_TAG) {
            self::markTestSkipped('this checkout has no release after ' . self::STALE_PAGE_TAG . ', so there is no newest page to close the loop with');
        }

        return [$newest, self::STALE_PAGE_TAG];
    }

    /** The page a tag published, as git holds it — the only copy of it left, and the ground truth. */
    private static function published(string $tag): string
    {
        $process = new Process(['git', 'show', $tag . ':API.md'], self::root());
        $process->setTimeout(30);

        if ($process->run() !== 0) {
            self::fail("git could not read API.md out of {$tag}:" . PHP_EOL . $process->getErrorOutput());
        }

        return self::normalise($process->getOutput());
    }

    /**
     * The bytes of every derived file, for asserting that a read left all of them alone.
     *
     * The record as well as the page: `--at` reads the record out of git, so a run that opened the
     * files behind it would be the failure this covers — and a run that *wrote* one of them would be
     * the failure that costs a release its evidence.
     *
     * @return array<string, string>
     */
    private static function digests(): array
    {
        $digests = [];

        foreach (['API.md', 'files.tsv', 'methods.tsv', 'surface.tsv'] as $name) {
            $digest = @hash_file('sha256', self::root() . '/' . $name);

            if ($digest === false) {
                self::fail($name . ' is not there, so there is nothing to protect.');
            }

            $digests[$name] = $digest;
        }

        return $digests;
    }

    /** The generator, run the way a pipeline would run it. */
    private static function api(string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, self::root() . '/bin/api.php', ...$arguments], self::root());
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    private static function normalise(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
