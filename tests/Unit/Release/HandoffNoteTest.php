<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\Handoff;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

/**
 * The three tag rows a release moves in HANDOFF.md — the one claim a tag falsifies.
 *
 * WHY A RELEASE WRITES THE NOTE
 * -----------------------------
 *   Section 3 of the note is a claim about *now*: the tags on the branch, the latest of them,
 *   and the suffixless version that is **not** there. A tag falsifies three rows of it in one
 *   act, and because the push that publishes the tag comes after the commit the release made,
 *   the failure lands on CI — on a tag somebody has already fetched. The release therefore moves
 *   those three rows into its own commit, and this file is where that is held: the rows it moves
 *   are the rows `HandoffTest` reads (`Handoff::tagRowLines()`), nothing else in the note moves,
 *   and a note it cannot move stops the release rather than being left stale behind a tag.
 *
 * THE NOTE UNDER TEST IS THIS PACKAGE'S
 * -------------------------------------
 *   `ReleaseRepo::withNote()` copies the real `HANDOFF.md`, so the labels, the two-column rows and
 *   the sentences a version sits inside are the ones a release will actually be run against. A
 *   note laid out here would agree with this file about a shape neither the reader nor the writer
 *   had seen.
 */
final class HandoffNoteTest extends TestCase
{
    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    /**
     * The three rows move, and the rest of the note is left byte for byte as it was.
     *
     * The rows asserted against are the ones the guard reads rather than three lines written out
     * here: a writer that found its rows by a rule of its own would pass a test that named the
     * same three lines back to it, and fail on CI instead.
     */
    public function test_the_release_moves_the_notes_tag_rows_into_its_own_commit(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->withNote();
        $repo->tag('v1.0.0');

        $rows = Handoff::tagRowLines();
        $before = $repo->read('HANDOFF.md');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(
            'HANDOFF.md — Tags on the branch, The latest of them, A version with no suffix moved to v1.0.1',
            $run->plan('handoff note'),
            $run->describe(),
        );
        $this->assertTrue(
            $run->said('handoff note moved to v1.0.1 — Tags on the branch, The latest of them, A version with no suffix'),
            $run->describe(),
        );

        $after = $repo->read('HANDOFF.md');

        $this->assertSame(
            array_values($rows),
            $this->changedLines($before, $after),
            'Only the three rows section 3 names the tags in may differ: the rest of the note is a '
            . 'working tree taken by hand, which a release has no way to re-take.' . PHP_EOL
            . $run->describe(),
        );

        $was = explode("\n", $before);
        $now = explode("\n", $after);

        // The list gains the tag just cut, and the two rows that name one version move only that
        // version — the sentence around it is the note's and is left as it was written.
        $this->assertSame(
            rtrim($was[$rows['tags'] - 1], ' |') . ', `v1.0.1` |',
            $now[$rows['tags'] - 1],
            $run->describe(),
        );
        $this->assertSame(
            str_replace('`' . Handoff::latest() . '`', '`v1.0.1`', $was[$rows['latest'] - 1]),
            $now[$rows['latest'] - 1],
            $run->describe(),
        );
        $this->assertSame(
            str_replace('`' . Handoff::absent() . '`', '`v1.0.2`', $was[$rows['absent'] - 1]),
            $now[$rows['absent'] - 1],
            $run->describe(),
        );

        // Into the release commit, or the tag points at a note naming the tag before it.
        $this->assertStringContainsString(
            'HANDOFF.md',
            $repo->git('show', '--name-only', '--format=', 'HEAD'),
            $run->describe(),
        );
        $this->assertSame('', trim($repo->git('status', '--porcelain', '--untracked-files=no', '--', '.')));
    }

    /**
     * The absent row is the *smallest* version with no suffix that is newer than the tag being
     * cut, which is two rules in one: a prerelease announces the release it will take, and above
     * a release it is the next patch.
     *
     * The note's own register is the second witness to the first rule: its newest tag is
     * `v0.2.0-alpha3` and the row it carries beside it is `v0.2.0`.
     */
    public function test_the_absent_row_names_the_release_a_prerelease_announces(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->withNote();
        $repo->tag('v0.1.0-alpha1');
        $repo->git('switch', '-c', 'dev');

        $rows = Handoff::tagRowLines();
        $was = explode("\n", $repo->read('HANDOFF.md'));

        $run = $repo->release('--version=0.1.0-alpha2', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('handoff note moved to v0.1.0-alpha2'), $run->describe());

        $now = explode("\n", $repo->read('HANDOFF.md'));

        $this->assertSame(
            str_replace('`' . Handoff::absent() . '`', '`v0.1.0`', $was[$rows['absent'] - 1]),
            $now[$rows['absent'] - 1],
            'The suffixless version the note says does not exist is the one the prerelease '
            . 'announces — not the prerelease itself.' . PHP_EOL . $run->describe(),
        );
        $this->assertSame(
            rtrim($was[$rows['tags'] - 1], ' |') . ', `v0.1.0-alpha2` |',
            $now[$rows['tags'] - 1],
            $run->describe(),
        );
    }

    /**
     * A note that already says what this release would write is neither rewritten nor committed:
     * a file that already holds these bytes is written by nobody, and a release that carried an
     * unchanged note into its commit would be claiming an edit it did not make.
     */
    public function test_a_note_already_at_this_version_is_left_out_of_the_commit(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->withNote(<<<'MD'
        # Handoff — the state of this package

        ## 3. Unreleased — built, tested, and in nothing a consumer can install

        | Field | At the snapshot |
        |---|---|
        | Tags on the branch | `v1.0.0`, `v1.0.1` |
        | The latest of them | `v1.0.1` |
        | A version with no suffix | none — `v1.0.2` does not exist |
        MD);
        $repo->tag('v1.0.0');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(
            'HANDOFF.md already names v1.0.1 — nothing to move',
            $run->plan('handoff note'),
            $run->describe(),
        );
        $this->assertStringNotContainsString(
            'HANDOFF.md',
            $repo->git('show', '--name-only', '--format=', 'HEAD'),
            'An unchanged note has no business in the release commit.' . PHP_EOL . $run->describe(),
        );
        $this->assertFalse($run->said('handoff note moved'), $run->describe());
    }

    /**
     * The note is a step of the release like any other file it writes, so a note it cannot write
     * stops the release before the tag — with the promoted CHANGELOG already on disk, which is the
     * shape every step after the first leaves.
     *
     * Produced rather than mocked: a read-only note still *reads*, so the release gets all the way
     * to its last write, and `--allow-dirty` is passed because the bit itself is a change some
     * platforms report as a modification to the file — the rail has its own tests.
     */
    public function test_a_note_that_cannot_be_written_is_refused_before_the_tag(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->withNote();
        $repo->tag('v1.0.0');

        if (!$repo->makeReadOnly('HANDOFF.md')) {
            $this->markTestSkipped('This environment cannot make a file read-only.');
        }

        $run = $repo->release('--weigh', '--yes', '--skip-ci', '--allow-dirty');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Could not write HANDOFF.md'), $run->describe());

        // The tag is the line that did not move.
        $this->assertSame('v1.0.0', trim($repo->tags()), $run->describe());
        $this->assertStringContainsString('## 1.0.1', $repo->read('CHANGELOG.md'), $run->describe());
    }

    /**
     * A row the release cannot find by the label the guard reads it by stops the release — before
     * the plan is printed and before anything is written.
     *
     * The alternative is the failure this exists to end: a tag whose note still names the tag
     * before it, reported by CI, on a commit already pushed. A refusal a reader can act on beats
     * a red build they did not cause, and the tree it refuses on is untouched — no promoted
     * CHANGELOG, no tag, and the note as it was.
     */
    public function test_a_note_whose_row_is_reworded_is_refused_before_anything_is_written(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->withNote(str_replace(
            '| A version with no suffix |',
            '| The suffixless version |',
            ReleaseRepo::note(),
        ));
        $repo->tag('v1.0.0');

        $before = $repo->read('HANDOFF.md');
        $notes = $repo->read('CHANGELOG.md');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('carries no [A version with no suffix] row'), $run->describe());
        $this->assertSame('', $run->plan('next version'), 'The plan was printed for a refused release.');

        $this->assertSame($before, $repo->read('HANDOFF.md'), 'A refused release edited the note.');
        $this->assertSame($notes, $repo->read('CHANGELOG.md'), 'The notes were promoted anyway.');
        $this->assertSame("v1.0.0\n", $repo->tags(), 'A refused release cut a tag.');
        $this->assertSame('', trim($repo->git('status', '--porcelain')), 'The tree moved.');
    }

    /**
     * A row that names no version has nothing in it to move, and that is a refusal too: a `The
     * latest of them` row that says `none yet` is a note whose reading the release cannot
     * complete, and completing it by writing a sentence of its own would be the release writing
     * prose it did not write.
     */
    public function test_a_row_with_no_version_in_it_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->withNote(str_replace(
            '| The latest of them | `' . Handoff::latest() . '` |',
            '| The latest of them | none yet |',
            ReleaseRepo::note(),
        ));
        $repo->tag('v1.0.0');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('names no version'), $run->describe());
        $this->assertTrue($run->refused('The latest of them'), $run->describe());
        $this->assertSame("v1.0.0\n", $repo->tags(), $run->describe());
    }

    /**
     * Two rows carrying one label is a table nothing can be asked of: the guard reads the first
     * of them and the note means whichever the writer picked, so the release refuses rather than
     * moving a row that may not be the row the guard reads.
     */
    public function test_two_rows_sharing_one_label_are_refused(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->withNote(ReleaseRepo::note() . "\n| Tags on the branch | `v1.0.0` |\n");
        $repo->tag('v1.0.0');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('carries two [Tags on the branch] rows'), $run->describe());
        $this->assertSame("v1.0.0\n", $repo->tags(), $run->describe());
    }

    /**
     * A package that keeps no note is not a package with a broken one: nothing is moved, nothing
     * is written, and the plan says which of the two states it read — every fixture in this suite
     * but the ones above is this state, which is why the line is printed rather than left out.
     */
    public function test_a_package_that_keeps_no_note_gets_none_written(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v1.0.0');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(
            'no HANDOFF.md in this package — none is written',
            $run->plan('handoff note'),
            $run->describe(),
        );
        $this->assertFalse($repo->exists('HANDOFF.md'), 'A release invented a note.' . PHP_EOL . $run->describe());
        $this->assertStringNotContainsString(
            'HANDOFF.md',
            $repo->git('show', '--name-only', '--format=', 'HEAD'),
            $run->describe(),
        );
    }

    /**
     * The note is read at the commit the release makes, and told which tag is pending.
     *
     * WHY THE PENDING TAG IS THE THING ASSERTED
     * -----------------------------------------
     *   A release moves the note's tag rows *before* it tags, so at the commit it verifies the note
     *   names a tag this repository does not have yet. Two claims in the guard are about tags existing,
     *   and without being told which one is pending they would refuse every release there is — so the
     *   one thing this step passes on is asserted here: the tag the release is about to create, by
     *   name, to the command that reads the note.
     */
    public function test_the_note_is_read_at_the_commit_the_release_tags_and_told_the_pending_tag(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->withNote();
        $repo->tag('v1.0.0');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(
            'HandoffTest at the commit this tags — nothing is tagged if it is red',
            $run->plan('note guard'),
            $run->describe(),
        );
        $this->assertSame('v1.0.1', $repo->guardAsked(), $run->describe());
        $this->assertTrue($run->said('handoff note guard — green at'), $run->describe());
        $this->assertStringContainsString('v1.0.1', $repo->tags(), $run->describe());
    }

    /**
     * A note the guard refuses is a version nobody is handed: no tag, and the commit unmade.
     *
     * The two halves are one claim. A tag is the thing a consumer pins, so it is the one artefact a
     * release must not leave behind on a red note; the commit is unmade with a soft reset because it
     * is a commit whose only reason to exist was the tag above it, and a branch that kept it would be
     * a release somebody could push by accident. The files stay staged, so what the guard named is in
     * front of whoever runs it next.
     */
    public function test_a_note_the_guard_refuses_is_a_release_that_leaves_no_tag(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->withNote()->withGuard(1);
        $repo->tag('v1.0.0');

        $before = trim($repo->git('rev-parse', 'HEAD'));

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused("The handoff note's guard is red at the commit this release made"), $run->describe());
        $this->assertTrue($run->refused('nothing was tagged'), $run->describe());
        $this->assertTrue($run->refused('The commit was unmade'), $run->describe());
        $this->assertTrue($run->refused('the note describes has been released past'), $run->describe());
        $this->assertSame("v1.0.0\n", $repo->tags(), $run->describe());
        $this->assertSame(
            $before,
            trim($repo->git('rev-parse', 'HEAD')),
            'A release that could not verify its note left the commit it made.' . PHP_EOL . $run->describe(),
        );
        $this->assertStringContainsString(
            'CHANGELOG.md',
            $repo->git('status', '--porcelain'),
            'The files the release wrote are not staged for the repair.' . PHP_EOL . $run->describe(),
        );
    }

    /**
     * The lines the two readings of the note disagree about, 1-based — the reading of what a
     * release moved.
     *
     * @return list<int>
     */
    private function changedLines(string $before, string $after): array
    {
        $was = explode("\n", $before);
        $now = explode("\n", $after);

        $this->assertSame(
            count($was),
            count($now),
            'The release changed the note\'s line count, so a row was added or dropped.',
        );

        $changed = [];

        foreach ($was as $index => $line) {
            if ($line !== $now[$index]) {
                $changed[] = $index + 1;
            }
        }

        return $changed;
    }
}
