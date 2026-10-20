<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRepo;
use Uak35\WeightedDbManager\Tests\Support\ReleaseRun;
use Uak35\WeightedDbManager\Tests\Support\RepositorySnapshot;

/**
 * Every exit `bin/release.php` can take without applying, held to the promise that it wrote
 * nothing.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `RailRefusalsTest` drives the rails the rest of the suite was silent about, and it asserts
 *   *some* of the cost of a refusal: no tag, no promoted notes, no inventory. That is a reading of
 *   three things, taken from the outside, and it leaves the rest of the tree unread — a refusal
 *   that rewrote `composer.json`, staged something, moved a ref or left a file behind would pass
 *   every one of them. The script is a precondition machine, so *a refusal costs nothing* is a
 *   claim about the whole repository, and the whole repository is what this file reads.
 *
 *   Two kinds of exit are in the table, and RELEASING.md's rails are only the first: the refusals
 *   (exit 1) and the usage errors (exit 2), which are the paths where the cost is easiest to
 *   argue away because the argument was wrong before the tree was read; the declared-version dry
 *   runs and the `--skip-ci` paths (exit 0), which are the paths where a run *does* walk the whole
 *   plan — weighing the changes, promoting the CHANGELOG in memory, printing it, rendering the
 *   version — and then stops one step short of the write. A run that walks all of that and writes
 *   is exactly the failure this file is for, and it is invisible to a test that reads the tag.
 *
 *   `--version=X.Y.Z --dry-run` is in the table on the strength of the one thing that separates it
 *   from `--weigh`: a declared version is decided before the tree is read, and it is the only way
 *   to name a prerelease — so it takes a branch rail, a tag rail and a policy gate of its own. Each
 *   is a row here rather than a claim.
 *
 * HOW IT IS HELD
 * --------------
 *   `RepositorySnapshot` is taken before and after each run, and the two have to be identical:
 *   every file under the root by the bytes and the mtime, and, where there is a `.git`, HEAD, the
 *   branch under it, every ref, the index, what git reports as staged, unstaged or untracked, and
 *   the reflog. The reading is shared with `DryRunTest`, which is where the control lives — a real
 *   release, in a fixture built the same way, is asserted to move it.
 *
 *   Each row also asserts the exit code and a sentence the path is *known* by, because a run that
 *   stopped somewhere else would keep the tree still for the wrong reason: a session that mistyped
 *   a fixture would produce a row that passes while measuring nothing.
 *
 * THE CENSUS
 * ----------
 *   A table like this rots in one direction, and it is not a row that stops matching: it is a new
 *   exit in the script that no row drives, which keeps the suite green and the promise unmeasured.
 *   So the exits are read out of `bin/release.php` itself, by the shape of the call, and the table
 *   is compared with them: one row per refusal, one per usage error, one per argument-check and
 *   one for the abort — which is what it is, a path a non-interactive shell can take to the end of
 *   the plan without a write — with the plan exit claimed by the several rows that reach it, since
 *   each of them is a different way of arriving at the one exit that is not a refusal.
 *
 *   One refusal is behind the parse gate rather than in front of it: `--version` is validated
 *   where it is parsed, and the release path re-applies the same rule to the same string, so no
 *   argument reaches the second reading. The census *measures* that rather than trusting this
 *   paragraph: a refusal whose sentence is one a usage error already prints is a guard the argument
 *   cannot get past, and the row for `--version=1.2.3-alpha.1` proves the parse gate answers first.
 *   The count therefore still has to agree with the script, and a second such guard would show up
 *   as a missing row rather than as a green suite.
 */
final class ExitPathsTest extends TestCase
{
    /** The quietest notes there are: a patch, and nothing to weigh loudly about. */
    private const FIXED = "### Fixed\n\n- A ported defect, fixed.\n";

    /** A new capability, so a tree can be weighed as a minor without adding anything to it. */
    private const ADDED = "### Added\n\n- A command nobody could run before.\n";

    /** A removal, so the policy can call for more than a hand-declared bump offers it. */
    private const REMOVED = "### Removed\n\n- The old command, gone.\n";

    /** What every dry run says on its way out — the sentence the promise is made in. */
    private const PLAN = 'Dry run: nothing was written, committed or tagged.';

    /**
     * Every way out of the script that is not the apply, one row apiece.
     *
     * @return list<array{label: string, site: string, fixture: callable(): ReleaseRepo, arguments: list<string>, exit: int, refused?: list<string>, said?: list<string>, lines?: array<string, string>}>
     */
    private static function exitPaths(): array
    {
        $clean = static fn (): ReleaseRepo => ReleaseRepo::make(self::FIXED);

        $tagged = static function (string $notes = self::FIXED, string $tag = 'v0.1.0'): ReleaseRepo {
            $repo = ReleaseRepo::make($notes);
            $repo->tag($tag);

            return $repo;
        };

        return [
            // ─────────────────────────────────────────────────────────────────────
            // The arguments, before the tree is read at all
            // ─────────────────────────────────────────────────────────────────────
            [
                'label' => '--help',
                'site' => 'help',
                'fixture' => $clean,
                'arguments' => ['--help'],
                'exit' => 0,
                'said' => ['Usage:'],
            ],
            [
                'label' => '--patch, which was removed in favour of --weigh',
                'site' => 'usage',
                'fixture' => $clean,
                'arguments' => ['--patch'],
                'exit' => 2,
                'refused' => ['--patch was removed'],
            ],
            [
                'label' => '--version with a suffix Composer cannot read',
                'site' => 'usage',
                'fixture' => $clean,
                'arguments' => ['--version=1.2.3-alpha.1'],
                'exit' => 2,
                'refused' => ['Not a version this can release: 1.2.3-alpha.1'],
            ],
            [
                'label' => '--allow-empty, which was removed',
                'site' => 'usage',
                'fixture' => $clean,
                'arguments' => ['--allow-empty'],
                'exit' => 2,
                'refused' => ['--allow-empty was removed'],
            ],
            [
                'label' => 'an option nothing knows',
                'site' => 'parse',
                'fixture' => $clean,
                'arguments' => ['--frobnicate'],
                'exit' => 2,
                'refused' => ['Unknown option: --frobnicate'],
            ],
            [
                'label' => 'no bump and no version at all',
                'site' => 'parse',
                'fixture' => $clean,
                'arguments' => ['--yes'],
                'exit' => 2,
                'refused' => ['Pick a bump: --weigh, --minor, --major or --version=X.Y.Z'],
            ],
            [
                'label' => 'two ways of naming the bump in one command',
                'site' => 'parse',
                'fixture' => $clean,
                'arguments' => ['--weigh', '--version=1.0.0'],
                'exit' => 2,
                'refused' => ['Use either --weigh/--minor/--major or --version, not both.'],
            ],

            // ─────────────────────────────────────────────────────────────────────
            // The preconditions on the tree
            // ─────────────────────────────────────────────────────────────────────
            [
                'label' => 'a directory that is not a repository',
                'site' => 'refusal',
                'fixture' => static function (): ReleaseRepo {
                    $repo = ReleaseRepo::make(self::FIXED);
                    $repo->drop('.git');

                    return $repo;
                },
                'arguments' => ['--weigh', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => ['This is not a git repository'],
            ],
            [
                'label' => 'a missing CHANGELOG.md',
                'site' => 'refusal',
                'fixture' => static function (): ReleaseRepo {
                    $repo = ReleaseRepo::make(self::FIXED);
                    $repo->tag('v0.1.0');
                    $repo->drop('CHANGELOG.md');
                    $repo->commit('chore: drop the changelog');

                    return $repo;
                },
                'arguments' => ['--weigh', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => ['Missing CHANGELOG.md.'],
            ],
            [
                'label' => 'a release asked for on a branch it is not cut from',
                'site' => 'refusal',
                'fixture' => static function (): ReleaseRepo {
                    $repo = ReleaseRepo::make(self::FIXED);
                    $repo->git('checkout', '-b', 'dev');

                    return $repo;
                },
                'arguments' => ['--weigh', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => ['Releases are cut from main, but HEAD is on dev.'],
            ],
            [
                'label' => 'an uncommitted edit to a tracked file',
                'site' => 'refusal',
                'fixture' => static function (): ReleaseRepo {
                    $repo = ReleaseRepo::make(self::FIXED);
                    $repo->tag('v0.1.0');
                    $repo->write('src/Thing.php', str_replace(
                        "return 'thing';",
                        "return 'thing changed';",
                        $repo->read('src/Thing.php'),
                    ));

                    return $repo;
                },
                'arguments' => ['--weigh', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => [
                    'Tracked files have uncommitted changes — commit them first, so the tag points at'
                    . ' exactly what was reviewed.',
                ],
            ],
            [
                'label' => 'a file no commit carries',
                'site' => 'refusal',
                'fixture' => static function (): ReleaseRepo {
                    $repo = ReleaseRepo::make(self::FIXED);
                    $repo->tag('v0.1.0');
                    $repo->write('uncommitted-record.md', "# A record no commit carries\n");

                    return $repo;
                },
                'arguments' => ['--weigh', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => [
                    'This tree holds files no commit carries — commit them first, so the tag points at exactly'
                    . ' what was reviewed, and so the handoff note cannot name a record the version does not'
                    . ' contain.',
                    'uncommitted-record.md',
                ],
            ],
            [
                'label' => 'a changelog with no Unreleased heading to promote',
                'site' => 'refusal',
                'fixture' => static function (): ReleaseRepo {
                    $repo = ReleaseRepo::make(self::FIXED);
                    $repo->tag('v0.1.0');
                    $repo->write('CHANGELOG.md', "# Release Notes\n\n## 1.0.0 - 2026-01-01\n\n- The old release.\n");
                    $repo->commit('chore: a changelog with no Unreleased heading');

                    return $repo;
                },
                'arguments' => ['--weigh', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => ['CHANGELOG.md has no "## Unreleased" section to promote.'],
            ],
            [
                'label' => 'an Unreleased section with nothing under it',
                'site' => 'refusal',
                'fixture' => static fn (): ReleaseRepo => ReleaseRepo::make(''),
                'arguments' => ['--weigh', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => ['The Unreleased section has no entries, so there is nothing to release.'],
            ],
            [
                'label' => 'a version whose tag is already there',
                'site' => 'refusal',
                'fixture' => static function (): ReleaseRepo {
                    $repo = ReleaseRepo::make(self::FIXED);
                    $repo->git('checkout', '-b', 'dev');
                    $repo->tag('v0.0.1-alpha1');

                    return $repo;
                },
                'arguments' => ['--version=0.0.1-alpha1', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => ['Tag v0.0.1-alpha1 already exists'],
            ],
            [
                'label' => 'a version that is not newer than the last release',
                'site' => 'refusal',
                'fixture' => static fn (): ReleaseRepo => $tagged(self::FIXED, 'v1.0.0'),
                'arguments' => ['--version=0.9.0', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => ['0.9.0 is not newer than the latest release (1.0.0).'],
            ],
            [
                'label' => 'an inventory that has lost a file',
                'site' => 'refusal',
                'fixture' => static function (): ReleaseRepo {
                    $repo = ReleaseRepo::make(self::FIXED);
                    $repo->tag('v0.1.0');
                    $repo->refreshInventory();
                    $repo->commit('chore: keep an inventory');
                    $repo->drop('surface.tsv');
                    $repo->commit('chore: surface.tsv is gone');

                    return $repo;
                },
                'arguments' => ['--weigh', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => ['The inventory is incomplete'],
            ],
            [
                'label' => 'a bump declared smaller than the changes call for',
                'site' => 'refusal',
                'fixture' => static function (): ReleaseRepo {
                    $repo = ReleaseRepo::make(self::REMOVED);
                    $repo->tag('v1.0.0');

                    return $repo;
                },
                'arguments' => ['--minor', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => ['These changes call for a major release, but --minor was declared.'],
            ],
            [
                'label' => 'notes that do not account for the public surface',
                'site' => 'refusal',
                'fixture' => static function (): ReleaseRepo {
                    $repo = ReleaseRepo::make(self::FIXED);
                    $repo->tag('v0.1.0');
                    $repo->dropClassConstant();
                    $repo->commit('chore: drop the version constant');

                    return $repo;
                },
                'arguments' => ['--weigh', '--yes', '--skip-ci'],
                'exit' => 1,
                'refused' => ['The public surface changed, and the Unreleased notes do not account for it.'],
            ],
            [
                'label' => 'a commit CI has not verified',
                'site' => 'refusal',
                'fixture' => static fn (): ReleaseRepo => ReleaseRepo::make(self::FIXED)
                    ->withRemote()
                    ->withCiAnswer('[]'),
                'arguments' => ['--weigh', '--yes'],
                'exit' => 1,
                'refused' => ['origin has no main branch'],
            ],

            // ─────────────────────────────────────────────────────────────────────
            // The plan: exit 0, one step short of the write
            // ─────────────────────────────────────────────────────────────────────
            [
                'label' => 'a weighed dry run',
                'site' => 'plan',
                'fixture' => static fn (): ReleaseRepo => $tagged(self::ADDED, 'v1.0.0'),
                'arguments' => ['--weigh', '--dry-run'],
                'exit' => 0,
                'said' => [self::PLAN],
                'lines' => ['next version' => '1.1.0  (tag v1.1.0)'],
            ],
            [
                'label' => 'a dry run of a declared minor',
                'site' => 'plan',
                'fixture' => static fn (): ReleaseRepo => $tagged(self::ADDED, 'v1.0.0'),
                'arguments' => ['--minor', '--dry-run'],
                'exit' => 0,
                'said' => [self::PLAN],
            ],
            [
                'label' => 'a dry run of a declared major',
                'site' => 'plan',
                'fixture' => static fn (): ReleaseRepo => $tagged(self::ADDED, 'v1.0.0'),
                'arguments' => ['--major', '--dry-run'],
                'exit' => 0,
                'said' => [self::PLAN],
            ],
            [
                'label' => 'a dry run of a declared version',
                'site' => 'plan',
                'fixture' => static fn (): ReleaseRepo => $tagged(self::ADDED, 'v1.0.0'),
                'arguments' => ['--version=1.2.0', '--dry-run'],
                'exit' => 0,
                'said' => [self::PLAN],
                'lines' => ['next version' => '1.2.0  (tag v1.2.0)'],
            ],
            [
                'label' => 'a dry run with the CI question skipped',
                'site' => 'plan',
                'fixture' => static fn (): ReleaseRepo => $tagged(self::ADDED, 'v1.0.0'),
                'arguments' => ['--weigh', '--dry-run', '--skip-ci'],
                'exit' => 0,
                'said' => [self::PLAN],
                'lines' => ['ci' => 'not checked (--skip-ci)'],
            ],
            [
                'label' => 'a weighed dry run on an empty Unreleased section',
                'site' => 'plan',
                'fixture' => static fn (): ReleaseRepo => ReleaseRepo::make(''),
                'arguments' => ['--weigh', '--dry-run'],
                'exit' => 0,
                'said' => [self::PLAN],
            ],
            [
                'label' => 'a dry run on a commit CI has not verified',
                'site' => 'plan',
                'fixture' => static fn (): ReleaseRepo => ReleaseRepo::make(self::FIXED)
                    ->withRemote()
                    ->withCiAnswer('[]'),
                'arguments' => ['--weigh', '--dry-run'],
                'exit' => 0,
                'said' => [self::PLAN],
                'lines' => ['ci' => 'origin has no main branch'],
            ],

            // ─────────────────────────────────────────────────────────────────────
            // The question nobody answered
            // ─────────────────────────────────────────────────────────────────────
            [
                'label' => 'a shell with no terminal to answer the question',
                'site' => 'abort',
                'fixture' => static fn (): ReleaseRepo => $tagged(),
                'arguments' => ['--weigh', '--skip-ci'],
                'exit' => 1,
                'refused' => ['Refusing to release from a non-interactive shell without --yes.'],
                'said' => ['Aborted.'],
                'lines' => ['next version' => '0.1.1  (tag v0.1.1)'],
            ],
        ];
    }

    /**
     * Every one of them, read on both sides of the run.
     */
    public function test_every_exit_before_the_apply_leaves_the_repository_exactly_as_it_was(): void
    {
        foreach (self::exitPaths() as $path) {
            $repo = ($path['fixture'])();

            $before = RepositorySnapshot::of($repo);

            $run = $repo->release(...$path['arguments']);

            $this->assertSame($path['exit'], $run->exitCode, self::report($path, $run));

            foreach ($path['refused'] ?? [] as $sentence) {
                $this->assertTrue($run->refused($sentence), self::report($path, $run, 'on stderr'));
            }

            foreach ($path['said'] ?? [] as $sentence) {
                $this->assertTrue($run->said($sentence), self::report($path, $run, 'on stdout'));
            }

            // A line of the plan, where what the path *is* shows up in the plan rather than in a
            // sentence: the `ci` line a dry run prints instead of refusing, and the version a run
            // that got as far as the question had worked out.
            foreach ($path['lines'] ?? [] as $key => $value) {
                $this->assertSame(
                    $value,
                    $run->plan($key),
                    self::report($path, $run, "the plan's {$key} line"),
                );
            }

            $moved = $before->differences(RepositorySnapshot::of($repo));

            $this->assertSame(
                [],
                $moved,
                sprintf(
                    '%s: wrote to the repository on its way out — %s.%s%s',
                    $path['label'],
                    implode(', ', $moved),
                    PHP_EOL,
                    $run->describe(),
                ),
            );
        }
    }

    /**
     * The census: the exits the script has, read out of it, against the rows that drive them.
     *
     * Both directions here are failures worth having. A row whose shape the script does not have is
     * a row measuring a path that is gone — the cheapest way for the table to look complete while
     * covering less. A shape the script has and no row drives is the promise quietly returning to
     * being unmeasured, which is the direction this whole file exists in.
     */
    public function test_every_exit_site_before_the_apply_is_claimed_by_a_row(): void
    {
        $sites = self::exitSites();
        $rows = self::exitPaths();

        $this->assertSame(
            count($rows),
            count(array_unique(array_column($rows, 'label'))),
            'Two rows drive the same path under the same name, so one of them measures nothing.',
        );

        $claimed = [];

        foreach ($rows as $row) {
            $claimed[$row['site']] = ($claimed[$row['site']] ?? 0) + 1;
        }

        foreach ($claimed as $shape => $count) {
            $this->assertArrayHasKey(
                $shape,
                $sites,
                sprintf('The table declares %d %s exit(s) and the script has no such exit.', $count, $shape),
            );
        }

        foreach ($sites as $shape => $lines) {
            $this->assertArrayHasKey(
                $shape,
                $claimed,
                sprintf(
                    'bin/release.php has %d %s exit site(s) before the apply (line%s %s) and no row drives one.'
                    . ' An exit that writes nothing is a promise, and a new one needs a row that reads the repository'
                    . ' on both sides of it.',
                    count($lines),
                    $shape,
                    count($lines) === 1 ? '' : 's',
                    implode(', ', $lines),
                ),
            );
        }

        // The plan exit is the one shape several rows arrive at — a weighed plan, a declared
        // bump, a declared version, an empty section, a skipped CI question — and that is what
        // they are for: each is a different way to reach the same last step before the write.
        $this->assertGreaterThanOrEqual(
            1,
            $claimed['plan'] ?? 0,
            'No row drives the plan exit, which is the path every dry run takes.',
        );

        foreach ($sites as $shape => $lines) {
            if ($shape === 'plan') {
                continue;
            }

            // A refusal the parse gate already makes is a guard behind the gate rather than a
            // path: `--version` is validated where it is parsed, and the release path re-applies
            // the same rule to the same string. Measured here rather than assumed, so the count
            // still has to add up when the script changes.
            $expected = $shape === 'refusal'
                ? count($lines) - self::refusalsTheParseGateAlreadyMakes($sites)
                : count($lines);

            $this->assertSame(
                $expected,
                $claimed[$shape],
                sprintf(
                    'bin/release.php has %d %s exit site(s) before the apply (line%s %s) and the table drives %d'
                    . ' Every exit that does not apply is a path that writes nothing, and each is held by a'
                    . ' row of its own — %s.',
                    count($lines),
                    $shape,
                    count($lines) === 1 ? '' : 's',
                    implode(', ', $lines),
                    $claimed[$shape],
                    $expected === count($lines)
                        ? 'and the table has one for every one of them'
                        : 'except the guard the parse gate already applies, which the row for that argument'
                            . ' drives by proving the gate answers first',
                ),
            );
        }
    }

    /**
     * The exits `bin/release.php` can take before the apply, read out of the script by the shape of
     * the call that takes them.
     *
     * Read from the file rather than declared, so a sentence here cannot go stale: the source is the
     * list, and the apply is the line the reading stops at — the banner is the point past which a
     * write is what the command is for.
     *
     * @return array<string, list<int>> the shape of the call => the lines it is made on
     */
    private static function exitSites(): array
    {
        $sites = [];

        foreach (self::source() as $index => $line) {
            $number = $index + 1;

            if (trim($line) === '// Apply') {
                break;
            }

            // Comments and the helper definitions explain the exits rather than taking them, and
            // the definitions sit below the banner in this script — read past them and every
            // call inside `fail()` and `usageError()` would count as a site of its own.
            if (preg_match('#^\s*(\*|//|/\*)#', $line) === 1 || preg_match('/^\s*function\s/', $line) === 1) {
                continue;
            }

            $shape = self::shapeOf($number);

            if ($shape !== null) {
                $sites[$shape][] = $number;
            }
        }

        return $sites;
    }

    /**
     * Which exit one line takes, or null when it takes none.
     *
     * The two `exit(0)` sites are told apart by the line above them: `--help` leaves through
     * `usage()`, and the plan ends on its own sentence.
     */
    private static function shapeOf(int $number): ?string
    {
        $source = self::source();
        $line = $source[$number - 1];

        if (preg_match('/(?<![\w$>])usageError\(/', $line) === 1) {
            return 'usage';
        }

        if (preg_match('/(?<![\w$>])fail\(/', $line) === 1) {
            return 'refusal';
        }

        if (preg_match('/(?<![\w$>])exit\((\d+)\)/', $line, $match) !== 1) {
            return null;
        }

        return match ((int) $match[1]) {
            2 => 'parse',
            1 => 'abort',
            default => str_contains($source[$number - 2] ?? '', 'usage();') ? 'help' : 'plan',
        };
    }

    /**
     * How many of the refusal sites apply a rule the parse site already applied to the same
     * argument.
     *
     * The measurement is the message: two sites that exit on the same words are two readings of one
     * rule, and the one behind the gate is unreachable because the argument cannot get past the
     * first. Only whole sentences count — a literal shorter than the sentence itself is a fragment
     * like `Missing ` or `Tag `, and matching those would count sites by coincidence.
     *
     * @param array<string, list<int>> $sites
     */
    private static function refusalsTheParseGateAlreadyMakes(array $sites): int
    {
        $guardLength = 28;

        $usage = [];

        foreach ($sites['usage'] ?? [] as $line) {
            $usage[] = substr(self::literal($line), 0, $guardLength);
        }

        $redundant = 0;

        foreach ($sites['refusal'] ?? [] as $line) {
            $literal = self::literal($line);

            if (strlen($literal) >= 20 && in_array(substr($literal, 0, $guardLength), $usage, true)) {
                $redundant++;
            }
        }

        return $redundant;
    }

    /**
     * The words one exit is known by: the first quoted string on the line, or on one of the few
     * after it.
     *
     * A window rather than the line, because that is the shape these exits are written in —
     * `usageError(` and `fail(sprintf(` put their sentence on the line below the call. The literal
     * is cut where the sentence starts interpolating, since what two sites can be compared by is the
     * words they print rather than the values they print them with.
     */
    private static function literal(int $number): string
    {
        foreach (array_slice(self::source(), $number - 1, 5) as $line) {
            if (preg_match('/(["\'])(.{4,}?)\1/', (string) $line, $match) !== 1) {
                continue;
            }

            $literal = $match[2];

            foreach (['%', '{', '$'] as $marker) {
                $literal = explode($marker, $literal)[0];
            }

            return trim($literal);
        }

        return '';
    }

    /**
     * The script's lines, read once.
     *
     * @return list<string>
     */
    private static function source(): array
    {
        static $source = null;

        if ($source !== null) {
            return $source;
        }

        $lines = @file(self::script(), FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException(
                'bin/release.php could not be read, and it is where the exits this table is compared with are read from',
            );
        }

        return $source = array_values($lines);
    }

    /** The script the table is about — at the root this test file sits three directories under. */
    private static function script(): string
    {
        return dirname(__DIR__, 3) . '/bin/release.php';
    }

    /** What a failed assertion says: which path, which stream, and the whole run. */
    private static function report(array $path, ReleaseRun $run, string $stream = ''): string
    {
        return sprintf(
            '%s%s%s%s',
            $path['label'],
            $stream === '' ? '' : ' — ' . $stream,
            PHP_EOL,
            $run->describe(),
        );
    }
}
