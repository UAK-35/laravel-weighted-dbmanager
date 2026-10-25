<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\ComposerScripts;

/**
 * `composer.json` against the decision that says what a consumer receives: a lifecycle event may
 * not run a file `.gitattributes` holds back.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `composer.json` ships to every consumer — it is the one file a dist tarball cannot leave out
 *   — and `bin/` does not, because holding it back is a decision this package made. An entry
 *   that runs a file under it is therefore a line no copy in `vendor/` can run, and the two
 *   halves of that statement live in two files that nothing compared.
 *
 *   Which half matters is the difference between a name and an event. `composer publish-config`
 *   is a name: a human types it into a checkout, where the file is, and holding the script back
 *   is exactly why the README calls the `bin/` commands a checkout's. An event fires by itself
 *   during an install — `pre-…` or `post-…` — and the install that reaches a held-back file is
 *   the one taken from the tarball, where there is no `bin/` at all. That is the shape this
 *   guard refuses, and it is not hypothetical: the two events this file used to declare are why
 *   it exists.
 *
 *   The event is written in the tests rather than taken from `composer.json`, because the file
 *   is the thing being kept clean: a guard whose only exercise is the state it guards stops
 *   being exercised the moment it works. It is `tests/Support/ComposerScripts.php` that reads
 *   the file.
 */
final class ComposerScriptsTest extends TestCase
{
    /**
     * The guard itself, and it is empty by decision rather than by accident: this package names
     * no lifecycle event at all, so the day one comes back is the day this has something to say.
     *
     * An event fires in the tarball as well as in a checkout, so a path it reaches has to be one
     * the tarball carries — `docs/`, `config/`, `src/`, `README.md`, `API.md`, `LICENSE` and
     * `composer.json` itself — and not one of the tools under `bin/`.
     */
    public function test_no_event_this_package_declares_runs_a_path_the_tarball_holds_back(): void
    {
        $declared = ComposerScripts::declared(self::root());
        $findings = self::findings(ComposerScripts::events($declared), $declared);

        self::assertSame([], $findings, sprintf(
            "composer.json fires a lifecycle event that runs a file .gitattributes holds back, so an install from\n"
            ."the dist tarball runs a file that is not in the copy it is installing:\n  - %s",
            implode("\n  - ", $findings),
        ));
    }

    /**
     * The reading, against the file it is about: the section is not empty, a declared script
     * reaches a tool under `bin/`, and git is the one that says the tool is held back.
     *
     * Without this the guard above would pass for a reader that found no scripts, a regular
     * expression that matched nothing, an alias nobody followed, and a `.gitattributes` nobody
     * asked about — five ways of agreeing with a tree that leaks nothing.
     */
    public function test_the_reading_finds_the_scripts_and_the_attributes_this_file_has(): void
    {
        $declared = ComposerScripts::declared(self::root());
        $paths = ComposerScripts::paths($declared, $declared);

        self::assertArrayHasKey('bin/tool.php', $paths, sprintf(
            'The scripts composer.json declares no longer reach bin/tool.php, so this reading found %d path(s) and\n'
            .'is not a reading of this composer.json: [%s]',
            count($paths),
            implode(', ', array_keys($paths)),
        ));

        $attributes = ComposerScripts::heldBack(self::root(), array_keys($paths));

        self::assertSame('set', $attributes['bin/tool.php'] ?? null, sprintf(
            'git says bin/tool.php is [%s], and a .gitattributes that does not hold it back is not the one this\n'
            .'reading is written against.',
            $attributes['bin/tool.php'] ?? 'nothing',
        ));
    }

    /**
     * The teeth, at the shape the deleted entries had — and the shape is an alias, which is the
     * half that makes this worth writing: `post-install-cmd` ran `@publish-config`, so a reading
     * that stopped at the line in front of it found no path at all and passed on the very defect
     * it was written for.
     *
     * Reported with both halves a reader needs: the event that fires it and the file that cannot
     * run, with the chain the event reaches it by.
     */
    public function test_an_event_reaching_a_held_back_script_through_a_name_is_reported(): void
    {
        $findings = self::findings(
            ['post-install-cmd' => ['@publish-config']],
            ['publish-config' => ['@php bin/publish-config.php']],
        );

        self::assertCount(1, $findings, 'An event reaching a held-back script through a name was not reported at all.');

        self::assertStringContainsString('bin/publish-config.php', $findings[0], 'The finding has to name the file that cannot run.');
        self::assertStringContainsString('post-install-cmd', $findings[0], 'The finding has to name the event that fires it.');
        self::assertStringContainsString('@publish-config →', $findings[0], 'The finding has to name the name it was reached through.');
    }

    /**
     * An event that names the held-back file outright is reported too, so the guard does not
     * depend on the indirection it was written for.
     */
    public function test_an_event_running_a_held_back_script_outright_is_reported(): void
    {
        $findings = self::findings(['post-update-cmd' => ['@php bin/publish-config.php']], []);

        self::assertCount(1, $findings, 'An event running a held-back script as a path was not reported.');
        self::assertStringContainsString('bin/publish-config.php', $findings[0]);
    }

    /**
     * The other side of the teeth, so the guard is not one that refuses everything: an event
     * reaching a shipped path — outright or through a name — is not a finding, a bare word is
     * not a path, and a path the tree does not carry is not one either. This asks what a copy
     * *carries*, and a name git has no pattern for is told apart from a name it holds back.
     */
    public function test_an_event_running_a_shipped_path_is_not_reported(): void
    {
        $clean = ['post-update-cmd' => ['@php config/db-manager.php', '@db', 'pint']];
        $names = ['db' => ['@php config/db-manager.php']];

        self::assertSame([], self::findings($clean, $names), sprintf(
            "This reading reports files that ship, names that reach one, or bare words, which is a guard that would\n"
            ."be turned off rather than fixed. It answered: [%s]",
            implode(', ', self::findings($clean, $names)),
        ));
    }

    /**
     * A path inside an export-ignored directory counts as held back, which is the half of
     * `.gitattributes` written as directories and the half `check-attr` does not answer on its
     * own: `tests` is `set`, `tests/Support/X.php` is `unspecified`, and `git archive` carries
     * neither. A reading that stopped at the file would have passed an event running one.
     */
    public function test_an_event_reaching_a_file_inside_an_ignored_directory_is_reported(): void
    {
        $findings = self::findings(['post-autoload-dump' => ['@php tests/Support/PgcatInstallation.php']], []);

        self::assertCount(1, $findings, 'A file inside tests/ was not read as held back, because its directory is what holds it back.');
        self::assertStringContainsString('tests/Support/PgcatInstallation.php', $findings[0]);
    }

    /**
     * Git is the source of the answer, and its answer is about the patterns rather than the disk:
     * a held-back tool is `set`, a file that ships is `unspecified`, and a path that is not there
     * is `unspecified` as well — which is why the guard is a question about the tarball rather
     * than a check that a file exists. A file under an ignored *directory* is `set` too, which is
     * asked about through the directories above it.
     */
    public function test_the_answer_comes_from_gitattributes_and_not_from_the_disk(): void
    {
        $attributes = ComposerScripts::heldBack(self::root(), [
            'bin/checks.php',
            'bin/publish-config.php',
            'tests/Support/ComposerScripts.php',
            'composer.json',
            'config/db-manager.php',
            'bin/gone.php',
        ]);

        self::assertSame([
            'bin/checks.php' => 'set',
            'bin/publish-config.php' => 'set',
            'tests/Support/ComposerScripts.php' => 'set',
            'composer.json' => 'unspecified',
            'config/db-manager.php' => 'unspecified',
            'bin/gone.php' => 'unspecified',
        ], $attributes);
    }

    /**
     * The directories `composer lint` and `composer test:lint` hand Pint, against the ones
     * `bin/checks.php` reads for its own PHP rows.
     *
     * WHY THIS EXISTS
     * ---------------
     *   Pint with no path reads every PHP file under the directory it starts in, which is this
     *   checkout — so a tree at the root that no commit carries was inside the style gate until
     *   both surfaces were narrowed to `src`, `tests`, `config` and `bin`: the package's own PHP,
     *   and nothing else. That is one list written in two files, and nothing compared them. A
     *   widening of one that misses the other is silent in the direction that matters — the gate's
     *   row and the composer command then answer different questions about the same tree, and the
     *   one a developer runs is not the one CI runs.
     *
     *   The comparison is by name rather than by content: what a root holds is `phpFiles()`'s
     *   business, and the claim here is only that both readers are pointed at the same places.
     *
     * WHY BOTH SCRIPTS AND NOT JUST THE GATE
     * --------------------------------------
     *   `lint` writes and `test:lint` reports, so a disagreement between them is the worst of the
     *   three: a file the fixer formats and the check refuses, or the reverse. They are read
     *   separately rather than assumed equal because the `--test` flag is the only difference
     *   between them, and a root dropped from one of the two lines is exactly how that drifts.
     */
    public function test_the_lint_scripts_and_the_gate_read_the_same_roots(): void
    {
        $declared = ComposerScripts::declared(self::root());

        $scripts = [];

        foreach (['lint', 'test:lint'] as $name) {
            self::assertArrayHasKey($name, $declared, sprintf(
                'composer.json no longer declares `%s`, so this reading would hold the gate to nothing.',
                $name,
            ));

            $scripts[$name] = self::pintRoots($declared[$name], $name);
        }

        $gate = self::gateRoots(self::root().'/bin/checks.php');

        self::assertNotSame([], $gate, 'No `$phpFiles = phpFiles([…])` call was found in bin/checks.php, so the gate side of this comparison was read as nothing at all.');

        self::assertSame($scripts['test:lint'], $scripts['lint'], sprintf(
            "`composer lint` and `composer test:lint` hand Pint different directories, so the fixer and the style\n".
            "check disagree about what this package's PHP is:\n  lint:      [%s]\n  test:lint: [%s]",
            implode(', ', $scripts['lint']),
            implode(', ', $scripts['test:lint']),
        ));

        self::assertSame($gate, $scripts['test:lint'], sprintf(
            "The composer lint scripts and the gate's PHP rows read different directories, so one of them checks a\n".
            "set the other never sees — and CI runs the gate while a developer runs the composer script:\n".
            "  bin/checks.php: [%s]\n  composer.json:  [%s]\n\n".
            'Widen or narrow both: the four roots are what keeps a tree at the package root outside the style row.',
            implode(', ', $gate),
            implode(', ', $scripts['test:lint']),
        ));

        // A root nobody has is a row that reads nothing, which is the failure the equality above
        // cannot see: two lists of the same wrong name agree with each other.
        foreach ($gate as $root) {
            self::assertDirectoryExists(
                self::root().'/'.$root,
                sprintf('Both the gate and the composer lint scripts name `%s` as a root, and this checkout has no such directory.', $root),
            );
        }
    }

    /**
     * The directory arguments one composer script passes Pint.
     *
     * The line is split the way a shell would and read after the tool name, so a flag (`--test`)
     * is not mistaken for a directory and a change of order does not read as a change of set.
     *
     * @param list<string> $commands
     * @return list<string>
     */
    private static function pintRoots(array $commands, string $script): array
    {
        $roots = [];

        foreach ($commands as $command) {
            $tokens = preg_split('/\s+/', trim($command)) ?: [];
            $at = array_search('pint', $tokens, true);

            if ($at === false) {
                continue;
            }

            foreach (array_slice($tokens, $at + 1) as $token) {
                if (! str_starts_with($token, '-')) {
                    $roots[] = $token;
                }
            }
        }

        self::assertNotSame([], $roots, sprintf(
            '`composer %s` names no directory after `pint`, so Pint would read the whole checkout again — the state this pair of lists exists to end.',
            $script,
        ));

        $roots = array_values(array_unique($roots));
        sort($roots);

        return $roots;
    }

    /**
     * The roots `bin/checks.php` builds `$phpFiles` from, read out of its own call.
     *
     * Read rather than restated: a second copy of the list here would be a third place to widen,
     * and this reading exists to hold two places together rather than to add one.
     *
     * @return list<string>
     */
    private static function gateRoots(string $file): array
    {
        $source = @file_get_contents($file);

        if ($source === false) {
            self::fail('bin/checks.php could not be read, so the gate side of this comparison is missing rather than equal.');
        }

        if (preg_match('/\$phpFiles = phpFiles\(\[(.*?)\]\);/s', $source, $call) !== 1) {
            return [];
        }

        preg_match_all("/'([^']+)'/", $call[1], $literals);

        $roots = [];

        foreach ($literals[1] as $literal) {
            $roots[] = ltrim($literal, '/');
        }

        sort($roots);

        return $roots;
    }

    /**
     * The findings in a set of entries: the path a command reaches, and the lines that reach it,
     * for every path git holds back.
     *
     * @param array<string, list<string>> $from the entries to walk
     * @param array<string, list<string>> $all  the section an `@name` is resolved against
     * @return list<string>
     */
    private static function findings(array $from, array $all): array
    {
        $paths = ComposerScripts::paths($from, $all);
        $attributes = ComposerScripts::heldBack(self::root(), array_keys($paths));

        $findings = [];

        foreach ($attributes as $path => $attribute) {
            if ($attribute === 'set') {
                $findings[] = sprintf('%s, reached by %s', $path, implode('; ', $paths[$path]));
            }
        }

        return $findings;
    }

    /** The package root: this file is two directories below it, in `tests/Unit/Support`. */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
