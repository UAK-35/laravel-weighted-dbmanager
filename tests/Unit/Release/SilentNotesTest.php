<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

/**
 * The rail that requires the release notes to be *about* the release: `bin/release.php`.
 *
 * Every other rail is satisfied by a non-empty Unreleased section, so a new public method
 * filed under `### Fixed` ships with a changelog that says the release fixed a defect — and
 * that is the shape of the accident this one exists for, because the notes are the only signal
 * a reader ever sees. The bump is not the same question: `--weigh` takes the loudest of four
 * signals, so the version moves correctly while the entry underneath it says nothing.
 *
 * Two properties are what these tests are really about. The comparison is the notes' own
 * severity against the surface's, which is why a removal filed under `### Changed` is refused
 * and pointed at `### Removed` — the headings are the vocabulary the changelog and the policy
 * already share, and a rule that searched the entries for class names would be one authors
 * route around. And the rail is a *rail*, not a report: a release that would tag is stopped,
 * a plan that would not is warned, and the refusal names the symbols so the note can be
 * written without running the weighing by hand.
 *
 * The fixture is `ReleaseRepo`, so the script under test is the real one, copied into a
 * package of its own and run with `PHP_BINARY`.
 */
final class SilentNotesTest extends TestCase
{
    /** A changelog that says the release fixed something — the quietest heading there is. */
    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    /** A changelog that claims a change, without saying which: a minor, and still not this one. */
    private const CHANGED = "### Changed\n\n- The version constant is gone.\n";

    // ─────────────────────────────────────────────────────────────────────────
    // Refused: the surface outweighs the notes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * An added public method beside a `### Fixed` entry. The version moves — the surface
     * signal weighs a minor and `--weigh` takes it — and the changelog would announce a fix,
     * which is exactly the disagreement between the number and the prose this rail is for.
     */
    public function test_an_addition_the_notes_do_not_account_for_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $repo->write('src/Thing.php', self::thingWithColumn());
        $repo->commit('feat: add column()');

        $before = $repo->read('CHANGELOG.md');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->refused('The public surface changed, and the Unreleased notes do not account for it.'),
            $run->describe(),
        );

        // The evidence is the symbols, not just a count: the note can be written from the
        // refusal alone, which is what makes the rail a repair rather than an obstacle.
        $this->assertTrue($run->refused('  public API (minor):'), $run->describe());
        $this->assertTrue($run->refused('    • added public method Fixture\Thing::column()'), $run->describe());
        $this->assertTrue($run->refused('The notes weigh a patch (1 entry)'), $run->describe());
        $this->assertTrue($run->refused('### Removed for a removal'), $run->describe());

        // A refused release costs nothing: no tag, and the notes are where they were.
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
        $this->assertSame($before, $repo->read('CHANGELOG.md'), $run->describe());

        // Refused before the plan, like every other precondition on the tree.
        $this->assertSame('', $run->plan('next version'), $run->describe());
    }

    /**
     * A removed public constant, which the surface weighs as breaking. `### Fixed` does not
     * cover it and neither does the bump: the 0.x caveat turns the breaking change into a
     * minor version, so what a consumer is told is again the only place it can show.
     */
    public function test_a_removal_the_notes_call_a_fix_is_refused(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $repo->dropClassConstant();
        $repo->commit('chore: drop the version constant');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('  public API (breaking):'), $run->describe());
        $this->assertTrue($run->refused('    • removed public constant Fixture\Thing::VERSION'), $run->describe());
        $this->assertTrue($run->refused('The notes weigh a patch (1 entry)'), $run->describe());
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
    }

    /**
     * The strict case, and the one that shows what the comparison is: a `### Changed` entry is
     * a claim that something changed, and it is a minor, so it is quieter than a removal. The
     * refusal names the heading the change belongs under rather than asking for a louder
     * version, because the version is already right.
     */
    public function test_a_removal_filed_under_changed_is_refused_with_the_heading_to_use(): void
    {
        $repo = ReleaseRepo::make(self::CHANGED);
        $repo->tag('v0.1.0');
        $repo->dropClassConstant();
        $repo->commit('chore: drop the version constant');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('The notes weigh a minor (1 entry)'), $run->describe());
        $this->assertTrue($run->refused('### Removed for a removal'), $run->describe());
        $this->assertTrue($run->refused('removed public constant Fixture\Thing::VERSION'), $run->describe());
    }

    /**
     * The inventory as the only witness. A stored row the tree does not back is a removal the
     * tag diff cannot see — the sources are unchanged since the tag — so the rail has to read
     * the inventory as part of the surface or this release ships with nothing said about it.
     */
    public function test_the_inventory_alone_can_trip_the_rail(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $repo->refreshInventory();
        $repo->inventPublicMethod('Fixture\\Thing', 'ghost');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('  inventory (breaking):'), $run->describe());
        $this->assertTrue($run->refused('    • removed public method Fixture\Thing::ghost()'), $run->describe());

        // The tag diff has nothing to report about it, and saying so is the difference
        // between "the surface is unchanged" and "one signal saw it".
        $this->assertFalse($run->refused('  public API ('), $run->describe());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Let through
    // ─────────────────────────────────────────────────────────────────────────

    /** The same change with an entry that claims it: the rail is about the note, not the change. */
    public function test_notes_that_account_for_the_change_let_the_release_through(): void
    {
        $repo = ReleaseRepo::make("### Added\n\n- A new method, `Fixture\\Thing::column()`.\n");
        $repo->tag('v0.1.0');
        $repo->write('src/Thing.php', self::thingWithColumn());
        $repo->commit('feat: add column()');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(['v0.1.0', 'v0.2.0'], self::tags($repo), $run->describe());
        $this->assertFalse(
            $run->said('The public surface changed'),
            'nothing was unaccounted for, so the plan has nothing to say about it: ' . $run->describe(),
        );
    }

    /**
     * A release with no public change at all. The notes are a `### Fixed` entry and the
     * surface is a patch, so the two agree and the rail is silent — which is the state most
     * releases are in, and the reason the rail cannot simply require the notes to be loud.
     */
    public function test_a_tree_with_no_surface_change_is_not_a_finding(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(['v0.1.0', 'v0.1.1'], self::tags($repo), $run->describe());
        $this->assertFalse($run->said('The public surface changed'), $run->describe());
    }

    /**
     * The documented limit, asserted so it cannot be tightened by accident: the comparison is
     * severity against severity, so a `### Added` entry about something else covers a new
     * method. Matching the entries against the symbol names would read better and would be
     * unworkable — a release note that has to spell every class it mentions is one authors
     * stop writing, and the rail would then be refusing the releases that are documented best.
     */
    public function test_an_entry_of_the_right_weight_covers_a_change_it_does_not_name(): void
    {
        $repo = ReleaseRepo::make("### Added\n\n- Something else entirely, which is also a minor.\n");
        $repo->tag('v0.1.0');
        $repo->write('src/Thing.php', self::thingWithColumn());
        $repo->commit('feat: add column()');

        $run = $repo->release('--weigh', '--yes', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(['v0.1.0', 'v0.2.0'], self::tags($repo), $run->describe());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The two ways past it
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `--allow-silent-notes` releases anyway and says so in the plan, the way `--skip-ci` and
     * `--ignore-policy` do: an escape that a reader of the plan cannot see is a rail that was
     * removed rather than one that was overridden.
     */
    public function test_allow_silent_notes_releases_anyway_and_says_so(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $repo->write('src/Thing.php', self::thingWithColumn());
        $repo->commit('feat: add column()');

        $run = $repo->release('--weigh', '--yes', '--skip-ci', '--allow-silent-notes');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertSame(['v0.1.0', 'v0.2.0'], self::tags($repo), $run->describe());
        $this->assertTrue(
            $run->said(
                'The public surface changed (weighed minor) and the notes are quieter (weighed patch)'
                . ' — released anyway (--allow-silent-notes).',
            ),
            $run->describe(),
        );
    }

    /**
     * A dry run reports the state and refuses nothing, the way the dirty-tree and CI rails do:
     * the plan exists to say whether the release would go through, and "write the note first" is
     * an answer a plan is the right place for. The warning is worded as a warning — the same
     * note a plan prints when it is what let the release past, with the other half of the
     * sentence.
     */
    public function test_a_dry_run_warns_instead_of_refusing(): void
    {
        $repo = ReleaseRepo::make(self::FIXED);
        $repo->tag('v0.1.0');
        $repo->write('src/Thing.php', self::thingWithColumn());
        $repo->commit('feat: add column()');

        $run = $repo->release('--weigh', '--dry-run', '--skip-ci');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue(
            $run->said(
                'The public surface changed (weighed minor) and the notes are quieter (weighed patch)'
                . ' — a real run would refuse to tag on this',
            ),
            $run->describe(),
        );
        $this->assertFalse(
            $run->refused('The public surface changed'),
            'a plan refuses nothing: ' . $run->describe(),
        );
        $this->assertSame('v0.1.0', trim($repo->tags()), $run->describe());
    }

    /**
     * The fixture's tags in the order git lists them. Split rather than compared with `PHP_EOL`:
     * the list is git's output, so its line endings are git's business and not the subject of
     * any of these tests.
     *
     * @return list<string>
     */
    private static function tags(ReleaseRepo $repo): array
    {
        return array_values(array_filter(preg_split('/\R/', trim($repo->tags())) ?: []));
    }

    /**
     * The fixture's class with one more public method, and nothing else touched: the change
     * these tests are about is one added row rather than a whole class replaced.
     */
    private static function thingWithColumn(): string
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

            public function weight(): int
            {
                return 1;
            }

            public function column(): string
            {
                return 'column';
            }
        }
        PHP . "\n";
    }
}
