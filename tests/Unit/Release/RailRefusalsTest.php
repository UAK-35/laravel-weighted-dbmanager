<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

/**
 * The safety rails that stop a release before anything is written, each one driven by
 * producing the state it is about.
 *
 * RELEASING.md's rails table is the list, and these are the rows the rest of the suite was
 * silent about: a tree that is not a repository, a file the release reads and cannot find,
 * a changelog with no `## Unreleased` heading to promote, a dirty tree, and a shell with no
 * terminal to answer the one question the script asks. Each is a state of the tree rather
 * than an argument, which is why each is made rather than passed — and why the fixture has
 * to be able to delete a path and to make one unwritable.
 *
 * Two of them are also the rails with a documented escape, and the escape is tested from
 * both sides: `--dry-run` warns about a dirty tree instead of refusing, and `--allow-dirty`
 * releases the reviewed files while leaving the unreviewed edit — and the tag — exactly
 * where they were. A rail tested only in the refusing direction would pass for a script that
 * refused unconditionally.
 *
 * The shared shape of every assertion here is that a refusal costs nothing: no tag, no
 * promoted notes, no inventory written. That is the whole claim of a precondition.
 */
final class RailRefusalsTest extends TestCase
{
    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    /**
     * The first rail, and the one that has to be first: tags are the version, so without git
     * there is nothing a release could be.
     *
     * `drop('.git')` rather than a directory that was never a repository, because the
     * packages that hit this are the ones that lost a `.git` — an export, an archive, a copy
     * of a directory that was not taken with its history — and the fixture has to be
     * populated for the check to be the one that refuses.
     */
    public function test_a_tree_that_is_not_a_repository_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $repo->drop('.git');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('This is not a git repository — releases are tags, so git is required.'),
            $run->describe(),
        );

        // Refused before anything was worked out: no plan, so nothing was weighed either.
        $this->assertSame('', $run->plan('next version'), $run->describe());
    }

    /**
     * A release writes the CHANGELOG and reads it, so a package without one is a package
     * this cannot release — and the refusal names the file rather than the argument, because
     * the user's mistake is in the tree.
     */
    public function test_a_missing_changelog_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $repo->drop('CHANGELOG.md');
        $repo->commit('chore: drop the changelog');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Missing CHANGELOG.md.'), $run->describe());
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
    }

    /** The same for `composer.json`, which the release reads for the branch alias. */
    public function test_a_missing_composer_json_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $repo->drop('composer.json');
        $repo->commit('chore: drop composer.json');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Missing composer.json.'), $run->describe());
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
    }

    /**
     * A changelog with no `## Unreleased` heading is not an empty section — it is no section,
     * and the two are different refusals because the repairs are different: one asks for
     * notes, this one asks for the heading they go under.
     */
    public function test_a_changelog_with_no_unreleased_heading_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $repo->write('CHANGELOG.md', "# Release Notes\n\n## 1.0.0 - 2026-01-01\n\n- The old release.\n");
        $repo->commit('chore: a changelog with no Unreleased heading');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('CHANGELOG.md has no "## Unreleased" section to promote.'),
            $run->describe(),
        );
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
    }

    /**
     * The dirty rail, and the reason it exists: a tag has to point at exactly what was
     * reviewed, so an uncommitted edit is a version nobody can reproduce. The refusal quotes
     * git's own porcelain, which is what tells the reader *which* file is uncommitted.
     */
    public function test_tracked_files_have_to_be_committed_first(): void
    {
        $repo = self::withADirtyFile();

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused(
                'Tracked files have uncommitted changes — commit them first, so the tag points at'
                . ' exactly what was reviewed.',
            ),
            $run->describe(),
        );
        $this->assertTrue($run->refused('M src/Thing.php'), $run->describe());

        // Refused before the plan, so nothing was weighed and nothing was written.
        $this->assertSame('', $run->plan('next version'), $run->describe());
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
    }

    /**
     * The same rail's documented exemption: a dry run warns instead of refusing, because a
     * plan decides nothing — and it says so twice, in a note and in the plan's own branch
     * line, so a reader who scrolled to the version sees it there too.
     */
    public function test_the_dry_run_warns_about_a_dirty_tree_instead_of_refusing(): void
    {
        $repo = self::withADirtyFile();

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said('Working tree is dirty (1 changed file(s)) — ignored for this dry run.'),
            $run->describe(),
        );
        $this->assertSame('main  (dirty)', $run->plan('branch'), $run->describe());
        $this->assertSame('0.1.1  (tag v0.1.1)', $run->plan('next version'), $run->describe());
        $this->assertTrue($run->said('Dry run: nothing was written, committed or tagged.'), $run->describe());
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
    }

    /**
     * The other escape, and the reason it is not a rail being waved through: `--allow-dirty`
     * exists for the first release of an already-populated repository, where the import and
     * the release are one commit. It releases the files a release *writes* — the CHANGELOG,
     * the inventory, the alias — and it does not sweep the rest of the tree into the tag.
     *
     * So the tag is asserted to hold the reviewed version of the file rather than the edit:
     * an `--allow-dirty` that quietly committed everything would be the rail's defect
     * reintroduced one flag away.
     */
    public function test_allow_dirty_releases_the_written_files_and_leaves_the_edit_out(): void
    {
        $repo = self::withADirtyFile();
        $edited = $repo->read('src/Thing.php');

        $run = $repo->release('--weigh', '--yes', '--skip-ci', '--allow-dirty');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('committed and tagged v0.1.1'), $run->describe());
        $this->assertSame('main  (dirty)', $run->plan('branch'), $run->describe());

        // The edit is still uncommitted, and the tag holds the version that was reviewed.
        $this->assertSame($edited, $repo->read('src/Thing.php'), $run->describe());
        $this->assertStringContainsString(
            'M src/Thing.php',
            $repo->git('status', '--porcelain', '--untracked-files=no', '--', '.'),
            $run->describe(),
        );
        $this->assertStringNotContainsString(
            'thing changed',
            $repo->git('show', 'v0.1.1:src/Thing.php'),
            $run->describe(),
        );
    }

    /**
     * The one rail that is about the shell rather than the tree, and the only one a reader is
     * likely to hit by accident — a release run from CI, from a script, or from anything else
     * whose stdin is not a terminal.
     *
     * It is tested from both sides in one test on purpose: the same tree, one flag later,
     * releases. That is what makes the refusal about the missing terminal rather than about
     * anything else in the fixture, and it pins the escape as the flag itself rather than as
     * a change to the repository.
     */
    public function test_a_non_interactive_shell_is_refused_without_yes(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $notes = $repo->read('CHANGELOG.md');

        $run = $repo->release('--weigh', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('Refusing to release from a non-interactive shell without --yes.'),
            $run->describe(),
        );
        $this->assertTrue($run->said('Aborted.'), $run->describe());

        // The tree was releasable — the plan is printed before the question — and the
        // question itself is never written, because there is nobody to answer it.
        $this->assertSame('0.1.1  (tag v0.1.1)', $run->plan('next version'), $run->describe());
        $this->assertFalse($run->said('Commit and tag v0.1.1?'), $run->describe());

        // And refusing cost nothing: no tag, no promoted notes, no inventory.
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
        $this->assertSame($notes, $repo->read('CHANGELOG.md'), $run->describe());
        $this->assertFalse($repo->exists('files.tsv'), $run->describe());

        $yes = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $yes->exitCode, $yes->describe());
        $this->assertStringContainsString('v0.1.1', $repo->tags(), $yes->describe());
    }

    /** A fixture with one tracked file edited and nothing committed. */
    private static function withADirtyFile(): ReleaseRepo
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        $repo->write(
            'src/Thing.php',
            str_replace("return 'thing';", "return 'thing changed';", $repo->read('src/Thing.php')),
        );

        return $repo;
    }
}
