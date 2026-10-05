<?php

declare(strict_types=1);

/**
 * bin/weighing.php — the shared half of bin/release.php and bin/blame.php.
 *
 * WHAT A WEIGHING IS
 * ------------------
 *   The bump a set of changes asks for, and the evidence behind it. Four signals are
 *   read — the release notes, the commits since the last tag, the public surface at HEAD
 *   versus that tag, and the inventory the last release wrote — and the loudest one wins.
 *   Nothing here decides a *version*: the caller picks one from the severity this
 *   returns, which is what keeps a single policy behind both `--weigh` and the answer to
 *   "which signal names this symbol, and what did it weigh?".
 *
 * WHAT LIVES HERE
 * ---------------
 *     versions   the parsing the bump needs
 *     signals    the four readers, each returning its severity and its evidence
 *     weighing   the maximum, the notes about what could not be read, and the reason
 *     changelog  the Unreleased section and its bullets, which the notes signal reads
 *                and the release's promotion moves
 *
 * NO SHEBANG, ON PURPOSE
 * ----------------------
 *   This file is only ever included, never run: PHP does not strip a shebang from an
 *   included file — it prints it — and bin/release.php writes a plan a human reads.
 */

require __DIR__ . '/surface.php';
/**
 * @return array{int, int, int}
 */
function parseVersion(string $version): array
{
    $parts = explode('.', $version);

    return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0)];
}

// ─────────────────────────────────────────────────────────────────────────────
// The bump a weighed severity asks for
// ─────────────────────────────────────────────────────────────────────────────
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
 * @return array{severity: string, bump: string, signals: list<array{source: string, severity: string, summary: string, evidence: list<string>}>, notes: list<string>, shadow: string|null, inventory: array{source: string, severity: string, summary: string, evidence: list<string>, counts: array{files: int, methods: int, surface: int}, stamp: string, fresh: bool, blocks_release: bool}}
 */
function weigh(string $root, ?string $latestTag, string $unreleased, string $base): array
{
    $signals = [changelogSignal($unreleased)];
    $notes = [];
    $declaredBy = [];

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

        $signals = array_merge($signals, surfaceSignals($root, $latestTag, $declaredBy));
    }

    // The surface is keyed by symbol name, so a name two files declare is in it once, from
    // the first file read — and the tag diff cannot see a change to the other one. Nothing
    // in the weighing can make that visible; saying it is what keeps the difference between
    // "nothing this signal can see moved" and "nothing moved" out of the plan's silence.
    //
    // Held apart from `notes` rather than pushed into them: the plan states it once for the
    // whole surface, and `bin/blame.php` — which prints these notes verbatim — states it
    // once for the one name it was asked about. One fact, said once per reader, in the
    // terms each of them is answering in.
    $shadow = surfaceDropped($declaredBy, $root);

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
        'shadow' => $shadow,
        'inventory' => $inventory,
    ];
}

/**
 * The names two files declare, as one plan note — or null when every name in the surface
 * came from one file, which is the normal state and says nothing.
 *
 * What it is for is the difference between a signal that is quiet because nothing moved and
 * one that is quiet because it cannot see the change: the surface keys a symbol by name, so
 * the second file's declaration is not in it, and a change to that file leaves both surfaces
 * holding the name. The note names the symbols and the files behind each one — the file that
 * won is as important as the ones that lost — and points at the artefact that does keep both:
 * the inventory's rows carry the file they came from, so `surface.tsv` writes both
 * declarations down, even though its verdict is keyed by name like this one.
 *
 * @param array<string, list<string>> $declaredBy one entry per symbol the surface merge saw
 */
function surfaceDropped(array $declaredBy, string $root): ?string
{
    $collisions = array_filter($declaredBy, static fn (array $paths): bool => count($paths) > 1);

    if ($collisions === []) {
        return null;
    }

    $shown = [];

    foreach (array_slice($collisions, 0, 3, true) as $symbol => $paths) {
        $shown[] = sprintf(
            '%s (%s)',
            $symbol,
            implode(' + ', array_map(static fn (string $path): string => relativeTo($root, $path), $paths)),
        );
    }

    if (count($collisions) > 3) {
        $shown[] = sprintf('… and %d more', count($collisions) - 3);
    }

    return sprintf(
        'the surface holds one entry per name, and %d name(s) are declared by more than one'
        . ' file, so only the first of each is in it: %s. A change to the other file\'s'
        . ' declaration is a change no name-keyed verdict can see — the name is still in both'
        . ' surfaces, from the file that did not change. The inventory writes both files down'
        . ' in its rows, which carry the file they came from, and its verdict is keyed by name'
        . ' for the same reason this one is: it is that file, and not a verdict, that keeps the'
        . ' two declarations apart',
        count($collisions),
        implode(', ', $shown),
    );
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
 * The signal the release notes are read through.
 *
 * Named here because it is asked about by name twice — the weighing puts it first so a tree
 * with no tag still has a signal, and the rail in bin/release.php that asks whether a surface
 * change was written down has to compare against it specifically. A source spelled in two
 * files is a source that can be renamed in one of them.
 */
function notesSource(): string
{
    return 'CHANGELOG';
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

    // An empty section and a section whose headings the policy does not know are one signal
    // whose evidence differs: the first is a release with nothing to publish, the second is
    // notes written in a vocabulary the policy cannot weigh. Naming them separately is what
    // lets a plan say which of the two it is looking at.
    $summary = $top === null
        ? (trim($unreleased) === ''
            ? 'the Unreleased section is empty — nothing for a release to publish'
            : 'the Unreleased section has no `###` heading the policy knows')
        : sprintf('### %s — %d %s, the loudest heading the notes use', $top['name'], $top['count'], $top['count'] === 1 ? 'entry' : 'entries');

    // A `### Breaking changes` heading, or the uppercase marker a changelog can
    // use in its place, is the loudest thing the notes are able to say — the
    // heading wherever a section carries it, and the marker only where a note
    // writes it as a claim, because a note that describes the marker writes the
    // word down without making the claim.
    if (preg_match('/^###[^\n]*\bbreaking\b/im', $unreleased) === 1 || breakingClaim($unreleased) === true) {
        $severity = 'breaking';
        $summary = 'the notes are marked breaking';
        $evidence[] = 'the Unreleased notes are marked breaking';
    }

    if ($unknown !== []) {
        $evidence[] = 'not a Keep a Changelog heading, so read as a patch: ### ' . implode(', ### ', $unknown);
    }

    return [
        'source' => notesSource(),
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
 * The halves the public surface is made of, and how each one is read: the classes
 * under `src/` as code, and the keys and env vars under `config/` as configuration.
 *
 * One list rather than a literal pair in each reader, so "what is the surface" is
 * answered once — the weighing diffs it half by half for its two signals, and the
 * blame command asks the same halves whether they hold one symbol at all.
 *
 * @return array<string, callable(string): array<string, string>>
 */
function surfaceParts(): array
{
    return [
        'src' => fileSurface(...),
        'config' => configSurface(...),
    ];
}

/**
 * The label each half of the surface reports under, keyed by the directory it is read from.
 *
 * The two halves are one thing to a reader — the public surface — and two signals to a
 * weighing, because a consumer breaks on a config key and a config key is not a class. This
 * is the only place the two names are written.
 *
 * @return array<string, string>
 */
function surfaceLabels(): array
{
    return ['src' => 'public API', 'config' => 'config'];
}

/**
 * The signal sources whose evidence is a statement about the public surface: the two halves
 * of the tag diff, and the inventory — the file rows a tag diff cannot see, a config key or a
 * constant removed since the last release.
 *
 * One list, in the file that owns the signals, so the three readers that have to decide what
 * counts as the surface — this weighing, the blame report and the release rail that asks
 * whether a surface change was written down — cannot come to different conclusions about it.
 *
 * @return list<string>
 */
function surfaceSources(): array
{
    return [...array_values(surfaceLabels()), inventorySource()];
}

/**
 * The inventory signal's source name, spelled once: the weighing reads it from three returns
 * and the surface rail has to know it is one of the surface's signals.
 */
function inventorySource(): string
{
    return 'inventory';
}

/**
 * The whole surface as the working tree declares it, both halves in one map.
 *
 * The `+=` here is safe where the same line inside a half is not: the two halves are keyed
 * by disjoint prefixes — a class, a method, a constant, an enum case and a property are
 * spelled `kind:name`, and a config key and an env var are the only things spelled
 * `config:` and `env:` — so no name one half declares can be a name the other already
 * holds. It is the merge across the files *within* a half that can drop a declaration, and
 * that one is reported.
 *
 * @param array<string, list<string>>|null $declaredBy filled in by mergeSurface(), one
 *        entry per symbol naming the files that declare it, when the caller asks
 * @return array<string, string>
 */
function workingSurfaceAll(string $root, ?array &$declaredBy = null): array
{
    $surface = [];

    foreach (surfaceParts() as $directory => $parse) {
        $surface += workingSurface($root . '/' . $directory, $parse, $declaredBy);
    }

    return $surface;
}

/**
 * The same map as the tag holds it. Empty with no tag: there is no "before" to read,
 * which is the state the weighing says out loud in its own notes.
 *
 * @return array<string, string>
 */
function taggedSurfaceAll(string $root, ?string $latestTag, ?array &$declaredBy = null): array
{
    if ($latestTag === null) {
        return [];
    }

    $surface = [];

    foreach (surfaceParts() as $directory => $parse) {
        $surface += taggedSurface($root, $latestTag, $directory, $parse, $declaredBy);
    }

    return $surface;
}

/**
 * The public surface at HEAD versus the last tag — the structural signal, and the
 * one that cannot be forgotten: a removed method is breaking however the notes
 * describe it. Two slices are reported separately, because they are what a consumer
 * names: the classes under src/, and the keys and env vars under config/.
 *
 * @return list<array{source: string, severity: string, summary: string, evidence: list<string>}>
 */
function surfaceSignals(string $root, string $latestTag, ?array &$collisions = null): array
{
    $labels = surfaceLabels();
    $signals = [];

    // Per side, not per call: the two sides hold the same names on a tree that did not
    // rename anything, so one registry would report every symbol in the package as declared
    // twice — once by the tag and once by the checkout. A name two *different* files declare
    // within one side is the question. Each side is read once, into a registry of its own,
    // and the tree is asked first because it is the side the report goes on to describe.
    $treeSide = [];
    $tagSide = [];

    foreach (surfaceParts() as $directory => $parse) {
        $before = $latestTag === null
            ? []
            : taggedSurface($root, $latestTag, $directory, $parse, $tagSide);
        $after = workingSurface($root . '/' . $directory, $parse, $treeSide);

        if ($before !== [] || $after !== []) {
            $signals[] = surfaceSignal($labels[$directory], $before, $after);
        }
    }

    if ($collisions !== null) {
        foreach ([$treeSide, $tagSide] as $side) {
            foreach ($side as $symbol => $paths) {
                if (count($paths) > 1 && !isset($collisions[$symbol])) {
                    $collisions[$symbol] = $paths;
                }
            }
        }
    }

    return $signals;
}

/**
 * The inventory — files.tsv, methods.tsv and surface.tsv — read as a fourth signal.
 *
 * The three files are written into the release commit by this script, stamped with
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
 * WHEN THE RECORD IS ONLY HALF THERE
 * ----------------------------------
 * A file that is not there is read as a whole-record question, as above, but it is not
 * only a reading: `blocks_release` says whether the release script should *wait* for the
 * record to be repaired first, and it is true for exactly one state — some of the three
 * files present and at least one missing.
 *
 * The reason that state is different from the other two is what the files on disk prove.
 * An inventory that is *entirely* absent cannot be told from a tree that never adopted one,
 * which is what every checkout is before the release that writes them, so there is nothing
 * to repair and `bin/release.php` goes ahead and writes them. A stale stamp is repaired by
 * the release that proceeds — that is the whole reason the stamp is rewritten in the
 * release commit — so refusing it would block an ordinary between-releases state. A
 * *partly* present record is neither: at least one file carries a stamp, so a previous
 * release demonstrably published this record, and a file missing from it cannot be "never
 * written". The rows that file held are exactly the ones nothing else can witness, so the
 * skip is silent in the only direction that matters, and the repair is one command:
 * `php bin/inventory.php --at=<the stamp the record carries>`, which reproduces the tag's
 * own rows rather than stamping the working tree with a tag that did not hold it.
 *
 * The limit is stated rather than hidden: deleting all three files produces the
 * entirely-absent state, which is reported and not refused. That is deliberate — the two
 * states are indistinguishable from the files alone, the release writes them either way,
 * and refusing would make a first release impossible.
 *
 * @return array{source: string, severity: string, summary: string, evidence: list<string>, counts: array{files: int, methods: int, surface: int}, stamp: string, fresh: bool, blocks_release: bool}
 */
function inventorySignal(string $root, ?string $latestTag): array
{
    $paths = inventoryPaths($root);

    $stored = [
        'files' => readInventory($paths['files']),
        'methods' => readInventory($paths['methods']),
        'surface' => readInventory($paths['surface']),
    ];

    $current = inventoryRecords($root);
    $counts = [
        'files' => count($current['files']),
        'methods' => count($current['methods']),
        'surface' => count($current['surface']),
    ];
    $expected = $latestTag ?? '(no tag)';
    $stamp = $stored['files']['stamp'] ?? $stored['methods']['stamp'] ?? $stored['surface']['stamp'] ?? 'unknown';

    // The inventory is weighed as a whole or not at all. A file that is not there cannot
    // say whether the rows it should hold were never written or were just removed, and the
    // difference is the whole question — so a missing one is reported rather than read as
    // an empty one, which would count every row of that kind as added since the last
    // release. The case this is really for is an upgrade: an inventory written before
    // `surface.tsv` existed is two files and a stamp that matches.
    $missing = [];

    foreach (['files', 'methods', 'surface'] as $file) {
        if ($stored[$file] === null) {
            $missing[] = basename($paths[$file]);
        }
    }

    if ($missing !== []) {
        // Partly present and wholly absent are two states, and only the first one is a lost
        // file: the names are read back above from whichever files *are* there, so a partial
        // record is repaired by reproducing the tag's own rows, while an absent one has no
        // record to reproduce and is written by the release. See the docblock.
        $partial = count($missing) < 3;

        return [
            'source' => inventorySource(),
            'severity' => 'patch',
            'summary' => sprintf(
                '%d file(s), %d method(s), %d key(s)/member(s) on disk, but no complete inventory to weigh against — %s missing',
                $counts['files'],
                $counts['methods'],
                $counts['surface'],
                implode(', ', $missing),
            ),
            'evidence' => [sprintf(
                '%s missing: %s',
                implode(', ', $missing),
                $partial
                    ? sprintf('run php bin/inventory.php --at=%s — that reproduces the rows and the stamp the tag holds, which is the record the file that is still there says these described; an inventory is weighed as a whole, because rows a file never held would weigh as changes since the last release', $stamp)
                    : 'run php bin/inventory.php — nothing was published to reproduce, and a release writes them; an inventory is weighed as a whole, because rows a file never held would weigh as changes since the last release',
            )],
            'counts' => $counts,
            'stamp' => '(missing)',
            'fresh' => false,
            'blocks_release' => $partial,
        ];
    }

    if ($stamp !== $expected) {
        return [
            'source' => inventorySource(),
            'severity' => 'patch',
            'summary' => sprintf('stale — it describes %s, and this release is built on %s', $stamp, $expected),
            'evidence' => ['a stale inventory cannot tell "nothing changed" from "not refreshed", so it was not weighed; the tag diff still covers the surface'],
            'counts' => $counts,
            'stamp' => $stamp,
            'fresh' => false,
            // Repaired by the release that proceeds, so a refusal here would block an
            // ordinary between-releases state rather than a lost record.
            'blocks_release' => false,
        ];
    }

    $diff = diffInventory($stored, $current);

    return [
        'source' => inventorySource(),
        'severity' => $diff['severity'],
        'summary' => $diff['evidence'] === []
            ? sprintf('current — %d file(s), %d method(s), %d key(s)/member(s), nothing removed, renamed or added since %s', $counts['files'], $counts['methods'], $counts['surface'], $stamp)
            : sprintf('%d change(s) since %s', count($diff['evidence']), $stamp),
        'evidence' => $diff['evidence'],
        'counts' => $counts,
        'stamp' => $stamp,
        'fresh' => true,
        'blocks_release' => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// The Unreleased section — the notes signal reads it, promotion moves it
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
 * The `BREAKING` marker a changelog writes in place of a `### Breaking changes`
 * heading, told apart from a mention of the marker.
 *
 * The word weighs where a changelog makes a claim of it — at the start of a line’s
 * prose, after the bullet, the emphasis or the quote the note opens with — and not
 * where it is only being named. `str_contains($notes, 'BREAKING')` could not tell
 * the two apart, and this repository’s own notes are the case that found it: the
 * entry documenting this signal quotes the marker as `` `BREAKING` `` mid-sentence,
 * so the notes that *describe* the marker marked their own Unreleased section
 * breaking — a claim nobody made, and after 1.0.0 the major this weighing exists
 * to refuse.
 *
 * Two things are read as a mention rather than as a claim, and both are how a word
 * is shown rather than said: a code span, and a fenced block. The word goes with
 * the span that quotes it and the rest of the line stays, so an entry is stripped
 * of what it is exhibiting and kept for what it says.
 *
 * The half this cannot do is read the sentence: a claim written mid-sentence weighs
 * nothing here, because weighing prose for intent is not something a release script
 * can do, and the remedy has always been the same — give the marker its own line.
 */
function breakingClaim(string $notes): bool
{
    // A fenced block is an example being shown, so the fence goes whole, opening
    // line to closing one; an unclosed fence is the same intent half-written.
    $prose = preg_replace('/^[ \t]*```.*?^[ \t]*```[ \t]*$/ms', '', $notes) ?? $notes;

    // Then the code spans, wherever the span sits on its line.
    $prose = preg_replace('/`[^`\n]*`/', '', $prose) ?? $prose;

    // A claim opens a line’s prose; the decoration a note writes in front of it —
    // a bullet, a quote, an emphasis, a warning sign — is skipped, not required.
    return preg_match('/^[ \t]*(?:[-*+>][ \t]*)*\W{0,4}BREAKING\b/m', $prose) === 1;
}
