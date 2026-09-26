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
            $run->said('inventory is current — 2 files, 2 public methods, described as (no tag)'),
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
            $run->said('the release script is the intended writer: it refreshes both files in the release commit'),
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

    public function test_help_exits_zero_and_writes_nothing(): void
    {
        $repo = ReleaseRepo::make();

        $run = $repo->scriptRun('inventory.php', '--help');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('php bin/inventory.php [options]'), $run->describe());
        $this->assertFalse($repo->exists('files.tsv'), '--help is not a write either');
        $this->assertSame('', $run->error, $run->describe());
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
        $this->assertTrue($run->refused('✗ Could not write files.tsv / methods.tsv.'), $run->describe());

        // Nothing half-written: the failure is reported before either file is promised,
        // and the pair is written by the same call.
        // Neither file is left half-written: the pair is promised together, so the
        // second is not attempted once the first has failed.
        $this->assertFalse($repo->exists('methods.tsv'), 'the second file is not written when the first cannot be');
    }

    private static function thingWithoutWeight(): string
    {
        return <<<'PHP'
        <?php

        namespace Fixture;

        class Thing
        {
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
