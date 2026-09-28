<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use RuntimeException;

/**
 * PUSHING.md read as data: where it sends a push, and what it tells you to run there.
 *
 * WHY THIS EXISTS
 * ---------------
 * The file is a runbook, and a runbook can be wrong in two ways that matter. It can name
 * the wrong remote — a push to the wrong repository succeeds, publishes nothing, and the
 * only other place that URL is written down is `composer.json`'s homepage, which is what
 * a consumer and Packagist read. And it can quote a command the shell it names cannot
 * run, which is the failure that costs the most and shows the least: a runbook is opened
 * precisely at the moment nobody wants to debug it.
 *
 * So both are parsed out of the file rather than re-typed beside it. The parser is
 * deliberately strict, in the same spirit as Readme: a fence whose language is a shell
 * yields commands, and a fence whose language is anything else — including no language at
 * all, which is how this file writes expected output — is a transcript. A shell fence
 * that quotes nothing, a fence left unclosed, and a command continued onto the next line
 * all raise, because a guard that quietly reads nothing reports agreement with a file it
 * never read.
 */
final class PushingDoc
{
    /** The file sits in the package root, whatever depth the calling test is at. */
    private const FILE = __DIR__.'/../../PUSHING.md';

    /** The package root, which is what a path quoted inside a command is relative to. */
    private const ROOT = __DIR__.'/../..';

    /**
     * The fence languages read as a shell, and the tool that runs one.
     *
     * A fence with any other language, and every fence with no language at all, is a
     * transcript of a command's output rather than something to run.
     */
    private const SHELLS = [
        'powershell' => 'pwsh',
        'ps1' => 'pwsh',
        'bash' => 'bash',
        'sh' => 'bash',
    ];

    /**
     * How many words a tool's own command is written as: `gh`'s commands are two deep
     * (`auth refresh`), and every other tool this file quotes takes one (`push`, `show`,
     * `bin/release.php`). A tool that is not here is read as taking one word.
     */
    private const COMMAND_WORDS = ['gh' => 2, 'git' => 1, 'composer' => 1, 'php' => 1];

    /**
     * Options that take their value as the next word, so the pair is skipped rather than
     * the value being mistaken for the command. `git -c credential.helper= push` is the
     * case this file contains.
     */
    private const VALUED_OPTIONS = ['-c', '-C', '--git-dir', '--work-tree', '--namespace', '--config-env'];

    /**
     * The package root, for turning a path a command quotes into a file to look for.
     */
    public static function root(): string
    {
        return self::ROOT;
    }

    /**
     * Every command the file quotes, in order, with the shell its fence names.
     *
     * A line is one command. A blank line, and a line that opens with `#`, is not one; a
     * leading `NAME=value` — how one command is given an environment — is stripped, so
     * `GIT_TERMINAL_PROMPT=0 git ls-remote …` names `git`.
     *
     * @return list<array{tool: string, program: string, argument: list<string>, line: string, source: int}>
     *
     * @throws RuntimeException when the file is missing, a fence is left open, a shell fence quotes nothing, or a command continues onto the next line
     */
    public static function commands(): array
    {
        $commands = [];
        $fence = null;
        $quoted = 0;

        foreach (self::lines() as $index => $line) {
            $number = $index + 1;
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '```')) {
                if ($fence !== null) {
                    self::closed($fence, $quoted);

                    $fence = null;
                    $quoted = 0;

                    continue;
                }

                $language = trim(substr($trimmed, 3));
                $tool = self::SHELLS[$language] ?? null;

                $fence = $tool === null ? null : ['tool' => $tool, 'language' => $language, 'line' => $number];

                continue;
            }

            if ($fence === null) {
                continue;
            }

            $command = self::command($line, $number);

            if ($command === null) {
                continue;
            }

            $commands[] = $command + ['tool' => $fence['tool'], 'line' => $line, 'source' => $number];
            $quoted++;
        }

        if ($fence !== null) {
            throw new RuntimeException(sprintf(
                'PUSHING.md line %d opens a %s fence that is never closed.',
                $fence['line'],
                $fence['language'],
            ));
        }

        return $commands;
    }

    /**
     * Every http(s) URL in the file, in the order they appear, with the line each is on.
     *
     * The whole file is read, not only the fences: the opening paragraph names the remote
     * and the commands quote it, and a guard that read one of those would miss the
     * sentence a reader trusts most.
     *
     * @return list<array{url: string, source: int}>
     *
     * @throws RuntimeException when the file is not there
     */
    public static function urls(): array
    {
        $urls = [];

        foreach (self::lines() as $index => $line) {
            preg_match_all('#https?://\S+#', $line, $matches);

            foreach ($matches[0] as $url) {
                // The URL is inside markdown as often as it is alone — backticked, bolded,
                // or ended by the sentence's full stop — and the delimiters are the line's
                // punctuation rather than part of the address.
                $urls[] = ['url' => rtrim($url, '`*_)].,;:\'"'), 'source' => $index + 1];
            }
        }

        return $urls;
    }

    /**
     * Every workflow file the file names, as the path it quotes and the line it is on.
     *
     * The scope warning this file carries is about one concrete path — GitHub's refusal names
     * it, so a reader can see which file was refused — and a path is exactly the kind of claim
     * that rots: renaming `.github/workflows/main.yml` would leave a runbook warning about a
     * file this package does not have, which reads as a rule that no longer applies.
     *
     * @return list<array{path: string, source: int}>
     *
     * @throws RuntimeException when the file is not there
     */
    public static function workflowPaths(): array
    {
        $paths = [];

        foreach (self::lines() as $index => $line) {
            preg_match_all('/`(\.github\/workflows\/[^`]+)`/', $line, $matches);

            foreach ($matches[1] as $path) {
                $paths[] = ['path' => $path, 'source' => $index + 1];
            }
        }

        return $paths;
    }

    /**
     * A URL as `owner/repository`, lowercased — the identity that two spellings of one
     * repository share — or null when it is not a GitHub repository URL.
     *
     * `https`, a `.git` suffix, a trailing slash and a `<PAT>@` user are all ways of
     * writing one repository rather than different repositories, so none of them is
     * compared. Anything that is not github.com, or that names no `owner/repository` path,
     * is not this package's repository and is ignored rather than guessed at.
     */
    public static function repository(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ($parts['host'] ?? '') !== 'github.com') {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $path = str_ends_with($path, '.git') ? substr($path, 0, -4) : $path;

        if ($path === '' || substr_count($path, '/') !== 1) {
            return null;
        }

        return strtolower($path);
    }

    /**
     * The tool's own command, as the words after the program: `push` from
     * `git -c credential.helper= push …`, `auth refresh` from `gh auth refresh -h …`, and
     * `bin/release.php` from `php bin/release.php …`. Empty for a line that names none —
     * `cd E:\…`, which is the shell's own command.
     *
     * The complication is that the file writes git's global options *before* its command,
     * and each of those takes its value as the next word: the pairs are skipped, so the
     * value cannot be mistaken for the command.
     *
     * @param list<string> $argument
     * @return list<string>
     */
    public static function subcommand(string $program, array $argument): array
    {
        $count = count($argument);
        $index = 0;

        while ($index < $count) {
            $word = $argument[$index];

            if (in_array($word, self::VALUED_OPTIONS, true)) {
                $index += 2;

                continue;
            }

            if (str_starts_with($word, '-')) {
                $index++;

                continue;
            }

            break;
        }

        $words = [];
        $limit = self::COMMAND_WORDS[$program] ?? 1;

        while ($index < $count && count($words) < $limit && !str_starts_with($argument[$index], '-')) {
            $words[] = $argument[$index];
            $index++;
        }

        return $words;
    }

    /**
     * `composer.json`'s homepage — the repository this package is published from, and the
     * only other record of it in the tree.
     *
     * @throws RuntimeException when the file, or the key, is not there
     */
    public static function declaredHomepage(): string
    {
        $raw = @file_get_contents(self::ROOT.'/composer.json');

        if ($raw === false) {
            throw new RuntimeException('No composer.json to read a homepage from: '.self::ROOT.'/composer.json');
        }

        $composer = json_decode($raw, true);
        $homepage = is_array($composer) ? ($composer['homepage'] ?? null) : null;

        if (!is_string($homepage) || $homepage === '') {
            throw new RuntimeException('composer.json declares no homepage, so PUSHING.md has nothing to agree with.');
        }

        return $homepage;
    }

    /**
     * One line read as a command: its program and the words after it, or null when the
     * line is not one — blank, or a comment.
     *
     * @return array{program: string, argument: list<string>}|null
     *
     * @throws RuntimeException when the line continues onto the next, which this reader does not follow
     */
    private static function command(string $line, int $number): ?array
    {
        $words = self::withoutAssignments(self::words($line));

        if ($words === [] || str_starts_with($words[0], '#')) {
            return null;
        }

        if (str_ends_with(rtrim($line), '`')) {
            throw new RuntimeException(sprintf(
                'PUSHING.md line %d ends with a PowerShell continuation (a backtick): this reader takes one line as one command, so write it on one line or one command per line. Line: %s',
                $number,
                trim($line),
            ));
        }

        return ['program' => $words[0], 'argument' => array_slice($words, 1)];
    }

    /**
     * A line split the way a shell splits it: on unquoted whitespace, with `"` and `'`
     * grouping — so the one git config value in this file, which contains spaces, stays a
     * single word. Quotes are dropped, because they are how a word is written rather than
     * part of the word the tool receives.
     *
     * @return list<string>
     */
    private static function words(string $line): array
    {
        $words = [];
        $word = '';
        $quote = null;
        $started = false;

        foreach (str_split(trim($line)) as $character) {
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;

                    continue;
                }

                $word .= $character;

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                $started = true;

                continue;
            }

            if ($character === ' ' || $character === "\t") {
                if ($started) {
                    $words[] = $word;
                    $word = '';
                    $started = false;
                }

                continue;
            }

            $word .= $character;
            $started = true;
        }

        if ($started) {
            $words[] = $word;
        }

        return $words;
    }

    /**
     * The leading `NAME=value` words removed — how a single command is given an environment
     * on a `bash` line, and never part of the program.
     *
     * @param list<string> $words
     * @return list<string>
     */
    private static function withoutAssignments(array $words): array
    {
        while ($words !== [] && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $words[0]) === 1) {
            array_shift($words);
        }

        return $words;
    }

    /**
     * @param array{tool: string, language: string, line: int} $fence
     *
     * @throws RuntimeException when a shell fence quoted nothing to run
     */
    private static function closed(array $fence, int $quoted): void
    {
        if ($quoted === 0) {
            throw new RuntimeException(sprintf(
                'PUSHING.md line %d opens a %s fence with no command in it. A block that is only output has no language, and this reader refuses to report agreement with one it did not read.',
                $fence['line'],
                $fence['language'],
            ));
        }
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException when the file is not there
     */
    private static function lines(): array
    {
        $raw = @file_get_contents(self::FILE);

        if ($raw === false) {
            throw new RuntimeException('No PUSHING.md to read: '.self::FILE);
        }

        return explode("\n", str_replace("\r\n", "\n", $raw));
    }
}
