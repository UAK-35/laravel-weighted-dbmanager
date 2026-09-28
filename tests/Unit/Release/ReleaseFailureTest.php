<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

/**
 * What happens when a release gets past every rail and a step of the release itself fails:
 * a file it has to write cannot be written, git will not take the index, git cannot push.
 *
 * These are not rails — RELEASING.md's table is about the state of the tree *before* the
 * release starts — and they are not the same kind of failure either. A rail costs nothing: it
 * refuses before anything is written, so the repository is exactly as it was. A step that
 * fails half-way has already written something, which makes two things worth pinning that no
 * rail test can:
 *
 *   - the exit is 1 and the message names the step, not the symptom (`git add failed: …`,
 *     not `Could not release`);
 *   - **the tag is the line that did not move.** Everything a release writes is uncommitted
 *     until the commit, and the tag is what makes a version exist, so a failure at any step
 *     leaves the repository with no new version in it — and, for the push, with a tag that is
 *     local only, which is the one failure a reader has to be told about because the release
 *     *did* happen.
 *
 * Each is produced rather than mocked: a read-only file, a path taken by a directory, a lock
 * git will not break, and a remote that is not there. The script is a subprocess, so faking
 * any of these would be testing the fake.
 */
final class ReleaseFailureTest extends TestCase
{
    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    /** An `### Added` entry, so opening the next minor line moves the branch aliases. */
    private const ADDED = "### Added\n\n- A command nobody could run before.\n";

    /**
     * The first write of a release, and the one that matters most: the promoted CHANGELOG is
     * the version's own record, so a release that cannot write it has nothing to tag.
     *
     * `--allow-dirty` is passed because making a file read-only is a change to the file that
     * some platforms record as a modification, and this test is about the write rather than
     * about that — the rail has its own tests.
     */
    public function test_a_changelog_that_cannot_be_written_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        if (!$repo->makeReadOnly('CHANGELOG.md')) {
            $this->markTestSkipped('This environment cannot make a file read-only.');
        }

        $run = $repo->release('--weigh', '--yes', '--skip-ci', '--allow-dirty');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Could not write CHANGELOG.md'), $run->describe());

        // Nothing was tagged, and the inventory — which the release writes after the
        // CHANGELOG — was not attempted, so there is no half-written release in the tree.
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
        $this->assertFalse($repo->exists('files.tsv'), $run->describe());
        $this->assertFalse($repo->exists('methods.tsv'), $run->describe());
    }

    /**
     * The second write, and the one that lands after the release has started changing the
     * tree: the alias is rewritten only when the version opens a new line, and by the time
     * that write is attempted the CHANGELOG and both inventories are already on disk.
     *
     * So this is the test of the failure that leaves the repository *partly* released — and
     * the reason the tag is the assertion that matters. A reader who finds a promoted
     * CHANGELOG and no tag is looking at exactly this state, and the next run redoes the work
     * rather than being blocked by it.
     */
    public function test_a_composer_json_that_cannot_be_written_is_refused_after_the_notes_are_promoted(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');
        $composer = $repo->read('composer.json');

        if (!$repo->makeReadOnly('composer.json')) {
            $this->markTestSkipped('This environment cannot make a file read-only.');
        }

        $run = $repo->release('--weigh', '--yes', '--skip-ci', '--allow-dirty');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Could not write composer.json'), $run->describe());

        // No version exists: the tag is what a failure cannot half-create.
        $this->assertSame('v1.0.0', trim($repo->tags()), $run->describe());
        $this->assertSame($composer, $repo->read('composer.json'), $run->describe());

        // What it did leave behind: the promoted notes, uncommitted — which is also why the
        // next run's dirty rail would stop a second attempt until somebody looks.
        $this->assertStringContainsString('## 1.1.0', $repo->read('CHANGELOG.md'), $run->describe());
        $this->assertStringContainsString(
            'M CHANGELOG.md',
            $repo->git('status', '--porcelain', '--untracked-files=no', '--', '.'),
            $run->describe(),
        );
    }

    /**
     * The inventory is written as a pair, so a path that cannot hold it fails both — and the
     * refusal names both files rather than the one that happened to be first, because the
     * reader has two paths to look at.
     */
    public function test_an_inventory_that_cannot_be_written_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        // The path is taken by something that cannot hold the bytes, which is the closest a
        // test gets to a permission failure on both platforms at once.
        mkdir($repo->path('files.tsv'));

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('Could not write files.tsv / methods.tsv.'),
            $run->describe(),
        );

        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
        $this->assertFalse($repo->exists('methods.tsv'), $run->describe());
    }

    /**
     * The commit itself: git is asked to stage five files, and an index it cannot write fails
     * the staging rather than the commit — so the refusal names `git add`, which is the step
     * that actually failed.
     *
     * Nothing else changes: the CHANGELOG is already promoted and the inventory already
     * written, both uncommitted, and no tag exists.
     */
    public function test_an_index_that_cannot_be_locked_refuses_the_add(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $repo->write('.git/index.lock', '');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('git add failed'), $run->describe());
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
        $this->assertStringContainsString('## 0.1.1', $repo->read('CHANGELOG.md'), $run->describe());
    }

    /**
     * The last step, and the only failure that leaves a version behind: the commit and the tag
     * happen before the push, so a push that cannot run means the release *did* happen and
     * nobody was told.
     *
     * The second half is what a reader does next, and it is not another release: the notes
     * were promoted, so the `## Unreleased` section this run consumed is empty and the next
     * run refuses there rather than at the tag. What is missing is the push, and the only
     * thing a second release would produce is a second version.
     */
    public function test_a_push_that_cannot_run_is_reported_after_the_tag_was_cut(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        // No remote at all: the one state a machine with no network, no token or no `gh` is in.
        $run = $repo->release('--weigh', '--yes', '--skip-ci', '--push');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('git push failed'), $run->describe());
        $this->assertSame('git push origin main --follow-tags', $run->plan('push'), $run->describe());

        // It got as far as tagging: the note comes first, and the push note never comes.
        $this->assertTrue($run->said('committed and tagged v0.1.1'), $run->describe());
        $this->assertFalse($run->said('pushed main and v0.1.1 to origin'), $run->describe());
        $this->assertStringContainsString('v0.1.1', $repo->tags(), $run->describe());
        $this->assertSame('Release v0.1.1', trim($repo->git('log', '-1', '--format=%s')), $run->describe());

        $again = $repo->release('--weigh', '--yes', '--skip-ci', '--push');

        $this->assertSame(1, $again->exitCode, $again->describe());
        $this->assertTrue(
            $again->refused('The Unreleased section has no entries'),
            $again->describe(),
        );
        $this->assertStringNotContainsString('v0.1.2', $repo->tags(), $again->describe());
    }
}
