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
 *     inventory   files.tsv and methods.tsv as the last release wrote them,
 *                 against the tree now — read only when their stamp names the tag
 *                 being released from, so a stale one is reported, not trusted
 *
 *   The inventory is the one signal that does not need a tag: this script writes
 *   both files into the release commit itself, stamped with the tag it is creating,
 *   so the next release has a written-down copy of the last one to weigh against.
 *
 *   The name is the verb on purpose: it does not only work the bump out, it
 *   takes it, which is why it is not called --detect.
 *
 *   An explicit bump (--minor, --major, --version=X.Y.Z) is still allowed, but
 *   never smaller than what the policy asks for: declaring one that undersells
 *   the changes stops the release with exit code 1 and names the change that
 *   forbids it. `--patch` is gone — that is what --weigh computes.
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
 *
 * EXIT CODES
 * ----------
 *   0 released (or dry run planned), 1 a precondition failed, 2 usage error.
 */

// The symbol reader, the surface differ and the inventory format are shared with
// bin/inventory.php, so they live in bin/surface.php. This file keeps the command:
// the arguments, the preconditions, the weighing, the plan and the tag.
require __DIR__ . '/surface.php';

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
    'ignore-policy' => false,
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

$dirty = trim(git($root, ['status', '--porcelain', '--untracked-files=no', '--', '.'])['output']);

if ($dirty !== '' && !$options['allow-dirty'] && !$dryRun) {
    fail(
        'Tracked files have uncommitted changes — commit them first, so the tag points at'
        . ' exactly what was reviewed.' . PHP_EOL . PHP_EOL . $dirty,
    );
}

if ($dirty !== '' && $dryRun) {
    note(sprintf('Working tree is dirty (%d changed file(s)) — ignored for this dry run.', count(explode("\n", $dirty))));
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

if ($entries === 0) {
    fail(
        'The Unreleased section has no entries, so there is nothing to release. Write'
        . ' them under "## Unreleased" first; a version with no notes is one nobody can'
        . ' read, and the bump is weighed from them.',
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Work out the version — the changes decide the bump
// ─────────────────────────────────────────────────────────────────────────────

$latestTag = latestTag($root);
$base = $latestTag === null ? '0.0.0' : ltrim($latestTag, 'vV');

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
    $version = bump($base, $required);
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

    $declared = kindBetween($base, $version);
} else {
    $declared = (string) $options['kind'];
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
    "  CHANGELOG     ## Unreleased -> ## %s - %s, new Unreleased section above%s",
    $version,
    gmdate('Y-m-d'),
    PHP_EOL
);
$inventoryLine = $weighing['inventory']['stamp'] === '(missing)'
    ? 'written by this release'
    : ($weighing['inventory']['fresh']
        ? 'fresh, weighed against ' . $weighing['inventory']['stamp']
        : 'stale, refreshed by this release and not weighed');

printf("  release notes %d bullet(s) in the promoted section%s", $promoted['entries'], PHP_EOL);
printf(
    "  inventory     %d file(s), %d method(s)  (%s)%s",
    $weighing['inventory']['counts']['files'],
    $weighing['inventory']['counts']['methods'],
    $inventoryLine,
    PHP_EOL,
);
printf(
    "  branch-alias  %s%s",
    $aliased['branches'] === []
        ? 'none for the dev lanes in composer.json — left alone'
        : implode(', ', $aliased['branches']) . ' -> ' . $alias
            . ($aliased['changed'] ? '  (updated)' : '  (unchanged)'),
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

if ($declared !== null && severityRank($declared) > severityRank($required)) {
    printf(
        '  note: %s is above what the changes call for (%s) — allowed, and it moves the line on.%s',
        $asked,
        $required,
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

// The inventory goes into the release commit next to the CHANGELOG, stamped with
// the tag being created: that stamp is what lets the next release weigh against it.
$inventoryFiles = inventoryPaths($root);
$inventory = syncInventory($root, $tag, true);

if (!$inventory['written']) {
    fail(sprintf(
        'Could not write %s / %s.',
        relativeTo($root, $inventoryFiles['files']),
        relativeTo($root, $inventoryFiles['methods']),
    ));
}

note(sprintf(
    'inventory refreshed — %d file(s), %d method(s), described as %s',
    $inventory['count']['files'],
    $inventory['count']['methods'],
    $tag,
));

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

$staged = ['CHANGELOG.md'];

foreach ($inventoryFiles as $inventoryFile) {
    $staged[] = relativeTo($root, $inventoryFile);
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
    note(sprintf(
        'next: git push %s %s --follow-tags    (Packagist picks the tag up from there)',
        $options['remote'],
        $branch,
    ));
}

exit(0);

// ─────────────────────────────────────────────────────────────────────────────
// Version maths
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @return array{int, int, int}
 */
function parseVersion(string $version): array
{
    $parts = explode('.', $version);

    return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0)];
}

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
 * The bump a weighed severity asks for, with the 0.x caveat from RELEASING.md:
 * a breaking change is a minor while the package is pre-1.0, and a major once a
 * 1.0 line exists to break.
 */
function bumpFor(string $severity, string $base): string
{
    if ($severity !== 'breaking') {
        return $severity;
    }

    [$major] = parseVersion($base);

    return $major >= 1 ? 'major' : 'minor';
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
// Policy weighing — the bump the changes ask for
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Work out which bump the changes themselves call for, following the policy
 * table in RELEASING.md. Four signals are read — the release notes, the commits
 * since the last tag, the public surface at HEAD versus that tag, and the
 * inventory the last release wrote — and the loudest one wins. Each signal is
 * returned with the evidence behind it, so the plan shows its working rather than
 * asserting a version.
 *
 * @return array{severity: string, bump: string, signals: list<array{source: string, severity: string, summary: string, evidence: list<string>}>, notes: list<string>, inventory: array{source: string, severity: string, summary: string, evidence: list<string>, counts: array{files: int, methods: int}, stamp: string, fresh: bool}}
 */
function weigh(string $root, ?string $latestTag, string $unreleased, string $base): array
{
    $signals = [changelogSignal($unreleased)];
    $notes = [];

    if ($latestTag === null) {
        // With no tag there is nothing to diff against: the whole package is new,
        // so a comparison would report every symbol as added and call the first
        // release a minor no matter what it contains. The notes say what the
        // release actually is, which is why they alone decide it.
        $notes[] = 'no release tag yet, so there is no base for the commits or the public'
            . ' surface to be compared against: the notes decide, and the inventory can'
            . ' only witness what changed after it was written';
    } else {
        $commits = commitSignal($root, $latestTag);

        if ($commits === null) {
            $notes[] = "git log {$latestTag}..HEAD could not be read: the commit signal was skipped";
        } else {
            $signals[] = $commits;
        }

        $signals = array_merge($signals, surfaceSignals($root, $latestTag));
    }

    // Read either way: with no tag to diff against, a written-down inventory is the
    // only thing that can still witness a change made after it was written.
    $inventory = inventorySignal($root, $latestTag);
    $signals[] = $inventory;

    $severity = 'patch';

    foreach ($signals as $signal) {
        if (severityRank($signal['severity']) > severityRank($severity)) {
            $severity = $signal['severity'];
        }
    }

    return [
        'severity' => $severity,
        'bump' => bumpFor($severity, $base),
        'signals' => $signals,
        'notes' => $notes,
        'inventory' => $inventory,
    ];
}

/**
 * The evidence behind the loudest signal, for the error a refused bump prints:
 * whoever has to fix the version should not have to run the weighing by hand to
 * find out what it saw.
 */
function weighingReason(array $weighing): string
{
    $lines = [];

    foreach ($weighing['signals'] as $signal) {
        if (severityRank($signal['severity']) !== severityRank($weighing['severity'])) {
            continue;
        }

        $lines[] = sprintf('  %s (%s):', $signal['source'], $signal['severity']);

        foreach (array_slice($signal['evidence'], 0, 8) as $evidence) {
            $lines[] = '    • ' . $evidence;
        }
    }

    if ($lines === []) {
        $lines[] = '  (nothing in the changes reads as more than a patch)';
    }

    return implode(PHP_EOL, $lines);
}

/**
 * The commit types that are a patch by the policy: they fix, document, test or
 * tidy what is already there rather than adding anything a consumer can use.
 *
 * @return list<string>
 */
function commitTypes(): array
{
    return ['fix', 'docs', 'test', 'chore', 'refactor', 'perf', 'style', 'build', 'ci', 'revert'];
}

/**
 * The Unreleased notes, read as the declaration of what changed: their `###`
 * headings are the Keep a Changelog categories the policy table is written
 * against, so this signal is the author's own words rather than a guess about
 * them. It is also the only signal a repository with no tags can offer.
 *
 * An entry that is really a fix belongs under `### Fixed` — that is the lever on
 * this signal, not overriding the version afterwards.
 *
 * @return array{source: string, severity: string, summary: string, evidence: list<string>}
 */
function changelogSignal(string $unreleased): array
{
    $severityByHeading = [
        'removed' => 'breaking',
        'added' => 'minor',
        'changed' => 'minor',
        'deprecated' => 'minor',
        'fixed' => 'patch',
        'security' => 'patch',
    ];

    $severity = 'patch';
    $top = null;
    $evidence = [];
    $unknown = [];

    foreach (preg_split('/^(?=###[ \t])/m', $unreleased) ?: [] as $chunk) {
        if (preg_match('/^###[ \t]+(.+?)[ \t]*$/m', $chunk, $heading) !== 1) {
            continue;
        }

        $name = trim($heading[1]);
        $count = countBullets($chunk);
        $level = $severityByHeading[strtolower($name)] ?? null;

        if ($level === null) {
            $unknown[] = $name;

            continue;
        }

        if ($top === null || severityRank($level) > severityRank($top['level'])) {
            $top = ['name' => $name, 'count' => $count, 'level' => $level];
            $severity = $level;
        }

        $evidence[] = sprintf('### %s — %d %s', $name, $count, $count === 1 ? 'entry' : 'entries');
    }

    $summary = $top === null
        ? 'the Unreleased section has no `###` heading the policy knows'
        : sprintf('### %s — %d %s, the loudest heading the notes use', $top['name'], $top['count'], $top['count'] === 1 ? 'entry' : 'entries');

    // A `### Breaking changes` heading, or the uppercase marker a changelog can
    // use in its place, is the loudest thing the notes are able to say.
    if (preg_match('/^###[^\n]*\bbreaking\b/im', $unreleased) === 1 || str_contains($unreleased, 'BREAKING') === true) {
        $severity = 'breaking';
        $summary = 'the notes are marked breaking';
        $evidence[] = 'the Unreleased notes are marked breaking';
    }

    if ($unknown !== []) {
        $evidence[] = 'not a Keep a Changelog heading, so read as a patch: ### ' . implode(', ### ', $unknown);
    }

    return [
        'source' => 'CHANGELOG',
        'severity' => $severity,
        'summary' => $summary,
        'evidence' => $evidence,
    ];
}

/**
 * The commits since the last tag, read as Conventional Commits: `feat` is a new
 * capability, `fix`/`docs`/`test` and their neighbours are not, and a `!` or a
 * `BREAKING CHANGE:` footer says what the notes may have left unsaid. It is the
 * signal that catches work nobody wrote a changelog entry for.
 *
 * @return array{source: string, severity: string, summary: string, evidence: list<string>}|null null when git log cannot be read
 */
function commitSignal(string $root, string $latestTag): ?array
{
    $log = git($root, ['log', '--no-merges', '--format=%s%x1f%b%x1e', $latestTag . '..HEAD']);

    if ($log['exit'] !== 0) {
        return null;
    }

    $severity = 'patch';
    $counts = [];
    $breaking = [];
    $unclassified = [];
    $total = 0;

    foreach (explode("\x1e", $log['output']) as $record) {
        $record = trim($record, "\r\n");

        if ($record === '') {
            continue;
        }

        $parts = explode("\x1f", $record, 2);
        $subject = trim($parts[0]);
        $body = $parts[1] ?? '';

        // The release commits are bookkeeping, not changes — a prerelease's own
        // commit included, or every dev tag would weigh its predecessor's commit.
        if (preg_match('/^Release v\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $subject) === 1) {
            continue;
        }

        $total++;

        if (preg_match('/^([A-Za-z]+)(?:\([^)]*\))?(!)?:/', $subject, $match) === 1) {
            $type = strtolower($match[1]);
            $bang = ($match[2] ?? '') === '!';
        } else {
            $type = null;
            $bang = false;
        }

        if ($bang || preg_match('/^BREAKING[ -]CHANGE:/mi', $body) === 1) {
            $severity = 'breaking';
            $breaking[] = $subject;

            continue;
        }

        if ($type === 'feat') {
            $counts['feat'] = ($counts['feat'] ?? 0) + 1;
            $severity = 'minor';

            continue;
        }

        if ($type !== null && in_array($type, commitTypes(), true)) {
            $counts[$type] = ($counts[$type] ?? 0) + 1;

            continue;
        }

        $unclassified[] = $subject;
    }

    if ($total === 0) {
        return [
            'source' => 'commits',
            'severity' => 'patch',
            'summary' => "no commits since {$latestTag}",
            'evidence' => [],
        ];
    }

    return [
        'source' => 'commits',
        'severity' => $severity,
        'summary' => sprintf(
            '%d commit(s) since %s — %s',
            $total,
            $latestTag,
            $counts === [] ? 'none declaring a type' : implode(', ', array_map(
                static fn (string $type): string => $type . ' ' . $counts[$type],
                array_keys($counts),
            )),
        ),
        'evidence' => array_merge(
            array_map(static fn (string $subject): string => 'breaking: ' . $subject, $breaking),
            $unclassified === [] ? [] : [sprintf(
                '%d commit(s) declare no type, so they read as a patch: %s',
                count($unclassified),
                implode('; ', array_slice($unclassified, 0, 3)),
            )],
        ),
    ];
}

/**
 * The public surface at HEAD versus the last tag — the structural signal, and the
 * one that cannot be forgotten: a removed method is breaking however the notes
 * describe it. Two slices are read, because they are what a consumer names: the
 * classes under src/, and the keys and env vars under config/.
 *
 * @return list<array{source: string, severity: string, summary: string, evidence: list<string>}>
 */
function surfaceSignals(string $root, string $latestTag): array
{
    $signals = [];

    $before = taggedSurface($root, $latestTag, 'src', fileSurface(...));
    $after = workingSurface($root . '/src', fileSurface(...));

    if ($before !== [] || $after !== []) {
        $signals[] = surfaceSignal('public API', $before, $after);
    }

    $before = taggedSurface($root, $latestTag, 'config', configSurface(...));
    $after = workingSurface($root . '/config', configSurface(...));

    if ($before !== [] || $after !== []) {
        $signals[] = surfaceSignal('config', $before, $after);
    }

    return $signals;
}

/**
 * The inventory — files.tsv and methods.tsv — read as a fourth signal.
 *
 * The two files are written into the release commit by this script, stamped with
 * the tag it creates, so on the next release they describe exactly what was
 * shipped last time. They are read here only when that stamp names the tag being
 * released from. An inventory that was regenerated at some other moment — by hand,
 * or in a commit of its own — cannot tell "nothing changed" from "not refreshed",
 * and believing the first when the second is true is how a breaking change ships as
 * a patch. So a mismatch is reported and ignored, and the tag diff remains the
 * authority.
 *
 * This signal can only ever raise the bump. Nothing here lowers what the notes, the
 * commits or the surface say, which is what makes a second opinion affordable.
 *
 * @return array{source: string, severity: string, summary: string, evidence: list<string>, counts: array{files: int, methods: int}, stamp: string, fresh: bool}
 */
function inventorySignal(string $root, ?string $latestTag): array
{
    $paths = inventoryPaths($root);

    $stored = [
        'files' => readInventory($paths['files']),
        'methods' => readInventory($paths['methods']),
    ];

    $current = inventoryRecords($root);
    $counts = ['files' => count($current['files']), 'methods' => count($current['methods'])];
    $expected = $latestTag ?? '(no tag)';

    if ($stored['files'] === null && $stored['methods'] === null) {
        return [
            'source' => 'inventory',
            'severity' => 'patch',
            'summary' => sprintf('%d file(s), %d method(s) on disk, but no inventory to weigh against', $counts['files'], $counts['methods']),
            'evidence' => ['files.tsv and methods.tsv are missing: run php bin/inventory.php'],
            'counts' => $counts,
            'stamp' => '(missing)',
            'fresh' => false,
        ];
    }

    $stamp = $stored['files']['stamp'] ?? $stored['methods']['stamp'] ?? 'unknown';

    if ($stamp !== $expected) {
        return [
            'source' => 'inventory',
            'severity' => 'patch',
            'summary' => sprintf('stale — it describes %s, and this release is built on %s', $stamp, $expected),
            'evidence' => ['a stale inventory cannot tell "nothing changed" from "not refreshed", so it was not weighed; the tag diff still covers the surface'],
            'counts' => $counts,
            'stamp' => $stamp,
            'fresh' => false,
        ];
    }

    $diff = diffInventory($stored, $current);

    return [
        'source' => 'inventory',
        'severity' => $diff['severity'],
        'summary' => $diff['evidence'] === []
            ? sprintf('current — %d file(s), %d method(s), nothing removed, renamed or added since %s', $counts['files'], $counts['methods'], $stamp)
            : sprintf('%d change(s) since %s', count($diff['evidence']), $stamp),
        'evidence' => $diff['evidence'],
        'counts' => $counts,
        'stamp' => $stamp,
        'fresh' => true,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// CHANGELOG promotion
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The Unreleased section of a changelog: where its heading is, how it is spelled
 * and what it says. Weighing reads the notes; promotion moves them.
 *
 * @return array{heading: string, offset: int, bracketed: bool, body: string, rest: string}|null null when there is no Unreleased heading
 */
function unreleasedSection(string $content): ?array
{
    // Horizontal whitespace only in the pattern: a greedy \s* would swallow the
    // blank line after the heading and leave the promoted section unbreathable.
    if (preg_match('/^##[ \t]+(?:\[Unreleased\]|Unreleased)[ \t]*$/m', $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    $heading = $match[0][0];
    $offset = $match[0][1];
    $rest = substr($content, $offset + strlen($heading));

    // The notes stop at the next `##` heading: everything past it belongs to a
    // release that already happened, and counting its bullets is how an empty
    // Unreleased section used to look full.
    $body = $rest;

    if (preg_match('/^##[ \t]+\S/m', $rest, $next, PREG_OFFSET_CAPTURE) === 1) {
        $body = substr($rest, 0, $next[0][1]);
    }

    return [
        'heading' => $heading,
        'offset' => $offset,
        'bracketed' => str_contains($heading, '['),
        'body' => $body,
        'rest' => $rest,
    ];
}

/**
 * How many bullets a slice of changelog holds.
 */
function countBullets(string $content): int
{
    return (int) preg_match_all('/^\s*[-*]\s+\S/m', $content);
}

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
          --ignore-policy  Release even though the bump undersells the changes.
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
      promoted exactly as for a release.

    On a repository with no tags the base version is 0.0.0, so a weighed minor
    gives 0.1.0 and a weighed patch gives 0.0.1.

    TXT;
}
