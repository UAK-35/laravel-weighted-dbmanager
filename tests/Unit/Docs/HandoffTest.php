<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Uak35\WeightedDbManager\Tests\Support\Handoff;
use Uak35\WeightedDbManager\Tests\Support\NumberWords;
use Uak35\WeightedDbManager\Tests\Support\SkillDescriptions;

/**
 * HANDOFF.md against the tree it describes.
 *
 * WHY THIS EXISTS
 * ---------------
 *   The note is the only record here that describes a moment rather than a rule, and it was the
 *   only one nothing read: everything else in this package is compared with the code by a test,
 *   and a snapshot that nobody checks is one that rots silently and is trusted anyway — which is
 *   worse than one that is missing, because the reader has no reason to doubt it. It had in fact
 *   already rotted in two places, both invisible in a diff: the command in its opening block did
 *   not run (bare `--dry-run` is refused — it needs a bump), and the paths it said the dist
 *   tarball holds back were wrong (`bin/*.php` is not a pattern `.gitattributes` writes).
 *
 * THE REGISTER, WHICH IS THE WHOLE DESIGN
 * ---------------------------------------
 *   A note cannot name its own `HEAD` — the commit that lands the note moves it — so holding every
 *   number against the checkout would make the file uncommittable rather than checked, and a guard
 *   that fails the first time anybody commits is a guard that gets deleted. The register is
 *   therefore the commit section 2 names as `HEAD`: what the note says about a *state* is asked of
 *   git about *that* commit, and stays true after the branch moves on.
 *
 *   What is left is what the note says about the tree *now*, and that is what these tests hold:
 *   the paths it names, the commits and tags it names, the one command name it carries, the files
 *   it describes, the version lane it claims is still open, the derivation it names for the size of
 *   the pending set — the one claim in it no number could be held to — and, through the gitignored
 *   `.agents/machine.local.json`, the environment table of section 5. That record belongs to the
 *   machine rather than to the package, so a checkout that has none (a fresh clone, CI's runner)
 *   skips those comparisons instead of running them against nothing.
 *
 *   A push does not fail them; a rename, a lost commit, a superseded tag or a regenerated
 *   inventory does.
 */
final class HandoffTest extends TestCase
{
    /**
     * What the package's own reader makes of a changelog: `unreleasedSection()` finds the section
     * and `changelogSignal()` weighs it, exactly as `bin/release.php` does. Run in a child process
     * because `bin/weighing.php` includes `bin/surface.php` with a bare `require`, and loading it
     * into the test runner is how two files that declare the same functions collide.
     */
    private const SIGNAL = <<<'PHP'
        require $argv[1];

        $section = unreleasedSection(file_get_contents($argv[2]));

        if ($section === null) {
            fwrite(STDERR, 'The changelog has no ## Unreleased section.');

            exit(1);
        }

        $signal = changelogSignal($section['body']);

        echo json_encode(
            ['severity' => $signal['severity'], 'evidence' => $signal['evidence']],
            JSON_THROW_ON_ERROR,
        );
        PHP;

    public function test_it_says_when_it_was_taken(): void
    {
        $taken = Handoff::taken();

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $taken);

        self::assertSame(
            $taken,
            gmdate('Y-m-d', (int) strtotime($taken)),
            sprintf('HANDOFF.md says it was taken on [%s], which is not a date.', $taken),
        );
    }

    public function test_every_path_it_names_is_one_this_repository_has(): void
    {
        $paths = Handoff::paths();

        self::assertGreaterThanOrEqual(
            20,
            count($paths),
            sprintf(
                'HANDOFF.md names %d paths in this package, which is fewer than a note that walks a reader '.
                'through the tree can explain: the reader that finds them may be reading less than it used to.',
                count($paths),
            ),
        );

        $missing = [];

        foreach ($paths as $claim) {
            if (file_exists(Handoff::root().'/'.$claim['path'])) {
                continue;
            }

            // A path git ignores is not one a checkout is meant to have, and the note says so
            // about the machine record itself: "gitignored, so a clone without the file skips
            // that comparison". An ignored path is therefore absent by design rather than
            // stale, and the exclude rules — not the filesystem — are what let this answer for
            // a fresh clone, which is the checkout this guard has to hold on CI.
            if (Handoff::git('check-ignore', '-q', '--', $claim['path'])['exit'] === 0) {
                continue;
            }

            $missing[] = sprintf('%s (line %d)', $claim['path'], $claim['source']);
        }

        self::assertSame(
            [],
            $missing,
            "HANDOFF.md names paths this package does not have:\n  - ".implode("\n  - ", $missing),
        );
    }

    public function test_the_files_it_calls_pending_are_the_ones_the_tree_it_describes_does_not_carry(): void
    {
        $head = Handoff::basis()['head']['sha'];

        foreach (Handoff::pending() as $file) {
            self::assertFileExists(
                Handoff::root().'/'.$file['path'],
                sprintf('HANDOFF.md line %d calls %s pending, and this package has no such file.', $file['source'], $file['path']),
            );
        }

        $committed = [];

        foreach (Handoff::pending() as $file) {
            if (Handoff::git('cat-file', '-e', $head.':'.$file['path'])['exit'] === 0) {
                $committed[] = $file['path'];
            }
        }

        self::assertSame(
            [],
            $committed,
            sprintf(
                "HANDOFF.md section 1 calls these files pending, and the commit it describes (%s) already carries them —\n".
                "so the state the note describes has been committed, and section 1 has to say what is pending now:\n  - %s",
                $head,
                implode("\n  - ", $committed),
            ),
        );
    }

    /**
     * `--recorded` renders at the register the note already names, and changes nothing when the note
     * is there already.
     *
     * WHY THIS MODE EXISTS AND WHY IT IS HELD
     * ---------------------------------------
     *   `bin/release.php` has to refresh the note's blocks after it restamps the three `.tsv` records —
     *   section 1 quotes them — but it must not *take* the note: a take records the commit the checkout
     *   is standing on, so the release commit would be describing itself as the register and section 3's
     *   table would be read against a changelog that no longer has an `## Unreleased` in it. `--recorded`
     *   is the render half on its own, and this holds it to that: in a checkout where every block is in
     *   step it writes nothing at all, so a release that runs it cannot move a row a re-take would have
     *   moved.
     */
    public function test_recorded_writes_nothing_where_a_take_would_move_the_register(): void
    {
        $path = Handoff::root().'/HANDOFF.md';
        $before = (string) file_get_contents($path);
        $register = Handoff::basis()['head']['sha'];

        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/handoff.php', '--recorded']);

        self::assertSame(0, $run['exit'], sprintf(
            "bin/handoff.php --recorded reports the note out of step with the register it names:\n%s%s",
            $run['output'],
            $run['error'],
        ));
        self::assertStringContainsString(
            'nothing was written',
            $run['output'],
            'A recorded render wrote the note in a checkout where every block is already in step.',
        );
        self::assertSame($before, (string) file_get_contents($path), 'A recorded render changed the note, and it moves no row: a release runs it before its commit.');
        self::assertSame($register, Handoff::basis()['head']['sha'], 'A recorded render moved the register row, which is the one thing a take does.');
    }

    /**
     * `--block=gate` renders the one region it names, at the snapshot the note records, and a name
     * that is not a block is refused rather than rendered as nothing.
     *
     * WHY A RENDER HAS A NARROWER FORM THAN A TAKE
     * --------------------------------------------
     *   The gate block has a second writer: `bin/checks.php --record` writes the record and renders the
     *   block from it, because a note taken before a ten-minute run and the record written after it leave
     *   the note quoting the run before last. What that command may move is one region — the record is the
     *   only input its run changes — so the renderer has to be able to write one block without becoming a
     *   take: rendering all five would move the pending set and the listing to whatever the tree is when
     *   the gate finishes, and refuse on a pending file whose description nobody has written yet.
     *
     *   In a checkout where every block is in step nothing is written here, which is the shape a partial
     *   render can be held to without editing the note: it reports one block, names no other, and leaves
     *   the bytes on disk alone. That it writes the block it names follows from the rendering being the
     *   same `Handoff::rendered()` a take writes through — the guard on the note itself is that comparison.
     */
    public function test_a_partial_render_names_one_block_and_leaves_the_others_alone(): void
    {
        $path = Handoff::root().'/HANDOFF.md';
        $before = (string) file_get_contents($path);

        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/handoff.php', '--block=gate']);

        self::assertSame(0, $run['exit'], sprintf(
            "bin/handoff.php --block=gate reports the gate block out of step with the record it reads:\n%s%s",
            $run['output'],
            $run['error'],
        ));
        self::assertStringContainsString(
            '1 block(s)',
            $run['output'],
            'A partial render reports every block it rendered, and this one was asked for a single block.',
        );

        foreach (array_diff(Handoff::blocks(), ['gate']) as $other) {
            self::assertStringNotContainsString(
                $other,
                $run['output'],
                sprintf(
                    '`--block=gate` rendered [%s] as well, so the region it was asked for is not the region it moved.',
                    $other,
                ),
            );
        }

        self::assertSame(
            $before,
            (string) file_get_contents($path),
            'A partial render changed the note in a checkout where every block is already in step.',
        );

        $refused = Handoff::run([PHP_BINARY, Handoff::root().'/bin/handoff.php', '--block=gatep']);

        self::assertSame(2, $refused['exit'], sprintf(
            "bin/handoff.php --block=gatep exits %d, and a name that is not a block is a usage error rather "
            ."than a render that found nothing to write:\n%s%s",
            $refused['exit'],
            $refused['output'],
            $refused['error'],
        ));
        self::assertStringContainsString('Unknown block(s): gatep', $refused['error'], 'A refused block name has to name the name it refused.');
    }

    /**
     * The one command that records a run also renders the block quoting it, so the two cannot be taken
     * apart in either order.
     *
     * WHY THIS IS READ OFF THE SOURCE
     * -------------------------------
     *   The claim is a pairing, and the pair cannot be run here: `--record` is the whole gate, which is
     *   ten minutes of the suite that would be doing the asserting, so its `--record` path is the one part
     *   of `bin/checks.php` no test can execute. What can be held is that the path still has both halves —
     *   the record is written, and the note is re-rendered from it — and that the render is the narrow one
     *   the previous test covers rather than a take. Remove either and the drift is silent again: the note
     *   keeps quoting the run before last, `bin/handoff.php --check` goes red, and the repair is a session
     *   re-running the renderer instead of the command it had just run.
     *
     *   The limitation is the one `test_the_gate_writes_the_record_the_note_reads` writes down: a `bin/`
     *   script does not read a test support class, so the two spellings cannot be brought together here —
     *   only the fact that this one names what the other one answers.
     */
    public function test_recording_a_run_also_renders_the_note_that_quotes_it(): void
    {
        $source = @file_get_contents(Handoff::root().'/bin/checks.php');

        self::assertIsString($source, 'bin/checks.php could not be read, so the half of `--record` that renders the note cannot be held.');

        self::assertStringContainsString(
            "'/bin/handoff.php'",
            $source,
            'bin/checks.php --record writes the record and no longer runs the note renderer, so the note can be left '
            .'quoting the run before last — which is the pair this command exists to keep together.',
        );
        self::assertStringContainsString(
            "'--block=gate'",
            $source,
            'The render `--record` runs is not the narrow one: a whole-note render here would be a take, moving the '
            .'pending set and the listing to a tree the run never read.',
        );
    }

    /**
     * The other direction, and the one that rots: every file this checkout has that the register
     * does not carry at all has to be named in section 1.
     *
     * WHY THIS EXISTS
     * ---------------
     *   The test above asks whether what section 1 names is pending — that a path is there, and
     *   that the commit it describes does not carry it. Nothing asked the converse, so a section
     *   that named one pending file and forgot the other three was green: the list was *sound* and
     *   not *complete*, which is the direction that harms a reader. A file nobody names is work the
     *   next thread cannot see, and it is not red anywhere else either — an untracked test file
     *   still runs, and an untracked source file is still analysed if a path glob reaches it.
     *
     *   The comparison is against the register, not against whatever is uncommitted now: the paths
     *   read are the ones a commit would *add* — untracked, plus anything staged as new — and those
     *   are absent from the register whatever the branch has done since.
     *
     *   On a checkout with nothing pending this reads nothing and passes, which is the state a
     *   clone and CI's runner are in. That is the shape of the claim rather than a hole in it:
     *   section 1 is a snapshot of one machine's working tree, so a clean checkout has nothing to
     *   disagree with — and nothing for this to excuse either, because the machine that wrote the
     *   note is the machine it fails on.
     */
    public function test_every_file_the_register_does_not_carry_is_named_in_section_1(): void
    {
        $uncarried = Handoff::uncarried();
        $listed = array_column(Handoff::pending(), 'path');
        $missing = array_values(array_diff($uncarried, $listed));

        self::assertSame([], $missing, sprintf(
            "This checkout holds %d file(s) that the commit section 2 names as `HEAD` does not carry, and section 1\n".
            "names %d of them. A file nothing names is work the next thread cannot see:\n  - %s\n\n".
            'Either name it in the table, or take it out of the tree — a file that is not work is one .gitignore should carry.',
            count($uncarried),
            count($listed),
            implode("\n  - ", $missing),
        ));
    }

    /**
     * The prose column's other half: a row whose words were read against other bytes is refused.
     *
     * WHY THIS REFUSES RATHER THAN WARNS
     * ----------------------------------
     *   A pending file is work in progress, and the sentence beside it in section 1 is the one part of
     *   the table no derivation reaches. So the declared line carries the reading of the file its words
     *   were written against, and a file that has changed since makes the row unrenderable: the note
     *   either prints a description somebody read, or it refuses to print one. A warning would leave the
     *   stale sentence in the note a reader trusts, which is the state this exists to make impossible.
     *
     *   The sentence has to name the repair as well as the fault, because the fault is not one a
     *   session can argue with: the digest moved, and the only answer is to read the file again.
     */
    public function test_a_pending_row_whose_file_changed_after_its_words_were_read_is_refused(): void
    {
        // A declared row rather than one the note happens to list: the declaration outlives the working
        // tree, so this reads the same path in a checkout with nothing pending as in this one.
        $declared = Handoff::declared();

        self::assertNotSame([], $declared, 'the prose column declares no line at all');

        $path = $declared[0];

        // The reading a changed file would have: anything but the one the line declares.
        $other = 'sha256:'.str_repeat('0', 64);

        try {
            Handoff::words($path, $other);

            self::fail('A row whose file no longer matches its declared reading was rendered.');
        } catch (RuntimeException $refused) {
            self::assertStringContainsString(
                'The words HANDOFF.md gives for '.$path.' were read against other bytes',
                $refused->getMessage(),
                'The refusal has to say which row it is and why, or a reader is left to diff two digests.',
            );
            self::assertStringContainsString($other, $refused->getMessage(), 'The reading the file has now is the half a session acts on.');
            self::assertStringContainsString(
                '--reaffirm='.$path,
                $refused->getMessage(),
                'The repair is a command, so the refusal names it rather than leaving one to be invented.',
            );
        }
    }

    /**
     * The same claim in the direction the suite runs in: every row the note prints is read against the
     * file in the tree.
     *
     * On a checkout whose pending files have not moved since the readings were taken this reads
     * everything and passes, which is the state a clone is in — and it is the state that has to keep
     * holding, because a declared line is a sentence about a file a reader will open next.
     */
    public function test_every_pending_row_is_read_against_the_file_it_names(): void
    {
        $stale = [];

        foreach (Handoff::pending() as $row) {
            try {
                Handoff::words($row['path']);
            } catch (RuntimeException $refused) {
                $stale[] = $refused->getMessage();
            }
        }

        self::assertSame(
            [],
            $stale,
            sprintf(
                "%d row(s) of section 1 describe a file that has changed since their words were read:\n\n%s",
                count($stale),
                implode("\n\n", $stale),
            ),
        );
    }

    /**
     * The repair, held the way the other writer is: in a checkout where every reading is current it
     * writes nothing at all.
     *
     * A command that rewrote the declaration block on every run would be blessing whatever the files had
     * become — the hashes would always agree, and the refusal would never fire. So the recorded half is
     * held to the state it records: nothing to re-read means no bytes written.
     */
    public function test_reaffirm_writes_nothing_where_every_reading_is_current(): void
    {
        $file = Handoff::root().'/'.Handoff::DECLARATIONS_FILE;
        $before = (string) file_get_contents($file);

        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/handoff.php', '--reaffirm']);

        self::assertSame(0, $run['exit'], sprintf(
            "bin/handoff.php --reaffirm reports a reading out of step with the file it describes:\n%s%s",
            $run['output'],
            $run['error'],
        ));
        self::assertStringContainsString(
            'nothing was written',
            $run['output'],
            'A re-reading wrote the declaration block in a checkout where every reading is already current.',
        );
        self::assertSame($before, (string) file_get_contents($file), 'A re-reading changed the block, and it changes nothing where the files have not moved.');
    }

    /**
     * A reading is the second half of a description, so a path nothing describes has nothing to
     * re-read: a typo in the command has to be red rather than recorded as a reading of nothing.
     */
    public function test_a_path_no_line_describes_cannot_be_reaffirmed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('There is no line for no/such/file.php in Handoff::PENDING_DESCRIPTIONS');

        Handoff::pendingReadings(['no/such/file.php']);
    }

    /**
     * The declaration block is the reader's own output, held to the file byte for byte.
     *
     * The block is written by a command and read by this class, and the two have to be the same shape:
     * a hand-edited entry — a digest pasted in, a words string that drifted — is what the round trip
     * through `pendingReadings()` makes red, so the only way to change a reading is the command that
     * takes one.
     */
    public function test_the_declared_block_is_what_the_reader_renders(): void
    {
        $source = (string) file_get_contents(Handoff::root().'/'.Handoff::DECLARATIONS_FILE);

        $found = preg_match(
            '/'.preg_quote(Handoff::DECLARATIONS, '/')."[^\n]*\n(.*)\n\s*".preg_quote(Handoff::DECLARATIONS_END, '/').'/s',
            $source,
            $matches,
        );

        self::assertSame(1, $found, 'The markers the declaration block is written between are gone.');
        self::assertSame(
            Handoff::pendingReadings()['block'],
            $matches[1],
            'A declared reading is not what the command writes, so it was edited by hand rather than taken.',
        );
    }

    /**
     * The five blocks the note renders rather than writes: section 1's gate summary, its records
     * transcript and its table, and section 2's table and listing — each compared with what its
     * derivation says at the register the note names: git for three of them, the inventory check
     * itself for how far its records have fallen behind that tree, and the recorded run for the gate.
     *
     * WHY THE NOTE DOES NOT WRITE THEM ITSELF
     * ---------------------------------------
     *   Each of those blocks is a set of files, a set of commits, or a recorded run, and a number over
     *   it — how many paths the register does not carry, how many commits no remote has, how they break
     *   down, which commits the listing shows, how long each check took and what it reported. The first
     *   four are questions git answers and the fifth is a question the last gate run answered, and
     *   copying either answer in by hand is how a note states a number the tree does not have: the count
     *   of uncommitted paths is a fact about one working tree, and the register row moves the moment
     *   anything is committed.
     *
     *   So the blocks are derived — `bin/handoff.php` writes them from the same `rendered()` this
     *   reads — and this holds the file to what that derivation says. The reading is taken at the
     *   register the note itself names rather than at `HEAD`, which is the note's design: it
     *   describes a commit older than the one that lands it, so rendering at `HEAD` would report a
     *   fresh note stale the moment it was committed. A block out of step here is repaired by running
     *   the renderer, not by editing the number.
     */
    public function test_the_generated_blocks_are_what_the_renderer_writes(): void
    {
        foreach (Handoff::blocks() as $name) {
            self::assertSame(
                Handoff::rendered($name),
                Handoff::block($name),
                sprintf(
                    "HANDOFF.md's `%s` block is not what its derivation says it is. The block is an output rather "
                    ."than a claim:\nrun `php bin/handoff.php` and it follows the tree and the last recorded run, so "
                    .'neither a set of files nor a count in it can be stated by whoever re-takes the note.',
                    $name,
                ),
            );
        }
    }

    /**
     * The renderer itself, driven the way a re-take drives it.
     *
     * The comparison above holds the blocks to the derivation; this holds the command a session runs
     * to the same answer, so the two cannot disagree about whether the note is current — a renderer
     * that wrote one thing while the guard read another would make every re-take a coin toss.
     */
    public function test_the_renderer_finds_the_note_in_step(): void
    {
        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/handoff.php', '--check']);

        self::assertSame(0, $run['exit'], sprintf(
            "bin/handoff.php --check reports the note out of step with the register it names:\n%s%s",
            $run['output'],
            $run['error'],
        ));

        self::assertStringContainsString('all in step with their derivations', $run['output']);
    }

    /**
     * Where the size of the pending set comes from, now that the note does not state it.
     *
     * WHY THIS SHAPE AND NOT A COMPARISON OF NUMBERS
     * ---------------------------------------------
     *   This test used to read three counts out of section 1 and compare them with `git status`, and
     *   it was red on every checkout but the one that wrote the note: a count of uncommitted paths is
     *   a fact about one working tree, so any file added, deleted or reverted afterwards left the
     *   sentence disagreeing with the tree, and the repair was a person re-reading git and editing
     *   three words. A number no derivation can hold is not a claim this package binds, so the
     *   sentence names the derivation instead — the command the note's own opening block tells a
     *   reader to run — and this holds it to the two things a committed file *can* be asked for: that
     *   it names that command, and that it does not state a count again.
     *
     *   The count check is a *shape* check rather than a pattern, because the way the number would
     *   come back is as a word — `Twelve paths differ`, `nine tracked paths changed`, `three new
     *   files` — or as a numeral, and neither is reliably readable through one table. `NumberWords`
     *   spells the counts a record states about a code fact and stops at thirty, deliberately; the
     *   pending set is a fact about one working tree and has no ceiling, so a refusal built on that
     *   table would be walked around by `thirty-five files` — a count the vocabulary cannot spell —
     *   and by `35 files`, which it never spells at all. `NumberWords::looksLikeNumber()` reads the
     *   shape instead, and the vocabulary's ceiling stays a writing rule rather than the refusal's
     *   reach.
     *
     *   It reads the words in front of every noun the guard counts, because that is where the note
     *   put its three counts, and it reads three of them rather than one because the note's own
     *   phrasing puts an adjective between the number and the noun: a check that saw only `tracked`
     *   would report agreement with a count it had never read. Which nouns those are is
     *   `Handoff::countsNoun()`'s — the pair table the count readings are built from — so the noun
     *   this catches is the noun a reading counts, in either of its forms, rather than a list kept
     *   here beside it. Case is the record's (`NumberWords` folds it), and a number word nowhere near
     *   those nouns — `one working tree` — is prose rather than a count.
     */
    public function test_section_1_names_the_derivation_for_the_pending_set_rather_than_a_count(): void
    {
        $sentence = Handoff::pendingSize();

        self::assertStringContainsString(
            '`git status --short`',
            $sentence['text'],
            sprintf(
                "HANDOFF.md line %d no longer names the command the size of the pending set is read from.\n".
                'A count of uncommitted paths cannot be held in a committed file, so the sentence has to name the '.
                'derivation a reader runs instead.',
                $sentence['source'],
            ),
        );

        // Digits are kept as words of their own: a numeral is a count too, and the split that
        // dropped them would leave `35 files` with no number in it at all.
        $words = preg_split('/[^A-Za-z0-9-]+/', $sentence['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counted = [];

        foreach ($words as $index => $word) {
            if (! Handoff::countsNoun($word)) {
                continue;
            }

            foreach (array_slice($words, max(0, $index - 3), min(3, $index)) as $before) {
                if (NumberWords::looksLikeNumber($before)) {
                    $counted[] = $before.' '.$word;
                }
            }
        }

        self::assertSame(
            [],
            $counted,
            sprintf(
                "HANDOFF.md line %d states the pending set as a count again (%s).\n".
                'A count of uncommitted paths is a fact about one working tree — red on every checkout but the one '.
                'that wrote the note — so the sentence names the derivation (`git status --short`) and leaves the '.
                'number to the command. A large set is not an exception: the table is how it is stated, at any size.',
                $sentence['source'],
                implode(', ', $counted),
            ),
        );
    }

    /**
     * The refusal reaches a count past the vocabulary that spells the records' numbers.
     *
     * WHY THIS IS A TEST RATHER THAN A COMMENT
     * ---------------------------------------
     *   `NumberWords` stops at thirty because a count that size is a generated document rather than a
     *   sentence, and every number it renders is a count of a code fact — bounded by a list. The
     *   pending set is the one count with no bound at all: a working tree can hold any number of
     *   files. A refusal that asked `NumberWords::knows()` would therefore be blind exactly where a
     *   large batch invites a count — `thirty-five files`, which the table cannot spell, and `35
     *   files`, which it never spells — while catching the small ones the note is least likely to
     *   state. The shape reading is what makes the refusal's reach the tree's rather than the
     *   vocabulary's, and this holds it to both ends.
     */
    public function test_the_pending_set_refusal_reads_a_count_the_vocabulary_cannot_spell(): void
    {
        // The two shapes a large batch is most likely to be written in, and neither is in the table.
        self::assertFalse(NumberWords::knows('thirty-five'), 'the vocabulary stops at thirty, on purpose');
        self::assertFalse(NumberWords::knows('35'), 'records write words, so a numeral was never in it');

        foreach (['thirty-five', '35', '1,024', 'ninety', 'hundred', 'nine', 'zero', 'Twenty-five'] as $count) {
            self::assertTrue(
                NumberWords::looksLikeNumber($count),
                sprintf('[%s] is a count, and the pending-set refusal has to see it at any size.', $count),
            );
        }

        foreach (['several', 'few', 'most', 'working', 'tracked', 'paths', 'well-known'] as $prose) {
            self::assertFalse(
                NumberWords::looksLikeNumber($prose),
                sprintf('[%s] is prose or a noun rather than a count.', $prose),
            );
        }
    }

    /**
     * Every count reading in this guard, held to the singular and the plural it has to accept.
     *
     * WHY THIS IS ITS OWN TEST
     * ------------------------
     *   A count reading is a pattern around a noun, and the noun has two forms: the singular a note
     *   writes when it holds exactly one of the thing, and the plural it writes for every other count.
     *   A pattern spelling only the plural is green against every note this package has today and blind
     *   on the note that holds one — the one a reader would most want read — so the failure is invisible
     *   in a diff of the record and lands on whoever writes the first such note. That is not a claim a
     *   note makes but the shape of the reading that reads it, which is why it is held here: the forms
     *   are declared beside the readings (`Handoff::countedNouns()`), the readings build their patterns
     *   from that declaration, and each pair is held to both of its forms.
     *
     *   The other half is the reading that does not exist yet. A pattern that spells its own noun passes
     *   every assertion above it, because the pair held here is not the pair it reads, so the guard's own
     *   source is read for the two shapes a hand-written noun takes: a form written into a pattern —
     *   `'/^paths$/'`, which weighs a count of many and is blind to a count of one — and a pair written out
     *   by hand, `path|paths` or the `entr(?:y|ies)` the Unreleased totals used to carry, which is a second
     *   spelling of a pair the table already decides. That is what makes the next reading unable to ship
     *   without the singular rather than merely unlikely to.
     *
     *   A reading that counts without a noun is held through the vocabulary it takes its number from:
     *   `NumberWords` is what spells the word a word reading matches, so the word for one has to be in
     *   it, while a reading that matches digits (`(\d+)`) reads a count of one by construction.
     */
    public function test_every_count_reading_accepts_the_singular_and_the_plural_it_counts(): void
    {
        $counted = Handoff::countedNouns();

        self::assertNotSame(
            [],
            $counted,
            'The guard declares no counted noun at all, so every count its readings take is one nothing holds.',
        );

        foreach ($counted as $noun => $pair) {
            self::assertNotSame(
                $pair['singular'],
                $pair['plural'],
                sprintf(
                    'The guard counts [%s] in one form, so a reading of it cannot tell a count of one from a count of many.',
                    $noun,
                ),
            );

            $pattern = '/^'.Handoff::counted($noun).'$/';

            foreach ([$pair['singular'], $pair['plural']] as $form) {
                self::assertSame(
                    1,
                    preg_match($pattern, $form),
                    sprintf(
                        'The pattern the readings build for [%s] does not read [%s], which is the %s form of a count of it: a '.
                        'reading built from this pair cannot be read through by the note that holds that count.',
                        $noun,
                        $form,
                        $form === $pair['singular'] ? 'singular' : 'plural',
                    ),
                );
            }
        }

        self::assertTrue(
            NumberWords::knows('one'),
            'The readings that take their number from words cannot read a count of one: `NumberWords` no longer spells [one], '.
            'and a word it does not know is a count no word reading can take.',
        );

        $spelled = self::nounsSpelledByHand(self::guardSources());

        self::assertSame(
            [],
            $spelled,
            sprintf(
                "A count reading in the guard spells its own noun rather than building it from the pair:\n  - %s\n\n".
                'A pattern built from `Handoff::counted()` reads both forms because the pair it is built from has both; one '.
                "written out beside the table reads the form it names and no other, which is a reading able to weigh a count\n".
                'of many and blind to a count of one — the note a reader would most want read. Declare the noun in '.
                '`Handoff::COUNTED`, the singular and the plural, and build the pattern from the pair.',
                implode("\n  - ", $spelled),
            ),
        );
    }

    /**
     * The scan, held to the two directions it can be useless in.
     *
     * WHY THIS IS A TEST AND NOT A PARAGRAPH
     * -------------------------------------
     *   The hold above is only as good as the scan behind it, and a scan fails in two directions: it can
     *   report nothing, which leaves a reading that spells only the plural green — the failure the whole
     *   hold exists for — or it can report everything, which fails an innocent reading until somebody
     *   deletes the hold rather than fixing it. Both directions are measured here, on lines the test
     *   composes rather than finds, because the teeth were measured by hand into section 5 of the note
     *   and a control nobody re-measures is one that rots with the reading that wrote it.
     *
     *   Each line is built from the declaration's own forms through variables, because the guard's own
     *   source is what the live scan reads: a form written into *this* file is a form this file would be
     *   refused for. That is not a way around the scan — a noun inside a variable is exactly what it
     *   leaves alone, which is one of the three lines below that has to come back empty.
     */
    public function test_the_scan_refuses_every_shape_a_reading_can_ship_with(): void
    {
        $counted = Handoff::countedNouns();
        $entry = $counted['entry'];
        $path = $counted['path'];
        $box = 'box';

        $cases = [
            // A note cannot be read through these: a form on its own, and both shapes of a pair.
            'a plural spelled into a pattern of its own' => [
                sprintf("if (preg_match('/^%s$/i', %s) !== 1) {", $path['plural'], '$word'),
                "Handoff::counted('path')",
            ],
            'a pair written out by hand beside the table' => [
                sprintf("if (preg_match('/^%s$/', %s) === 1) {", $entry['singular'].'|'.$entry['plural'], '$span'),
                $entry['singular'].'|'.$entry['plural'],
            ],
            'a pair for a noun the table does not declare' => [
                sprintf("preg_match('/^(%s)$/', %s)", $box.'|'.$box.'es', '$name'),
                $box.'|'.$box.'es',
            ],
            // ... and these are readings: the door, a key, and a variable.
            'a pattern the pair was built around' => [
                sprintf("if (preg_match('/^(\d+) %s$/', %s) !== 1) {", "self::counted('entry')", '$row'),
                null,
            ],
            'a form that runs into the key it names' => [
                sprintf("if (preg_match('/^\s*%s:\s*$/', %s) === 1) {", $path['plural'], '$line'),
                null,
            ],
            'a form inside a variable' => [
                sprintf("if (preg_match('/^src/', %s) === 1) {", '$file'),
                null,
            ],
        ];

        foreach ($cases as $shape => [$line, $names]) {
            $findings = self::findingsIn($line);

            if ($names === null) {
                self::assertSame([], $findings, sprintf(
                    "The scan refuses a line it has no business refusing — %s:\n  %s\n\n".
                    'A scan that reports everything fails an innocent reading, and a hold that does that is deleted rather '.
                    'than fixed.',
                    $shape,
                    $line,
                ));

                continue;
            }

            self::assertNotSame([], $findings, sprintf(
                "The scan read nothing in a line no note could be read through — %s:\n  %s\n\n".
                'This is the direction the whole hold exists for: a reading that spells the form it names and no other.',
                $shape,
                $line,
            ));

            self::assertStringContainsString($names, implode(' ', $findings), sprintf(
                "The scan refused %s without saying what to do about it:\n  %s",
                $shape,
                implode("\n  ", $findings),
            ));
        }
    }

    /**
     * What the scan makes of one line of source, read as if it were the reader's — the controls above are
     * lines rather than files, and a scan is only as good as what it says about the shape it is handed.
     *
     * @return list<string>
     */
    private static function findingsIn(string $line): array
    {
        return self::nounsSpelledByHand(['tests/Support/Handoff.php' => $line."\n"]);
    }

    /**
     * Every place the guard's own source spells a noun a count reading matches, as `file:line  finding`.
     *
     * THE TWO SHAPES THAT ARE FINDINGS
     * -------------------------------
     *   A declared form written into a pattern — `'/^paths$/'` — is a reading that weighs a count of
     *   many and is blind to a count of one, and the doors the readings are meant to go through
     *   (`counted('path')`) are cut out of the line before it is searched for, so a pattern *built* from
     *   the pair is not read as one that spells it. A pair written out by hand — `path|paths`, or the
     *   `entr(?:y|ies)` the Unreleased totals used to carry — reads both forms and is refused all the
     *   same: it is a second spelling of a pair the table already decides, and when the noun is one
     *   nobody declared it is a reading of a count the guard holds nowhere at all.
     *
     *   The pair shapes are the three ways English writes a plural for a noun a pattern could count —
     *   `s`, as in `path|paths`; `es`, as in `batch|batches`; and the `y` a plural turns into `ies` — so
     *   an alternation that is not a noun pair (`true|false`) is left to the reading it belongs to. Prose
     *   is not read: a docblock saying which forms a noun has is the decision written down, while only a
     *   pattern is what a note is read through, so comment lines are stepped over and a line's own
     *   trailing comment is cut before anything is looked for.
     *
     * The source is handed in rather than read here, so that the same scan can be asked about a line the
     * controls above compose: what it reads in the guard's files and what it reads in a shape somebody
     * handed it are the same reading, which is the only way the controls hold the live scan.
     *
     * @param array<string, string> $sources
     *
     * @return list<string>
     */
    private static function nounsSpelledByHand(array $sources): array
    {
        $found = [];

        foreach ($sources as $file => $source) {
            foreach (explode("\n", $source) as $index => $line) {
                $trimmed = ltrim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '//')) {
                    continue;
                }

                $comment = strpos($line, '//');
                $code = $comment === false ? $line : substr($line, 0, $comment);

                foreach (array_merge(self::nounsInPatterns($code), self::nounPairs($code)) as $finding) {
                    $found[] = sprintf('%s:%d  %s', $file, $index + 1, $finding);
                }
            }
        }

        return $found;
    }

    /**
     * The guard's own source, keyed by the path a finding names it with: the reader that takes the
     * note's claims, and every file of the note's own suite named after it.
     *
     * The suite is read as a directory rather than as a list written down here, so the next test of the
     * note is scanned without anybody adding it to this list, and the files are read from disk rather
     * than held as text, so what is scanned is what a commit would carry. A file that is not there is a
     * failure rather than an empty scan: one that read nothing and found nothing would report agreement
     * with a reading it never read.
     *
     * @return array<string, string>
     */
    private static function guardSources(): array
    {
        $suite = array_map(
            static fn (string $path): string => 'tests/Unit/Docs/'.basename($path),
            glob(Handoff::root().'/tests/Unit/Docs/Handoff*.php') ?: [],
        );

        self::assertNotSame(
            [],
            $suite,
            'No file of the note\'s own suite is named after the reader, so the half of this guard that reads the guard\'s '.
            'source is scanning nothing.',
        );

        $sources = [];

        foreach (array_merge(['tests/Support/Handoff.php'], $suite) as $path) {
            $source = @file_get_contents(Handoff::root().'/'.$path);

            self::assertIsString(
                $source,
                sprintf('%s is not there to be read for a count reading spelled by hand — move this list with the file.', $path),
            );

            $sources[$path] = $source;
        }

        return $sources;
    }

    /**
     * The noun-and-plural alternations in one line of code, as the line writes them — both arms, in
     * the order they appear, for each pair whose second arm is the plural of its first.
     *
     * @return list<string>
     */
    private static function nounPairs(string $code): array
    {
        if (preg_match_all('/([a-z]+)\|([a-z]+)/', $code, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $pairs = [];

        foreach ($matches as $match) {
            if (self::isPluralOf($match[1], $match[2])) {
                $pairs[] = $match[1].'|'.$match[2];
            }
        }

        return $pairs;
    }

    /**
     * The declared forms a line spells into a pattern of its own, each as the instruction it should have
     * followed instead.
     *
     * WHAT MAKES A FORM A FINDING HERE
     * -------------------------------
     *   Only a line that matches text is read at all, and only a regex — a reading of the note is a
     *   `preg_match` or a `preg_match_all`, while a `str_contains` asks whether a word is there rather
     *   than weighing a count of it. Two kinds of word come out of the line before it is searched: the
     *   doors (`counted('path')`, `countsNoun($word)`), because a form named *to* the door is the one way
     *   of writing it that reads both forms; and a variable, because a noun inside a name (`$paths`,
     *   `$file`) is not a pattern. What is left is a form standing alone in what the line matches with.
     *
     *   A form running straight into a colon or a dot is left alone as well: that is a noun naming a key
     *   or a path rather than counting the thing — `phpstan.neon.dist`'s `paths:` row is the one pattern
     *   in the guard that spells one so, and it holds no count. Anywhere else, a declared form in a
     *   pattern is a reading that reads the form it names and no other.
     *
     * @return list<string>
     */
    private static function nounsInPatterns(string $code): array
    {
        if (preg_match('/\b(?:preg_match|preg_match_all)\s*\(/', $code) !== 1) {
            return [];
        }

        $bare = preg_replace([
            '/\b(?:counted|countsNoun)\([^)]*\)/',
            '/\$[A-Za-z_][A-Za-z0-9_]*/',
        ], '', $code) ?? $code;

        $found = [];

        foreach (self::countedForms() as $form => $noun) {
            if (preg_match('/\b'.preg_quote($form, '/').'\b(?![:.])/', $bare) === 1) {
                $found[] = sprintf(
                    "the form [%s], spelled into the pattern rather than built from the pair (Handoff::counted('%s'))",
                    $form,
                    $noun,
                );
            }
        }

        return $found;
    }

    /**
     * Every form the guard declares a noun in, keyed by the form, so that a form found in a pattern is
     * reported with the noun it counts and with the door it should have gone through.
     *
     * @return array<string, string>
     */
    private static function countedForms(): array
    {
        $forms = [];

        foreach (Handoff::countedNouns() as $noun => $pair) {
            $forms[$pair['singular']] = $noun;
            $forms[$pair['plural']] = $noun;
        }

        return $forms;
    }

    /**
     * Whether the second of two arms is the plural of the first — the three shapes a hand-written pair
     * takes, so that an alternation which is not one (`true|false`, or a `[^}]*|[^/]+` inside a pattern
     * spanning more than a word) is left to the reading it belongs to.
     */
    private static function isPluralOf(string $singular, string $plural): bool
    {
        return $plural === $singular.'s'
            || $plural === $singular.'es'
            || (str_ends_with($singular, 'y') && $plural === substr($singular, 0, -1).'ies');
    }

    public function test_the_commits_it_describes_are_the_ones_it_names(): void
    {
        foreach (['HEAD' => Handoff::basis()['head'], 'origin/dev' => Handoff::basis()['origin']] as $label => $commit) {
            $run = Handoff::git('show', '-s', '--format=%s', $commit['sha'].'^{commit}');

            self::assertSame(
                0,
                $run['exit'],
                sprintf(
                    'HANDOFF.md names %s as %s, and this repository has no such commit — section 2 has to be '.
                    're-taken, because every number in it is read against that commit.',
                    $commit['sha'],
                    $label,
                ),
            );

            self::assertSame(
                $commit['subject'],
                trim($run['output']),
                sprintf(
                    'HANDOFF.md line %d gives %s the subject [%s], and the commit reads [%s].',
                    $commit['source'],
                    $commit['sha'],
                    $commit['subject'],
                    trim($run['output']),
                ),
            );
        }
    }

    public function test_the_unpushed_count_and_mix_are_the_commits_between_the_two_it_names(): void
    {
        $basis = Handoff::basis();
        $range = $basis['origin']['sha'].'..'.$basis['head']['sha'];

        $count = Handoff::git('rev-list', '--count', $range);
        $actual = (int) trim($count['output']);

        self::assertSame(0, $count['exit'], sprintf('The commits between the two the note names could not be counted: %s', $count['error']));

        self::assertSame(
            Handoff::unpushed(),
            $actual,
            sprintf(
                'HANDOFF.md section 2 says %d commits are unpushed; the commits between %s and %s are %d. A commit '.
                'or a push is what moved it, and the number is a claim about the pair the note names.',
                Handoff::unpushed(),
                $basis['origin']['sha'],
                $basis['head']['sha'],
                $actual,
            ),
        );

        $tally = [];

        foreach (Handoff::outputLines(Handoff::git('log', '--format=%s', $range)['output']) as $subject) {
            if (preg_match('/^([A-Za-z]+)(?:\([^)]*\))?!?:/', $subject, $matches) === 1) {
                $type = strtolower($matches[1]);
                $tally[$type] = ($tally[$type] ?? 0) + 1;
            }
        }

        $mix = Handoff::mix();
        ksort($tally);
        ksort($mix);

        self::assertSame(
            $mix,
            $tally,
            sprintf(
                "HANDOFF.md breaks the unpushed commits down as %s; the commits between the two it names are %s.",
                self::written($mix),
                self::written($tally),
            ),
        );
    }

    public function test_the_commits_it_lists_are_the_most_recent_ones_between_them(): void
    {
        $basis = Handoff::basis();
        $range = $basis['origin']['sha'].'..'.$basis['head']['sha'];
        $listing = Handoff::listing();

        self::assertCount(
            $listing['claimed'],
            $listing['commits'],
            sprintf(
                'HANDOFF.md says it lists the %d most recent unpushed commits and lists %d, so the sentence and '.
                'the listing disagree even before the tree is asked.',
                $listing['claimed'],
                count($listing['commits']),
            ),
        );

        $log = Handoff::outputLines(Handoff::git('log', '--format=%h%x1f%s', $range)['output']);

        self::assertGreaterThanOrEqual(
            count($listing['commits']),
            count($log),
            'HANDOFF.md lists more unpushed commits than the two it names have between them.',
        );

        foreach ($listing['commits'] as $position => $listed) {
            [$sha, $subject] = array_pad(explode("\x1f", $log[$position], 2), 2, '');

            self::assertSame(
                ['sha' => $listed['sha'], 'subject' => $listed['subject']],
                ['sha' => $sha, 'subject' => $subject],
                sprintf(
                    'HANDOFF.md line %d lists %s — %s at position %d of the unpushed commits, and the commit '.
                    'there is %s — %s.',
                    $listed['source'],
                    $listed['sha'],
                    $listed['subject'],
                    $position + 1,
                    $sha,
                    $subject,
                ),
            );
        }
    }

    /**
     * The tags the note's claims are read against, with the release's own pending tag in them.
     *
     * The one thing the guard is told rather than asked, and it is told by `bin/release.php` alone:
     * between the release's commit and its tag, the note names a tag that does not exist yet, and
     * without the allowance this claim and the ancestry one below would refuse every release there is.
     *
     * @return list<string>
     */
    private static function knownTags(): array
    {
        return Handoff::tagNames();
    }

    /**
     * The row says which tag is the latest *of the ones on the branch*, so the claim is read against
     * the tags this branch carries, not every tag in the repository.
     *
     * The two lists are not the same list, and the difference is a release cut on a branch this one
     * cannot reach: a real tag, and not a release this lane has taken. `RELEASING.md` builds a release
     * from "the most recent `v*` tag that is an ancestor of `HEAD`", `bin/surface.php` and
     * `bin/release.php` each read their base with it, and
     * `BumpWeighingTest::test_a_tag_this_branch_cannot_reach_is_not_the_base` pins that rule for the
     * base version — so a note whose latest row named the newest tag *anywhere* would be claiming a
     * release this history does not contain. The existence check above it still reads every tag: a
     * name the note writes has to resolve wherever it was cut.
     */
    public function test_the_tag_it_calls_latest_is_still_the_newest(): void
    {
        $known = self::knownTags();

        $unknown = array_values(array_diff(Handoff::tags(), $known));

        self::assertSame(
            [],
            $unknown,
            sprintf(
                'HANDOFF.md names these tags, and this repository has none of them: %s.',
                implode(', ', $unknown),
            ),
        );

        $newest = self::newest(Handoff::branchTagNames());

        self::assertSame(
            Handoff::latest(),
            $newest,
            sprintf(
                'HANDOFF.md says %s is the latest tag, and %s is the newest one this branch carries — so '
                .'the lane the note describes has been released past, and section 3 has to be re-taken '
                .'before the next tag is cut.',
                Handoff::latest(),
                $newest,
            ),
        );

        self::assertNotContains(
            Handoff::absent(),
            $known,
            sprintf(
                'HANDOFF.md says there is no %s, and this repository has that tag — which is the sentence '.
                'that lets the next version be a prerelease, so the note is now describing the wrong lane.',
                Handoff::absent(),
            ),
        );
    }

    /**
     * The two readings are two questions, and this test is the repository where the answers differ.
     *
     * `tagNames()` is the register a claim is checked against, so it is every `v*` tag the repository
     * has; `branchTagNames()` is what "the latest of them" is read from, so it is the tags `HEAD` can
     * reach. This package's own history is what put the guards in touch with the difference —
     * `v0.2.0-alpha8` was cut on a release branch `dev` has never merged — and that is exactly why the
     * difference is planted here instead: a guard whose two readings only differ on whatever history
     * happens to be lying around stops saying anything the day that branch is merged, and the next
     * release branch to be cut teaches the lesson again. The fixture is two tags, one of them behind a
     * branch `main` cannot reach, and what is asserted is what each list is for.
     *
     * The pending tag is asserted on both lists, because the release names it before it exists and both
     * readings have to take it: the note may name the tag it is about to cut, and a tag cut at `HEAD`
     * is the newest of this lane.
     */
    public function test_the_two_tag_lists_answer_two_different_questions(): void
    {
        $root = self::plantedTags();
        $previous = getenv('HANDOFF_PENDING_TAG');

        try {
            // A release this guard runs inside is already naming the tag it is about to cut, and a
            // pending tag is taken by `tagNames()` whatever repository is asked — which is the point of
            // the second half of this test. The fixture's own tags are the first half's question, so the
            // ambient name is cleared here and set again below, where it is a claim rather than noise.
            putenv('HANDOFF_PENDING_TAG');

            self::assertSame(
                ['v0.1.0', 'v0.2.0-alpha8'],
                Handoff::tagNames($root),
                'the register is every tag the repository has, and the fixture has one on a branch this one cannot reach',
            );

            self::assertSame(
                ['v0.1.0'],
                Handoff::branchTagNames($root),
                'the tags this branch carries are the ones `HEAD` can reach, and the fixture\'s second tag is behind a branch it cannot',
            );

            self::assertSame(
                'v0.2.0-alpha8',
                self::newest(Handoff::tagNames($root)),
                'read as "the newest tag anywhere", the newest is the release this branch does not contain',
            );

            self::assertSame(
                'v0.1.0',
                self::newest(Handoff::branchTagNames($root)),
                'read as "the tags on the branch", the newest is the newest release this lane has taken',
            );

            putenv('HANDOFF_PENDING_TAG=v0.2.0-alpha9');

            self::assertContains(
                'v0.2.0-alpha9',
                Handoff::tagNames($root),
                'a release names the tag it is about to cut, and the register has to take it before it exists',
            );

            self::assertSame(
                'v0.2.0-alpha9',
                self::newest(Handoff::branchTagNames($root)),
                'the tag a release is about to cut is made at `HEAD`, so it is the newest of the branch it is cut on',
            );
        } finally {
            if ($previous === false) {
                putenv('HANDOFF_PENDING_TAG');
            } else {
                putenv('HANDOFF_PENDING_TAG=' . $previous);
            }

            self::removeTree($root);
        }
    }

    /**
     * A repository with two tags — one on `main`, one on a release branch `main` cannot reach — and the
     * path it is in.
     *
     * Planted in the system temp directory, with a git config of its own (`GIT_CONFIG_GLOBAL` and
     * `GIT_CONFIG_NOSYSTEM`), for the reason `ReleaseRepo` gives: the developer's global config — a
     * signing key, an `init.defaultBranch`, a `core.hooksPath` pointing at this repository's hooks —
     * must not be able to decide what a guard sees. The tag on the side branch is the only thing this
     * fixture exists for; the commits are what a tag has to point at.
     */
    private static function plantedTags(): string
    {
        $root = sys_get_temp_dir() . '/swrr-tags-' . bin2hex(random_bytes(6));
        $config = $root . '.gitconfig';

        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            self::fail('the tag fixture could not be created at ' . $root);
        }

        file_put_contents($config, "[init]\n\tdefaultBranch = main\n[commit]\n\tgpgsign = false\n[user]\n\tname = Tag Fixture\n\temail = fixture@example.test\n");

        $git = static function (string ...$arguments) use ($root, $config): void {
            $process = new Process(['git', ...$arguments], $root, [
                'GIT_CONFIG_GLOBAL' => $config,
                'GIT_CONFIG_NOSYSTEM' => '1',
                'GIT_TERMINAL_PROMPT' => '0',
            ]);

            $process->setTimeout(60);

            if ($process->run() !== 0) {
                self::fail(sprintf(
                    'git %s failed in the tag fixture: %s',
                    implode(' ', $arguments),
                    $process->getErrorOutput(),
                ));
            }
        };

        $git('init', '--quiet');
        file_put_contents($root . '/README.md', "this lane\n");
        $git('add', 'README.md');
        $git('commit', '--quiet', '-m', 'the release this branch carries');
        $git('tag', 'v0.1.0');

        $git('checkout', '--quiet', '-b', 'release/v0.2.0-alpha8');
        file_put_contents($root . '/README.md', "the other lane\n");
        $git('commit', '--quiet', '-am', 'the release cut on a branch this one cannot reach');
        $git('tag', 'v0.2.0-alpha8');
        $git('checkout', '--quiet', 'main');

        return $root;
    }

    /** Remove a planted repository, config file and all; nothing a guard leaves behind belongs on disk. */
    private static function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                self::removeTree($path . '/' . $entry);
            }

            @rmdir($path);

            return;
        }

        @unlink($path);
    }

    public function test_each_tag_it_names_is_on_the_branch_it_describes(): void
    {
        $head = Handoff::basis()['head']['sha'];
        $pending = Handoff::pendingTag();
        $off = [];

        foreach (Handoff::tags() as $tag) {
            // The row is read as its own label says it — the tags on the branch — so the question is
            // whether the tag is reachable from the branch tip, which is `HEAD`, the commit this run
            // verifies. Reading it as "behind the register" was the stricter claim and one the row
            // never makes: a release moves these rows in its own commit, which is *after* the commit
            // a note taken before it names as the register, so the tag it has just cut is on the
            // branch and not behind the register — and the guard then failed on the note the release
            // had written. Behind `HEAD` is the row's own words and a claim that stays true as the
            // branch moves on.
            //
            // The tag about to be created has no commit yet — the release makes it at `HEAD`, which
            // is the commit this run verifies. What can be asked of it now is the claim it will have
            // to keep: that the commit it will point at is a descendant of the register, rather than
            // some other line of history.
            $behind = $tag === $pending
                ? Handoff::git('merge-base', '--is-ancestor', $head, 'HEAD')['exit']
                : Handoff::git('merge-base', '--is-ancestor', $tag, 'HEAD')['exit'];

            if ($behind !== 0) {
                $off[] = $tag;
            }
        }

        self::assertSame(
            [],
            $off,
            sprintf(
                'HANDOFF.md calls these tags the ones on the branch, and these are not reachable from HEAD, '
                .'the commit this run verifies: %s.',
                implode(', ', $off),
            ),
        );
    }

    public function test_the_tarball_it_describes_is_the_one_gitattributes_writes(): void
    {
        $shipped = Handoff::ships();
        $held = Handoff::withheld();
        $scripts = array_values(array_map(
            static fn (string $path): string => 'bin/'.basename($path),
            glob(Handoff::root().'/bin/*.php') ?: [],
        ));

        $attributes = self::attributes(array_values(array_unique(array_merge(
            array_column($shipped, 'path'),
            array_column($held, 'path'),
            $scripts,
        ))));

        $wrong = [];

        foreach ($shipped as $claim) {
            if (($attributes[$claim['path']] ?? '') !== 'unspecified') {
                $wrong[] = sprintf(
                    'line %d calls %s shippable, and .gitattributes reads [%s]',
                    $claim['source'],
                    $claim['path'],
                    $attributes[$claim['path']] ?? 'nothing',
                );
            }
        }

        foreach ($held as $claim) {
            if (($attributes[$claim['path']] ?? '') !== 'set') {
                $wrong[] = sprintf(
                    'line %d says %s is held back, and .gitattributes reads [%s]',
                    $claim['source'],
                    $claim['path'],
                    $attributes[$claim['path']] ?? 'nothing',
                );
            }
        }

        self::assertSame([], $wrong, "HANDOFF.md describes a dist tarball this package does not build:\n  - ".implode("\n  - ", $wrong));

        $named = array_values(array_intersect(array_column($shipped, 'path'), $scripts));
        $actual = array_values(array_filter(
            $scripts,
            static fn (string $script): bool => ($attributes[$script] ?? '') === 'unspecified',
        ));

        sort($named);
        sort($actual);

        self::assertSame(
            $named,
            $actual,
            sprintf(
                "HANDOFF.md says these scripts in bin/ reach a consumer: %s. The scripts `.gitattributes` does not ".
                'hold back are: %s. Nothing else is checked about them, so the two lists have to be the same list.',
                implode(', ', $named) ?: '(none)',
                implode(', ', $actual) ?: '(none)',
            ),
        );
    }

    public function test_the_analysis_runs_at_the_level_over_the_paths_it_names(): void
    {
        $claim = Handoff::phpstan();
        $configured = self::phpstanNeon();

        self::assertSame(
            $claim['level'],
            $configured['level'],
            sprintf(
                'HANDOFF.md line %d says the analysis runs at level [%s], and phpstan.neon.dist says [%s].',
                $claim['source'],
                $claim['level'],
                $configured['level'],
            ),
        );

        $claimed = $claim['paths'];
        $paths = $configured['paths'];
        sort($claimed);
        sort($paths);

        self::assertSame(
            $claimed,
            $paths,
            sprintf(
                'HANDOFF.md line %d says the analysis covers %s, and phpstan.neon.dist covers %s.',
                $claim['source'],
                implode(', ', $claimed) ?: '(nothing)',
                implode(', ', $paths) ?: '(nothing)',
            ),
        );
    }

    public function test_the_skills_it_names_are_the_ones_that_are_indexed(): void
    {
        $known = array_keys(SkillDescriptions::skills());
        $named = Handoff::skills();
        sort($known);
        sort($named);

        self::assertSame(
            $known,
            $named,
            sprintf(
                "HANDOFF.md names these skills: %s. This package has: %s — a skill added, renamed or removed is a ".
                're-take, because a fresh thread reads that row to find out what is here.',
                implode(', ', $named),
                implode(', ', $known),
            ),
        );

        $listed = array_map(
            static fn (array $row): string => $row['skill'],
            SkillDescriptions::indexed(),
        );

        self::assertSame(
            [],
            array_values(array_diff($named, $listed)),
            sprintf(
                'HANDOFF.md calls these the skills this package has, and .agents/README.md does not list them: %s.',
                implode(', ', array_values(array_diff($named, $listed))),
            ),
        );
    }

    public function test_the_command_it_quotes_is_declared_where_it_says(): void
    {
        $command = Handoff::command();
        $path = Handoff::root().'/'.$command['file'];

        self::assertFileExists(
            $path,
            sprintf('HANDOFF.md line %d says the flip is a command declared in %s, which is not a file here.', $command['source'], $command['file']),
        );

        $raw = (string) file_get_contents($path);

        self::assertSame(
            1,
            preg_match('/protected \$signature\s*=\s*\'([^\']+)\'/', $raw, $matches),
            sprintf('%s declares no `protected $signature`, so the command the note quotes has no name to hold it to.', $command['file']),
        );

        self::assertSame(
            $command['name'],
            (string) strtok(trim($matches[1]), " \t\n"),
            sprintf(
                'HANDOFF.md line %d quotes `%s`, and %s declares `%s`.',
                $command['source'],
                $command['name'],
                $command['file'],
                (string) strtok(trim($matches[1]), " \t\n"),
            ),
        );
    }

    public function test_the_checks_it_says_the_list_names_are_the_checks_it_lists(): void
    {
        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/checks.php', '--list']);

        self::assertSame(
            0,
            $run['exit'],
            sprintf('php bin/checks.php --list exited %d: %s%s', $run['exit'], $run['output'], $run['error']),
        );

        $names = Handoff::outputLines($run['output']);

        self::assertSame(
            Handoff::claimedChecks(),
            count($names),
            sprintf(
                'HANDOFF.md says the list names %d checks, and it names %d: %s.',
                Handoff::claimedChecks(),
                count($names),
                implode(', ', array_map(static fn (string $line): string => (string) strtok($line, ' '), $names)),
            ),
        );
    }

    public function test_the_unreleased_section_is_the_one_it_describes(): void
    {
        $claim = Handoff::unreleased();
        $head = Handoff::basis()['head']['sha'];

        $show = Handoff::git('show', $head.':CHANGELOG.md');

        self::assertSame(0, $show['exit'], sprintf('The changelog the note describes (%s:CHANGELOG.md) could not be read: %s', $head, $show['error']));

        $file = tempnam(sys_get_temp_dir(), 'swrr-handoff-');

        self::assertIsString($file, 'A temporary file could not be made for the changelog the weighing is asked about.');

        file_put_contents($file, $show['output']);

        try {
            $run = Handoff::run([PHP_BINARY, '-r', self::SIGNAL, Handoff::root().'/bin/weighing.php', $file]);
        } finally {
            @unlink($file);
        }

        self::assertSame(
            0,
            $run['exit'],
            sprintf('The weighing could not be asked about the changelog the note describes: %s%s', $run['output'], $run['error']),
        );

        $signal = json_decode($run['output'], true);

        self::assertIsArray($signal, sprintf('The weighing answered nothing readable: %s', $run['output']));

        self::assertSame(
            $claim['severity'],
            $signal['severity'],
            sprintf(
                'HANDOFF.md says the Unreleased notes weigh as [%s], and the package\'s own changelogSignal() reads them as [%s].',
                $claim['severity'],
                $signal['severity'],
            ),
        );

        $counts = [];

        foreach ($signal['evidence'] as $line) {
            if (preg_match('/^### (\w+) — (\d+) entr/', $line, $matches) === 1) {
                $counts[strtolower($matches[1])] = (int) $matches[2];
            }
        }

        // A heading the section does not carry counts zero rather than being absent from the
        // comparison: `bin/release.php` promotes the notes and writes a bare `## Unreleased`,
        // leaving the headings to the first change, so the section a release leaves behind has
        // none of them and a note taken in that state has to be able to say so. The teeth are
        // untouched — a heading that *is* there is reported with its count, so a note that calls
        // it empty fails, and a heading the note does not name (say `### Security`) is a key it
        // does not have.
        // Every heading the weighing reports is a key the note has to state, `removed` included: it is
        // the heading that decides a `breaking`, and the note's row has said `, d removed` since the
        // first section that retired anything. A heading the section does not carry counts zero rather
        // than being absent, so a note taken where nothing was removed still names all four.
        foreach (['added', 'fixed', 'changed', 'removed'] as $heading) {
            $counts[$heading] ??= 0;
        }

        $claimed = [
            'added' => $claim['added'],
            'fixed' => $claim['fixed'],
            'changed' => $claim['changed'],
            'removed' => $claim['removed'],
        ];
        ksort($counts);
        ksort($claimed);

        self::assertSame(
            $claimed,
            $counts,
            sprintf(
                'HANDOFF.md says the Unreleased section carries %s; the weighing counts %s. A heading the note '.
                'does not mention is the drift this half is for, because the note says those counts are the '.
                'weighing\'s and not counted by hand.',
                self::written($claimed),
                self::written($counts),
            ),
        );

        self::assertSame(
            array_sum($counts),
            $claim['total'],
            sprintf(
                'HANDOFF.md says the Unreleased section carries %d entries, and the headings it lists add up to %d.',
                $claim['total'],
                array_sum($counts),
            ),
        );
    }

    /**
     * What section 1 quotes about the three `.tsv` records, held to the commands it quotes.
     *
     * WHY THE STATE IS NOT WHAT IS HELD
     * ---------------------------------
     *   This test used to require exit 1 — the records out of step with the commit the note names —
     *   and that is not always the truth: the release that writes the records leaves them current at
     *   its own commit, so a note taken there, which is the note a release cycle ends with, would
     *   have to claim a disagreement that is not there or be refused. What the note can be held to is
     *   what it *quotes*: the commands are taken out of the transcript and run, and the exit code
     *   beside each one has to be the one it returned. So a note that writes `exit 1` beside a
     *   command that exits 0 fails, and so does a command that failed with nothing written beside it —
     *   the state is a fact about the tree, and the quote is the note's claim about it.
     */
    public function test_the_records_are_what_the_commands_section_1_quotes_say(): void
    {
        foreach (Handoff::records() as $quoted) {
            $arguments = array_slice($quoted['command'], 1);
            $run = Handoff::run(array_merge([PHP_BINARY], $arguments));
            $said = trim((string) preg_replace('/\s+/', ' ', $run['output'].' '.$run['error']));

            self::assertSame(
                $quoted['exit'],
                $run['exit'],
                sprintf(
                    "HANDOFF.md line %d quotes `%s` and writes exit %d beside it, and the command exits %d.\n".
                    'The records are a state rather than a claim, so the transcript has to be what the command '.
                    "answers: run it, write what it says, or take the note again.\n%s%s",
                    $quoted['source'],
                    implode(' ', $quoted['command']),
                    $quoted['exit'],
                    $run['exit'],
                    $run['output'],
                    $run['error'],
                ),
            );

            foreach (self::numbersIn($quoted['summary']) as $number) {
                self::assertStringContainsString(
                    $number,
                    $said,
                    sprintf(
                        'HANDOFF.md line %d quotes %s beside `%s` and the command does not report it, so the '.
                        'summary is not the reading it is presented as.',
                        $quoted['source'],
                        $number,
                        implode(' ', $quoted['command']),
                    ),
                );
            }

            // A quoted failure has to be *this* failure. `bin/inventory.php` exits 1 for a record of
            // another release and for a ref it does not have as well, and a note that quoted one of
            // those while describing the records would be reading the wrong sentence.
            if ($quoted['exit'] === 1) {
                self::assertStringContainsString(
                    'out of step',
                    $said,
                    sprintf(
                        'HANDOFF.md line %d quotes `%s` failing, and the command did not fail with the records '
                        .'out of step — which is the reading the section is about.',
                        $quoted['source'],
                        implode(' ', $quoted['command']),
                    ),
                );

                // Which of the three has drifted is the working tree's fact rather than the
                // sentence's, and it is not always all three: a change that only edits files
                // leaves `files.tsv` — the files and the symbols in them — current, so the reading
                // names the records that moved. What has to hold is the other direction: every
                // record the reading names is one the section is about, so the note cannot be
                // quoting a drift about something else.
                preg_match_all('/([a-z]+\.tsv): \d+ line\(s\) stale/', $said, $drifted);

                self::assertNotSame([], $drifted[1], sprintf(
                    'HANDOFF.md line %d quotes `%s` failing, and the reading names none of the three '
                    .'records the section is about — so the note is quoting a drift whose subject it '
                    .'does not state.',
                    $quoted['source'],
                    implode(' ', $quoted['command']),
                ));

                foreach ($drifted[1] as $named) {
                    self::assertContains($named, ['files.tsv', 'methods.tsv', 'surface.tsv'], sprintf(
                        'HANDOFF.md line %d quotes `%s` failing, and the reading names %s, which is not one '
                        .'of the three records the section is about.',
                        $quoted['source'],
                        implode(' ', $quoted['command']),
                        $named,
                    ));
                }
            }
        }
    }

    /**
     * The transcript's other reading: a register whose records are current renders exit 0, and says so
     * in the tool's own sentence.
     *
     * WHY THIS CASE EXISTS
     * --------------------
     *   The block quotes two runs, and the one a release depends on is the first: a release rewrites the
     *   three `.tsv` records at its own commit and then refreshes this block there, so the register run
     *   exits 0 and the note has to read that way rather than claiming a drift. The note in the tree
     *   cannot reach that state — its register is older than the records, which is why the first run it
     *   quotes exits 1 — so the branch was pinned by nothing, and the two things that can silently break
     *   it are cheap to write: quoting the run's preamble (`reading the tree at … out of git, not the
     *   checkout`) instead of its verdict, or writing `exit 0` with an empty reading beside it.
     *
     *   The register is the one the records name in their own header rather than `HEAD`, so the case is
     *   about the branch rather than about one afternoon's tree: a record and the tag it describes travel
     *   together, and `--check --at=<that tag>` is the reading that must exit 0.
     */
    public function test_a_register_whose_records_are_current_renders_exit_zero(): void
    {
        $stamp = self::recordStamp();

        // A release stamps the three records with the tag it is about to cut, so during the guard run
        // that tag is a name git cannot resolve yet: the register is the commit it will be cut at, and
        // the reading that has to be current is the plain `--check`, which is the one `bin/inventory.php`
        // documents for a release's own record ("the one stamp `--check` cannot derive from git"). Asking
        // `--at=<stamp>` there would be asking git for a ref the release has deliberately not created.
        $pending = Handoff::pendingTag();
        $atRelease = $pending !== null && $pending === $stamp;

        $resolved = Handoff::git('rev-parse', $atRelease ? 'HEAD' : $stamp.'^{commit}');

        self::assertSame(0, $resolved['exit'], sprintf(
            $atRelease
                ? 'The records are stamped for %s, the tag this release is cutting, and HEAD does not resolve — a release has no commit to cut it at%s'
                : "files.tsv says it describes the tree at %s, and git resolves no such ref — a record of a tag "
                    ."nothing has is a transcript about a tree a reader cannot open%s",
            $stamp,
            $resolved['error'],
        ));

        $register = trim($resolved['output']);
        $block = Handoff::rendered('records', ['register' => $register, 'origin' => $register, 'branch' => 'dev']);

        // The run the block quotes, asked directly: what it says beside `exit 0` has to be the tool's
        // own sentence rather than this renderer's idea of one.
        $run = Handoff::run([
            PHP_BINARY,
            Handoff::root().'/bin/inventory.php',
            '--check',
            ...($atRelease ? [] : ['--at='.$stamp]),
        ]);

        self::assertSame(0, $run['exit'], sprintf(
            $atRelease
                ? 'files.tsv is stamped for %s, the tag this release is cutting, and `bin/inventory.php --check` exits %d:\n%s%s'
                : 'files.tsv is stamped for %s and `bin/inventory.php --check --at=%s` exits %d:\n%s%s',
            ...($atRelease
                ? [$stamp, $run['exit'], $run['output'], $run['error']]
                : [$stamp, $stamp, $run['exit'], $run['output'], $run['error']]),
        ));

        // The block quotes both runs, `--at=<register>` first and the plain one second: a release's own
        // record is the second, and the first is the drift that is true of a tag that does not exist yet.
        $lines = explode("\n", $block);
        $command = null;
        $prefix = $atRelease ? 'php bin/inventory.php --check' : 'php bin/inventory.php --check --at=';

        foreach ($lines as $index => $line) {
            if (str_starts_with($line, $prefix)) {
                $command = $index;
            }
        }

        self::assertIsInt($command, 'The records block quotes no `--check` run at all.');

        // The tool's own verdict, taken off the run rather than written here: a sentence in this file
        // would be a second copy of the counts, and would go stale on the first file the tree gains.
        $bullets = array_values(array_filter(
            Handoff::outputLines($run['output']."\n".$run['error']),
            static fn (string $line): bool => str_starts_with($line, '•'),
        ));

        self::assertNotSame([], $bullets, 'the run found the records current and said nothing a transcript can quote');

        $said = trim(ltrim((string) end($bullets), '•'));

        self::assertStringStartsWith(
            'inventory is current',
            $said,
            'The run\'s last word is the reading the section is about, so the verdict is taken from it.',
        );
        self::assertSame(
            '  --> exit 0: '.$said,
            $lines[$command + 1],
            'A register whose records are current has to read as the tool reads it: the verdict, not the run\'s '
            .'preamble and not an exit code with nothing beside it.',
        );

        // Nothing follows a current run, and which line that nothing is depends on where the run sits:
        // a run followed by another command is separated from it by a blank, while the last run quoted
        // is closed by the block's fence. A record row under the verdict is the drift either way.
        self::assertContains(
            $lines[$command + 2] ?? null,
            ['', '```'],
            'A current run listed a record under it, so the transcript claims a drift the tool did not find.',
        );
    }

    /**
     * The tag an inventory record is stamped for, read off the record's own header — where the tool puts
     * it, and the only place it is written for a reader rather than for a comparison.
     */
    private static function recordStamp(): string
    {
        $header = (string) file_get_contents(Handoff::root().'/files.tsv');

        if (preg_match('/^# .*describes the tree at (\S+)\s*$/m', $header, $matches) !== 1) {
            self::fail('files.tsv no longer says which tree it describes, and a record without a stamp cannot be read against one.');
        }

        return $matches[1];
    }

    /**
     * The gate record, held to being the run the note quotes: every check the gate declares, one
     * verdict apiece, all green, and a duration that can hold the rows under it.
     *
     * WHAT THE RECORD CAN AND CANNOT BE HELD TO
     * -----------------------------------------
     *   A record is evidence of a run, not the run: nothing here re-runs a ten-minute gate inside the
     *   suite the gate is running, so what is checked is the shape a run leaves rather than that this
     *   afternoon happened. What that shape can still be wrong about is everything a session could
 *   have typed by hand, which is the point — a record with fourteen rows and a summary saying
 *   thirteen, a row whose verdict is neither PASS, FAIL nor SKIP, a run shorter than its longest
 *   check, an exit code at odds with the rows, a missing check, or a run dated before the note that
 *   quotes it would each make the block above it a claim the gate does not make. The names are
 *   compared with `bin/checks.php --list` rather than with a list here, so a check added to the gate
 *   is a record that has to be taken again; the two dates are compared with the note's own title,
 *   because a note re-taken on a later day is otherwise free to carry the run from before it.
     */
    public function test_the_gate_record_is_a_run_of_every_check_the_gate_declares(): void
    {
        $record = Handoff::gate();
        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/checks.php', '--list']);
        $declared = [];

        foreach (Handoff::outputLines($run['output']) as $line) {
            if (preg_match('/^(\S+)\s/', $line, $matches) === 1) {
                $declared[] = $matches[1];
            }
        }

        $recorded = array_column($record['checks'], 'name');

        self::assertSame(
            $declared,
            $recorded,
            sprintf(
                "HANDOFF.md's gate block is rendered from a run of %s, and the gate declares %s. The record is "
                .'written by `php bin/checks.php --record`, which records the whole run and refuses `--only` — a '
                .'block that listed a subset would be claiming the checks it leaves out were not there.',
                implode(', ', $recorded) ?: '(nothing)',
                implode(', ', $declared) ?: '(nothing)',
            ),
        );

        $failed = array_column(array_filter($record['checks'], static fn (array $check): bool => $check['status'] === 'FAIL'), 'name');
        $slowest = max(array_column($record['checks'], 'seconds'));

        self::assertSame(
            [],
            $failed,
            sprintf(
                "HANDOFF.md's gate block quotes `exit %d` and a run with failures in it: %s. Section 1 says the gate "
                .'is green, so a red record is a note that has to be re-taken on a green run rather than one that '
                .'quotes the red one.',
                $record['exit'],
                implode(', ', $failed),
            ),
        );

        self::assertSame(0, $record['exit'], sprintf('A record with no failure in it and an exit code of %d is not the run it describes.', $record['exit']));

        self::assertGreaterThanOrEqual(
            $slowest,
            $record['elapsed'],
            sprintf(
                'The record says the whole run took %.1fs and its slowest check %.1fs, which is a record no run could have written.',
                $record['elapsed'],
                $slowest,
            ),
        );

        // A day of slack, because the record is written on a machine whose clock is the record's and
        // read on whatever machine runs the suite: what is refused is a date no run could have written,
        // not a timezone.
        self::assertLessThanOrEqual(
            strtotime(date('Y-m-d')) + 86400,
            strtotime($record['taken']),
            sprintf(
                'The gate record is dated %s, which is in the future: a run cannot have been taken after the day '
                .'the note quoting it is being read.',
                $record['taken'],
            ),
        );

        // The other end of the same question, and the one a reader is actually misled by. The note is
        // dated in its title, which is the only claim in it about the note rather than about the tree,
        // and a record older than that date is a run taken before the note existed to quote it — a note
        // re-taken a week later still carrying last week's run, with section 1's sentence calling it
        // this note's verification. So the two dates are read against each other, and the failure names
        // the command that writes both halves in one step.
        //
        // What is still *not* held is how old the run is within the note's own day: a take made the same
        // day as its run is the ordinary case, and a typo fixed an hour later should not demand another
        // ten minutes of gate. The day is the unit because the day is what the note states.
        self::assertGreaterThanOrEqual(
            strtotime(Handoff::taken()),
            strtotime($record['taken']),
            sprintf(
                'HANDOFF.md was taken on %s and its gate block quotes a run recorded on %s, which predates the '
                .'note. A run from before the day it is quoted in cannot be this note\'s verification, however '
                .'green it was: run `php bin/checks.php --record`, which writes the record and re-renders this '
                .'block from it, or take the note again on the day of the run it quotes.',
                Handoff::taken(),
                $record['taken'],
            ),
        );

        $block = Handoff::block('gate');

        foreach ($record['checks'] as $check) {
            self::assertStringContainsString(
                sprintf('%-10s %-5s %6.1fs  %s', $check['name'], $check['status'], $check['seconds'], $check['note']),
                $block,
                sprintf(
                    "HANDOFF.md's gate block does not carry the recorded row for [%s]: the block is rendered from "
                    .'the record, so a row it lost is one the renderer dropped rather than one the run omitted.',
                    $check['name'],
                ),
            );
        }
    }

    /**
     * The place the record lives, named once in the gate and once in the reader that quotes it.
     *
     * WHY TWO FILES ARE HELD TO A STRING
     * ----------------------------------
     *   `bin/checks.php` writes `.agents/gate.json` and `tests/Support/Handoff.php` reads it, so the
     *   same path is spelled twice in a package whose whole habit is that a fact is written down
     *   once. The two cannot be brought together — a `bin/` script does not read a test support class,
     *   and the guard has to read the writer's own source to know what it would write — so what is
     *   held is the pair: the writer has to name the path the reader has. Rename either and this
     *   fails, which is the failure that would otherwise be silent, because a record written to a
     *   path nobody reads leaves the note quoting the run before last.
     */
    public function test_the_gate_writes_the_record_the_note_reads(): void
    {
        $source = @file_get_contents(Handoff::root().'/bin/checks.php');
        $path = Handoff::gateRecordPath();

        self::assertIsString($source, 'bin/checks.php could not be read, so the path it writes its record to cannot be held to the one the note reads.');
        self::assertStringContainsString(
            "'{$path}'",
            $source,
            sprintf(
                'HANDOFF.md is rendered from the record at %s, and bin/checks.php does not name that path — so the '
                .'run it records is one nothing quotes, and the block would keep describing the run before it.',
                $path,
            ),
        );
    }

    /**
     * The sentence that introduces the gate block, held to stating no number at all.
     *
     * WHY THE PROSE MAY NOT DO THIS ITSELF
     * ------------------------------------
     *   Every number in the block is the record's: the totals, the seconds and the suite's own counts
     *   come out of `.agents/gate.json` and move when a run is recorded. A number written into the
     *   sentence above it is the hand-copy this mechanism exists to refuse — and it is the one shape
     *   that cannot be caught by comparing the prose with a run, because what it states is what some
     *   *other* run said. So the rule is the shape rather than the value: a digit in that paragraph
     *   fails, whichever run it came from, and the repair is to name the record the number is in.
     */
    public function test_section_1_states_no_gate_number_outside_the_block(): void
    {
        $claim = Handoff::gateClaim();

        self::assertSame(
            [],
            array_values(array_filter(
                preg_split('/(\s+)/', $claim['text']) ?: [],
                static fn (string $word): bool => preg_match('/\d/', $word) === 1,
            )),
            sprintf(
                "HANDOFF.md line %d states a number about the gate — [%s] — and the numbers are the recorded run's: "
                .'they are rendered into the block below this sentence and move when a run is recorded. Name the '
                .'record the number is read from instead of writing it down.',
                $claim['source'],
                $claim['text'],
            ),
        );
    }

    /**
     * Every number a quoted summary states, as the string it is written as — so the comparison is
     * against the command's own line and not against a re-formatted one.
     *
     * @return list<string>
     */
    private static function numbersIn(string $summary): array
    {
        preg_match_all('/\d+/', $summary, $matches);

        return array_values(array_unique($matches[0]));
    }

    public function test_the_toolchain_rows_are_the_facts_the_machine_record_holds(): void
    {
        $machine = self::machineRecord();
        $php = Handoff::php();

        // The row names the record's key rather than a path, and `Handoff::php()` resolves the
        // path through that record — so what is compared here is the row's pointer against the
        // record, whose own `php.exe` is on both sides by construction. What is still the row's own
        // claim is below: the version, and whether `php` answers on PATH.
        self::assertSame($machine['php']['exe'], $php['exe'], sprintf(
            'HANDOFF.md line %d resolves PHP to [%s], and the record the row names says [%s].',
            $php['source'],
            $php['exe'] ?? 'nothing',
            $machine['php']['exe'],
        ));

        self::assertSame($machine['php']['version'], $php['version'], sprintf(
            'HANDOFF.md line %d puts the PHP version at [%s], and the machine record says [%s].',
            $php['source'],
            $php['version'],
            $machine['php']['version'],
        ));

        self::assertSame($machine['php']['onPath'], $php['onPath'], sprintf(
            'HANDOFF.md line %d says PHP is%s on PATH, and the machine record says it is%s.',
            $php['source'],
            $php['onPath'] ? '' : ' not',
            $machine['php']['onPath'] ? '' : ' not',
        ));

        $composer = Handoff::composer();

        self::assertSame($machine['composer']['version'], $composer['version'], sprintf(
            'HANDOFF.md line %d leads the Composer row with [%s], and the machine record says [%s].',
            $composer['source'],
            $composer['version'],
            $machine['composer']['version'],
        ));

        self::assertSame(basename($machine['composer']['phar']), $composer['phar'], sprintf(
            'HANDOFF.md line %d names [%s], and the machine record points at [%s].',
            $composer['source'],
            $composer['phar'],
            $machine['composer']['phar'],
        ));

        self::assertSame($machine['composer']['gitignored'], $composer['gitignored'], sprintf(
            'HANDOFF.md line %d says composer.phar is%s gitignored, and the machine record says it is%s.',
            $composer['source'],
            $composer['gitignored'] ? '' : ' not',
            $machine['composer']['gitignored'] ? '' : ' not',
        ));

        $search = Handoff::search();

        self::assertSame($machine['search']['rgOnPath'], $search['available'], sprintf(
            'HANDOFF.md line %d says rg is%s on PATH, and the machine record says it is%s.',
            $search['source'],
            $search['available'] ? '' : ' not',
            $machine['search']['rgOnPath'] ? '' : ' not',
        ));

        self::assertSame($machine['search']['rgVersion'], $search['version'], sprintf(
            'HANDOFF.md line %d puts ripgrep at [%s], and the machine record says [%s].',
            $search['source'],
            $search['version'],
            $machine['search']['rgVersion'],
        ));

        $style = Handoff::style();

        self::assertSame($machine['style']['command'], $style['command'], sprintf(
            'HANDOFF.md line %d names the style command [%s], and the machine record says [%s].',
            $style['source'],
            $style['command'],
            $machine['style']['command'],
        ));

        self::assertSame($machine['style']['preset'], $style['preset'], sprintf(
            'HANDOFF.md line %d puts the style gate at [%s], and the machine record says [%s].',
            $style['source'],
            $style['preset'],
            $machine['style']['preset'],
        ));
    }

    public function test_the_git_row_is_the_facts_the_machine_record_holds(): void
    {
        $machine = self::machineRecord();
        $identity = Handoff::gitIdentity();

        self::assertSame($machine['git']['origin'], $identity['origin'], sprintf(
            'HANDOFF.md line %d quotes origin as [%s], and the machine record says [%s].',
            $identity['source'],
            $identity['origin'],
            $machine['git']['origin'],
        ));

        self::assertSame($machine['git']['commitGpgSign'], $identity['commitGpgSign'], sprintf(
            'HANDOFF.md line %d says commit.gpgsign is [%s], and the machine record says [%s].',
            $identity['source'],
            var_export($identity['commitGpgSign'], true),
            var_export($machine['git']['commitGpgSign'], true),
        ));

        self::assertSame($machine['git']['userSigningKey'], $identity['userSigningKey'], sprintf(
            'HANDOFF.md line %d signs with [%s], and the machine record says [%s].',
            $identity['source'],
            $identity['userSigningKey'],
            $machine['git']['userSigningKey'],
        ));

        self::assertSame($machine['git']['hooksPath'], $identity['hooksPath'], sprintf(
            'HANDOFF.md line %d says the hooks path is [%s], and the machine record says [%s].',
            $identity['source'],
            $identity['hooksPath'] ?? 'unset',
            $machine['git']['hooksPath'] ?? 'unset',
        ));
    }

    public function test_the_packaging_rows_are_the_facts_the_machine_record_holds(): void
    {
        $machine = self::machineRecord();
        $ships = array_column(Handoff::ships(), 'path');
        $holds = array_column(Handoff::withheld(), 'path');
        $carries = $machine['tarball']['carries'];
        $leftOut = $machine['tarball']['leavesOut'];

        sort($ships);
        sort($holds);
        sort($carries);
        sort($leftOut);

        self::assertSame($carries, $ships, sprintf(
            "The machine record says a consumer's tarball carries:\n  - %s\nHANDOFF.md says:\n  - %s",
            implode("\n  - ", $carries),
            implode("\n  - ", $ships),
        ));

        self::assertSame($leftOut, $holds, sprintf(
            "The machine record says the tarball holds back:\n  - %s\nHANDOFF.md says:\n  - %s",
            implode("\n  - ", $leftOut),
            implode("\n  - ", $holds),
        ));

        $skills = Handoff::skills();
        $recorded = array_map(
            static fn (string $dir): string => basename($dir),
            $machine['skills']['dirs'],
        );

        sort($skills);
        sort($recorded);

        self::assertSame($recorded, $skills, sprintf(
            'The machine record lists the skills %s, and HANDOFF.md names %s.',
            implode(', ', $recorded),
            implode(', ', $skills),
        ));

        self::assertSame($machine['skills']['index'], Handoff::skillsIndex(), sprintf(
            'The machine record says the skills are indexed by [%s], and HANDOFF.md says [%s].',
            $machine['skills']['index'],
            Handoff::skillsIndex(),
        ));

        self::assertSame($machine['checks']['list'], Handoff::checksCommand(), sprintf(
            'The machine record says the checks are listed by [%s], and HANDOFF.md says [%s].',
            $machine['checks']['list'],
            Handoff::checksCommand(),
        ));

        self::assertSame($machine['checks']['count'], Handoff::claimedChecks(), sprintf(
            'The machine record says the list names %d checks, and HANDOFF.md says %d.',
            $machine['checks']['count'],
            Handoff::claimedChecks(),
        ));

        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/checks.php', '--list']);

        self::assertSame(
            0,
            $run['exit'],
            sprintf('php bin/checks.php --list exited %d: %s%s', $run['exit'], $run['output'], $run['error']),
        );

        $names = array_map(
            static fn (string $line): string => (string) strtok($line, ' '),
            Handoff::outputLines($run['output']),
        );

        self::assertSame($machine['checks']['names'], $names, sprintf(
            "The machine record says the list is:\n  - %s\nIt is:\n  - %s",
            implode("\n  - ", $machine['checks']['names']),
            implode("\n  - ", $names),
        ));

        $analysis = Handoff::phpstan();
        $paths = $analysis['paths'];
        $recordedPaths = $machine['staticAnalysis']['paths'];

        sort($paths);
        sort($recordedPaths);

        self::assertSame($machine['staticAnalysis']['config'], $analysis['config'], sprintf(
            'The machine record says the analysis is configured in [%s], and HANDOFF.md says [%s].',
            $machine['staticAnalysis']['config'],
            $analysis['config'],
        ));

        self::assertSame($machine['staticAnalysis']['level'], $analysis['level'], sprintf(
            'The machine record says the analysis runs at level [%s], and HANDOFF.md says [%s].',
            $machine['staticAnalysis']['level'],
            $analysis['level'],
        ));

        self::assertSame($recordedPaths, $paths, sprintf(
            'The machine record says the analysis covers %s, and HANDOFF.md says %s.',
            implode(', ', $recordedPaths),
            implode(', ', $paths),
        ));
    }

    /**
     * The one thing a row *added* to section 5 can break, which every test above reads past.
     *
     * WHY THIS IS NOT ONE OF THE COMPARISONS ABOVE
     * --------------------------------------------
     *   Each test above takes a row by the label it knows — `Handoff::php()`, `Handoff::composer()`,
     *   `Handoff::search()`, `Handoff::ships()` — so a row nobody asks for is a row nothing
     *   compares. The table can grow a field for the machine (`PowerShell`, the container the suite
     *   runs in, the editor) and stay green while describing one machine more than
     *   `.agents/machine.local.json` — the record the table is *held to* — does. The gap is an
     *   absence, so it is invisible in a diff of either file.
     *
     *   This therefore asks the other question: the rows the table *has*, rather than the rows a
     *   reader asks for. `Handoff::working()` is that list read against the contract in the reader
     *   (`WORKING_LEAVES`), which says which of the record's leaves holds each row, and which rows
     *   (one, so far) the record does not hold at all. Two answers fail it: a row the contract does
     *   not name, which is a row nobody has decided about; and a row held by a path that is not a
     *   leaf of `Handoff::machineLeaves()` — coverage in name only, because those leaves are the
     *   ones `machine()` refuses a record without, and a path nothing asks the record for would
     *   cover the row with nothing.
     *
     * WHY IT DOES NOT SKIP WHERE THE OTHERS DO
     * ---------------------------------------
     *   The comparisons above need the record, so they skip in a checkout without one — a fresh
     *   clone, CI's runner. This one does not: the answer is the contract, which is in the reader,
     *   and a checkout with no record is exactly where a row nobody has decided about would
     *   otherwise arrive unremarked, in the checkout and on the runner both.
     */
    public function test_section_5_has_no_row_the_machine_record_does_not_cover(): void
    {
        $leaves = Handoff::machineLeaves();
        $rows = Handoff::working();
        $undecided = [];
        $unheld = [];

        foreach ($rows as $row) {
            if (!$row['known']) {
                $undecided[] = sprintf('%s (line %d)', $row['label'], $row['source']);

                continue;
            }

            foreach ($row['leaves'] ?? [] as $path) {
                if (!in_array($path, $leaves, true)) {
                    $unheld[] = sprintf('%s (line %d) is held by %s', $row['label'], $row['source'], $path);
                }
            }
        }

        self::assertGreaterThanOrEqual(
            10,
            count($rows),
            sprintf(
                'HANDOFF.md section 5 has %d rows. This guard reads the table it finds rather than a count kept '
                .'beside it, so a table cut back to a handful of rows would be read as the whole of section 5.',
                count($rows),
            ),
        );

        self::assertSame(
            [],
            $undecided,
            sprintf(
                "HANDOFF.md section 5 has rows nothing says which machine-record leaves hold:\n  - %s\n".
                "A row is how a reader learns a fact about the machine, and `.agents/machine.local.json` is where those\n".
                "facts live — so a row names the record's leaves for it in the reader's contract, or is written there as\n".
                "one the record does not hold. A row in neither is one nothing compares: the record would not notice it\n".
                'missing, and the table would describe a machine the record does not.',
                implode("\n  - ", $undecided),
            ),
        );

        self::assertSame(
            [],
            $unheld,
            sprintf(
                "HANDOFF.md section 5 has rows held by leaves the machine record is not required to carry:\n  - %s\n".
                "A row is covered only by a leaf of `Handoff::machineLeaves()` — the list `machine()` proves the record\n".
                'has, and refuses a record without — because a path nothing asks the record for covers the row with nothing.',
                implode("\n  - ", $unheld),
            ),
        );
    }

    public function test_the_machine_record_describes_this_checkout(): void
    {
        $machine = self::machineRecord();

        self::assertSame(
            realpath(Handoff::root()),
            realpath($machine['package']['dir']),
            sprintf(
                'The machine record says this checkout is [%s], and the package root reads [%s].',
                $machine['package']['dir'],
                realpath(Handoff::root()),
            ),
        );

        self::assertFileExists($machine['php']['exe'], sprintf(
            'The machine record points PHP at [%s], which is not on this machine.',
            $machine['php']['exe'],
        ));

        self::assertSame($machine['php']['dir'], dirname($machine['php']['exe']), sprintf(
            'The machine record splits PHP into dir [%s] and exe [%s], which do not sit together.',
            $machine['php']['dir'],
            $machine['php']['exe'],
        ));

        self::assertFileExists($machine['pwsh']['exe'], sprintf(
            'The machine record points pwsh at [%s], which is not on this machine.',
            $machine['pwsh']['exe'],
        ));

        self::assertFileExists($machine['composer']['phar'], sprintf(
            'The machine record says composer.phar is [%s], which is not on this machine.',
            $machine['composer']['phar'],
        ));

        if ($machine['search']['rgOnPath']) {
            self::assertFileExists($machine['search']['rgExe'], sprintf(
                'The machine record says rg answers on PATH at [%s], which is not on this machine.',
                $machine['search']['rgExe'],
            ));
        }

        self::assertFileExists(Handoff::root().'/'.$machine['skills']['index'], sprintf(
            'The machine record says the skills are indexed by [%s], which this checkout does not have.',
            $machine['skills']['index'],
        ));

        $pint = json_decode((string) file_get_contents(Handoff::root().'/pint.json'), true);

        self::assertIsArray($pint, 'pint.json is not readable to be compared with the machine record.');

        self::assertSame($pint['preset'] ?? null, $machine['style']['preset'], sprintf(
            'pint.json asks for the [%s] preset, and the machine record says [%s].',
            $pint['preset'] ?? '(none)',
            $machine['style']['preset'],
        ));

        $neon = (string) file_get_contents(Handoff::root().'/phpstan.neon.dist');

        self::assertSame(
            1,
            preg_match('/phpVersion:\s*(\S+)/', $neon, $version),
            'phpstan.neon.dist no longer states a phpVersion for the machine record to agree with.',
        );

        self::assertSame($version[1], $machine['staticAnalysis']['phpVersion'], sprintf(
            'phpstan.neon.dist floors the analysis at [%s], and the machine record says [%s].',
            $version[1],
            $machine['staticAnalysis']['phpVersion'],
        ));

        $ignored = Handoff::git('check-ignore', '--quiet', '.agents/machine.local.json');

        self::assertSame(
            0,
            $ignored['exit'],
            '.agents/machine.local.json is not gitignored, so the machine record would be committed with everything else.',
        );

        $attribute = Handoff::git('check-attr', 'export-ignore', '--', '.agents/machine.local.json');

        self::assertSame(0, $attribute['exit'], sprintf('git could not be asked about the machine record: %s', $attribute['error']));

        self::assertStringEndsWith(
            ': set',
            trim($attribute['output']),
            '.agents/machine.local.json is not export-ignored, so the machine record would ship in a consumer\'s tarball.',
        );

        $probe = array_merge(
            glob(Handoff::root().'/bin/*') ?: [],
            glob(Handoff::root().'/docs/*.md') ?: [],
        );
        $unread = [];

        foreach ($machine['envVars']['read'] as $name) {
            $found = false;

            foreach ($probe as $file) {
                if (is_file($file) && str_contains((string) file_get_contents($file), $name)) {
                    $found = true;

                    break;
                }
            }

            if (!$found) {
                $unread[] = $name;
            }
        }

        self::assertSame([], $unread, sprintf(
            'The machine record lists environment variables nothing in bin/ or docs/ reads: %s.',
            implode(', ', $unread),
        ));
    }

    /**
     * The machine record every test above compares against, or a skip when this checkout has
     * none — the file is gitignored and describes one machine, so a fresh clone and CI's runner
     * have nothing to compare rather than something wrong to compare against.
     *
     * @return array<string, mixed>
     */
    private static function machineRecord(): array
    {
        $machine = Handoff::machine();

        if ($machine === null) {
            self::markTestSkipped(
                '.agents/machine.local.json is not in this checkout, so section 5 has no machine record to be held '.
                'to here. Recreate it from machine.local.json.example to run this half of the guard.',
            );
        }

        return $machine;
    }

    /**
     * The version that is the largest of a list, which order of creation does not decide: `v0.1.0`
     * is newer than `v0.0.9-alpha1` whichever came first.
     *
     * @param list<string> $tags
     */
    private static function newest(array $tags): string
    {
        $newest = null;

        foreach ($tags as $tag) {
            if ($newest === null || version_compare(ltrim($tag, 'v'), ltrim($newest, 'v'), '>')) {
                $newest = $tag;
            }
        }

        self::assertNotNull($newest, 'This repository has no v* tag, so the note describes a lane that does not exist.');

        return $newest;
    }

    /**
     * What `git` says about one attribute of a list of paths, keyed by path — asked once for all of
     * them, because `.gitattributes` decides by pattern and the question is only asked to the tool
     * that reads it.
     *
     * @param list<string> $paths
     * @return array<string, string>
     */
    private static function attributes(array $paths): array
    {
        $run = Handoff::git('check-attr', 'export-ignore', ...$paths);

        self::assertSame(0, $run['exit'], sprintf('git could not be asked about the tarball: %s', $run['error']));

        $attributes = [];

        foreach (Handoff::outputLines($run['output']) as $line) {
            if (preg_match('/^(.*?): export-ignore: (.*)$/', $line, $matches) === 1) {
                $attributes[$matches[1]] = $matches[2];
            }
        }

        self::assertCount(
            count($paths),
            $attributes,
            sprintf('git answered about %d of the %d paths, so half of this comparison would have been against nothing.', count($attributes), count($paths)),
        );

        return $attributes;
    }

    /**
     * `phpstan.neon.dist` as it is configured: the level, and the paths the analysis covers.
     *
     * @return array{level: string, paths: list<string>}
     */
    private static function phpstanNeon(): array
    {
        $raw = @file_get_contents(Handoff::root().'/phpstan.neon.dist');

        self::assertIsString($raw, 'phpstan.neon.dist is not there to be compared with the note.');
        self::assertSame(1, preg_match('/^\s*level:\s*(\S+)\s*$/m', $raw, $level), 'phpstan.neon.dist no longer states a level.');

        $paths = [];
        $inside = false;

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^\s*paths:\s*$/', $line) === 1) {
                $inside = true;

                continue;
            }

            if (!$inside) {
                continue;
            }

            if (preg_match('/^\s*-\s*(\S+)\s*$/', $line, $matches) === 1) {
                $paths[] = trim($matches[1], '/');

                continue;
            }

            break;
        }

        self::assertNotSame([], $paths, 'phpstan.neon.dist no longer states the paths the analysis covers.');

        return ['level' => $level[1], 'paths' => $paths];
    }

    /**
     * A breakdown as the note writes one — `feat 14, fix 15` — so a failure quotes both sides the
     * way a reader would compare them.
     *
     * @param array<string, int> $counts
     */
    private static function written(array $counts): string
    {
        return implode(', ', array_map(
            static fn (string $type, int $count): string => $type.' '.$count,
            array_keys($counts),
            array_values($counts),
        ));
    }
}
