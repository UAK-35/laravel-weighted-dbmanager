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
 * ------------------     *   Section 5's environment table is held to `.agents/machine.local.json` — the machine's own
     *   copy of those facts, written from the committed sample
     *   `.agents/sample.machine.local.json`, which is the same file with a `__TOKEN__` in every
     *   position a machine fills in. It is read as data: JSON, strict about the leaves the table compares against, and
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
     * The last gate run, as `bin/checks.php --record` wrote it — the file section 1's summary is
     * rendered from rather than copied out of a terminal.
     *
     * It is committed, and it is a record of a run rather than of a commit: the three `.tsv` files
     * beside it describe a tree, this one describes an afternoon on one machine, which is why the
     * block it renders carries the date and the interpreter the record names. `bin/checks.php`
     * declares the same path as `GATE_RECORD`; `HandoffTest` holds the two spellings to each other,
     * because two files naming one location is a pair that drifts silently.
     */
    private const GATE = '.agents/gate.json';

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

    /**
     * How section 1's transcript writes the command that reads the three `.tsv` records — the one
     * command the note quotes that a guard runs.
     *
     * Written once and read twice: `transcript()` looks for the block under section 1 that holds it,
     * and `records()` takes the arguments off every line that starts with it. A command read out of a
     * line by a pattern of its own would be a command the note could rename into a tool nobody runs
     * while the reading beside it went on reporting whatever the old name resolved to.
     */
    private const RECORDS = 'php bin/inventory.php';

    /**
     * The nouns the guard's count readings count, each as the two forms a note may write it in.
     *
     * WHY A PAIR AND NOT THE PATTERN THAT READS IT
     * -------------------------------------------
     *   A count of one is written with the singular and every other count with the plural, so a
     *   reading that spells only the plural cannot read the one note holding exactly one of the thing
     *   — and it fails in the direction nobody looks: it reports agreement with a claim it never read,
     *   which is the failure `NumberWords` names for a word it does not know. The noun is therefore
     *   declared here once, as both forms, and a reading builds its pattern out of the declaration
     *   (`counted()`) rather than out of one of them, so the singular cannot be dropped by whoever
     *   writes the next reading. `tests/Unit/Docs/HandoffTest.php` holds every pair to both forms, and
     *   refuses to see one spelled by hand in the guard's own source.
     *
     *   Two readings share the table: the Unreleased totals, which read the number in front of the
     *   noun, and section 1's refusal, which reads a number *word* in front of it. One table rather
     *   than two, because a noun one of them counts is a noun the other has to catch, and two tables
     *   are two chances for one of them to be missing a form — the reason `NumberWords` is one table
     *   for its two directions rather than two of them. `path` and `file` are here for the refusal
     *   alone: the size of the pending set is the one count this note cannot state, so the refusal has
     *   to find the number by the noun it counts.
     *
     * @var array<string, array{singular: string, plural: string}>
     */
    private const COUNTED = [
        'entry' => ['singular' => 'entry', 'plural' => 'entries'],
        'path' => ['singular' => 'path', 'plural' => 'paths'],
        'file' => ['singular' => 'file', 'plural' => 'files'],
    ];

    /** The package root, for turning a path the note names into a file to look for. */
    public static function root(): string
    {
        return self::ROOT;
    }

    /**
     * The nouns a count reading counts, keyed by the singular — the list rather than one pair, so
     * that a test can ask what the guard counts at all rather than what one reading counts.
     *
     * @return array<string, array{singular: string, plural: string}>
     */
    public static function countedNouns(): array
    {
        return self::COUNTED;
    }

    /**
     * The pattern a count reading matches one of those nouns with — both forms, or neither.
     *
     * The one door to a pair, and it raises for a noun the table does not declare: a pattern with one
     * form written into it is a reading that ships unable to read a count of one, and a call here is
     * the one shape of that which cannot happen.
     *
     * @throws RuntimeException when the noun is not one the table declares
     */
    public static function counted(string $noun): string
    {
        if (! isset(self::COUNTED[$noun])) {
            throw new RuntimeException(sprintf(
                'the guard counts no such noun as "%s": a reading builds its pattern from the pairs in this file, so '.
                'declare the singular and the plural there first',
                $noun,
            ));
        }

        return '(?:'.self::COUNTED[$noun]['singular'].'|'.self::COUNTED[$noun]['plural'].')';
    }

    /**
     * Whether a word is one of those nouns, in either form — how a reading that comes *across* a word
     * rather than looking for one asks whether it has arrived at what it counts.
     *
     * Case is the record's, as it is for `NumberWords`.
     */
    public static function countsNoun(string $word): bool
    {
        foreach (self::COUNTED as $pair) {
            if (in_array(strtolower($word), [$pair['singular'], $pair['plural']], true)) {
                return true;
            }
        }

        return false;
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
     * The files section 1 names as what the register does not carry, each with the line it is on.
     *
     * WHY AN EMPTY TABLE IS A READING AND NOT AN ERROR
     * -----------------------------------------------
     *   This used to refuse a table with no rows — *the half of the note that says what has not been
     *   committed yet has nothing in it* — and that refusal made the note un-takeable in the state a
     *   cycle ends in: every batch committed, nothing untracked, and a section 1 whose honest answer
     *   is that there is nothing to name. The complaint was already made in the other direction, by
     *   the test that compares `uncarried()` — every path this checkout has that no commit carries —
     *   with the paths the section names: a file nobody names fails it whether the table has rows or
     *   not, so an empty table cannot hide one, and refusing it protected nothing while forbidding a
     *   state the note has to be able to describe.
     *
     * @return list<array{path: string, source: int}>
     *
     * @throws RuntimeException when the section or its table is not there
     */
    public static function pending(): array
    {
        $pending = [];

        foreach (self::table(self::PENDING) as $row) {
            foreach (self::pathsIn($row['cells'][0] ?? '') as $path) {
                $pending[] = ['path' => $path, 'source' => $row['source']];
            }
        }

        return $pending;
    }

    /**
     * What `git status` says this checkout has and no commit does, read once as entries: the path,
     * and the two status columns the tool writes before it.
     *
     * `-uall` rather than the default, so a directory that is new in its entirety is read as the
     * files inside it rather than as one collapsed entry, and a rename is read by the name it has
     * now, which is the name no commit has.
     *
     * @return list<array{status: string, path: string}>
     *
     * @throws RuntimeException when git cannot be asked — a reading of nothing agrees with a
     *                          section that names nothing, so the failure has to be loud
     */
    private static function porcelain(): array
    {
        $run = self::git('status', '--porcelain', '-uall');

        if ($run['exit'] !== 0) {
            throw new RuntimeException(sprintf(
                'git could not be asked what this checkout carries and no commit does: %s',
                trim($run['error']) ?: 'git said nothing',
            ));
        }

        $entries = [];

        foreach (self::outputLines($run['output']) as $line) {
            $path = substr($line, 3);

            // A rename is reported as `R  old -> new`, and the name no commit carries is the one on
            // the right.
            if (str_contains($path, ' -> ')) {
                $path = substr($path, (int) strpos($path, ' -> ') + 4);
            }

            $entries[] = ['status' => substr($line, 0, 2), 'path' => $path];
        }

        return $entries;
    }

    /**
     * Whether one entry is a path no commit carries at all: untracked, or added, renamed or copied
     * in the index. `??` is the former, and the first column is where the latter are written.
     */
    private static function isNew(string $status): bool
    {
        return $status === '??' || in_array(substr($status, 0, 1), ['A', 'R', 'C'], true);
    }

    /**
     * The paths this checkout has that no commit carries at all — what a commit would add.
     *
     * A checkout with nothing pending reads nothing here, which is the state a clone and CI's
     * runner are in — so what this answers is only ever compared in one direction: every path it
     * returns has to be named in section 1, while a path section 1 names is a claim about the
     * machine that wrote it, and is left to the test above.
     *
     * @return list<string>
     *
     * @throws RuntimeException when git cannot be asked
     */
    public static function uncarried(): array
    {
        $paths = [];

        foreach (self::porcelain() as $entry) {
            if (self::isNew($entry['status'])) {
                $paths[] = $entry['path'];
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * The sentence section 1 names the derivation of the pending set's size in — the paragraph that
     * says where the count comes from, rather than one that states it.
     *
     * WHY A POINTER AND NOT A NUMBER
     * ------------------------------
     *   This sentence used to state three counts — how many paths differ from the register, how many
     *   of those are tracked paths that changed, and how many are files no commit carries — and the
     *   guard compared them with the working tree. That comparison could only ever be green on the
     *   machine that wrote the note: a count of uncommitted paths is a fact about one checkout, so
     *   every file added, deleted or reverted after the note was taken left it red until a person
     *   re-read `git status` and edited three words. A number no derivation can hold is not one this
     *   package binds (`docs/prose-numbers.md` keeps it out of the bound table), and rendering it is
     *   no better: the rendered sentence would be wrong on the first clean clone. So the sentence
     *   names the derivation — the command the note's own opening block runs — and this reads *that*
     *   sentence, so the guard can hold it to naming one.
     *
     *   What is read is the claim's own phrase (*the size of the pending set*), not the command in
     *   it: section 1 also talks *about* this change, and a paragraph that mentions the command while
     *   describing the sentence is not a second claim. A paragraph rather than a line, for the reason
     *   the sentence is wrapped: the command is on one physical line and the words it is about are on
     *   the next, so a reader that stopped at the line would report a claim it had only half read. Two
     *   paragraphs stating the claim is the one ambiguity a reader here cannot resolve, so it is
     *   raised rather than guessed at.
     *
     * @return array{text: string, source: int}
     *
     * @throws RuntimeException when no paragraph of section 1 names it, or more than one does
     */
    public static function pendingSize(): array
    {
        $named = [];

        foreach (self::paragraphs(self::PENDING) as $paragraph) {
            if (preg_match('/\bthe size of the pending set\b/i', self::plain($paragraph['text'])) === 1) {
                $named[] = $paragraph;
            }
        }

        if ($named === []) {
            throw new RuntimeException(
                'HANDOFF.md section 1 no longer says what the size of the pending set is — it has to name the '.
                'derivation rather than state a count, because a count of uncommitted paths is a fact about one '.
                'working tree and a committed file cannot be asked for it.',
            );
        }

        if (count($named) > 1) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md section 1 states the size of the pending set in %d paragraphs (lines %s), and which '.
                'one of them is the claim is not something this can read.',
                count($named),
                implode(', ', array_column($named, 'source')),
            ));
        }

        return $named[0];
    }

    /**
     * The commands section 1's transcript quotes about the three `.tsv` records, each with the exit
     * code the note writes beside it and the summary it reads off the run.
     *
     * WHY THE TRANSCRIPT IS READ RATHER THAN THE STATE REQUIRED
     * -------------------------------------------------------
     *   Whether the records are in step with the commit the note names is a *state*, and a state is not
     *   something a committed note can be required to be in. The release writes the three files, so at
     *   the commit a release leaves — which is the commit the note a release cycle ends with is taken
     *   at — they are current: their header names the tag just cut and `--check` exits 0. A guard that
     *   required the disagreement would refuse the one note a release cycle most needs taken, and a
     *   guard that read a fixed exit could only ever catch the direction it was written for. What the
     *   note can be held to is what it *quotes*: the commands come out of the transcript and are run,
     *   and the exit code written beside each one has to be the one it returned — a note that writes
     *   `exit 1` beside a command that exits 0 fails, and so does a command that failed while the
     *   summary beside it says it did not.
     *
     *   A `--> exit N: …` line carries the code; a bare `--> …` claims the one a check that found
     *   nothing to say exits with, which is 0. A summary is joined across the lines it is wrapped
     *   over, because the note wraps at a hundred columns and the tool's own line is longer than that.
     *
     * @return list<array{command: list<string>, exit: int, summary: string, source: int}>
     *
     * @throws RuntimeException when the section quotes no command
     */
    public static function records(): array
    {
        $quoted = [];
        $written = null;

        foreach (self::transcript() as $line) {
            $text = trim($line['text']);

            if (str_starts_with($text, self::RECORDS)) {
                $quoted[] = [
                    'command' => self::commandIn($text),
                    'exit' => 0,
                    'summary' => '',
                    'source' => $line['source'],
                ];
                $written = count($quoted) - 1;

                continue;
            }

            if ($written === null) {
                continue;
            }

            $said = str_starts_with($text, '-->') ? ltrim(substr($text, 3)) : $text;

            if (preg_match('/^exit\s+(\d+)\s*:\s*(.*)$/', $said, $matches) === 1) {
                $quoted[$written]['exit'] = (int) $matches[1];
                $quoted[$written]['summary'] .= ' '.$matches[2];

                continue;
            }

            $quoted[$written]['summary'] .= ' '.$said;
        }

        if ($quoted === []) {
            throw new RuntimeException(
                'HANDOFF.md section 1 quotes no `php bin/inventory.php` command, so the state it describes the '.
                'three records in is a claim with no evidence beside it.',
            );
        }

        return array_map(static fn (array $quotedCommand): array => [
            'command' => $quotedCommand['command'],
            'exit' => $quotedCommand['exit'],
            'summary' => trim((string) preg_replace('/\s+/', ' ', $quotedCommand['summary'])),
            'source' => $quotedCommand['source'],
        ], $quoted);
    }

    /**
     * One quoted command as the words it runs: the program and the tool's own path are written here,
     * and the line contributes the arguments.
     *
     * @return list<string>
     */
    private static function commandIn(string $line): array
    {
        return array_merge(
            ['php', 'bin/inventory.php'],
            preg_split('/\s+/', substr($line, strlen(self::RECORDS)), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );
    }

    /**
     * The lines of the block of plain output under section 1 that quotes the inventory check — the
     * note's transcript, as opposed to the listing of commits that is fenced under the same section.
     *
     * @return list<array{text: string, source: int}>
     *
     * @throws RuntimeException when no block under the section quotes the tool
     */
    private static function transcript(): array
    {
        foreach (self::fences(self::PENDING) as $fence) {
            foreach ($fence as $line) {
                if (str_starts_with(trim($line['text']), self::RECORDS)) {
                    return $fence;
                }
            }
        }

        throw new RuntimeException(
            'HANDOFF.md section 1 quotes no `php bin/inventory.php` command, so the state it describes the '.
            'three records in is a claim with no evidence beside it.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The rendered blocks: what `bin/handoff.php` writes, and what the guard checks
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The regions of the note that are written from a derivation rather than by hand, in the order
     * the renderer writes them.
     *
     * Two of them are not git questions at all: `gate` is the last recorded run of the gate, read
     * out of `.agents/gate.json` and never re-run here — a block cannot ask for a ten-minute suite,
     * and a suite that rendered its own numbers would be quoting the run it is in the middle of — and
     * `records` is the transcript of the inventory check itself, run rather than read, because the
     * numbers in it are the check's reading of this tree and a session that typed them was stating a
     * count it had not measured.
     */
    private const BLOCKS = ['gate', 'records', 'pending', 'unpushed', 'log'];

    /**
     * How many of the unpushed commits the listing under section 2 shows. A ceiling rather than a
     * count: a note that listed a hundred commits would be a log, and the first ten are the ones a
     * reader is standing in.
     */
    private const LISTED = 10;

    /**
     * What each pending file is, one line apiece: the prose column of section 1's table, which is
     * the one part of a rendered block git cannot answer.
     *
     * WHY THE WORDS LIVE HERE AND NOT IN THE NOTE
     * ------------------------------------------
     *   The rows of that table are derived — the files this checkout has that the register does not
     *   carry — and prose cannot be. So the derivation is the set and this is the words, and the note
     *   carries the output of both; a path the register does not carry and that has no line here is
     *   refused by name rather than rendered blank, because the refusal is the sentence a reader of
     *   the table needs (name the file, or take it out of the tree). It is a declaration rather than a
     *   carry-over from the note's previous table for the reason the note's own counts are declared:
     *   a renderer whose input is the file it is about to rewrite cannot answer for the file it was
     *   handed instead.
     *
     * WHAT IS HERE, AND WHY A LINE LEAVES
     * -----------------------------------
     *   A line is words about a *pending* file, so it goes when the file lands: the lines that stood
     *   here described the files the notes before this one were taken with still carrying, and they
     *   were removed when those batches were committed, because prose kept beside a table that cannot
     *   hold its rows is the stale copy this mechanism exists to refuse. The rule reads forwards too:
     *   the next file the register does not carry asks for its line again, by name, which is the
     *   refusal doing its work rather than an accident. What the map holds now is one line apiece for
     *   the files the note in the tree is taken with: the gate's record and the deployment contract's
     *   pair.
     *
     * @var array<string, string>
     */
    private const PENDING_DESCRIPTIONS = [
        '.agents/gate.json' => 'the last recorded gate run: the totals, the exit code, the day and interpreter it was taken on, and every check with its verdict, seconds and note — what section 1\'s `gate` block is rendered from',
        'src/Support/DeploymentContract.php' => 'the deployment contract as data: each requirement\'s key, its statement, and the `db:doctor` rows that answer it',
        'tests/Unit/Docs/DeploymentContractTest.php' => 'the guard that holds the record\'s `Key` column and the README\'s row table to that map, in both directions',
        'src/Support/PinnedQuery.php' => 'the one real query — `select 1`, through the application\'s own connection — with the answer the health endpoint and `db:doctor --real-test` both report',
        'docs/read-path-escalation.md' => 'the reader-facing explanation of the two read cancellations, the cap asymmetry that makes escalation possible and the decisions left to the application — the record\'s facts, written for the caller rather than for this package\'s maintainer',
        'docs/pool-repair-rate.md' => 'the repair-rate decision: why a count cannot separate the self-heal from a pooler that keeps needing one, why the moments live in the state file, and why the endpoint warns rather than fails',
        'docs/pgcat-pool-metrics.md' => 'the metric decision: why the probe needed a surface a monitor can scrape, why a pool the reading could not prove has no sample, the alert rules, and the shapes it rejected',
        'src/Pgcat/PoolProbeMetrics.php' => 'the pool probe\'s verdict as Prometheus text exposition — one present sample per pool the pooler spoke about, no sample for one the reading could not prove, and the flags a deploy gates on',
        'src/Console/Commands/DbPgcatMetrics.php' => 'the command that writes that exposition to stdout, with `--strict` as the deploy step\'s exit code and the body unchanged by it',
        'tests/Unit/Pgcat/PoolProbeMetricsTest.php' => 'the rendering held against verdicts written by hand: a held pool, a refused one, an unproven one with no sample, each family\'s HELP and TYPE, and the label escaping',
        'tests/Unit/Console/DbPgcatMetricsCommandTest.php' => 'the command\'s two channels: the exposition a scrape archives and the code `--strict` adds, over a console answer or a probe the test supplies rather than a socket',
        'tests/Unit/Pgcat/PoolProbeAgainstPgcatTest.php' => 'the live fixture: the probe\'s three answers held against a real pgcat from the published image, the console column it corrected, and the front-door ordering the image pins',
        'src/Support/WritableFile.php' => 'the one hand-back for files this package leaves behind — 0666 on the audit record and its lock, a flip\'s state file and its lock, read back from the mode rather than trusted, plus the file-or-directory writability reading the audit and the flipper share',
        'tests/Unit/Support/WritableFileTest.php' => 'the guard on that helper: a read-only file handed back with its contents intact, a missing path answered false and not created, and the file-or-directory reading in both of its shapes',
        'tests/Support/pool-probe-server.php' => 'the sweep fixture: a loopback listener that accepts every connection before answering any, so a serial sweep fails the barrier rather than merely slowing down, plus a silent mode that holds the connections open',
        'tests/Unit/Pgcat/PoolProbeSweepTest.php' => 'the concurrent sweep held against that fixture — one wave for the answers, one budget for silence, the sentinel in the same wave, and an empty or refused sweep',
        'health-db-host-ip-plan.md' => 'the plan for reading eth1\'s IPv4 in PHP and publishing it as `pinned.host_ip` — the native call, the null contract, the tests and the records the change would touch',
        'docs/issues/issue-pgcat-flip-force-mode-readers.md' => 'the incident record for the `db:pgcat-flip --force-mode=readers` failure on a live ECS task: why the same command succeeds as `root` and fails for the application user, the `root:root` 0700 supervisord socket behind it, and the fix',
        'docs/investigations/investigation-pgcat-both-mechanisms-armed-warning.md' => 'the investigation of the boot-audit warning `swrr.pgcat.schedule.both_armed`: why it appears (both scheduling mechanisms armed), why it repeats on every FPM request, whether that is intended, and the fix',
        'docs/investigations/investigation-pgcat-flip-writer-to-readers-failed.md' => 'the investigation of why the pooler was not flipped from writer to readers on a live ECS task: the automatic flips failed because `supervisorctl` is unreachable to the `www-data` the schedule runs as (a `root:root` 0700 socket), the root-owned flip target and empty boot stamp that would still bite, and the fix',
        '.agents/skills/wdm-issue/SKILL.md' => 'the skill that records an LPR fault as a markdown document: the `/wdm-issue` marker that always activates it, the "Is this an LPR issue?" question it asks for a bare problem or issue, and the issue/investigation folder its document lands in',
        '.agents/skills/wdm-investigation/SKILL.md' => 'the skill that records an LPR investigation as a markdown document: the `/wdm-investigation` marker that always activates it, the "Is this an LPR investigation?" question it asks for the word investigate or the phrase "find out why", and the `docs/investigations/` folder its document lands in',
        'docs/health-host-ip.md' => 'the `pinned.host_ip` decision: why the standard library rather than a shell, why `null` is the answer for an absent or unusable interface, why the name is fixed to `eth1`, and the deployment check that holds the payload to `ip`',
        'src/Support/HostAddress.php' => 'the interface reader: `net_get_interfaces()` behind one null-answering call, and the pure `from()` that decides absent, down, IPv6-only, link-local and several-address listings without touching the machine',
        'tests/Unit/Support/HostAddressTest.php' => 'the reader held against fixtures rather than this host: the published address, every state that answers `null`, the primary of several, and the refusal to fall back to another interface',
    ];

    /**
     * The blocks the note carries, so a test can ask for each of them by name.
     *
     * @return list<string>
     */
    public static function blocks(): array
    {
        return self::BLOCKS;
    }

    /**
     * The text one block holds, as the file has it — what the markers delimit.
     *
     * @throws RuntimeException when the block or its markers are gone
     */
    public static function block(string $name): string
    {
        $inside = false;
        $block = [];

        foreach (self::lines() as $line) {
            $trimmed = trim($line);

            if ($trimmed === self::marker($name)) {
                $inside = true;

                continue;
            }

            if ($trimmed === self::marker($name, close: true)) {
                return implode("\n", $block);
            }

            if ($inside) {
                $block[] = $line;
            }
        }

        throw new RuntimeException(sprintf(
            'HANDOFF.md has no `%s` block: the markers that delimit what the renderer writes are gone, so a block '
            .'nobody can find is one the suite reads as nothing.',
            $name,
        ));
    }

    /**
     * What one block should say — from git, about the snapshot it is given.
     *
     * THE SNAPSHOT IS THE NOTE'S OWN, NOT THE CHECKOUT'S
     * --------------------------------------------------
     *   A block rendered at `HEAD` would be out of step the moment anything was committed: the note
     *   describes a register older than the commit that lands it, which is the design rather than
     *   drift. So a check renders the snapshot the note *records* — the register and the remote tip
     *   its own table names — and the count of unpushed commits, the breakdown and the listing are
     *   all answers about *that* pair. A take is the other half and it is `take()`: a re-take reads
     *   the same three facts off the checkout, which is what makes the rows new ones.
     *
     *   The remote tip is on the recorded side rather than read off `origin/dev`, because a push
     *   moves that ref and a committed note cannot follow it. That is the same sentence the note's
     *   own `What the test above cannot ask` writes — the guard holds the commit the remote named
     *   and the commits between it and the basis, not the ref it sits on — and reading the live ref
     *   here made a note red for a push that had nothing to do with it.
     *
     * @param array{register: string, origin: string, branch: string}|null $snapshot
     *
     * @throws RuntimeException when the snapshot or a git question it needs cannot be answered, or
     *                          when a pending file has no words declared for it
     */
    public static function rendered(string $name, ?array $snapshot = null): string
    {
        if (!in_array($name, self::BLOCKS, true)) {
            throw new RuntimeException(sprintf(
                'The note renders no block called "%s"; the blocks it has are %s.',
                $name,
                implode(', ', self::BLOCKS),
            ));
        }

        $snapshot ??= self::recorded();

        return match ($name) {
            'gate' => self::renderedGate(),
            'records' => self::renderedRecords($snapshot['register']),
            'pending' => self::renderedPending($snapshot['register']),
            'unpushed' => self::renderedUnpushed($snapshot),
            'log' => self::renderedLog($snapshot),
        };
    }

    /**
     * The tag a release is about to create, as the environment names it — and nothing on an ordinary
     * run, because an ordinary run is not creating one.
     *
     * WHY A GUARD NEEDS TO BE TOLD
     * ----------------------------
     *   `bin/release.php` moves this note's three tag rows into its own commit *before* it tags, so the
     *   commit it is about to tag names a tag that does not exist yet. Two of the claims here are about
     *   tags existing: the latest row names the newest tag the repository has, and every tag the note
     *   lists has to be behind the register. Read against a tree where the release has not happened, a
     *   note that correctly names the tag under it fails both, and a release that refused on that would
     *   be refusing every release. So the release says which tag it is about to create, and the two
     *   readings take it as existing — that is the only thing this allows, and the allowance is taken
     *   away the moment the environment does not name one.
     *
     * A tag that is not shaped like this package's (`v1.2.3`, prerelease suffix and all) is refused
     * rather than admitted: a name no `git tag` here would accept is not a pending tag, it is a typo,
     * and a guard that admitted it would be checking the note against a tag that will never exist.
     *
     * @throws RuntimeException when the environment names something that is not a tag this package cuts
     */
    public static function pendingTag(): ?string
    {
        $tag = getenv('HANDOFF_PENDING_TAG');

        if ($tag === false || trim($tag) === '') {
            return null;
        }

        $tag = trim($tag);

        if (preg_match('/^v\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $tag) !== 1) {
            throw new RuntimeException(sprintf(
                'HANDOFF_PENDING_TAG is [%s], and a release of this package cuts a tag like v1.2.3 or '
                .'v1.2.3-alpha1 — a guard told about anything else would be checking the note against a tag that '
                .'will never exist.',
                $tag,
            ));
        }

        return $tag;
    }

    /**
     * Every tag the note's claims are read against: what this repository has, plus the one a release is
     * about to create.
     *
     * One reader for the list, so a claim that asks "is this the newest" and the claim that asks "is this
     * one behind the register" cannot disagree about which tags count.
     *
     * @return list<string>
     */
    public static function tagNames(): array
    {
        $names = self::outputLines(self::git('tag', '--list', 'v*')['output']);
        $pending = self::pendingTag();

        if ($pending !== null && !in_array($pending, $names, true)) {
            $names[] = $pending;
        }

        return $names;
    }

    /** The commit a write renders at: the one this checkout is standing on, which is the new register. */
    public static function head(): string
    {
        return self::asked('rev-parse', 'HEAD')[0] ?? '';
    }

    /**
     * The snapshot a take records: the commit this checkout is standing on, the tip of `origin/dev`
     * as it is at that moment, and the branch it is on.
     *
     * @return array{register: string, origin: string, branch: string}
     *
     * @throws RuntimeException when `origin/dev` or the register does not resolve
     */
    public static function take(?string $register = null): array
    {
        return [
            'register' => $register === null ? self::head() : self::full($register),
            'origin' => self::full('origin/dev'),
            'branch' => self::branch(),
        ];
    }

    /**
     * The snapshot the note records: the two commits section 2's table names, and the branch it
     * writes. Every other row of the block is derived from those.
     *
     * @return array{register: string, origin: string, branch: string}
     *
     * @throws RuntimeException when either commit row stops naming one
     */
    private static function recorded(): array
    {
        $basis = self::basis();
        $branch = self::claim(self::UNPUSHED, 'Branch');

        return [
            'register' => $basis['head']['sha'],
            'origin' => $basis['origin']['sha'],
            'branch' => trim(str_replace('`', '', $branch['value'])),
        ];
    }

    /**
     * The branch this checkout is on, or the words the note writes where a checkout is on none.
     *
     * `symbolic-ref` exits 1 on a detached HEAD rather than answering, so the refusal is read as the
     * answer it is: a re-take from a worktree or a CI checkout is an ordinary take, and the row says
     * which state it was taken in rather than the take refusing.
     */
    private static function branch(): string
    {
        $run = self::git('symbolic-ref', '--short', 'HEAD');

        return $run['exit'] === 0
            ? (self::outputLines($run['output'])[0] ?? '(unnamed branch)')
            : '(detached HEAD)';
    }

    /**
     * Section 1's table: the files this checkout has that the register does not carry, one row
     * apiece, with the words `PENDING_DESCRIPTIONS` holds.
     *
     * The set is `git ls-files --cached --others --exclude-standard` minus `git ls-tree -r` of the
     * register, which is the question the section asks in its own words — and it is a *tree* question
     * rather than a `git status` one, because a file the register does not carry is exactly what a
     * reader of the table needs named, whether it is new in the index or committed on a branch the
     * register predates.
     */
    private static function renderedPending(string $register): string
    {
        $carried = self::asked('ls-tree', '-r', '--name-only', self::full($register));
        $pending = array_values(array_diff(self::asked('ls-files', '--cached', '--others', '--exclude-standard'), $carried));

        // Sorted here rather than left as git prints them: `--cached --others` reports the two sets
        // one after the other, so the rows would move when a path entered the index — a different
        // order for the same set, which is a diff nobody made.
        sort($pending);

        $rows = ['| File | What it is |', '|---|---|'];

        foreach ($pending as $path) {
            $rows[] = sprintf('| `%s` | %s |', $path, self::words($path));
        }

        return implode("\n", $rows);
    }

    /**
     * Section 2's table: the register, the remote tip, and the commits between them.
     *
     * THE TWO COMMITS ARE THE SNAPSHOT'S, NOT THE REFS'
     * ------------------------------------------------
     *   Both rows are read out of the note, so a check renders the pair the note names and a push —
     *   which moves `origin/dev` past a register that is already committed — cannot make it red. The
     *   count, the breakdown and the listing underneath are derived from that pair, which is the
     *   question section 2 exists to answer: what is on the branch that the remote it names did not
     *   have. `take()` is the other half, and it reads the live ref, which is what makes the row a
     *   new one.
     */
    private static function renderedUnpushed(array $snapshot): string
    {
        $head = $snapshot['register'];
        $origin = $snapshot['origin'];
        $pushed = (int) (self::asked('rev-list', '--count', $origin.'..'.$head)[0] ?? 0);

        return implode("\n", [
            '| Field | At the snapshot |',
            '|---|---|',
            sprintf('| Branch | `%s` |', $snapshot['branch']),
            sprintf('| HEAD | `%s` — *%s* |', self::short($head), self::subject($head)),
            sprintf('| `origin/dev` | `%s` — *%s* |', self::short($origin), self::subject($origin)),
            sprintf('| Unpushed | %s |', self::unpushedRow($pushed)),
            sprintf('| Since the last tag | %s |', self::written(self::unpushedMix($origin, $head))),
        ]);
    }

    /**
     * The `Unpushed` row's sentence: the count, and what the command a reader would run says about
     * it. Nothing being unpushed is a state a note is taken in — the branch a push has just
     * finished, and the one a release leaves — so it is written rather than refused, and the count
     * is still a number in every state because that is what the guard reads.
     */
    private static function unpushedRow(int $count): string
    {
        return match ($count) {
            0 => '0 commits — `git log --oneline origin/dev..HEAD` prints nothing',
            1 => '1 commit — the one `git log --oneline origin/dev..HEAD` prints',
            default => sprintf('%d commits — the ones `git log --oneline origin/dev..HEAD` prints', $count),
        };
    }

    /**
     * The listing under section 2: the most recent unpushed commits, with the sentence that counts
     * them — or, where the range the table names holds nothing, the sentence that says so.
     */
    private static function renderedLog(array $snapshot): string
    {
        $commits = self::unpushedCommits($snapshot['origin'], $snapshot['register']);

        // Nothing between the two the table names is a state a take is written in rather than one
        // it refuses, and the sentence says which fact the empty listing is a reading of: the range
        // is empty either because the register is the remote tip or because a later push carried it
        // there, and both are "there is no unpushed commit to list".
        if ($commits === []) {
            return 'Nothing is between the two commits the table names — `git log --oneline origin/dev..HEAD` prints '
                .'nothing, so there is no listing to print.';
        }

        $listed = array_slice($commits, 0, self::LISTED);
        $width = max(array_map(static fn (array $commit): int => strlen($commit['sha']), $listed)) + 2;

        $lines = [
            sprintf(
                'The %s most recent are the ones a reader is most likely to be standing in the middle of:',
                NumberWords::toWord(count($listed)),
            ),
            '',
            '```',
        ];

        foreach ($listed as $commit) {
            $lines[] = str_pad($commit['sha'], $width).$commit['subject'];
        }

        $lines[] = '```';

        return implode("\n", $lines);
    }

    /**
     * The last recorded gate run, as `.agents/gate.json` holds it: the day it was taken, the
     * interpreter, the exit code, the whole run's seconds and every check with its verdict, its
     * seconds and the note the gate printed beside it.
     *
     * WHY A RECORD AND NOT A RUN
     * --------------------------
     *   The note used to state the gate's numbers — how many checks, tests, assertions and skips the
     *   last run reported, what it took, and how long the suite was — copied out of a terminal by
     *   whoever re-took the note. Every one of them is a number a command already answered, and none
     *   of them could be held: a re-take that forgot one left the sentence describing a run that no
     *   longer happened, and the guard could only compare prose with prose. Running the gate here is
     *   the other way to be wrong — it is ten minutes inside the suite that is measuring it, and the
     *   run being rendered is the run doing the rendering.
     *
     *   So the run is recorded instead: `bin/checks.php --record` writes what it printed, once, and
     *   this reads it. What the guard can hold is what the record has to be — internally consistent,
     *   all green, and a run of every check the gate declares — and what it cannot hold is that the
     *   record is *true*, which is the same thing that cannot be held about the three `.tsv` files
     *   and for the same reason: a record is evidence of a moment, and the moment is not the suite's
     *   to re-live.
     *
     * @return array{taken: string, php: string, exit: int, elapsed: float, checks: list<array{name: string, status: string, seconds: float, note: string}>}
     *
     * @throws RuntimeException when the record is absent, unreadable, or not one a gate run wrote
     */
    public static function gate(): array
    {
        $path = self::ROOT.'/'.self::GATE;
        $raw = is_file($path) ? @file_get_contents($path) : false;

        if ($raw === false) {
            throw new RuntimeException(sprintf(
                'This checkout has no gate record at %s, and HANDOFF.md section 1 quotes one. Run '
                .'`php bin/checks.php --record` (the whole gate, ten minutes of it), then `php bin/handoff.php`.',
                self::GATE,
            ));
        }

        $record = json_decode($raw, true);

        if (! is_array($record)) {
            throw new RuntimeException(sprintf('%s is not JSON this can read, so the run it records cannot be quoted.', self::GATE));
        }

        foreach (['taken', 'php', 'exit', 'elapsed', 'checks'] as $key) {
            if (! array_key_exists($key, $record)) {
                throw new RuntimeException(sprintf(
                    '%s records no [%s], so the block rendered from it would be missing a claim rather than '
                    .'stating one. `php bin/checks.php --record` writes every key.',
                    self::GATE,
                    $key,
                ));
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $record['taken']) !== 1) {
            throw new RuntimeException(sprintf('%s records [%s] as the day it was taken, which is not a date.', self::GATE, (string) $record['taken']));
        }

        if (! is_int($record['exit']) || ! is_numeric($record['elapsed']) || ! is_array($record['checks'])) {
            throw new RuntimeException(sprintf('%s does not hold a run: the exit code, the seconds and the checks have to be an int, a number and a list.', self::GATE));
        }

        $checks = [];

        foreach ($record['checks'] as $check) {
            if (! is_array($check) || ! in_array($check['status'] ?? null, ['PASS', 'FAIL', 'SKIP'], true)
                || ! is_string($check['name'] ?? null) || ! is_numeric($check['seconds'] ?? null) || ! is_string($check['note'] ?? null)) {
                throw new RuntimeException(sprintf(
                    "%s holds a check that is not one a run writes: a record's rows are a name, one of PASS, "
                    .'FAIL and SKIP, the seconds it took and the note the gate printed.',
                    self::GATE,
                ));
            }

            $checks[] = [
                'name' => $check['name'],
                'status' => $check['status'],
                'seconds' => (float) $check['seconds'],
                'note' => $check['note'],
            ];
        }

        if ($checks === []) {
            throw new RuntimeException(sprintf('%s records a run of no checks, which is not a gate run.', self::GATE));
        }

        return [
            'taken' => (string) $record['taken'],
            'php' => (string) $record['php'],
            'exit' => $record['exit'],
            'elapsed' => (float) $record['elapsed'],
            'checks' => $checks,
        ];
    }

    /** Where that record lives, so a test can hold `bin/checks.php`'s own spelling of it. */
    public static function gateRecordPath(): string
    {
        return self::GATE;
    }

    /**
     * The sentence section 1 makes the gate's claim in: the paragraph the block under it belongs to.
     *
     * A paragraph rather than a section, because section 1 is mostly other business — commits, the
     * release, the records — and what this is for is one sentence: the one that says the gate is
     * green. It is read by the marker rather than by its words, so the sentence can be reworded
     * without the guard losing it, and what the guard then asks of it is the thing the sentence may
     * not do: state a number. Every number in the block is the record's; a count written back into
     * the prose is the hand-copy this mechanism exists to refuse, and it is refused by the shape of
     * the sentence rather than by a comparison with a run nothing can re-read.
     *
     * @return array{text: string, source: int}
     *
     * @throws RuntimeException when the block is gone, or nothing introduces it
     */
    public static function gateClaim(): array
    {
        $paragraphs = self::paragraphs(self::PENDING);
        $at = null;

        foreach ($paragraphs as $index => $paragraph) {
            if (str_contains(trim($paragraph['text']), self::marker('gate'))) {
                $at = $index;

                break;
            }
        }

        if ($at === null) {
            throw new RuntimeException(
                'HANDOFF.md section 1 carries no `gate` block, so the numbers it used to state by hand have no '
                .'rendered answer and the sentence above them cannot be read.',
            );
        }

        if ($at === 0) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d is the `gate` block with no paragraph above it: the block has to be '
                .'introduced by the sentence that claims the run is green.',
                $paragraphs[0]['source'],
            ));
        }

        return ['text' => trim($paragraphs[$at - 1]['text']), 'source' => $paragraphs[$at - 1]['source']];
    }

    /**
     * Section 1's gate summary: the recorded run's totals, then its rows, as the gate printed them.
     *
     * The numbers are the record's and the shape is the gate's — the rows are rebuilt with the same
     * format string `bin/checks.php` prints them with, and the summary line is built from the rows
     * rather than stored beside them, so a record cannot say thirteen checks over fourteen rows. What
     * is added to the run is the provenance the run itself does not print: the day it was taken and
     * the interpreter, because a record that describes an afternoon on one machine has to say which
     * one to a reader who was not there.
     */
    private static function renderedGate(): string
    {
        $record = self::gate();

        $lines = [
            '```',
            'php bin/checks.php --record',
            sprintf('  --> exit %d: %s', $record['exit'], self::gateSummary($record['checks'], $record['elapsed'])),
            sprintf('      recorded %s, PHP %s', $record['taken'], $record['php']),
        ];

        foreach ($record['checks'] as $check) {
            $lines[] = sprintf('      %-10s %-5s %6.1fs  %s', $check['name'], $check['status'], $check['seconds'], $check['note']);
        }

        $lines[] = '```';

        return implode("\n", $lines);
    }

    /**
     * The gate's own summary line, built from the rows it sums — `13 checks: 13 passed, 0 failed, 0
     * skipped — 646.5s`, as `bin/checks.php` writes it after its last check.
     *
     * @param list<array{name: string, status: string, seconds: float, note: string}> $checks
     */
    private static function gateSummary(array $checks, float $elapsed): string
    {
        $count = static fn (string $status): int => count(array_filter($checks, static fn (array $check): bool => $check['status'] === $status));
        $total = count($checks);

        return sprintf(
            '%d check%s: %d passed, %d failed, %d skipped — %.1fs',
            $total,
            $total === 1 ? '' : 's',
            $count('PASS'),
            $count('FAIL'),
            $count('SKIP'),
            $elapsed,
        );
    }

    /**
     * Section 1's transcript: `bin/inventory.php --check` run twice, and the reading it gives.
     *
     * WHY THE BLOCK IS RUN RATHER THAN WRITTEN
     * ----------------------------------------
     *   The two runs are the state of the three `.tsv` records — against the register the note names,
     *   and against this checkout — and every number in them is the check's own: how many lines of
     *   each record the tree has moved past. Those numbers were quoted in the note by hand, so adding
     *   one source file made the note state a count the tree did not have, and the repair was a
     *   session reading the command's output and typing it back. A number a command measures is the
     *   command's to state; the renderer runs it and writes what it said, and the guard runs the
     *   commands the block quotes rather than trusting the summary beside them.
     *
     *   The register run is rendered at the note's own register, like the other blocks: a check
     *   compares the note with itself, while a take re-reads the checkout. The checkout run cannot
     *   be anything but this tree's reading — that is what the run is — so a working tree whose
     *   pending set has moved since the note was taken is a block `bin/handoff.php` rewrites rather
     *   than one a reader edits, which is the same rule the `pending` table follows.
     *
     *   Only the verdict and each record's count are carried, not the drifted rows the tool names:
     *   those change with every file added, and the note's prose says where to read them. A run that
     *   found the records current is quoted as the tool says it, so a note taken at a release's own
     *   commit reads `exit 0` rather than claiming a disagreement that is not there.
     */
    private static function renderedRecords(string $register): string
    {
        $lines = ['```'];

        foreach (['--check --at='.self::short(self::full($register)), '--check'] as $arguments) {
            $run = self::run([PHP_BINARY, self::ROOT.'/bin/inventory.php', ...explode(' ', $arguments)]);

            $lines[] = 'php bin/inventory.php '.$arguments;
            $lines = array_merge($lines, self::recordLines($run));
            $lines[] = '';
        }

        // The blank after the last run separates commands rather than closing the fence, which the
        // note writes on the line after it — a trailing blank inside the block would be a diff every
        // render.
        array_pop($lines);
        $lines[] = '```';

        return implode("\n", $lines);
    }

    /**
     * One inventory run as the note quotes it: the exit code and verdict on the `-->` line, then the
     * records it found out of step, one apiece, with the count the tool gave for each.
     *
     * The words are the tool's, so a line here cannot disagree with the run: a failure is quoted with
     * the sentence the command itself printed (minus the `✗` it leads with), and a current record with
     * the success note's own phrase. Row counts are the sum-free reading — `N line(s) stale`, exactly
     * as `describeDrift()` writes it — and the rows themselves are left out because the tool truncates
     * them and the note's prose already says what they are.
     *
     * @param array{exit: int, output: string, error: string} $run
     * @return list<string>
     */
    private static function recordLines(array $run): array
    {
        $said = array_merge(self::outputLines($run['error']), self::outputLines($run['output']));
        $verdict = '';
        $stale = [];

        foreach ($said as $line) {
            if ($verdict === '' && preg_match('/^✗\s*(.+)$/u', $line, $matches) === 1) {
                $verdict = rtrim(lcfirst($matches[1]), ':.');

                continue;
            }

            if (preg_match('/^(\S+\.tsv): (\d+) line\(s\) stale\b/u', ltrim($line), $matches) === 1) {
                $stale[] = $matches[1].': '.$matches[2].' line(s) stale';
            }
        }

        if ($verdict === '') {
            foreach ($said as $line) {
                if (str_starts_with($line, '•')) {
                    $verdict = trim(ltrim($line, '•'));
                }
            }
        }

        if ($verdict === '') {
            throw new RuntimeException(sprintf(
                'bin/inventory.php --check exited %d without saying anything a transcript can quote, so the '
                .'records block would be a line with no reading in it.',
                $run['exit'],
            ));
        }

        return array_merge(
            [sprintf('  --> exit %d: %s', $run['exit'], $verdict)],
            array_map(static fn (string $record): string => '      '.$record, $stale),
        );
    }

    /**
     * The unpushed commits, most recent first, as the short id and the subject each row carries.
     *
     * @return list<array{sha: string, subject: string}>
     */
    private static function unpushedCommits(string $origin, string $head): array
    {
        $commits = [];

        foreach (self::asked('log', '--format=%h%x1f%s', $origin.'..'.$head) as $line) {
            [$sha, $subject] = array_pad(explode("\x1f", $line, 2), 2, '');
            $commits[] = ['sha' => $sha, 'subject' => $subject];
        }

        return $commits;
    }

    /**
     * The shape of those commits by type, keyed by the type — the same reading `mix()` takes out of
     * the note, taken out of the log instead.
     *
     * @return array<string, int>
     */
    private static function unpushedMix(string $origin, string $head): array
    {
        $mix = [];

        foreach (self::unpushedCommits($origin, $head) as $commit) {
            if (preg_match('/^([A-Za-z]+)(?:\([^)]*\))?!?:/', $commit['subject'], $matches) === 1) {
                $type = strtolower($matches[1]);
                $mix[$type] = ($mix[$type] ?? 0) + 1;
            }
        }

        ksort($mix);

        return $mix;
    }

    /**
     * A tally as the note writes it: `chore 2, docs 4`, in the alphabetical order the types are
     * keyed in, which is the order the guard compares against rather than the order of the log.
     *
     * Nothing to tally is written as `none` rather than as an empty column: the row is read back by
     * `mix()`, and a row a reader cannot tell from a truncated one is a breakdown nobody can hold.
     *
     * @param array<string, int> $mix
     */
    private static function written(array $mix): string
    {
        if ($mix === []) {
            return 'none';
        }

        return implode(', ', array_map(
            static fn (string $type, int $count): string => $type.' '.$count,
            array_keys($mix),
            array_values($mix),
        ));
    }

    /**
     * The words one pending file is described by.
     *
     * @throws RuntimeException when the file the register does not carry has no line declared for it
     */
    private static function words(string $path): string
    {
        return self::PENDING_DESCRIPTIONS[$path] ?? throw new RuntimeException(sprintf(
            'The register does not carry %s and no words are declared for it. A prose column cannot be derived, so '
            .'add one line about it to Handoff::PENDING_DESCRIPTIONS — or take the file out of the tree, since a file '
            .'that is not work is one .gitignore should carry.',
            $path,
        ));
    }

    /** One commit's abbreviated id, as git abbreviates it rather than as a fixed slice of it. */
    private static function short(string $commit): string
    {
        return self::asked('rev-parse', '--short', $commit.'^{commit}')[0] ?? '';
    }

    /** One commit's subject, asked of git rather than read out of a row. */
    private static function subject(string $commit): string
    {
        return self::asked('show', '-s', '--format=%s', $commit.'^{commit}')[0] ?? '';
    }

    /** The commit id a short id, a ref or a name resolves to. */
    private static function full(string $commit): string
    {
        $resolved = self::asked('rev-parse', $commit.'^{commit}')[0] ?? '';

        if ($resolved === '') {
            throw new RuntimeException(sprintf('git resolved %s to nothing, and a block rendered against it would be empty.', $commit));
        }

        return $resolved;
    }

    /**
     * One git question with no answer this can be rendered without: a failure is raised rather than
     * rendered, because a block written from a failed question is a block that says nothing.
     *
     * @return list<string>
     */
    private static function asked(string ...$arguments): array
    {
        $run = self::git(...$arguments);

        if ($run['exit'] !== 0) {
            throw new RuntimeException(sprintf(
                'git %s could not be asked while a block was being rendered: %s',
                implode(' ', $arguments),
                trim($run['error']) ?: 'it said nothing',
            ));
        }

        return self::outputLines($run['output']);
    }

    /** The comment one block is delimited by, opening or closing. */
    private static function marker(string $name, bool $close = false): string
    {
        return '<!-- '.($close ? '/' : '').'handoff:'.$name.' -->';
    }

    /**
     * The paragraphs of a section: its lines run together until a blank one, each with the line it
     * starts on.
     *
     * A claim is written as a paragraph and wrapped wherever the line runs out, so the unit a
     * sentence is read in is the paragraph and not the line — a reader that asks a line for three
     * numbers is asking the wrong thing, and would answer "no such claim" for a sentence that is
     * simply long.
     *
     * @return list<array{text: string, source: int}>
     */
    private static function paragraphs(string $heading): array
    {
        $paragraphs = [];
        $current = ['text' => '', 'source' => 0];

        foreach (self::section($heading) as $line) {
            if (trim($line['text']) === '') {
                if ($current['text'] !== '') {
                    $paragraphs[] = $current;
                    $current = ['text' => '', 'source' => 0];
                }

                continue;
            }

            if ($current['text'] === '') {
                $current['source'] = $line['source'];
            }

            $current['text'] .= ' '.$line['text'];
        }

        if ($current['text'] !== '') {
            $paragraphs[] = $current;
        }

        return $paragraphs;
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

        // The empty breakdown: the row is what `written([])` writes, and it is read back as an empty
        // tally rather than as a row this cannot read. The commit counts are what decide it — a
        // section with nothing unpushed has no mix — and the words are the ones the renderer writes.
        if (preg_match_all('/([a-z]+)\s+(\d+)/', self::plain($row['value']), $matches, PREG_SET_ORDER) === 0) {
            if (self::plain($row['value']) === 'none') {
                return [];
            }

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
            $plain = self::plain($line['text']);

            if (preg_match('/\b([a-z]+)\s+most recent\b/', $plain, $matches) === 1) {
                $claimed = NumberWords::toInt($matches[1]);

                break;
            }

            // The other sentence a listing is written under: the one that says there is none. It is
            // read as a claim of nothing rather than as the absence of a claim, because "the note
            // lists none" and "the note stopped saying what it lists" are different states and only
            // the second is a section that evidences nothing.
            if (str_contains($plain, 'no listing to print')) {
                $claimed = 0;

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
        $fence = $claimed === 0 ? (self::fenced(self::UNPUSHED) ?? []) : self::fence(self::UNPUSHED);

        foreach ($fence as $line) {
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

        if ($claimed === 0 && $commits !== []) {
            throw new RuntimeException(
                'HANDOFF.md says there is no listing to print and the block under its table lists a commit: a '.
                'section that lists what it says it has not got is a claim and its evidence disagreeing.',
            );
        }

        if ($claimed > 0 && $commits === []) {
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
     * The lines the three tag rows sit on, read through the same `claim()` the readers above use.
     *
     * A release moves those rows — the ones section 3 names the tags, the latest of them and the
     * absent suffixless version in — and it has to move *these* rows: a label compared one way by
     * `bin/release.php` and another way here would leave this guard reading a tag the tree no
     * longer has, on a commit already pushed. `HandoffNoteTest` holds the two readings together by
     * asking the writer's diff to be exactly this answer, so neither side states the rule twice.
     *
     * @return array{tags: int, latest: int, absent: int}
     *
     * @throws RuntimeException when a row stops being there
     */
    public static function tagRowLines(): array
    {
        return [
            'tags' => self::claim(self::UNRELEASED, 'Tags on the branch')['source'],
            'latest' => self::claim(self::UNRELEASED, 'The latest of them')['source'],
            'absent' => self::claim(self::UNRELEASED, 'A version with no suffix')['source'],
        ];
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
     * The total is read through `counted('entry')` rather than as a pattern written out here: a
     * section holding exactly one entry writes the singular and every other count writes the plural,
     * and the pair is declared once in `COUNTED`, so an edit here cannot drop the form a section with
     * one entry needs. What the row calls the number is untouched — it is still digits, and the four
     * numbers still have to be there.
     *
     * @return array{total: int, added: int, fixed: int, changed: int, severity: string}
     *
     * @throws RuntimeException when either row stops saying it in the shape this reads
     */
    public static function unreleased(): array
    {
        $counts = self::claim(self::UNRELEASED, '## Unreleased at the basis');

        if (preg_match('/^(\d+) '.self::counted('entry').' — (\d+) added, (\d+) fixed, (\d+) changed$/', self::plain($counts['value']), $matches) !== 1) {
            throw new RuntimeException(sprintf(
                'HANDOFF.md line %d no longer states the Unreleased section as [total entry/entries — a Added, b '.
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
        return self::fenced($heading) ?? throw new RuntimeException(sprintf(
            'HANDOFF.md has no block of plain output under [%s], so the listing read from it is gone.',
            $heading,
        ));
    }

    /**
     * The same fence, or null where the section holds none.
     *
     * A section that prints no listing is a state the note is written in rather than one it is
     * refused in, so the reader that has to tell the two apart asks the tolerant question. A fence
     * that is opened and never closed is still a refusal: that file is malformed rather than empty.
     *
     * @return list<array{text: string, source: int}>|null
     *
     * @throws RuntimeException when a fence is opened and never closed
     */
    private static function fenced(string $heading): ?array
    {
        return self::fences($heading)[0] ?? null;
    }

    /**
     * Every fence under a heading whose info string is empty, in the order the note writes them.
     *
     * A section holds more than one of them: section 1 writes both the commits its batches became
     * and the transcript of the check that reads the records. So the fences are read here and the
     * readers that want one of them ask which — `fenced()` takes the first, and `transcript()` the one
     * that quotes the tool it is about — rather than each reader walking the section for itself and
     * disagreeing about where a block starts.
     *
     * @return list<list<array{text: string, source: int}>>
     *
     * @throws RuntimeException when a fence is opened and never closed
     */
    private static function fences(string $heading): array
    {
        $fences = [];
        $fence = [];
        $inside = false;
        $wanted = false;
        $openedAt = 0;

        foreach (self::section($heading) as $line) {
            $trimmed = trim($line['text']);

            if (!str_starts_with($trimmed, '```')) {
                if ($inside && $wanted) {
                    $fence[] = $line;
                }

                continue;
            }

            if ($inside) {
                if ($wanted) {
                    $fences[] = $fence;
                }

                $fence = [];
                $inside = false;
                $wanted = false;

                continue;
            }

            // A fence carries its language in the info string, and the note uses one for a command a
            // reader runs (```powershell, ```bash). Both are read the same way here — a fence is
            // opened and the next ``` line closes it — while only the bare ``` is kept: the note's
            // output and listings are written in those, and a block a reader runs is prose.
            $inside = true;
            $wanted = trim(substr($trimmed, 3)) === '';
            $openedAt = $line['source'];
        }

        if ($inside) {
            throw new RuntimeException(sprintf('HANDOFF.md line %d opens a fence that is never closed.', $openedAt));
        }

        return $fences;
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
