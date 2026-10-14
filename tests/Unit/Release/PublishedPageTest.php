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
     * A class the fixture gains, so the record beside the page can be made to move on.
     *
     * The tests about reading a *past* tree need two of them: a page that is the same either way
     * would pass whatever the reader did, which is the failure these are about.
     */
    private const EXTRA = <<<'PHP'
    <?php

    namespace Fixture;

    class Extra
    {
        public function extra(): string
        {
            return 'extra';
        }
    }
    PHP;

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
     * A past release's page, rendered out of the tree its tag holds — and this checkout's page left
     * where it was.
     *
     * WHY THIS IS A READ RATHER THAN A SECOND PAGE
     * -------------------------------------------
     *   `API.md` is one file, and the one a checkout ships describes that checkout: it is the page
     *   the record beside it renders. A past release's page is therefore not something a command can
     *   write *down* — replacing the page with another tree's publishes a consumer-facing document
     *   that disagrees with the record in front of it, which is the failure the page exists to make
     *   impossible. So `--at` prints: the rows come out of the tag's tree the way
     *   `bin/inventory.php --at` reads them, the stamp is that tree's release, and the bytes go to
     *   stdout. The two tags here hold different rows on purpose.
     *
     *   The page is the output, so the note about where it came from is on the other stream — a note
     *   in front of the bytes would be a page that does not render, and a test that read the two
     *   streams together could not tell a clean redirect from a broken one.
     */
    public function test_a_read_at_a_ref_prints_that_trees_page_and_writes_nothing(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');
        $repo->refreshInventory();
        $repo->script('api.php');
        $repo->commit('chore: the record and the page at v1.0.0');

        $published = $repo->read('API.md');

        // The tree moves on and the second release's page is written over the same file, which is
        // what makes the read below the only way back to the first one.
        $repo->write('src/Extra.php', self::EXTRA . "\n");
        $repo->commit('feat: a second class with a public method');
        $repo->tag('v1.1.0');
        $repo->refreshInventory();
        $repo->script('api.php');
        $repo->commit('chore: the record and the page at v1.1.0');

        $now = $repo->read('API.md');

        $run = $repo->scriptRun('api.php', '--at=v1.0.0');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('reading the tree at v1.0.0 out of git, not the checkout — nothing is written'),
            $run->describe(),
        );

        // The bytes are the page that release published, read back off the tag that published it:
        // that is the closed loop, and anything less is a promise about a renderer rather than the
        // past page a reader asked for.
        $this->assertSame($published, str_replace(["\r\n", "\r"], "\n", $run->output), $run->describe());
        $this->assertNotSame($now, $published, 'the two tags have to render different pages for this to mean anything');
        $this->assertStringContainsString('**v1.0.0**', $run->output, $run->describe());
        $this->assertStringNotContainsString('Extra', $run->output, 'the page printed is the tag\'s, and the class the tag does not have is on the newer one');

        // ...and the page in the checkout is the one the second release left, byte for byte: a read
        // that repaired the file it read would be the write this mode exists not to do.
        $this->assertSame($now, $repo->read('API.md'), 'the read wrote the checkout\'s page');
    }

    /**
     * A ref this repository does not have is refused before anything is read, and nothing is
     * printed under its name.
     *
     * A ref that does not resolve reads as a tree with no files in it, so the refusal is the one
     * outcome that is not merely confusing: a page rendered from that empty record is a consumer
     * document announcing a package with no API.
     */
    public function test_a_ref_this_repository_does_not_have_is_refused_rather_than_read_as_an_empty_tree(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');
        $repo->refreshInventory();
        $repo->script('api.php');
        $repo->commit('chore: the record and the page at v1.0.0');

        $before = $repo->read('API.md');

        $run = $repo->scriptRun('api.php', '--at=v9.9.9');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('v9.9.9 is not a ref this repository has'), $run->describe());
        $this->assertSame('', $run->output, 'a refused read printed a page');
        $this->assertSame($before, $repo->read('API.md'), $run->describe());
    }

    /**
     * `--check --at=REF` answers the question a release's own commit has to answer, in both
     * directions: whether the page in this checkout is the one that tree's record renders.
     *
     * Run against the tag the page was written at, it is the release's own guard. Run against an
     * older one after the page has moved on, it says no — and says what to do about it, which is
     * nothing: the page a checkout ships is the checkout's, and no run writes another tree's. That
     * is why the refusal names a report rather than a repair, and why the mode exists at all.
     */
    public function test_check_at_answers_whether_the_page_on_disk_is_the_one_that_tree_renders(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');
        $repo->refreshInventory();
        $repo->script('api.php');
        $repo->commit('chore: the record and the page at v1.0.0');

        $current = $repo->scriptRun('api.php', '--check', '--at=v1.0.0');

        $this->assertSame(0, $current->exitCode, $current->describe());
        $this->assertTrue(
            $current->said('the page on disk is the one the tree at v1.0.0 renders'),
            $current->describe(),
        );

        $repo->write('src/Extra.php', self::EXTRA . "\n");
        $repo->commit('feat: a second class with a public method');
        $repo->tag('v1.1.0');
        $repo->refreshInventory();
        $repo->script('api.php');
        $repo->commit('chore: the record and the page at v1.1.0');

        $stale = $repo->scriptRun('api.php', '--check', '--at=v1.0.0');

        $this->assertSame(1, $stale->exitCode, $stale->describe());
        $this->assertTrue($stale->refused('The API report is out of step with the tree at v1.0.0'), $stale->describe());
        $this->assertTrue($stale->refused("No run writes another tree's page"), $stale->describe());

        // The checkout's own page is still the one the checkout's own record renders: comparing it
        // with another tree's is a question, not a rewrite.
        $plain = $repo->scriptRun('api.php', '--check');

        $this->assertSame(0, $plain->exitCode, $plain->describe());
    }

    /** The flags that cannot mean what they say are refused the way an unknown option is. */
    public function test_an_at_with_no_ref_is_a_usage_error(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);

        $run = $repo->scriptRun('api.php', '--at=');

        $this->assertSame(2, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('--at= needs a ref: a tag, a branch or a commit.'), $run->describe());
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
