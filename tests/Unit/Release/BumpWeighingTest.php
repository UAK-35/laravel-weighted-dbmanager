<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

/**
 * How `bin/release.php --weigh` decides the bump from the changes.
 *
 * The policy is read off four signals — the Unreleased notes, the commits since the
 * last tag, the public surface of `src/` and `config/`, and the inventory the last
 * release wrote — and the loudest wins. Each test isolates one of them, because a
 * test that lets two signals say the same thing proves neither: the point of the
 * maximum is that any one of them alone can raise the bump.
 *
 * Every test drives the real script as a subprocess in a real git repository, with
 * real tags. That is the only level at which "weighed against the last tag" means
 * anything: the tag has to exist for git to describe it, and the commits have to be
 * commit-able for `git log` to read them.
 */
final class BumpWeighingTest extends TestCase
{
    /** An entry under `### Fixed` — the notes declaring a patch. */
    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    /** An entry under `### Added` — the notes declaring a new capability. */
    private const ADDED = "### Added\n\n- A command nobody could run before.\n";

    /** An entry under `### Removed` — the notes declaring a breaking change. */
    private const REMOVED = "### Removed\n\n- The old command, gone.\n";

    public function test_fixed_notes_weigh_a_patch(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v1.0.0');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('patch  (weighed: a patch change)', $run->plan('bump'), $run->describe());
        $this->assertSame('1.0.1  (tag v1.0.1)', $run->plan('next version'), $run->describe());
    }

    public function test_added_notes_weigh_a_minor(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('minor  (weighed: a minor change)', $run->plan('bump'), $run->describe());
        $this->assertSame('1.1.0  (tag v1.1.0)', $run->plan('next version'), $run->describe());
    }

    /**
     * The whole reason the weighing exists: `### Removed` is the notes declaring a
     * breaking change, and once there is a 1.0 line to break, that is a major.
     */
    public function test_removed_notes_weigh_a_major_once_there_is_a_one_point_oh_line(): void
    {
        $repo = ReleaseRepo::make(self::REMOVED);
        $repo->tag('v1.2.3');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('major  (weighed: a breaking change)', $run->plan('bump'), $run->describe());
        $this->assertSame('2.0.0  (tag v2.0.0)', $run->plan('next version'), $run->describe());
    }

    /**
     * The 0.x caveat: before 1.0 there is no compatibility promise to break, so a
     * breaking change is carried by a minor — and the plan says so, rather than
     * leaving the reader to wonder why a removal did not move the major.
     */
    public function test_removed_notes_are_softened_to_a_minor_before_one_point_oh(): void
    {
        $repo = ReleaseRepo::make(self::REMOVED);
        $repo->tag('v0.4.0');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(
            'minor  (weighed: a breaking change, which is a minor while the package is pre-1.0)',
            $run->plan('bump'),
            $run->describe(),
        );
        $this->assertSame('0.5.0  (tag v0.5.0)', $run->plan('next version'), $run->describe());
    }

    /**
     * The commit signal's job is to catch work nobody wrote a note for. The notes say
     * patch; a `feat` commit does not, and the louder of the two decides.
     */
    public function test_a_feat_commit_weighs_a_minor_the_notes_did_not_declare(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v1.0.0');

        $repo->write('README.md', "A mode nobody documented.\n");
        $repo->commit('feat: add a reader window nobody wrote up');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('minor  (weighed: a minor change)', $run->plan('bump'), $run->describe());
        $this->assertSame('1.1.0  (tag v1.1.0)', $run->plan('next version'), $run->describe());
    }

    /**
     * A `!` on the type is the strongest thing a commit can say, and it outranks the
     * notes — a breaking change reasoned about only in a commit message is still a
     * breaking change.
     */
    public function test_a_bang_commit_weighs_a_breaking_change(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v1.0.0');

        $repo->write('README.md', "The mode is gone.\n");
        $repo->commit('feat!: drop the reader window');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('major  (weighed: a breaking change)', $run->plan('bump'), $run->describe());
        $this->assertSame('2.0.0  (tag v2.0.0)', $run->plan('next version'), $run->describe());
    }

    /**
     * Before the first tag there is nothing to diff against — every symbol would read
     * as added — so the notes are the only honest signal, and the base is 0.0.0.
     */
    public function test_with_no_tag_the_notes_decide_the_first_version(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('minor  (weighed: a minor change)', $run->plan('bump'), $run->describe());
        $this->assertSame('0.1.0  (tag v0.1.0)', $run->plan('next version'), $run->describe());
        $this->assertTrue($run->said('no release tag yet'), $run->describe());
    }

    /**
     * A stale stamp cannot tell "nothing changed" from "not refreshed", so the file is
     * reported and skipped rather than trusted. Skipping is safe in exactly one
     * direction, which is the one a versioning signal must never be wrong in.
     */
    public function test_a_stale_inventory_is_reported_and_skipped(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        $repo->refreshInventory();
        $repo->restampInventory('v0.9.9');
        $repo->commit('chore: keep an inventory');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said('stale, refreshed by this release and not weighed'),
            $run->describe(),
        );
        $this->assertTrue(
            $run->said('stale — it describes v0.9.9, and this release is built on v0.1.0'),
            $run->describe(),
        );

        // Skipped means the notes still decide, and nothing was lowered by it.
        $this->assertSame('patch  (weighed: a patch change)', $run->plan('bump'), $run->describe());
        $this->assertSame('0.1.1  (tag v0.1.1)', $run->plan('next version'), $run->describe());
    }

    /**
     * The fourth signal's whole reason for existing, and the one case the tag diff
     * cannot cover: a fresh inventory remembers a public method the tree no longer
     * has, so a removal is *witnessed* rather than inferred.
     *
     * The notes say patch and no source file changed since the tag — the surface diff
     * is silent — so the breaking change comes from the inventory and nowhere else.
     */
    public function test_a_fresh_inventory_witnesses_a_removed_public_method(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        $repo->refreshInventory();
        $repo->inventPublicMethod("Fixture\\Thing", 'ghost');
        $repo->commit('chore: keep an inventory');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(
            'minor  (weighed: a breaking change, which is a minor while the package is pre-1.0)',
            $run->plan('bump'),
            $run->describe(),
        );
        $this->assertSame('0.2.0  (tag v0.2.0)', $run->plan('next version'), $run->describe());
        $this->assertTrue(
            $run->said('removed public method Fixture\Thing::ghost()'),
            $run->describe(),
        );
        // And it was weighed, not skipped: a stale one would have been ignored.
        $this->assertTrue($run->said('fresh, weighed against v0.1.0'), $run->describe());
    }

    /**
     * Nothing a signal may do: an inventory that is merely missing is not a reason to
     * change the version, and a release should not be blocked by a file that is
     * neither the version nor the tree.
     */
    public function test_a_missing_inventory_never_moves_the_bump(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v1.0.0');

        $this->assertFalse($repo->exists('files.tsv'));

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('patch  (weighed: a patch change)', $run->plan('bump'), $run->describe());
        $this->assertTrue($run->said('written by this release'), $run->describe());
    }
}
