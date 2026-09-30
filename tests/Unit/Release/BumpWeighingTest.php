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
     * Every heading the policy names, in one table.
     *
     * The three rungs have a case each above, because each is a rung. The other three rows —
     * `### Changed`, `### Deprecated` and `### Security` — were exercised by nothing at all:
     * changing one to another severity left every test in the suite passing, and this table is
     * the policy's own statement of what a heading is worth, so a row nothing reads is a
     * severity that can be edited by accident. The base is `0.4.0` rather than a 1.0 line so
     * that `### Removed` appears as the 0.x caveat makes it, which keeps the table about the
     * headings rather than about the caveat.
     */
    public function test_the_notes_table_weighs_every_heading_the_policy_names(): void
    {
        $expected = [
            'Fixed' => ['patch', '0.4.1'],
            'Added' => ['minor', '0.5.0'],
            'Changed' => ['minor', '0.5.0'],
            'Deprecated' => ['minor', '0.5.0'],
            'Removed' => ['minor', '0.5.0'],
            'Security' => ['patch', '0.4.1'],
        ];

        foreach ($expected as $heading => [$bump, $version]) {
            $repo = ReleaseRepo::make("### {$heading}\n\n- One entry under this heading.\n");
            $repo->tag('v0.4.0');

            $run = $repo->release('--weigh', '--dry-run');

            $this->assertSame(0, $run->exitCode, "### {$heading}: " . $run->describe());
            $this->assertStringStartsWith($bump, $run->plan('bump'), "### {$heading}: " . $run->describe());
            $this->assertSame(
                $version . '  (tag v' . $version . ')',
                $run->plan('next version'),
                "### {$heading}: " . $run->describe(),
            );
        }
    }

    /**
     * A changelog that marks its release as breaking is believed, whatever headings it uses.
     *
     * The heading vocabulary is the usual way a note declares a change, and it is not the only
     * one: `### Breaking changes` is a heading of its own in plenty of files, and `BREAKING` in
     * the body is the marker a changelog may use in place of one. Both are the loudest thing
     * the notes are able to say, so they weigh what `### Removed` weighs — and they are heeded
     * with the rest of the section in view, because the marker is a claim about the release
     * rather than about one entry in it.
     */
    public function test_notes_marked_breaking_weigh_a_major_whatever_heading_they_use(): void
    {
        $marked = [
            "### Breaking changes\n\n- The old command is gone.\n",
            "### Fixed\n\n- A ported defect, fixed.\n\nBREAKING: the old command is gone.\n",
        ];

        foreach ($marked as $notes) {
            $repo = ReleaseRepo::make($notes);
            $repo->tag('v1.2.3');

            $run = $repo->release('--weigh', '--dry-run');

            $this->assertSame(0, $run->exitCode, $run->describe());
            $this->assertSame('major  (weighed: a breaking change)', $run->plan('bump'), $run->describe());
            $this->assertSame('2.0.0  (tag v2.0.0)', $run->plan('next version'), $run->describe());
            $this->assertTrue($run->said('the notes are marked breaking'), $run->describe());
        }
    }

    /**
     * Notes in a vocabulary the policy does not know weigh a patch, and the plan says which of
     * the two things it is looking at.
     *
     * An empty section and a section full of headings the policy has never heard of are the
     * same weight and not the same state: the first is a release with nothing to publish, the
     * second is notes this policy cannot read a severity out of. Reporting them as one thing
     * sends an author looking for entries that are already written.
     */
    public function test_notes_in_a_vocabulary_the_policy_does_not_know_weigh_a_patch_and_say_so(): void
    {
        $repo = ReleaseRepo::make("### Notes\n\n- Something happened, under a heading of our own.\n");
        $repo->tag('v1.2.3');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('patch  (weighed: a patch change)', $run->plan('bump'), $run->describe());
        $this->assertSame('1.2.4  (tag v1.2.4)', $run->plan('next version'), $run->describe());
        $this->assertTrue(
            $run->said('the Unreleased section has no `###` heading the policy knows'),
            $run->describe(),
        );
        $this->assertTrue(
            $run->said('not a Keep a Changelog heading, so read as a patch: ### Notes'),
            $run->describe(),
        );
    }

    /**
     * The surface signal is two halves — the public API of `src/` and the keys of `config/` —
     * and a dropped config key is a breaking change the public API half cannot see.
     *
     * Nothing else covers it: the tests that drop a key are about `bin/inventory.php --check`,
     * and every case where the surface moves the bump does it through `src/`. A config key is
     * the thing a consumer *sets* rather than imports, so it is also the change with no
     * compile error to announce it — which is why the row is written down at all. The notes
     * stay a patch here, so the severity in the plan is the config half's own and not a note
     * the author happened to file under `### Removed`.
     */
    public function test_a_dropped_config_key_is_the_breaking_change_the_public_api_cannot_see(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.4.0');
        $repo->dropConfigKey('enabled');
        $repo->commit('feat!: the enabled key is gone');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said('removed config key \'enabled\''),
            "The config half has to name the key it lost.\n" . $run->describe(),
        );

        // The severity as the plan prints it, not just the evidence line: a removal read as a
        // minor would still name the key and still leave the same bump behind it, since the
        // notes and the caveat are the other half of it.
        $this->assertMatchesRegularExpression(
            '/^\s+breaking\s+config\s+1 change\(s\) to the surface since the last tag\r?$/m',
            $run->output,
            "The config half has to be the breaking one.\n" . $run->describe(),
        );
        $this->assertMatchesRegularExpression(
            '/^\s+patch\s+CHANGELOG\s+### Fixed — 1 entry, the loudest heading the notes use\r?$/m',
            $run->output,
            "The notes stay a patch, so the breaking severity is the config half's own.\n" . $run->describe(),
        );

        $this->assertSame(
            'minor  (weighed: a breaking change, which is a minor while the package is pre-1.0)',
            $run->plan('bump'),
            $run->describe(),
        );
        $this->assertSame('0.5.0  (tag v0.5.0)', $run->plan('next version'), $run->describe());
    }

    /**
     * The surface keys a name once, so two files declaring one key is one entry in it — and
     * the file that loses is a file no surface signal reads. That is the difference the note
     * exists for: the plan says `nothing removed, renamed or added` about the config half
     * while a key has gone from one of the two files that declared it, and only the note
     * tells a reader that the silence is not the same silence as "nothing moved".
     *
     * The key is dropped rather than added on purpose. An addition leaves a name the surface
     * has never held, which it reports; a drop leaves a name the other file still declares,
     * which it cannot.
     */
    public function test_a_key_two_files_declare_is_the_change_no_surface_signal_can_see(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->writeConfig(['enabled', 'only_here']);
        $repo->duplicateConfigKey('enabled');
        $repo->commit('chore: a second config file returns the same key');
        $repo->tag('v0.4.0');

        // `enabled` goes from config/sample.php while config/extra.php still returns it, so
        // the name is in the surface at both ends — from the other file — and the tag diff
        // has nothing to report.
        $repo->dropConfigKey('enabled');
        $repo->commit('refactor: sample.php stops returning a key extra.php still returns');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());

        $this->assertMatchesRegularExpression(
            '/^\s+patch\s+config\s+nothing removed, renamed or added since the last tag\r?$/m',
            $run->output,
            "The config half has to be quiet, or the note is not the only thing saying it.\n"
            . $run->describe(),
        );

        $this->assertTrue(
            $run->said('config:enabled (config/extra.php + config/sample.php)'),
            "The note has to name the key and both files that declare it.\n" . $run->describe(),
        );
        $this->assertTrue($run->said('declared by more than one file'), $run->describe());

        // Invisible, not weightless: the patch the notes ask for is the whole bump, because
        // nothing the weighing can read moved.
        $this->assertSame('patch  (weighed: a patch change)', $run->plan('bump'), $run->describe());
        $this->assertSame('0.4.1  (tag v0.4.1)', $run->plan('next version'), $run->describe());
    }

    /**
     * The note is about a state the tree is usually not in, and a note that fired on every
     * release would stop being read. With one file per name there is nothing to say, and the
     * plan says nothing.
     */
    public function test_a_surface_whose_names_all_come_from_one_file_says_nothing(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v0.4.0');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertFalse(
            $run->said('declared by more than one file'),
            "The fixture declares every name once, so there is no collision to report.\n" . $run->describe(),
        );

        // The note's own opening line as well, because "declared by more than one file" is a
        // phrase the note carries however it was built: a filter that reported every name it
        // had seen would say it about a whole surface of one-file names, and this is the
        // assertion that reads the sentence rather than one clause of it.
        $this->assertFalse(
            $run->said('the surface holds one entry per name'),
            "A note that fired on every release would stop being read.\n" . $run->describe(),
        );
    }

    /**
     * More collisions than the note names, and the count is what keeps a partial list honest:
     * a note that listed three and stopped would read as three.
     */
    public function test_the_note_names_three_names_and_counts_the_rest(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $keys = ['alpha', 'beta', 'gamma', 'delta'];
        $repo->writeConfig($keys);
        $repo->writeConfig($keys, 'config/extra.php');
        $repo->commit('chore: two config files return the same four keys');
        $repo->tag('v0.4.0');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said(
                '4 name(s) are declared by more than one file, so only the first of each is in it:'
                . ' config:alpha (config/extra.php + config/sample.php),'
                . ' config:beta (config/extra.php + config/sample.php),'
                . ' config:gamma (config/extra.php + config/sample.php), … and 1 more',
            ),
            "The note has to name three of the four and count the rest.\n" . $run->describe(),
        );
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
     * The base is the last tag this branch actually contains, not the highest tag in the
     * repository.
     *
     * A dev tag cut on a side branch is a real tag and, from here, not an ancestor of
     * HEAD: this line has never had that version. Reading it as the base measures the bump
     * against a release this branch never made, and the range `vX.Y.Z..HEAD` walks a
     * history the tag is not part of. The leak was the fallback rather than `git
     * describe`, which already reads only reachable tags — `git tag --list` sees every tag
     * in the repository, so a branch with no tag of its own used to take the highest one
     * anywhere, a dev tag on another lane included.
     */
    public function test_a_tag_this_branch_cannot_reach_is_not_the_base(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);

        // The dev lane, tagged ahead of a release — from `main` that is a different
        // branch, and the tag is not a version this one contains.
        $repo->git('checkout', '-b', 'dev');
        $repo->write('src/Dev.php', "<?php\n\nnamespace Fixture;\n\nfinal class Dev\n{\n}\n");
        $repo->commit('feat: something on the dev lane');
        $repo->tag('v0.9.0');

        $repo->git('checkout', 'main');
        $repo->write('src/Main.php', "<?php\n\nnamespace Fixture;\n\nfinal class Main\n{\n}\n");
        $repo->commit('feat: something on main');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());

        // The tag is in the repository; it is simply not this branch's base.
        $this->assertStringContainsString('v0.9.0', $repo->tags());
        $this->assertSame('(none)', $run->plan('latest tag'), $run->describe());
        $this->assertTrue($run->said('no release tag yet'), $run->describe());
        $this->assertFalse(
            $run->said('since v0.9.0'),
            "The commits were diffed against a tag this branch cannot reach.\n" . $run->describe(),
        );

        // With no base, the notes decide the first version — 0.1.0, not something built
        // on 0.9.0.
        $this->assertSame('0.1.0  (tag v0.1.0)', $run->plan('next version'), $run->describe());
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
     * The case the third inventory file was added for: a tree with no tag at all. The
     * public-API signal diffs the tree against the last tag, so with no tag there is no
     * "before" for it to see a removal in, and the weighing says so — the notes are what
     * decide a first release. A written-down inventory is the one thing that can still
     * witness the removal, and a config key or a constant is what it has to have written
     * down to do it, since a method is the only kind of row the other signal would have
     * caught anyway.
     *
     * The tag diff is not merely silent here; it has nothing to diff. So the removal comes
     * from the inventory and nowhere else, which is what makes this a test of the rows
     * rather than of the signal that was already there.
     */
    public function test_a_constant_removed_with_no_tag_at_all_is_witnessed_by_the_inventory_alone(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);

        // Stamped `(no tag)`, which is the stamp a tree with no releases writes — so the
        // inventory is fresh rather than stale, and is weighed without a tag to check it
        // against.
        $repo->refreshInventory();
        $repo->dropClassConstant();
        $repo->commit('chore: keep an inventory');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('no release tag yet'), $run->describe());
        $this->assertTrue($run->said('fresh, weighed against (no tag)'), $run->describe());
        $this->assertTrue(
            $run->said('removed public constant Fixture\\Thing::VERSION'),
            $run->describe(),
        );
        $this->assertSame(
            'minor  (weighed: a breaking change, which is a minor while the package is pre-1.0)',
            $run->plan('bump'),
            $run->describe(),
        );

        // And nothing about the tree's files or methods moved with it: the constant is a row
        // only the third file holds.
        $this->assertFalse($run->said('removed public method'), $run->describe());
    }

    /**
     * An inventory with two of its three files, on a plan: the *reading* rule that makes a
     * third file safe to add at all.
     *
     * A file that is not there cannot say whether the rows it should hold were never written
     * or were just removed, so it is reported as incomplete rather than read as an empty file —
     * which would count every config key and constant in the tree as added since the last
     * release, and raise a bump nothing changed asked for. It never moves the bump, and a plan
     * publishes nothing, so this run still exits 0.
     *
     * What a *real* run does with the same state is the rail's own question and is pinned by
     * `test_a_half_written_inventory_refuses_a_release_rather_than_weighing_without_it`: the
     * reading rule here says "not weighed", and the rail says "wait until it can be".
     */
    public function test_an_inventory_missing_one_of_its_files_is_incomplete_and_never_moves_the_bump(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        $repo->refreshInventory();
        $repo->commit('chore: keep an inventory');
        $repo->drop('surface.tsv');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said('no complete inventory to weigh against — surface.tsv missing'),
            $run->describe(),
        );
        $this->assertSame('patch  (weighed: a patch change)', $run->plan('bump'), $run->describe());
    }

    /**
     * Nothing a signal may do: an inventory that is *entirely* absent is not a reason to
     * change the version, and not a reason to block a release either — the release writes
     * it, which is the only way the artefact can exist on a tree that has never had one.
     *
     * This is the half of the rail the refusal deliberately does not cover. The files alone
     * cannot tell a tree that never adopted the inventory from one whose files were deleted,
     * so the state is reported and the release goes ahead; the refusal is reserved for the
     * state that *is* distinguishable, where at least one file is on disk and a series has
     * lost a piece of itself.
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

    /**
     * The refusal itself: at least one inventory file is on disk with a stamp, so a previous
     * release demonstrably published the record, and a file missing from it cannot be "never
     * written" — it is a record that has lost a piece of itself.
     *
     * The rows in that file are the ones nothing else here can witness: the inventory is the
     * only signal that carries the *file* a declaration came from, so a removal a second file's
     * declaration hides, and anything at all on a tree with no tag, are its alone. Weighing
     * without it is under-weighing, which is the one direction a version signal must never be
     * wrong in — and the repair is named, because it is what makes refusing affordable here:
     * one command reproduces the rows and the stamp the tag holds, rather than stamping the
     * working tree with a tag that never held it.
     */
    public function test_a_half_written_inventory_refuses_a_release_rather_than_weighing_without_it(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        $repo->refreshInventory();
        $repo->commit('chore: keep an inventory');
        $repo->drop('surface.tsv');
        $repo->commit('chore: surface.tsv is gone');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertNotSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('The inventory is incomplete'), $run->describe());
        $this->assertTrue(
            $run->refused('surface.tsv missing: run php bin/inventory.php --at=v0.1.0'),
            $run->describe(),
        );

        // And nothing was cut: the refusal is a precondition, so no tag was made and the
        // record on disk is still the one the tree had.
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
    }

    /**
     * The refusal is affordable only because the repair is one command, so the command is
     * driven here rather than described: `--at=<the stamp the record carries>` reproduces the
     * rows the tag actually published, and the release that was refused then proceeds with the
     * inventory *weighed* rather than skipped. A plain `bin/inventory.php` would not do this —
     * it writes the working tree's rows and stamps them with the tag, which describes a tree
     * that tag never held.
     */
    public function test_the_named_repair_turns_the_refusal_into_a_weighed_release(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        $repo->refreshInventory();
        $repo->commit('chore: keep an inventory');
        $repo->drop('surface.tsv');
        $repo->commit('chore: surface.tsv is gone');

        $refused = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $refused->exitCode, $refused->describe());

        // Run exactly as the refusal names it.
        $repo->script('inventory.php', '--at=v0.1.0');
        $repo->commit('chore: restore surface.tsv from the tag it describes');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('fresh, weighed against v0.1.0'), $run->describe());
        $this->assertFalse($run->refused('The inventory is incomplete'), $run->describe());
        $this->assertTrue(
            $repo->exists('surface.tsv'),
            'the third file is back, written by the repair and carried into the release commit',
        );
    }

    /**
     * The same state on a plan: reported, not refused, because a plan publishes nothing —
     * the shape every rail here has, and the one that lets "what would this release weigh?"
     * be asked on a tree that is not ready.
     */
    public function test_a_half_written_inventory_is_a_note_on_a_plan_rather_than_a_refusal(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        $repo->refreshInventory();
        $repo->commit('chore: keep an inventory');
        $repo->drop('surface.tsv');

        $run = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said('a real run refuses to release until it is repaired'),
            $run->describe(),
        );
        $this->assertTrue(
            $run->said('incomplete, and a real run refuses to release until it is repaired'),
            $run->describe(),
        );
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
    }

    /**
     * The other half, on a release that could tag: an entirely absent inventory does not stop
     * it, because the release writes the files and there is nothing to reproduce. A refusal
     * here would make the artefact a precondition of ever creating it.
     */
    public function test_an_absent_inventory_does_not_block_the_release_that_writes_it(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v1.0.0');

        $this->assertFalse($repo->exists('files.tsv'));

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertFalse($run->refused('The inventory is incomplete'), $run->describe());
        $this->assertTrue($run->said('written by this release'), $run->describe());
        $this->assertSame('v1.0.0' . "\n" . 'v1.0.1', trim($repo->tags()), $run->describe());
    }

    /**
     * The stale case on a release that proceeds, which is where the file's fate is decided:
     * a plan only reports it, but a real release *replaces* it.
     *
     * That replacement is the whole reason the file can be stale at all. A release that
     * skipped it — or that wrote it with the old stamp — would leave the next run reading a
     * stamp that is wrong forever, and "not refreshed" would never become "fresh" again: the
     * inventory would be a signal the suite has and the process never recovers. So the stamp
     * is asserted to be the tag this run cut, that v0.9.9 is gone rather than left beside it,
     * and that the stale file did not lower the bump on the way through.
     */
    public function test_a_stale_inventory_is_replaced_by_the_release_that_proceeds(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        $repo->refreshInventory();
        $repo->restampInventory('v0.9.9');
        $repo->commit('chore: keep an inventory');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said('stale, refreshed by this release and not weighed'),
            $run->describe(),
        );
        $this->assertSame('patch  (weighed: a patch change)', $run->plan('bump'), $run->describe());
        $this->assertSame('0.1.1  (tag v0.1.1)', $run->plan('next version'), $run->describe());
        $this->assertTrue(
            $run->said('inventory refreshed — 2 file(s), 2 method(s), 3 key(s)/member(s), described as v0.1.1'),
            $run->describe(),
        );

        // Rewritten, not appended to: the stamp describes the tag that was just cut.
        foreach (['files.tsv', 'methods.tsv', 'surface.tsv'] as $inventory) {
            $this->assertStringContainsString(
                'describes the tree at v0.1.1',
                $repo->read($inventory),
                $run->describe(),
            );
            $this->assertStringNotContainsString('v0.9.9', $repo->read($inventory), $run->describe());
        }

        // And it went into the release commit, so the tag points at the inventory that
        // describes it — the next release reads that stamp rather than the one it replaced.
        $this->assertSame(
            '',
            trim($repo->git('status', '--porcelain', '--untracked-files=no', '--', '.')),
            $run->describe(),
        );
        $this->assertSame('describes the tree at v0.1.1', self::stamp($repo->git('show', 'v0.1.1:files.tsv')));
    }

    /** The `describes the tree at …` stamp of an inventory file, read off the file itself. */
    private static function stamp(string $inventory): string
    {
        if (preg_match('/^# .*(describes the tree at .*)$/m', $inventory, $match) !== 1) {
            return '';
        }

        return trim($match[1]);
    }
}
