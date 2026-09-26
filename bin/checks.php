#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/checks.php
 *
 * One entry point for every check this package can run: PHP syntax, an
 * independent AST parse of the same files, the composer.json schema, the
 * platform requirements, the workflow YAML, PHPStan, Pint and PHPUnit.
 *
 * WHY THIS EXISTS IN ADDITION TO `composer test`
 * ----------------------------------------------
 * `composer test` covers the three gates CI enforces — style, types, tests.
 * The rest of what is checked here (a per-file `php -l` pass, a parse of every
 * file by nikic/php-parser, `composer validate --strict`,
 * `composer check-platform-reqs`, the workflow YAML) is otherwise only looked
 * at by hand after something breaks. Running them together gives one command,
 * one summary and one exit code to trust.
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
 */

use PhpParser\ParserFactory;

$root = str_replace('\\', '/', dirname(__DIR__));

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
$composer = composerCommand($root);
$yamlFiles = yamlFiles($root);

$tools = [
    'pint' => $root . '/vendor/laravel/pint/builds/pint',
    'phpstan' => $root . '/vendor/phpstan/phpstan/phpstan.phar',
    'phpunit' => $root . '/vendor/phpunit/phpunit/phpunit',
    'yaml-lint' => $root . '/vendor/symfony/yaml/Resources/bin/yaml-lint',
];

// ─────────────────────────────────────────────────────────────────────────────
// The checks
// ─────────────────────────────────────────────────────────────────────────────

$level = phpstanLevel($root);

$checks = [
    'syntax' => [
        'title' => 'PHP syntax (php -l)',
        'skip' => $phpFiles === [] ? 'no PHP files found' : null,
        'run' => static fn (): array => checkSyntax($root, $phpFiles),
        'note' => static function (string $output) use ($phpFiles): string {
            $failed = preg_match_all('/Errors parsing/', $output);

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
    'schema' => [
        'title' => 'composer.json schema (validate --strict)',
        'skip' => $composer === null ? 'composer is not available (set COMPOSER_BINARY)' : null,
        'run' => static fn (): array => runCommand([...$composer ?? [], 'validate', '--strict'], $root),
    ],
    'platform' => [
        'title' => 'Platform requirements (check-platform-reqs)',
        'skip' => $composer === null ? 'composer is not available (set COMPOSER_BINARY)' : null,
        'run' => static fn (): array => runCommand([...$composer ?? [], 'check-platform-reqs'], $root),
        'note' => static function (string $output): string {
            $satisfied = preg_match_all('/\bsuccess\b/', $output);

            return $satisfied === 0
                ? lastLine($output)
                : "{$satisfied} platform requirements satisfied";
        },
    ],
    'yaml' => [
        'title' => 'Workflow YAML (yaml-lint)',
        'skip' => match (true) {
            $yamlFiles === [] => 'no YAML outside vendor/',
            !is_file($tools['yaml-lint']) => 'symfony/yaml is not installed',
            default => null,
        },
        'run' => static fn (): array => runCommand(
            [PHP_BINARY, $tools['yaml-lint'], '--no-ansi', ...$yamlFiles],
            $root,
        ),
    ],
    'phpstan' => [
        'title' => "Static analysis (phpstan, level {$level})",
        'skip' => is_file($tools['phpstan']) ? null : 'phpstan is not installed',
        'run' => static fn (): array => runCommand(
            [PHP_BINARY, $tools['phpstan'], 'analyse', '--no-progress', '--no-ansi'],
            $root,
        ),
    ],
    'pint' => [
        'title' => 'Code style (pint --test)',
        'skip' => is_file($tools['pint']) ? null : 'laravel/pint is not installed',
        'run' => static fn (): array => runCommand([PHP_BINARY, $tools['pint'], '--test'], $root),
    ],
    'tests' => [
        'title' => 'Unit tests (phpunit)',
        'skip' => is_file($tools['phpunit']) ? null : 'phpunit is not installed',
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

    $note = isset($check['note'])
        ? $check['note'](stripAnsi($result['output']))
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
 * Path relative to the package root, for output that does not depend on where
 * the repository is checked out.
 */
function relative(string $root, string $path): string
{
    $path = str_replace('\\', '/', $path);

    return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
}
