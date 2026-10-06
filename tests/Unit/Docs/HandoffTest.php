<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Tests\Support\Handoff;
use Uak35\WeightedDbManager\Tests\Support\SkillDescriptions;

/**
 * HANDOFF.md against the tree it describes.
 *
 * WHY THIS EXISTS
 * ---------------
 *   The note is the only record here that describes a moment rather than a rule, and it was the
 *   only one nothing read: everything else in this package is compared with the code by a test,
 *   and a snapshot that nobody checks is one that rots silently and is trusted anyway — which is
 *   worse than one that is missing, because the reader has no reason to doubt it. It had in fact
 *   already rotted in two places, both invisible in a diff: the command in its opening block did
 *   not run (bare `--dry-run` is refused — it needs a bump), and the paths it said the dist
 *   tarball holds back were wrong (`bin/*.php` is not a pattern `.gitattributes` writes).
 *
 * THE REGISTER, WHICH IS THE WHOLE DESIGN
 * ---------------------------------------
 *   A note cannot name its own `HEAD` — the commit that lands the note moves it — so holding every
 *   number against the checkout would make the file uncommittable rather than checked, and a guard
 *   that fails the first time anybody commits is a guard that gets deleted. The register is
 *   therefore the commit section 2 names as `HEAD`: what the note says about a *state* is asked of
 *   git about *that* commit, and stays true after the branch moves on.
 *
 *   What is left is what the note says about the tree *now*, and that is what these tests hold:
 *   the paths it names, the commits and tags it names, the one command name it carries, the files
 *   it describes, the version lane it claims is still open, and — through the gitignored
 *   `.agents/machine.local.json` — the environment table of section 5. That record belongs to the
 *   machine rather than to the package, so a checkout that has none (a fresh clone, CI's runner)
 *   skips those comparisons instead of running them against nothing.
 *
 *   A push does not fail them; a rename, a lost commit, a superseded tag or a regenerated
 *   inventory does.
 */
final class HandoffTest extends TestCase
{
    /**
     * What the package's own reader makes of a changelog: `unreleasedSection()` finds the section
     * and `changelogSignal()` weighs it, exactly as `bin/release.php` does. Run in a child process
     * because `bin/weighing.php` includes `bin/surface.php` with a bare `require`, and loading it
     * into the test runner is how two files that declare the same functions collide.
     */
    private const SIGNAL = <<<'PHP'
        require $argv[1];

        $section = unreleasedSection(file_get_contents($argv[2]));

        if ($section === null) {
            fwrite(STDERR, 'The changelog has no ## Unreleased section.');

            exit(1);
        }

        $signal = changelogSignal($section['body']);

        echo json_encode(
            ['severity' => $signal['severity'], 'evidence' => $signal['evidence']],
            JSON_THROW_ON_ERROR,
        );
        PHP;

    public function test_it_says_when_it_was_taken(): void
    {
        $taken = Handoff::taken();

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $taken);

        self::assertSame(
            $taken,
            gmdate('Y-m-d', (int) strtotime($taken)),
            sprintf('HANDOFF.md says it was taken on [%s], which is not a date.', $taken),
        );
    }

    public function test_every_path_it_names_is_one_this_repository_has(): void
    {
        $paths = Handoff::paths();

        self::assertGreaterThanOrEqual(
            20,
            count($paths),
            sprintf(
                'HANDOFF.md names %d paths in this package, which is fewer than a note that walks a reader '.
                'through the tree can explain: the reader that finds them may be reading less than it used to.',
                count($paths),
            ),
        );

        $missing = [];

        foreach ($paths as $claim) {
            if (!file_exists(Handoff::root().'/'.$claim['path'])) {
                $missing[] = sprintf('%s (line %d)', $claim['path'], $claim['source']);
            }
        }

        self::assertSame(
            [],
            $missing,
            "HANDOFF.md names paths this package does not have:\n  - ".implode("\n  - ", $missing),
        );
    }

    public function test_the_files_it_calls_pending_are_the_ones_the_tree_it_describes_does_not_carry(): void
    {
        $head = Handoff::basis()['head']['sha'];

        foreach (Handoff::pending() as $file) {
            self::assertFileExists(
                Handoff::root().'/'.$file['path'],
                sprintf('HANDOFF.md line %d calls %s pending, and this package has no such file.', $file['source'], $file['path']),
            );
        }

        $committed = [];

        foreach (Handoff::pending() as $file) {
            if (Handoff::git('cat-file', '-e', $head.':'.$file['path'])['exit'] === 0) {
                $committed[] = $file['path'];
            }
        }

        self::assertSame(
            [],
            $committed,
            sprintf(
                "HANDOFF.md section 1 calls these files pending, and the commit it describes (%s) already carries them —\n".
                "so the state the note describes has been committed, and section 1 has to say what is pending now:\n  - %s",
                $head,
                implode("\n  - ", $committed),
            ),
        );
    }

    public function test_the_commits_it_describes_are_the_ones_it_names(): void
    {
        foreach (['HEAD' => Handoff::basis()['head'], 'origin/dev' => Handoff::basis()['origin']] as $label => $commit) {
            $run = Handoff::git('show', '-s', '--format=%s', $commit['sha'].'^{commit}');

            self::assertSame(
                0,
                $run['exit'],
                sprintf(
                    'HANDOFF.md names %s as %s, and this repository has no such commit — section 2 has to be '.
                    're-taken, because every number in it is read against that commit.',
                    $commit['sha'],
                    $label,
                ),
            );

            self::assertSame(
                $commit['subject'],
                trim($run['output']),
                sprintf(
                    'HANDOFF.md line %d gives %s the subject [%s], and the commit reads [%s].',
                    $commit['source'],
                    $commit['sha'],
                    $commit['subject'],
                    trim($run['output']),
                ),
            );
        }
    }

    public function test_the_unpushed_count_and_mix_are_the_commits_between_the_two_it_names(): void
    {
        $basis = Handoff::basis();
        $range = $basis['origin']['sha'].'..'.$basis['head']['sha'];

        $count = Handoff::git('rev-list', '--count', $range);
        $actual = (int) trim($count['output']);

        self::assertSame(0, $count['exit'], sprintf('The commits between the two the note names could not be counted: %s', $count['error']));

        self::assertSame(
            Handoff::unpushed(),
            $actual,
            sprintf(
                'HANDOFF.md section 2 says %d commits are unpushed; the commits between %s and %s are %d. A commit '.
                'or a push is what moved it, and the number is a claim about the pair the note names.',
                Handoff::unpushed(),
                $basis['origin']['sha'],
                $basis['head']['sha'],
                $actual,
            ),
        );

        $tally = [];

        foreach (Handoff::outputLines(Handoff::git('log', '--format=%s', $range)['output']) as $subject) {
            if (preg_match('/^([A-Za-z]+)(?:\([^)]*\))?!?:/', $subject, $matches) === 1) {
                $type = strtolower($matches[1]);
                $tally[$type] = ($tally[$type] ?? 0) + 1;
            }
        }

        $mix = Handoff::mix();
        ksort($tally);
        ksort($mix);

        self::assertSame(
            $mix,
            $tally,
            sprintf(
                "HANDOFF.md breaks the unpushed commits down as %s; the commits between the two it names are %s.",
                self::written($mix),
                self::written($tally),
            ),
        );
    }

    public function test_the_commits_it_lists_are_the_most_recent_ones_between_them(): void
    {
        $basis = Handoff::basis();
        $range = $basis['origin']['sha'].'..'.$basis['head']['sha'];
        $listing = Handoff::listing();

        self::assertCount(
            $listing['claimed'],
            $listing['commits'],
            sprintf(
                'HANDOFF.md says it lists the %d most recent unpushed commits and lists %d, so the sentence and '.
                'the listing disagree even before the tree is asked.',
                $listing['claimed'],
                count($listing['commits']),
            ),
        );

        $log = Handoff::outputLines(Handoff::git('log', '--format=%h%x1f%s', $range)['output']);

        self::assertGreaterThanOrEqual(
            count($listing['commits']),
            count($log),
            'HANDOFF.md lists more unpushed commits than the two it names have between them.',
        );

        foreach ($listing['commits'] as $position => $listed) {
            [$sha, $subject] = array_pad(explode("\x1f", $log[$position], 2), 2, '');

            self::assertSame(
                ['sha' => $listed['sha'], 'subject' => $listed['subject']],
                ['sha' => $sha, 'subject' => $subject],
                sprintf(
                    'HANDOFF.md line %d lists %s — %s at position %d of the unpushed commits, and the commit '.
                    'there is %s — %s.',
                    $listed['source'],
                    $listed['sha'],
                    $listed['subject'],
                    $position + 1,
                    $sha,
                    $subject,
                ),
            );
        }
    }

    public function test_the_tag_it_calls_latest_is_still_the_newest(): void
    {
        $known = Handoff::outputLines(Handoff::git('tag', '--list', 'v*')['output']);

        $unknown = array_values(array_diff(Handoff::tags(), $known));

        self::assertSame(
            [],
            $unknown,
            sprintf(
                'HANDOFF.md names these tags, and this repository has none of them: %s.',
                implode(', ', $unknown),
            ),
        );

        $newest = self::newest($known);

        self::assertSame(
            Handoff::latest(),
            $newest,
            sprintf(
                'HANDOFF.md says %s is the latest tag, and %s is — so the lane the note describes has been '.
                'released past, and section 3 has to be re-taken before the next tag is cut.',
                Handoff::latest(),
                $newest,
            ),
        );

        self::assertNotContains(
            Handoff::absent(),
            $known,
            sprintf(
                'HANDOFF.md says there is no %s, and this repository has that tag — which is the sentence '.
                'that lets the next version be a prerelease, so the note is now describing the wrong lane.',
                Handoff::absent(),
            ),
        );
    }

    public function test_each_tag_it_names_is_on_the_branch_it_describes(): void
    {
        $head = Handoff::basis()['head']['sha'];
        $off = [];

        foreach (Handoff::tags() as $tag) {
            if (Handoff::git('merge-base', '--is-ancestor', $tag, $head)['exit'] !== 0) {
                $off[] = $tag;
            }
        }

        self::assertSame(
            [],
            $off,
            sprintf(
                'HANDOFF.md calls these tags the ones on the branch %s, and they are not behind that commit: %s.',
                $head,
                implode(', ', $off),
            ),
        );
    }

    public function test_the_tarball_it_describes_is_the_one_gitattributes_writes(): void
    {
        $shipped = Handoff::ships();
        $held = Handoff::withheld();
        $scripts = array_values(array_map(
            static fn (string $path): string => 'bin/'.basename($path),
            glob(Handoff::root().'/bin/*.php') ?: [],
        ));

        $attributes = self::attributes(array_values(array_unique(array_merge(
            array_column($shipped, 'path'),
            array_column($held, 'path'),
            $scripts,
        ))));

        $wrong = [];

        foreach ($shipped as $claim) {
            if (($attributes[$claim['path']] ?? '') !== 'unspecified') {
                $wrong[] = sprintf(
                    'line %d calls %s shippable, and .gitattributes reads [%s]',
                    $claim['source'],
                    $claim['path'],
                    $attributes[$claim['path']] ?? 'nothing',
                );
            }
        }

        foreach ($held as $claim) {
            if (($attributes[$claim['path']] ?? '') !== 'set') {
                $wrong[] = sprintf(
                    'line %d says %s is held back, and .gitattributes reads [%s]',
                    $claim['source'],
                    $claim['path'],
                    $attributes[$claim['path']] ?? 'nothing',
                );
            }
        }

        self::assertSame([], $wrong, "HANDOFF.md describes a dist tarball this package does not build:\n  - ".implode("\n  - ", $wrong));

        $named = array_values(array_intersect(array_column($shipped, 'path'), $scripts));
        $actual = array_values(array_filter(
            $scripts,
            static fn (string $script): bool => ($attributes[$script] ?? '') === 'unspecified',
        ));

        sort($named);
        sort($actual);

        self::assertSame(
            $named,
            $actual,
            sprintf(
                "HANDOFF.md says these scripts in bin/ reach a consumer: %s. The scripts `.gitattributes` does not ".
                'hold back are: %s. Nothing else is checked about them, so the two lists have to be the same list.',
                implode(', ', $named) ?: '(none)',
                implode(', ', $actual) ?: '(none)',
            ),
        );
    }

    public function test_the_analysis_runs_at_the_level_over_the_paths_it_names(): void
    {
        $claim = Handoff::phpstan();
        $configured = self::phpstanNeon();

        self::assertSame(
            $claim['level'],
            $configured['level'],
            sprintf(
                'HANDOFF.md line %d says the analysis runs at level [%s], and phpstan.neon.dist says [%s].',
                $claim['source'],
                $claim['level'],
                $configured['level'],
            ),
        );

        $claimed = $claim['paths'];
        $paths = $configured['paths'];
        sort($claimed);
        sort($paths);

        self::assertSame(
            $claimed,
            $paths,
            sprintf(
                'HANDOFF.md line %d says the analysis covers %s, and phpstan.neon.dist covers %s.',
                $claim['source'],
                implode(', ', $claimed) ?: '(nothing)',
                implode(', ', $paths) ?: '(nothing)',
            ),
        );
    }

    public function test_the_skills_it_names_are_the_ones_that_are_indexed(): void
    {
        $known = array_keys(SkillDescriptions::skills());
        $named = Handoff::skills();
        sort($known);
        sort($named);

        self::assertSame(
            $known,
            $named,
            sprintf(
                "HANDOFF.md names these skills: %s. This package has: %s — a skill added, renamed or removed is a ".
                're-take, because a fresh thread reads that row to find out what is here.',
                implode(', ', $named),
                implode(', ', $known),
            ),
        );

        $listed = array_map(
            static fn (array $row): string => $row['skill'],
            SkillDescriptions::indexed(),
        );

        self::assertSame(
            [],
            array_values(array_diff($named, $listed)),
            sprintf(
                'HANDOFF.md calls these the skills this package has, and .agents/README.md does not list them: %s.',
                implode(', ', array_values(array_diff($named, $listed))),
            ),
        );
    }

    public function test_the_command_it_quotes_is_declared_where_it_says(): void
    {
        $command = Handoff::command();
        $path = Handoff::root().'/'.$command['file'];

        self::assertFileExists(
            $path,
            sprintf('HANDOFF.md line %d says the flip is a command declared in %s, which is not a file here.', $command['source'], $command['file']),
        );

        $raw = (string) file_get_contents($path);

        self::assertSame(
            1,
            preg_match('/protected \$signature\s*=\s*\'([^\']+)\'/', $raw, $matches),
            sprintf('%s declares no `protected $signature`, so the command the note quotes has no name to hold it to.', $command['file']),
        );

        self::assertSame(
            $command['name'],
            (string) strtok(trim($matches[1]), " \t\n"),
            sprintf(
                'HANDOFF.md line %d quotes `%s`, and %s declares `%s`.',
                $command['source'],
                $command['name'],
                $command['file'],
                (string) strtok(trim($matches[1]), " \t\n"),
            ),
        );
    }

    public function test_the_checks_it_says_the_list_names_are_the_checks_it_lists(): void
    {
        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/checks.php', '--list']);

        self::assertSame(
            0,
            $run['exit'],
            sprintf('php bin/checks.php --list exited %d: %s%s', $run['exit'], $run['output'], $run['error']),
        );

        $names = Handoff::outputLines($run['output']);

        self::assertSame(
            Handoff::claimedChecks(),
            count($names),
            sprintf(
                'HANDOFF.md says the list names %d checks, and it names %d: %s.',
                Handoff::claimedChecks(),
                count($names),
                implode(', ', array_map(static fn (string $line): string => (string) strtok($line, ' '), $names)),
            ),
        );
    }

    public function test_the_unreleased_section_is_the_one_it_describes(): void
    {
        $claim = Handoff::unreleased();
        $head = Handoff::basis()['head']['sha'];

        $show = Handoff::git('show', $head.':CHANGELOG.md');

        self::assertSame(0, $show['exit'], sprintf('The changelog the note describes (%s:CHANGELOG.md) could not be read: %s', $head, $show['error']));

        $file = tempnam(sys_get_temp_dir(), 'swrr-handoff-');

        self::assertIsString($file, 'A temporary file could not be made for the changelog the weighing is asked about.');

        file_put_contents($file, $show['output']);

        try {
            $run = Handoff::run([PHP_BINARY, '-r', self::SIGNAL, Handoff::root().'/bin/weighing.php', $file]);
        } finally {
            @unlink($file);
        }

        self::assertSame(
            0,
            $run['exit'],
            sprintf('The weighing could not be asked about the changelog the note describes: %s%s', $run['output'], $run['error']),
        );

        $signal = json_decode($run['output'], true);

        self::assertIsArray($signal, sprintf('The weighing answered nothing readable: %s', $run['output']));

        self::assertSame(
            $claim['severity'],
            $signal['severity'],
            sprintf(
                'HANDOFF.md says the Unreleased notes weigh as [%s], and the package\'s own changelogSignal() reads them as [%s].',
                $claim['severity'],
                $signal['severity'],
            ),
        );

        $counts = [];

        foreach ($signal['evidence'] as $line) {
            if (preg_match('/^### (\w+) — (\d+) entr/', $line, $matches) === 1) {
                $counts[strtolower($matches[1])] = (int) $matches[2];
            }
        }

        $claimed = ['added' => $claim['added'], 'fixed' => $claim['fixed'], 'changed' => $claim['changed']];
        ksort($counts);
        ksort($claimed);

        self::assertSame(
            $claimed,
            $counts,
            sprintf(
                'HANDOFF.md says the Unreleased section carries %s; the weighing counts %s. A heading the note '.
                'does not mention is the drift this half is for, because the note says those counts are the '.
                'weighing\'s and not counted by hand.',
                self::written($claimed),
                self::written($counts),
            ),
        );

        self::assertSame(
            array_sum($counts),
            $claim['total'],
            sprintf(
                'HANDOFF.md says the Unreleased section carries %d entries, and the headings it lists add up to %d.',
                $claim['total'],
                array_sum($counts),
            ),
        );
    }

    public function test_the_records_are_out_of_step_with_the_tree_it_describes(): void
    {
        $head = Handoff::basis()['head']['sha'];
        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/inventory.php', '--check', '--at='.$head]);

        self::assertSame(
            1,
            $run['exit'],
            sprintf(
                "HANDOFF.md section 1 says the three records are out of step with the commit it describes (%s), and\n".
                "php bin/inventory.php --check --at=%s exited %d — so either the records describe a tree the note\n".
                "does not, or the state the note was written in is gone and it has to be re-taken.\n%s%s",
                $head,
                $head,
                $run['exit'],
                $run['output'],
                $run['error'],
            ),
        );

        foreach (['files.tsv', 'methods.tsv', 'surface.tsv'] as $record) {
            self::assertStringContainsString(
                $record,
                $run['error'],
                sprintf('The records are out of step at %s without naming %s, which is the record the note lists.', $head, $record),
            );
        }
    }

    public function test_the_toolchain_rows_are_the_facts_the_machine_record_holds(): void
    {
        $machine = self::machineRecord();
        $php = Handoff::php();

        self::assertSame($machine['php']['exe'], $php['exe'], sprintf(
            'HANDOFF.md line %d points PHP at [%s], and the machine record says [%s].',
            $php['source'],
            $php['exe'],
            $machine['php']['exe'],
        ));

        self::assertSame($machine['php']['version'], $php['version'], sprintf(
            'HANDOFF.md line %d puts the PHP version at [%s], and the machine record says [%s].',
            $php['source'],
            $php['version'],
            $machine['php']['version'],
        ));

        self::assertSame($machine['php']['onPath'], $php['onPath'], sprintf(
            'HANDOFF.md line %d says PHP is%s on PATH, and the machine record says it is%s.',
            $php['source'],
            $php['onPath'] ? '' : ' not',
            $machine['php']['onPath'] ? '' : ' not',
        ));

        $composer = Handoff::composer();

        self::assertSame($machine['composer']['version'], $composer['version'], sprintf(
            'HANDOFF.md line %d leads the Composer row with [%s], and the machine record says [%s].',
            $composer['source'],
            $composer['version'],
            $machine['composer']['version'],
        ));

        self::assertSame(basename($machine['composer']['phar']), $composer['phar'], sprintf(
            'HANDOFF.md line %d names [%s], and the machine record points at [%s].',
            $composer['source'],
            $composer['phar'],
            $machine['composer']['phar'],
        ));

        self::assertSame($machine['composer']['gitignored'], $composer['gitignored'], sprintf(
            'HANDOFF.md line %d says composer.phar is%s gitignored, and the machine record says it is%s.',
            $composer['source'],
            $composer['gitignored'] ? '' : ' not',
            $machine['composer']['gitignored'] ? '' : ' not',
        ));

        $search = Handoff::search();

        self::assertSame($machine['search']['rgOnPath'], $search['available'], sprintf(
            'HANDOFF.md line %d says rg is%s on PATH, and the machine record says it is%s.',
            $search['source'],
            $search['available'] ? '' : ' not',
            $machine['search']['rgOnPath'] ? '' : ' not',
        ));

        self::assertSame($machine['search']['rgVersion'], $search['version'], sprintf(
            'HANDOFF.md line %d puts ripgrep at [%s], and the machine record says [%s].',
            $search['source'],
            $search['version'],
            $machine['search']['rgVersion'],
        ));

        $style = Handoff::style();

        self::assertSame($machine['style']['command'], $style['command'], sprintf(
            'HANDOFF.md line %d names the style command [%s], and the machine record says [%s].',
            $style['source'],
            $style['command'],
            $machine['style']['command'],
        ));

        self::assertSame($machine['style']['preset'], $style['preset'], sprintf(
            'HANDOFF.md line %d puts the style gate at [%s], and the machine record says [%s].',
            $style['source'],
            $style['preset'],
            $machine['style']['preset'],
        ));
    }

    public function test_the_git_row_is_the_facts_the_machine_record_holds(): void
    {
        $machine = self::machineRecord();
        $identity = Handoff::gitIdentity();

        self::assertSame($machine['git']['origin'], $identity['origin'], sprintf(
            'HANDOFF.md line %d quotes origin as [%s], and the machine record says [%s].',
            $identity['source'],
            $identity['origin'],
            $machine['git']['origin'],
        ));

        self::assertSame($machine['git']['commitGpgSign'], $identity['commitGpgSign'], sprintf(
            'HANDOFF.md line %d says commit.gpgsign is [%s], and the machine record says [%s].',
            $identity['source'],
            var_export($identity['commitGpgSign'], true),
            var_export($machine['git']['commitGpgSign'], true),
        ));

        self::assertSame($machine['git']['userSigningKey'], $identity['userSigningKey'], sprintf(
            'HANDOFF.md line %d signs with [%s], and the machine record says [%s].',
            $identity['source'],
            $identity['userSigningKey'],
            $machine['git']['userSigningKey'],
        ));

        self::assertSame($machine['git']['hooksPath'], $identity['hooksPath'], sprintf(
            'HANDOFF.md line %d says the hooks path is [%s], and the machine record says [%s].',
            $identity['source'],
            $identity['hooksPath'] ?? 'unset',
            $machine['git']['hooksPath'] ?? 'unset',
        ));
    }

    public function test_the_packaging_rows_are_the_facts_the_machine_record_holds(): void
    {
        $machine = self::machineRecord();
        $ships = array_column(Handoff::ships(), 'path');
        $holds = array_column(Handoff::withheld(), 'path');
        $carries = $machine['tarball']['carries'];
        $leftOut = $machine['tarball']['leavesOut'];

        sort($ships);
        sort($holds);
        sort($carries);
        sort($leftOut);

        self::assertSame($carries, $ships, sprintf(
            "The machine record says a consumer's tarball carries:\n  - %s\nHANDOFF.md says:\n  - %s",
            implode("\n  - ", $carries),
            implode("\n  - ", $ships),
        ));

        self::assertSame($leftOut, $holds, sprintf(
            "The machine record says the tarball holds back:\n  - %s\nHANDOFF.md says:\n  - %s",
            implode("\n  - ", $leftOut),
            implode("\n  - ", $holds),
        ));

        $skills = Handoff::skills();
        $recorded = array_map(
            static fn (string $dir): string => basename($dir),
            $machine['skills']['dirs'],
        );

        sort($skills);
        sort($recorded);

        self::assertSame($recorded, $skills, sprintf(
            'The machine record lists the skills %s, and HANDOFF.md names %s.',
            implode(', ', $recorded),
            implode(', ', $skills),
        ));

        self::assertSame($machine['skills']['index'], Handoff::skillsIndex(), sprintf(
            'The machine record says the skills are indexed by [%s], and HANDOFF.md says [%s].',
            $machine['skills']['index'],
            Handoff::skillsIndex(),
        ));

        self::assertSame($machine['checks']['list'], Handoff::checksCommand(), sprintf(
            'The machine record says the checks are listed by [%s], and HANDOFF.md says [%s].',
            $machine['checks']['list'],
            Handoff::checksCommand(),
        ));

        self::assertSame($machine['checks']['count'], Handoff::claimedChecks(), sprintf(
            'The machine record says the list names %d checks, and HANDOFF.md says %d.',
            $machine['checks']['count'],
            Handoff::claimedChecks(),
        ));

        $run = Handoff::run([PHP_BINARY, Handoff::root().'/bin/checks.php', '--list']);

        self::assertSame(
            0,
            $run['exit'],
            sprintf('php bin/checks.php --list exited %d: %s%s', $run['exit'], $run['output'], $run['error']),
        );

        $names = array_map(
            static fn (string $line): string => (string) strtok($line, ' '),
            Handoff::outputLines($run['output']),
        );

        self::assertSame($machine['checks']['names'], $names, sprintf(
            "The machine record says the list is:\n  - %s\nIt is:\n  - %s",
            implode("\n  - ", $machine['checks']['names']),
            implode("\n  - ", $names),
        ));

        $analysis = Handoff::phpstan();
        $paths = $analysis['paths'];
        $recordedPaths = $machine['staticAnalysis']['paths'];

        sort($paths);
        sort($recordedPaths);

        self::assertSame($machine['staticAnalysis']['config'], $analysis['config'], sprintf(
            'The machine record says the analysis is configured in [%s], and HANDOFF.md says [%s].',
            $machine['staticAnalysis']['config'],
            $analysis['config'],
        ));

        self::assertSame($machine['staticAnalysis']['level'], $analysis['level'], sprintf(
            'The machine record says the analysis runs at level [%s], and HANDOFF.md says [%s].',
            $machine['staticAnalysis']['level'],
            $analysis['level'],
        ));

        self::assertSame($recordedPaths, $paths, sprintf(
            'The machine record says the analysis covers %s, and HANDOFF.md says %s.',
            implode(', ', $recordedPaths),
            implode(', ', $paths),
        ));
    }

    public function test_the_machine_record_describes_this_checkout(): void
    {
        $machine = self::machineRecord();

        self::assertSame(
            realpath(Handoff::root()),
            realpath($machine['package']['dir']),
            sprintf(
                'The machine record says this checkout is [%s], and the package root reads [%s].',
                $machine['package']['dir'],
                realpath(Handoff::root()),
            ),
        );

        self::assertFileExists($machine['php']['exe'], sprintf(
            'The machine record points PHP at [%s], which is not on this machine.',
            $machine['php']['exe'],
        ));

        self::assertSame($machine['php']['dir'], dirname($machine['php']['exe']), sprintf(
            'The machine record splits PHP into dir [%s] and exe [%s], which do not sit together.',
            $machine['php']['dir'],
            $machine['php']['exe'],
        ));

        self::assertFileExists($machine['pwsh']['exe'], sprintf(
            'The machine record points pwsh at [%s], which is not on this machine.',
            $machine['pwsh']['exe'],
        ));

        self::assertFileExists($machine['composer']['phar'], sprintf(
            'The machine record says composer.phar is [%s], which is not on this machine.',
            $machine['composer']['phar'],
        ));

        if ($machine['search']['rgOnPath']) {
            self::assertFileExists($machine['search']['rgExe'], sprintf(
                'The machine record says rg answers on PATH at [%s], which is not on this machine.',
                $machine['search']['rgExe'],
            ));
        }

        self::assertFileExists(Handoff::root().'/'.$machine['skills']['index'], sprintf(
            'The machine record says the skills are indexed by [%s], which this checkout does not have.',
            $machine['skills']['index'],
        ));

        $pint = json_decode((string) file_get_contents(Handoff::root().'/pint.json'), true);

        self::assertIsArray($pint, 'pint.json is not readable to be compared with the machine record.');

        self::assertSame($pint['preset'] ?? null, $machine['style']['preset'], sprintf(
            'pint.json asks for the [%s] preset, and the machine record says [%s].',
            $pint['preset'] ?? '(none)',
            $machine['style']['preset'],
        ));

        $neon = (string) file_get_contents(Handoff::root().'/phpstan.neon.dist');

        self::assertSame(
            1,
            preg_match('/phpVersion:\s*(\S+)/', $neon, $version),
            'phpstan.neon.dist no longer states a phpVersion for the machine record to agree with.',
        );

        self::assertSame($version[1], $machine['staticAnalysis']['phpVersion'], sprintf(
            'phpstan.neon.dist floors the analysis at [%s], and the machine record says [%s].',
            $version[1],
            $machine['staticAnalysis']['phpVersion'],
        ));

        $ignored = Handoff::git('check-ignore', '--quiet', '.agents/machine.local.json');

        self::assertSame(
            0,
            $ignored['exit'],
            '.agents/machine.local.json is not gitignored, so the machine record would be committed with everything else.',
        );

        $attribute = Handoff::git('check-attr', 'export-ignore', '--', '.agents/machine.local.json');

        self::assertSame(0, $attribute['exit'], sprintf('git could not be asked about the machine record: %s', $attribute['error']));

        self::assertStringEndsWith(
            ': set',
            trim($attribute['output']),
            '.agents/machine.local.json is not export-ignored, so the machine record would ship in a consumer\'s tarball.',
        );

        $probe = array_merge(
            glob(Handoff::root().'/bin/*') ?: [],
            glob(Handoff::root().'/docs/*.md') ?: [],
        );
        $unread = [];

        foreach ($machine['envVars']['read'] as $name) {
            $found = false;

            foreach ($probe as $file) {
                if (is_file($file) && str_contains((string) file_get_contents($file), $name)) {
                    $found = true;

                    break;
                }
            }

            if (!$found) {
                $unread[] = $name;
            }
        }

        self::assertSame([], $unread, sprintf(
            'The machine record lists environment variables nothing in bin/ or docs/ reads: %s.',
            implode(', ', $unread),
        ));
    }

    /**
     * The machine record every test above compares against, or a skip when this checkout has
     * none — the file is gitignored and describes one machine, so a fresh clone and CI's runner
     * have nothing to compare rather than something wrong to compare against.
     *
     * @return array<string, mixed>
     */
    private static function machineRecord(): array
    {
        $machine = Handoff::machine();

        if ($machine === null) {
            self::markTestSkipped(
                '.agents/machine.local.json is not in this checkout, so section 5 has no machine record to be held '.
                'to here. Recreate it from machine.local.json.example to run this half of the guard.',
            );
        }

        return $machine;
    }

    /**
     * The version that is the largest of a list, which order of creation does not decide: `v0.1.0`
     * is newer than `v0.0.9-alpha1` whichever came first.
     *
     * @param list<string> $tags
     */
    private static function newest(array $tags): string
    {
        $newest = null;

        foreach ($tags as $tag) {
            if ($newest === null || version_compare(ltrim($tag, 'v'), ltrim($newest, 'v'), '>')) {
                $newest = $tag;
            }
        }

        self::assertNotNull($newest, 'This repository has no v* tag, so the note describes a lane that does not exist.');

        return $newest;
    }

    /**
     * What `git` says about one attribute of a list of paths, keyed by path — asked once for all of
     * them, because `.gitattributes` decides by pattern and the question is only asked to the tool
     * that reads it.
     *
     * @param list<string> $paths
     * @return array<string, string>
     */
    private static function attributes(array $paths): array
    {
        $run = Handoff::git('check-attr', 'export-ignore', ...$paths);

        self::assertSame(0, $run['exit'], sprintf('git could not be asked about the tarball: %s', $run['error']));

        $attributes = [];

        foreach (Handoff::outputLines($run['output']) as $line) {
            if (preg_match('/^(.*?): export-ignore: (.*)$/', $line, $matches) === 1) {
                $attributes[$matches[1]] = $matches[2];
            }
        }

        self::assertCount(
            count($paths),
            $attributes,
            sprintf('git answered about %d of the %d paths, so half of this comparison would have been against nothing.', count($attributes), count($paths)),
        );

        return $attributes;
    }

    /**
     * `phpstan.neon.dist` as it is configured: the level, and the paths the analysis covers.
     *
     * @return array{level: string, paths: list<string>}
     */
    private static function phpstanNeon(): array
    {
        $raw = @file_get_contents(Handoff::root().'/phpstan.neon.dist');

        self::assertIsString($raw, 'phpstan.neon.dist is not there to be compared with the note.');
        self::assertSame(1, preg_match('/^\s*level:\s*(\S+)\s*$/m', $raw, $level), 'phpstan.neon.dist no longer states a level.');

        $paths = [];
        $inside = false;

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^\s*paths:\s*$/', $line) === 1) {
                $inside = true;

                continue;
            }

            if (!$inside) {
                continue;
            }

            if (preg_match('/^\s*-\s*(\S+)\s*$/', $line, $matches) === 1) {
                $paths[] = trim($matches[1], '/');

                continue;
            }

            break;
        }

        self::assertNotSame([], $paths, 'phpstan.neon.dist no longer states the paths the analysis covers.');

        return ['level' => $level[1], 'paths' => $paths];
    }

    /**
     * A breakdown as the note writes one — `feat 14, fix 15` — so a failure quotes both sides the
     * way a reader would compare them.
     *
     * @param array<string, int> $counts
     */
    private static function written(array $counts): string
    {
        return implode(', ', array_map(
            static fn (string $type, int $count): string => $type.' '.$count,
            array_keys($counts),
            array_values($counts),
        ));
    }
}
