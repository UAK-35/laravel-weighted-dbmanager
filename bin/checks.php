#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/checks.php
 *
 * One entry point for every check this package can run: PHP syntax, an
 * independent AST parse of the same files, a scan for files read and written
 * back whole, the composer.json schema, the platform requirements, the workflow
 * YAML, the counts the records render from their derivations, a scan of the
 * records for a sentence boundary that lost its space, PHPStan, Pint and
 * PHPUnit.
 *
 * WHY THIS EXISTS IN ADDITION TO `composer test`
 * ----------------------------------------------
 * `composer test` covers the three gates CI enforces — style, types, tests.
 * The rest of what is checked here (a per-file `php -l` pass, a parse of every
 * file by nikic/php-parser, the write-back scan, `composer validate --strict`,
 * `composer check-platform-reqs`, the workflow YAML) is otherwise only looked at
 * by hand after something breaks.
 * Running them together gives one command, one summary and one exit code to
 * trust.
 *
 * USAGE
 * -----
 *   php bin/checks.php                  run everything, print only what failed
 *   php bin/checks.php --verbose        stream the output of every check
 *   php bin/checks.php --only=pint,tests
 *   php bin/checks.php --audit          add `composer audit` (needs the network)
 *   php bin/checks.php --require-all    fail instead of skipping when a tool is missing
 *   php bin/checks.php --list           list the checks without running anything
 *   php bin/checks.php --help
 *
 * Exit code is 0 when every check passed, 1 when any failed (or was skipped
 * under --require-all), 2 for a usage error.
 *
 * PORTABILITY
 * -----------
 * Tools are invoked as `PHP_BINARY <vendor entry file>` rather than through the
 * `vendor/bin` shims, because a shim is a shell script on Unix and a .bat on
 * Windows and neither is reliably executable from another process. The script
 * works on Windows and Linux with the same invocation, and reports a missing
 * tool as a skip rather than crashing on it.
 *
 * Which entry file, for every tool this package installs, is `bin/tools.php` — the
 * same manifest `bin/tool.php` runs the composer scripts from, so a check here and
 * `composer test:types` cannot end up running two builds of one tool.
 */

use Composer\InstalledVersions;
use Composer\Semver\Semver;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$root = str_replace('\\', '/', dirname(__DIR__));

// ─────────────────────────────────────────────────────────────────────────────
// The write-back register
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Every place this package reads a file and writes it back whole, and why it is not the
 * silent overwrite `checkStructureWriteBacks()` exists to catch.
 *
 * The shape the check looks for is the one a reader cannot see: a file is read as a keyed
 * structure — a record, a state file, a config — a key or two are set on the copy in memory,
 * and the whole structure is written back. Every key the reader did not know about is then
 * gone, with nothing in the diff to say so, because the write looks like an ordinary one. It
 * has happened here twice: the flip's state file was replaced by whichever four keys the flip
 * happened to carry (erasing `converged_at` and moving the boot window with it), and the boot
 * audit's record is one file shared by every boot of an installation.
 *
 * So each of them is a decision now, written down rather than assumed. The key is what a
 * failing run prints — `<file>::<method> writes <path>` — so an author who adds the next one
 * pastes the key the check just named and says why the read is still the truth when the write
 * lands. A merge with a read taken at the write site is the one form that needs no entry: a
 * key that appeared between the read and the write survives it, which is what makes the
 * difference between the bug and a read-modify-write that is aware of it.
 */
const STRUCTURE_WRITE_BACKS = [
    'src/Pgcat/PgcatConfigFlipper.php::rollBack writes $target' => 'the bytes read from the target before the copy, put back when the swap has already happened. A
        rollback is the one write that must not merge: the copy being restored is older than the file on
        disk on purpose, which is what putting it back means, and both hold one mode for one mode. It is
        one flip wide, under the flip lock, and what it prevents — pgcat running one mode from memory
        while the disk holds the other — is the reason it exists.',
    'src/Pgcat/PgcatConfigFlipper.php::writeState writes $this->stateFile' => 'the state file, through the one method every flip writes it by. The state is read and the file is
        not: writeLastMode() merges a read taken at the write site, so a key the file gained between two
        flips survives that write, and recordRun() replaces the record with the copy it read under the
        flip lock, which is the only writer of this file. This write used to be a plain
        file_put_contents of four keys, which is how converged_at came to be erased and the boot window
        moved with it.',
    'src/Support/BootAudit.php::write writes $this->file' => 'the audit record. The record is one file shared by every boot of an installation, so the read this
        write is safe against is taken in its caller rather than in its payload: persist() reads the file,
        merges the copy this boot started with into what is on disk, and hands the result here, with the
        whole read-merge-write under an exclusive flock on a companion file. A key another boot recorded
        therefore cannot arrive between that read and this write, which is what makes this a
        read-modify-write that cannot lose one rather than the bug — and the lock is an open descriptor,
        so a worker killed mid-write leaves nothing behind that stops the next boot, which is why it is a
        lock and not the sentinel the store-probe decision refused. The write itself rather than persist()
        is the site because this is where the file is replaced; a rename of a temp over the record is what
        that looks like, and it is the reason the lock lives on a companion file beside it.',
];

/**
 * The calls that turn a value into the text a file holds. A keyed structure on its way to disk is one
 * of these, whichever function built the string around it — which is what makes a payload recognisable
 * as a structure written back rather than as a sentence this run composed.
 */
const VALUE_ENCODERS = ['json_encode', 'var_export', 'serialize', 'yaml_emit'];

/**
 * The shape a sentence boundary makes when its space is eaten: a terminator — `.`, `?`, `!` —
 * with the capitalised word that begins the next sentence against it.
 *
 * The lookbehind is what makes the match *start* at the terminator, so a finding can show the
 * repair without repeating the word before it. The capital has to be followed by a lowercase
 * letter, and that is the whole width of the rule: `production.ERROR` is a config key written in
 * prose twice in these records and `.PGSQL.5432` is a socket path in the changelog, so a rule
 * that fired on any capital after a dot would fail this gate over names rather than over
 * sentences. `checkRecordSentences()` has the measurement and the cost.
 *
 * It is declared here rather than beside its reader because the checks run before the file's own
 * execution reaches the bottom of it: a `const` is bound when the statement runs, and the run
 * loop is above every function.
 */
const FUSED_SENTENCE = '/(?<=[a-z0-9)"\'`*\]])[.?!]["\'`)\]]*[A-Z][a-z]+/';

// ─────────────────────────────────────────────────────────────────────────────
// CLI
// ─────────────────────────────────────────────────────────────────────────────

$options = [
    'verbose' => false,
    'audit' => false,
    'require-all' => false,
    'list' => false,
    'only' => [],
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        usage();
        exit(0);
    }

    if ($argument === '--verbose' || $argument === '-v') {
        $options['verbose'] = true;
        continue;
    }

    if ($argument === '--audit') {
        $options['audit'] = true;
        continue;
    }

    if ($argument === '--require-all') {
        $options['require-all'] = true;
        continue;
    }

    if ($argument === '--list') {
        $options['list'] = true;
        continue;
    }

    if (str_starts_with($argument, '--only=')) {
        $options['only'] = array_values(array_filter(array_map(
            'trim',
            explode(',', substr($argument, 7)),
        )));
        continue;
    }

    fwrite(STDERR, "Unknown option: {$argument}" . PHP_EOL . PHP_EOL);
    usage();
    exit(2);
}

// ─────────────────────────────────────────────────────────────────────────────
// Environment
// ─────────────────────────────────────────────────────────────────────────────

if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}

// bin/ is in the php -l and AST passes on purpose: the scripts are run far more
// often than they are read, and a syntax error in one of them is otherwise only
// found by running it.
$phpFiles = phpFiles([$root . '/src', $root . '/tests', $root . '/config', $root . '/bin']);

// What a consumer installs, which is the half of the tree a write-back can lose data in. The
// dev scripts under bin/ rewrite documents a person then reads, and the one that rewrites a
// record (bin/counts.php) refuses unless every claim it is about to render resolved, so they
// are outside this check on purpose rather than by omission.
$shippedFiles = phpFiles([$root . '/src', $root . '/config']);
$composer = composerCommand($root);
$yamlFiles = yamlFiles($root);

// Whether this repository contains composer.lock. It is asked once, here, because the
// schema check is the only one that can be answered differently for a lock the checkout
// has and the repository does not — see composerValidateCommand().
$lockIsShipped = commitsLockFile($root);

// The installed tools, from the one manifest that says where each of them lives: `bin/tool.php`
// runs them for the composer scripts, this file runs them for the gate, and neither spells a
// vendor path. The manifest holds them relative to the package root — nothing in it is about the
// machine it is read on — and every command below wants an absolute one.
/**
 * @var array<string, array{package: string, pin: string|null, entry: string, purpose: string}> $manifest
 */
$manifest = require __DIR__ . '/tools.php';

// The same tools as the path each command runs. The records are kept as well, because a record
// says more than its path: the package is what a skip sentence names and what a version is asked
// about, and the pin is what the tools check measures the installation against.
$tools = array_map(
    static fn (array $tool): string => $root . '/' . $tool['entry'],
    $manifest,
);

// ─────────────────────────────────────────────────────────────────────────────
// The checks
// ─────────────────────────────────────────────────────────────────────────────

$level = phpstanLevel($root);

$checks = [
    'syntax' => [
        'title' => 'PHP syntax (php -l)',
        'skip' => $phpFiles === [] ? 'no PHP files found' : null,
        'run' => static fn (): array => checkSyntax($root, $phpFiles),
        'note' => static function (array $result) use ($phpFiles): string {
            $failed = preg_match_all('/Errors parsing/', $result['output']);

            return $failed === 0
                ? count($phpFiles) . ' files, no syntax errors'
                : "{$failed} of " . count($phpFiles) . ' files failed';
        },
    ],
    'ast' => [
        'title' => 'AST parse (nikic/php-parser)',
        'skip' => !class_exists(ParserFactory::class) ? 'nikic/php-parser is not installed' : null,
        'run' => static fn (): array => checkAst($root, $phpFiles),
    ],
    'writeback' => [
        'title' => 'Structure write-back (a file read from disk, written back whole)',
        'skip' => !class_exists(ParserFactory::class) ? 'nikic/php-parser is not installed' : null,
        'run' => static fn (): array => checkStructureWriteBacks($root, $shippedFiles),
    ],
    'schema' => [
        'title' => 'composer.json schema (validate --strict)',
        'skip' => $composer === null ? 'composer is not available (set COMPOSER_BINARY)' : null,
        'run' => static fn (): array => runCommand(composerValidateCommand($composer, $lockIsShipped), $root),
        'note' => static function (array $result) use ($lockIsShipped): string {
            // composer prints its lock-file section whether or not it was asked to check
            // it, so a run that deliberately skipped the lock would otherwise summarise
            // itself with a warning nothing acted on. The verdict is the exit code's; the
            // note says what the check actually covered.
            if ($lockIsShipped || $result['exit'] !== 0) {
                return lastLine($result['output']);
            }

            return 'composer.json is valid; composer.lock is not part of this repository, so it is not checked';
        },
    ],
    'platform' => [
        'title' => 'Platform requirements (check-platform-reqs)',
        'skip' => $composer === null ? 'composer is not available (set COMPOSER_BINARY)' : null,
        'run' => static fn (): array => runCommand([...$composer ?? [], 'check-platform-reqs'], $root),
        'note' => static function (array $result): string {
            $satisfied = preg_match_all('/\bsuccess\b/', $result['output']);

            return $satisfied === 0
                ? lastLine($result['output'])
                : "{$satisfied} platform requirements satisfied";
        },
    ],
    'tools' => [
        'title' => 'Installed tools (the version each pin asks for)',
        // The manifest is one half of this question and Composer's own record of the installation is
        // the other, so it can only be asked where there is an installation to ask: the autoloader
        // is what brings InstalledVersions in, and composer/semver is what decides whether a version
        // is inside a constraint. Both absences are the existing kind of skip rather than a failure
        // — a checkout nobody has installed into cannot be wrong about what it installed.
        'skip' => match (true) {
            !is_file($root . '/vendor/autoload.php') => 'composer install has not been run',
            !class_exists(InstalledVersions::class) => "Composer's installed-versions record is not loaded",
            !class_exists(Semver::class) => 'composer/semver is not installed, so a pin cannot be evaluated',
            default => null,
        },
        'run' => static fn (): array => checkTools($manifest),
    ],
    'yaml' => [
        'title' => 'Workflow YAML (yaml-lint)',
        'skip' => match (true) {
            $yamlFiles === [] => 'no YAML outside vendor/',
            !is_file($tools['yaml-lint']) => $manifest['yaml-lint']['package'] . ' is not installed',
            default => null,
        },
        'run' => static fn (): array => runCommand(
            [PHP_BINARY, $tools['yaml-lint'], '--no-ansi', ...$yamlFiles],
            $root,
        ),
    ],
    'counts' => [
        'title' => 'Rendered record counts (bin/counts.php --check)',
        // The renderer reads the claims out of the test support classes, so it needs the
        // autoloader the same way phpstan and pint need their own tools: without it every other
        // check skips too, and this one would fail with the reason the skip already states.
        'skip' => is_file($root . '/vendor/autoload.php') ? null : 'composer install has not been run',
        'run' => static fn (): array => runCommand([PHP_BINARY, $root . '/bin/counts.php', '--check'], $root),
    ],
    'sentences' => [
        'title' => 'Fused sentences (a sentence boundary in a record with no space after it)',
        'skip' => recordFiles($root) === [] ? 'no records to read' : null,
        'run' => static fn (): array => checkRecordSentences($root),
    ],
    'phpstan' => [
        'title' => "Static analysis (phpstan, level {$level})",
        'skip' => is_file($tools['phpstan']) ? null : $manifest['phpstan']['package'] . ' is not installed',
        'run' => static fn (): array => runCommand(
            [PHP_BINARY, $tools['phpstan'], 'analyse', '--no-progress', '--no-ansi'],
            $root,
        ),
    ],
    'pint' => [
        'title' => 'Code style (pint --test)',
        'skip' => is_file($tools['pint']) ? null : $manifest['pint']['package'] . ' is not installed',
        'run' => static fn (): array => runCommand([PHP_BINARY, $tools['pint'], '--test'], $root),
    ],
    'tests' => [
        'title' => 'Unit tests (phpunit)',
        'skip' => is_file($tools['phpunit']) ? null : $manifest['phpunit']['package'] . ' is not installed',
        'run' => static fn (): array => runCommand([PHP_BINARY, $tools['phpunit']], $root),
    ],
];

if ($options['audit']) {
    $checks['audit'] = [
        'title' => 'Security advisories (composer audit)',
        'skip' => $composer === null ? 'composer is not available' : null,
        'run' => static fn (): array => runCommand(
            [...$composer ?? [], 'audit', '--no-interaction'],
            $root,
        ),
    ];
}

if ($options['only'] !== []) {
    $unknown = array_diff($options['only'], array_keys($checks));

    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown check(s): ' . implode(', ', $unknown) . PHP_EOL);
        fwrite(STDERR, 'Known: ' . implode(', ', array_keys($checks)) . PHP_EOL);
        exit(2);
    }

    $checks = array_intersect_key($checks, array_flip($options['only']));
}

if ($options['list']) {
    foreach ($checks as $key => $check) {
        $skip = $check['skip'] === null ? '' : "  (skipped when: {$check['skip']})";

        printf("%-10s %s%s%s", $key, $check['title'], $skip, PHP_EOL);
    }

    exit(0);
}

// ─────────────────────────────────────────────────────────────────────────────
// Run
// ─────────────────────────────────────────────────────────────────────────────

$results = [];
$startedAt = microtime(true);

foreach ($checks as $key => $check) {
    if ($options['verbose']) {
        echo PHP_EOL . "▶ {$check['title']}" . PHP_EOL;
    }

    if ($check['skip'] !== null) {
        $results[$key] = [
            'status' => $options['require-all'] ? 'FAIL' : 'SKIP',
            'note' => $options['require-all']
                ? "could not run: {$check['skip']}"
                : $check['skip'],
            'seconds' => 0.0,
            'output' => '',
        ];

        continue;
    }

    $checkStartedAt = microtime(true);
    $result = $check['run']();
    $seconds = microtime(true) - $checkStartedAt;

    if ($options['verbose']) {
        echo $result['output'];
    }

    // A check may lend a `note` of its own, for a summary line clearer than the last line
    // of its output. It is handed the whole result — the verdict as well as the output — so
    // a note can say what was and was not checked rather than guess it from the prose. The
    // output arrives stripped of ANSI: a note that counts words would otherwise miss them,
    // because a colour reset ends in `m` and `m` is a word character.
    $note = isset($check['note'])
        ? $check['note'](['exit' => $result['exit'], 'output' => stripAnsi($result['output'])])
        : null;

    $results[$key] = [
        'status' => $result['exit'] === 0 ? 'PASS' : 'FAIL',
        'note' => $note ?? lastLine($result['output']),
        'seconds' => $seconds,
        'output' => $result['output'],
    ];
}

$elapsed = microtime(true) - $startedAt;

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────

$passed = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'PASS'));
$failed = array_keys(array_filter($results, static fn (array $r): bool => $r['status'] === 'FAIL'));
$skipped = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'SKIP'));
$total = count($results);

echo PHP_EOL . str_repeat('─', 84) . PHP_EOL;
printf(
    '%d check%s: %d passed, %d failed, %d skipped — %.1fs' . PHP_EOL,
    $total,
    $total === 1 ? '' : 's',
    $passed,
    count($failed),
    $skipped,
    $elapsed,
);
echo str_repeat('─', 84) . PHP_EOL;

foreach ($results as $key => $result) {
    printf(
        "%-10s %-5s %6.1fs  %s" . PHP_EOL,
        $key,
        $result['status'],
        $result['seconds'],
        $result['note'],
    );
}

if ($failed !== []) {
    foreach ($failed as $key) {
        $output = rtrim(stripAnsi($results[$key]['output']));

        if ($output === '') {
            continue;
        }

        echo PHP_EOL . str_repeat('─', 84) . PHP_EOL;
        echo "✗ {$checks[$key]['title']}" . PHP_EOL;
        echo str_repeat('─', 84) . PHP_EOL;
        echo $output . PHP_EOL;
    }

    echo PHP_EOL . 'FAILED: ' . implode(', ', $failed) . PHP_EOL;

    exit(1);
}

echo PHP_EOL . 'All checks passed.' . PHP_EOL;

exit(0);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — argument handling
// ─────────────────────────────────────────────────────────────────────────────

function usage(): void
{
    echo <<<'TXT'
    bin/checks.php — run every check this package can run, with one summary.

    Usage:
      php bin/checks.php [options]

    Options:
      -v, --verbose     Stream the output of every check, not just failures.
          --only=KEYS   Run a subset, comma separated (see --list).
          --audit       Also run `composer audit` (needs network access).
          --require-all Treat a missing tool as a failure instead of a skip.
          --list        List the checks and their skip conditions.
      -h, --help        Show this help.

    Exit code: 0 all green, 1 anything failed, 2 usage error.

    TXT;
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — process management
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run a command with stdout and stderr merged.
 *
 * The command is passed as an array so no shell is involved: no quoting rules
 * to get wrong on Windows, and no shell profile to depend on.
 *
 * @param list<string> $command
 * @return array{exit: int, output: string}
 */
function runCommand(array $command, string $cwd): array
{
    if ($command === []) {
        return ['exit' => 127, 'output' => 'nothing to run'];
    }

    $process = @proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $cwd,
    );

    if (!is_resource($process)) {
        return ['exit' => 127, 'output' => 'could not start: ' . implode(' ', $command)];
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return ['exit' => proc_close($process), 'output' => $output];
}

/**
 * The composer executable to use: an explicit COMPOSER_BINARY, the local
 * composer.phar (gitignored, present in a dev checkout) or composer on PATH.
 *
 * @return list<string>|null
 */
function composerCommand(string $root): ?array
{
    $binary = getenv('COMPOSER_BINARY');

    if (is_string($binary) && $binary !== '' && is_file($binary)) {
        return [PHP_BINARY, $binary];
    }

    if (is_file($root . '/composer.phar')) {
        return [PHP_BINARY, $root . '/composer.phar'];
    }

    // Composer installed globally: `composer --version` decides whether it is
    // really reachable, so an absent binary becomes a skip rather than a fail.
    return runCommand(['composer', '--version'], $root)['exit'] === 0 ? ['composer'] : null;
}

/**
 * `composer validate --strict`, asking the same question CI asks.
 *
 * `--strict` fails on warnings as well as errors, and the one warning this package meets
 * locally and never in CI is the stale lock file. `.gitignore` excludes `composer.lock`,
 * so a checkout has one only because it was installed into, while CI has one only because
 * `composer install` wrote it moments earlier — which is why CI's lock cannot be out of
 * date and a developer's can be, on the same commit, over a file the repository does not
 * contain. Editing `composer.json` then turns this check red on the machine that edited
 * it, and `bin/release.php` moving `extra.branch-alias` does it too, without anyone
 * touching a manifest by hand.
 *
 * So the lock is checked only when the repository contains one. A branch that ships a lock
 * has something to keep in step with the manifest; a branch that ignores it has nothing
 * for a local one to disagree with, and what is validated there is the manifest — the same
 * question, and the same answer, as CI's.
 *
 * @param list<string>|null $composer  the composed `validate` invocation's prefix, or null
 * @param bool              $lockIsShipped whether the repository contains a composer.lock
 * @return list<string>
 */
function composerValidateCommand(?array $composer, bool $lockIsShipped): array
{
    $command = [...$composer ?? [], 'validate', '--strict'];

    if (!$lockIsShipped) {
        $command[] = '--no-check-lock';
    }

    return $command;
}

/**
 * Whether `composer.lock` is part of the repository rather than a local install artefact.
 *
 * git is asked about the file instead of `.gitignore` being read, because the question is
 * whether the repository contains it: a tracked lock is in the repository even where a
 * pattern would ignore it, and an untracked one is the checkout's own however it got
 * there.
 *
 * A machine with no git — or with `composer.lock` in a checkout that is not a repository —
 * answers `false`. There is then no repository to have shipped a lock, so the only lock a
 * run could be comparing is this checkout's own, which is the case the manifest-only
 * question exists for.
 */
function commitsLockFile(string $root): bool
{
    return runCommand(['git', 'ls-files', '--error-unmatch', '--', 'composer.lock'], $root)['exit'] === 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — file discovery
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Every .php file under the given roots, sorted, dot-directories skipped.
 *
 * @param list<string> $roots
 * @return list<string>
 */
function phpFiles(array $roots): array
{
    $files = [];

    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }

        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);

        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static fn (SplFileInfo $entry): bool => !$entry->isDir()
                || !str_starts_with($entry->getFilename(), '.'),
        );

        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * YAML that ships with this repository — `.github/` only, so vendor and any
 * fixture YAML are left alone.
 *
 * @return list<string>
 */
function yamlFiles(string $root): array
{
    $files = [];

    foreach ([$root . '/.github'] as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['yml', 'yaml'], true)) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * The level phpstan.neon.dist asks for, so the summary names what it enforced.
 */
function phpstanLevel(string $root): string
{
    $config = @file_get_contents($root . '/phpstan.neon.dist');

    if (is_string($config) && preg_match('/^\s*level:\s*(\S+)/m', $config, $matches) === 1) {
        return $matches[1];
    }

    return 'unknown';
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — output
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The most informative line of a check's output: its last non-empty one, which
 * in every tool used here is the verdict ("OK (155 tests, 464 assertions)",
 * "[OK] No errors", "PASS 28 files", "./composer.json is valid").
 *
 * Progress dots and box-drawing rules are collapsed first, because a summary
 * line reading 'PASS  ............' tells you nothing.
 */
function lastLine(string $output): string
{
    $lines = [];

    foreach (explode("\n", stripAnsi($output)) as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

        // A separator or progress line carries no verdict.
        if ($line === '' || preg_match('/^[.\-═─= ]+$/', $line) === 1) {
            continue;
        }

        $lines[] = preg_replace('/\.{4,}/', '…', $line) ?? $line;
    }

    if ($lines === []) {
        return '(no output)';
    }

    $last = (string) end($lines);

    return strlen($last) > 78 ? substr($last, 0, 77) . '…' : $last;
}

/**
 * Strip ANSI escape sequences — PHPStan colours its table even when stdout is
 * not a terminal, and a note made entirely of a reset sequence looks like an
 * empty line.
 */
function stripAnsi(string $output): string
{
    return (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output);
}

// ─────────────────────────────────────────────────────────────────────────────
// Checks
// ─────────────────────────────────────────────────────────────────────────────

/**
 * `php -l` each file: the interpreter's own linter, which is the only thing
 * that catches problems the parser alone would accept.
 *
 * @param list<string> $files
 * @return array{exit: int, output: string}
 */
function checkSyntax(string $root, array $files): array
{
    $failures = [];

    foreach ($files as $file) {
        $result = runCommand([PHP_BINARY, '-l', $file], $root);

        if ($result['exit'] !== 0) {
            $failures[] = relative($root, $file) . ': ' . trim($result['output']);
        }
    }

    return $failures === []
        ? ['exit' => 0, 'output' => count($files) . ' files, no syntax errors']
        : ['exit' => 1, 'output' => implode(PHP_EOL, $failures)];
}

/**
 * Parse every file with nikic/php-parser — the same parser PHPStan uses, run
 * here in-process so a syntax problem is reported as a file:line rather than as
 * a wall of AST dump.
 *
 * @param list<string> $files
 * @return array{exit: int, output: string}
 */
function checkAst(string $root, array $files): array
{
    $factory = new ParserFactory();

    $parser = method_exists($factory, 'createForNewestSupportedVersion')
        ? $factory->createForNewestSupportedVersion()
        : $factory->create();

    $failures = [];

    foreach ($files as $file) {
        $source = @file_get_contents($file);

        if ($source === false) {
            $failures[] = relative($root, $file) . ': unreadable';

            continue;
        }

        try {
            $parser->parse($source);
        } catch (\PhpParser\Error $error) {
            $failures[] = sprintf(
                '%s:%d: %s',
                relative($root, $file),
                $error->getStartLine(),
                $error->getRawMessage(),
            );
        }
    }

    return $failures === []
        ? ['exit' => 0, 'output' => count($files) . ' files parsed']
        : ['exit' => 1, 'output' => implode(PHP_EOL, $failures)];
}

/**
 * Every tool the manifest names, against the version its pin asks it to be at.
 *
 * WHY THIS IS A CHECK AND NOT A CLAIM OF THE MANIFEST
 * ---------------------------------------------------
 *   `bin/tools.php` says which package each tool comes from and which constraint `composer.json`
 *   asks that package to be at, and both of those are statements about the tree. What a machine has
 *   installed is a different fact, and it is the one that goes stale with nothing in the diff to
 *   say so: raise `laravel/pint` to `^1.31` and vendor still holds 1.30.4 until someone runs
 *   `composer update`, so `composer lint` and this gate keep running an older tool than the package
 *   says it needs, on a manifest that reads as if they do not. The suite cannot ask it — what is
 *   installed is the machine's rather than the tree's — which is why the manifest's agreement with
 *   `composer.json` and with the README is a test and its agreement with the installation is here.
 *
 * WHAT IT DOES NOT OWN
 * --------------------
 *   A tool that is not installed is reported and is not a failure: three checks above report a
 *   missing tool as a skip already, and a `composer install --no-dev` checkout is a state this
 *   check must not turn red — what it says about such a tool is that it has no version to compare.
 *   A pin that cannot be read is a failure, because the constraint is what everything else is
 *   measured against and a claim nothing can check is worse than no claim.
 *
 * @param array<string, array{package: string, pin: string|null, entry: string, purpose: string}> $tools
 * @return array{exit: int, output: string}
 */
function checkTools(array $tools): array
{
    $rows = [];
    $failures = [];
    $installed = [];

    foreach ($tools as $name => $tool) {
        $package = $tool['package'];

        if (!InstalledVersions::isInstalled($package)) {
            $rows[] = sprintf('  %-10s %-18s %-10s %s', $name, $package, '—', 'not installed');

            continue;
        }

        $version = (string) InstalledVersions::getPrettyVersion($package);
        $installed[] = "{$name} {$version}";

        if ($tool['pin'] === null) {
            $rows[] = sprintf('  %-10s %-18s %-10s %s', $name, $package, $version, 'no pin of its own');

            continue;
        }

        try {
            $satisfied = Semver::satisfies($version, $tool['pin']);
        } catch (UnexpectedValueException $error) {
            $failures[] = sprintf(
                '  %-10s %-18s %s against the pin %s: %s',
                $name,
                $package,
                $version,
                $tool['pin'],
                $error->getMessage(),
            );

            continue;
        }

        if (!$satisfied) {
            $failures[] = sprintf(
                '  %-10s %-18s %s is installed, and the pin asks for %s',
                $name,
                $package,
                $version,
                $tool['pin'],
            );

            continue;
        }

        $rows[] = sprintf('  %-10s %-18s %-10s %s', $name, $package, $version, 'satisfies ' . $tool['pin']);
    }

    if ($failures !== []) {
        return [
            'exit' => 1,
            'output' => implode(PHP_EOL, [
                'A pin is the constraint composer.json asks a package to be at, and this installation',
                'does not answer it: vendor is behind the manifest, or the pin is a version nothing',
                'can be. `composer update` is the repair for the first and an edit for the second.',
                '',
                ...$rows,
                '',
                ...$failures,
            ]),
        ];
    }

    // The last line is the one the summary shows, so it is a single line and it names every tool
    // the manifest has — which is what makes the gate's summary a reading of this table.
    return [
        'exit' => 0,
        'output' => implode(PHP_EOL, [
            ...$rows,
            '',
            $installed === []
                ? 'none of the tools in the manifest is installed'
                : sprintf('%d installed: %s', count($installed), implode(', ', $installed)),
        ]),
    ];
}

/**
 * A record read as prose, checked for a sentence boundary that has lost its space.
 *
 * WHY THIS IS A CHECK
 * -------------------
 *   The records are read as prose and this is what an edit does to them: the space at the one
 *   place a reader cannot recover it from is eaten, the last word of one sentence becomes the
 *   first of the next, and the join reads as a word that is not in the language — `…written by
 *   the release.Diffing it is…`. Nothing else notices: `php -l` has no opinion about a paragraph,
 *   the suite asserts behaviour rather than wording, and the records' own prose is one copy-paste
 *   away from the shape at all times. It is a typo, and it is
 *   the same kind of guard as the write-back register below: a defect that is invisible in the
 *   diff that makes it and cheap to look for afterwards.
 *
 * THE RULE, AND WHY IT IS THIS WIDE
 * ---------------------------------
 *   A terminator touches a capitalised word that has at least one lowercase letter after it, and
 *   the character before the terminator is a letter, a digit or a closing `)`, `]`, quote,
 *   backtick or emphasis marker. That is narrower than "a capital after a dot" on purpose, and
 *   the width is a measurement rather than a preference: read that way, the records carry
 *   `production.ERROR` twice and the changelog a `/var/run/postgresql/.s.PGSQL.5432`, both of
 *   which are names rather than sentences, so the loose rule fails the gate over a config key.
 *   The price is the other direction — a fused sentence whose next word is all capitals
 *   (`release.PHP`) is not reported — and it is a price paid out loud: that string is one of the
 *   fixtures, so a rule widened later has to keep it silent and the trade cannot be undone by
 *   accident.
 *
 * WHAT IS NOT READ
 * ----------------
 *   Fenced blocks. A fence holds code, this is a rule about sentences, and the code in these
 *   records is exactly what a sentence rule reports: `$_.Subject`, `'. '.ClassName`, `1.0.0`. The
 *   line that says so found this out rather than argued it — the paragraph documenting this check
 *   was reported twice before it was fixed, once for quoting the example in prose and once because
 *   the PHP idiom quoted beside it lost a space in the quoting, which is a fused sentence. Both
 *   are in a fence now.
 *
 *   The CHANGELOG is not read either: everything below its `## Unreleased` heading is a published
 *   record and RELEASING.md leaves every released section byte for byte alone, so a finding there
 *   would have no repair that is not an edit to a release. What is left is the records —
 *   `README.md`, `RELEASING.md` and every `docs/*.md` — and the fixtures are run before them, so
 *   a detector that has stopped matching fails by name instead of reporting no fused sentence.
 *
 * @return array{exit: int, output: string}
 */
function checkRecordSentences(string $root): array
{
    $fixtures = fusedSentenceFixtures();
    $disagreements = [];

    foreach ($fixtures as $name => [$text, $expected]) {
        $reported = array_column(fusedSentences(proseIn($text)), 1);
        sort($reported);
        sort($expected);

        if ($reported !== $expected) {
            $disagreements[] = sprintf(
                '  %-64s expected %s, reported %s',
                $name,
                $expected === [] ? 'nothing' : implode(', ', $expected),
                $reported === [] ? 'nothing' : implode(', ', $reported),
            );
        }
    }

    // The fixtures are run against the same detector the records are, and before them, because a
    // detector that has stopped seeing the shape reports an empty result: without this, the check
    // that is meant to catch a fused sentence would pass by finding none.
    if ($disagreements !== []) {
        return [
            'exit' => 1,
            'output' => implode(PHP_EOL, [
                'The detector no longer agrees with its own fixtures, so what it says about the',
                'records is not worth having — a shape it has stopped recognising is a finding it',
                'reports as an empty result:',
                '',
                ...$disagreements,
            ]),
        ];
    }

    $records = recordFiles($root);
    $findings = [];
    $prose = 0;

    foreach ($records as $record) {
        $text = @file_get_contents($root . '/' . $record);

        if ($text === false) {
            continue;
        }

        $reading = proseIn($text);
        $prose += count(array_filter(
            explode("\n", $reading),
            static fn (string $line): bool => trim($line) !== '',
        ));

        foreach (fusedSentences($reading) as [$offset, $join]) {
            $lineStart = strrpos(substr($reading, 0, $offset), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $lineEnd = strpos($reading, "\n", $offset);

            $findings[] = sprintf(
                '  %s:%d  %s',
                $record,
                substr_count(substr($reading, 0, $offset), "\n") + 1,
                joinedWindow(
                    substr($reading, $lineStart, ($lineEnd === false ? strlen($reading) : $lineEnd) - $lineStart),
                    $offset - $lineStart,
                    strlen($join),
                ),
            );
        }
    }

    if ($findings !== []) {
        return [
            'exit' => 1,
            'output' => implode(PHP_EOL, [
                'A sentence boundary in a record has lost its space, so the last word of one sentence',
                'and the first of the next are written as one:',
                '',
                ...$findings,
                '',
                'A space goes back after the terminator — the join reads as one word only because',
                'nothing separates the two sentences — and a sentence that is wrapped rather than',
                'joined is not one of these, because a newline is a space.',
            ]),
        ];
    }

    return [
        'exit' => 0,
        'output' => sprintf(
            '%d prose line(s) in %d record(s), no fused sentence; the detector agreed with all %d fixtures',
            $prose,
            count($records),
            count($fixtures),
        ),
    ];
}

/**
 * The records: the two documents at the root, and every design record under `docs/`.
 *
 * The CHANGELOG is deliberately not one of them — a published section is left byte for byte
 * alone, so a record whose repair is an edit is not a record this can read. The set is the one
 * the citations guard reads, and both take `docs/` as a glob rather than a list, because a
 * record added there is a record in both.
 *
 * @return list<string>
 */
function recordFiles(string $root): array
{
    $records = ['README.md', 'RELEASING.md'];

    foreach (glob($root . '/docs/*.md') ?: [] as $record) {
        $records[] = 'docs/' . basename($record);
    }

    sort($records);

    return $records;
}

/**
 * A record's text with its fenced blocks blanked out.
 *
 * The lines are kept — an empty line for each one dropped — so a finding's line number is the
 * file's own, and the fence delimiters go with the code, indented or not.
 */
function proseIn(string $text): string
{
    $lines = [];
    $fenced = false;

    foreach (explode("\n", $text) as $line) {
        if (str_starts_with(ltrim($line), '```')) {
            $fenced = ! $fenced;
            $lines[] = '';

            continue;
        }

        $lines[] = $fenced ? '' : $line;
    }

    return implode("\n", $lines);
}

/**
 * The joins in a piece of prose: the offset of each, and the terminator with the word it is
 * touching, as it reads — `release.Diffing`.
 *
 * @return list<array{0: int, 1: string}>
 */
function fusedSentences(string $prose): array
{
    preg_match_all(FUSED_SENTENCE, $prose, $matches, PREG_OFFSET_CAPTURE);

    return array_map(
        static fn (array $match): array => [$match[1], $match[0]],
        $matches[0],
    );
}

/**
 * One line of prose, cut to a window around a join: enough to read the word two sentences made,
 * and short enough that a report prints one line per finding.
 */
function joinedWindow(string $line, int $start, int $length): string
{
    $from = max(0, $start - 32);
    $to = min(strlen($line), $start + $length + 32);

    return ($from > 0 ? '…' : '') . substr($line, $from, $to - $from) . ($to < strlen($line) ? '…' : '');
}

/**
 * The strings the detector is proven against before it is trusted with the records: the shapes a
 * sentence can be fused in, and the shapes it has to leave alone.
 *
 * The second half is not padding. A rule that fired on any capital after a dot reports
 * `production.ERROR` — a config key written in prose twice in these records — and a fence full of
 * `$_.Subject` and `'. '.ClassName`; a filename, a version, a URL and a namespace are the same
 * kind of shape; a sentence wrapped onto the next line is not fused, because a newline is a
 * space; and `log.FLUSH` is the one fusion this rule cannot tell from a name. Each negative is a
 * case the detector must *not* report, so a rule widened for one more fusion has to keep them
 * silent or fail here by name.
 *
 * @return array<string, array{0: string, 1: list<string>}>
 */
function fusedSentenceFixtures(): array
{
    return [
        'a full stop with the next sentence against it' => [
            "The rows describe the tree the release published.Diffing them is what the next release does.\n",
            ['.Diffing'],
        ],
        'a question mark' => [
            "Which of the three rows is the one?The answer is in the record.\n",
            ['?The'],
        ],
        'an exclamation mark' => [
            "That is the whole of the reason!Writing it down is what the next reader has.\n",
            ['!Writing'],
        ],
        'a sentence that ends in a bracket' => [
            "The read is taken at the write site (see the flip).Diffing two of them is what it does.\n",
            ['.Diffing'],
        ],
        'a sentence that ends in a quotation' => [
            "The note says \"this is refused.\"Anything else is a change to the record.\n",
            ['."Anything'],
        ],
        'a sentence that ends in an inline code span' => [
            "The third file is `surface.tsv`.The rows are written from the ref.\n",
            ['.The'],
        ],
        'a sentence that ends in emphasis' => [
            "The register entry says **safe**.Writing the reason down is the point.\n",
            ['.Writing'],
        ],
        'a sentence that ends in a digit, as a version does' => [
            "The release tag is v0.2.0.The rows describe that tree.\n",
            ['.The'],
        ],
        'prose after a fenced block is still read' => [
            "The command is:\n\n```bash\nphp bin/checks.php --only=tests\n```\n\nEvery check passes.The next line is read from here.\n",
            ['.The'],
        ],
        'a fenced block of PowerShell is code, not a sentence' => [
            <<<'TXT'
            The subject is read with:

            ```powershell
            $_.Subject -match 'Web/Mail Shield'
            ```

            TXT,
            [],
        ],
        'a fenced block of PHP concatenation is code too' => [
            <<<'TXT'
            The sentence is assembled as:

            ```php
            return $offending.'. Refused: '.ReaderWindows::ACCEPTED.'. '.$consequence;
            ```

            TXT,
            [],
        ],
        'a config key written in prose is a name, not a sentence' => [
            "The handler is `production.ERROR` when the log is a line log.\n",
            [],
        ],
        'a socket path in prose is a name too' => [
            "The server listens on /var/run/postgresql/.s.PGSQL.5432 while writes go on.\n",
            [],
        ],
        'a filename with a capital in it' => [
            "The reader is `src/Console/JsonEnvelope.php` and it reads the record.\n",
            [],
        ],
        'a version' => [
            "The tag is v0.2.0-alpha1 and the rows describe it.\n",
            [],
        ],
        'an abbreviation that kept its space' => [
            "The state file, e.g. the four keys the flip carried, is one file.\n",
            [],
        ],
        'a URL, whose capital follows a slash rather than a stop' => [
            "The package lives at github.com/Uak35/WeightedDbManager today.\n",
            [],
        ],
        'a word of capitals after a dot, which is the fusion this cannot tell from a name' => [
            "The layer is log.FLUSH and the row is written.\n",
            [],
        ],
        'two sentences with the space where it belongs' => [
            "One sentence. The next one starts with its space.\n",
            [],
        ],
        'a sentence wrapped onto the next line' => [
            "One sentence.\nThe next one starts a line, which is a space.\n",
            [],
        ],
    ];
}

/**
 * The whole-file write-back check: a file read from disk, written back as one piece.
 *
 * WHY A STATIC CHECK, AND WHY THIS ONE
 * ------------------------------------
 *   A keyed structure read from disk, changed in memory and written back whole loses every key
 *   the reader did not know about, and it loses them *quietly*: the write is an ordinary write,
 *   the diff shows the key that was added, and nothing shows the keys that went. Both places in
 *   this package where it has mattered were found by a reader noticing, not by a run failing —
 *   the flip's state file, replaced by whichever four keys the flip carried at the time, and the
 *   boot audit's record, which is one file shared by every boot of an installation.
 *
 *   So the shape is looked for in the source instead of in a running installation. A class that
 *   reads a path (as a keyed structure, or as the whole file's bytes) and writes that same path
 *   back is a read-modify-write of a file, and every one of them is either safe for a reason only
 *   its author knows or is the bug. One of them is a form that needs no reason:
 *
 *     file_put_contents($this->stateFile, json_encode([...$this->readState(), 'last_mode' => $mode]));
 *
 *   A key that appeared between the read and the write survives that, because the read is taken at
 *   the write. Every other form — a structure read earlier and written back, bytes read before a
 *   swap and restored after it — is reported, and the register at the top of this file is where
 *   its author says why the copy being replaced is still the truth.
 *
 * WHAT IS SCANNED
 * ---------------
 *   `src/` and `config/`, the two directories a consumer installs, in the AST rather than as text.
 *   A write is `file_put_contents()`, or the `fwrite()` of a handle opened in the same method, and
 *   its target is the path it names — or, when the write lands in a temp that a `rename()` in the
 *   same method completes, the path that rename names, which is the atomic write every writer here
 *   uses and so the one whose target a text search would miss.
 *
 * WHAT IT CANNOT SEE
 * ------------------
 *   It is deliberately shallow, so the ways past it are worth naming rather than discovering: a
 *   read that goes through a handle opened elsewhere (`fread`), a write assembled by a helper
 *   (`file_put_contents($path, $this->encode($state))` — the payload is a call this check does not
 *   unwrap), a path written in one class and read in another, a path built by string functions the
 *   check does not follow, and a read-modify-write that happens across two processes rather than in
 *   one method. Each is a real way to lose a key; none is a way this package does it today. The
 *   check is a guard against the shape coming back, not a proof that it is absent.
 *
 * THE FRESH-READ EXEMPTION IS NOT TRUST
 * -------------------------------------
 *   A merge with a read of the same path taken at the write site is the form the flip's
 *   `writeLastMode()` uses, and it is exempt because the exemption is *checked*: the payload has to
 *   be an array, an `array_merge()` or a `+`, and the read has to be of the path being written,
 *   found by walking the payload. A payload that merely looks like a merge is not one.
 *
 * @param list<string> $files
 * @return array{exit: int, output: string}
 */
function checkStructureWriteBacks(string $root, array $files): array
{
    $fixtures = structureWriteBackFixtures();
    $disagreements = [];

    foreach ($fixtures as $name => [$source, $expected]) {
        $reported = structureWriteBackMethods($source);
        $wanted = $expected;
        sort($reported);
        sort($wanted);

        if ($reported !== $wanted) {
            $disagreements[] = sprintf(
                '  %-54s expected %s, reported %s',
                $name,
                $wanted === [] ? 'nothing' : implode(', ', $wanted),
                $reported === [] ? 'nothing' : implode(', ', $reported),
            );
        }
    }

    // The fixtures are run against the same detector the shipped tree is, and before it, because a
    // detector that has stopped seeing the shape reports an empty result: without this, the check
    // that is meant to catch a silent overwrite would fail silently itself.
    if ($disagreements !== []) {
        return [
            'exit' => 1,
            'output' => implode(PHP_EOL, [
                'The detector no longer agrees with its own fixtures, so what it says about the shipped',
                'tree is not worth having — a shape it has stopped recognising is a finding it reports',
                'as an empty result:',
                '',
                ...$disagreements,
            ]),
        ];
    }

    $findings = [];

    foreach ($files as $file) {
        $source = @file_get_contents($file);

        if ($source === false) {
            continue;
        }

        foreach (structureWriteBacksIn($source) as $finding) {
            $findings[] = [...$finding, 'file' => relative($root, $file), 'key' => sprintf(
                '%s::%s writes %s',
                relative($root, $file),
                $finding['method'],
                $finding['path'],
            )];
        }
    }

    $declared = array_keys(STRUCTURE_WRITE_BACKS);
    $seen = array_column($findings, 'key');
    $undeclared = [];

    foreach ($findings as $finding) {
        if (!in_array($finding['key'], $declared, true)) {
            $undeclared[] = sprintf(
                '  %s:%d  %s  (%s)',
                $finding['file'],
                $finding['line'],
                $finding['key'],
                $finding['payload'],
            );
        }
    }

    $stale = array_values(array_diff($declared, $seen));

    if ($undeclared !== [] || $stale !== []) {
        $output = [];

        if ($undeclared !== []) {
            $output = [
                'A file this class reads is written back whole, which is how a key nobody knew about',
                'disappears without a diff saying so:',
                '',
                ...$undeclared,
                '',
                'Either merge the read into the write (a fresh read of the same path taken at the write',
                'site survives a key that arrived in between), or add the key above to',
                'STRUCTURE_WRITE_BACKS in this file with the reason the copy being replaced is still',
                'the truth.',
            ];
        }

        if ($stale !== []) {
            if ($output !== []) {
                $output[] = '';
            }

            $output = [...$output, 'STRUCTURE_WRITE_BACKS declares write-back(s) that are not there any more, so a', 'declaration outlives the code it described and would excuse the next write at that spot:', ''];

            foreach ($stale as $key) {
                $output[] = '  ' . $key;
            }

            $output[] = '';
            $output[] = 'Remove the entry, or restore the write — a register that is empty of meaning is';
            $output[] = 'how a check stops being one.';
        }

        return ['exit' => 1, 'output' => implode(PHP_EOL, $output)];
    }

    return [
        'exit' => 0,
        'output' => sprintf(
            '%d write-back(s) in the shipped tree, every one declared; the detector agreed with all %d fixtures',
            count($findings),
            count($fixtures),
        ),
    ];
}

/**
 * The shapes the detector is proven against before it is trusted with the shipped tree.
 *
 * Each fixture is a source string and the methods that must be reported in it, so the check runs
 * the same code over a shape it must find and shapes it must leave alone: the two ways this package
 * has lost a key, the merges that are the safe form of both — one direct, one whose read is two
 * calls deep — the atomic temp-then-rename write, a handle opened and written with, a config file
 * rebuilt around an encoded structure, a copy to a different file, a scratch file nobody reads, a
 * line of prose written into a file that is also read, and the case where the write site is one
 * method and the read is in its caller's. A detector that stops seeing one of these fails the check
 * by name rather than passing everything.
 *
 * @return array<string, array{0: string, 1: list<string>}>
 */
function structureWriteBackFixtures(): array
{
    return [
        'a structure read earlier, written back whole' => [
            <<<'PHP'
            <?php
            class State
            {
                public function __construct(private string $file) {}

                private function read(): array
                {
                    $raw = @file_get_contents($this->file);
                    return is_string($raw) ? (array) json_decode($raw, true) : [];
                }

                public function persist(string $mode): void
                {
                    $state = $this->read();
                    $state['mode'] = $mode;
                    file_put_contents($this->file, json_encode($state));
                }
            }
            PHP,
            ['persist'],
        ],
        'the file replaced by the keys this run happens to carry' => [
            <<<'PHP'
            <?php
            class Flip
            {
                private string $stateFile = '/tmp/state.json';

                private function readState(): array
                {
                    return (array) json_decode((string) @file_get_contents($this->stateFile), true);
                }

                public function record(string $mode): void
                {
                    $state = $this->readState();
                    file_put_contents($this->stateFile, json_encode([
                        'last_mode' => $mode,
                        'flipped_by' => 'flipper',
                    ]));
                }
            }
            PHP,
            ['record'],
        ],
        'a merge with a read taken at the write site' => [
            <<<'PHP'
            <?php
            class Merge
            {
                private string $stateFile = '/tmp/state.json';

                private function readState(): array
                {
                    return (array) json_decode((string) @file_get_contents($this->stateFile), true);
                }

                public function record(string $mode): void
                {
                    file_put_contents($this->stateFile, json_encode([
                        ...$this->readState(),
                        'last_mode' => $mode,
                    ]));
                }
            }
            PHP,
            [],
        ],
        'a temp file a rename completes, read in a helper the write site calls' => [
            <<<'PHP'
            <?php
            class Audit
            {
                private string $file = '/tmp/audit.json';

                private function read(): array
                {
                    return (array) json_decode((string) @file_get_contents($this->file), true);
                }

                private function keepEntries(): void
                {
                    $onDisk = $this->read();
                }

                public function persist(array $updated): void
                {
                    $this->keepEntries();
                    $temporary = $this->file.'.tmp.'.getmypid();
                    file_put_contents($temporary, json_encode($updated));
                    rename($temporary, $this->file);
                }
            }
            PHP,
            ['persist'],
        ],
        'a copy to another file' => [
            <<<'PHP'
            <?php
            class Copier
            {
                public function swap(string $from, string $to): void
                {
                    $temporary = $to.'.tmp.'.getmypid();
                    file_put_contents($temporary, file_get_contents($from));
                    rename($temporary, $to);
                }
            }
            PHP,
            [],
        ],
        'a scratch file nothing reads' => [
            <<<'PHP'
            <?php
            class Locks
            {
                private string $lockFile = '/tmp/flip.lock';

                public function touch(): void
                {
                    file_put_contents($this->lockFile, '');
                }
            }
            PHP,
            [],
        ],
        'a line of prose written into a file that is read' => [
            <<<'PHP'
            <?php
            class Log
            {
                private string $file = '/tmp/log.txt';

                public function tail(): string
                {
                    return (string) @file_get_contents($this->file);
                }

                public function write(string $line): void
                {
                    file_put_contents($this->file, sprintf("%s\n", $line));
                }
            }
            PHP,
            [],
        ],
        'a merge whose read is two calls deep' => [
            <<<'PHP'
            <?php
            class DeepMerge
            {
                private string $stateFile = '/tmp/state.json';

                private function state(): array
                {
                    return (array) json_decode((string) @file_get_contents($this->stateFile), true);
                }

                private function readState(): array
                {
                    return $this->state();
                }

                public function record(string $mode): void
                {
                    file_put_contents($this->stateFile, json_encode([
                        ...$this->readState(),
                        'mode' => $mode,
                    ]));
                }
            }
            PHP,
            [],
        ],
        'a handle opened and written with' => [
            <<<'PHP'
            <?php
            class Handle
            {
                private string $file = '/tmp/handle.json';

                public function read(): array
                {
                    return (array) json_decode((string) @file_get_contents($this->file), true);
                }

                public function persist(array $state): void
                {
                    $handle = fopen($this->file, 'w');
                    fwrite($handle, json_encode($state));
                    fclose($handle);
                }
            }
            PHP,
            ['persist'],
        ],
        'a config file rebuilt around an encoded structure' => [
            <<<'PHP'
            <?php
            class ConfigFile
            {
                public function __construct(private string $path) {}

                private function load(): array
                {
                    return (array) (require $this->path);
                }

                public function save(array $values): void
                {
                    file_put_contents($this->path, '<?php return '.var_export($values, true).';');
                }
            }
            PHP,
            ['save'],
        ],
        'the write site that replaces what the class reads elsewhere' => [
            <<<'PHP'
            <?php
            class Helpers
            {
                private string $stateFile = '/tmp/state.json';

                private function readState(): array
                {
                    return (array) json_decode((string) @file_get_contents($this->stateFile), true);
                }

                private function writeState(array $state): void
                {
                    @file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
                }

                public function record(string $mode): void
                {
                    $state = $this->readState();
                    $state['last_mode'] = $mode;
                    $this->writeState($state);
                }
            }
            PHP,
            ['writeState'],
        ],
    ];
}

/**
 * The methods a source string writes a whole value back into a path its own class reads.
 *
 * The fixture half of the check, which asserts on names; `structureWriteBacksIn()` is the same
 * scan with the file, line and payload a report needs.
 *
 * @return list<string>
 */
function structureWriteBackMethods(string $source): array
{
    $methods = array_column(structureWriteBacksIn($source), 'method');

    return array_values(array_unique($methods));
}

/**
 * Every write in one source string that puts a whole value back into a path the same class reads.
 *
 * @return list<array{method: string, line: int, path: string, payload: string}>
 */
function structureWriteBacksIn(string $source): array
{
    $factory = new ParserFactory();

    $parser = method_exists($factory, 'createForNewestSupportedVersion')
        ? $factory->createForNewestSupportedVersion()
        : $factory->create();

    try {
        $ast = $parser->parse($source);
    } catch (\PhpParser\Error) {
        // A file that does not parse is the syntax and AST checks' to report; saying it again here
        // would turn one problem into three failures with the same cause.
        return [];
    }

    if ($ast === null) {
        return [];
    }

    $finder = new NodeFinder();
    $findings = [];

    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            $methods[$method->name->toString()] = $method;
        }

        $findings = [...$findings, ...scopeWriteBacks($methods, $source, $finder)];
    }

    $functions = [];

    foreach ($ast as $statement) {
        if ($statement instanceof Node\Stmt\Function_) {
            $functions[$statement->name->toString()] = $statement;
        }
    }

    return [...$findings, ...scopeWriteBacks($functions, $source, $finder)];
}

/**
 * The write-backs in one set of methods that share a class: a write of a whole value to a path
 * that the class reads.
 *
 * The class is the unit rather than the method, and that is the decision this check is built on:
 * the read and the write are rarely in one method. `writeState()` replaces the file `readState()`
 * reads, `persist()` merges the file `read()` reads and hands the result to `write()`, which is the method that replaces it —
 * and the bug this check exists for lived exactly at a method that replaced a file the class read
 * somewhere else. A write in a class that reads the path is a read-modify-write of it, whichever
 * method each half is in; what is reported is the write site, because that is where the file is
 * replaced.
 *
 * Each method's own reads are still closed over the calls between them first, because the
 * fresh-read exemption has to see a read taken at the write site through a helper — `[...$this->readState(), …]`
 * is a merge however many calls deep the read itself is.
 *
 * @param array<string, Node> $methods
 * @return list<array{method: string, line: int, path: string, payload: string}>
 */
function scopeWriteBacks(array $methods, string $source, NodeFinder $finder): array
{
    $reads = [];

    foreach ($methods as $name => $method) {
        $reads[$name] = readPaths($method, $source, $finder);
    }

    do {
        $changed = false;

        foreach ($methods as $name => $method) {
            foreach (selfCalls($method, $finder) as $called) {
                if (!isset($reads[$called]) || $reads[$called] === []) {
                    continue;
                }

                $merged = array_values(array_unique([...$reads[$name], ...$reads[$called]]));

                if ($merged !== $reads[$name]) {
                    $reads[$name] = $merged;
                    $changed = true;
                }
            }
        }
    } while ($changed);

    $class = array_values(array_unique(array_merge(...array_values($reads))));

    if ($class === []) {
        return [];
    }

    $findings = [];

    foreach ($methods as $name => $method) {
        foreach (writeSites($method, $source, $finder) as $site) {
            if (!in_array($site['path'], $class, true)) {
                continue;
            }

            if (!wholeValue($site['payload'], $source, $finder)) {
                continue;
            }

            if (mergesFreshRead($site['payload'], $site['path'], $reads, $source, $finder)) {
                continue;
            }

            $findings[] = [
                'method' => $name,
                'line' => $site['line'],
                'path' => $site['path'],
                'payload' => nodeText($site['payload'], $source),
            ];
        }
    }

    return $findings;
}

/**
 * The paths one method reads whole: a file read as bytes, a file included as a PHP structure, or
 * a file parsed as one.
 *
 * A handle is not a read here: `fopen()` for a lock is not a whole file, and the paths this
 * package opens that way are never written back through `file_put_contents()`.
 *
 * @return list<string>
 */
function readPaths(Node $method, string $source, NodeFinder $finder): array
{
    $sinks = ['file_get_contents', 'file', 'readfile', 'parse_ini_file', 'yaml_parse_file', 'yaml_parse_url'];
    $paths = [];

    foreach ($finder->findInstanceOf($method, Node\Expr\FuncCall::class) as $call) {
        $name = functionName($call);
        $argument = $call->getArgs()[0] ?? null;

        if ($name === null || $argument === null || !in_array($name, $sinks, true)) {
            continue;
        }

        $paths[] = nodeText($argument->value, $source);
    }

    foreach ($finder->findInstanceOf($method, Node\Expr\Include_::class) as $include) {
        $paths[] = nodeText($include->expr, $source);
    }

    return array_values(array_unique($paths));
}

/**
 * The writes a method performs, with the path each one really lands on.
 *
 * Two resolutions, and both are the way this package writes a file: a `fwrite()` names a handle,
 * so the path is the `fopen()` that opened it; a write into a temp names the temp, so the path is
 * what a `rename()` of that temp finishes on. A write that is neither is the path it names.
 *
 * @return list<array{path: string, payload: Node\Expr, line: int}>
 */
function writeSites(Node $method, string $source, NodeFinder $finder): array
{
    $handles = [];
    $renames = [];

    foreach ($finder->findInstanceOf($method, Node\Expr\Assign::class) as $assign) {
        $expr = $assign->expr;

        if (!$expr instanceof Node\Expr\FuncCall || functionName($expr) !== 'fopen') {
            continue;
        }

        $opened = $expr->getArgs()[0] ?? null;

        if ($opened !== null) {
            $handles[nodeText($assign->var, $source)] = nodeText($opened->value, $source);
        }
    }

    foreach ($finder->findInstanceOf($method, Node\Expr\FuncCall::class) as $call) {
        if (functionName($call) !== 'rename') {
            continue;
        }

        $arguments = $call->getArgs();
        $from = $arguments[0] ?? null;
        $to = $arguments[1] ?? null;

        if ($from !== null && $to !== null && str_starts_with(nodeText($from->value, $source), '$')) {
            $renames[nodeText($from->value, $source)] = nodeText($to->value, $source);
        }
    }

    $sites = [];

    foreach ($finder->findInstanceOf($method, Node\Expr\FuncCall::class) as $call) {
        if (!in_array(functionName($call), ['file_put_contents', 'fwrite'], true)) {
            continue;
        }

        $arguments = $call->getArgs();
        $target = $arguments[0] ?? null;
        $payload = $arguments[1] ?? null;

        if ($target === null || $payload === null) {
            continue;
        }

        $named = nodeText($target->value, $source);

        $sites[] = [
            'path' => $renames[$named] ?? $handles[$named] ?? $named,
            'payload' => $payload->value,
            'line' => $call->getStartLine(),
        ];
    }

    return $sites;
}

/**
 * Whether a payload is a whole value rather than prose this run composed: an encoded structure, a
 * variable, property, element or array holding one, or a string built around an encode — which is
 * what a config file rebuilt from the array it was read as looks like.
 *
 * A literal is not one, and neither is a `sprintf()`: a file this class reads and a line written
 * into it is a log, not a keyed structure going back to disk, and reporting it would be noise that
 * teaches a reader to declare things. An encoder is the signal that a structure is on its way to
 * disk, so it counts wherever it appears in the payload.
 */
function wholeValue(Node $payload, string $source, NodeFinder $finder): bool
{
    if (containsEncoder($payload, $finder)) {
        return true;
    }

    $value = unwrapValue($payload);

    return $value instanceof Node\Expr\Variable
        || $value instanceof Node\Expr\PropertyFetch
        || $value instanceof Node\Expr\StaticPropertyFetch
        || $value instanceof Node\Expr\ArrayDimFetch
        || $value instanceof Node\Expr\Array_;
}

/**
 * Whether a payload encodes a structure anywhere in it, so `'<?php return '.var_export($config, true).';'`
 * is a structure written back rather than a sentence composed.
 */
function containsEncoder(Node $payload, NodeFinder $finder): bool
{
    return $finder->find($payload, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall
        && in_array(functionName($node), VALUE_ENCODERS, true)) !== [];
}


/**
 * A payload with its encoders and casts removed: the value that is actually being written, so a
 * `json_encode([...$state])` is read as the array it is.
 */
function unwrapValue(Node $node): Node
{
    $encoders = VALUE_ENCODERS;

    while (true) {
        if ($node instanceof Node\Expr\Cast) {
            $node = $node->expr;
            continue;
        }

        $argument = $node instanceof Node\Expr\FuncCall && in_array(functionName($node), $encoders, true)
            ? ($node->getArgs()[0] ?? null)
            : null;

        if ($argument === null) {
            return $node;
        }

        $node = $argument->value;
    }
}

/**
 * Whether a payload merges a read of the path it is writing, taken at the write site — the one form
 * that needs no declaration, because a key that arrived between the two is not lost by it.
 *
 * Only a container that can merge qualifies: an array, an `array_merge()`, a `+`. A payload that is
 * merely *built from* a read — `json_encode(['last_mode' => $mode])` — is not a merge however much
 * of its content came from one.
 *
 * @param array<string, list<string>> $reads
 */
function mergesFreshRead(Node $payload, string $path, array $reads, string $source, NodeFinder $finder): bool
{
    $value = unwrapValue($payload);

    $merges = $value instanceof Node\Expr\Array_
        || $value instanceof Node\Expr\BinaryOp\Plus
        || ($value instanceof Node\Expr\FuncCall && functionName($value) === 'array_merge');

    if (!$merges) {
        return false;
    }

    foreach ($finder->findInstanceOf($value, Node\Expr\FuncCall::class) as $call) {
        $argument = $call->getArgs()[0] ?? null;

        if (functionName($call) === 'file_get_contents' && $argument !== null && nodeText($argument->value, $source) === $path) {
            return true;
        }
    }

    foreach ($finder->findInstanceOf($value, Node\Expr\MethodCall::class) as $call) {
        $called = selfCalledName($call);

        if ($called !== null && in_array($path, $reads[$called] ?? [], true)) {
            return true;
        }
    }

    return false;
}

/**
 * The methods of its own class a method calls, by name.
 *
 * @return list<string>
 */
function selfCalls(Node $method, NodeFinder $finder): array
{
    $names = [];

    foreach ($finder->findInstanceOf($method, Node\Expr\MethodCall::class) as $call) {
        $called = selfCalledName($call);

        if ($called !== null) {
            $names[] = $called;
        }
    }

    return array_values(array_unique($names));
}

/**
 * The method name of a `$this->x()` / `self::x()` / `static::x()` call, or null when the call is on
 * something else — another object's method is not this class's read.
 */
function selfCalledName(Node\Expr\MethodCall $call): ?string
{
    $variable = $call->var;

    $isSelf = $variable instanceof Node\Expr\Variable && $variable->name === 'this';
    $isStatic = $variable instanceof Node\Name
        && in_array(strtolower($variable->toString()), ['self', 'static'], true);

    return ($isSelf || $isStatic) && $call->name instanceof Node\Identifier
        ? $call->name->toString()
        : null;
}

/**
 * The name of a called function, when it is a plain function name: `file_get_contents('x')` has
 * one, `$fn('x')` and `$this->m()` do not.
 */
function functionName(Node\Expr\FuncCall $call): ?string
{
    return $call->name instanceof Node\Name ? strtolower($call->name->toString()) : null;
}

/**
 * The source text of a node, so a path is compared as it is written rather than as a resolved
 * value nothing here can know.
 */
function nodeText(Node $node, string $source): string
{
    $start = $node->getStartFilePos();
    $end = $node->getEndFilePos();

    if ($start < 0 || $end < $start) {
        return '(unknown)';
    }

    return (string) preg_replace('/\s+/', ' ', substr($source, $start, $end - $start + 1));
}

/**
 * Path relative to the package root, for output that does not depend on where
 * the repository is checked out.
 */
function relative(string $root, string $path): string
{
    $path = str_replace('\\', '/', $path);

    return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
}
