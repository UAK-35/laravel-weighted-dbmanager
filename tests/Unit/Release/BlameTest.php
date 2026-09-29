<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;

/**
 * The blame command on its own: `bin/blame.php`, which answers which release signal
 * names one symbol and what that signal contributed to the bump.
 *
 * The release suite already covers the weighing as a whole — which of the four signals
 * wins and what version comes out — and none of it asks what the plan looks like from
 * the other end, from one name. That is the question these tests are about, and it has
 * three answers rather than one: a signal that names the symbol and carries the top
 * severity, a signal that names it below the top, and no signal at all, which is the
 * answer for most symbols most of the time and the one that is easy to get wrong.
 *
 * Each test drives the real script through the fixture, so the weighing it reads is a
 * weighing the production code produced: `bin/blame.php` includes the same
 * `bin/weighing.php` that `bin/release.php` does, and a change that made the two
 * disagree would show up here as a disagreement with the release plan.
 */
final class BlameTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Which signal names it
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The loudest case: the changelog entry and the surface change are both there, both
     * weigh a minor, and the name reaches both of them. The notes are reported from the
     * bullet rather than from a heading — the heading is what weighs, but the entry is
     * what names the symbol, and a report that said "the notes do not name this" for a
     * `### Added` section whose entry is about it would be worse than useless.
     */
    public function test_it_names_the_signal_that_caught_a_symbol(): void
    {
        $repo = ReleaseRepo::make(self::ADDED_NOTES);
        $repo->tag('v0.1.0');
        $repo->write('src/Thing.php', self::thingWithColumn());
        $repo->commit('feat: add column()');

        $run = $repo->scriptRun('blame.php', 'Fixture\\Thing::column');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertStringContainsString(
            'Weighing HEAD against v0.1.0 for "Fixture\Thing::column"' . PHP_EOL,
            $run->output,
            $run->describe(),
        );

        $this->assertStringContainsString(
            '  caught  minor     public API  names it in 1 line(s)' . PHP_EOL,
            $run->output,
            $run->describe(),
        );
        $this->assertStringContainsString(
            '            • added public method Fixture\Thing::column()' . PHP_EOL,
            $run->output,
            $run->describe(),
        );

        // The notes are read as entries, not as headings: the heading weighs a minor and
        // is reported as such, while the entry naming the symbol is reported with it.
        $this->assertStringContainsString(
            '  caught  minor     CHANGELOG   names it in 1 line(s)' . PHP_EOL,
            $run->output,
            $run->describe(),
        );
        $this->assertStringContainsString(
            'the notes name it under ### Added: A new method `Fixture\Thing::column`, which reports a column.',
            $run->output,
            $run->describe(),
        );

        // The line that answers the question the command exists for.
        $this->assertStringContainsString(
            'It contributed: the weighing came out at minor, and it is named in what carried that'
            . ' — CHANGELOG and public API. The bump is a minor.',
            $run->output,
            $run->describe(),
        );

        $this->assertStringContainsString('In the surface now: 1 symbol(s)' . PHP_EOL, $run->output, $run->describe());
        $this->assertStringContainsString('In the surface at v0.1.0: nothing' . PHP_EOL, $run->output, $run->describe());
    }

    /**
     * The middle case, and the one a contributor most often needs: a signal does name the
     * symbol, and the bump did not come from it. A commit with no Conventional Commit type
     * reads as a patch and is quoted as evidence — which is what makes it worth reporting
     * at all — while the notes weigh the minor that actually moves the version.
     *
     * The distinction the command has to draw is between "named" and "contributed", and
     * the two are different sentences because the answers are different.
     */
    public function test_a_signal_that_names_it_below_the_top_did_not_contribute(): void
    {
        $repo = ReleaseRepo::make(self::ADDED_NOTES);
        $repo->tag('v0.1.0');

        // A real change, so the commit has something to carry: the subject is the part
        // the commits signal reads, and an untitled one is what it quotes back.
        $repo->write('GUIDE.md', "See Fixture\\Thing::label.\n");
        $repo->commit('Mention Fixture\Thing::label in the guide');

        $run = $repo->scriptRun('blame.php', 'Fixture\\Thing::label');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertStringContainsString(
            '  caught  patch     commits     names it in 1 line(s)' . PHP_EOL,
            $run->output,
            $run->describe(),
        );
        $this->assertStringContainsString(
            'It contributed nothing to the bump: the weighing came out at minor, and no line naming'
            . ' "Fixture\Thing::label" is in what carried it. The bump is a minor.',
            $run->output,
            $run->describe(),
        );
        $this->assertStringContainsString(
            'It is named below the top, in: commits.' . PHP_EOL,
            $run->output,
            $run->describe(),
        );

        // Naming it is not enough: the reader is owed the signal that did move the bump.
        $this->assertStringContainsString('What did carry it:' . PHP_EOL, $run->output, $run->describe());
        $this->assertStringContainsString(
            '    • CHANGELOG — ### Added — 1 entry, the loudest heading the notes use' . PHP_EOL,
            $run->output,
            $run->describe(),
        );

        // `label()` is untouched, so the surface signals have nothing to say about it —
        // the case that looks like a miss and is not one.
        $this->assertStringContainsString('In the surface now: 1 symbol(s)' . PHP_EOL, $run->output, $run->describe());
        $this->assertStringContainsString('In the surface at v0.1.0: 1 symbol(s)' . PHP_EOL, $run->output, $run->describe());
        $this->assertStringContainsString(
            'it is unchanged, so the public API and config signals have nothing to say about it',
            $run->output,
            $run->describe(),
        );
    }

    /**
     * Nothing names it and nothing holds it: the answer is an exit code and a paragraph,
     * not an error. A name from outside the package, a private member or a local variable
     * all land here, and the bump moved for reasons of its own — which is exactly what
     * somebody who changed a line and saw an unrelated version change needs to be told.
     */
    public function test_a_name_no_signal_and_no_surface_holds_exits_one(): void
    {
        $repo = ReleaseRepo::make();

        $run = $repo->scriptRun('blame.php', 'zzzNotAThing');

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertStringContainsString(
            'Weighing HEAD against (no tag yet) for "zzzNotAThing"' . PHP_EOL,
            $run->output,
            $run->describe(),
        );
        $this->assertStringContainsString(
            'It contributed nothing: no signal names it, so nothing about it was weighed. The bump is a patch.',
            $run->output,
            $run->describe(),
        );
        $this->assertStringContainsString('In the surface now: nothing' . PHP_EOL, $run->output, $run->describe());
        $this->assertStringContainsString(
            'It is not a class, public method, constant, enum case, property, config key or env var of this package',
            $run->output,
            $run->describe(),
        );

        // With no tag there is nothing to diff against, and the command says so rather
        // than reporting a surface signal that never ran.
        $this->assertStringContainsString('note: no release tag yet', $run->output, $run->describe());
    }

    /**
     * A public symbol that exists and did not change. Every signal is quiet about it and
     * the surface holds it at both ends, which is a different answer from the one above:
     * nothing was changed about this name, rather than nothing being able to find it.
     */
    public function test_a_symbol_that_did_not_change_is_held_but_names_no_signal(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v0.1.0');

        $run = $repo->scriptRun('blame.php', 'Fixture\\Thing::weight');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertStringContainsString('It contributed nothing: no signal names it', $run->output, $run->describe());
        $this->assertStringContainsString('In the surface now: 1 symbol(s)' . PHP_EOL, $run->output, $run->describe());
        $this->assertStringContainsString('In the surface at v0.1.0: 1 symbol(s)' . PHP_EOL, $run->output, $run->describe());
        $this->assertStringContainsString(
            'is in both the tree and v0.1.0, and no signal line names it: it is unchanged',
            $run->output,
            $run->describe(),
        );
    }

    /**
     * The inventory as the only witness — a stored row the tree does not back. The symbol
     * is in neither surface, because it is not in the tree at all, so this is also the case
     * where a line of evidence names something the surface reader cannot see: a real answer,
     * and not a lookup that failed.
     */
    public function test_the_inventory_can_be_the_only_signal_that_names_it(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v0.1.0');
        $repo->refreshInventory();
        $repo->inventPublicMethod('Fixture\\Thing', 'ghost');

        $run = $repo->scriptRun('blame.php', 'Fixture\\Thing::ghost');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertStringContainsString(
            '  caught  breaking  inventory   names it in 1 line(s)' . PHP_EOL,
            $run->output,
            $run->describe(),
        );
        $this->assertStringContainsString(
            '            • removed public method Fixture\Thing::ghost()' . PHP_EOL,
            $run->output,
            $run->describe(),
        );
        $this->assertStringContainsString(
            'It contributed: the weighing came out at breaking, and it is named in what carried that'
            . ' — inventory.',
            $run->output,
            $run->describe(),
        );
        $this->assertStringContainsString('In the surface now: nothing' . PHP_EOL, $run->output, $run->describe());
        $this->assertStringContainsString(
            'but a line of signal evidence does',
            $run->output,
            $run->describe(),
        );
    }

    /**
     * A changelog with no `## Unreleased` heading is a state the weighing has a name for —
     * empty notes — and the blame report says which of the two it is looking at. Left
     * unsaid, a quiet notes signal would read as "the changelog does not mention this"
     * rather than "there is nothing to read".
     */
    public function test_a_changelog_with_no_unreleased_heading_is_reported_rather_than_read_as_quiet(): void
    {
        $repo = ReleaseRepo::make();
        $repo->write('CHANGELOG.md', "# Release Notes\n\n## 0.1.0 — a past release\n\n- Old news.\n");

        $run = $repo->scriptRun('blame.php', 'Fixture\\Thing::weight');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertStringContainsString(
            'note: CHANGELOG.md has no `## Unreleased` heading, so the notes signal had nothing to weigh',
            $run->output,
            $run->describe(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The name as it is typed
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The three ways the same name gets written in a shell all reduce to one query, so a
     * quoted and parenthesised name answers byte-for-byte what the bare one does. The
     * header prints the reduced form, which is how a reader sees what was actually
     * searched for.
     */
    public function test_quotes_a_leading_slash_and_a_trailing_pair_are_all_the_same_query(): void
    {
        $repo = ReleaseRepo::make();
        $repo->tag('v0.1.0');

        $bare = $repo->scriptRun('blame.php', 'Fixture\Thing::weight');
        $quoted = $repo->scriptRun('blame.php', "'Fixture\Thing::weight()'");
        $slashed = $repo->scriptRun('blame.php', '\Fixture\Thing::weight');

        $this->assertSame(0, $bare->exitCode, $bare->describe());
        $this->assertSame($bare->output, $quoted->output, $quoted->describe());
        $this->assertSame($bare->output, $slashed->output, $slashed->describe());
        $this->assertStringContainsString(
            'for "Fixture\Thing::weight"' . PHP_EOL,
            $bare->output,
            $bare->describe(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Exit codes
    // ─────────────────────────────────────────────────────────────────────────

    public function test_naming_nothing_is_a_usage_error_and_exits_two(): void
    {
        $repo = ReleaseRepo::make();

        $run = $repo->scriptRun('blame.php');

        $this->assertSame(2, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Name a class, a method, a constant or a config key to ask about.'), $run->describe());
        $this->assertTrue($run->refused('Usage:'), $run->describe());
        $this->assertSame('', $run->output, 'a usage error is not a report');
    }

    public function test_an_unknown_option_is_a_usage_error_and_exits_two(): void
    {
        $repo = ReleaseRepo::make();

        // `--weigh` is the one a reader is most likely to reach for, and it is not this
        // command's: the weighing is read either way, so accepting it would mean silently
        // ignoring an argument somebody thought changed the answer.
        $run = $repo->scriptRun('blame.php', 'Fixture\Thing::weight', '--weigh');

        $this->assertSame(2, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('Unknown option --weigh.'), $run->describe());
        $this->assertSame('', $run->output, 'a usage error is not a report');
    }

    public function test_two_names_is_a_usage_error_and_exits_two(): void
    {
        $repo = ReleaseRepo::make();

        $run = $repo->scriptRun('blame.php', 'Foo', 'Bar');

        $this->assertSame(2, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('One symbol at a time: Foo and Bar were both given.'), $run->describe());
        $this->assertSame('', $run->output, 'a usage error is not a report');
    }

    public function test_a_root_with_no_path_is_a_usage_error_and_exits_two(): void
    {
        $repo = ReleaseRepo::make();

        $run = $repo->scriptRun('blame.php', 'Fixture\Thing::weight', '--root=');

        $this->assertSame(2, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('--root needs a path'), $run->describe());
    }

    public function test_help_exits_zero_and_asks_nothing(): void
    {
        $repo = ReleaseRepo::make();

        $run = $repo->scriptRun('blame.php', '--help');

        $this->assertSame(0, $run->exitCode, $run->describe());
        $this->assertTrue($run->said('php bin/blame.php SYMBOL [options]'), $run->describe());
        $this->assertSame('', $run->error, $run->describe());
    }

    public function test_a_root_with_no_surface_is_not_a_package_and_exits_one(): void
    {
        $repo = ReleaseRepo::make();

        // A real directory that is not a package root: the check is the shape of the tree,
        // so it holds for any path a script is pointed at.
        $run = $repo->scriptRun('blame.php', 'Fixture\Thing::weight', '--root=' . $repo->path('bin'));

        $this->assertSame(1, $run->exitCode, $run->describe());
        $this->assertTrue($run->refused('has no src/ or config/ directory, so there is no surface to weigh.'), $run->describe());
        $this->assertSame('', $run->output, 'there is no weighing to report');
    }

    /**
     * `--root` is honoured rather than being taken and ignored: the tag named in the header
     * is the one in the checkout that was asked about, and this run's own package has no tag
     * at all. That is the whole difference between the two answers.
     */
    public function test_root_answers_about_the_checkout_it_names(): void
    {
        $asked = ReleaseRepo::make();
        $asked->tag('v0.7.0');

        $run = ReleaseRepo::make()->scriptRun('blame.php', 'Fixture\Thing::weight', '--root=' . $asked->path('.'));

        $this->assertStringContainsString(
            'Weighing HEAD against v0.7.0 for "Fixture\Thing::weight"' . PHP_EOL,
            $run->output,
            $run->describe(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The fixture
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A changelog whose loudest heading is `### Added`, with one entry naming the method
     * the surface tests add — so the notes and the surface name the same symbol at the
     * same severity, and one run answers for both.
     */
    private const ADDED_NOTES = <<<'NOTES'
        ### Added

        - A new method `Fixture\Thing::column`, which reports a column.
        NOTES;

    /**
     * The fixture's class with one more public method, and nothing else touched: the
     * change these tests are about is one added row rather than a whole class replaced.
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
