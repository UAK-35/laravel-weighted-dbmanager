<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRun;

/**
 * The part of the command the dry run never reaches: promoting the changelog,
 * rewriting the inventory, committing, tagging.
 *
 * Weighing can be tested against a plan. The inventory's safety property cannot: it
 * only exists because one release writes a record and the *next* one reads it, so
 * proving it means cutting two releases in a row and watching the second accept what
 * the first left behind. That round trip is what these tests are for.
 */
final class ReleaseApplyTest extends TestCase
{
    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    private const ADDED = "### Added\n\n- A command nobody could run before.\n";

    public function test_the_release_promotes_the_changelog_stamps_the_inventory_and_tags(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('committed and tagged v1.1.0'), $run->describe());
        $this->assertTrue($run->said('inventory refreshed — 2 file(s), 2 method(s), described as v1.1.0'), $run->describe());

        // The tag exists, and the commit under it is the release's own.
        $this->assertStringContainsString('v1.1.0', $repo->tags());
        $this->assertSame('Release v1.1.0', trim($repo->git('log', '-1', '--format=%s')));

        // The notes moved rather than being copied, and a fresh Unreleased section sits
        // above them: it is what the next release weighs.
        $changelog = $repo->read('CHANGELOG.md');
        $this->assertStringContainsString('## 1.1.0 - ' . gmdate('Y-m-d'), $changelog);
        $this->assertLessThan(
            strpos($changelog, '## 1.1.0'),
            strpos($changelog, '## Unreleased'),
            'The empty Unreleased section has to be above the promoted notes.',
        );
        $this->assertStringContainsString('A command nobody could run before.', $changelog);

        // The inventory went into the same commit, stamped with the tag it describes —
        // that stamp is the entire contract with the next release.
        $this->assertStringContainsString('describes the tree at v1.1.0', $repo->read('files.tsv'));
        $this->assertStringContainsString('describes the tree at v1.1.0', $repo->read('methods.tsv'));
        $this->assertStringContainsString('files.tsv', $repo->git('ls-files', 'files.tsv', 'methods.tsv'));

        // Everything the release wrote was committed, so the tag points at the tree.
        $this->assertSame('', trim($repo->git('status', '--porcelain', '--untracked-files=no', '--', '.')));
    }

    /**
     * The round trip, and the reason a written-down inventory is worth cutting a
     * release over: the second release reads the stamp the first one wrote and weighs
     * the tree against it, instead of having nothing to compare against.
     */
    public function test_a_second_release_weighs_against_the_inventory_the_first_one_wrote(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v1.0.0');

        $first = $repo->release('--weigh', '--yes', '--skip-ci');
        $this->assertSame(0, $first->exitCode, $first->describe());
        $this->assertStringContainsString('describes the tree at v1.0.1', $repo->read('methods.tsv'));

        // A public method goes away and the notes say nothing about it, so the tag diff
        // and the inventory agree: 1.0.1 had weight(), and this tree does not.
        $repo->write('src/Thing.php', self::thingWithoutWeight());
        $repo->release_notes(self::FIXED);
        $repo->commit('chore: drop weight()');

        $second = $repo->release('--weigh', '--dry-run');

        $this->assertSame(0, $second->exitCode, $second->describe());
        $this->assertTrue(
            $second->said('fresh, weighed against v1.0.1'),
            $second->describe(),
        );
        $this->assertTrue(
            $second->said('removed public method Fixture\Thing::weight()'),
            $second->describe(),
        );
        $this->assertSame('major  (weighed: a breaking change)', $second->plan('bump'), $second->describe());
        $this->assertSame('2.0.0  (tag v2.0.0)', $second->plan('next version'), $second->describe());
    }

    /**
     * Both dev-lane aliases name the line being developed, so opening a new line moves
     * both — `main` and `dev` are two installable names for one line, and an alias that
     * moved on only one of them would leave the other resolving to the old one.
     *
     * The rest of the file is the control: only the alias values may change, because a
     * release that reflowed someone else's formatting would be a diff nobody asked for.
     */
    public function test_opening_a_new_line_points_both_dev_lane_aliases_at_it(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->tag('v1.0.0');
        $before = $repo->read('composer.json');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('dev-dev, dev-main -> 1.1.x-dev  (updated)', $run->plan('branch-alias'), $run->describe());
        $this->assertTrue(
            $run->said('branch-alias updated to 1.1.x-dev (dev-dev, dev-main)'),
            $run->describe(),
        );

        $after = $repo->read('composer.json');
        $this->assertSameSubstringCount('0.0.x-dev', 0, $after);
        $this->assertSameSubstringCount('1.1.x-dev', 2, $after);
        $this->assertSame(
            str_replace('0.0.x-dev', '<alias>', $before),
            str_replace('1.1.x-dev', '<alias>', $after),
            'Only the two alias values may differ; the rest of composer.json is untouched.',
        );

        // It went into the release commit, or the tag would point at a tree whose
        // composer.json still advertises the old line.
        $this->assertSame('', trim($repo->git('status', '--porcelain', '--untracked-files=no', '--', '.')));
        $this->assertSame('Release v1.1.0', trim($repo->git('log', '-1', '--format=%s')));
    }

    /**
     * A release on the same line leaves both alone, and leaves composer.json out of the
     * commit — the plan says so before anything is written, which is what makes it safe
     * to read the plan as a statement about the diff.
     */
    public function test_a_release_on_the_same_line_leaves_the_aliases_alone(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.0.1');
        $before = $repo->read('composer.json');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame('0.0.2  (tag v0.0.2)', $run->plan('next version'), $run->describe());
        $this->assertSame(
            'dev-dev, dev-main -> 0.0.x-dev  (unchanged)',
            $run->plan('branch-alias'),
            $run->describe(),
        );
        $this->assertSame($before, $repo->read('composer.json'), 'composer.json was rewritten anyway.');
        $this->assertStringNotContainsString(
            'composer.json',
            $repo->git('show', '--name-only', '--format=', 'HEAD'),
            'An unchanged alias has no business in the release commit.',
        );
    }

    /**
     * An alias the dev lanes do not own names a *different* line — a maintenance
     * branch's, say — so it is left alone rather than updated. Being thorough here would
     * mean being wrong, which is why the rewrite names the two keys it owns.
     */
    public function test_an_alias_for_another_line_is_left_alone(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->write('composer.json', str_replace(
            '"dev-main": "0.0.x-dev"',
            '"dev-main": "0.0.x-dev",' . "\n" . '                    "dev-1.x": "1.x-dev"',
            $repo->read('composer.json'),
        ));
        $repo->commit('chore: a maintenance line with an alias of its own');
        $repo->tag('v0.0.1');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(
            'dev-dev, dev-main -> 0.1.x-dev  (updated)',
            $run->plan('branch-alias'),
            $run->describe(),
        );
        $this->assertStringContainsString('"dev-1.x": "1.x-dev"', $repo->read('composer.json'));
        $this->assertSameSubstringCount('0.1.x-dev', 2, $repo->read('composer.json'));
    }

    /**
     * A package that aliases neither dev lane is not a broken release — it is one with
     * nothing to keep in step, and the plan says so rather than printing a key that is
     * not in the file.
     */
    public function test_a_package_with_no_dev_lane_alias_is_left_alone(): void
    {
        $repo = ReleaseRepo::make(self::ADDED);
        $repo->write('composer.json', str_replace(
            [
                '            "dev-dev": "0.0.x-dev",' . "\n",
                '            "dev-main": "0.0.x-dev"',
            ],
            ['', ''],
            $repo->read('composer.json'),
        ));
        $repo->commit('chore: no dev-lane aliases');
        $repo->tag('v0.0.1');
        $before = $repo->read('composer.json');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(
            'none for the dev lanes in composer.json — left alone',
            $run->plan('branch-alias'),
            $run->describe(),
        );
        $this->assertTrue(
            $run->said('no extra.branch-alias for the dev lanes'),
            $run->describe(),
        );
        $this->assertSame($before, $repo->read('composer.json'));
    }

    /**
     * The closing note is the one place the tool says what to do next, so it has to be true —
     * and it cannot be conditioned on a fact the script does not have. Whether the package has
     * ever been submitted to packagist.org is exactly that kind of fact: a tag on GitHub says
     * nothing about whether a version is being served, so the first release and the tenth are
     * told the same thing, and the line names both ways the gap closes rather than guessing at
     * which one applies. Guessing was the defect: the note used to read the presence of an
     * earlier tag as proof of a submission, which is true of a package that has been submitted
     * and false of one that has been tagged several times without ever being submitted.
     */
    public function test_the_closing_note_says_the_same_thing_on_every_run(): void
    {
        $first = ReleaseRepo::make(self::FIXED);

        $run = $first->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said('next: git push origin main --follow-tags'),
            $run->describe(),
        );

        // A release cut on top of a tag that already exists — the run the old note assumed had
        // been published. It gets the same sentence, word for word.
        $later = ReleaseRepo::make(self::FIXED);
        $later->tag('v1.0.0');

        $second = $later->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $second->exitCode, $second->describe());

        $line = self::closingLine($run);

        // The same sentence for a first release and for one cut on top of a tag. The version is
        // the only part allowed to differ, so it is the only part normalised away.
        $this->assertSame(
            self::closingNoteShape($run),
            self::closingNoteShape($second),
            $second->describe(),
        );
        $this->assertStringContainsString('{version}', self::closingNoteShape($run), $run->describe());

        // One line, and true whichever state the reader is in: the question is about this
        // release's tag, and both ways of closing the gap are named on it.
        $this->assertStringNotContainsString(PHP_EOL, $line);
        $this->assertStringContainsString('Packagist reads tags, not commits', $line);
        $this->assertStringContainsString('submit the package if it never was', $line);
        $this->assertStringContainsString('or trigger a crawl', $line);
        $this->assertStringContainsString('RELEASING.md, "Publishing".', $line);

        $tag = self::taggedVersion($run);

        $this->assertNotSame('', $tag, $run->describe());
        $this->assertStringContainsString($tag, $line);
    }

    /**
     * The pointer to the manual crawl, on both exits. A tag on GitHub and a version on
     * Packagist are two different events, so a successful push says nothing about whether
     * a version was published — and the exit that has just pushed is where that gap
     * matters most, because nothing else on that path mentions Packagist at all.
     *
     * The push is real: the fixture is given a bare repository as `origin`, so `--push` is
     * exercised rather than assumed, and the tag is read back off the remote afterwards.
     */
    public function test_the_closing_note_points_at_the_manual_crawl_on_both_exits(): void
    {
        $byHand = ReleaseRepo::make(self::FIXED);
        $byHand->tag('v1.0.0');

        $run = $byHand->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('next: git push origin main --follow-tags'), $run->describe());
        $this->assertTrue($run->said('or trigger a crawl'), $run->describe());
        $this->assertTrue($run->said('RELEASING.md, "Publishing".'), $run->describe());

        $pushed = ReleaseRepo::make(self::FIXED)->withRemote();
        $pushed->tag('v1.0.0');

        $pushedRun = $pushed->release('--weigh', '--yes', '--skip-ci', '--push');

        $this->assertSame(0, $pushedRun->exitCode, $pushedRun->describe());
        $this->assertTrue(
            $pushedRun->said('pushed main and v1.0.1 to origin'),
            $pushedRun->describe(),
        );
        $this->assertTrue($pushedRun->said('or trigger a crawl'), $pushedRun->describe());

        // The push happened, so the note is advice about a tag that really left.
        $this->assertStringContainsString('v1.0.1', $pushed->git('ls-remote', '--tags', 'origin'));
    }

    /**
     * The last line a run printed — the note the tool ends on.
     */
    private static function closingLine(ReleaseRun $run): string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $run->output) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        return $lines === [] ? '' : (string) end($lines);
    }

    /**
     * The version this run committed and tagged, read out of the run's own output, so the note
     * can be asserted against the release it was printed for rather than a version this test
     * has to guess at.
     */
    private static function taggedVersion(ReleaseRun $run): string
    {
        return preg_match('/committed and tagged (v\S+)/', $run->output, $match) === 1 ? $match[1] : '';
    }

    /**
     * The note a run ends on, with the version it names replaced by `{version}` — two runs of
     * two different releases can then be asked whether they say the same thing, which is the
     * property the note is supposed to have.
     */
    private static function closingNoteShape(ReleaseRun $run): string
    {
        $line = self::closingLine($run);
        $tag = self::taggedVersion($run);

        return $tag === '' ? $line : str_replace($tag, '{version}', $line);
    }

    private static function assertSameSubstringCount(string $needle, int $expected, string $haystack): void
    {
        self::assertSame($expected, substr_count($haystack, $needle), $haystack);
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
}
