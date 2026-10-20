#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/handoff.php — write the handoff note's pending set, records transcript and commit listing.
 *
 * WHAT THIS IS FOR
 * ----------------
 *   `HANDOFF.md` describes a moment: which files this checkout has that its register does not
 *   carry, which commit the register is, how many commits no remote has, how they break down by
 *   type, and the most recent of them by id and subject. Every one of those is a question git
 *   answers, and until now the answer was copied in by hand — which is why the note's own words
 *   call a count of uncommitted paths a fact about one working tree, and why its guard had to be
 *   told not to bind that number to anything. The one block git does not answer is the inventory
 *   check's transcript, and it is the same problem one step along: how far the three `.tsv` records
 *   have fallen behind the tree is a number the check measures, so the renderer runs it and writes
 *   what it said rather than leaving a session to copy the counts.
 *
 *   So the numbers have one home — git, read through `tests/Support/Handoff` — and this command
 *   writes them into the note. A re-take runs it and the blocks follow the tree; whether a human
 *   remembered to re-read `git status` stops being part of whether the note is true.
 *
 * WHAT IT WRITES, AND WHAT IT LEAVES ALONE
 * ----------------------------------------
 *   Five blocks, each delimited in the note by `<!-- handoff:NAME -->` and `<!-- /handoff:NAME -->`:
 *
 *     gate       section 1's summary — the last recorded gate run, read out of `.agents/gate.json`:
 *                its totals, its exit code, the day and interpreter it was taken on, and every
 *                check with its verdict, seconds and note. Written by `bin/checks.php --record`
 *                rather than run here: the suite that reads this block cannot run the gate it is
 *                inside, and a note that stated those numbers by hand was restating a run.
 *     records    section 1's transcript — `bin/inventory.php --check` run at the register and at
 *                this checkout, with each run's exit code, verdict and per-record stale count
 *     pending    section 1's table — the files this checkout has that the register does not carry,
 *                with the words `Handoff::PENDING_DESCRIPTIONS` declares for each
 *     unpushed   section 2's table — the branch, the register, the remote tip, the count and the
 *                breakdown by type
 *     log        section 2's listing — the most recent unpushed commits, and the sentence above
 *                them that counts them
 *
 *   Everything else in the note is prose a session writes: what a batch is, why it was done, what
 *   its controls measured. The renderer touches the bytes between the markers and not one byte
 *   outside them.
 *
 * WHY A GENERATOR IS SAFE HERE
 * ----------------------------
 *   The note's guard renders the same five blocks from the same derivations and asserts the file
 *   says what they say, so a hand-edited block is red in the suite rather than silently believed —
 *   and the register a *check* renders at is the one the note already names, not `HEAD`, because a
 *   note describes a register older than the commit that lands it. That is also why nothing is
 *   written unless every block could be rendered: a half-rendered note is worse than a stale one.
 *
 *   The one input git cannot answer is the prose column of section 1's table, and it is declared
 *   beside the renderer rather than carried over from the table it is about: a file the register
 *   does not carry and that has no line declared for it is refused by name.
 *
 * THE OTHER HALF OF THAT COLUMN: `--reaffirm`
 * ------------------------------------------
 *   A declared line is words *and* the reading of the file they were written against, so the table
 *   refuses a row whose file has changed since the words were read — a pending file is work in
 *   progress, and a sentence beside it that nobody re-read is the note asserting something about a
 *   file that may have become a different one. `--reaffirm` is the repair: it keeps the words, takes
 *   the reading the files have now, and rewrites the declaration block in `tests/Support/Handoff.php`,
 *   so that a re-reading is a thing somebody ran rather than a hash that drifted. A row whose words no
 *   longer hold is reworded first and reaffirmed after.
 *
 * ONE BLOCK AT A TIME: `--block`
 * ------------------------------
 *   `--block=NAME[,NAME]` renders only the regions named and never takes the note: the snapshot is the
 *   one the note already records, so a render of one block cannot move a row of another. It exists
 *   because the gate block has a second writer — `bin/checks.php --record` writes the record and then
 *   renders the block from it, since a note taken before a ten-minute run and the run recorded after it
 *   left the note quoting the run before last. The record is the only input that run changes, so the
 *   block rendered from it is the only region it may move: rendering all five would be a re-take by
 *   another name, and would refuse on a pending file whose description nobody has written yet.
 *
 * USAGE
 * -----
 *   php bin/handoff.php                     write the blocks at the commit this checkout is on
 *   php bin/handoff.php --recorded          write them at the register the note already names
 *   php bin/handoff.php --block=gate        render one block, at the note's own snapshot
 *   php bin/handoff.php --register=<commit> write them at another commit
 *   php bin/handoff.php --check             compare, write nothing, exit 1 when a block is stale
 *   php bin/handoff.php --reaffirm          re-read every declared pending file
 *   php bin/handoff.php --reaffirm=<path>   re-read one of them
 *   php bin/handoff.php --help
 *
 * EXIT CODES
 * ----------
 *   0 written, or every block already in step under --check, or the readings taken under
 *   --reaffirm; 1 a block is stale, could not be rendered, or a reading could not be taken, or the
 *   note could not be read or written; 2 usage error.
 */

use Uak35\WeightedDbManager\Tests\Support\Handoff;

$root = str_replace('\\', '/', dirname(__DIR__));
$check = false;
$recorded = false;
$register = null;
$reaffirm = null;
$only = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        usage();
        exit(0);
    }

    if ($argument === '--check') {
        $check = true;
        continue;
    }

    if ($argument === '--recorded') {
        $recorded = true;
        continue;
    }

    if (str_starts_with($argument, '--register=')) {
        $register = substr($argument, 11);
        continue;
    }

    if (str_starts_with($argument, '--block=')) {
        $only = array_values(array_filter(array_map('trim', explode(',', substr($argument, 8)))));
        continue;
    }

    // `--reaffirm` on its own re-reads every declared pending file; the `=path` form re-reads one,
    // so a session that changed a single file records a reading of that file rather than blessing
    // the whole table.
    if ($argument === '--reaffirm') {
        $reaffirm ??= [];
        continue;
    }

    if (str_starts_with($argument, '--reaffirm=')) {
        $reaffirm ??= [];
        $reaffirm[] = substr($argument, 11);
        continue;
    }

    fwrite(STDERR, "Unknown option: {$argument}" . PHP_EOL . PHP_EOL);
    usage();
    exit(2);
}

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, 'No vendor/autoload.php — run `composer install` first.' . PHP_EOL);
    exit(1);
}

require $root . '/vendor/autoload.php';

// A block name is refused before anything is rendered, for the same reason `--only` is in the gate: a
// typo that rendered nothing would read as a render that found nothing to write.
if ($only !== []) {
    $unknown = array_values(array_diff($only, Handoff::blocks()));

    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown block(s): ' . implode(', ', $unknown) . PHP_EOL);
        fwrite(STDERR, 'Known: ' . implode(', ', Handoff::blocks()) . PHP_EOL);
        exit(2);
    }
}

if (!is_file($root . '/HANDOFF.md')) {
    fwrite(STDERR, "Not a package root — no HANDOFF.md under {$root}." . PHP_EOL);
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Reaffirming: the readings the pending table refuses on
// ─────────────────────────────────────────────────────────────────────────────

if ($reaffirm !== null) {
    try {
        $readings = Handoff::pendingReadings($reaffirm);
    } catch (Throwable $e) {
        fwrite(STDERR, 'The readings could not be taken: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    $declarations = $root . '/' . Handoff::DECLARATIONS_FILE;
    $source = @file_get_contents($declarations);

    if ($source === false) {
        fwrite(STDERR, Handoff::DECLARATIONS_FILE . ' could not be read, and no reading was written.' . PHP_EOL);
        exit(1);
    }

    $spliced = reaffirm($source, $readings['block']);

    if ($spliced === null) {
        fwrite(STDERR, sprintf(
            '%s has no `%s` … `%s` block: the markers a reading is written between are gone.' . PHP_EOL,
            Handoff::DECLARATIONS_FILE,
            Handoff::DECLARATIONS,
            Handoff::DECLARATIONS_END,
        ));
        exit(1);
    }

    if ($spliced === $source) {
        echo sprintf('%d pending reading(s), all in step with the files they describe; nothing was written.', $readings['read']) . PHP_EOL;

        exit(0);
    }

    if (@file_put_contents($declarations, $spliced) === false) {
        fwrite(STDERR, Handoff::DECLARATIONS_FILE . ' could not be written.' . PHP_EOL);
        exit(1);
    }

    echo sprintf('%d pending reading(s) re-taken: %s rewritten.', $readings['read'], Handoff::DECLARATIONS_FILE) . PHP_EOL;

    exit(0);
}

// ─────────────────────────────────────────────────────────────────────────────
// Deriving: every block, before a byte is written
// ─────────────────────────────────────────────────────────────────────────────

// A write re-takes the note, so it renders the snapshot this checkout is in — the register is the
// commit it is standing on, because the row being written is one of the things that moves. A check
// compares the note with itself, so it renders the snapshot the note already records, and a note
// committed at one commit is not reported stale by every commit made after it — nor by a push,
// which moves `origin/dev` and leaves the pair the note names where it was.
//
// `--recorded` is the third case, and the one `bin/release.php` needs: it writes the blocks at the
// register the note already names, so a release can refresh what its own rewrites moved — the records
// in section 1 — without re-taking the note. A release that re-took the register would be describing
// the commit before itself as the thing a reader is behind, which is the opposite of what a release is.
try {
    // One take for the whole render, and none at all for a comparison or a partial one: a check renders
    // the snapshot the note records, and `--block` is a render rather than a re-take.
    $snapshot = $check || $recorded || $only !== [] ? null : Handoff::take($register);
    $blocks = $only === [] ? Handoff::blocks() : array_values(array_intersect(Handoff::blocks(), $only));
    $rendered = [];

    foreach ($blocks as $name) {
        $rendered[$name] = Handoff::rendered($name, $snapshot);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'The blocks could not be rendered: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$source = @file_get_contents($root . '/HANDOFF.md');

if ($source === false) {
    fwrite(STDERR, 'HANDOFF.md could not be read, and nothing was written.' . PHP_EOL);
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Writing
// ─────────────────────────────────────────────────────────────────────────────

$outOfStep = [];
$report = [];
$note = $source;

foreach ($rendered as $name => $block) {
    $spliced = splice($note, $name, $block);

    if ($spliced === null) {
        fwrite(STDERR, sprintf(
            'HANDOFF.md has no `%s` block: the markers that delimit it are gone, so the bytes this would write '
            .'are not marked — put back `<!-- handoff:%s -->` and `<!-- /handoff:%s -->` around the region.'
            .PHP_EOL,
            $name,
            $name,
            $name,
        ));
        exit(1);
    }

    $stale = $spliced !== $note;
    $note = $spliced;

    if ($stale) {
        $outOfStep[] = $name;
    }

    $report[] = sprintf('  %-10s %s', $name, $stale ? 'out of step' : 'in step');
}

echo implode(PHP_EOL, $report) . PHP_EOL;

if ($check) {
    echo $outOfStep === []
        ? sprintf('%d block(s) in HANDOFF.md, all in step with their derivations.', count($rendered)) . PHP_EOL
        : sprintf('%d block(s) out of step — run `php bin/handoff.php` to render them.', count($outOfStep)) . PHP_EOL;

    exit($outOfStep === [] ? 0 : 1);
}

if ($outOfStep === []) {
    echo sprintf('%d block(s) already in step; nothing was written.', count($rendered)) . PHP_EOL;

    exit(0);
}

if (@file_put_contents($root . '/HANDOFF.md', $note) === false) {
    fwrite(STDERR, 'HANDOFF.md could not be written.' . PHP_EOL);
    exit(1);
}

echo sprintf('%d block(s) written from git: %s.', count($outOfStep), implode(', ', $outOfStep)) . PHP_EOL;

exit(0);

/**
 * One block replaced between its markers — or null when either marker is missing.
 *
 * The markers are kept rather than written: they are the note's own, and a renderer that rewrote
 * them would be free to move the region the next run has to find.
 */
/**
 * The declaration block replaced between its two markers — or null when either is gone.
 *
 * The markers are comment lines inside the class, and the block sits between them whole: the line the
 * opening marker is on is kept, because it carries the sentence saying what the block is, and the
 * close marker's line is kept with its indentation.
 */
function reaffirm(string $source, string $block): ?string
{
    $from = strpos($source, Handoff::DECLARATIONS);

    if ($from === false) {
        return null;
    }

    $start = strpos($source, "\n", $from);
    $close = strpos($source, Handoff::DECLARATIONS_END, $start === false ? $from : $start);

    if ($start === false || $close === false) {
        return null;
    }

    $line = strrpos(substr($source, 0, $close), "\n");

    if ($line === false) {
        return null;
    }

    return substr($source, 0, $start + 1) . $block . "\n" . substr($source, $line + 1);
}

function splice(string $source, string $name, string $block): ?string
{
    $open = '<!-- handoff:' . $name . ' -->';
    $close = '<!-- /handoff:' . $name . ' -->';

    $from = strpos($source, $open);
    $to = strpos($source, $close);

    if ($from === false || $to === false || $to < $from) {
        return null;
    }

    $start = $from + strlen($open);

    // `\n` rather than `PHP_EOL`: the note is `eol=lf` in `.gitattributes`, so a block written with
    // the platform's ending would be a rewrite of every line of it on every run — and on Windows it
    // would compare as out of step for ever, since the guard reads the file's lines and renders its
    // own with `\n`.
    return substr($source, 0, $start) . "\n" . $block . "\n" . substr($source, $to);
}

function usage(): void
{
    echo <<<'TEXT'
    bin/handoff.php — write the handoff note's pending set and commit listing from git.

    Usage:
      php bin/handoff.php                     write the blocks at the commit this checkout is on
      php bin/handoff.php --recorded          write them at the register the note already names
      php bin/handoff.php --block=gate        render one block, at the note's own snapshot
      php bin/handoff.php --register=<commit> write them at another commit
      php bin/handoff.php --check             compare, write nothing, exit 1 when a block is stale
      php bin/handoff.php --reaffirm          re-read every declared pending file
      php bin/handoff.php --reaffirm=<path>   re-read one of them
      php bin/handoff.php --help

    The blocks are the `gate`, `records`, `pending`, `unpushed` and `log` regions of HANDOFF.md,
    each delimited by `<!-- handoff:NAME -->` … `<!-- /handoff:NAME -->`. The derivations are in
    tests/Support/Handoff, which is also what the note's guard reads, so a block this writes is a
    block the suite checks. The gate block needs a recorded run to read: `php bin/checks.php
    --record` writes one and renders that block from it, and this refuses rather than renders from
    a record that is not there.

    `--block=NAME` renders only the regions named, comma separated, at the snapshot the note already
    records — a render rather than a re-take, so no other row moves. It is what `bin/checks.php
    --record` uses for the gate block it just rewrote.

    The prose column of the pending table is declared in tests/Support/Handoff — the words, and the
    reading of the file each was written against. `--reaffirm` keeps the words and takes a fresh
    reading, which is what a session runs after re-reading a pending file; until then the table
    refuses that row rather than printing a description nobody re-read.

    Exit codes: 0 written, in step or re-read, 1 stale, unrenderable or unreadable, 2 usage error.
    TEXT;
    echo PHP_EOL;
}
