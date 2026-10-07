<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

require_once dirname(__DIR__, 3) . '/bin/api.php';

/**
 * The page a release publishes, held to the record it renders — in the release's own commit.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `API.md` is rendered from `files.tsv`, `methods.tsv` and `surface.tsv`, and that is the whole
 *   argument for having it: the page a consumer reads cannot describe a different tree from the
 *   one the weighing read. That only holds while the page is rendered *by the release*, and it
 *   was not. `v0.2.0-alpha2` rewrote the three records to describe its own tree and left the page
 *   describing the release before it, so the guard on the committed page went red on the release
 *   commit itself and a follow-up commit had to re-render the page by hand. `bin/api.php`'s own
 *   help had said all along that "the inventory is written by the release script, so this page is
 *   refreshed in the same commit" — the claim lived in the renderer's help, and the script that
 *   writes the record carried no reference to it at all.
 *
 *   The fixture is what makes this testable rather than argued about: `bin/release.php` resolves
 *   its own root and runs every git command there, so it cannot be *pointed* at a repository — it
 *   has to live in one. `ReleaseRepo` plants a package with one class and one config file, so a
 *   release can be cut inside it and the page read back off disk afterwards.
 *
 * WHAT IS HELD
 *   The bytes: the page on disk is compared with the bytes the record renders, which is the one
 *   assertion every other one here is a detail of. The stamp: the page names the tag the release
 *   cut, which is the fact a page rendered at the wrong moment gets wrong. The commit: the page is
 *   in the release commit rather than left for the next reader to notice. And the tool's own
 *   answer — `php bin/api.php --check` inside the tree the release left behind, which is the
 *   interface a pipeline would call.
 */
final class PublishedPageTest extends TestCase
{
    private const ADDED = "### Added\n\n- A command nobody could run before.\n";

    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    /**
     * The state the defect left behind is the state to test from: the record and the page both
     * describing `v1.0.0`, committed, with the tool agreeing that they agree. The release is then
     * the only thing that can move the page to the tag it cut.
     */
    public function test_the_release_renders_the_page_from_the_record_it_writes(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');
        $repo->refreshInventory();
        $repo->script('api.php');
        $repo->commit('chore: the record and the page at v1.0.0');

        $before = $repo->scriptRun('api.php', '--check');

        $this->assertSame(0, $before->exitCode, $before->describe());
        $this->assertStringContainsString('**v1.0.0**', $repo->read('API.md'), $before->describe());

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('committed and tagged v1.1.0'), $run->describe());

        // The plan says what the run then did, in the same words: the counts are the page's own,
        // and the tree it names is the tag being created rather than the one it replaces.
        $this->assertSame(
            '1 class(es), 2 public method(s), described as v1.1.0',
            $run->plan('api page'),
            $run->describe(),
        );
        $this->assertTrue(
            $run->said('API.md re-rendered — 1 class(es), 2 public method(s), described as v1.1.0'),
            $run->describe(),
        );

        $page = str_replace(["\r\n", "\r"], "\n", $repo->read('API.md'));

        // The page and the record are one tree, read two ways. Anything short of the bytes is a
        // promise about a renderer rather than a property of the release.
        $this->assertSame(
            apiReport($repo->root()),
            $page,
            'API.md is not the page the record this release wrote renders.',
        );

        // ...and the record it was rendered from is this release's own, at the tag it created.
        $this->assertStringContainsString('**v1.1.0**', $page, $run->describe());
        $this->assertStringNotContainsString('v1.0.0', $page, 'the page still names the previous release');

        foreach (['files.tsv', 'methods.tsv', 'surface.tsv'] as $record) {
            $this->assertStringContainsString('describes the tree at v1.1.0', $repo->read($record), $record);
        }

        // The tag points at a tree that carries the page: a page written but not committed is one
        // the next checkout does not have.
        $this->assertStringContainsString('API.md', $repo->git('show', '--name-only', '--format=', 'HEAD'));
        $this->assertStringContainsString('API.md', $repo->git('ls-files', 'API.md'));
        $this->assertSame(
            '',
            trim($repo->git('status', '--porcelain', '--untracked-files=no', '--', '.')),
            'the release left tracked changes behind',
        );

        // And the tool a pipeline runs agrees, in the tree the release left behind.
        $after = $repo->scriptRun('api.php', '--check');

        $this->assertSame(0, $after->exitCode, $after->describe());
        $this->assertTrue($after->said('api report is current'), $after->describe());
    }

    /**
     * The plan counts the page from the tree, not from the record on disk — the one design decision
     * here worth pinning, because the two disagree exactly when the record is out of step with the
     * tree, which is the state a release exists to fix. A page line read out of the record on disk
     * would announce the counts of the release *before* this one, in a plan whose whole job is to
     * say what this run will write.
     */
    public function test_the_plan_counts_the_page_from_the_tree_rather_than_the_record_on_disk(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');
        $repo->refreshInventory();
        $repo->commit('chore: the record at v1.0.0');

        // A class the record has never been told about, so the record on disk and the tree this
        // plan is about disagree about what the page will hold. The record is still *readable* —
        // its stamp names the tag being released from — and the weighing reports the drift in it,
        // which is why the page line has to be the tree's: the two lines would otherwise be about
        // two different trees and neither would say so.
        $repo->write('src/Extra.php', <<<'PHP'
        <?php

        namespace Fixture;

        class Extra
        {
            public function extra(): string
            {
                return 'extra';
            }
        }
        PHP . "\n");
        $repo->commit('feat: a second class with a public method');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('fresh, weighed against v1.0.0'), $run->describe());
        $this->assertTrue($run->said('added public method Fixture\Extra::extra()'), $run->describe());
        $this->assertSame(
            '2 class(es), 3 public method(s), described as v1.1.0',
            $run->plan('api page'),
            $run->describe(),
        );
        $this->assertStringNotContainsString(
            'extra',
            $repo->read('methods.tsv'),
            'the record on disk is the one the plan had to read past, and it does not know about Extra',
        );
    }

    /**
     * A page that cannot be written stops the release before the tag, like the changelog, the
     * record and the index do. A version published with a page that disagrees with the record it
     * sits beside is worse than a failed run, because it looks like a successful one.
     */
    public function test_a_page_that_cannot_be_written_is_refused_before_the_tag(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        // The path is taken by something that cannot hold the bytes, which is the closest a test
        // gets to a permission failure on both platforms at once.
        mkdir($repo->path('API.md'));

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Could not write API.md'), $run->describe());
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
        $this->assertStringContainsString('## 0.1.1', $repo->read('CHANGELOG.md'), $run->describe());
    }
}
