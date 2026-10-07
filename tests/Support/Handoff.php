<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * HANDOFF.md read as the claims it makes — and the repository those claims are about.
 *
 * WHY THIS EXISTS
 * ---------------
 *   `HANDOFF.md` is the one document in this package that describes a *moment* rather than a
 *   rule, which is exactly why nothing held it: every other record is compared with the code by
 *   a test, and this one was read by a person or trusted. A snapshot nobody checks is one that
 *   rots quietly, and the two ways this one had already rotted were both invisible in a diff —
 *   the command in its opening block did not run (`--dry-run` on its own is refused: it needs a
 *   bump), and the list of paths it said the dist tarball holds back was wrong (`bin/*.php` is
 *   not a pattern `.gitattributes` writes: eight of the eleven scripts in `bin/` are listed one
 *   by one, and three are not listed at all, so all three ship).
 *
 * THE REGISTER IT READS IN
 * ------------------------
 *   A note cannot name its own `HEAD` — the commit that lands the note moves it — so holding
 *   every number against the checkout would make the file uncommittable rather than checked. The
 *   register is therefore the commit section 2 names as `HEAD`: a number here is a claim about
 *   *that* commit, and the test asks git about *that* commit. The branch moving on is not a
 *   contradiction. What is a contradiction is a path that is gone, a commit or a tag that is gone
 *   or that reads differently, a tag that has been superseded, or a file this package owns that
 *   disagrees with the sentence describing it.
 *
 * STRICT, IN THE HOUSE WAY
 * ------------------------
 *   A claim is looked up by the label the note writes in the first cell of a table, or by the
 *   heading it sits under, and a label that is no longer there raises rather than returning an
 *   empty answer — the one failure a guard cannot have is agreeing with a sentence it never
 *   found. The commit listing is read the same way: a fence that is not there, a line in it that
 *   is not a commit, or a section the note no longer writes all raise.
 *
 *   Paths are read out of the backticked spans of the whole file rather than out of a list kept
 *   beside it, so a path added to the prose next month is held without anybody remembering to
 *   register it. A span is read as a path when its first segment is an entry in the package root,
 *   which is what keeps `origin/dev`, `db:pgcat-window-flip`, `site/composer.json` and
 *   `C:\…` out of it: none of them starts with something this repository has.
 *
 * THE MACHINE RECORD
 * ------------------
 *   Section 5's environment table is held to `.agents/machine.local.json` — the machine's own
 *   copy of those facts, written from `machine.local.json.example` one directory above this
 *   package. It is read as data: JSON, strict about the leaves the table compares against, and
 *   absent in a fresh clone or CI's runner, where there is no machine to record — those
 *   checkouts get null and the comparison skips rather than runs against nothing.
 *
 *   The table is read both ways round. `machine()` is the record's own leaves, which it refuses
 *   to answer without; `machineLeaves()` is the list of them; and `working()` is section 5 read
 *   against `WORKING_LEAVES` — the contract saying which of those leaves holds each row, and
 *   which rows (one, so far) the record does not hold at all. A row in neither is a row nothing
 *   compares: every other reader here goes looking for the label it knows, so a row added to the
 *   table is invisible to them, and the table can describe one machine more than the record
 *   beside it does while the suite stays green.
 */
final class Handoff
{
    /** The file sits in the package root, whatever depth the calling test is at. */
    private const FILE = __DIR__.'/../../HANDOFF.md';

    /** The package root, which is what a path the note names is relative to. */
    private const ROOT = __DIR__.'/../..';

    /**
     * The machine's own record of section 5's environment facts — local to the checkout that
     * has it, like the machine it describes.
     */
    private const MACHINE = __DIR__.'/../../.agents/machine.local.json';

    /**
     * The leaves of that record the table is compared against, as dot paths — the contract
     * between the file and the tests, so a key renamed in one of them names itself.
     *
     * @var list<string>
     */
    private const MACHINE_LEAVES = [
        'php.exe',
        'php.dir',
        'php.version',
        'php.onPath',
        'pwsh.exe',
        'package.dir',
        'composer.phar',
        'composer.version',
        'composer.gitignored',
        'git.origin',
        'git.commitGpgSign',
        'git.userSigningKey',
        'git.hooksPath',
        'search.rgExe',
        'search.rgVersion',
        'search.rgOnPath',
        'skills.index',
        'skills.dirs',
        'checks.list',
        'checks.count',
        'checks.names',
        'style.command',
        'style.preset',
        'staticAnalysis.config',
        'staticAnalysis.level',
        'staticAnalysis.paths',
        'staticAnalysis.phpVersion',
        'tarball.carries',
        'tarball.leavesOut',
        'envVars.read',
    ];

    /**
     * Section 5's table as the contract it is: the label the note writes each row under, against
     * the machine-record dot paths that hold that row's facts — and null for a row that is *about*
     * the record rather than a fact inside it.
     *
     * This is the half of the guard that catches a row added to the table. The readers in this
     * file ask for the labels they know (`PHP`, `Composer`, `Text search`), so a row nobody asks
     * for is a row nothing compares; `tests/Unit/Docs/HandoffTest.php` therefore takes the rows
     * the table *has* from `working()` and fails on a label this map does not name. Being written
     * here is the decision: the leaves that hold the row, or an explicit null with the reason.
     *
     * The paths have to be leaves of `MACHINE_LEAVES`, which is the list `machine()` refuses a
     * record without — a row naming a path nothing asks the record for would be covered in name
     * only, which is the failure this contract exists to make visible.
     *
     * @var array<string, list<string>|null>
     */
    private const WORKING_LEAVES = [
        'PHP' => ['php.exe', 'php.dir', 'php.version', 'php.onPath'],
        'Composer' => ['composer.phar', 'composer.version', 'composer.gitignored'],
        'Git identities' => ['git.origin', 'git.commitGpgSign', 'git.userSigningKey', 'git.hooksPath'],
        'Skills' => ['skills.index', 'skills.dirs'],
        'Checks' => ['checks.list', 'checks.count', 'checks.names'],
        'Style gate' => ['style.command', 'style.preset'],
        'Static analysis' => [
            'staticAnalysis.config',
            'staticAnalysis.level',
            'staticAnalysis.paths',
            'staticAnalysis.phpVersion',
        ],
        'In a consumer\'s tarball' => ['tarball.carries'],
        'Left out of it' => ['tarball.leavesOut'],
        'Text search' => ['search.rgExe', 'search.rgVersion', 'search.rgOnPath'],
        // The row is about the record rather than in it: a file cannot hold a leaf naming itself,
        // and what the row does say of it is held elsewhere — that it is gitignored by
        // `.gitignore`, that one machine wrote it by `machine()`, that this suite compares it
        // by the tests under `tests/Unit/Docs/`. Null rather than an omission, so that a row like
        // this one is a decision on the page rather than a gap no reader can tell from a mistake.
        'Machine record' => null,
    ];

    /**
     * The note's lines, read once: every claim, table, section and fence is read out of the same
     * file, and the reader asks for it from a dozen places.
     *
     * @var list<string>
     */
    private static array $lines = [];

    /**
     * The entries of the package root, which decides whether a word is a path in this package.
     * Asked once for the same reason and because it is asked per word: this is thousands of
     * questions of a directory listing that cannot change while the suite runs.
     *
     * @var list<string>
     */
    private static array $entries = [];

    /** The sections every claim is read under, as the note writes their openings. */
    private const PENDING = '## 1. Pending';
    private const UNPUSHED = '## 2. Unpushed';
    private const UNRELEASED = '## 3. Unreleased';
    private const WORKING = '## 5. Working here';

    /** The package root, for turning a path the note names into a file to look for. */
    public static function root(): string
    {
        return self::ROOT;
    }

    /**
     * The day the note was taken, which its title carries — the only claim in it that is about
     * the note rather than about the tree, and the one a reader dates a re-take against.
     *
     * @throws RuntimeException when the title stops saying it
     */
    public static function taken(): string
    {
        foreach (self::lines() as $line) {
            if (preg_match('/^#\s.*\btaken\s+(\d{4}-\d{2}-\d{2})\s*$/', trim($line), $matches) === 1) {
                return $matches[1];
            }
        }

        throw new RuntimeException(
            'HANDOFF.md no longer says when it was taken: its title is where the date is read from, '.
            'and a handoff that cannot be dated cannot be known to be stale.',
        );
    }

    /**
     * The files section 1 calls written and uncommitted, each with the line it is named on.
     *
     * @return list<array{path: string, source: int}>
     *
     * @throws RuntimeException when the section, its table, or a path in it is not there
     */
    public static function pending(): array
    {
        $pending = [];

        foreach (self::table(self::PENDING) as $row) {
            foreach (self::pathsIn($row['cells'][0] ?? '') as $path) {
                $pending[] = ['path' => $path, 'source' => $row['source']];
            }
        }

        if ($pending === []) {
            throw new RuntimeException(
                'HANDOFF.md section 1 names no file as pending, so the half of the note that says what has '.
                'not been committed yet has nothing in it.',
            );
        }

        return $pending;
    }

    /**
     * The commit the note describes itself as being taken at, and the tip of `origin/dev` it was
     * measured against — the pair every number in section 2 is a claim about.
     *
     * @return array{head: array{sha: string, subject: string, source: int}, origin: array{sha: string, subject: string, source: int}}
     *
     * @throws RuntimeException when either row stops naming a commit and its subject
     */
    public static function basis(): array
    {
        return [
            'head' => self::commit(self::UNPUSHED, 'HEAD'),
            'origin' => self::commit(self::UNPUSHED, 'origin/dev'),
        ];
    }

    /**
     * How many commits the note says no remote has.
     *
     * @throws RuntimeException when the row stops naming one
     */
    public static function unpushed(): int
    {
        $row = self::claim(self::UNPUSHED, 'Unpushed');

        if (preg_match('/(\d+)/', $row['raw'], $matches) !== 1) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d says how many commits are unpushed without a number in it: [%s]',
                $row['source'],
                trim($row['raw']),
            ));
        }

        return (int) $matches[1];
    }

    /**
     * The shape of those commits by type, as the note writes it — `feat 14, fix 15` — keyed by the
     * type, so the comparison is against the commits rather than against the order they are listed.
     *
     * @return array<string, int>
     *
     * @throws RuntimeException when the row stops naming one
     */
    public static function mix(): array
    {
        $row = self::claim(self::UNPUSHED, 'Since the last tag');

        if (preg_match_all('/([a-z]+)\s+(\d+)/', self::plain($row['value']), $matches, PREG_SET_ORDER) === 0) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer breaks the commits down by type ([%s]).',
                $row['source'],
                trim($row['value']),
            ));
        }

        $mix = [];

        foreach ($matches as $match) {
            $mix[$match[1]] = (int) $match[2];
        }

        return $mix;
    }

    /**
     * The commits section 2 lists and the count the sentence above them claims — read together,
     * because a listing that was extended without the sentence following it is exactly the drift
     * this half of the guard is for.
     *
     * @return array{claimed: int, commits: list<array{sha: string, subject: string, source: int}>}
     *
     * @throws RuntimeException when the sentence, the fence or a line of it is not what this reads
     */
    public static function listing(): array
    {
        $claimed = null;

        foreach (self::section(self::UNPUSHED) as $line) {
            if (!str_contains($line['text'], 'most recent')) {
                continue;
            }

            if (preg_match('/\b([a-z]+)\s+most recent\b/', self::plain($line['text']), $matches) === 1) {
                $claimed = NumberWords::toInt($matches[1]);

                break;
            }
        }

        if ($claimed === null) {
            throw new RuntimeException(
                'HANDOFF.md no longer says how many of the unpushed commits it lists, so a listing that was '.
                'extended or shortened cannot be caught.',
            );
        }

        $commits = [];

        foreach (self::fence(self::UNPUSHED) as $line) {
            if (trim($line['text']) === '') {
                continue;
            }

            if (preg_match('/^([0-9a-f]{7,40})\s+(.+?)\s*$/', trim($line['text']), $matches) !== 1) {
                throw new RuntimeException(sprintf(
                    'HANDOFF.md line %d is in the listing of unpushed commits without reading as one: [%s]',
                    $line['source'],
                    trim($line['text']),
                ));
            }

            $commits[] = ['sha' => $matches[1], 'subject' => $matches[2], 'source' => $line['source']];
        }

        if ($commits === []) {
            throw new RuntimeException('HANDOFF.md lists no unpushed commit at all, so section 2 evidences nothing.');
        }

        return ['claimed' => $claimed, 'commits' => $commits];
    }

    /**
     * The tags the note says are on the branch, in the order it lists them.
     *
     * @return list<string>
     *
     * @throws RuntimeException when the row or its tags are not there
     */
    public static function tags(): array
    {
        $tags = [];

        foreach (self::claim(self::UNRELEASED, 'Tags on the branch')['spans'] as $span) {
            if (preg_match('/^v\d[0-9A-Za-z.\-]*$/', trim($span)) === 1) {
                $tags[] = trim($span);
            }
        }

        if ($tags === []) {
            throw new RuntimeException('HANDOFF.md section 3 names no tag on the branch, so the version lane is unreadable.');
        }

        return $tags;
    }

    /**
     * The tag the note calls the latest of them — the one a release would supersede.
     *
     * @throws RuntimeException when the row stops naming one
     */
    public static function latest(): string
    {
        return self::version(self::claim(self::UNRELEASED, 'The latest of them'), 'the latest tag');
    }

    /**
     * The version the note says a suffixless release would have to be, and says does not exist —
     * the sentence a prerelease's legality rests on.
     *
     * @throws RuntimeException when the row stops naming one
     */
    public static function absent(): string
    {
        return self::version(self::claim(self::UNRELEASED, 'A version with no suffix'), 'the version with no suffix');
    }

    /**
     * What the note says the Unreleased section holds, and what the weighing makes of it: the
     * total the promotion would carry, the count under each heading it names, and the severity the
     * notes ask for.
     *
     * @return array{total: int, added: int, fixed: int, changed: int, severity: string}
     *
     * @throws RuntimeException when either row stops saying it in the shape this reads
     */
    public static function unreleased(): array
    {
        $counts = self::claim(self::UNRELEASED, '## Unreleased at the basis');

        if (preg_match('/^(\d+) entries — (\d+) added, (\d+) fixed, (\d+) changed$/', self::plain($counts['value']), $matches) !== 1) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer states the Unreleased entries as [total entries — a Added, b '.
                'Fixed, c Changed]: [%s]',
                $counts['source'],
                trim($counts['value']),
            ));
        }

        $weighing = self::claim(self::UNRELEASED, 'The notes weigh as');
        $severity = strtok(self::plain($weighing['value']), ' ');

        if ($severity === false || $severity === '') {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d says what the notes weigh as without naming a severity: [%s]',
                $weighing['source'],
                trim($weighing['value']),
            ));
        }

        return [
            'total' => (int) $matches[1],
            'added' => (int) $matches[2],
            'fixed' => (int) $matches[3],
            'changed' => (int) $matches[4],
            'severity' => $severity,
        ];
    }

    /**
     * The console command section 3 quotes and the file it says declares it — the one name in the
     * note that a rename in `src/Console/Commands/` would falsify without touching the note.
     *
     * @return array{name: string, file: string, source: int}
     *
     * @throws RuntimeException when the command, or the file beside it, is not there
     */
    public static function command(): array
    {
        $name = null;
        $file = null;
        $source = 0;

        foreach (self::section(self::UNRELEASED) as $line) {
            foreach (self::spans($line['text']) as $span) {
                if ($name === null && preg_match('/^db:[a-z0-9:-]+/', trim($span)) === 1) {
                    $name = (string) strtok(trim($span), " \t");
                    $source = $line['source'];

                    continue;
                }

                if ($name !== null && $file === null && preg_match('#^src/[\w/.\-]+\.php$#', trim($span)) === 1) {
                    $file = trim($span);
                }
            }

            if ($name !== null && $file !== null) {
                break;
            }
        }

        if ($name === null || $file === null) {
            throw new RuntimeException(
                'HANDOFF.md section 3 no longer quotes a `db:` command with the file that declares it, so the '.
                'one command name the note carries is no longer held to the package.',
            );
        }

        return ['name' => $name, 'file' => $file, 'source' => $source];
    }

    /**
     * The skills the note names, taken from the `.agents/skills/<name>/SKILL.md` paths in it.
     *
     * @return list<string>
     *
     * @throws RuntimeException when it names none
     */
    public static function skills(): array
    {
        $skills = [];

        foreach (self::spans(self::claim(self::WORKING, 'Skills')['value']) as $span) {
            if (preg_match('#^\.agents/skills/(\{[^}]*\}|[^/]+)/SKILL\.md$#', trim($span), $matches) !== 1) {
                continue;
            }

            foreach (explode(',', trim($matches[1], '{}')) as $name) {
                $skills[] = $name;
            }
        }

        if ($skills === []) {
            throw new RuntimeException('HANDOFF.md names no skill under .agents/skills/, so nothing is held to the index.');
        }

        return $skills;
    }

    /**
     * The paths the note says a consumer's tarball carries.
     *
     * @return list<array{path: string, source: int}>
     *
     * @throws RuntimeException when the row names none
     */
    public static function ships(): array
    {
        return self::namedPaths(self::WORKING, 'In a consumer\'s tarball', 'carries');
    }

    /**
     * The paths the note says `export-ignore` keeps out of it.
     *
     * @return list<array{path: string, source: int}>
     *
     * @throws RuntimeException when the row names none
     */
    public static function withheld(): array
    {
        return self::namedPaths(self::WORKING, 'Left out of it', 'holds back');
    }

    /**
     * The file the note says is configured, and the level and paths it gives for it, read out of
     * the sentence (``phpstan.neon.dist`: level `max`, over `src/` only``) rather than restated
     * here.
     *
     * @return array{config: string, level: string, paths: list<string>, source: int}
     *
     * @throws RuntimeException when the sentence stops naming them
     */
    public static function phpstan(): array
    {
        $row = self::claim(self::WORKING, 'Static analysis');
        $config = null;

        foreach ($row['spans'] as $span) {
            if (preg_match('/^[\w.-]+\.neon\.dist$/', trim($span)) === 1) {
                $config = trim($span);

                break;
            }
        }

        if ($config === null
            || preg_match('/level `([^`]+)`/', $row['value'], $level) !== 1
            || preg_match('/over `([^`]+)`/', $row['value'], $paths) !== 1
        ) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer names a .neon.dist file with a quoted level over a quoted path: [%s]',
                $row['source'],
                trim($row['value']),
            ));
        }

        return [
            'config' => $config,
            'level' => $level[1],
            'paths' => array_values(array_filter(array_map(
                static fn (string $path): string => trim($path, '/'),
                preg_split('/[\s,]+/', $paths[1]) ?: [],
            ))),
            'source' => $row['source'],
        ];
    }

    /**
     * How many checks the note says the list names — a number written as a word, converted with
     * the reader the prose numbers are held by.
     *
     * @throws RuntimeException when the sentence stops naming one
     */
    public static function claimedChecks(): int
    {
        $row = self::claim(self::WORKING, 'Checks');

        if (preg_match('/names ([a-z]+)/', self::plain($row['value']), $matches) !== 1) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer says how many checks the list names: [%s]',
                $row['source'],
                trim($row['value']),
            ));
        }

        return NumberWords::toInt($matches[1]);
    }

    /**
     * The PHP row as it states it: the executable the machine record names for `php.exe`, the
     * version in parentheses beside it, and whether the row says that executable answers as a bare
     * `php`.
     *
     * The row names the record's key rather than a path, and the path is resolved here through
     * `machine()`: a path written into the note is one that resolves for whoever wrote it — the
     * shape `tests/Unit/Support/MachinePathsTest.php` refuses — while the record that holds it is
     * gitignored and describes one machine. The row still has to point at the key, which is what
     * keeps it a claim rather than a sentence: rename either and this raises.
     *
     * @return array{exe: string|null, version: string, onPath: bool, source: int}
     *
     * @throws RuntimeException when the row stops naming the key, the version, or the PATH claim
     */
    public static function php(): array
    {
        $row = self::claim(self::WORKING, 'PHP');
        $key = null;

        foreach ($row['spans'] as $span) {
            if (preg_match('/^php\.exe$/i', trim($span)) === 1) {
                $key = trim($span);

                break;
            }
        }

        if ($key === null || preg_match('/\((\d+\.\d+\.\d+)\)/', $row['value'], $version) !== 1) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer names the key php.exe beside the version: [%s]',
                $row['source'],
                trim($row['value']),
            ));
        }

        $plain = self::plain($row['value']);

        if (str_contains($plain, 'not on path')) {
            $onPath = false;
        } elseif (str_contains($plain, 'is on path')) {
            $onPath = true;
        } else {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer says whether the php it names answers on PATH: [%s]',
                $row['source'],
                trim($row['value']),
            ));
        }

        // The path comes from the record the row names, and only from it: the row is read in every
        // commit and the record is gitignored, so a path written into the row resolves for one
        // person. Null when there is no record, which is the state of a fresh clone and of CI — and
        // a caller that cannot run the binary there has nothing to run rather than a wrong thing.
        $machine = self::machine();
        $php = is_array($machine) ? ($machine['php'] ?? null) : null;
        $candidate = is_array($php) ? ($php['exe'] ?? null) : null;

        return [
            'exe' => is_string($candidate) ? $candidate : null,
            'version' => $version[1],
            'onPath' => $onPath,
            'source' => $row['source'],
        ];
    }

    /**
     * The Composer row: the version it leads with, the phar it says sits in the package root,
     * and whether it says that phar is gitignored.
     *
     * @return array{version: string, phar: string, gitignored: bool, source: int}
     *
     * @throws RuntimeException when the row stops saying one of the three
     */
    public static function composer(): array
    {
        $row = self::claim(self::WORKING, 'Composer');
        $plain = self::plain($row['value']);
        $phar = null;

        foreach ($row['spans'] as $span) {
            if (trim($span) === 'composer.phar') {
                $phar = trim($span);

                break;
            }
        }

        if (preg_match('/^(\d+\.\d+\.\d+)\b/', $plain, $version) !== 1 || $phar === null) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer leads with a version or names composer.phar: [%s]',
                $row['source'],
                trim($row['value']),
            ));
        }

        // The row writes the state in bold, and `plain()` strips backticks but not emphasis.
        if (preg_match('/\bis (not )?gitignored\b/', str_replace(['*', '_'], '', $plain), $ignored) !== 1) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer says whether composer.phar is gitignored: [%s]',
                $row['source'],
                trim($row['value']),
            ));
        }

        return [
            'version' => $version[1],
            'phar' => $phar,
            'gitignored' => ($ignored[1] ?? null) === null,
            'source' => $row['source'],
        ];
    }

    /**
     * The Git identities row: the remote URL it quotes, the signing settings it writes as
     * `key=value`, and the hooks path — null when the row says the repository has none.
     *
     * @return array{origin: string, commitGpgSign: bool, userSigningKey: string, hooksPath: string|null, source: int}
     *
     * @throws RuntimeException when the row stops saying one of them
     */
    public static function gitIdentity(): array
    {
        $row = self::claim(self::WORKING, 'Git identities');
        $origin = null;

        foreach ($row['spans'] as $span) {
            if (preg_match('#^https://#', trim($span)) === 1) {
                $origin = trim($span);

                break;
            }
        }

        if ($origin === null
            || preg_match('/commit\.gpgsign=(true|false)/', $row['raw'], $signing) !== 1
            || preg_match('/user\.signingkey=([0-9a-f]+)/i', $row['raw'], $key) !== 1
        ) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer writes the remote, commit.gpgsign and user.signingkey as this reads them: [%s]',
                $row['source'],
                trim($row['raw']),
            ));
        }

        if (!str_contains(self::plain($row['value']), 'core.hookspath is unset')) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer says the hooks path is unset, which is the state the record compares: [%s]',
                $row['source'],
                trim($row['value']),
            ));
        }

        return [
            'origin' => $origin,
            'commitGpgSign' => $signing[1] === 'true',
            'userSigningKey' => $key[1],
            'hooksPath' => null,
            'source' => $row['source'],
        ];
    }

    /**
     * The Style gate row: the command it names and the preset in parentheses after it, spelled
     * the way `pint.json` and the machine record spell theirs (`PSR-12` and `psr12`).
     *
     * @return array{command: string, preset: string, source: int}
     *
     * @throws RuntimeException when the row stops naming both
     */
    public static function style(): array
    {
        $row = self::claim(self::WORKING, 'Style gate');
        $command = null;

        foreach ($row['spans'] as $span) {
            if (preg_match('#^php bin/tool\.php#', trim($span)) === 1) {
                $command = trim($span);

                break;
            }
        }

        if ($command === null || preg_match('/\(([^)]+)\)/', $row['value'], $preset) !== 1) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer names the lint command with its preset in parentheses: [%s]',
                $row['source'],
                trim($row['value']),
            ));
        }

        return [
            'command' => $command,
            'preset' => strtolower(str_replace('-', '', trim($preset[1]))),
            'source' => $row['source'],
        ];
    }

    /**
     * The Text search row: whether the row says `rg` answers on PATH, and the ripgrep version it
     * names beside it.
     *
     * @return array{available: bool, version: string, source: int}
     *
     * @throws RuntimeException when the row stops saying either
     */
    public static function search(): array
    {
        $row = self::claim(self::WORKING, 'Text search');
        $plain = self::plain($row['value']);

        if (str_contains($plain, 'is not available')) {
            $available = false;
        } elseif (str_contains($plain, 'is on path')) {
            $available = true;
        } else {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer says whether rg is available: [%s]',
                $row['source'],
                trim($row['value']),
            ));
        }

        if (preg_match('/ripgrep (\d+(?:\.\d+)+)/', $row['value'], $version) !== 1) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d names rg without the ripgrep version beside it: [%s]',
                $row['source'],
                trim($row['value']),
            ));
        }

        return ['available' => $available, 'version' => $version[1], 'source' => $row['source']];
    }

    /**
     * The command the Checks row says the list is named by.
     *
     * @throws RuntimeException when the row stops naming it
     */
    public static function checksCommand(): string
    {
        foreach (self::claim(self::WORKING, 'Checks')['spans'] as $span) {
            if (preg_match('#^php bin/checks\.php --list$#', trim($span)) === 1) {
                return trim($span);
            }
        }

        throw new RuntimeException('HANDOFF.md no longer quotes `php bin/checks.php --list` in the Checks row.');
    }

    /**
     * The file the Skills row says indexes the skills.
     *
     * @throws RuntimeException when the row stops naming it
     */
    public static function skillsIndex(): string
    {
        foreach (self::claim(self::WORKING, 'Skills')['spans'] as $span) {
            if (preg_match('#^\.agents/README\.md$#', trim($span)) === 1) {
                return trim($span);
            }
        }

        throw new RuntimeException('HANDOFF.md no longer says which file indexes the skills.');
    }

    /**
     * The maintenance clause of the Skills row: the three things the note says this package has
     * none of — `.skills/` sources, a generator, and a `verify.py`.
     *
     * Read from the prose rather than assumed, and all three at once: a re-take that drops one of
     * them, or rewrites the sentence into something this cannot read, raises here rather than
     * leaving a clause no guard holds. Each key is the note's claim that the package has none of
     * it, which is the fact a test can then go and check against the disk.
     *
     * @return array{sources: bool, generator: bool, verify: bool, source: int}
     *
     * @throws RuntimeException when the row stops saying it
     */
    public static function handMaintained(): array
    {
        $row = self::claim(self::WORKING, 'Skills');
        $plain = self::plain($row['value']);
        $claims = [];
        $named = ['sources' => '\.skills/\s+sources', 'generator' => 'generator', 'verify' => 'verify\.py'];

        foreach ($named as $what => $needle) {
            // `#` delimits rather than `/`, which the first needle has in it.
            if (preg_match('#\bno\s+'.$needle.'#i', $plain) !== 1) {
                throw new RuntimeException(sprintf(
                    'HANDOFF.md line %d no longer says the skills are hand-maintained with no %s: [%s]',
                    $row['source'],
                    $what,
                    trim($row['value']),
                ));
            }

            $claims[$what] = true;
        }

        $claims['source'] = $row['source'];

        return $claims;
    }

    /**
     * The machine record `.agents/machine.local.json` holds — the environment facts section 5's
     * table is compared against.
     *
     * Null when the file is not there, which is the state of a fresh clone and of CI's runner:
     * the record is gitignored and describes one machine, and a checkout that has no machine to
     * record has nothing to compare rather than something wrong to compare against. Present is
     * strict: not-JSON, a group that is gone, or a leaf the table asks for that is gone raises —
     * half a record is one the comparison would run against blind.
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException when the file is there but is not the record this reads
     */
    public static function machine(): ?array
    {
        $raw = @file_get_contents(self::MACHINE);

        if ($raw === false) {
            return null;
        }

        try {
            $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                '.agents/machine.local.json does not read as JSON: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        if (!is_array($record)) {
            throw new RuntimeException(
                '.agents/machine.local.json decodes to '.get_debug_type($record).', not the record of facts this reads.',
            );
        }

        foreach (self::MACHINE_LEAVES as $path) {
            $node = $record;

            foreach (explode('.', $path) as $segment) {
                if (!is_array($node) || !array_key_exists($segment, $node)) {
                    throw new RuntimeException(sprintf(
                        '.agents/machine.local.json no longer carries [%s], which is one of the facts section 5 is held by.',
                        $path,
                    ));
                }

                $node = $node[$segment];
            }
        }

        return $record;
    }

    /**
     * The leaves of the machine record this package is held to carry, as dot paths — what
     * `machine()` refuses a record without, and so the only paths a section 5 row can be covered
     * by.
     *
     * @return list<string>
     */
    public static function machineLeaves(): array
    {
        return self::MACHINE_LEAVES;
    }

    /**
     * Section 5's table, row by row, each read against the record's contract: the label as the
     * note writes it, the line it is on, whether that contract names the row at all, and the
     * record's leaves when it does — null for a row the contract writes as one the record does
     * not hold.
     *
     * `known` is therefore the whole question a row added to the table without a decision looks
     * like from here: false, with no leaves, which is what `HandoffTest` fails on rather than
     * reading past. Labels are looked up folded the way `claim()` folds them, so the map can write
     * a label the way a reader would.
     *
     * @return list<array{label: string, source: int, known: bool, leaves: list<string>|null}>
     *
     * @throws RuntimeException when the note has no such table, or no row in it
     */
    public static function working(): array
    {
        $contract = [];

        foreach (self::WORKING_LEAVES as $label => $leaves) {
            $contract[self::plain($label)] = $leaves;
        }

        $rows = [];

        foreach (self::table(self::WORKING) as $row) {
            $label = trim($row['cells'][0] ?? '');
            $folded = self::plain($label);

            $rows[] = [
                'label' => $label,
                'source' => $row['source'],
                'known' => array_key_exists($folded, $contract),
                'leaves' => $contract[$folded] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Every path in the package the note names, wherever it names it, with the line it is on.
     *
     * The whole file is read rather than a table kept beside it: a path in the prose is a claim
     * like any other, and one that only a registration step could hold is one that gets forgotten.
     *
     * @return list<array{path: string, source: int}>
     *
     * @throws RuntimeException when the note names no path at all
     */
    public static function paths(): array
    {
        $paths = [];

        foreach (self::lines() as $index => $line) {
            foreach (self::pathsIn($line) as $path) {
                $paths[] = ['path' => $path, 'source' => $index + 1];
            }
        }

        if ($paths === []) {
            throw new RuntimeException(
                'HANDOFF.md names no path in this package, so there is nothing for this guard to hold — which '.
                'is the one answer it must not give quietly.',
            );
        }

        return $paths;
    }

    /**
     * git, in this package, asked the way a read-only question has to be asked: colour off, no
     * signature lines (a global `log.showSignature` would otherwise be pasted into the output this
     * test parses), no pager, and no prompt.
     *
     * @return array{exit: int, output: string, error: string}
     */
    public static function git(string ...$arguments): array
    {
        return self::run([
            'git',
            '-c', 'color.ui=false',
            '-c', 'log.showSignature=false',
            '-c', 'core.quotepath=false',
            ...$arguments,
        ]);
    }

    /**
     * One child process in the package root: the whole interface this file has to the tools the
     * claims are about.
     *
     * @param list<string> $command
     * @return array{exit: int, output: string, error: string}
     */
    public static function run(array $command): array
    {
        $process = new Process($command, self::ROOT);
        $process->setTimeout(120);
        $process->run(null, [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_PAGER' => 'cat',
        ]);

        return [
            'exit' => $process->getExitCode() ?? 1,
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    /**
     * The lines of one child's output, as the non-empty trimmed lines it printed.
     *
     * @return list<string>
     */
    public static function outputLines(string $output): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', $output) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * One commit-shaped row: the short id it names, the subject it writes beside it, and the line
     * both are on.
     *
     * @return array{sha: string, subject: string, source: int}
     *
     * @throws RuntimeException when the row stops naming both
     */
    private static function commit(string $heading, string $label): array
    {
        $row = self::claim($heading, $label);
        $sha = null;

        foreach ($row['spans'] as $span) {
            if (preg_match('/^[0-9a-f]{7,40}$/', trim($span)) === 1) {
                $sha = trim($span);

                break;
            }
        }

        if ($sha === null || preg_match('/\*([^*]+)\*/', $row['raw'], $subject) !== 1) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer writes a commit and its subject as `sha` — *subject*: [%s]',
                $row['source'],
                trim($row['raw']),
            ));
        }

        return ['sha' => $sha, 'subject' => trim($subject[1]), 'source' => $row['source']];
    }

    /**
     * The version one row names — the first `v…` span in it, so the sentence around it is free to
     * say why.
     *
     * @param array{value: string, raw: string, source: int, spans: list<string>} $row
     *
     * @throws RuntimeException when the row names no version
     */
    private static function version(array $row, string $what): string
    {
        foreach ($row['spans'] as $span) {
            if (preg_match('/^v\d[0-9A-Za-z.\-]*$/', trim($span)) === 1) {
                return trim($span);
            }
        }

        throw new RuntimeException(sprintf(
            'HANDOFF.md line %d names %s without writing one: [%s]',
            $row['source'],
            $what,
            trim($row['value']),
        ));
    }

    /**
     * The paths one row names, with the line the row is on.
     *
     * @return list<array{path: string, source: int}>
     *
     * @throws RuntimeException when the row names none
     */
    private static function namedPaths(string $heading, string $label, string $what): array
    {
        $row = self::claim($heading, $label);
        $paths = [];

        foreach (self::pathsIn($row['value']) as $path) {
            $paths[] = ['path' => $path, 'source' => $row['source']];
        }

        if ($paths === []) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer names a path the tarball %s: [%s]',
                $row['source'],
                $what,
                trim($row['value']),
            ));
        }

        return $paths;
    }

    /**
     * The paths in one piece of text: the backticked spans of it, each read as a path where its
     * first segment is something the package has, with `{a,b}` alternatives and globs resolved to
     * the files they stand for.
     *
     * @return list<string>
     */
    private static function pathsIn(string $text): array
    {
        $paths = [];

        foreach (self::spans($text) as $span) {
            foreach (preg_split('/\s+/', $span) ?: [] as $word) {
                foreach (self::resolve($word) as $path) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * One word read as a path, or nothing when it is not one.
     *
     * A placeholder is not a path: `bin/…` and `db:…` name a shape, and the ellipsis is why they
     * are written. Everything else is decided by the first segment, which has to be an entry in
     * the package root — so `origin/dev`, `site/composer.json` and `C:\…\php.exe` are not
     * paths of this package and are left to the reader rather than guessed at.
     *
     * @return list<string>
     */
    private static function resolve(string $word): array
    {
        $word = trim($word, "`*_()[];,'\"");

        if ($word === '' || str_contains($word, '…') || str_contains($word, '...')) {
            return [];
        }

        $segments = explode('/', $word);

        if (!in_array($segments[0], self::rootEntries(), true)) {
            return [];
        }

        if (preg_match('/\{([^{}]*)\}/', $word, $braces) === 1) {
            $paths = [];

            foreach (explode(',', $braces[1]) as $alternative) {
                $paths = array_merge($paths, self::resolve(str_replace($braces[0], $alternative, $word)));
            }

            return $paths;
        }

        if (str_contains($word, '*')) {
            $matches = glob(self::ROOT.'/'.$word) ?: [];

            return array_values(array_map(
                static fn (string $match): string => str_replace('\\', '/', substr($match, strlen(self::ROOT) + 1)),
                $matches,
            ));
        }

        return [$word];
    }

    /**
     * The entries of the package root, which is what decides whether a word is a path in this
     * package at all.
     *
     * @return list<string>
     */
    private static function rootEntries(): array
    {
        if (self::$entries !== []) {
            return self::$entries;
        }

        return self::$entries = array_values(array_filter(
            scandir(self::ROOT) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
    }

    /**
     * One row of the note's tables, read by the label in its first cell.
     *
     * @return array{value: string, raw: string, source: int, spans: list<string>}
     *
     * @throws RuntimeException when the table, the row or a label is not there
     */
    private static function claim(string $heading, string $label): array
    {
        foreach (self::table($heading) as $row) {
            if (self::plain($row['cells'][0] ?? '') !== self::plain($label)) {
                continue;
            }

            return [
                'value' => implode(' ', array_slice($row['cells'], 1)),
                'raw' => $row['raw'],
                'source' => $row['source'],
                'spans' => self::spans($row['raw']),
            ];
        }

        throw new RuntimeException(sprintf(
            'HANDOFF.md has no [%s] row under [%s]. The tables are read by their labels, so a reworded '.
            'label is a claim this guard can no longer find — write it as it is written here, or move the '.
            'claim out of the table.',
            $label,
            $heading,
        ));
    }

    /**
     * The rows of the first table under a heading, the header and the dash row folded away.
     *
     * @return list<array{cells: list<string>, raw: string, source: int}>
     *
     * @throws RuntimeException when the heading is not there, or no table under it
     */
    private static function table(string $heading): array
    {
        $lines = self::lines();
        $at = self::headingAt($lines, $heading);
        $rows = [];
        $started = false;

        foreach (array_slice($lines, $at + 1, null, true) as $index => $line) {
            $trimmed = trim($line);

            if (!str_starts_with($trimmed, '|')) {
                if ($started) {
                    break;
                }

                continue;
            }

            $started = true;
            $rows[] = ['cells' => self::cells($trimmed), 'raw' => $trimmed, 'source' => $index + 1];
        }

        if ($rows === []) {
            throw new RuntimeException(sprintf('HANDOFF.md has no table under [%s].', $heading));
        }

        foreach ($rows as $position => $row) {
            if (self::separator($row['cells'])) {
                return array_slice($rows, $position + 1);
            }
        }

        throw new RuntimeException(sprintf(
            'HANDOFF.md has a table under [%s] with no header row to separate it from its contents.',
            $heading,
        ));
    }

    /**
     * The heading line's index, and everything under it to the next section — the unit a claim is
     * read in, so a table in one section cannot be read as another's.
     *
     * @return list<array{text: string, source: int}>
     *
     * @throws RuntimeException when no heading opens with it
     */
    private static function section(string $heading): array
    {
        $lines = self::lines();
        $at = self::headingAt($lines, $heading);
        $section = [];

        foreach (array_slice($lines, $at + 1, null, true) as $index => $line) {
            if (preg_match('/^##\s/', trim($line)) === 1) {
                break;
            }

            $section[] = ['text' => $line, 'source' => $index + 1];
        }

        return $section;
    }

    /**
     * The first fence under a heading whose info string is empty — how the note writes output and
     * a listing, and never something to run.
     *
     * @return list<array{text: string, source: int}>
     *
     * @throws RuntimeException when there is no such fence, or it is never closed
     */
    private static function fence(string $heading): array
    {
        $fence = [];
        $inside = false;
        $openedAt = 0;

        foreach (self::section($heading) as $line) {
            $trimmed = trim($line['text']);

            if (!str_starts_with($trimmed, '```')) {
                if ($inside) {
                    $fence[] = $line;
                }

                continue;
            }

            if ($inside) {
                return $fence;
            }

            if (trim(substr($trimmed, 3)) === '') {
                $inside = true;
                $openedAt = $line['source'];

                continue;
            }
        }

        if ($inside) {
            throw new RuntimeException(sprintf('HANDOFF.md line %d opens a fence that is never closed.', $openedAt));
        }

        throw new RuntimeException(sprintf(
            'HANDOFF.md has no block of plain output under [%s], so the listing read from it is gone.',
            $heading,
        ));
    }

    /**
     * The index of the heading line a section opens with.
     *
     * @param list<string> $lines
     *
     * @throws RuntimeException when no heading starts with it
     */
    private static function headingAt(array $lines, string $heading): int
    {
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (preg_match('/^##\s/', $trimmed) !== 1) {
                continue;
            }

            if (str_starts_with(self::plain($trimmed), self::plain($heading))) {
                return $index;
            }
        }

        throw new RuntimeException(sprintf(
            'HANDOFF.md has no section headed [%s], so the claims read under it have nowhere to be read from.',
            $heading,
        ));
    }

    /**
     * One markdown row's cells, trimmed — the text as the file writes it, backticks included.
     *
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        $cells = array_map('trim', explode('|', trim($line)));

        if ($cells !== [] && $cells[0] === '') {
            array_shift($cells);
        }

        if ($cells !== [] && end($cells) === '') {
            array_pop($cells);
        }

        return array_values($cells);
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

    /**
     * The backticked spans of a line, in the order they appear — the note's way of writing a name,
     * a command or a path, and so the only place a claim about one is read from.
     *
     * @return list<string>
     */
    private static function spans(string $line): array
    {
        preg_match_all('/`([^`]*)`/', $line, $matches);

        return array_values($matches[1]);
    }

    /**
     * The form two labels are compared in: backticks, capitalisation and spacing are prose, the
     * words are the label's identity.
     */
    private static function plain(string $text): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $text))));
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException when the file is not there
     */
    private static function lines(): array
    {
        if (self::$lines !== []) {
            return self::$lines;
        }

        $raw = @file_get_contents(self::FILE);

        if ($raw === false) {
            throw new RuntimeException('No HANDOFF.md to hold to the tree: '.self::FILE);
        }

        return self::$lines = explode("\n", str_replace("\r\n", "\n", $raw));
    }
}
