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
}
