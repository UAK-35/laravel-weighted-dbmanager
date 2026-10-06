<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use RuntimeException;

/**
 * The `.agents/skills/pkg-*` frontmatter descriptions, and the index that lists the skills,
 * read as data.
 *
 * WHY THIS EXISTS
 * ---------------
 * A skill's frontmatter `description` is the only part of it a tool reads before choosing it:
 * it is what an index of skills shows, what a `@name` invocation matches, and the line that
 * decides whether the skill is invoked at all. It was also the part nothing read: the guards
 * under `tests/` hold the README, `RELEASING.md`, `PUSHING.md` and `docs/` to what they name,
 * and none of them opened `.agents/` — so the skills were the one corpus in this tree whose
 * prose had no reader to disagree with it.
 *
 * The gap has exactly one shape that costs anything: a description advertising something the
 * package no longer has. Rename a `bin/` script, a console command or a skill, and the
 * description that named it goes on reading as true while sending a reader to a command the
 * tree no longer answers. Nothing fails, which is the defect `DocCitationsTest` and the README
 * tables exist to catch on the documents.
 *
 * So the descriptions are parsed rather than trusted. Four shapes are read as a name of
 * something this package answers for:
 *
 *   1. `@handle` — an invocation of a skill, held to the directories under `.agents/skills/`;
 *   2. `bin/…` (with or without a `php` in front) — a script in this package's `bin/`;
 *   3. `db:…` — a console command, held to the `$signature` every command class declares;
 *   4. `composer …` — a script, held to `composer.json`'s `scripts`.
 *
 * Everything else backticked is prose and is not read: a description legitimately names flags
 * (`--weigh`), config keys (`db-manager.swrr.pgcat.schedule.windows`), documents
 * (`RELEASING.md`) and branches (`dev`), and reading those as commands would make this a
 * spell-checker rather than a guard.
 *
 * The parser is strict in the house spirit, because a guard that quietly reads nothing reports
 * agreement with a file it never read: a SKILL.md whose frontmatter block is missing, whose
 * `name` is missing, whose name disagrees with the folder it sits in, or whose `description` is
 * empty each raise, and so does an index that has no table with a `Skill` column in it.
 *
 * The one restatement here is `COMPOSER_OWN`. Telling this package's script (`composer
 * release`) from one of Composer's own subcommands (`composer install`) cannot be done from
 * this tree, and the suite runs where `composer` is not on `PATH` and the phar is gitignored —
 * so the subcommands are written down instead. They are Composer's surface rather than the
 * package's, and a subcommand a later Composer adds is a finding a reader can dismiss, which is
 * the direction this guard errs in.
 */
final class SkillDescriptions
{
    /** The package root, whatever depth the calling test sits at. */
    private const ROOT = __DIR__.'/../..';

    /** Where a skill lives: one directory per skill, one SKILL.md in each. */
    private const SKILLS = '.agents/skills';

    /** The index a person opens to find out which skills exist. */
    private const INDEX = '.agents/README.md';

    /** The index column a skill's name is written in. */
    private const SKILL_COLUMN = 'Skill';

    /** The index column that says what the skill does — the second corpus this reads. */
    private const PURPOSE_COLUMN = 'What it does';

    /**
     * Composer's own subcommands: what a `composer …` a description names may be without it
     * being a script of this package's. See the class docblock for why this is written down.
     */
    private const COMPOSER_OWN = [
        'about', 'archive', 'audit', 'check-platform-reqs', 'clear-cache', 'completion',
        'config', 'create-project', 'depends', 'diagnose', 'dump-autoload', 'exec', 'fund',
        'global', 'help', 'init', 'install', 'licenses', 'list', 'outdated', 'prohibits',
        'reinstall', 'remove', 'require', 'run-script', 'self-update', 'show', 'status',
        'suggests', 'update', 'validate', 'why', 'why-not',
    ];

    /**
     * Every skill this package has, keyed by the name an invocation resolves.
     *
     * @return array<string, array{name: string, description: string, source: string}>
     *
     * @throws RuntimeException when a skill is missing, or its frontmatter names nothing this reader can hold it to
     */
    public static function skills(): array
    {
        $files = glob(self::ROOT.'/'.self::SKILLS.'/*/SKILL.md') ?: [];

        if ($files === []) {
            throw new RuntimeException(
                'No skill is under '.self::SKILLS.', so there is nothing for this guard to hold to the package. '.
                'An empty answer here is the one failure it cannot report.',
            );
        }

        $skills = [];

        foreach ($files as $file) {
            $folder = basename(dirname($file));
            $source = self::SKILLS.'/'.$folder.'/SKILL.md';

            $frontmatter = self::frontmatter((string) file_get_contents($file), $source);
            $name = $frontmatter['name'] ?? '';
            $description = trim($frontmatter['description'] ?? '');

            if ($name !== $folder) {
                throw new RuntimeException(sprintf(
                    '%s declares the name [%s], and the folder it sits in is [%s] — the folder is what an invocation resolves, so the two cannot disagree.',
                    $source,
                    $name,
                    $folder,
                ));
            }

            if ($description === '') {
                throw new RuntimeException(sprintf(
                    '%s declares no frontmatter `description`, which is the only part of it a tool reads before choosing the skill.',
                    $source,
                ));
            }

            $skills[$name] = ['name' => $name, 'description' => $description, 'source' => $source];
        }

        ksort($skills);

        return $skills;
    }

    /**
     * The skills the index lists, with the sentence it describes each one in.
     *
     * @return list<array{skill: string, description: string, source: int}>
     *
     * @throws RuntimeException when the index is missing, has no skills table, or has a row whose shape is not the header's
     */
    public static function indexed(): array
    {
        $raw = @file_get_contents(self::ROOT.'/'.self::INDEX);

        if ($raw === false) {
            throw new RuntimeException('No index to read the skills from: '.self::INDEX);
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $raw));

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (! str_starts_with($trimmed, '|')) {
                continue;
            }

            $header = self::cells($trimmed);
            $skill = self::column($header, self::SKILL_COLUMN);
            $purpose = self::column($header, self::PURPOSE_COLUMN);

            if ($skill === null || $purpose === null) {
                continue;
            }

            return self::rows($lines, $index, $header, $skill, $purpose);
        }

        throw new RuntimeException(sprintf(
            '%s has no table with a [%s] column, so nothing is read as the list of skills.',
            self::INDEX,
            self::SKILL_COLUMN,
        ));
    }

    /**
     * Every `@handle` a text names — the way one skill is invoked.
     *
     * The lookbehind keeps an address out of it: `noreply@codebuff.com` is an email in a
     * commit trailer rather than an invocation, and a handle is written at the start of a
     * word.
     *
     * @return list<string>
     */
    public static function handles(string $text): array
    {
        preg_match_all('/(?<![A-Za-z0-9_.])@([a-z][a-z0-9-]*)/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Every command a text names in one of the shapes this package answers for, in order.
     *
     * @return list<array{shape: string, name: string}>
     */
    public static function commands(string $text): array
    {
        preg_match_all('/`([^`\n]+)`/', $text, $matches);

        $commands = [];

        foreach ($matches[1] as $quoted) {
            $words = preg_split('/\s+/', trim($quoted)) ?: [];

            // A block that runs a script writes the interpreter first — `php bin/checks.php
            // --only=counts` — and the interpreter is not what the text is naming.
            while ($words !== [] && in_array(strtolower($words[0]), ['php', 'php.exe', '$php'], true)) {
                array_shift($words);
            }

            if ($words === []) {
                continue;
            }

            if (preg_match('#^bin/[A-Za-z0-9._-]+$#', $words[0]) === 1) {
                $commands[] = ['shape' => 'bin', 'name' => $words[0]];

                continue;
            }

            if (preg_match('/^[a-z][a-z0-9-]*:[a-z0-9-]+$/', $words[0]) === 1) {
                $commands[] = ['shape' => 'console', 'name' => $words[0]];

                continue;
            }

            if ($words[0] === 'composer') {
                foreach (array_slice($words, 1) as $word) {
                    if (str_starts_with($word, '-')) {
                        continue;
                    }

                    $commands[] = ['shape' => 'composer', 'name' => $word];

                    break;
                }
            }
        }

        return $commands;
    }

    /**
     * Everything a text names that this package does not have, in the order it names them —
     * one sentence per finding, naming the text's own words so the repair is the edit rather
     * than a search.
     *
     * @return list<string>
     */
    public static function missing(string $text): array
    {
        $skills = self::skills();
        $findings = [];

        foreach (self::handles($text) as $handle) {
            if (! array_key_exists($handle, $skills)) {
                $findings[] = sprintf(
                    '@%s is not a skill this package has (it has %s).',
                    $handle,
                    implode(', ', array_map(
                        static fn (string $name): string => '@'.$name,
                        array_keys($skills),
                    )),
                );
            }
        }

        foreach (self::commands($text) as $command) {
            if (self::resolves($command)) {
                continue;
            }

            $findings[] = match ($command['shape']) {
                'bin' => sprintf('`%s` is not a script in this package\'s bin/.', $command['name']),
                'console' => sprintf('`%s` is not a console command this package registers.', $command['name']),
                default => sprintf(
                    '`composer %s` is neither a script composer.json declares nor one of Composer\'s own subcommands.',
                    $command['name'],
                ),
            };
        }

        return $findings;
    }

    /**
     * @param array{shape: string, name: string} $command
     */
    private static function resolves(array $command): bool
    {
        return match ($command['shape']) {
            'bin' => is_file(self::ROOT.'/'.$command['name']),
            'console' => in_array($command['name'], self::consoleCommands(), true),
            default => in_array($command['name'], self::composerCommands(), true),
        };
    }

    /**
     * The commands this package registers, read from the `$signature` each of them declares —
     * the one place a command's name is written for the framework.
     *
     * @return list<string>
     *
     * @throws RuntimeException when a command class declares a signature this reader cannot read
     */
    private static function consoleCommands(): array
    {
        $files = glob(self::ROOT.'/src/Console/Commands/*.php') ?: [];
        $commands = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match("/protected \\\$signature\\s*=\\s*'([a-z][a-z0-9:-]*)/", $source, $matches) !== 1) {
                throw new RuntimeException(sprintf(
                    '%s declares no signature this reader can read, so its command would look like one that does not exist.',
                    'src/Console/Commands/'.basename($file),
                ));
            }

            $commands[] = $matches[1];
        }

        sort($commands);

        return $commands;
    }

    /**
     * The scripts `composer.json` declares, plus Composer's own subcommands — the two things a
     * `composer …` in a description can name.
     *
     * @return list<string>
     *
     * @throws RuntimeException when the manifest, or its `scripts`, is not there
     */
    private static function composerCommands(): array
    {
        $raw = @file_get_contents(self::ROOT.'/composer.json');

        if ($raw === false) {
            throw new RuntimeException('No composer.json to read the declared scripts from.');
        }

        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($manifest) || ! isset($manifest['scripts']) || ! is_array($manifest['scripts'])) {
            throw new RuntimeException('composer.json declares no scripts, so a `composer …` in a description cannot be resolved.');
        }

        return array_merge(array_keys($manifest['scripts']), self::COMPOSER_OWN);
    }

    /**
     * The frontmatter of a SKILL.md: the `key: value` pairs between the opening and closing
     * `---`, with an indented line continuing the value above it.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when the block, or the `name` in it, is not there
     */
    private static function frontmatter(string $raw, string $source): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $raw));

        if (trim($lines[0] ?? '') !== '---') {
            throw new RuntimeException(sprintf(
                '%s opens with no `---` frontmatter block, so there is no description to read.',
                $source,
            ));
        }

        $fields = [];
        $key = null;

        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '---') {
                if (! isset($fields['name'])) {
                    throw new RuntimeException(sprintf(
                        '%s declares no frontmatter `name`, so nothing names the skill an invocation resolves.',
                        $source,
                    ));
                }

                return $fields;
            }

            if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*):\s*(.*)$/', $line, $matches) === 1) {
                $key = $matches[1];
                $fields[$key] = trim($matches[2]);

                continue;
            }

            // A wrapped value continues on the line below it, which is how YAML writes a
            // sentence that does not fit in the 100 columns this repository wraps prose at.
            if ($key !== null && trim($line) !== '') {
                $fields[$key] .= ' '.trim($line);
            }
        }

        throw new RuntimeException(sprintf('%s opens a frontmatter block that is never closed.', $source));
    }

    /**
     * The rows of the index's skills table, from the line after its header.
     *
     * The `description` cell is handed back exactly as the file writes it, backticks included:
     * the backticks are what tell `commands()` a word is a command rather than prose, so
     * stripping them here would make the index's own command names invisible to the reader this
     * class exists to be.
     *
     * @param list<string> $lines
     * @param list<string> $header
     * @return list<array{skill: string, description: string, source: int}>
     *
     * @throws RuntimeException when the table has no rows, or a row and the header disagree about the column count
     */
    private static function rows(array $lines, int $at, array $header, int $skill, int $purpose): array
    {
        $rows = [];

        foreach (array_slice($lines, $at + 1) as $offset => $line) {
            $trimmed = trim($line);

            if (! str_starts_with($trimmed, '|')) {
                break;
            }

            $cells = self::cells($trimmed);

            if (self::separator($cells)) {
                continue;
            }

            if (count($cells) !== count($header)) {
                throw new RuntimeException(sprintf(
                    '%s line %d has %d cells where its header has %d: [%s]',
                    self::INDEX,
                    $at + $offset + 2,
                    count($cells),
                    count($header),
                    $trimmed,
                ));
            }

            $rows[] = [
                'skill' => self::label($cells[$skill]),
                'description' => $cells[$purpose],
                'source' => $at + $offset + 2,
            ];
        }

        if ($rows === []) {
            throw new RuntimeException(self::INDEX.' has a skills table with no rows in it, so the list of skills is empty.');
        }

        return $rows;
    }

    /**
     * @param list<string> $header
     *
     * @throws RuntimeException when a column this reader insists on is missing
     */
    private static function column(array $header, string $name): ?int
    {
        foreach ($header as $index => $cell) {
            if (strcasecmp(self::label($cell), self::label($name)) === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * One markdown row's cells, trimmed — the text as the file writes it, markup included.
     *
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        return array_map(
            static fn (string $cell): string => trim($cell),
            explode('|', trim(trim($line), '|')),
        );
    }

    /**
     * A cell as the name it carries: the form two labels are compared in, so a backticked
     * `pkg-commit` in the index is the same skill as the folder it names.
     */
    private static function label(string $cell): string
    {
        return trim(str_replace('`', '', $cell));
    }

    /**
     * @param list<string> $cells
     */
    private static function separator(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (preg_match('/^:?-{2,}:?$/', $cell) !== 1) {
                return false;
            }
        }

        return $cells !== [];
    }
}
