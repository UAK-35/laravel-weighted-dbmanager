<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRun;

/**
 * The five reads one commit's notes need, as the verdict `bin/entry.php` prints.
 *
 * WHY THIS FILE IS THE SCRIPT'S ONLY GUARD
 * ---------------------------------------
 *   The script exists because answering "did this commit's entry ship?" by hand is `git log`,
 *   `git show --name-only`, `git show <sha> -- CHANGELOG.md`, `git tag --contains` and a read of
 *   the tag's own changelog held together in a head — and the answer is one of a few verdicts
 *   rather than five screens of output. A regression here is quiet: every read still succeeds, the
 *   report still prints, and what changes is which verdict a state gets. So every state the script
 *   distinguishes has a test, and each holds the exit code, the line the verdict is read from, and
 *   the sentence a reader is meant to trust.
 *
 *   The states are not variations on one fixture, because the script tells them apart: a note under
 *   `## Unreleased` and a note under `## 1.0.0` are the same bytes in different places, a promotion
 *   rewrites a heading and adds no bullet, and "the tag's tree has no changelog" is a release that
 *   shipped without the file. Each is built, committed, tagged where the state asks for it, and
 *   asked.
 *
 * The fixture is `ReleaseRepo`, so `bin/entry.php` runs as the real script in a package of its own,
 * with git answering to the fixture rather than to whoever installed it.
 */
final class EntryVerdictTest extends TestCase
{
    /**
     * One note, in the shape the changelog writes notes: a bold opening sentence followed by the
     * prose. The bold opening is what the script compares against the tag's file, so the fixtures
     * are written that way rather than as prose under a heading.
     */
    private const ADDED = "### Added\n\n- **A note.** It says what changed.\n";

    /** The heading a release writes over `## Unreleased` — dated, and not `Unreleased`. */
    private const RELEASED = '## 1.0.0 - 2026-01-01';

    // ─────────────────────────────────────────────────────────────────────────
    // Exit 0: nothing to publish
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A commit that writes no note and touches nothing the weighing reads. That is a pass rather
     * than an omission — a docs-only commit has no entry a consumer could want — and the paths read
     * is what decides it, so the assertion is on the list the script printed.
     */
    public function test_a_commit_that_writes_no_note_and_touches_nothing_weighed_is_a_pass(): void
    {
        $repo = ReleaseRepo::make();
        $repo->write('README.md', "# Fixture\n\nA sentence a reader can open.\n");
        $repo->commit('docs: a readme');

        $run = self::entry($repo);

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('1 — README.md', $run->plan('paths'), $run->describe());
        $this->assertSame('none — this commit adds no changelog entry', $run->plan('notes'), $run->describe());
        $this->assertTrue($run->said('nothing a release weighs was touched'), $run->describe());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Exit 1: no note where one is owed
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A config file added with no note: `config/` is one of the two paths the weighing reads, so
     * this is the commit a release cannot account for. It is asked with no revision argument, which
     * is the default path — the script reads HEAD — and the paths read is asserted because that is
     * what the refusal is built on.
     */
    public function test_a_commit_that_changes_what_a_release_weighs_without_a_note_is_refused(): void
    {
        $repo = ReleaseRepo::make();
        $repo->write('config/extra.php', "<?php\n\nreturn [\n    'added' => true,\n];\n");
        $repo->commit('feat: a config key with no note');

        $run = self::entry($repo);

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertSame('1 — config/extra.php', $run->plan('paths'), $run->describe());
        $this->assertSame('none — this commit adds no changelog entry', $run->plan('notes'), $run->describe());
        $this->assertTrue($run->said('changes what a release weighs'), $run->describe());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Exit 1: a note, and no release holding it
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A note added under `## Unreleased`, with no tag carrying the commit. The notes read counts it
     * under the heading it sits under, and the release read says what is missing: no tag at all.
     */
    public function test_the_notes_of_a_commit_no_tag_carries_are_unreleased(): void
    {
        $repo = ReleaseRepo::make();
        self::noteCommit($repo);

        $run = self::entry($repo);

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertSame('1 — added 1', $run->plan('notes'), $run->describe());
        $this->assertSame('none — no tag carries this commit', $run->plan('release'), $run->describe());
        $this->assertTrue($run->said('the notes are in no release'), $run->describe());
    }

    /**
     * A tag that carries the commit, but a tag's changelog that still holds the note under
     * `## Unreleased` — a release cut without promoting the section. The tag is not enough on its
     * own: `Unreleased` is excluded from the sections a note can be published in, which is the half
     * of the question the tag cannot answer.
     */
    public function test_a_tag_whose_changelog_still_holds_the_note_unreleased_does_not_publish_it(): void
    {
        $repo = ReleaseRepo::make();
        $noted = self::noteCommit($repo);

        $repo->write('README.md', "# Fixture\n\nA sentence a reader can open.\n");
        $repo->commit('docs: a readme');
        $repo->tag('v1.0.0');

        $run = self::entry($repo, $noted);

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertSame(
            'v1.0.0 carries this commit; 0 of 1 note(s) under no released heading',
            $run->plan('release'),
            $run->describe(),
        );
        $this->assertTrue($run->refused('  missing  A note.'), $run->describe());
        $this->assertTrue($run->said('does not hold every note this commit wrote'), $run->describe());
    }

    /**
     * The case the second read exists for: the release that carries the commit reworded the bullet
     * after it shipped. The commit's own diff is unchanged — a later commit is that commit's answer
     * and not this one's — and the verdict is the rewritten note, named.
     */
    public function test_a_note_rewritten_after_it_shipped_is_named_missing(): void
    {
        $repo = ReleaseRepo::make();

        $noted = self::noteCommit(
            $repo,
            "### Fixed\n\n- **The connector row no longer fails on a pair.** Both halves are read.\n",
        );

        $repo->write('CHANGELOG.md', '# Release Notes'."\n\n".self::RELEASED."\n\n### Fixed\n\n"
            ."- **The connector row reads a pair.** Both halves are read.\n");
        $repo->commit('Release 1.0.0');
        $repo->tag('v1.0.0');

        $run = self::entry($repo, $noted);

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('  missing  The connector row no longer fails on a pair.'),
            $run->describe(),
        );
        $this->assertTrue($run->said('does not hold every note this commit wrote'), $run->describe());
    }

    /**
     * A tag whose tree has no `CHANGELOG.md` at all — a release that shipped without the file the
     * notes live in. It is refused before the notes are compared, because there is nothing to
     * compare them against and "published by the tag" would be a claim the tag's own tree denies.
     */
    public function test_a_tag_whose_tree_has_no_changelog_cannot_hold_the_note(): void
    {
        $repo = ReleaseRepo::make();
        $noted = self::noteCommit($repo);

        $repo->drop('CHANGELOG.md');
        $repo->commit('chore: drop the changelog');
        $repo->tag('v1.0.0');

        $run = self::entry($repo, $noted);

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('and its tree has no CHANGELOG.md'), $run->describe());
        $this->assertTrue($run->said('cannot hold notes it did not ship'), $run->describe());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The release commit: a promotion, not an absence
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A release commit adds a `##` heading and no bullet — the section's notes are context in its
     * diff — so "this commit wrote no note" is the wrong reading of it. The script reads the
     * promotion instead, counts the bullets it promoted, and treats the tag as the release's own.
     */
    public function test_a_release_commit_is_read_as_the_promotion_it_is(): void
    {
        $repo = ReleaseRepo::make();
        self::noteCommit($repo);
        self::promote($repo);

        $run = self::entry($repo);

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('1 — promoted into ## 1.0.0 - 2026-01-01', $run->plan('notes'), $run->describe());
        $this->assertSame('v1.0.0 carries this commit', $run->plan('release'), $run->describe());
        $this->assertTrue(
            $run->said('this commit is the release of 1 note(s), published by v1.0.0.'),
            $run->describe(),
        );
    }

    /**
     * The same promotion on no tag: the section was written and no consumer can read it. The
     * refusal is about the promotion rather than about a missing note, which is the distinction the
     * `promoted()` read is for.
     */
    public function test_a_promotion_no_tag_carries_is_refused(): void
    {
        $repo = ReleaseRepo::make();
        self::noteCommit($repo);
        self::promote($repo, withTag: false);

        $run = self::entry($repo);

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertSame('1 — promoted into ## 1.0.0 - 2026-01-01', $run->plan('notes'), $run->describe());
        $this->assertSame('no tag carries this commit', $run->plan('release'), $run->describe());
        $this->assertTrue($run->said('the promotion is on no tag'), $run->describe());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Exit 0: the note the release published
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The answer the script exists for: a commit whose note a later release promoted, found under
     * the released heading in the tag's own file. Both the section and the count are asserted,
     * because a verdict of "published" that names the wrong section is the failure this guards.
     */
    public function test_a_note_the_release_promoted_is_published_by_the_tag_that_carries_it(): void
    {
        $repo = ReleaseRepo::make();
        $noted = self::noteCommit($repo);
        self::promote($repo);

        $run = self::entry($repo, $noted);

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(
            'v1.0.0 carries this commit; 1 of 1 note(s) under ## 1.0.0 - 2026-01-01',
            $run->plan('release'),
            $run->describe(),
        );
        $this->assertTrue($run->said('the notes this commit wrote are published by v1.0.0.'), $run->describe());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Exit 2: asked wrongly, before any git read
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The three ways to ask the question wrongly, none of which touches a ref: a revision-shaped
     * word where an option was meant, two revisions, and a revision git does not have. `--help` is
     * the fourth answer and it is a pass.
     */
    public function test_usage_errors_exit_two_before_any_git_read(): void
    {
        $repo = ReleaseRepo::make();

        $option = self::entry($repo, '--nope');
        $this->assertSame(2, $option->exitCode, $option->describe());
        $this->assertTrue($option->refused('one revision, or none for HEAD'), $option->describe());

        $two = $repo->scriptRun('entry.php', 'HEAD', 'HEAD');
        $this->assertSame(2, $two->exitCode, $two->describe());
        $this->assertTrue($two->refused('one revision, or none for HEAD'), $two->describe());

        $unknown = self::entry($repo, 'no-such-revision');
        $this->assertSame(2, $unknown->exitCode, $unknown->describe());
        $this->assertTrue($unknown->refused('Not a revision this repository has: no-such-revision'), $unknown->describe());

        $help = self::entry($repo, '--help');
        $this->assertSame(0, $help->exitCode, $help->describe());
        $this->assertTrue($help->said('Exit codes: 0 published'), $help->describe());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────

    /** Run the script at one revision, or at the checkout's HEAD when none is named. */
    private static function entry(ReleaseRepo $repo, ?string $rev = null): ReleaseRun
    {
        $arguments = $rev === null ? [] : [$rev];

        return $repo->scriptRun('entry.php', ...$arguments);
    }

    /**
     * Write one note under `## Unreleased`, commit it, and hand back the commit — the revision
     * every "did it ship" question here is asked about.
     */
    private static function noteCommit(ReleaseRepo $repo, string $note = self::ADDED): string
    {
        $repo->release_notes($note);
        $repo->commit('feat: a note');

        return trim($repo->git('rev-parse', 'HEAD'));
    }

    /**
     * Move the whole section under a released heading the way a release does: the heading line is
     * rewritten in place, so the diff adds a `##` and no bullet — which is what makes it a
     * promotion rather than a commit that writes the notes again.
     */
    private static function promote(ReleaseRepo $repo, string $tag = 'v1.0.0', bool $withTag = true): void
    {
        $repo->write('CHANGELOG.md', str_replace('## Unreleased', self::RELEASED, $repo->read('CHANGELOG.md')));
        $repo->commit('Release 1.0.0');

        if ($withTag) {
            $repo->tag($tag);
        }
    }
}
