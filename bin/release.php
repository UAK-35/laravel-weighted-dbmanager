#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/release.php — cut a release from the git tags.
 *
 * THE MODEL
 * ---------
 *   The version of this package is the tag, and nothing else. There is no
 *   `version` field in composer.json and no version file: a number stored in the
 *   repository is a second source of truth that drifts from the tag, and
 *   Packagist derives every published version from tags regardless. So this
 *   script never writes a version — it reads the latest tag, works out the next
 *   one, promotes the CHANGELOG heading, commits and tags.
 *
 *   With no tags at all the base is 0.0.0, so the weighed bump applies to it
 *   directly: a minor weighing gives 0.1.0, a patch weighing 0.0.1.
 *
 * THE BUMP IS WEIGHED
 * -------------------
 *   `--weigh` reads the changes that are about to be released, weighs them
 *   against the versioning policy in RELEASING.md and takes the bump: a fix, a
 *   doc correction or a test is a patch; a new command, config key or health
 *   field is a minor; a removed or renamed public class, method or config key is
 *   a minor while 0.x and a major after 1.0.0. Four signals are read and the
 *   loudest one wins:
 *
 *     CHANGELOG   the `###` headings of the Unreleased section, which are the
 *                 Keep a Changelog categories the policy is written against
 *     commits     the subjects and body footers since the last tag, read as
 *                 Conventional Commits (feat, fix, docs, `!`, BREAKING CHANGE)
 *     public API  the classes, public methods, constants, cases, properties and
 *                 config keys of src/ and config/ at HEAD versus the last tag
 *     inventory   files.tsv, methods.tsv and surface.tsv as the last release wrote
 *                 them, against the tree now — read only when their stamp names the
 *                 tag being released from, so a stale one is reported, not trusted
 *
 *   The inventory is the one signal that does not need a tag: this script writes all
 *   three files into the release commit itself, stamped with the tag it is creating,
 *   so the next release has a written-down copy of the last one to weigh against —
 *   and `surface.tsv` carries the config keys, env vars, constants, cases and
 *   properties, which are the rows that witness a removal on a tree with no tag at
 *   all, since the public-API signal above diffs two tags.
 *
 *   Those three files are also what a consumer reads, as `API.md`: `bin/api.php` renders the
 *   page from them, and this script writes it into the same commit — so a release publishes a
 *   page that names the tag it was cut from rather than the one before it. The page is derived
 *   and is never weighed, which is why it is written after the record it renders: a page
 *   rendered from a record that is not on disk yet is the state a hand-run leaves behind.
 *
 *   The record and the page are rows of one list in `bin/surface.php` (`derivedFiles()`), and
 *   each row names the commands that write it — this script among them, which is the whole of
 *   why the files this release writes and commits are `derivedBy('release', …)` rather than a
 *   list kept here: a fifth derived artefact is a row, and a command missing from a row stops
 *   writing that file instead of half-writing the set.
 *
 *   A name two files declare is one entry in the public-API map — the first file read
 *   holds it, and the second file's declaration is not in the surface at all — so a
 *   change to that second file's key is a change the tag diff cannot see. The plan says
 *   so in a note that names the names and the files behind each one. The inventory writes
 *   both declarations down — its rows carry the file they came from — but its verdict is
 *   keyed by name for the same reason, so it is the file, and not a verdict, that keeps
 *   them apart.
 *
 *   A record that is *partly* missing is the one state this script refuses over rather than
 *   weighing three signals and calling it a verdict: at least one file carries a stamp, so a
 *   previous release published the record, and a file missing from it cannot be "never
 *   written" — the rows it held are the ones nothing else can witness. The refusal names the
 *   file and `php bin/inventory.php --at=<the stamp the record carries>`, which reproduces the
 *   rows the tag actually published. An inventory that is *entirely* absent is a tree that has
 *   never written one, which this release does; a stale one is replaced by this release. Those
 *   two are reported and skipped, and the refusal is reserved for the state that is
 *   distinguishable.
 *
 *   The name is the verb on purpose: it does not only work the bump out, it
 *   takes it, which is why it is not called --detect.
 *
 *   An explicit bump (--minor, --major, --version=X.Y.Z) is still allowed, but
 *   never smaller than what the policy asks for: declaring one that undersells
 *   the changes stops the release with exit code 1 and names the change that
 *   forbids it. `--patch` is gone — that is what --weigh computes.
 *
 * THE NOTES ARE A RAIL, NOT ONLY A SIGNAL
 * ---------------------------------------
 *   The notes are weighed, which decides the version; they are also required to be
 *   *about* the release. A `### Fixed` entry beside a new public method weighs a patch and
 *   satisfies every other rail — the section is not empty, and the bump is the loudest of the
 *   four signals — so the changelog would then announce a fix while a consumer gained
 *   something to use, and nobody reading it would have been told. So a surface change
 *   heavier than the notes stops the release, names the symbols in the refusal, and
 *   --allow-silent-notes is the escape, printed in the plan like every other one.
 *
 *   The comparison is severity against severity rather than a search of the entries for the
 *   symbol names: the headings are the vocabulary the changelog and the policy already
 *   share, and a release note that has to spell every class it mentions is a rule authors
 *   route around. `### Removed` is what a removal is, and the refusal says so.
 *
 * THE NOTE THE RELEASE MOVES
 * --------------------------
 *   HANDOFF.md's section 3 names the tags on the branch, the latest of them and the suffixless
 *   version that is *not* there — three claims about *now*, and the tag this run is about to cut
 *   falsifies all three at once. So they are moved into the release commit beside the CHANGELOG,
 *   the record and the page, and nothing else in the note is touched: the rest of it is a
 *   snapshot of a working tree taken by hand, and a script has no way to know what is pending,
 *   which commits a push carries or what a thread meant, so re-taking the note is the note's own
 *   act rather than this script's.
 *
 *   A package that keeps no note has nothing moved and nothing written — the fixture the release
 *   suite drives is one. A note that is there has to be movable: a row this cannot find by the
 *   label the guard reads it by, or two rows sharing one label, stops the release before anything
 *   is written, because the alternative is a tag whose commit CI reports as a stale note, and by
 *   then the tag is a version somebody has fetched.
 *
 * PRERELEASES
 * -----------
 *   A version may carry a prerelease suffix — `-alpha1`, `-beta1`, `-rc1`, or a bare
 *   `-dev` — and the suffixes this script writes are the ones Composer's own parser
 *   reads. That is not tidiness: a tag Composer cannot parse is a tag nobody can
 *   install, and it fails quietly rather than loudly, so `0.0.1-dev.1` is refused here
 *   instead of being discovered downstream. `dev` takes no number, which is why a
 *   numbered dev lane is spelled `-alpha1`.
 *
 *   The number is written without a dot because that is how Packagist's own examples
 *   spell a prerelease (`1.10.5-RC1`), and because a dot would be a second spelling of
 *   one version: Composer reads `-alpha.1` and `-alpha1` alike as `0.0.1.0-alpha1`, so
 *   two tags carrying them are one version published twice — and once a dotted one
 *   exists it blocks the undotted one on the "is not newer" rail. Exactly one spelling
 *   is therefore accepted, and it is the undotted one.
 *
 *   The suffix decides the branch as well. A prerelease precedes the release it is named
 *   after, so it is cut from `dev`, and a version with no suffix is cut from `main`.
 *   `--branch` overrides either, and the plan prints which one it expects.
 *
 *   Promotion is the same either way — the notes move, a fresh `## Unreleased` is left
 *   above them, and the inventory is rewritten and stamped with the prerelease tag —
 *   because a prerelease is a version like any other. It is the numbering that differs.
 *
 *   A tag is a version, so it is not cut on a commit nothing verified. Before anything is
 *   written, the script asks whether the commit being released — HEAD, the tip of the
 *   branch — is on the remote and whether the workflow's run for it passed; --skip-ci
 *   releases anyway and says so in the plan. The release *commit* is bookkeeping and cannot
 *   have a run of its own yet, because the push that puts it on the remote comes last; the
 *   workflow's `v*` tag trigger is what records it, after the fact.
 *
 *   What that leaves is the tree a tag points at, which is this release's own commit. So the
 *   release reads it: the handoff note's blocks are rendered at the register the note names
 *   (`bin/handoff.php --recorded` — a render, not a re-take), and once the commit exists the
 *   note's guard (`HandoffTest`) is run against it, with the tag being created passed as the
 *   one pending tag, before anything is tagged. A red guard refuses, the commit is unmade with
 *   `git reset --soft` and its files stay staged, and no tag is created: a note that would be
 *   red at a release commit is a version nobody is handed, rather than a runner finding out
 *   after the push. A tree with a note and no installed suite refuses rather than skipping the
 *   check, and `HANDOFF_GUARD` names the command for a fixture that has one and no vendor.
 *
 * USAGE
 * -----
 *   php bin/release.php --weigh --dry-run      show the plan, change nothing
 *   php bin/release.php --weigh                v0.0.1 -> v0.0.2
 *   php bin/release.php --minor                declare a minor (never below the policy)
 *   php bin/release.php --major                declare a major
 *   php bin/release.php --version=1.4.0        release exactly this version
 *   php bin/release.php --weigh --push         ...and push branch + tag
 *
 *   --weigh          Weigh the changes and take the bump they ask for, per the
 *                    policy in RELEASING.md. This is the one to reach for.
 *   --dry-run        Print the plan (version, CHANGELOG head, alias, commands)
 *                    and change nothing. Safe on a dirty tree.
 *   --yes, -y        Do not ask for confirmation.
 *   --push           Push the branch with --follow-tags when done.
 *   --branch=NAME    Branch to release from (default: main).
 *   --remote=NAME    Remote to push to (default: origin).
 *   --allow-dirty    Release even though tracked files have uncommitted changes.
 *   --ignore-policy  Release even though the declared bump undersells the
 *                    changes. Loud on purpose, and printed in the plan.
 *   --allow-silent-notes
 *                    Release even though the public surface changed and the Unreleased
 *                    notes do not account for it. Loud on purpose, and printed in the plan.
 *
 * EXIT CODES
 * ----------
 *   0 released (or dry run planned), 1 a precondition failed, 2 usage error.
 */

// The shared halves: the symbol reader, the surface differ and the inventory format are
// in bin/surface.php (shared with bin/inventory.php), and the four signals with the
// weighing that reads them are in bin/weighing.php (shared with bin/blame.php). This file
// keeps the command: the arguments, the preconditions, the plan and the tag.
require __DIR__ . '/weighing.php';

// The page the record renders is bin/api.php, required rather than shelled out to because
// this is the script that writes the record: that file's own help has said since it was
// written that the inventory "is written by the release script, so this page is refreshed in
// the same commit", and until now nothing in this file knew it existed. Including it only
// defines its functions and the reader it shares with this one — its command runs when it is
// the file being run, which is a comparison against __FILE__ rather than against $argv[0].
require_once __DIR__ . '/api.php';

$root = str_replace('\\', '/', dirname(__DIR__));

$options = [
    'kind' => null,
    'version' => null,
    'dry-run' => false,
    'yes' => false,
    'push' => false,
    // null rather than 'main': the suffix decides which branch a release is cut
    // from, and --branch is the override. See $expectedBranch below.
    'branch' => null,
    'remote' => 'origin',
    'allow-dirty' => false,
    'skip-ci' => false,
    'ignore-policy' => false,
    'allow-silent-notes' => false,
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        usage();
        exit(0);
    }

    // --patch used to pick the bump by hand. It is gone on purpose: weighing the
    // bump is the whole point of --weigh, and a hand-picked patch is what
    // undersold a change in the first place. Saying so beats ignoring it.
    if ($argument === '--patch') {
        usageError(
            '--patch was removed: the bump is weighed from the changes, so there is'
            . ' nothing to declare for a patch.' . PHP_EOL . 'Use --weigh.',
        );
    }

    if ($argument === '--weigh') {
        $options['kind'] = 'weigh';
        continue;
    }

    if (in_array($argument, ['--minor', '--major'], true)) {
        $options['kind'] = substr($argument, 2);
        continue;
    }

    if ($argument === '--ignore-policy') {
        $options['ignore-policy'] = true;
        continue;
    }

    if (str_starts_with($argument, '--version=')) {
        $options['version'] = substr($argument, 10);

        // Validated where it is parsed rather than where it is used: the suffix also
        // decides which branch the release is expected on, so a rail that ran later
        // would answer a misspelled suffix with "that is the wrong branch" — true, and
        // not the thing that is wrong.
        if (!isReleasableVersion(ltrim($options['version'], 'vV'))) {
            usageError(sprintf(
                'Not a version this can release: %s' . PHP_EOL . PHP_EOL
                . 'A version is X.Y.Z, or X.Y.Z with a prerelease suffix — -alpha1, -beta1 or'
                . PHP_EOL
                . '-rc1, each with an optional number written without a dot, or a bare -dev.'
                . PHP_EOL
                . 'Those are the forms Composer can read, and a tag it cannot read is one nobody'
                . PHP_EOL
                . 'can install — which is why the suffix is refused here rather than written and'
                . PHP_EOL
                . 'found later. `dev` takes no number, so a numbered dev lane is -alpha1.'
                . PHP_EOL . PHP_EOL
                . 'The dot is refused on purpose: Composer reads -alpha.1 and -alpha1 as the same'
                . PHP_EOL
                . 'version, so a tag carrying one spelling blocks the other.',
                $options['version'],
            ));
        }

        continue;
    }

    if ($argument === '--dry-run') {
        $options['dry-run'] = true;
        continue;
    }

    if ($argument === '--yes' || $argument === '-y') {
        $options['yes'] = true;
        continue;
    }

    if ($argument === '--push') {
        $options['push'] = true;
        continue;
    }

    if ($argument === '--allow-dirty') {
        $options['allow-dirty'] = true;
        continue;
    }

    if ($argument === '--skip-ci') {
        $options['skip-ci'] = true;
        continue;
    }

    if ($argument === '--allow-silent-notes') {
        $options['allow-silent-notes'] = true;
        continue;
    }

    // --allow-empty used to release anyway when the Unreleased section held nothing.
    // It is gone on purpose. The notes are what the weighing reads and what a reader
    // upgrades on, so an empty section is not a variance to permit — it is the reason
    // there is nothing to release. Saying so beats "Unknown option".
    if ($argument === '--allow-empty') {
        usageError(
            '--allow-empty was removed: a release needs entries under "## Unreleased".'
            . PHP_EOL . 'They are what the bump is weighed from, and what a reader decides'
            . ' whether to upgrade on.' . PHP_EOL . 'Write them.',
        );
    }

    if (str_starts_with($argument, '--branch=')) {
        $options['branch'] = substr($argument, 9);
        continue;
    }

    if (str_starts_with($argument, '--remote=')) {
        $options['remote'] = substr($argument, 9);
        continue;
    }

    fwrite(STDERR, "Unknown option: {$argument}" . PHP_EOL . PHP_EOL);
    usage();
    exit(2);
}

if ($options['kind'] === null && $options['version'] === null) {
    fwrite(STDERR, 'Pick a bump: --weigh, --minor, --major or --version=X.Y.Z' . PHP_EOL . PHP_EOL);
    usage();
    exit(2);
}

if ($options['kind'] !== null && $options['version'] !== null) {
    fwrite(STDERR, 'Use either --weigh/--minor/--major or --version, not both.' . PHP_EOL);
    exit(2);
}

// ─────────────────────────────────────────────────────────────────────────────
// Preconditions
// ─────────────────────────────────────────────────────────────────────────────

$dryRun = $options['dry-run'];

if (git($root, ['rev-parse', '--git-dir'])['exit'] !== 0) {
    fail('This is not a git repository — releases are tags, so git is required.');
}

$changelogPath = $root . '/CHANGELOG.md';
$composerPath = $root . '/composer.json';

foreach ([$changelogPath, $composerPath] as $required) {
    if (!is_file($required)) {
        fail('Missing ' . basename($required) . '.');
    }
}

// symbolic-ref reads the branch off an unborn HEAD too, where rev-parse fails
// with a fatal — which is exactly the state a package is in before its first
// commit.
$branch = trim(git($root, ['symbolic-ref', '--short', '-q', 'HEAD'])['output']);

if ($branch === '') {
    $branch = trim(git($root, ['rev-parse', '--abbrev-ref', 'HEAD'])['output']);
}

// A release is cut from the branch that holds released versions; a prerelease is cut
// from the line being developed. The suffix decides, and --branch overrides it — which
// is why the option has no default of its own. Deciding it from the *declared* version
// is enough because --weigh, --minor and --major can only ever produce a release: the
// only way to name a prerelease is to spell it out with --version.
$expectedBranch = $options['branch'] !== null
    ? (string) $options['branch']
    : releaseBranchFor(ltrim((string) ($options['version'] ?? ''), 'vV'));

if ($branch !== $expectedBranch) {
    fail(sprintf(
        'Releases are cut from %s, but HEAD is on %s. Switch branch (or pass --branch=%s).',
        $expectedBranch,
        $branch,
        $branch,
    ));
}

// `rtrim`, not `trim`: porcelain's first line begins with the index column, and trimming the
// left end of it turns an unstaged edit (` M path`) into a staged one (`M  path`) — a
// different state from the one this rail is about, and one both surfaces below now quote.
$dirty = rtrim(git($root, ['status', '--porcelain', '--untracked-files=no', '--', '.'])['output']);

if ($dirty !== '' && !$options['allow-dirty'] && !$dryRun) {
    fail(
        'Tracked files have uncommitted changes — commit them first, so the tag points at'
        . ' exactly what was reviewed.' . PHP_EOL . PHP_EOL . $dirty,
    );
}

if ($dirty !== '' && $dryRun) {
    $dirtyPaths = explode("\n", $dirty);

    note(sprintf('Working tree is dirty (%d changed file(s)) — ignored for this dry run.', count($dirtyPaths)));

    // Named rather than left to `git status`, because the count raises a question it does not
    // answer — *which* files — and this run already holds the answer: the same porcelain a real
    // run refuses with, printed here instead of appended to a refusal. The two surfaces are one
    // reading of the tree, so a plan and the refusal it is a rehearsal of cannot name different
    // sets, and a reader who sees the dirty tree in the plan does not have to leave it to find
    // out what is standing in the way.
    foreach ($dirtyPaths as $dirtyPath) {
        echo '    ' . $dirtyPath . PHP_EOL;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CHANGELOG — read first, because the notes are what the bump is weighed from
// ─────────────────────────────────────────────────────────────────────────────

$changelog = (string) file_get_contents($changelogPath);
$unreleased = unreleasedSection($changelog);

if ($unreleased === null) {
    fail(
        'CHANGELOG.md has no "## Unreleased" section to promote. Add the release notes'
        . ' under that heading first.',
    );
}

$entries = countBullets($unreleased['body']);

// The rail below is about what a release *publishes*; the weighing is a question about the
// *changes*, and the notes are only one of the four things it reads. So `--weigh --dry-run`
// is let past an empty section: it writes nothing, so no version can be published with no
// notes under it, and "what does the next bump weigh as?" is exactly the question a tree
// with nothing under Unreleased is asked. Every run that could tag still refuses here — the
// two answers differ because they are answers to different questions, one about the version
// a reader gets and one about the changes a release would carry.
$emptyNotes = $entries === 0;
$weighsEmptyNotes = $emptyNotes && $dryRun && $options['kind'] === 'weigh';

if ($emptyNotes && !$weighsEmptyNotes) {
    fail(
        'The Unreleased section has no entries, so there is nothing to release. Write'
        . ' them under "## Unreleased" first; a version with no notes is one nobody can'
        . ' read, and the bump is weighed from them.'
        . PHP_EOL . PHP_EOL
        . 'A dry run of --weigh reports the weighed bump from an empty section instead of'
        . ' refusing, because a plan publishes nothing. Every run that could tag refuses.',
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Work out the version — the changes decide the bump
// ─────────────────────────────────────────────────────────────────────────────

$latestTag = latestTag($root);
$base = $latestTag === null ? '0.0.0' : ltrim($latestTag, 'vV');

/*
 * A prerelease does not hold the rung it is named after — it *announces* it. So there
 * are two versions here, and telling them apart is the whole of what a dev tag changes
 * about the release that follows it:
 *
 *   $base       the latest tag, suffix and all: what a candidate has to be newer than,
 *               and the version the plan shows as the one this release is built on
 *   $rungBase   the newest release at or below the line being developed: the version a
 *               rung is measured from, so promoting a dev tag steps from 0.2.0 to
 *               0.5.0 — a minor — rather than from 0.5.0-alpha1 to 0.5.0, which reads
 *               as the patch it is not
 *
 * Measured from the dev tag, the promotion of one is always a patch, and a patch is
 * below whatever the changes call for the moment they are more than a fix. The damage
 * shows at both ends: a release the policy is satisfied by (0.5.0 over 0.2.0) is refused,
 * and `--weigh`, asked for the version that does satisfy it, answers with `bump()` from
 * the dev tag — 3.0.0 for the 2.0.0 line an alpha announced, a version that skips the
 * release it was cut for.
 */
$line = releasePart($base);
$rungBase = precedingRelease($root, $line);
$promotion = isPrerelease($base) && version_compare($line, $rungBase, '>') ? $line : null;

/*
 * Weighing runs either way. `--weigh` releases from it, and a declared bump is
 * checked against it, so a patch can no longer be asked for when the notes
 * describe a new command — which is the case that used to ship a breaking
 * change in a patch release and surprise everybody downstream.
 */
$weighing = weigh($root, $latestTag, $unreleased['body'], $base);
$required = $weighing['bump'];

if ($options['kind'] === 'weigh') {
    $declared = null;

    // A dev tag's own release comes first, because that is the version its line is being
    // developed for and the one a consumer is waiting on. It is only stepped past when the
    // changes since the tag ask for more than the promotion itself climbs — and then the
    // step is taken from the last release, since stepping one rung above a prerelease is
    // how 0.5.0-alpha1 would come out as 0.6.0.
    $version = $promotion !== null
        && severityRank(kindBetween($rungBase, $promotion)) >= severityRank($required)
            ? $promotion
            : bump($rungBase, $required);
} elseif ($options['version'] !== null) {
    $version = ltrim($options['version'], 'vV');

    // Already held at the parse site, where the explanation a user needs is printed.
    // Kept because it is the one place the rule is stated, so a second path that ever
    // sets $options['version'] cannot get past it.
    if (!isReleasableVersion($version)) {
        fail("Not a version this can release: {$version}");
    }

    // `-RC1` and `-rc1` are one version to Composer, so the tag carries the lower-case
    // spelling: the repository cannot end up with two tags claiming a single version.
    $version = canonicalVersion($version);

    // Against the last release, so that declaring the release a dev tag announced is the
    // minor or major it is rather than the patch its own prerelease makes it look like.
    $declared = kindBetween($rungBase, $version);
} else {
    $declared = (string) $options['kind'];

    // Stepped from the tag this branch is on rather than from $rungBase: `--minor` is a
    // command about the line being developed, and a minor above the last *release* would
    // answer with a version older than the dev tag it is meant to move past.
    $version = bump($base, $declared);
}

$tag = 'v' . $version;

// A tag that is already there is answered first. Checked before the ordering rail
// because the two overlap on exactly the case that matters: a version equal to the base
// is not "newer" than it, so that rail would name the same version twice — "0.0.1-alpha1
// is not newer than the latest release (0.0.1-alpha1)" — and it is a re-run of a release
// that got as far as tagging, where what is wanted is the tag named and the next free
// number in its lane.
if (git($root, ['rev-parse', '-q', '--verify', 'refs/tags/' . $tag])['exit'] === 0) {
    $free = nextPrerelease($root, $version);

    fail(
        "Tag {$tag} already exists — a published tag is never moved or reused."
        . ($free === null ? '' : PHP_EOL . PHP_EOL . "The next free number in that lane is {$free}.")
    );
}

if (version_compare($version, $base, '<=')) {
    fail("{$version} is not newer than the latest release ({$base}).");
}

// ─────────────────────────────────────────────────────────────────────────────
// The inventory — a record that has lost a file is repaired, not skipped
// ─────────────────────────────────────────────────────────────────────────────

// The fourth signal is read as a whole or not at all, and every other signal here can be
// reported and skipped without changing what the release ships — the notes, the commits and
// the tag diff are code and prose in the tree. The inventory is the one that cannot: it is
// the only signal that carries the *file* a declaration came from, so it is the only thing
// that can witness a removal another file's declaration hides, and the only thing that can
// witness anything at all on a tree with no tag. A release that weighs three signals and
// calls the result a verdict is wrong in the one direction a version signal must never be
// wrong in.
//
// Only one of the three ways the record can be incomplete is refused, and `inventorySignal()`
// is where that is decided rather than here: an inventory that is *entirely* absent is a tree
// that has never written one — what every checkout is before the first release that writes
// them, and what a first release is — so there is nothing to repair and this release writes
// them. A stale stamp is repaired by the release that proceeds. A *partly* present record is
// a file that has demonstrably been published and has lost a piece, and it is repaired by one
// command that reproduces the tag's own rows.
//
// Placed after the two rails that decide whether this run can cut a tag at all — those are
// about the version, and this one is about the tree — and before the policy gate, because the
// gate reads the weighing this rail says is incomplete: a shadowed removal the inventory would
// have raised a minor over is a removal the gate would otherwise let past.
//
// A dry run notes rather than refuses, like every rail here: a plan publishes nothing.
if ($weighing['inventory']['blocks_release']) {
    if ($dryRun) {
        note(sprintf(
            'the inventory is incomplete — a real run refuses to release until it is repaired: %s. %s',
            $weighing['inventory']['summary'],
            $weighing['inventory']['evidence'][0] ?? '',
        ));
    } else {
        fail(sprintf(
            'The inventory is incomplete, so the weighing cannot read the one signal that witnesses'
            . ' what the tag diff cannot see — a declaration a second file shadows, and a config key'
            . ' or a constant removed on a tree with no tag to diff against. Releasing here would'
            . ' weigh three signals and call it a verdict.'
            . PHP_EOL . PHP_EOL
            . '  %s' . PHP_EOL
            . '  %s' . PHP_EOL . PHP_EOL
            . 'Repair it and release again. An inventory that is entirely absent is a tree that has'
            . ' never written one, and this release writes it, so that state is reported rather than'
            . ' refused — this one is a record that has lost a file.',
            $weighing['inventory']['summary'],
            $weighing['inventory']['evidence'][0] ?? '',
        ));
    }
}

$asked = $declared === null
    ? '--weigh'
    : ($options['version'] !== null ? "--version={$options['version']}" : '--' . $declared);

// The gate. A bump smaller than the policy's is what hides a breaking change, so
// it stops the release unless it was overridden out loud.
if ($declared !== null && severityRank($declared) < severityRank($required)) {
    if (!$options['ignore-policy']) {
        fail(sprintf(
            'These changes call for a %s release, but %s was declared.' . PHP_EOL . PHP_EOL
            . '%s' . PHP_EOL . PHP_EOL
            . 'Release with --weigh to let the policy pick, or step up to --%s.' . PHP_EOL
            . 'The evidence is the release notes, the commits and the public surface — if'
            . ' it read one of them wrong, --ignore-policy releases anyway and says so.',
            $required,
            $asked,
            weighingReason($weighing),
            $required,
        ));
    }

    note("--ignore-policy: releasing {$version}, below the {$required} the changes call for");
}

// ─────────────────────────────────────────────────────────────────────────────
// The public surface changed — the notes have to account for it
// ─────────────────────────────────────────────────────────────────────────────

// A release note is read by people and the public surface is read by nobody but the policy,
// so `### Fixed` ships a new public method without a line about it and every other rail is
// satisfied: the section is not empty, the bump is the loudest of the four signals, and the
// changelog says the release fixed a defect. This is the rail that tells "there are notes"
// apart from "the notes are about this", and it compares the two the only way they can be
// compared without reading prose for symbol names: the notes signal's own severity against
// the loudest severity the signals that read the surface reported. A `### Added` heading
// weighs a minor and covers a new method; a removal needs a `### Removed` entry, because
// that is what a removal is in the vocabulary the changelog and the policy share.
$surface = weighedAcross($weighing, surfaceSources());
$notes = weighedAcross($weighing, [notesSource()]);
$unaccounted = severityRank($surface) > severityRank($notes);

if ($unaccounted && !$options['allow-silent-notes'] && !$dryRun) {
    fail(sprintf(
        'The public surface changed, and the Unreleased notes do not account for it.'
        . PHP_EOL . PHP_EOL
        . '%s' . PHP_EOL . PHP_EOL
        . '  %s, so a reader of the CHANGELOG would find nothing about any of that.'
        . PHP_EOL
        . '  File each change under a heading that weighs at least as much — ### Removed for a'
        . ' removal,' . PHP_EOL
        . '  ### Added or ### Changed for an addition — or release with --allow-silent-notes'
        . ' and say so in the plan.',
        surfaceReason($weighing),
        $entries === 0
            ? 'The Unreleased section has no entries at all'
            : sprintf(
                'The notes weigh a %s (%d %s)',
                $notes,
                $entries,
                $entries === 1 ? 'entry' : 'entries',
            ),
    ));
}

// Reached on a dry run, and on an override. The first is a warning about a release that
// would be refused — a plan is where that belongs — and the second is the release saying what
// it let through, which is why every other escape here does the same.
if ($unaccounted) {
    note(sprintf(
        'The public surface changed (weighed %s) and the notes are quieter (weighed %s) — %s.',
        $surface,
        $notes,
        $options['allow-silent-notes']
            ? 'released anyway (--allow-silent-notes)'
            : 'a real run would refuse to tag on this',
    ));
}

// ─────────────────────────────────────────────────────────────────────────────
// CI — the commit being released is one CI has verified
// ─────────────────────────────────────────────────────────────────────────────

// Last of the preconditions on purpose, and the only one that leaves the machine: every
// refusal above this is cheaper, local, and about the tree rather than about GitHub, so a
// run that is not releasable anyway is told why without a network call first.
$ci = ciVerdict($root, $branch, $options['remote'], $options['skip-ci']);

if ($ci['state'] !== 'green' && $ci['state'] !== 'skipped') {
    if (!$dryRun) {
        fail($ci['refusal']);
    }

    // A dry run stops at nothing: the plan exists to show whether the release would go
    // through, and "push this first" is the answer this rail exists to give. The state is
    // in the plan's `ci` line, and this says what a real run would have done with it.
    note($ci['line'] . ' — a real run would refuse to tag on this.');
}

// ─────────────────────────────────────────────────────────────────────────────
// The promoted CHANGELOG
// ─────────────────────────────────────────────────────────────────────────────

$promoted = [
    'content' => promoteChangelog($changelog, $version, gmdate('Y-m-d'), $latestTag, $unreleased),
    'entries' => $entries,
];

// ─────────────────────────────────────────────────────────────────────────────
// composer.json branch-alias
// ─────────────────────────────────────────────────────────────────────────────

$composer = (string) file_get_contents($composerPath);
$alias = aliasFor($version);
$aliased = rewriteBranchAlias($composer, $alias);

// ─────────────────────────────────────────────────────────────────────────────
// The handoff note — the three tag rows this release is about to falsify
// ─────────────────────────────────────────────────────────────────────────────

// Read here, before the plan is printed and before anything is written, so a note this cannot
// move stops the release with the tree as it was rather than after the CHANGELOG has been
// promoted — the same reason the promoted notes are computed above rather than in the apply half.
$handoff = moveHandoffTagRows($root, $version);

// ─────────────────────────────────────────────────────────────────────────────
// Plan
// ─────────────────────────────────────────────────────────────────────────────

$previous = $latestTag ?? '(none)';
// A breaking change asks for a major, but only while there is a 1.0 line to
// break — the 0.x caveat in RELEASING.md softens it to a minor.
$softened = $weighing['severity'] === 'breaking' && $required === 'minor';

$bumpLine = $declared === null
    ? sprintf(
        '%s  (weighed: a %s change%s)',
        $required,
        $weighing['severity'],
        $softened ? ', which is a minor while the package is pre-1.0' : '',
    )
    : sprintf('%s  (declared as %s; the changes call for %s)', $declared, $asked, $required);

echo PHP_EOL . "Release plan" . PHP_EOL . str_repeat('─', 72) . PHP_EOL;
printf("  branch        %s%s%s", $branch, $dirty === '' ? '' : '  (dirty)', PHP_EOL);
printf("  ci            %s%s", $ci['line'], PHP_EOL);
printf("  latest tag    %s%s", $previous, PHP_EOL);
printf("  bump          %s%s", $bumpLine, PHP_EOL);
printf("  next version  %s  (tag %s)%s", $version, $tag, PHP_EOL);

if (isPrerelease($version)) {
    printf(
        "  prerelease    %s — Composer stability \"%s\", so a consumer has to opt in%s",
        prereleaseLane($version),
        stabilityOf($version),
        PHP_EOL,
    );
}

printf(
    "  CHANGELOG     %s%s",
    $weighsEmptyNotes
        ? 'nothing to promote — ## Unreleased is empty, so a real run refuses here; this plan weighs the changes in it instead'
        : sprintf('## Unreleased -> ## %s - %s, new Unreleased section above', $version, gmdate('Y-m-d')),
    PHP_EOL,
);

$inventoryLine = $weighing['inventory']['blocks_release']
    ? 'incomplete, and a real run refuses to release until it is repaired'
    : ($weighing['inventory']['stamp'] === '(missing)'
        ? 'written by this release'
        : ($weighing['inventory']['fresh']
            ? 'fresh, weighed against ' . $weighing['inventory']['stamp']
            : 'stale, refreshed by this release and not weighed'));

// One line per derived file, from the rows that name this command (bin/surface.php) — so an
// artefact whose row adds `release` appears in this plan, in the notes below and in the release
// commit without anything here being told about it, and an artefact this command does not write is
// one the plan does not claim and the commit does not carry. The lines are described from the
// tree's rows rather than from the record on disk: the record the plan is describing may be the
// stale or absent one the weighing just reported, and every derived file is written from this tree.
// Each line names the tag being created, because that is what stamps all of them: it is the tag,
// and not a count, that says which release a consumer is reading.
$derivedContext = ['root' => $root, 'tag' => $tag, 'records' => inventoryRecords($root)];
$derivedList = derivedBy('release', $root);

printf("  release notes %d bullet(s) in the promoted section%s", $promoted['entries'], PHP_EOL);

foreach ($derivedList as $derivedKey => $derivedArtefact) {
    printf(
        "  %-13s %s%s%s",
        $derivedArtefact['label'],
        $derivedArtefact['describe']($derivedContext),
        // The record is the one row whose state is the weighing's verdict rather than a staleness
        // check: it is what the bump was worked out from, so the plan says which release it was
        // weighed against, and whether it counted at all.
        $derivedKey === 'inventory' ? '  (' . $inventoryLine . ')' : '',
        PHP_EOL,
    );
}
printf(
    "  branch-alias  %s%s",
    $aliased['branches'] === []
        ? 'none for the dev lanes in composer.json — left alone'
        : implode(', ', $aliased['branches']) . ' -> ' . $alias
            . ($aliased['changed'] ? '  (updated)' : '  (unchanged)'),
    PHP_EOL,
);
printf(
    "  %-13s %s%s",
    'handoff note',
    match (true) {
        !$handoff['present'] => 'no HANDOFF.md in this package — none is written',
        $handoff['moved'] === [] => 'HANDOFF.md already names ' . $tag . ' — nothing to move',
        default => 'HANDOFF.md — ' . implode(', ', $handoff['moved']) . ' moved to ' . $tag,
    },
    PHP_EOL,
);
// The step between the commit and the tag: the note's own guard, read at the commit this release
// is about to freeze into a version. It is planned here rather than described in prose because a
// dry run is where a reader decides whether to let the release run at all, and a check that can
// only refuse after the commit is one they should see before it.
$guard = noteGuardCommand($root);

printf(
    "  %-13s %s%s",
    'note guard',
    match (true) {
        !is_file($root . '/HANDOFF.md') => 'no HANDOFF.md in this package — nothing to guard',
        $guard === null => 'cannot run here — no vendor/bin/phpunit and no HANDOFF_GUARD',
        default => "HandoffTest at the commit this tags — nothing is tagged if it is red",
    },
    PHP_EOL,
);
printf("  commit        %s%s", commitMessage($version), PHP_EOL);
printf("  tag           git tag -a %s -m \"%s\"%s", $tag, $tag, PHP_EOL);
printf("  push          git push %s %s --follow-tags%s", $options['remote'], $branch, PHP_EOL);

echo str_repeat('─', 72) . PHP_EOL . PHP_EOL;

echo ($declared === null ? 'Policy weighing (--weigh)' : 'Policy weighing (checked against the declared bump)')
    . PHP_EOL;

foreach ($weighing['signals'] as $signal) {
    printf("  %-9s %-14s %s%s", $signal['severity'], $signal['source'], $signal['summary'], PHP_EOL);

    foreach (array_slice($signal['evidence'], 0, 6) as $evidence) {
        echo '            • ' . $evidence . PHP_EOL;
    }

    if (count($signal['evidence']) > 6) {
        printf("            … and %d more%s", count($signal['evidence']) - 6, PHP_EOL);
    }
}

foreach ($weighing['notes'] as $weighingNote) {
    echo '  note: ' . $weighingNote . PHP_EOL;
}

// The surface's own caveat, kept out of the notes list so the reader that asks about one
// name can narrow it instead of repeating the whole sentence: what the surface holds is one
// entry per name, so a name two files declare is in it from the first of them, and a change
// to the other file's declaration is a change this signal cannot see.
if ($weighing['shadow'] !== null) {
    echo '  note: ' . $weighing['shadow'] . PHP_EOL;
}

if ($declared !== null && severityRank($declared) > severityRank($required)) {
    printf(
        '  note: %s is above what the changes call for (%s) — allowed, and it moves the line on.%s',
        $asked,
        $required,
        PHP_EOL,
    );
}

// Said out loud because the plan shows the dev tag as "latest tag" and the rung against a
// version that is not it: a reader comparing the two would otherwise have to know that a
// prerelease announces a rung instead of holding one.
if ($promotion !== null) {
    printf(
        '  note: the rung is measured from the last release%s, not from the dev tag v%s this branch is on — a prerelease announces the rung it will take.%s',
        $rungBase === '0.0.0' ? ' (none yet, so this is a first release)' : ' v' . $rungBase,
        $base,
        PHP_EOL,
    );
}

echo PHP_EOL;

echo 'CHANGELOG head after promotion:' . PHP_EOL . PHP_EOL;

foreach (array_slice(explode("\n", $promoted['content']), 0, 7) as $line) {
    echo '  ' . $line . PHP_EOL;
}

echo PHP_EOL;

if ($dryRun) {
    echo 'Dry run: nothing was written, committed or tagged.' . PHP_EOL;

    exit(0);
}

if (!$options['yes'] && !confirm("Commit and tag {$tag}?")) {
    echo 'Aborted.' . PHP_EOL;

    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Apply
// ─────────────────────────────────────────────────────────────────────────────

if (!write($changelogPath, $promoted['content'])) {
    fail('Could not write CHANGELOG.md');
}

// Every derived file goes into the release commit next to the CHANGELOG, written from the one list
// that names them and stamped with the tag being created: that stamp is what lets the next release
// weigh against the record, and the page is written after the record it renders — the list's order
// is that dependency rather than a coincidence of the order these steps used to be in. A refusal
// here leaves the tree with a promoted CHANGELOG, no tag, and the artefacts the list had reached,
// which is the same shape as every other step that fails — no version is left behind.
try {
    $derived = syncDerived($root, $tag, true, null, 'release');
} catch (RuntimeException $error) {
    fail('Could not render the derived files: ' . $error->getMessage() . '.');
}

// A renderer that refuses — the page cannot read the record it renders — is a release that cannot
// publish that file, so it stops here rather than committing a record whose page is the one before
// it. The message names the file and the renderer's own sentence, which is the repair as well.
if ($derived['refused'] !== []) {
    foreach ($derived['refused'] as $derivedKey => $refusal) {
        fail(sprintf('Could not render %s: %s.', implode(' / ', array_map(
            static fn (string $path): string => basename($path),
            array_values($derivedList[$derivedKey]['files']),
        )), $refusal));
    }
}

if (!$derived['written']) {
    fail(sprintf('Could not write %s.', implode(' / ', array_map(
        static fn (string $path): string => basename($path),
        $derived['failed'],
    ))));
}

foreach ($derived['wrote'] as $derivedKey) {
    note(sprintf($derivedList[$derivedKey]['note'], $derived['rows'][$derivedKey]));
}

if ($aliased['changed'] && !write($composerPath, $aliased['content'])) {
    fail('Could not write composer.json');
}

if ($aliased['changed']) {
    note(sprintf(
        'branch-alias updated to %s (%s)',
        $alias,
        implode(', ', $aliased['branches']),
    ));
} elseif (!$aliased['found']) {
    note('no extra.branch-alias for the dev lanes in composer.json — leaving it alone');
}

// The note, when a row of it moved. Written with the files above it and staged with them, so the
// tag points at a note that names it — the whole reason the rows are moved at all.
if ($handoff['moved'] !== [] && !write($handoff['path'], $handoff['content'])) {
    fail('Could not write HANDOFF.md');
}

if ($handoff['moved'] !== []) {
    note('handoff note moved to ' . $tag . ' — ' . implode(', ', $handoff['moved']));
}

// The blocks the rows sit among, written from their own derivations before anything is committed:
// the records this release just restamped are quoted in one of them, so a note committed without
// this is a note the guard it is about to meet would refuse. A refusal here is the renderer's own
// sentence, and it stops the release before the commit rather than after it.
$renderedNote = renderHandoffNote($root);

if ($renderedNote['ran'] && $renderedNote['exit'] !== 0) {
    fail(sprintf(
        "The handoff note's blocks could not be rendered, so nothing was committed or tagged:%s%s",
        PHP_EOL . PHP_EOL . trim($renderedNote['output'] . $renderedNote['error']),
        PHP_EOL,
    ));
}

if ($renderedNote['changed']) {
    note('handoff note — the rendered blocks moved with the records this release rewrote');
}

$staged = ['CHANGELOG.md'];

if ($handoff['moved'] !== [] || $renderedNote['changed']) {
    $staged[] = 'HANDOFF.md';
}

// Every file the list wrote, and no file it did not: the commit cannot carry an artefact the run did
// not produce, and cannot leave one behind.
foreach ($derived['files'] as $derivedPath) {
    $staged[] = relativeTo($root, $derivedPath);
}

if ($aliased['changed']) {
    $staged[] = 'composer.json';
}

$add = git($root, ['add', '--', ...$staged]);

if ($add['exit'] !== 0) {
    fail('git add failed: ' . trim($add['output']));
}

$commit = git($root, ['commit', '-m', commitMessage($version), '--', ...$staged]);

if ($commit['exit'] !== 0) {
    fail('git commit failed: ' . trim($commit['output']));
}

// The commit exists and its tag does not: the one moment the note can be read at the tree a version
// would freeze. Read after the commit rather than before it because the commit is what the tag will
// point at — a guard run on the working tree would be checking a tree nobody is about to tag — and
// read before the tag because a red note behind a published tag is a version a consumer can already
// hold, which is the state `RELEASING.md` calls unfixable.
//
// The tag being absent is the one thing the guard cannot know for itself, so it is told: the note
// names this version in its three tag rows, and `HANDOFF_PENDING_TAG` is how a note is read against a
// tag that is about to exist. Nothing else is excused.
$made = trim(git($root, ['rev-parse', 'HEAD'])['output']);

if (is_file($root . '/HANDOFF.md')) {
    if ($guard === null) {
        fail(
            'This package has a handoff note and no guard to read it: there is no vendor/bin/phpunit here and '
            . 'HANDOFF_GUARD names none. A release does not tag a note it cannot verify — run composer install, '
            . 'or point HANDOFF_GUARD at the suite.'
        );
    }

    $verdict = runCommand($guard, $root, ['HANDOFF_PENDING_TAG' => $tag]);

    if ($verdict['exit'] !== 0) {
        $headNow = trim(git($root, ['rev-parse', 'HEAD'])['output']);
        $unmade = $headNow === $made && git($root, ['reset', '--soft', 'HEAD~1'])['exit'] === 0;

        fail(sprintf(
            "The handoff note's guard is red at the commit this release made, so nothing was tagged:%s%s%s%s",
            PHP_EOL . PHP_EOL . trim($verdict['output'] . $verdict['error']),
            PHP_EOL,
            $unmade
                ? PHP_EOL . 'The commit was unmade (git reset --soft HEAD~1) and its files are staged as the '
                    . 'release wrote them: repair what the guard names, then run the release again.'
                : PHP_EOL . 'The commit was left in place because HEAD has moved since it was made.',
            PHP_EOL,
        ));
    }

    note('handoff note guard — green at ' . shortSha($made));
}

$tagResult = git($root, ['tag', '-a', $tag, '-m', $tag]);

if ($tagResult['exit'] !== 0) {
    fail('git tag failed: ' . trim($tagResult['output']));
}

note("committed and tagged {$tag}");

if ($options['push']) {
    $push = git($root, ['push', $options['remote'], $branch, '--follow-tags']);

    if ($push['exit'] !== 0) {
        fail('git push failed: ' . trim($push['output']));
    }

    note("pushed {$branch} and {$tag} to {$options['remote']}");
} else {
    note(sprintf('next: git push %s %s --follow-tags', $options['remote'], $branch));
}

// The line the run ends on, printed after either exit — the one that leaves the push to the
// reader, and the one that has just pushed. It is one line and the same line on every run,
// because what it has to say does not depend on anything this script knows: a tag on GitHub
// and a version on Packagist are two different events, and a push that succeeded says nothing
// about whether a version is being served. Which of the two ways the gap gets closed applies
// is a fact about the outside world — whether the package was ever submitted — so the note
// names both and leaves the state to the reader. A note that assumed an earlier release had
// been published would be false exactly on the run where it was read, and one that assumed it
// never had would be false on every run after the first.
note(sprintf(
    'Packagist reads tags, not commits: if %s is not listed there yet, submit the package if it never was, or trigger a crawl — RELEASING.md, "Publishing".',
    $tag,
));

exit(0);

// ─────────────────────────────────────────────────────────────────────────────
// The notes rail
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The loudest severity the named signals reported, or `patch` when none of them found
 * anything — and when the weighing does not carry them at all, which is the state of the
 * commits and the two surface halves on a tree with no tag at all.
 *
 * The name is the question rather than a signal: the release notes are one source and the
 * public surface is three, so "how loud is the surface" is a maximum over a set, and spelling
 * the set once is what keeps the two sides of the rail comparable.
 *
 * @param array{signals: list<array{source: string, severity: string}>} $weighing
 * @param list<string>                                                 $sources
 */
function weighedAcross(array $weighing, array $sources): string
{
    $severity = 'patch';

    foreach ($weighing['signals'] as $signal) {
        if (in_array($signal['source'], $sources, true)
            && severityRank($signal['severity']) > severityRank($severity)) {
            $severity = $signal['severity'];
        }
    }

    return $severity;
}

/**
 * The surface changes a release is carrying, for the error a refused release prints: the
 * signals that read the public surface and weighed more than a patch, which is the list a
 * reader has to file under `## Unreleased`.
 *
 * The threshold is what leaves the wrong lines out. A `patch` from one of these signals is
 * not a change to a symbol: it is the inventory saying its file is stale or missing, or a
 * file that moved with its symbol intact, and neither belongs in a list of things to write a
 * note for. Every line that survives the threshold is a symbol that was added, removed or
 * reshaped, because that is the only thing a surface diff produces above a patch.
 *
 * @param array{signals: list<array{source: string, severity: string, evidence: list<string>}>} $weighing
 */
function surfaceReason(array $weighing): string
{
    $lines = [];

    foreach ($weighing['signals'] as $signal) {
        if (!in_array($signal['source'], surfaceSources(), true)
            || severityRank($signal['severity']) === severityRank('patch')) {
            continue;
        }

        $lines[] = sprintf('  %s (%s):', $signal['source'], $signal['severity']);

        foreach (array_slice($signal['evidence'], 0, 8) as $evidence) {
            $lines[] = '    • ' . $evidence;
        }
    }

    return implode(PHP_EOL, $lines);
}

// ─────────────────────────────────────────────────────────────────────────────
// Version maths
// ─────────────────────────────────────────────────────────────────────────────

function bump(string $base, string $kind): string
{
    [$major, $minor, $patch] = parseVersion($base);

    return match ($kind) {
        'major' => sprintf('%d.0.0', $major + 1),
        'minor' => sprintf('%d.%d.0', $major, $minor + 1),
        default => sprintf('%d.%d.%d', $major, $minor, $patch + 1),
    };
}

/**
 * The branch alias for the line being developed: X.Y.x-dev for release X.Y.Z,
 * which is only rewritten when a release opens a new minor or major. Patch
 * releases stay on the same line, so the alias is left alone.
 */
function aliasFor(string $version): string
{
    [$major, $minor] = parseVersion($version);

    return sprintf('%d.%d.x-dev', $major, $minor);
}

/**
 * Which rung a version moved to — the bump somebody declared with
 * `--version=X.Y.Z`, so it can be held to the same gate the flags are.
 */
function kindBetween(string $base, string $version): string
{
    [$baseMajor, $baseMinor] = parseVersion($base);
    [$major, $minor] = parseVersion($version);

    if ($major !== $baseMajor) {
        return 'major';
    }

    return $minor !== $baseMinor ? 'minor' : 'patch';
}

/**
 * The newest release tag at or below the line a version is on — the version the rung of
 * the next one is measured from, and where `--weigh` starts counting when the promotion
 * it would otherwise offer is a smaller step than the changes ask for.
 *
 * "At or below the line" rather than "the newest release anywhere", because those are
 * not the same version and only one of them is the right base: `git tag --merged HEAD`
 * reaches every release on every merged line, so a maintenance branch cut from 1.0.0 can
 * see a 2.0.0 that another line published. The rung of the next 1.5.x is still measured
 * from 1.0.0 — measuring it from 2.0.0 would hold a patch release on an old line to a
 * major it has nothing to do with.
 *
 * Ancestry is the same question `latestTag()` asks, and it is asked the same way: a tag
 * on a branch HEAD cannot reach is a real tag and not one of this line's.
 */
function precedingRelease(string $root, string $line): string
{
    $listed = git($root, ['tag', '--merged', 'HEAD', '--list', 'v[0-9]*', '--sort=-v:refname']);

    if ($listed['exit'] !== 0) {
        return '0.0.0';
    }

    // Highest first, so the first one that is a release at or below the line is the
    // newest of them rather than merely the first one found.
    foreach (lines($listed['output']) as $tag) {
        $version = ltrim($tag, 'vV');

        if (!isPrerelease($version) && version_compare($version, $line, '<=')) {
            return $version;
        }
    }

    // No release yet: the whole package is new, which is the one bump the weighing cannot
    // make and the reason a first release is declared rather than weighed.
    return '0.0.0';
}

/**
 * The prerelease lane a version is in: `alpha` for `0.0.1-alpha1`, `dev` for
 * `0.0.1-dev`, and the empty string for a release.
 *
 * This is the one place that knows which suffixes exist, so the validator, the branch
 * rule and the plan all read it rather than each spelling the list out. The lanes are
 * the ones Composer reads for the shapes this package writes. Composer also accepts
 * `a`, `b` and `pl`, and the same numbers separated by a dot or by nothing at all, and
 * none of those is written here — narrower than Composer is safe. Here it is also
 * *right*: Composer reads `-alpha.1` and `-alpha1` as one version, so being wider would
 * let two tags claim it.
 */
function prereleaseLane(string $version): string
{
    // The number is optional and undotted — `-alpha`, `-alpha1` — and `dev` is matched by
    // itself because it takes no number at all: `0.0.1-dev.1` is a spelling Composer
    // rejects, and a pattern that let the optional number apply to every lane would read
    // that one back in. Anchoring on the undotted form is deliberate for the mirror-image
    // reason: `-alpha.1` never gets past this function, so it is never written as a tag.
    if (preg_match('/^\d+\.\d+\.\d+-(alpha|beta|rc)(\d*)$/i', $version, $match) === 1) {
        return strtolower($match[1]);
    }

    return preg_match('/^\d+\.\d+\.\d+-dev$/i', $version) === 1 ? 'dev' : '';
}

/**
 * The digits a numbered lane carries — `1` for `0.0.1-alpha1` — and the empty string for
 * a lane with no number on it. Only meaningful once `prereleaseLane()` has said the
 * version is a prerelease of some kind.
 */
function prereleaseNumber(string $version): string
{
    return preg_match('/(\d+)$/', $version, $match) === 1 ? $match[1] : '';
}

/**
 * The one spelling of this version that the script writes.
 *
 * Computed rather than trusted, because Composer treats several spellings as a single
 * version: `-RC1` and `-rc1` are both `0.0.1.0-RC1`, so two tags carrying them would be
 * one version published twice. The lane goes to lower case and the number is left exactly
 * as it was — `-alpha10` stays ten, not a one followed by a zero. A release has nothing
 * to canonicalise and is returned untouched.
 */
function canonicalVersion(string $version): string
{
    $lane = prereleaseLane($version);

    if ($lane === '') {
        return $version;
    }

    $base = substr($version, 0, (int) strpos($version, '-'));

    return $lane === 'dev'
        ? $base . '-dev'
        : $base . '-' . $lane . prereleaseNumber($version);
}

/**
 * The stability Composer assigns a version — the word a consumer has to allow in
 * `minimum-stability` before the tag can be installed at all.
 */
function stabilityOf(string $version): string
{
    $lane = prereleaseLane($version);

    return match ($lane) {
        '' => 'stable',
        'rc' => 'RC',
        default => $lane,
    };
}

/**
 * Is this a version the release script may write as a tag?
 *
 * `X.Y.Z` for a release, or `X.Y.Z-<lane>` for a prerelease, where the lane is one
 * `prereleaseLane()` knows. Never wider than Composer's grammar, because being wider
 * means writing a tag nobody can install.
 */
function isReleasableVersion(string $version): bool
{
    return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 || prereleaseLane($version) !== '';
}

/** A prerelease is not a release; it precedes the version it is named after. */
function isPrerelease(string $version): bool
{
    return prereleaseLane($version) !== '';
}

/**
 * The release a prerelease announces: `0.5.0-alpha1` -> `0.5.0`, and a version with no
 * suffix unchanged.
 *
 * The suffix is the whole of what a dev tag has to say about the rung, and dropping it is
 * what makes the version readable as the one being developed for. A lane this package
 * does not write is left alone rather than guessed at, which cannot happen here — every
 * path that reaches this has been through the validator, and the suffix grammar is one
 * place — but a version that somehow arrived with an unknown suffix keeps it, so the
 * mistake is visible rather than quietly reshaped.
 */
function releasePart(string $version): string
{
    return isPrerelease($version)
        ? substr($version, 0, (int) strpos($version, '-'))
        : $version;
}

/**
 * The branch a version is cut from: a prerelease from the line being developed, a
 * release from the branch that holds released versions. `--branch` overrides it.
 */
function releaseBranchFor(string $version): string
{
    return prereleaseLane($version) === '' ? 'main' : 'dev';
}

/**
 * The next free number in a prerelease lane, for a refusal that would otherwise leave
 * the caller to guess.
 *
 * Counted from the tags rather than from the number that was typed: asked for
 * `0.0.1-alpha1` when `alpha1` and `alpha2` both exist, the free number is `alpha3`, and
 * a hint that merely added one to the input would send the next attempt straight into a
 * second refusal. A bare `-alpha` counts as zero, so it is followed by `-alpha1`, which
 * is how Composer orders them; a bare `-dev` takes no number at all, so its lane has
 * nothing to suggest.
 */
function nextPrerelease(string $root, string $version): ?string
{
    if (preg_match('/^(\d+\.\d+\.\d+)-(alpha|beta|rc)(\d*)$/i', $version, $match) !== 1) {
        return null;
    }

    $base = $match[1];
    $lane = strtolower($match[2]);
    $listed = git($root, ['tag', '--list', 'v' . $base . '-' . $lane . '*']);

    if ($listed['exit'] !== 0) {
        return null;
    }

    $highest = 0;

    foreach (explode("\n", $listed['output']) as $tag) {
        if (preg_match('/^v' . preg_quote($base . '-' . $lane, '/') . '(\d*)$/', trim($tag), $number) === 1) {
            $highest = max($highest, (int) $number[1]);
        }
    }

    return sprintf('%s-%s%d', $base, $lane, $highest + 1);
}

// ─────────────────────────────────────────────────────────────────────────────
// The handoff note — its rendered blocks, its tag rows, and the guard a release runs on it
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The note's five rendered blocks, written before the release commit — the half of a re-take a
 * command can do.
 *
 * WHY THE RELEASE RENDERS THE NOTE AT ALL
 * ---------------------------------------
 *   A release falsifies more of the note than its three tag rows. `syncDerived()` rewrites the three
 *   `.tsv` records with the stamp of the tag being cut, and section 1's transcript quotes a
 *   `bin/inventory.php --check` that then exits 0 where the note says it exits 1 — so the release
 *   commit has always been red on that block too, and nothing noticed because no run ever read the
 *   note at the commit a tag points at. `bin/handoff.php` is the one thing that can write those
 *   blocks: it derives them from git, the inventory check and the recorded gate run, and none of
 *   those is prose. The prose is left exactly as the note was written — which is what the tag rows
 *   already do, and why `HandoffNoteTest` asserts the shape rather than the bytes.
 *
 *   A tree with no reader (`tests/Support/Handoff.php`) has nothing to render against, and a
 *   fixture is exactly that: a package with a note and no suite. There the note is left as the rows
 *   were moved, which is the state the fixture is about.
 *
 * @return array{ran: bool, changed: bool, exit: int, output: string, error: string}
 */
function renderHandoffNote(string $root): array
{
    $script = $root . '/bin/handoff.php';

    if (!is_file($root . '/HANDOFF.md') || !is_file($script) || !is_file($root . '/tests/Support/Handoff.php')) {
        return ['ran' => false, 'changed' => false, 'exit' => 0, 'output' => '', 'error' => ''];
    }

    // `--recorded`, not a plain run: a plain run takes the note, and the register it would record is
    // the commit this release is standing on — a release does not re-take, it refreshes the blocks its
    // own rewrites moved, against the register the note already names.
    $before = (string) @file_get_contents($root . '/HANDOFF.md');
    $run = runCommand([PHP_BINARY, $script, '--recorded'], $root);
    $after = (string) @file_get_contents($root . '/HANDOFF.md');

    return [
        'ran' => true,
        'changed' => $run['exit'] === 0 && $before !== $after,
        'exit' => $run['exit'],
        'output' => $run['output'],
        'error' => $run['error'],
    ];
}

/**
 * The command that reads the note at the commit a release makes, or null where this tree has none.
 *
 * The guard is the package's own suite (`HandoffTest`), run from the package root against the tree
 * the release is about to tag: a second reading of the note written here would be a check that
 * agrees with this file rather than with CI, which is the failure the guard exists to prevent.
 *
 * `HANDOFF_GUARD` is the seam a fixture needs — a package with a note and no installed suite is a
 * state `ReleaseRepo` produces on purpose, and a release test that installed composer there would be
 * a release test about composer. A real checkout takes the vendor path, and the pending tag is handed
 * to whichever one runs.
 *
 * @return list<string>|null
 */
function noteGuardCommand(string $root): ?array
{
    $configured = getenv('HANDOFF_GUARD');

    if ($configured !== false && trim($configured) !== '') {
        return [PHP_BINARY, trim($configured)];
    }

    if (is_file($root . '/vendor/bin/phpunit')) {
        return [PHP_BINARY, $root . '/vendor/bin/phpunit', '--filter', 'HandoffTest'];
    }

    return null;
}

/**
 * The three tag rows of HANDOFF.md's section 3, moved to the version being cut — or the note
 * as it stands, in a package that keeps none.
 *
 * WHY THE RELEASE WRITES THIS FILE
 * --------------------------------
 *   The note's section 3 table is a claim about *now*, and a tag falsifies three rows of it in
 *   one act: the row listing the tags on the branch does not hold the new one, the row naming
 *   the latest of them names the tag before it, and the row naming the suffixless version that
 *   is *not* there may name the version this release has just published. So every note written
 *   before a release is wrong in those three rows the moment it is cut — which is what
 *   `tests/Unit/Docs/HandoffTest.php` fails on, and because the push that publishes the tag comes
 *   after the commit it made, the failure lands on CI rather than on the machine that cut it.
 *
 *   What this cannot do is re-take the note: everything else in it is a snapshot of a working
 *   tree taken by hand, and a script has no way to know what is pending, which commits a push
 *   carries or what a thread meant. So it moves the three rows it has just made stale and touches
 *   nothing else, and section 3 of the note and README.md both say so for a reader.
 *
 *   The three values are the ones section 3's own rules give:
 *
 *     Tags on the branch        the row as it stands, plus the tag being cut
 *     The latest of them        the tag being cut
 *     A version with no suffix  the smallest version with no suffix that is newer than it — the
 *                               release a prerelease announces, or the next patch above a
 *                               release — which is the version the note already carries at a
 *                               register whose newest tag is a prerelease: `v0.2.0` beside
 *                               `v0.2.0-alpha3`
 *
 *   The sentence around a version is not rewritten — only the version in it is moved — so what a
 *   row says stays what the note said, and a note that has reworded one keeps its wording. A row
 *   with nothing in it to move, or a label that is not there at all, is a refusal rather than a
 *   release: a note left describing the tag before this one is a commit CI reports as stale, and
 *   a refusal a reader can act on beats a red build they did not cause.
 *
 * @return array{path: string, present: bool, content: string, moved: list<string>, absent: string}
 */
function moveHandoffTagRows(string $root, string $version): array
{
    $path = $root . '/HANDOFF.md';
    $tag = 'v' . $version;

    // The smallest version with no suffix that is newer than the tag being cut. A prerelease
    // announces the release it will take, so that release is the answer; a release is already
    // the smallest suffixless version at its version, so the next patch above it is.
    $absent = isPrerelease($version) ? releasePart($version) : bump($version, 'patch');

    $note = [
        'path' => $path,
        'present' => is_file($path),
        'content' => '',
        'moved' => [],
        'absent' => $absent,
    ];

    if (!$note['present']) {
        return $note;
    }

    $content = @file_get_contents($path);

    if ($content === false) {
        fail(
            'HANDOFF.md is there and could not be read, so the tag rows this release moves cannot be'
            . ' moved — and a tag cut on a note that still names the last one is a commit CI reports'
            . ' as stale.',
        );
    }

    // Read by the plain label, named as the note writes it: the two are one list, so a refusal
    // can say which row is missing in the words section 3 uses for it.
    $wanted = [
        'tags on the branch' => 'Tags on the branch',
        'the latest of them' => 'The latest of them',
        'a version with no suffix' => 'A version with no suffix',
    ];
    $lines = explode("\n", $content);
    $found = [];

    foreach ($lines as $position => $line) {
        $label = noteRowLabel($line);

        if ($label === null || !isset($wanted[$label])) {
            continue;
        }

        if (isset($found[$label])) {
            fail(sprintf(
                'HANDOFF.md carries two [%s] rows, so which of them the note means cannot be read:'
                . ' the table is read by its labels, and this release moves the rows that guard reads.',
                $wanted[$label],
            ));
        }

        $found[$label] = $position;
    }

    $missing = array_values(array_diff_key($wanted, $found));

    if ($missing !== []) {
        fail(sprintf(
            'HANDOFF.md carries no [%s] row, so this release cannot move the note past %s — a note'
            . ' left naming the tag before this one is a commit CI reports as stale. Write the row as'
            . ' section 3 of the note writes it, or take HANDOFF.md out of the tree.',
            implode('] or [', $missing),
            $tag,
        ));
    }

    foreach ($found as $label => $position) {
        $cells = noteRowCells($lines[$position]);

        if (count($cells) !== 2) {
            fail(sprintf(
                'HANDOFF.md line %d is the note\'s [%s] row and is not a two-column row, so there is no'
                . ' value cell in it to move to %s. The tag table is a label and a value, the way'
                . ' section 3 writes it.',
                $position + 1,
                $cells === [] ? $label : $cells[0],
                $tag,
            ));
        }

        [$written, $value] = [$cells[0], $cells[1]];

        if ($label === 'tags on the branch') {
            // Appended rather than rebuilt from `git tag`: the row is the tags the note says are
            // on the branch, which is a list a person keeps. This release knows one tag to add,
            // and a list rewritten from the tags on this machine would be a different claim.
            $value = str_contains($value, '`' . $tag . '`') ? $value : $value . ', `' . $tag . '`';
        } else {
            // Only the version moves: the note's sentence around it is left as it was written. The
            // `v` is the note's — a version is written with one in every row of this table — so the
            // absent version is written the way the row already carries it.
            $target = $label === 'the latest of them' ? $tag : 'v' . $absent;
            $moved = preg_replace('/`v[0-9][0-9A-Za-z.\-]*`/', '`' . $target . '`', $value, 1, $count);

            if ($count === 0) {
                fail(sprintf(
                    'HANDOFF.md line %d is the note\'s [%s] row and names no version, so there is'
                    . ' nothing in it to move to %s. The row is read by the first `vX.Y.Z` span it'
                    . ' writes.',
                    $position + 1,
                    $written,
                    $target,
                ));
            }

            $value = (string) $moved;
        }

        // A row that already says this is a row nobody wrote: a re-run over a note that was moved
        // and not committed writes nothing rather than a second copy of the tag.
        if ($value === $cells[1]) {
            continue;
        }

        $lines[$position] = '| ' . $written . ' | ' . $value . ' |';
        $note['moved'][] = $written;
    }

    if ($note['moved'] !== []) {
        $note['content'] = implode("\n", $lines);
    }

    return $note;
}

/**
 * One markdown row's cells, trimmed — the line as the note writes it, backticks included.
 *
 * @return list<string>
 */
function noteRowCells(string $line): array
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
 * A row's label — its first cell, plain — or null for a line that is not a table row.
 *
 * Plain is the comparison the guard that reads these rows makes: the words are the label's
 * identity, and backticks, capitalisation and spacing are prose. `Handoff::claim()` in
 * `tests/Support/Handoff.php` is the other half of the rule, and the two have to agree — a label
 * read one way here and another way there is either a release that moves a row the guard is not
 * reading, or one refused over a row the guard would have found.
 */
function noteRowLabel(string $line): ?string
{
    if (!str_starts_with(trim($line), '|')) {
        return null;
    }

    $cells = noteRowCells($line);

    return $cells === []
        ? null
        : strtolower(trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $cells[0]))));
}

// ─────────────────────────────────────────────────────────────────────────────
// CHANGELOG promotion
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Replace "## Unreleased" with the released heading and put a fresh, empty
 * Unreleased section above it. Section style follows whatever the file already
 * uses, so a bracket-less changelog stays bracket-less.
 *
 * @param array{heading: string, offset: int, bracketed: bool, body: string, rest: string} $unreleased
 */
function promoteChangelog(string $content, string $version, string $date, ?string $previousTag, array $unreleased): string
{
    $unreleasedHeading = $unreleased['bracketed'] ? '## [Unreleased]' : '## Unreleased';
    $releasedHeading = $unreleased['bracketed']
        ? sprintf('## [%s] - %s', $version, $date)
        : sprintf('## %s - %s', $version, $date);

    $tail = substr($content, 0, $unreleased['offset']);

    // A fresh Unreleased section goes above the release, so the next release
    // has somewhere to accumulate notes.
    $promoted = $tail
        . $unreleasedHeading . "\n\n"
        . $releasedHeading . $unreleased['rest'];

    $promoted = addCompareLink($promoted, $version, $date, $previousTag);

    return ensureFinalNewline($promoted);
}

/**
 * Keep the link-reference block at the bottom in step, when the changelog has
 * one. A changelog without link references is left exactly as it is.
 */
function addCompareLink(string $content, string $version, string $date, ?string $previousTag): string
{
    if (preg_match('/^\[Unreleased\]:\s*(\S+)/m', $content, $match) !== 1) {
        return $content;
    }

    $url = rtrim($match[1], '/');
    $prefix = preg_replace('#/compare/.*$#', '', $url) ?? $url;

    $previous = $previousTag === null ? null : 'v' . ltrim($previousTag, 'vV');

    $newLink = $previous === null
        ? sprintf('[%s]: %s/releases/tag/v%s', $version, $prefix, $version)
        : sprintf('[%s]: %s/compare/%s...v%s', $version, $prefix, $previous, $version);

    $content = preg_replace(
        '/^\[Unreleased\]:\s*\S+/m',
        sprintf('[Unreleased]: %s/compare/v%s...HEAD', $prefix, $version),
        $content,
    ) ?? $content;

    return rtrim($content) . "\n" . $newLink . "\n";
}

function ensureFinalNewline(string $content): string
{
    return rtrim($content) . "\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// composer.json
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Point the branch aliases for the dev lanes at the given alias — both of them,
 * because both are installable names for the same line: `main` holds releases and
 * `dev` holds the prereleases cut ahead of them, so a consumer tracking either is
 * tracking the line being developed.
 *
 * Only those two keys are touched, and only their values: the rest of composer.json
 * is left byte-for-byte alone, so a release cannot reflow someone else's formatting.
 * Another alias — a maintenance line's, say — names a *different* line, so rewriting
 * it would be wrong rather than thorough.
 *
 * @return array{content: string, changed: bool, found: bool, branches: list<string>}
 */
function rewriteBranchAlias(string $composer, string $alias): array
{
    $branches = [];
    $changed = false;

    $updated = preg_replace_callback(
        '/"(dev-main|dev-dev)"(\s*:\s*")([^"]*)(")/',
        static function (array $match) use ($alias, &$branches, &$changed): string {
            $branches[] = $match[1];

            if ($match[3] === $alias) {
                return $match[0];
            }

            $changed = true;

            return '"' . $match[1] . '"' . $match[2] . $alias . $match[4];
        },
        $composer,
    );

    if ($updated === null) {
        return ['content' => $composer, 'changed' => false, 'found' => false, 'branches' => []];
    }

    return [
        'content' => $updated,
        'changed' => $changed,
        'found' => $branches !== [],
        'branches' => $branches,
    ];
}

function commitMessage(string $version): string
{
    return "Release v{$version}";
}

// ─────────────────────────────────────────────────────────────────────────────
// CI — a tag is a version, so it is not cut on a commit nothing verified
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Whether the commit being released is one CI has verified.
 *
 * A tag is the version, so tagging a commit nothing has checked publishes a version no
 * build ever saw. The commit that has to be verified is therefore the one the release is
 * *built on* — HEAD, the tip of the branch — and not the release commit this script is
 * about to create. That one is bookkeeping (the CHANGELOG, the inventory, the branch
 * alias), and it cannot have a run yet: it is not on the remote until the push at the end,
 * and the workflow's `v*` tag trigger is what records it, after the fact.
 *
 * Two questions, asked in this order because they fail differently:
 *
 *   1. Is HEAD the tip of the remote branch? A commit that has not been pushed is one CI
 *      cannot have an opinion about, and answering "no run found" for it would send the
 *      reader to the wrong place entirely.
 *   2. Does the workflow have a run for it, and did that run pass? A run still in progress
 *      is not a pass — the rail must not answer before CI does — and a commit with one
 *      failed run beside a passing one is not verified either, because this rail is only
 *      allowed to be wrong in one direction.
 *
 * `--skip-ci` answers "not checked" without asking anything, which is the way out for a
 * machine with no `gh`, no token, or no network.
 *
 * @return array{state: string, line: string, refusal: string} `state` is `green` or
 *   `skipped` when the release may go ahead, and `refusal` is empty in those two cases and
 *   the sentence to print in the others.
 */
function ciVerdict(string $root, string $branch, string $remote, bool $skip): array
{
    if ($skip) {
        return ['state' => 'skipped', 'line' => 'not checked (--skip-ci)', 'refusal' => ''];
    }

    $head = trim(git($root, ['rev-parse', 'HEAD'])['output']);

    // `credential.interactive=false` turns "a private remote with no cached credential" from
    // a prompt nobody asked for into the refusal below. A credential helper still answers —
    // only the terminal is taken away — so this cannot lock anyone out of pushing later.
    $listing = git($root, ['-c', 'credential.interactive=false', 'ls-remote', $remote, 'refs/heads/' . $branch]);

    if ($listing['exit'] !== 0) {
        return ciRefusal('unreachable', sprintf('%s could not be read', $remote), [
            sprintf('Could not read %s, so whether CI has run on this commit is unknown:', $remote),
            '',
            '  ' . firstLine($listing['output']),
            '',
            'Check the remote name and the network — or pass --skip-ci to tag without a CI',
            'verdict.',
        ]);
    }

    $refs = lines($listing['output']);

    if ($refs === []) {
        return ciRefusal('unpushed', sprintf('%s has no %s branch', $remote, $branch), [
            sprintf('%s has no %s branch, so nothing there has ever been built.', $remote, $branch),
            '',
            sprintf('Push %s first and let the workflow finish: a tag is cut from a commit CI', $branch),
            'has verified — or pass --skip-ci, which says in the plan what it skipped.',
        ]);
    }

    $tip = explode("\t", $refs[0])[0];

    if ($tip !== $head) {
        return ciRefusal('unpushed', sprintf('HEAD is not the tip of %s/%s', $remote, $branch), [
            sprintf('HEAD (%s) is not the tip of %s/%s (%s).', shortSha($head), $remote, $branch, shortSha($tip)),
            '',
            sprintf('A release is cut from a commit the remote has and CI has built. Push %s and let', $branch),
            'the workflow finish first — or fetch, if the remote has moved ahead of you. To tag',
            'without a verdict, pass --skip-ci.',
        ]);
    }

    $asked = ciRuns($root, $head, $branch);

    if ($asked['exit'] !== 0) {
        return ciRefusal('unaskable', sprintf('CI could not be asked about %s', shortSha($head)), [
            sprintf('Could not ask CI about %s:', shortSha($head)),
            '',
            '  ' . $asked['error'],
            '',
            'Log in first (gh auth login), or pass --skip-ci to tag without a CI verdict.',
        ]);
    }

    $runs = $asked['runs'];

    if ($runs === []) {
        return ciRefusal('missing', sprintf('CI has no run for %s', shortSha($head)), [
            sprintf('CI has no run for %s on %s/%s, so nothing verified this commit.', shortSha($head), $remote, $branch),
            '',
            'Check that the workflow covers this branch and push a commit it will build — or',
            'pass --skip-ci.',
        ]);
    }

    $running = array_values(array_filter(
        $runs,
        static fn (array $run): bool => ($run['status'] ?? '') !== 'completed',
    ));

    if ($running !== []) {
        return ciRefusal('running', sprintf('CI is still running (%s)', runNames($running)), [
            sprintf('CI is still running for %s:', shortSha($head)),
            '',
            ...array_map(static fn (array $run): string => sprintf('  %s (%s)', $run['name'], $run['status'] ?? ''), $running),
            '',
            'Wait for it to finish — the rail will not answer before CI does — or pass --skip-ci.',
        ]);
    }

    $failed = array_values(array_filter(
        $runs,
        static fn (array $run): bool => ($run['conclusion'] ?? '') !== 'success',
    ));

    if ($failed !== []) {
        return ciRefusal('failed', sprintf('CI failed (%s)', runNames($failed)), [
            sprintf('CI failed for %s:', shortSha($head)),
            '',
            ...array_map(static fn (array $run): string => sprintf('  %s (%s)', $run['name'], $run['conclusion'] ?? 'unknown'), $failed),
            '',
            'A tag is a version, so it is not cut on a commit CI rejected. Fix it and release the',
            'commit that passes — or pass --skip-ci, which says in the plan what it skipped.',
        ]);
    }

    return [
        'state' => 'green',
        'line' => sprintf('%s/%s @ %s — CI green (%s)', $remote, $branch, shortSha($head), runNames($runs)),
        'refusal' => '',
    ];
}

/**
 * A refusal as the three parts of a verdict: the state it is in, the one-line summary the
 * plan prints, and the sentence that stops a real run. The body carries its own blank
 * lines, because a refusal here is a short report rather than a sentence.
 *
 * @param list<string> $body
 * @return array{state: string, line: string, refusal: string}
 */
function ciRefusal(string $state, string $line, array $body): array
{
    return ['state' => $state, 'line' => $line, 'refusal' => implode(PHP_EOL, $body)];
}

/**
 * The runs GitHub has for one commit, filtered to the ones that say something about the
 * branch being released: a run whose event is a push and whose head branch is this branch.
 *
 * The filter is not decoration. One commit carries several runs — this package's own
 * `v0.1.0-alpha1` commit has two, one for the branch push and one for the tag, and the tag
 * run reports its head branch as the *tag name* — and a run for another branch or another
 * event is not evidence about this one. A pull request's run does not vouch for the branch
 * tip either, even though it runs the same steps.
 *
 * `gh run list` is the ask because a GitHub Actions workflow is what reports a run, and its
 * per-commit JSON is the same data branch protection reads.
 *
 * @return array{exit: int, runs: list<array<string, string>>, error: string}
 */
function ciRuns(string $root, string $sha, string $branch): array
{
    $asked = runCommand(ciCommand($sha), $root);

    if ($asked['exit'] !== 0) {
        $report = $asked['error'] !== '' ? $asked['error'] : $asked['output'];

        return ['exit' => $asked['exit'], 'runs' => [], 'error' => firstLine($report)];
    }

    $decoded = json_decode(trim($asked['output']), true);

    if (!is_array($decoded)) {
        return [
            'exit' => 1,
            'runs' => [],
            'error' => 'the answer was not a list of runs: ' . firstLine($asked['output']),
        ];
    }

    $runs = [];

    foreach ($decoded as $run) {
        if (!is_array($run) || ($run['event'] ?? '') !== 'push' || ($run['headBranch'] ?? '') !== $branch) {
            continue;
        }

        $runs[] = array_map(
            static fn (mixed $value): string => is_string($value) ? $value : '',
            $run,
        );
    }

    return ['exit' => 0, 'runs' => $runs, 'error' => ''];
}

/**
 * The command that answers the CI question: `gh run list` for one commit.
 *
 * RELEASE_CI_COMMAND overrides it, for a machine whose gh lives somewhere else and for a
 * test that has to answer without a network. It is a command line, tokenised the way a
 * shell splits one — quote a path that contains a space — and `%SHA%` is where the commit
 * goes. The default needs the placeholder mid-line because `--commit` takes a value rather
 * than a trailing argument.
 *
 * @return list<string>
 */
function ciCommand(string $sha): array
{
    $template = getenv('RELEASE_CI_COMMAND');

    if (!is_string($template) || trim($template) === '') {
        $template = 'gh run list --commit %SHA% --limit 20 --json name,status,conclusion,event,headBranch';
    }

    return tokens(str_replace('%SHA%', $sha, $template));
}

/**
 * A command line as its words: on whitespace, with `"` and `'` grouping, so a path with a
 * space in it survives. The quotes are dropped — they are how the word is written, not
 * part of what the program receives.
 *
 * @return list<string>
 */
function tokens(string $line): array
{
    preg_match_all('/"[^"]*"|\'[^\']*\'|\S+/', $line, $matches);

    return array_map(
        static fn (string $token): string => trim($token, '"\''),
        $matches[0],
    );
}

/**
 * Run a command with its streams apart, because the answer to the CI question is JSON on
 * stdout and a warning on stderr would be sitting in front of it in a merged stream.
 *
 * @param list<string> $command
 * @return array{exit: int, output: string, error: string}
 */
function runCommand(array $command, string $root, array $env = []): array
{
    $process = @proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        // A complete environment, because `proc_open()` replaces rather than merges when it is given
        // one: the guard is told which tag is pending and still has to see PATH, HOME and whatever
        // else a child needs to start. Nothing is passed unless a caller asks for something.
        $env === [] ? null : array_merge(getenv(), $env),
    );

    if (!is_resource($process)) {
        return ['exit' => 127, 'output' => '', 'error' => $command === [] ? 'nothing to run' : $command[0] . ' could not be started'];
    }

    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'output' => $output, 'error' => $error];
}

/**
 * The first non-empty line of a tool's own words, for a message that quotes them rather than
 * describing them.
 */
function firstLine(string $text): string
{
    foreach (lines($text) as $line) {
        return $line;
    }

    return '(no output)';
}

/** A commit as a message spells it: short enough to read in a sentence. */
function shortSha(string $sha): string
{
    return substr($sha, 0, 7);
}

/**
 * The distinct workflow names behind a set of runs, so a refusal names what ran rather than
 * how many things did.
 *
 * @param list<array<string, string>> $runs
 */
function runNames(array $runs): string
{
    $names = [];

    foreach ($runs as $run) {
        $name = $run['name'] ?? '';

        $names[$name === '' ? 'unnamed' : $name] = true;
    }

    return $names === [] ? 'unnamed' : implode(', ', array_keys($names));
}

// ─────────────────────────────────────────────────────────────────────────────
// Plumbing
// ─────────────────────────────────────────────────────────────────────────────

function confirm(string $question): bool
{
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, 'Refusing to release from a non-interactive shell without --yes.' . PHP_EOL);

        return false;
    }

    fwrite(STDOUT, $question . ' [y/N] ');

    $answer = fgets(STDIN);

    return is_string($answer) && str_starts_with(strtolower(trim($answer)), 'y');
}

function write(string $path, string $content): bool
{
    return file_put_contents($path, $content) !== false;
}

function note(string $message): void
{
    echo '• ' . $message . PHP_EOL;
}

function fail(string $message): never
{
    fwrite(STDERR, PHP_EOL . '✗ ' . $message . PHP_EOL . PHP_EOL);

    exit(1);
}

/**
 * A usage error: the argument itself is wrong, so nothing was attempted and the exit
 * code says so — 2, distinct from the 1 a failed precondition returns. The message goes
 * to stderr, the usage against it to stdout, which is how every other refusal here is
 * split, and the usage is printed because a mistyped version is a moment to be reminded
 * of the shape rather than told to go and read a file.
 */
function usageError(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL . PHP_EOL);
    usage();

    exit(2);
}

function usage(): void
{
    echo <<<'TXT'
    bin/release.php — cut a release from the git tags.

    Usage:
      php bin/release.php <--weigh|--minor|--major|--version=X.Y.Z> [options]

    Bump:
      --weigh             Weigh the changes and take the bump they ask for, per
                          RELEASING.md: the Unreleased notes, the commits since
                          the last tag and the public surface of src/ and config/.
      --minor, --major    Declare the bump. Never smaller than --weigh's answer:
                          a refused bump exits 1 and shows what forbade it.
      --version=X.Y.Z     Release exactly this version, held to the same gate. A
                          prerelease suffix is allowed; see Prerelease below.

    Options:
          --dry-run        Show the plan and change nothing.
      -y, --yes            Do not ask for confirmation.
          --push           Push the branch with --follow-tags when done.
          --branch=NAME    Branch to release from (default: main, or dev when the
                          version is a prerelease).
          --remote=NAME    Remote to push to (default: origin).
          --allow-dirty    Allow uncommitted changes to tracked files.
          --skip-ci        Tag without asking CI: the commit being released has to
                           be the tip of the remote branch and its run has to have
                           passed. Set RELEASE_CI_COMMAND to ask a gh that lives
                           somewhere else, or to answer from a fixture.
          --ignore-policy  Release even though the bump undersells the changes.
          --allow-silent-notes
                           Release even though the public surface changed and the
                           Unreleased notes do not account for it; the plan says so.
      -h, --help           Show this help.

    Prerelease:
      A version may carry a suffix: -alpha1, -beta1 or -rc1, each with an optional
      number written without a dot, or a bare -dev. Those are the forms Composer
      can read — a tag it cannot read is one nobody can install — so 0.0.1-dev.1
      is refused rather than written. So is 0.0.1-alpha.1: Composer reads the dot
      as the same version as the undotted spelling, so two tags carrying the two
      spellings would be one version published twice, and the dotted one blocks
      the other once it exists. The suffix also decides the branch: a prerelease
      is cut from dev, a release from main. The CHANGELOG and the inventory are
      promoted, and the page the inventory renders is rewritten, exactly as for a
      release.

    CI:
      A tag is a version, so it is not cut on a commit nothing verified. The commit
      being released — HEAD, the tip of the branch — has to be on the remote, and the
      workflow's run for it (a push run on this branch) has to have finished and
      passed. The release commit itself is bookkeeping and cannot have a run of its
      own yet, so the workflow's v* tag trigger records that one after the push.
      --skip-ci tags anyway; the plan says what it skipped.

    On a repository with no tags the base version is 0.0.0, so a weighed minor
    gives 0.1.0 and a weighed patch gives 0.0.1.

    TXT;
}
