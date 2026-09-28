<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

/**
 * Prereleases: a version with a suffix, cut from the dev branch.
 *
 * The suffix is not decoration. Composer's own parser reads a fixed vocabulary of
 * prerelease identifiers — `alpha`, `beta`, `RC`, and a bare `dev` — and rejects
 * everything else, `-dev.1` included, because `dev` takes no number. A tag Composer
 * cannot parse is a tag nobody can install, and it fails *quietly*: the tag is simply
 * not a version. So the grammar is pinned here against the shape of the problem rather
 * than against taste, and the last test checks the list against Composer itself.
 *
 * The number is written without a dot — `-alpha1`, as Packagist's own examples spell it
 * — and the dotted spelling is *refused* rather than tolerated, because Composer reads
 * the two as a single version. Two tags carrying the two spellings would be one version
 * published twice, and once a dotted one exists it blocks the undotted one outright: the
 * candidate is not "newer" than a base that is the same version. That is pinned in the
 * grammar test and proved against Composer's parser in the last one.
 *
 * The suffix also decides the branch, and that is the other half of what these tests
 * cover: a prerelease precedes the release it is named after, so it is cut from `dev`,
 * and a version with no suffix is cut from `main`.
 */
final class PrereleaseTest extends TestCase
{
    /** The notes declaring a patch — the lightest thing to weigh, so the gate is quiet. */
    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    public function test_a_prerelease_is_cut_from_dev_and_promoted_like_a_release(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->git('checkout', '-b', 'dev');

        $run = $repo->release('--version=0.0.1-alpha1', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('committed and tagged v0.0.1-alpha1'), $run->describe());

        // The plan says which branch it is releasing from, and why it is allowed to be
        // that one: the suffix is the reason, not a --branch somebody remembered.
        $this->assertSame('dev', $run->plan('branch'), $run->describe());
        $this->assertStringContainsString('alpha', $run->plan('prerelease'), $run->describe());

        // The tag is the version, suffix and all.
        $this->assertStringContainsString('v0.0.1-alpha1', $repo->tags());
        $this->assertSame('Release v0.0.1-alpha1', trim($repo->git('log', '-1', '--format=%s')));

        // Promotion is what a release does: the notes move under the version, and a fresh
        // empty Unreleased section is left above them for the next one.
        $changelog = $repo->read('CHANGELOG.md');
        $this->assertStringContainsString('## 0.0.1-alpha1 - ' . gmdate('Y-m-d'), $changelog);
        $this->assertLessThan(
            strpos($changelog, '## 0.0.1-alpha1'),
            strpos($changelog, '## Unreleased'),
            'The empty Unreleased section has to be above the promoted notes.',
        );
        $this->assertStringContainsString('A ported defect, fixed.', $changelog);

        // And the inventory is stamped with the prerelease tag, which is what the next
        // release reads to tell "nothing changed" from "not refreshed".
        $this->assertStringContainsString('describes the tree at v0.0.1-alpha1', $repo->read('files.tsv'));
        $this->assertStringContainsString('describes the tree at v0.0.1-alpha1', $repo->read('methods.tsv'));

        // A prerelease is not a licence to leave the tree dirty: the tag has to point at
        // exactly what was reviewed.
        $this->assertSame('', trim($repo->git('status', '--porcelain', '--untracked-files=no', '--', '.')));
    }

    public function test_a_prerelease_cannot_be_cut_from_main(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);

        $run = $repo->release('--version=0.0.1-alpha1', '--dry-run');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('Releases are cut from dev, but HEAD is on main.'),
            $run->describe(),
        );

        // The refusal names the way out, so nobody has to read the source to find it.
        $this->assertTrue($run->refused('or pass --branch=main'), $run->describe());
    }

    /**
     * The rule has two directions. A release on `dev` is the same mistake pointing the
     * other way, and it is refused for the same reason: the branch a version is cut from
     * is a fact about the version, not a preference.
     */
    public function test_a_release_cannot_be_cut_from_dev(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->git('checkout', '-b', 'dev');

        $run = $repo->release('--version=0.0.1', '--dry-run');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('Releases are cut from main, but HEAD is on dev.'),
            $run->describe(),
        );
    }

    public function test_the_expected_branch_can_be_overridden(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);

        // A prerelease on main: unusual, and allowed when it is said out loud.
        $run = $repo->release('--version=0.0.1-alpha1', '--branch=main', '--dry-run');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('main', $run->plan('branch'), $run->describe());
    }

    /**
     * The grammar, stated once and pinned: what this script will write as a tag is a
     * subset of what Composer reads, never a superset.
     *
     * `-dev.1` is the case that started this and `-alpha.1` is its mirror image — the one
     * spelling that reads most naturally, refused because Composer reads it as the *same
     * version* as the spelling that is written. The dot is not a style question: two tags
     * carrying the two spellings are one version published twice, and whichever lands
     * first blocks the other.
     *
     * The upper-case spellings are accepted and canonicalised, because `-RC1` and `-rc1`
     * are likewise one version — so writing only the lower-case form prevents the same
     * collision without refusing a spelling Packagist itself puts in its own examples.
     */
    public function test_the_suffix_grammar_is_the_one_composer_can_read(): void
    {
        // input => the version actually planned, which is the canonical spelling.
        $accepted = [
            '0.0.1-alpha' => '0.0.1-alpha',
            '0.0.1-alpha1' => '0.0.1-alpha1',
            '0.0.1-alpha10' => '0.0.1-alpha10',
            '0.0.1-beta2' => '0.0.1-beta2',
            '0.0.1-rc1' => '0.0.1-rc1',
            '0.0.1-RC1' => '0.0.1-rc1',
            '0.0.1-Beta1' => '0.0.1-beta1',
            '0.0.1-dev' => '0.0.1-dev',
            'v0.0.1-alpha1' => '0.0.1-alpha1',
        ];

        $refused = [
            '0.0.1-alpha.1' => 'the dot is a second spelling of the same version',
            '0.0.1-beta.2' => 'the dot is a second spelling of the same version',
            '0.0.1-dev.1' => '`dev` takes no number',
            '0.0.1-dev1' => '`dev` takes no number',
            '0.0.1alpha1' => 'the separator is required',
            '0.0.1-alpha1x' => 'nothing may follow the number',
            '0.0.1-nightly' => 'not a lane Composer reads',
            '0.0.1-pre.1' => 'not a lane Composer reads',
            '0.0.1-p1' => 'patch is a stability, not a lane this writes',
            '0.0.1.1' => 'four parts is not a version here',
        ];

        $repo = ReleaseRepo::make(self::FIXED);
        $repo->git('checkout', '-b', 'dev');

        foreach ($accepted as $version => $planned) {
            $run = $repo->release("--version={$version}", '--dry-run');

            $this->assertSame(0, $run->exitCode, "{$version} should be releasable. " . $run->describe());

            // Accepted, and accepted *as itself*: the version planned is the version asked
            // for in its canonical spelling, suffixed and all — not silently turned into
            // something else, and not silently turned into nothing.
            $this->assertStringStartsWith(
                $planned . '  (tag v',
                $run->plan('next version'),
                $run->describe(),
            );
        }

        foreach ($refused as $version => $why) {
            $run = $repo->release("--version={$version}", '--dry-run');

            // 2, not 1: the argument itself is wrong, so nothing was attempted. A
            // precondition that failed is a different thing and keeps exit 1.
            $this->assertSame(2, $run->exitCode, "{$version} should be refused ({$why}). " . $run->describe());
            $this->assertTrue(
                $run->refused("Not a version this can release: {$version}"),
                $run->describe(),
            );
            $this->assertTrue($run->refused('a numbered dev lane is -alpha1'), $run->describe());

            // A refusal that says nothing about the tree must not have read the tree:
            // no plan, just the usage.
            $this->assertSame('', $run->plan('next version'), $run->describe());
        }
    }

    /**
     * The bad suffix wins over the wrong branch.
     *
     * Both are wrong here — `main` is the wrong branch for a prerelease, and `-alpha.1`
     * is not a spelling this writes — and the ordering is the point. When the suffix was
     * validated after the branch rail, this reported "Releases are cut from dev", which is
     * true and useless: it sends the caller to switch branches to fix a typo.
     */
    public function test_a_refused_suffix_is_refused_before_the_branch_is_checked(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);

        $run = $repo->release('--version=0.0.1-alpha.1', '--dry-run');

        $this->assertSame(2, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Not a version this can release'), $run->describe());
        $this->assertFalse($run->refused('Releases are cut from'), $run->describe());
    }

    /**
     * A lane is numbered, so a refusal can say which number is free. It is counted from
     * the tags rather than from the number that was typed: the tag asked for existing does
     * not mean the next one does not.
     *
     * The setup is also the case that makes the count necessary — the higher tag lives on
     * a branch this one cannot reach, so `git describe` only finds `alpha1` and the script
     * cannot simply look at its own latest tag.
     */
    public function test_the_refusal_names_the_next_free_number_in_the_lane(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->git('checkout', '-b', 'dev');
        $repo->tag('v0.0.1-alpha1');

        $repo->git('checkout', '-b', 'side');
        $repo->write('src/Side.php', "<?php\n\nnamespace Fixture;\n\nfinal class Side\n{\n}\n");
        $repo->commit('feat: something on the side');
        $repo->tag('v0.0.1-alpha2');
        $repo->git('checkout', 'dev');

        $run = $repo->release('--version=0.0.1-alpha2', '--dry-run');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('Tag v0.0.1-alpha2 already exists'),
            $run->describe(),
        );
        $this->assertTrue(
            $run->refused('The next free number in that lane is 0.0.1-alpha3.'),
            $run->describe(),
        );
    }

    /**
     * A bare lane is zero, not a dead end: Composer orders `alpha` before `alpha1`, so
     * that is the number the count starts from.
     */
    public function test_a_bare_lane_is_followed_by_its_first_number(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->git('checkout', '-b', 'dev');
        $repo->tag('v0.0.1-alpha');

        $run = $repo->release('--version=0.0.1-alpha', '--dry-run');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('The next free number in that lane is 0.0.1-alpha1.'),
            $run->describe(),
        );
    }

    /**
     * The series, which is the whole point of a numbered lane: one line cut more than
     * once, each tag built on the one before it.
     *
     * Each round writes fresh notes first, because a dev tag promotes what it finds and
     * leaves an empty Unreleased section behind — the same thing a release does. That is
     * the one piece of care a second dev tag needs: the rail is unconditional, so a round
     * with nothing to write is a round with nothing to tag.
     */
    public function test_a_lane_can_be_cut_as_a_series(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->git('checkout', '-b', 'dev');

        foreach ([1, 2, 3] as $number) {
            $run = $repo->release("--version=0.0.1-alpha{$number}", '--yes', '--skip-ci');

            $this->assertSame(0, $run->exitCode, "alpha{$number} should cut. " . $run->describe());
            $this->assertSame(
                $number === 1 ? '(none)' : 'v0.0.1-alpha' . ($number - 1),
                $run->plan('latest tag'),
                "alpha{$number} should be built on the tag before it. " . $run->describe(),
            );
            $this->assertStringContainsString("v0.0.1-alpha{$number}", $repo->tags());
            $this->assertSame(
                "Release v0.0.1-alpha{$number}",
                trim($repo->git('log', '-1', '--format=%s')),
            );

            // Fresh notes for the next round, committed, so the next cut has something to
            // promote rather than an empty section to refuse.
            $repo->release_notes(self::FIXED);
            $repo->commit("docs: notes for the alpha{$number} round");
        }

        // Three tags in one lane, each an ancestor of the next.
        $this->assertSame(
            [
                'v0.0.1-alpha1',
                'v0.0.1-alpha2',
                'v0.0.1-alpha3',
            ],
            explode("\n", trim($repo->git('tag', '--list', '--sort=v:refname'))),
        );
    }

    /**
     * A cut consumes the Unreleased section, so a series needs notes for every round — and
     * there is no flag that skips it.
     *
     * This is the rail a repeated dev tag meets first, and it is deliberately not
     * overridable: the notes are the signal the weighing reads and the thing a reader
     * decides to upgrade on, so a version with nothing under it is one nobody can weigh.
     * The removal is checked as well as the refusal, because the failure worth preventing
     * is the flag being accepted and doing nothing.
     */
    public function test_a_dev_tag_with_no_notes_is_refused_and_has_no_override(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->git('checkout', '-b', 'dev');

        $first = $repo->release('--version=0.0.1-alpha1', '--yes', '--skip-ci');
        $this->assertSame(0, $first->exitCode, $first->describe());

        // The notes have moved to their promoted heading; what is left behind is empty.
        $empty = $repo->release('--version=0.0.1-alpha2', '--yes');

        $this->assertSame(1, $empty->exitCode, $empty->describe());
        $this->assertTrue(
            $empty->refused('The Unreleased section has no entries'),
            $empty->describe(),
        );

        $override = $repo->release('--version=0.0.1-alpha2', '--allow-empty', '--yes');

        $this->assertSame(2, $override->exitCode, $override->describe());
        $this->assertTrue(
            $override->refused('--allow-empty was removed'),
            $override->describe(),
        );

        // Neither attempt got as far as a tag.
        $this->assertSame('v0.0.1-alpha1', trim($repo->tags()));
    }

    /**
     * The same tree, two questions, two answers — and the plan says which one it answered.
     *
     * The refusal above is about what a release *publishes*: the notes are the version's
     * record, so a release with none is one nobody can read. The weighing is about the
     * *changes*, and the notes are only one of the four things it reads — so `--weigh
     * --dry-run` is let past an empty section, reports the bump the other signals weigh, and
     * says in the plan that a real run would refuse here. The release is exercised on the
     * same tree, because "leave the rail alone" is half of what this is about.
     */
    public function test_a_weighing_dry_run_reports_the_bump_while_a_release_of_it_refuses(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v1.0.0');
        $repo->release_notes('');
        $repo->commit('docs: nothing to release yet');

        $plan = $repo->release('--weigh', '--dry-run', '--skip-ci');

        $this->assertSame(0, $plan->exitCode, $plan->describe());
        $this->assertSame('patch  (weighed: a patch change)', $plan->plan('bump'), $plan->describe());
        $this->assertStringContainsString('1.0.1', $plan->plan('next version'), $plan->describe());

        // The line a reader trusts to know what this plan is: it reports the weighing and
        // names the refusal it is standing in for.
        $this->assertTrue($plan->said('nothing to promote'), $plan->describe());
        $this->assertTrue($plan->said('a real run refuses here'), $plan->describe());

        // And the weighing table says which signal was silent, in the same words the empty
        // section gets anywhere else — an empty section and a section in a vocabulary the
        // policy does not know are not reported as the same thing.
        $this->assertTrue(
            $plan->said('the Unreleased section is empty — nothing for a release to publish'),
            $plan->describe(),
        );

        // A plan writes nothing, so the report above cannot be mistaken for a cut.
        $this->assertSame('v1.0.0', trim($repo->tags()));
        $this->assertSame('docs: nothing to release yet', trim($repo->git('log', '-1', '--format=%s')));

        $release = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $release->exitCode, $release->describe());
        $this->assertTrue($release->refused('The Unreleased section has no entries'), $release->describe());

        // And the refusal names the run that does report, so a reader who wanted the bump is
        // not left to find it: only --weigh asks the question the notes are irrelevant to.
        $this->assertTrue($release->refused('--weigh'), $release->describe());
        $this->assertSame('v1.0.0', trim($repo->tags()));
    }

    /**
     * The exemption is exactly `--weigh`. A declared bump is not a question the changes
     * answer — the weighing is only a check on it — so a dry run of one is refused here like
     * any other run that could tag, and the refusal says which flag would have reported.
     */
    public function test_a_declared_bump_is_not_let_past_an_empty_unreleased_even_as_a_dry_run(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v1.0.0');
        $repo->release_notes('');
        $repo->commit('docs: nothing to release yet');

        $run = $repo->release('--minor', '--dry-run', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('The Unreleased section has no entries'), $run->describe());
        $this->assertFalse($run->said('Release plan'), $run->describe());
    }

    /**
     * A prerelease's own commit is bookkeeping, like any other release commit.
     *
     * The tag is moved back to put the release commit *inside* `base..HEAD`, which is the
     * only arrangement in which the question can be asked at all: a release commit normally
     * sits *under* the tag it created, so the range never contains it. A mistagged release
     * is exactly where it does, and a release commit read as a change would be counted
     * against the very release it recorded.
     *
     * The notes for the next round sit in the range beside it, and they are the control: two
     * commits are there, and the signal has to report one of them.
     */
    public function test_a_prerelease_release_commit_is_not_counted_as_a_change(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->git('checkout', '-b', 'dev');

        $first = $repo->release('--version=0.0.1-alpha1', '--yes', '--skip-ci');
        $this->assertSame(0, $first->exitCode, $first->describe());

        // The cut consumed the section, so the weigh below needs notes of its own — and
        // committing them is what makes the count meaningful rather than trivially zero.
        $repo->release_notes(self::FIXED);
        $repo->commit('docs: notes for the next round');

        $repo->git('tag', '-f', '-a', 'v0.0.1-alpha1', '-m', 'v0.0.1-alpha1', 'HEAD~2');

        $weigh = $repo->release('--weigh', '--branch=dev', '--dry-run');

        $this->assertSame(0, $weigh->exitCode, $weigh->describe());
        $this->assertTrue(
            $weigh->said('1 commit(s) since v0.0.1-alpha1'),
            "The release commit was weighed as a change.\n" . $weigh->describe(),
        );
    }

    /**
     * The drift guard.
     *
     * Every form this script writes has to be one Composer can read, and the list above is
     * a claim about Composer's grammar rather than about this package's preferences — so
     * it is checked against Composer's own parser when the dev toolchain provides it.
     * `composer/semver` arrives transitively (through orchestra/canvas rather than as a
     * dependency of this package), so the guard skips when it is not installed instead of
     * failing for a reason that is not about the release script.
     */
    public function test_every_form_this_writes_is_one_composers_parser_accepts(): void
    {
        if (!class_exists(\Composer\Semver\VersionParser::class)) {
            $this->markTestSkipped('composer/semver is not installed, so Composer cannot be asked.');
        }

        $parser = new \Composer\Semver\VersionParser();

        // The stability is the word the plan prints and the word a consumer has to allow in
        // `minimum-stability`, so it is checked rather than assumed: `-rc1` is `RC`, not
        // `rc`, and a plan that printed the wrong one would be quoting a vocabulary
        // Composer does not have.
        $forms = [
            '0.0.1' => 'stable',
            '0.0.1-alpha' => 'alpha',
            '0.0.1-alpha1' => 'alpha',
            '0.0.1-alpha10' => 'alpha',
            '0.0.1-beta2' => 'beta',
            '0.0.1-rc1' => 'RC',
            '0.0.1-RC1' => 'RC',
            '0.0.1-dev' => 'dev',
        ];

        foreach ($forms as $version => $stability) {
            $this->assertNotSame('', $parser->normalize($version), $version);
            $this->assertSame($stability, $parser->parseStability($version), $version);
        }

        // Composer rejects these outright, so they could never have been a version.
        foreach (['0.0.1-dev.1', '0.0.1-dev1', '0.0.1-nightly'] as $version) {
            try {
                $parser->normalize($version);
                $this->fail("Composer was expected to reject {$version}.");
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }

        // And the dotted form is refused for a different reason, which is the whole
        // argument for refusing it: Composer reads it as the *same* version, so accepting
        // both spellings would let two tags claim one version — and the dotted one, once
        // it existed, would block the undotted one on the "is not newer" rail.
        $this->assertSame(
            $parser->normalize('0.0.1-alpha1'),
            $parser->normalize('0.0.1-alpha.1'),
            'The dotted spelling has to be the same version, or refusing it would be arbitrary.',
        );
        $this->assertTrue(
            version_compare('0.0.1-alpha.1', '0.0.1-alpha1', '<='),
            'A dotted tag has to block the undotted one, or the refusal would not be needed.',
        );
    }
}
