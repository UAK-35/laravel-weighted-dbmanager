<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use Composer\Semver\Semver;
use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\Handoff;

/**
 * HANDOFF.md section 5 against the programs themselves, rather than against the record of them.
 *
 * WHY THIS EXISTS
 * ---------------
 *   The environment table is the part of the note a reader acts on before checking anything else:
 *   which interpreter to run, whether Composer can be asked anything, what `rg` is, what the style
 *   gate is. `HandoffTest` holds that table to `.agents/machine.local.json` — and that record is a
 *   *copy*. It is gitignored, written on one machine, and compared only where it exists, which
 *   leaves two ways for the table to be wrong that a green suite cannot see: the copy can be stale
 *   (a raised Composer, a moved ripgrep), and then the table and the record agree with each other
 *   about a machine that has moved on; or the copy can be edited by hand, and the same thing
 *   happens with nobody the wiser. Both failures are invisible from inside the suite, because the
 *   suite reads the record rather than the disk.
 *
 *   So this reads the disk. Each row is taken from the note through the reader `HandoffTest` uses,
 *   and each claim is answered by running the program or reading the file the row names: the
 *   interpreter's own `PHP_VERSION` for the PHP row, `<php> composer.phar --version` for Composer,
 *   `rg --version` for the search row, `git config` and `git remote get-url` for the identities,
 *   `pint.json` and `bin/tools.php` for the style row, and the two directories a generator would
 *   live in for the maintenance clause. The row is the claim; the run is the answer; nothing is
 *   restated here that the note says.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 *   It does not re-implement `HandoffTest`. The rows that are facts about this *tree* rather than
 *   about the machine — the tarball split, the analysis level and its paths, the skills the index
 *   names, the number of checks — are already held by that file against the files that own them,
 *   and are not held again. What is added is the half that can only be answered by running
 *   something, plus the one clause in the table no other guard reads: that the skills are
 *   hand-maintained, with no sources to generate them and no guard of their own. And one claim in
 *   the record that is a *copy* rather than a fact about this disk: the platform constraint written
 *   beside the interpreter, which `composer.json` owns. A copy agrees with itself, so nothing
 *   inside the record can tell a stale one from a current one — the file that owns the constraint
 *   is the only thing that can, and it is held there below.
 *
 * WHY FOUR OF THEM SKIP
 * ---------------------
 *   Five rows describe this machine: an interpreter at a path on this disk, the phar beside it,
 *   the `rg` on its PATH, the signing identity in its global git config, the formatter's
 *   preset. A fresh clone and CI's runner have none of that, and a comparison made there would
 *   fail on nothing being wrong. So the machine half is asked only where the interpreter the table
 *   names is a file — the same choice `HandoffTest` makes about the machine record, and for the
 *   same reason: a checkout with no machine to describe has nothing to compare rather than
 *   something to compare wrongly. The style and maintenance rows are facts about this tree, so
 *   they are asked everywhere.
 */
final class HandoffEnvironmentTest extends TestCase
{
    public function test_the_php_row_is_the_interpreter_it_names(): void
    {
        $php = self::onThisMachine();

        $run = Handoff::run([$php['exe'], '-r', 'echo PHP_VERSION;']);

        self::assertSame(
            0,
            $run['exit'],
            sprintf('The interpreter HANDOFF.md line %d names exited %d: %s', $php['source'], $run['exit'], trim($run['error'])),
        );

        self::assertSame(
            $php['version'],
            trim($run['output']),
            sprintf(
                'HANDOFF.md line %d puts PHP at %s, and the interpreter it names reports %s.',
                $php['source'],
                $php['version'],
                trim($run['output']) ?: 'nothing',
            ),
        );

        // The row claims one thing about PATH as well, and it is answerable rather than assumed:
        // the same command with a bare `php`, in the environment the suite itself runs in.
        $bare = Handoff::run(['php', '-r', 'echo PHP_VERSION;']);
        $answers = $bare['exit'] === 0;

        self::assertSame(
            $php['onPath'],
            $answers,
            sprintf(
                'HANDOFF.md line %d says the interpreter is%s on PATH, and a bare `php` in the environment this suite runs in %s.',
                $php['source'],
                $php['onPath'] ? '' : ' not',
                $answers ? 'answers' : 'does not answer',
            ),
        );
    }

    /**
     * The constraint the record carries is the package's own, and the version beside it is inside
     * that constraint.
     *
     * WHY THIS EXISTS
     * ---------------
     *   `.agents/machine.local.json` writes `php.requirement` next to the interpreter — the
     *   constraint that interpreter has to meet — and the gate's `interpreter` check evaluates it
     *   there. Nothing compared it with the file that *owns* it: `composer.json`'s `require.php`, the
     *   one Composer refuses to install the package without. A record written on another package,
     *   edited by hand, or left behind by a raised floor can state a constraint the package no longer
     *   asks for, and every reading agrees with itself about it — the gate passes against the copy,
     *   and section 5's PHP row is held to the same record — so the copy is the only place the
     *   disagreement can be seen.
     *
     *   The second reading is the record's own consistency, which nothing else asks: the gate
     *   compares the interpreter's *reported* version with the requirement, so a record whose
     *   `version` and `requirement` contradict one another passes there while telling a reader two
     *   things at once.
     */
    public function test_the_requirement_the_record_carries_is_the_one_the_package_asks_for(): void
    {
        self::onThisMachine();

        $machine = Handoff::machine();
        $php = is_array($machine) ? ($machine['php'] ?? []) : [];
        $requirement = is_string($php['requirement'] ?? null) ? $php['requirement'] : '';
        $recorded = is_string($php['version'] ?? null) ? $php['version'] : '';
        $declared = self::declaredPhpRequirement();

        self::assertSame(
            $declared,
            $requirement,
            sprintf(
                'composer.json asks for PHP %s, and the machine record carries [%s] as the requirement its interpreter has to meet. The record is a copy of that constraint rather than a second opinion about it.',
                $declared,
                $requirement === '' ? 'nothing' : $requirement,
            ),
        );

        self::assertTrue(
            Semver::satisfies($recorded, $requirement),
            sprintf(
                'The machine record writes PHP %s and asks for %s, which that version is not inside — so the record contradicts itself, and a reader takes away whichever half they read first.',
                $recorded === '' ? 'no version' : $recorded,
                $requirement,
            ),
        );
    }

    public function test_the_composer_row_is_the_phar_the_interpreter_there_runs(): void
    {
        $php = self::onThisMachine();
        $composer = Handoff::composer();
        $phar = Handoff::root().'/'.$composer['phar'];

        self::assertFileExists($phar, sprintf(
            'HANDOFF.md line %d says %s sits in the package root, and it is not there.',
            $composer['source'],
            $composer['phar'],
        ));

        $run = Handoff::run([$php['exe'], $phar, '--version']);

        self::assertSame(
            0,
            $run['exit'],
            sprintf('%s --version exited %d: %s%s', $composer['phar'], $run['exit'], trim($run['output']), trim($run['error'])),
        );

        self::assertSame(
            1,
            preg_match('/^Composer version (\d+\.\d+\.\d+)\b/m', $run['output'], $version),
            sprintf('%s no longer reports a version: %s', $composer['phar'], trim($run['output']) ?: 'nothing'),
        );

        self::assertSame(
            $composer['version'],
            $version[1],
            sprintf(
                'HANDOFF.md line %d puts Composer at %s, and the phar it names reports %s.',
                $composer['source'],
                $composer['version'],
                $version[1],
            ),
        );

        // The row's other claim about the phar is a git verdict, so git is asked rather than the
        // ignore file read: which rule keeps it out is not what the row says, that it is kept out.
        $ignored = Handoff::git('check-ignore', '-q', '--', $composer['phar']);

        self::assertSame(
            $composer['gitignored'],
            $ignored['exit'] === 0,
            sprintf(
                'HANDOFF.md line %d says %s is%s gitignored, and git %s ignore it.',
                $composer['source'],
                $composer['phar'],
                $composer['gitignored'] ? '' : ' not',
                $ignored['exit'] === 0 ? 'does' : 'does not',
            ),
        );
    }

    public function test_the_text_search_row_is_the_program_that_answers(): void
    {
        self::onThisMachine();
        $search = Handoff::search();

        $run = Handoff::run(['rg', '--version']);
        $answers = $run['exit'] === 0;

        self::assertSame(
            $search['available'],
            $answers,
            sprintf(
                'HANDOFF.md line %d says rg is%s available, and `rg --version` in the environment this suite runs in %s.',
                $search['source'],
                $search['available'] ? '' : ' not',
                $answers ? 'answered' : 'did not answer',
            ),
        );

        if (!$answers) {
            return;
        }

        self::assertSame(
            1,
            preg_match('/^ripgrep (\d+(?:\.\d+)+)/', trim($run['output']), $version),
            sprintf('The rg on this machine no longer reports a ripgrep version: %s', trim($run['output']) ?: 'nothing'),
        );

        self::assertSame(
            $search['version'],
            $version[1],
            sprintf(
                'HANDOFF.md line %d puts ripgrep at %s, and the rg on this machine is %s.',
                $search['source'],
                $search['version'],
                $version[1],
            ),
        );
    }

    public function test_the_git_row_is_what_git_reports(): void
    {
        self::onThisMachine();
        $identity = Handoff::gitIdentity();

        $origin = Handoff::git('remote', 'get-url', 'origin');

        self::assertSame(
            0,
            $origin['exit'],
            sprintf('HANDOFF.md line %d quotes a remote, and git cannot name one: %s', $identity['source'], trim($origin['error'])),
        );

        self::assertSame(
            $identity['origin'],
            trim($origin['output']),
            sprintf(
                'HANDOFF.md line %d quotes origin as [%s], and git reports [%s].',
                $identity['source'],
                $identity['origin'],
                trim($origin['output']) ?: 'nothing',
            ),
        );

        $signing = Handoff::git('config', '--get', 'commit.gpgsign');

        self::assertSame(
            $identity['commitGpgSign'],
            trim($signing['output']) === 'true',
            sprintf(
                'HANDOFF.md line %d says commit.gpgsign is [%s], and git config reports [%s].',
                $identity['source'],
                var_export($identity['commitGpgSign'], true),
                trim($signing['output']) ?: 'nothing',
            ),
        );

        $key = Handoff::git('config', '--get', 'user.signingkey');

        self::assertSame(
            $identity['userSigningKey'],
            trim($key['output']),
            sprintf(
                'HANDOFF.md line %d signs with [%s], and git config reports [%s].',
                $identity['source'],
                $identity['userSigningKey'],
                trim($key['output']) ?: 'nothing',
            ),
        );

        // `git config --get` exits 1 for a key that is not set, which is how a repository with no
        // hooks path answers — the row writes that as prose ("is unset"), and this is the same fact.
        $hooks = Handoff::git('config', '--get', 'core.hooksPath');
        $hooksPath = $hooks['exit'] === 0 ? trim($hooks['output']) : null;

        self::assertSame(
            $identity['hooksPath'],
            $hooksPath,
            sprintf(
                'HANDOFF.md line %d says the hooks path is [%s], and git config reports [%s].',
                $identity['source'],
                $identity['hooksPath'] ?? 'unset',
                $hooksPath ?? 'unset',
            ),
        );
    }

    public function test_the_style_row_is_the_tool_and_the_preset_it_names(): void
    {
        $style = Handoff::style();
        $tool = self::toolOf($style['command']);

        self::assertFileExists(Handoff::root().'/bin/tool.php', sprintf(
            'HANDOFF.md line %d runs the style gate through bin/tool.php, and there is no such program.',
            $style['source'],
        ));

        $manifest = require Handoff::root().'/bin/tools.php';

        self::assertIsArray($manifest, 'bin/tools.php is not readable as the list of tools this package runs.');
        self::assertArrayHasKey($tool, $manifest, sprintf(
            'HANDOFF.md line %d runs `%s`, and bin/tools.php has no %s to run.',
            $style['source'],
            $style['command'],
            $tool,
        ));

        $pint = json_decode((string) file_get_contents(Handoff::root().'/pint.json'), true);

        self::assertIsArray($pint, 'pint.json is not readable as the configuration the style row describes.');
        self::assertArrayHasKey('preset', $pint, 'pint.json states no preset, so the style row has nothing to be held to.');

        // The row spells the preset the way PSR-12 is written; pint.json spells it the way Pint
        // reads it, and `Handoff::style()` lower-cases and unhyphenates both before this compares.
        $preset = strtolower(str_replace('-', '', (string) $pint['preset']));

        self::assertSame(
            $style['preset'],
            $preset,
            sprintf(
                'HANDOFF.md line %d puts the style gate at [%s], and pint.json asks Pint for [%s].',
                $style['source'],
                $style['preset'],
                $preset,
            ),
        );
    }

    public function test_the_skills_row_is_how_they_are_actually_maintained(): void
    {
        $maintained = Handoff::handMaintained();

        self::assertDirectoryDoesNotExist(Handoff::root().'/.skills', sprintf(
            'HANDOFF.md line %d says this package has no `.skills/` sources, and the directory is here.',
            $maintained['source'],
        ));

        self::assertFileDoesNotExist(Handoff::root().'/.agents/verify.py', sprintf(
            'HANDOFF.md line %d says there is no `verify.py` holding the skills, and there is one.',
            $maintained['source'],
        ));

        // A generator is a program in the package, so it is asked of the files a commit would
        // carry rather than of the disk: a stray script in a working tree is not this package's.
        $python = Handoff::git('ls-files', '*.py');
        $files = Handoff::outputLines($python['output']);

        self::assertSame(
            [],
            $files,
            sprintf(
                "HANDOFF.md line %d says the skills are hand-maintained and no generator writes them, and these programs in the package are Python:\n  - %s",
                $maintained['source'],
                implode("\n  - ", $files),
            ),
        );
    }

    /**
     * The interpreter the PHP row names, or a skip when this checkout is not on the machine the
     * table describes.
     *
     * The rows are written about one machine — an exe at a path on its disk, an `rg` on its PATH,
     * an identity in its global git config — and CI's runner is not that machine. Asking there
     * would fail the suite for describing a checkout correctly, so the question is left unasked.
     * What is compared when it is asked is the machine, not a record of it.
     *
     * @return array{exe: string, version: string, onPath: bool, source: int}
     */
    private static function onThisMachine(): array
    {
        $php = Handoff::php();

        // No record, no machine: the row points at `.agents/machine.local.json` rather than naming a
        // path, so a checkout without that file has no interpreter to run rather than a wrong one.
        if ($php['exe'] === null) {
            self::markTestSkipped(sprintf(
                '.agents/machine.local.json is not in this checkout, so HANDOFF.md line %d — which names that record rather than a path — has no interpreter for it to name here.',
                $php['source'],
            ));
        }

        if (!is_file($php['exe'])) {
            self::markTestSkipped(sprintf(
                'Section 5 describes one machine, and %s — the interpreter it names at line %d — is not on this one.',
                $php['exe'],
                $php['source'],
            ));
        }

        return $php;
    }

    /**
     * `composer.json`'s own platform constraint — the file that owns it, rather than the record
     * that copies it.
     *
     * It is read here rather than through a support class because it is one key of one file, and
     * the reading is the claim: a `require.php` that stops being a string is the same finding as a
     * record that states a different one.
     */
    private static function declaredPhpRequirement(): string
    {
        $raw = @file_get_contents(Handoff::root().'/composer.json');

        self::assertIsString($raw, 'composer.json could not be read, so the requirement the machine record carries has nothing to be held to.');

        $decoded = json_decode($raw, true);

        self::assertIsArray($decoded, 'composer.json does not read as a JSON object, so its platform requirement cannot be asked for.');

        $declared = $decoded['require']['php'] ?? null;

        self::assertIsString($declared, 'composer.json declares no `require.php`, so which interpreter versions this package needs is not written down in it.');

        return $declared;
    }

    /**
     * The tool a `php bin/tool.php <tool> …` row runs, read from the command rather than assumed.
     */
    private static function toolOf(string $command): string
    {
        self::assertSame(
            1,
            preg_match('#^php bin/tool\.php (\S+)#', $command, $matches),
            sprintf('The style row no longer runs a tool through bin/tool.php: [%s].', $command),
        );

        return $matches[1];
    }
}
