<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

/**
 * The inventory generator on its own: `bin/inventory.php`, which the release script
 * runs and which a person is allowed to run by hand.
 *
 * The release tests cover the round trip — one release writes the files, the next
 * reads them — and none of them asks what the generator does when it is pointed at
 * a tree it no longer describes, which is the state it exists to detect. So these
 * are the three things a reader of `bin/inventory.php` has to trust: that `--check`
 * floors drift without touching a byte, that a rewrite which discards a recorded
 * change says so, and that the exit codes mean what the header says they mean.
 *
 * The fixture is `ReleaseRepo`, so the script under test is the real one, copied
 * into a package of its own and run with `PHP_BINARY`.
 */
final class InventoryTest extends TestCase
{
    /** A package whose inventory has just been written, so it is current. */
    private static function inventoried(): ReleaseRepo
    {
        $repo = ReleaseRepo::make();
        $repo->refreshInventory();

        return $repo;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // --check: drift it has to catch
    // ─────────────────────────────────────────────────────────────────────────

    public function test_check_accepts_an_inventory_that_describes_the_tree(): void
    {
        $repo = self::inventoried();
        $stamped = [$repo->read('files.tsv'), $repo->read('methods.tsv')];

        $run = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said('inventory is current — 2 files, 2 public methods, 3 key(s)/member(s), described as (no tag)'),
            $run->describe(),
        );

        // Nothing on stderr, and nothing written: the current case is a statement and
        // not a repair.
        $this->assertSame('', $run->error, $run->describe());
        $this->assertSame($stamped, [$repo->read('files.tsv'), $repo->read('methods.tsv')]);
    }

    public function test_check_reports_a_method_the_tree_no_longer_declares(): void
    {
        $repo = self::inventoried();
        $repo->write('src/Thing.php', self::thingWithoutWeight());

        $run = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('✗ The inventory is out of step with the tree:'), $run->describe());
        $this->assertTrue($run->refused('methods.tsv: 1 line(s) stale: 1 gone, 0 new'), $run->describe());
        $this->assertTrue($run->refused('gone: weight'), $run->describe());
        $this->assertTrue(
            $run->refused('Run php bin/inventory.php to write them and commit them'),
            $run->describe(),
        );

        // The file that is still right is not described: the verdict is about the pair,
        // and a reader sent to files.tsv would find nothing there.
        $this->assertFalse($run->refused('files.tsv'), $run->describe());

        // A check that repaired what it found would be worthless as a check.
        $this->assertStringContainsString('weight', $repo->read('methods.tsv'));
    }

    public function test_check_reports_a_source_file_the_inventory_has_never_seen(): void
    {
        $repo = self::inventoried();
        $repo->write('src/Extra.php', self::extra());

        $run = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('files.tsv: 1 line(s) stale'), $run->describe());
        $this->assertTrue($run->refused('new:  Extra.php | src/Extra.php | Fixture\Extra'), $run->describe());

        // The class brought a public method with it, so both files are out of step —
        // which is the one case where both are named.
        $this->assertTrue($run->refused('methods.tsv: 1 line(s) stale'), $run->describe());
        $this->assertTrue($run->refused('new:  describe | src/Extra.php | Fixture\Extra'), $run->describe());
    }

    public function test_check_catches_a_stamp_that_names_a_different_release(): void
    {
        $repo = self::inventoried();

        // Every row is right; only the tag in the header is not. That is the one
        // difference the file's whole safety property rests on, so it must never be
        // waved through as cosmetic.
        $repo->restampInventory('v9.9.9');

        $run = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('files.tsv: 1 line(s) stale: 1 gone, 1 new'), $run->describe());
        $this->assertTrue($run->refused('methods.tsv: 1 line(s) stale: 1 gone, 1 new'), $run->describe());
        $this->assertTrue($run->refused('gone: # files.tsv — generated by bin/inventory.php'), $run->describe());
    }

    public function test_check_reports_a_file_that_is_missing_rather_than_describing_it(): void
    {
        $repo = self::inventoried();
        unlink($repo->path('methods.tsv'));

        $run = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('methods.tsv is missing'), $run->describe());
        $this->assertFalse($run->refused('files.tsv'), $run->describe(), 'the file that is still right has nothing to report');
        $this->assertFalse($repo->exists('methods.tsv'), 'a check writes nothing, a missing file included');
    }

    /**
     * A file whose rows are exactly right but whose bytes are not — a stray blank line,
     * trailing space, a checkout that came back CRLF. It is out of step, so `--check`
     * says so; but it is not *wrong*, and the report has to say which of the two it is
     * rather than leaving a reader to diff the file.
     */
    public function test_check_tells_a_stray_blank_line_from_a_lost_row(): void
    {
        $repo = self::inventoried();
        $repo->write('files.tsv', $repo->read('files.tsv') . "\n");

        $run = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('the rows are right but the bytes are not'),
            $run->describe(),
        );
        $this->assertFalse($run->refused('line(s) stale'), $run->describe());
        $this->assertFalse($run->refused('methods.tsv'), $run->describe(), 'the other file is untouched by this');
    }

    /**
     * The file the comparison normalises away: a CRLF checkout of an inventory whose
     * rows are right is *current*, not something to rewrite. It is pinned to LF in
     * `.gitattributes`, but a Windows checkout can still hand one back with carriage
     * returns, and a checker that reacted to that would report drift on every machine
     * with a different default.
     */
    public function test_check_accepts_carriage_returns_it_would_write_away_anyway(): void
    {
        $repo = self::inventoried();
        $repo->write('files.tsv', str_replace("\n", "\r\n", $repo->read('files.tsv')));

        $run = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('inventory is current'), $run->describe());
    }

    /**
     * Two of the three gone, which is the state a checkout that never had them is in: neither
     * is described, because there is nothing to describe, and both are named beside the file
     * that is still there.
     *
     * The report loops the set rather than stopping at the first, for the same reason the
     * verdict is about the set: an operator who is told about `files.tsv` and writes it is
     * told about `methods.tsv` by the next run instead.
     */
    public function test_check_names_every_inventory_file_that_is_not_there(): void
    {
        $repo = self::inventoried();

        unlink($repo->path('files.tsv'));
        unlink($repo->path('methods.tsv'));

        $run = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('files.tsv is missing'), $run->describe());
        $this->assertTrue($run->refused('methods.tsv is missing'), $run->describe());
        $this->assertSame(2, $run->occurrences('is missing', true), $run->describe());
        $this->assertFalse($run->refused('surface.tsv'), $run->describe(), 'the file that is still right has nothing to report');
        $this->assertFalse($repo->exists('files.tsv'), 'a check writes nothing, a missing file included');
        $this->assertFalse($repo->exists('methods.tsv'), $run->describe());
    }

    /**
     * The one rule of the drift report that is about the report rather than about the file:
     * a row is cut at 68 characters and ends in an ellipsis. A signature is not something to
     * paste into a terminal, and a report that printed one whole would wrap past reading.
     *
     * A row no source backs is the only way to reach the cut — every row in this fixture is
     * short, and the two cases together are the boundary: `weight` above is printed entire,
     * this one is not. The expected text is read back out of the file rather than restated,
     * because the row is the fixture's own and only its length matters here.
     */
    public function test_check_cuts_a_row_that_is_too_long_to_print(): void
    {
        $repo = self::inventoried();

        $method = str_repeat('m', 70);
        $repo->inventPublicMethod('Fixture\Ghost', $method);

        $run = $repo->scriptRun('inventory.php', '--check');

        $rows = array_values(array_filter(
            explode("\n", $repo->read('methods.tsv')),
            static fn (string $line): bool => str_contains($line, $method),
        ));

        $printable = str_replace("\t", ' | ', rtrim($rows[0], "\r"));

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('gone: ' . substr($printable, 0, 67) . '…'), $run->describe());
        $this->assertFalse($run->refused($printable), 'the whole row is what the cut exists to keep out of the report');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // surface.tsv: the rows that do not need a tag
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The third file, and the rows it is for: everything a consumer can name that is not
     * a file and not a method — a config key, a public constant, a public property. They
     * are the half of the inventory that witnesses a removal on a tree with no tag at all,
     * because the public-API signal diffs two tags and a tree with one has nothing for it
     * to see a removal in.
     *
     * The property in the fixture is typed, which is worth stating: a type name sits between
     * the visibility and the variable, and the reader used to stop walking at it, so every
     * property that declared one was invisible — to this file and to the tag diff both.
     */
    public function test_the_surface_rows_record_the_keys_and_members_a_tag_diff_cannot(): void
    {
        $repo = self::inventoried();
        $surface = $repo->read('surface.tsv');

        $this->assertStringContainsString('describes the tree at (no tag)', $surface);
        $this->assertStringContainsString("config\tenabled\tconfig/sample.php", $surface);
        $this->assertStringContainsString("const\tFixture\\Thing::VERSION\tsrc/Thing.php", $surface);
        $this->assertStringContainsString("property\tFixture\\Thing::\$label\tsrc/Thing.php", $surface);

        // One row per line, in the columns the header names, like the other two files.
        $this->assertStringContainsString("# kind\tsymbol\tfile", $surface);
        $this->assertCount(3, array_slice(explode("\n", trim($surface)), 2));
    }

    /**
     * The drift the file exists to catch: a constant the tree no longer declares. The rows
     * are named the way the reader spells them, so the report reads as the removal rather
     * than as a line that moved.
     */
    public function test_check_reports_a_constant_the_tree_no_longer_declares(): void
    {
        $repo = self::inventoried();
        $repo->dropClassConstant();

        $run = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('surface.tsv: 1 line(s) stale: 1 gone, 0 new'), $run->describe());
        $this->assertTrue(
            $run->refused('gone: const | Fixture\Thing::VERSION | src/Thing.php'),
            $run->describe(),
        );

        // The other two files are untouched by it: a constant is nothing to files.tsv or
        // methods.tsv, which is why the file it lives in has to be noticed separately.
        $this->assertFalse($run->refused('files.tsv'), $run->describe());
        $this->assertFalse($run->refused('methods.tsv'), $run->describe());
    }

    /**
     * The same drift one key over: a config key is what an installation sets, so a removed
     * one breaks every installation that sets it, and it is the row a consumer is most
     * likely to notice only after upgrading.
     */
    public function test_check_reports_a_config_key_the_file_no_longer_returns(): void
    {
        $repo = self::inventoried();
        $repo->dropConfigKey('enabled');

        $run = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('gone: config | enabled | config/sample.php'),
            $run->describe(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The root it is pointed at
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `--root=PATH` is the second documented use — "run against another checkout" — and it
     * is the only argument that moves which tree is read and written. The default is the
     * parent of `bin/`, which is the package the script happens to live in, so pointing it
     * somewhere else has to leave that package alone entirely.
     *
     * The path is passed with a trailing slash because the argument trims one rather than
     * requiring its absence: a tab-completed path arrives with it.
     */
    public function test_root_writes_into_the_package_it_names_and_leaves_its_own_alone(): void
    {
        $repo = ReleaseRepo::make();
        $other = ReleaseRepo::make();

        $run = $repo->scriptRun('inventory.php', '--root=' . $other->path('') . '/');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($other->exists('files.tsv'), $run->describe());
        $this->assertTrue($other->exists('methods.tsv'), $run->describe());
        $this->assertTrue($run->said('files.tsv — 2 files, described as (no tag)'), $run->describe());

        // The stamp is read from the tree that was named, and this one has never been
        // tagged either, so both fixtures would produce these rows — which is why the
        // files are asserted rather than only the counts.
        $this->assertFalse($repo->exists('files.tsv'), 'the package the script lives in is not the one it was pointed at');
        $this->assertFalse($repo->exists('methods.tsv'), $run->describe());
    }

    /**
     * The check follows the root as well, and the two halves are what make it a test of the
     * argument rather than of the write: the same run against the package the script lives
     * in is out of step — it has no inventory at all — while the root it was pointed at is
     * current, and then reports the drift that root has.
     */
    public function test_check_follows_the_root_it_is_given_rather_than_the_script_s_own(): void
    {
        $repo = ReleaseRepo::make();
        $other = ReleaseRepo::make();

        $repo->script('inventory.php', '--root=' . $other->path(''));

        $here = $repo->scriptRun('inventory.php', '--check');
        $there = $repo->scriptRun('inventory.php', '--check', '--root=' . $other->path(''));

        $this->assertSame(1, $here->exitCode, $here->describe());
        $this->assertTrue($here->refused('files.tsv is missing'), $here->describe());
        $this->assertSame(0, $there->exitCode, $there->describe());
        $this->assertTrue($there->said('inventory is current'), $there->describe());

        $other->write('src/Extra.php', self::extra());

        $drifted = $repo->scriptRun('inventory.php', '--check', '--root=' . $other->path(''));

        $this->assertSame(1, $drifted->exitCode, $drifted->describe());
        $this->assertTrue($drifted->refused('new:  Extra.php | src/Extra.php | Fixture\Extra'), $drifted->describe());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A rewrite, and the record it discards
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_write_stamps_the_latest_tag_and_says_what_to_stage(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v0.1.0');

        $run = $repo->scriptRun('inventory.php');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('files.tsv — 2 files, described as v0.1.0'), $run->describe());
        $this->assertTrue($run->said('methods.tsv — 2 public methods'), $run->describe());
        $this->assertTrue($run->said('stage them with: git add -- files.tsv methods.tsv'), $run->describe());

        $this->assertStringContainsString('describes the tree at v0.1.0', $repo->read('files.tsv'));
        $this->assertStringContainsString('describes the tree at v0.1.0', $repo->read('methods.tsv'));
    }

    public function test_a_first_write_has_no_record_to_discard(): void
    {
        $repo = ReleaseRepo::make();

        $run = $repo->scriptRun('inventory.php');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertFalse($run->said('discarded'), $run->describe());
    }

    /**
     * A pair that is half there: `files.tsv` written, `methods.tsv` not — the state an
     * interrupted release leaves, because the two are written by one call and only the
     * second can fail. The rewrite completes the pair and says nothing about discarding.
     *
     * The file that is not there recorded nothing, and diffing a side that was never read
     * would report every row of the other side as a change this run is about to lose. Both
     * halves are asserted: quiet about the record, and loud about the rows it did write.
     */
    public function test_a_pair_that_is_half_there_discards_nothing_it_did_not_read(): void
    {
        $repo = self::inventoried();
        unlink($repo->path('methods.tsv'));

        $run = $repo->scriptRun('inventory.php');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertFalse($run->said('discarded'), $run->describe());
        $this->assertTrue($run->said('methods.tsv — 2 public methods'), $run->describe());
        $this->assertTrue($repo->exists('methods.tsv'), 'the pair is completed rather than left half there');
    }

    /**
     * The ordinary case, and the one that has to stay quiet: a rewrite whose rows the
     * tree still agrees with discards nothing, so warning would be crying wolf about
     * the tool's own output.
     */
    public function test_rewriting_a_current_inventory_says_nothing_about_discarding(): void
    {
        $repo = self::inventoried();

        $run = $repo->scriptRun('inventory.php');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertFalse($run->said('discarded'), $run->describe());
    }

    public function test_a_rewrite_that_discards_a_recorded_change_says_so(): void
    {
        $repo = self::inventoried();

        // The tree drops a method the last inventory recorded. Rewriting now forgets
        // that it was ever there — which is the one thing a hand regeneration can do
        // that a release cannot, and the reason it is worth a line.
        $repo->write('src/Thing.php', self::thingWithoutWeight());

        $run = $repo->scriptRun('inventory.php');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('this rewrite discarded the record of 1 change(s):'), $run->describe());
        $this->assertTrue($run->said('      • removed public method Fixture\Thing::weight()'), $run->describe());
        $this->assertTrue(
            $run->said('the release script is the intended writer: it refreshes all three files in the release commit'),
            $run->describe(),
        );

        // Reported, not refused: the tree is the authority and the write goes ahead, so
        // the record is gone. Saying so is the whole of the remedy.
        $this->assertStringNotContainsString('weight', $repo->read('methods.tsv'));
    }

    public function test_more_than_three_discarded_changes_are_counted_rather_than_listed(): void
    {
        $repo = self::inventoried();

        // Five rows the tree does not back. A rewrite can only lose them by writing
        // over them, and a report that printed all five would be a report nobody reads.
        foreach (['one', 'two', 'three', 'four', 'five'] as $method) {
            $repo->inventPublicMethod('Fixture\Ghost', $method);
        }

        $run = $repo->scriptRun('inventory.php');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('this rewrite discarded the record of 5 change(s):'), $run->describe());
        $this->assertSame(3, $run->occurrences('      • '), $run->describe());
        $this->assertTrue($run->said('      … and 2 more'), $run->describe());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // --at: the tree a ref holds, rather than the checkout
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The flag's whole reason: the rows come from the tree git holds for the ref, not from
     * the files in front of the person running it. The fixture's checkout is left dirty on
     * purpose — a file the tag never had, and a constant it did — because a checkout is
     * exactly what a hand regeneration would have read, and a record that describes one tree
     * while naming another is the mistake every rule about a stamp exists to refuse.
     *
     * All three files are asserted: the ref is the tree for the rows as well as for the
     * stamp, `surface.tsv` included — which is the file this package's own history needed
     * backfilling for, since `v0.2.0-alpha1` was cut before it existed.
     */
    public function test_at_reads_the_tree_the_ref_holds_rather_than_the_checkout(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v0.1.0');
        $repo->write('src/Extra.php', self::extra());
        $repo->dropClassConstant();

        $run = $repo->scriptRun('inventory.php', '--at=v0.1.0');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said('reading the tree at v0.1.0 out of git, not the checkout'),
            $run->describe(),
        );
        $this->assertTrue($run->said('files.tsv — 2 files, described as v0.1.0'), $run->describe());

        $this->assertStringContainsString('describes the tree at v0.1.0', $repo->read('files.tsv'));
        $this->assertStringNotContainsString('Extra.php', $repo->read('files.tsv'), 'the checkout\'s file is not part of the tag');
        $this->assertStringNotContainsString('src/Extra.php', $repo->read('methods.tsv'), 'nor the method it declares');

        // The constant the checkout dropped is still a row, because the tag still declares
        // it: the third file is read from the ref like the other two.
        $this->assertStringContainsString(
            "const\tFixture\\Thing::VERSION\tsrc/Thing.php",
            $repo->read('surface.tsv'),
        );
    }

    /**
     * The read-only half, and the pair of assertions that make it a test of `--at` rather
     * than of the checker: one record, two trees, and the verdict follows the tree that was
     * named. `--at` is the only way to ask about a tree the checkout is no longer.
     */
    public function test_check_at_compares_the_record_with_the_tree_the_ref_holds(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v0.1.0');
        $repo->write('src/Extra.php', self::extra());
        $repo->script('inventory.php', '--at=v0.1.0');

        $at = $repo->scriptRun('inventory.php', '--check', '--at=v0.1.0');
        $here = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(0, $at->exitCode, $at->describe());
        $this->assertTrue(
            $at->said('inventory is current — 2 files, 2 public methods, 3 key(s)/member(s), described as v0.1.0'),
            $at->describe(),
        );

        $this->assertSame(1, $here->exitCode, $here->describe());
        $this->assertTrue($here->refused('new:  Extra.php | src/Extra.php | Fixture\\Extra'), $here->describe());
    }

    /**
     * Two sentences that would otherwise be wrong about which tree was read: the drift
     * header, and the line that says how to write the rows. "The inventory is out of step
     * with the tree" is true of the checkout and not of the ref, and a remedy that named
     * the script without the flag would write the other tree's rows — the one repair the
     * reader must not be handed by mistake.
     */
    public function test_check_at_names_the_tree_and_the_flag_that_would_write_it(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v0.1.0');
        $repo->script('inventory.php', '--at=v0.1.0');
        $repo->restampInventory('v9.9.9');

        $run = $repo->scriptRun('inventory.php', '--check', '--at=v0.1.0');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('✗ The inventory is out of step with the tree at v0.1.0:'),
            $run->describe(),
        );
        $this->assertTrue(
            $run->refused('Run php bin/inventory.php --at=v0.1.0 to write them and commit them'),
            $run->describe(),
        );
    }

    /**
     * One inventory is kept, and it is the one the next weighing reads — so a record that
     * describes another release is refused rather than written over, and the refusal names
     * both releases rather than leaving the reader to work out which pair disagreed.
     *
     * The harm is quiet, which is why it is a refusal: a substituted record is not an error
     * anybody sees, it is a second opinion that stops being weighed. `--force` is the escape
     * for the one case a person means it, and the file it writes is the ref's own.
     */
    public function test_at_refuses_to_write_over_the_record_of_another_release(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v0.1.0');
        $repo->write('src/Extra.php', self::extra());
        $repo->commit('feat: a file v0.1.0 never had');
        $repo->tag('v0.2.0');
        $repo->script('inventory.php');

        $stamped = [$repo->read('files.tsv'), $repo->read('methods.tsv'), $repo->read('surface.tsv')];

        $run = $repo->scriptRun('inventory.php', '--at=v0.1.0');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('✗ Nothing was written: the record on disk describes v0.2.0, and this run describes v0.1.0.'),
            $run->describe(),
        );
        $this->assertTrue(
            $run->refused('files.tsv (v0.2.0), methods.tsv (v0.2.0), surface.tsv (v0.2.0)'),
            $run->describe(),
        );
        $this->assertTrue($run->refused('--force writes this ref\'s rows anyway'), $run->describe());

        // Refused is the whole of it: a refusal that had already written would have lost the
        // record it was protecting.
        $this->assertSame($stamped, [$repo->read('files.tsv'), $repo->read('methods.tsv'), $repo->read('surface.tsv')]);

        $forced = $repo->scriptRun('inventory.php', '--at=v0.1.0', '--force');

        $this->assertSame(0, $forced->exitCode, $forced->describe());
        $this->assertStringContainsString('describes the tree at v0.1.0', $repo->read('files.tsv'));
        $this->assertStringNotContainsString('Extra.php', $repo->read('files.tsv'));
    }

    /**
     * The state an interrupted release leaves, reached here through a ref: one file gone, and
     * the two that are there stamped with the release this run describes. Nothing is refused
     * and nothing is described as discarded, because a file that is not there recorded nothing
     * to lose — the same rule a plain run follows, now stated for the writer that reads git.
     *
     * It is also this package's own upgrade state, one tag older: an inventory written before
     * `surface.tsv` existed is two files and a stamp, and the refusal must not stand in the
     * way of completing it.
     */
    public function test_at_completes_a_record_that_is_half_there_when_the_stamp_agrees(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v0.1.0');
        $repo->script('inventory.php', '--at=v0.1.0');
        unlink($repo->path('surface.tsv'));

        $run = $repo->scriptRun('inventory.php', '--at=v0.1.0');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertFalse($run->said('discarded'), $run->describe());
        $this->assertTrue($repo->exists('surface.tsv'), 'the set is completed rather than left half there');
    }

    /**
     * A ref is any ref: the same commit named by a sha rather than by its tag reads the same
     * rows and is stamped with the same release. The stamp says what the tree *was*, not how
     * the caller happened to name it — so a backfill is not a different record depending on
     * whether the tag or the commit was typed.
     */
    public function test_at_stamps_a_commit_that_carries_a_tag_with_its_release(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v0.1.0');
        $repo->write('src/Extra.php', self::extra());
        $repo->commit('feat: a file v0.1.0 never had');

        $sha = trim($repo->git('rev-parse', 'HEAD~1'));

        $run = $repo->scriptRun('inventory.php', '--at=' . $sha);

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('reading the tree at ' . $sha . ' out of git'), $run->describe());
        $this->assertTrue($run->said('files.tsv — 2 files, described as v0.1.0'), $run->describe());
    }

    /**
     * A commit no tag names is stamped `(no tag)` — the word the working-tree reader uses,
     * so the rows are still comparable — and *not* with the repository's latest tag, which is
     * what a run against the checkout would stamp. That is the whole difference between the
     * two readers: the tree and the stamp come from the same side, and the ref's own tag is
     * the only release either of them may name.
     */
    public function test_at_stamps_a_commit_no_release_names_with_no_tag(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v0.1.0');
        $repo->write('src/Extra.php', self::extra());
        $repo->commit('feat: a file v0.1.0 never had');

        $write = $repo->scriptRun('inventory.php', '--at=HEAD');

        $this->assertSame(0, $write->exitCode, $write->describe());
        $this->assertTrue($write->said('files.tsv — 3 files, described as (no tag)'), $write->describe());
        $this->assertStringContainsString('describes the tree at (no tag)', $repo->read('files.tsv'));

        $check = $repo->scriptRun('inventory.php', '--check', '--at=HEAD');

        $this->assertSame(0, $check->exitCode, $check->describe());
        $this->assertTrue($check->said('described as (no tag)'), $check->describe());

        // And the same bytes are out of step with the checkout, whose stamp is the latest
        // tag: the two runs are two questions about one file.
        $here = $repo->scriptRun('inventory.php', '--check');

        $this->assertSame(1, $here->exitCode, $here->describe());
        $this->assertTrue($here->refused('✗ The inventory is out of step with the tree:'), $here->describe());
    }

    /**
     * A ref this repository does not have is answered before the tree is read, because an
     * unresolvable ref reads as a tree with no files in it — and writing that as an empty
     * inventory under a tag's name is the one outcome worse than a refusal. git's own words
     * are carried into the message, since "not a repository" and "no such ref" are the same
     * exit code and not the same problem.
     */
    public function test_at_a_ref_this_repository_does_not_have_is_an_exit_one(): void
    {
        $repo = self::inventoried();
        $stamped = [$repo->read('files.tsv'), $repo->read('methods.tsv')];

        $run = $repo->scriptRun('inventory.php', '--at=v9.9.9');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('✗ Nothing to read: v9.9.9 is not a ref this repository has'),
            $run->describe(),
        );
        $this->assertFalse($run->said('reading the tree at'), $run->describe(), 'nothing is read once the ref is refused');
        $this->assertSame($stamped, [$repo->read('files.tsv'), $repo->read('methods.tsv')]);
    }

    /**
     * The flags that cannot mean what they say, refused the way an unknown option is: `--at=`
     * names no ref, and `--force` is about a run that writes — so it is a no-op without `--at`
     * (a plain run rewrites the record of the tree it stamps) and a no-op under `--check`
     * (which compares and stops). A `--force` that quietly did nothing would read as one that
     * did, and it is the flag a reader reaches for when they mean to replace a record.
     */
    public function test_a_flag_that_cannot_mean_anything_is_a_usage_error(): void
    {
        $repo = self::inventoried();

        $empty = $repo->scriptRun('inventory.php', '--at=');
        $lone = $repo->scriptRun('inventory.php', '--force');
        $audit = $repo->scriptRun('inventory.php', '--check', '--at=v0.1.0', '--force');

        $this->assertSame(2, $empty->exitCode, $empty->describe());
        $this->assertTrue($empty->refused('--at= needs a ref: a tag, a branch or a commit.'), $empty->describe());
        $this->assertTrue($empty->said('Usage:'), $empty->describe());

        $this->assertSame(2, $lone->exitCode, $lone->describe());
        $this->assertTrue($lone->refused('--force is about a run that writes: it needs --at=REF'), $lone->describe());
        $this->assertTrue($lone->said('Usage:'), $lone->describe());

        $this->assertSame(2, $audit->exitCode, $audit->describe());
        $this->assertTrue($audit->refused('--force is about a run that writes'), $audit->describe());

        $this->assertFalse($empty->said('files.tsv — '), $empty->describe());
        $this->assertFalse($lone->said('files.tsv — '), 'a usage error is not a write either');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Exit codes
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_unknown_option_is_a_usage_error_and_exits_two(): void
    {
        $repo = self::inventoried();

        $run = $repo->scriptRun('inventory.php', '--nope');

        $this->assertSame(2, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Unknown option: --nope'), $run->describe());
        $this->assertTrue($run->said('Usage:'), $run->describe());
    }

    /**
     * The arguments are read in the order they arrive and the first wrong one stops the run,
     * so a valid option in front of an unknown one does not make the run something else: it
     * compared nothing, which is why `--check`'s exit 1 is not the answer here, and a script
     * that could not read its arguments has not earned the right to write either.
     */
    public function test_an_unknown_option_after_a_valid_one_still_exits_two(): void
    {
        $repo = self::inventoried();
        $stamped = $repo->read('files.tsv');

        $run = $repo->scriptRun('inventory.php', '--check', '--nope');

        $this->assertSame(2, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Unknown option: --nope'), $run->describe());
        $this->assertSame($stamped, $repo->read('files.tsv'), 'a usage error is not a write either');
    }

    public function test_help_exits_zero_and_writes_nothing(): void
    {
        $repo = ReleaseRepo::make();

        $run = $repo->scriptRun('inventory.php', '--help');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('php bin/inventory.php [options]'), $run->describe());
        $this->assertFalse($repo->exists('files.tsv'), '--help is not a write either');
        $this->assertSame('', $run->error, $run->describe());
    }

    /** The short alias, which the usage text names in the same breath as the long one. */
    public function test_the_short_help_alias_is_the_same_run(): void
    {
        $repo = ReleaseRepo::make();

        $run = $repo->scriptRun('inventory.php', '-h');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('php bin/inventory.php [options]'), $run->describe());
        $this->assertSame('', $run->error, $run->describe());
        $this->assertFalse($repo->exists('files.tsv'), '-h is not a write either');
    }

    public function test_a_root_with_no_src_is_not_a_package_and_exits_one(): void
    {
        $repo = self::inventoried();

        // A real directory that is not a package root: the check is the shape of the
        // tree, so it holds for any path a script is pointed at.
        $run = $repo->scriptRun('inventory.php', '--root=' . $repo->path('config'));

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Not a package root — no src/ under'), $run->describe());
        $this->assertTrue($run->refused(str_replace('\\', '/', $repo->path('config'))), $run->describe());
    }

    public function test_a_file_that_cannot_be_written_is_an_exit_one(): void
    {
        $repo = ReleaseRepo::make();

        // `files.tsv` as a directory: the path is taken by something that cannot hold
        // the bytes, which is the closest a test can get to a permission failure on both
        // platforms at once.
        mkdir($repo->path('files.tsv'));

        $run = $repo->scriptRun('inventory.php');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('✗ Could not write files.tsv / methods.tsv / surface.tsv.'), $run->describe());

        // Neither file is left half-written: the set is promised together, so the
        // second is not attempted once the first has failed.
        $this->assertFalse($repo->exists('methods.tsv'), 'the second file is not written when the first cannot be');
    }

    /**
     * The mirror of the case above, and the one that leaves the tree in a state a reader has
     * to be told about: the first file is written and the second is not. The pair is written
     * in order — one call, two writes — so `files.tsv` on disk is the written one.
     *
     * The message names both files whether one failed or both did, because the pair is one
     * artifact: naming the one that failed would send a reader to run the script again to
     * find out what is on disk.
     */
    public function test_a_second_file_that_cannot_be_written_is_an_exit_one(): void
    {
        $repo = ReleaseRepo::make();

        mkdir($repo->path('methods.tsv'));

        $run = $repo->scriptRun('inventory.php');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('✗ Could not write files.tsv / methods.tsv / surface.tsv.'), $run->describe());
        $this->assertTrue($repo->exists('files.tsv'), $run->describe(), 'the first write is not undone by the second failing');
        $this->assertTrue(is_dir($repo->path('methods.tsv')), $run->describe());
    }

    /**
     * The fixture's class with one method gone — and everything else it declares left in
     * place, so the change these tests are about is one row rather than the three a whole
     * class replaced would be.
     */
    private static function thingWithoutWeight(): string
    {
        return <<<'PHP'
        <?php

        namespace Fixture;

        class Thing
        {
            public const VERSION = '1';

            public string $label = 'thing';

            public function label(): string
            {
                return 'thing';
            }
        }
        PHP . "\n";
    }

    private static function extra(): string
    {
        return <<<'PHP'
        <?php

        namespace Fixture;

        class Extra
        {
            public function describe(): string
            {
                return 'extra';
            }
        }
        PHP . "\n";
    }
}
