<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * What this package's own `composer.json` asks Composer to run, and what a consumer's copy of
 * the package can run in reply.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `composer.json` is the one file a dist tarball cannot leave out: Packagist reads it,
 *   Composer reads it, and `.gitattributes` therefore lists it nowhere. So a command it names
 *   is named in every copy of this package that has ever been installed — including the
 *   tarball, which is not a checkout and carries only the paths `.gitattributes` lets through.
 *   `bin/` is held back whole, and that is a decision rather than an accident, so an entry
 *   naming a script under it names a file the consumer does not have.
 *
 *   For a script with a *name* that is nobody's problem: `composer publish-config` is typed by
 *   a human into a checkout, where the file is, and this reading deliberately says nothing
 *   about it. It is different for a **lifecycle event**, which fires while a machine is doing
 *   something else and has no human to notice. The shape that reaches one is not a clone but
 *   the tarball — `composer create-project`, or any root install taken with `preferred-install:
 *   dist`, which is what this package's own config prefers — and there the install runs a file
 *   that is not in the copy being installed.
 *
 *   It was not theoretical. `post-install-cmd` and `post-update-cmd` here ran
 *   `bin/publish-config.php` until this reading was written, and an install from an export of
 *   the tree failed on it:
 *
 *     > @php bin/publish-config.php
 *     Could not open input file: bin\publish-config.php
 *     Script @php bin/publish-config.php handling the publish-config event returned with error code 1
 *
 *   Inside the package's own root the same line could never have done anything: there is no
 *   `artisan` here, so the publisher answers "not a Laravel application — nothing to publish"
 *   and exits 0. It was an event with no effect in the place it could run and a failure in the
 *   other, which is why it is held back rather than made conditional.
 *
 * THE TWO HALVES OF A COMMAND, AND WHY BOTH ARE READ
 * --------------------------------------------------
 *   An event is spelled `pre-…` or `post-…`. Every one of Composer's own lifecycle events is
 *   written that way, so the reading needs no list of them and cannot fall behind one; anything
 *   else in `scripts` is a name, and a name is walked here only so the teeth can be shown to
 *   bite at the shape the events section used to have.
 *
 *   A command line is not the whole of what runs, either: `@name` is another entry of this same
 *   section, and Composer substitutes it — which is how the two deleted events were written
 *   (`"post-install-cmd": ["@publish-config"]`), so a reading that stopped at the line in front
 *   of it would have found no path at all and agreed with the defect it was written for. An
 *   alias is therefore followed, and the chain it was reached by is carried into the finding,
 *   because that is what a reader has to edit. A cycle simply stops.
 *
 * WHAT IS A FINDING, AND WHAT IS NOT
 * ----------------------------------
 *   A finding is a path token in an event's command that `.gitattributes` holds back, and the
 *   question is put to git with `check-attr export-ignore` — the patterns are git's to read, so
 *   a second parser of them would only be a second answer, and the one that mattered would be
 *   git's.
 *
 *   A token is a run of `[A-Za-z0-9_.-]` segments joined by `/`, with one leading `./` taken
 *   off, which is how a command line names a file. Nothing else is read as one: a bare word
 *   (`pint`), a Composer binary alias (`@php`), a flag and the value of one are not paths. A URL
 *   is asked about rather than special-cased, because a path git has no pattern for answers
 *   `unspecified` and a URL's host is such a path — and, for the same reason, a token whose file
 *   is gone is not a finding either. This asks what a copy *carries*, not what the disk holds.
 *
 *   A path is held back by a directory above it as well as by a pattern of its own, and the two
 *   are not the same question to git: `check-attr` does not recurse — `tests` is `set` while
 *   `tests/support/x.php` answers `unspecified` — and `git archive`, which is what actually
 *   builds the tarball, prunes the subtree. So every directory above a token is asked about too,
 *   and a token is held back when it or anything it sits inside is. Without that the reading
 *   would have missed the half of `.gitattributes` that is written as directories, which is
 *   `tests/`, `.agents/` and `.idea`.
 *
 * @see ComposerScriptsTest for the assertions, including the one that shows the teeth.
 */
final class ComposerScripts
{
    /**
     * Every script `composer.json` declares, name => the command lines it runs, in the order it
     * writes them.
     *
     * The whole section is returned rather than the events alone, because an event may reach a
     * path through a name, and a caller that has only the events cannot resolve one.
     *
     * @return array<string, list<string>>
     *
     * @throws RuntimeException when there is no `composer.json`, it is not JSON this can read,
     *                          or it declares no scripts — each of which is a reading of
     *                          nothing rather than a clean tree.
     */
    public static function declared(string $root): array
    {
        $raw = @file_get_contents($root.'/composer.json');

        if ($raw === false) {
            throw new RuntimeException("No composer.json to read at {$root}/composer.json");
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("{$root}/composer.json is not JSON this can read, so no script of it was read");
        }

        $scripts = $decoded['scripts'] ?? null;

        if (!is_array($scripts) || $scripts === []) {
            throw new RuntimeException("{$root}/composer.json declares no scripts, so this reading has nothing to be about");
        }

        $declared = [];

        foreach ($scripts as $name => $commands) {
            $lines = [];

            // One command, or a list of them: both are how Composer writes the section.
            foreach ((array) $commands as $command) {
                if (is_string($command)) {
                    $lines[] = $command;
                }
            }

            $declared[(string) $name] = $lines;
        }

        return $declared;
    }

    /**
     * The scripts that fire by themselves, which is every name Composer spells as a lifecycle
     * event.
     *
     * @param array<string, list<string>> $declared
     * @return array<string, list<string>>
     */
    public static function events(array $declared): array
    {
        return array_filter(
            $declared,
            static fn (string $name): bool => preg_match('/^(pre|post)-/', $name) === 1,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The path tokens the commands in `$from` name, as path => the lines that name it, with any
     * `@name` already substituted from `$all`.
     *
     * The naming lines are kept rather than dropped so a failure can say which event fired the
     * command, which is the half of the finding a reader has to edit.
     *
     * @param array<string, list<string>> $from the entries to walk — every event, or the whole section
     * @param array<string, list<string>> $all  the section an `@name` is resolved against
     * @return array<string, list<string>>
     */
    public static function paths(array $from, array $all): array
    {
        $paths = [];

        foreach ($from as $name => $commands) {
            foreach ($commands as $command) {
                foreach (self::expanded($command, $all) as $line) {
                    foreach (self::tokens($line) as $path) {
                        $paths[$path][] = sprintf('%s: %s', $name, $line);
                    }
                }
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * What git says about `export-ignore` for each path, keyed by path.
     *
     * An answer of `unspecified` is what a path no pattern matches — including one the tree does
     * not carry — comes back as, and a path inside an export-ignored directory answers `set`,
     * because that is what the tarball does with it.
     *
     * @param list<string> $paths
     * @return array<string, string>
     *
     * @throws RuntimeException when git cannot be asked, or answers about fewer paths than were
     *                          put to it — half a comparison is not one.
     */
    public static function heldBack(string $root, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        // git decides a path by a pattern of its own *and* by an export-ignored directory above
        // it, and `check-attr` only answers the first: it does not recurse, while the `git
        // archive` that builds the tarball prunes the whole subtree. So the directories above
        // every token are asked about in the same breath, and a token is held back when it, or
        // anything it sits inside, is.
        $asked = [];

        foreach ($paths as $path) {
            foreach (self::chain($path) as $question) {
                $asked[$question] = true;
            }
        }

        $answers = self::ask($root, array_keys($asked));
        $attributes = [];

        foreach ($paths as $path) {
            $held = 'unspecified';

            foreach (self::chain($path) as $question) {
                if (($answers[$question] ?? 'unspecified') === 'set') {
                    $held = 'set';

                    break;
                }
            }

            $attributes[$path] = $held;
        }

        return $attributes;
    }

    /**
     * One path and every directory above it, innermost first: `bin/tool.php` is itself and `bin`,
     * and `tests/Support/X.php` is itself, `tests/Support` and `tests`.
     *
     * @return list<string>
     */
    private static function chain(string $path): array
    {
        $segments = explode('/', $path);
        $chain = [];

        for ($depth = count($segments); $depth >= 1; $depth--) {
            $chain[] = implode('/', array_slice($segments, 0, $depth));
        }

        return $chain;
    }

    /**
     * What git says about `export-ignore` for each of these paths, keyed by path.
     *
     * Asked in one call, because the patterns decide and the question is only put to the tool
     * that reads them. `unspecified` is what a path no pattern matches comes back as.
     *
     * @param list<string> $paths
     * @return array<string, string>
     *
     * @throws RuntimeException when git cannot be asked, or answers about fewer paths than were
     *                          put to it — half a comparison is not one.
     */
    private static function ask(string $root, array $paths): array
    {
        $process = new Process(
            ['git', '-c', 'core.quotepath=false', 'check-attr', 'export-ignore', '--', ...$paths],
            $root,
        );
        $process->setTimeout(120);
        $process->run(null, ['GIT_TERMINAL_PROMPT' => '0', 'GIT_PAGER' => 'cat']);

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'git could not be asked what %s holds back: %s',
                $root,
                trim($process->getErrorOutput()) ?: 'git said nothing',
            ));
        }

        $answers = [];

        foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
            if (preg_match('/^(.*?): export-ignore: (.*)$/', trim($line), $matches) === 1) {
                $answers[$matches[1]] = $matches[2];
            }
        }

        if (count($answers) !== count($paths)) {
            throw new RuntimeException(sprintf(
                'git answered about %d of the %d paths put to it, so part of this comparison would have been against nothing',
                count($answers),
                count($paths),
            ));
        }

        return $answers;
    }

    /**
     * The command lines one entry actually runs, which is the line as written unless it is an
     * `@name` this section declares — in which case it is the entry that name holds, prefixed by
     * the name it was reached through.
     *
     * The prefix is the chain rather than the substitution, so a finding can print the line a
     * reader recognises from `composer.json` and the command that line turns into.
     *
     * @param array<string, list<string>> $all
     * @param list<string>                $seen the names already followed, so a cycle stops
     * @return list<string>
     */
    private static function expanded(string $command, array $all, array $seen = []): array
    {
        if (preg_match('/^@([A-Za-z0-9_.-]+)$/', $command, $matches) !== 1
            || !isset($all[$matches[1]])
            || in_array($matches[1], $seen, true)
        ) {
            // Not an alias this section declares: `@php`, `@composer` and any other line are the
            // line, and whatever paths it names are its own.
            return [$command];
        }

        $lines = [];

        foreach ($all[$matches[1]] as $held) {
            foreach (self::expanded($held, $all, [...$seen, $matches[1]]) as $line) {
                $lines[] = $command.' → '.$line;
            }
        }

        return $lines;
    }

    /**
     * The path tokens in one command line, in the order they are written.
     *
     * @return list<string>
     */
    private static function tokens(string $command): array
    {
        preg_match_all('~[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+~', $command, $matches);

        $tokens = [];

        foreach ($matches[0] as $token) {
            $tokens[] = preg_replace('~^\./~', '', $token) ?? $token;
        }

        return $tokens;
    }
}
