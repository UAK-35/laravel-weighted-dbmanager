<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\RepoEscapes;

/**
 * AGENTS.md section 0 against the tree it describes: nothing here reads, searches or writes outside
 * this repository's root, and the way that breaks silently is a path that climbs.
 *
 * WHY THIS EXISTS
 * ---------------
 *   The rule is a sentence in `AGENTS.md`, and a sentence is not a guard — it is the thing a guard
 *   is written for. What it forbids is a *walk* out of the checkout, and a walk is invisible: a
 *   token like `../../site` reads as a path wherever it is read, so it survives a review, a docs
 *   guard (it is not a link) and a machine-path reading (it names no machine). The only time it
 *   announces itself is when the checkout moves, and by then the line has been in the tree for
 *   months.
 *
 *   So the paths are read out of the files a commit would carry — `git ls-files`, tracked plus the
 *   untracked the ignore rules do not cover — and *walked*, from the depth of the file each one was
 *   written in. The climb is a finding only when the walk goes above the root, which is what lets
 *   the records keep their own links upward while a climb written at the root cannot be anything
 *   else. The reading is `tests/Support/RepoEscapes.php`.
 *
 * THREE THINGS ARE ASSERTED RATHER THAN ONE
 * -----------------------------------------
 *   That nothing in the tree climbs out; that the detector fires at all — the samples below, which
 *   is the only way a reading of nothing is told apart from a reading that works; and that a file
 *   exempted for holding those samples still has to hold one, because an exemption is a hole in the
 *   guard and a hole nobody is standing in is just a hole.
 */
final class RepoEscapesTest extends TestCase
{
    public function test_no_path_in_the_tree_climbs_out_of_the_repository(): void
    {
        $scan = RepoEscapes::scan(self::root());

        $reported = array_map(
            static fn (array $hit): string => sprintf('%s:%d  %s', $hit['file'], $hit['line'], $hit['path']),
            $scan['found'],
        );

        // A scan that read nothing agrees with a repository that leaked nothing, so the listing is
        // checked for substance before the findings are: the files the samples live in have to be
        // in it, which is only true of a listing that came from the real working tree.
        self::assertSame(
            [],
            array_values(array_diff(array_keys(RepoEscapes::EXEMPT), $scan['files'])),
            'The scan did not read the files the samples live in, so it is not reading this tree.',
        );

        self::assertSame([], $reported, sprintf(
            "AGENTS.md says the work is this folder and nothing outside it, and these paths climb above the root — each one resolves only where the checkout happens to sit:\n  - %s",
            implode("\n  - ", $reported),
        ));
    }

    public function test_every_exemption_is_still_earned(): void
    {
        $scan = RepoEscapes::scan(self::root());

        $exempt = array_values(array_unique(array_column($scan['exempt'], 'file')));
        $declared = array_keys(RepoEscapes::EXEMPT);

        sort($exempt);
        sort($declared);

        // Each exemption carries the reason it was granted, and a reason is only worth writing down
        // once it can be checked: a file that no longer holds a sample is reported with the reason
        // it was exempted for, which is the one that gets removed.
        self::assertSame($declared, $exempt, sprintf(
            'These files are exempt from the repository-escape reading and no longer hold a sample it would fire at, so the exemption is hiding nothing but is hiding it from a reading that no longer needs it: %s',
            implode(', ', array_values(array_diff($declared, $exempt))) ?: '(none)',
        ));

        foreach (RepoEscapes::EXEMPT as $file => $reason) {
            self::assertNotSame('', trim($reason), "The exemption for {$file} does not say why it was granted.");
        }
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('climbs')]
    public function test_a_path_that_climbs_above_the_root_is_reported(string $text, int $depth, array $expected): void
    {
        self::assertSame(
            $expected,
            array_column(RepoEscapes::in($text, $depth), 'path'),
            sprintf('The climbs in [%s] written at depth %d are not the ones this reports.', $text, $depth),
        );
    }

    #[DataProvider('inside')]
    public function test_a_path_that_stays_inside_the_root_is_left_alone(string $text, int $depth): void
    {
        self::assertSame(
            [],
            RepoEscapes::in($text, $depth),
            sprintf('[%s] written at depth %d is reported as a climb, and it is not one.', $text, $depth),
        );
    }

    /**
     * The report a failure prints is `file:line`, so a climb found in the wrong place would send
     * the reader to the wrong line of a file they are already looking at.
     */
    public function test_each_path_keeps_the_line_it_was_written_on(): void
    {
        self::assertSame(
            [['line' => 1, 'path' => '../..']],
            RepoEscapes::in("cd ../..\n", 1),
        );

        self::assertSame(
            [['line' => 2, 'path' => '../../tools']],
            RepoEscapes::in("first\nsecond ../../tools\n", 1),
        );

        self::assertSame(
            [['line' => 2, 'path' => '$PROJECT_DIR$/../..']],
            RepoEscapes::in("first\r\nsecond \$PROJECT_DIR\$/../..\r\n", 0),
        );
    }

    /**
     * A climb, and the depth of the file it is written in — which is what it is relative to, so the
     * same token is a finding in one file and a link to the root in the next one up.
     *
     * @return array<string, array{string, int, list<string>}>
     */
    public static function climbs(): array
    {
        return [
            'a climb written at the root' => ['../sibling/notes.md', 0, ['../sibling/notes.md']],
            'a record climbing one level too far' => ['[config](../../config.php)', 1, ['../../config.php']],
            'the marker is the root, so it leaves at once' => ['$PROJECT_DIR$/../../application/vendor/bin/pint.bat', 2, ['$PROJECT_DIR$/../../application/vendor/bin/pint.bat']],
            'the same climb, back-slashed' => ['..\\..\\..\\tools', 2, ['..\\..\\..\\tools']],
            'a climb written as a PHP string' => ["__DIR__ . '/../../..'", 2, ['/../../..']],
            'a climb computed from the file directory' => ['dirname(__DIR__, 3)', 2, ['dirname(__DIR__, 3)']],
            'a climb through a name and back out' => ['config/../..', 0, ['config/../..']],
        ];
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function inside(): array
    {
        return [
            'a record linking what it describes' => ['[DbManager](../src/Support/ConfigValue.php)', 1],
            'two levels up from a reading is the root' => ['../../src/Support/ConfigValue.php', 2],
            'a climb walked back before it leaves' => ['docs/../tests/x.php', 1],
            'the config path the provider builds' => ["sprintf('%s/../config/%s.php', __DIR__)", 2],
            'a computed climb that lands on the root' => ['dirname(__DIR__, 2)', 2],
            'a computed climb of one level' => ['dirname(__DIR__, 1)', 2],
            'the directory of the file itself' => ['dirname(__DIR__)', 1],
            'the IDE marker at the root it stands for' => ['$PROJECT_DIR$/vendor/bin/phpstan', 2],
            'a Windows directory, which the machine-path reading tolerates' => ['core.sshCommand = C:/Windows/System32/OpenSSH/ssh.exe', 0],
            'a CI runner, which that reading tolerates for the same reason' => ['cd /home/runner/work/pkg/pkg', 0],
            'a URL whose path climbs' => ['see https://example.test/a/../..', 0],
            'an ellipsis standing for a directory' => ['the value is `C:/Windows/...`', 0],
            'a path with no climb in it' => ['vendor/laravel/pint/builds/pint', 2],
            'a namespace in PHP source' => ['namespace Uak35\\\\WeightedDbManager\\\\Tests;', 2],
            'an escaped namespace in JSON' => ['"Uak35\\\\\\\\WeightedDbManager\\\\\\\\": "src/"', 2],
            'the sibling-ward path a skill names, from three levels down' => ['../../packages/laravel-weighted-dbmanager', 3],
        ];
    }

    /** The package root: the directory the paths above are relative to. */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
