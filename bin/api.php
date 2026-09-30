#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/api.php — publish the record as a page a consumer can read.
 *
 * WHAT THE PAGE IS
 * ----------------
 *   `files.tsv`, `methods.tsv` and `surface.tsv` are the inventory the release
 *   weighing reads: what this package ships, the public methods it declares, and the
 *   keys and members a host application can name besides them. They are written for
 *   a programme — one row per thing, tab-separated — and that is the right shape for
 *   the question a release asks and the wrong one for the question a consumer asks,
 *   which is "is this name part of the published API?". This command renders the same
 *   rows as `API.md`: one section per class, one row per method, the config keys and
 *   env vars grouped by the file that declares them, and a summary table whose counts
 *   are the record's own.
 *
 *   That is the whole argument for generating it rather than writing a second
 *   document by hand: the page cannot describe a different package from the one the
 *   weighing reads, because it is the same rows. A method renamed six releases ago
 *   cannot still be on it, and a count that disagrees with the rows below it cannot be
 *   printed — so the rows do double duty as published documentation.
 *
 * WHAT IT IS NOT
 * --------------
 *   A behaviour guide. The reading is syntactic, from the files the package ships, so
 *   this is a list of names: a method a parent class declares is listed under the class
 *   that declares it, a member marked `@internal` still counts, and the page says as
 *   much in its own closing section.
 *
 * USAGE
 * -----
 *   php bin/api.php                 write API.md from the record on disk
 *   php bin/api.php --check         compare, write nothing, exit 1 when out of step
 *   php bin/api.php --root=PATH     run against another checkout
 *   php bin/api.php --help
 *
 *   The inventory is written by the release script, so this page is refreshed in the
 *   same commit: run it by hand only when you mean to commit the page it writes.
 *
 * EXIT CODES
 * ----------
 *   0 written, or already current under --check; 1 the record is missing or names more
 *   than one tree, or the page is out of step; 2 usage error.
 */

require_once __DIR__ . '/surface.php';

if (apiInvoked()) {
    $root = str_replace('\\', '/', dirname(__DIR__));
    $check = false;

    foreach (array_slice($_SERVER['argv'], 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            apiUsage();
            exit(0);
        }

        if ($argument === '--check') {
            $check = true;

            continue;
        }

        if (str_starts_with($argument, '--root=')) {
            $root = rtrim(str_replace('\\', '/', substr($argument, 7)), '/');

            continue;
        }

        fwrite(STDERR, "Unknown option: {$argument}" . PHP_EOL . PHP_EOL);
        apiUsage();
        exit(2);
    }

    if (!is_dir($root . '/src')) {
        fwrite(STDERR, "Not a package root — no src/ under {$root}." . PHP_EOL);
        exit(1);
    }

    exit(apiMain($root, $check));
}

/**
 * The record, read back from the three files on disk.
 *
 * The stamp is checked as well as the rows, and it is the reason this refuses rather
 * than renders: one page cannot describe three trees, so three files whose headers
 * name different releases are a record that was written at three moments and is not
 * one release's. The missing-file case is refused too, because a renderer that treated
 * an absent file as an empty list would publish a package with no API and exit 0.
 *
 * @return array{stamp: string, files: list<array<string, string>>, methods: list<array<string, string>>, surface: list<array<string, string>>}
 */
function apiRecord(string $root): array
{
    $paths = inventoryPaths($root);

    $read = [];

    foreach (['files', 'methods', 'surface'] as $name) {
        $record = readInventory($paths[$name]);

        if ($record === null) {
            throw new RuntimeException(sprintf(
                '%s is not there, so there is no record to publish — run php bin/inventory.php --at=REF to write it',
                basename($paths[$name]),
            ));
        }

        $read[$name] = $record;
    }

    $stamps = array_map(static fn (array $record): string => $record['stamp'], $read);

    if (count(array_unique($stamps)) !== 1) {
        throw new RuntimeException(sprintf(
            'the three files describe different trees (%s), and one page cannot describe three',
            implode(', ', array_map(
                static fn (string $name, string $stamp): string => $name . ' ' . $stamp,
                array_keys($stamps),
                array_values($stamps),
            )),
        ));
    }

    return [
        'stamp' => $read['files']['stamp'],
        'files' => $read['files']['rows'],
        'methods' => $read['methods']['rows'],
        'surface' => $read['surface']['rows'],
    ];
}

/**
 * The page as bytes: the whole of API.md, from the record.
 */
function apiReport(string $root): string
{
    return apiMarkdown(apiRecord($root));
}

/**
 * Where the page lives, so the writer and the checker name one path.
 */
function apiReportPath(string $root): string
{
    return $root . '/API.md';
}

/**
 * The page, assembled from blocks and joined with one blank line between them.
 *
 * Blocks rather than line-by-line appends because a section — a class, a table, a
 * paragraph — is joined inside by single newlines and separated from the next by a
 * blank line, and the two must not be confused.
 *
 * @param array{stamp: string, files: list<array<string, string>>, methods: list<array<string, string>>, surface: list<array<string, string>>} $record
 */
function apiMarkdown(array $record): string
{
    $classes = apiClasses($record['files'], $record['methods']);
    $surface = $record['surface'];
    $stamp = $record['stamp'];

    $blocks = [];

    $blocks[] = '# Public API';
    $blocks[] = '<!-- Generated by `php bin/api.php` from files.tsv, methods.tsv and surface.tsv. Do not edit by hand. -->';
    $blocks[] = "What a host application can name, read out of the same inventory the release weighing reads. It\n"
        . "describes the tree at **{$stamp}**, so every row below is a member of that\n"
        . 'release: a name that is not on this page is not part of the published API.';

    $counts = ["| the record | rows |", '| --- | ---: |'];

    foreach (apiCounts($record) as $label => $count) {
        $counts[] = sprintf('| %s | %d |', $label, $count);
    }

    $blocks[] = implode("\n", $counts);

    $blocks[] = '## Configuration';
    $blocks[] = "The keys the shipped config files return and the environment variables they read, grouped by\n"
        . "the file that declares them. These are configuration rather than API: a key that disappears\n"
        . 'from here is a key a deployed config stops receiving.';
    $blocks[] = '### Config keys';
    $blocks[] = apiTwoColumnTable(apiOfKind($surface, 'config'), 'key', 'declared by');
    $blocks[] = '### Environment variables';
    $blocks[] = apiTwoColumnTable(apiOfKind($surface, 'env'), 'variable', 'read by');

    $blocks[] = '## PHP API';
    $blocks[] = sprintf('%d classes, interfaces, traits and enums.', count($classes));

    $table = ["| class | methods | file |", '| --- | ---: | --- |'];

    foreach ($classes as $class) {
        $table[] = sprintf('| `%s` | %d | `%s` |', $class['class'], $class['methods'], $class['path']);
    }

    $blocks[] = implode("\n", $table);

    $blocks[] = "A method's arguments are the shape the inventory compares releases on — how many are\n"
        . 'required of how many it takes, then each argument as it is declared, with a trailing `=`' . "\n"
        . "marking a default. The type is there and the contract is not, so the guides above this page\n"
        . 'are still the place to read what a call means.';

    foreach (apiNamespaces($classes) as $namespace => $group) {
        $blocks[] = sprintf('### `%s`', $namespace);

        foreach ($group as $class) {
            $blocks[] = apiClassSection($class, $record['methods'], $surface);
        }
    }

    $blocks[] = '## What this page is not';
    $blocks[] = 'The record is read syntactically from the files the package ships, so this is a list of names' . "\n"
        . "rather than a behaviour guide. A method declared by a parent class or a trait is listed under\n"
        . 'the class that declares it, not under every class that inherits or uses it; a member marked' . "\n"
        . '`@internal` still counts; and properties and constants a consumer can see but the reader could' . "\n"
        . 'not name are absent. What it is for is the question a release asks — did a name a host' . "\n"
        . 'application depends on move — and for that, a syntactic reading is the correct one, because it' . "\n"
        . "is what a consumer's compiler sees.";

    return implode("\n\n", $blocks) . "\n";
}

/**
 * One class, the file it lives in, its own method rows and its members — the page's
 * unit, and the one a reader navigates by.
 *
 * @param array{class: string, path: string, methods: int} $class
 * @param list<array<string, string>> $methods
 * @param list<array<string, string>> $surface
 */
function apiClassSection(array $class, array $methods, array $surface): string
{
    $fqn = $class['class'];

    $own = array_values(array_filter(
        $methods,
        static fn (array $method): bool => ($method['class'] ?? '') === $fqn,
    ));

    $lines = [
        sprintf('#### `%s`', $fqn),
        '',
        sprintf('`%s`', $class['path']),
        '',
    ];

    if ($own === []) {
        $lines[] = 'No public methods of its own.';
    } else {
        $lines[] = '| method | requires | arguments |';
        $lines[] = '| --- | ---: | --- |';

        foreach ($own as $method) {
            $shape = apiShape((string) ($method['signature'] ?? ''));

            $lines[] = sprintf(
                '| `%s()` | %s | %s |',
                (string) ($method['method'] ?? ''),
                $shape['requires'],
                $shape['arguments'] === '' ? '—' : '`' . $shape['arguments'] . '`',
            );
        }
    }

    $members = apiMembers($surface, $fqn);

    if ($members !== []) {
        $lines[] = '';
        $lines[] = 'Members: ' . implode(', ', array_map(
            static fn (string $member): string => '`' . $member . '`',
            $members,
        )) . '.';
    }

    return implode("\n", $lines);
}

/**
 * The classes the page lists, in the order the record holds its files — the path sort —
 * each with the count of method rows that name it.
 *
 * The two config files ship no class and are skipped here; they are counted in the
 * summary as files and their keys are what the Configuration section is.
 *
 * @param list<array<string, string>> $files
 * @param list<array<string, string>> $methods
 * @return list<array{class: string, path: string, methods: int}>
 */
function apiClasses(array $files, array $methods): array
{
    $counts = [];

    foreach ($methods as $method) {
        $class = (string) ($method['class'] ?? '');

        if ($class !== '') {
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }
    }

    $classes = [];

    foreach ($files as $file) {
        $symbol = (string) ($file['symbol'] ?? '');

        if ($symbol === '' || $symbol === '(none)') {
            continue;
        }

        $classes[] = [
            'class' => $symbol,
            'path' => (string) ($file['path'] ?? ''),
            'methods' => $counts[$symbol] ?? 0,
        ];
    }

    return $classes;
}

/**
 * The classes grouped by the namespace before the last backslash, the namespaces sorted
 * and each group sorted inside it.
 *
 * @param list<array{class: string, path: string, methods: int}> $classes
 * @return array<string, list<array{class: string, path: string, methods: int}>>
 */
function apiNamespaces(array $classes): array
{
    $namespaces = [];

    foreach ($classes as $class) {
        $cut = strrpos($class['class'], '\\');
        $namespace = $cut === false ? '' : substr($class['class'], 0, $cut);

        $namespaces[$namespace][] = $class;
    }

    ksort($namespaces);

    foreach ($namespaces as $namespace => $group) {
        usort($group, static fn (array $a, array $b): int => $a['class'] <=> $b['class']);
        $namespaces[$namespace] = $group;
    }

    return $namespaces;
}

/**
 * The rows of one kind, in the order the record holds them — grouped by kind already,
 * so a caller reads one kind's rows in the order they were written.
 *
 * @param list<array<string, string>> $surface
 * @return list<array<string, string>>
 */
function apiOfKind(array $surface, string $kind): array
{
    return array_values(array_filter(
        $surface,
        static fn (array $row): bool => ($row['kind'] ?? '') === $kind,
    ));
}

/**
 * A two-column table — a name and the file that declares it — which is exactly the
 * shape of both the config-key and env-var sections.
 *
 * @param list<array<string, string>> $rows
 */
function apiTwoColumnTable(array $rows, string $first, string $second): string
{
    $lines = [
        sprintf('| %s | %s |', $first, $second),
        '| --- | --- |',
    ];

    foreach ($rows as $row) {
        $lines[] = sprintf('| `%s` | `%s` |', (string) ($row['symbol'] ?? ''), (string) ($row['file'] ?? ''));
    }

    return implode("\n", $lines);
}

/**
 * The members a class declares: its constants, enum cases and public properties, in the
 * record's order — kind first, so the constants read as a block and the properties after
 * them.
 *
 * @param list<array<string, string>> $surface
 * @return list<string>
 */
function apiMembers(array $surface, string $class): array
{
    $members = [];

    foreach ($surface as $row) {
        if (!in_array((string) ($row['kind'] ?? ''), ['const', 'case', 'property'], true)) {
            continue;
        }

        $symbol = (string) ($row['symbol'] ?? '');

        if (apiOwner($symbol) !== $class) {
            continue;
        }

        $members[] = apiMemberName($symbol);
    }

    return $members;
}

/**
 * The class a `Class::MEMBER` symbol belongs to, or the empty string when it names none.
 */
function apiOwner(string $symbol): string
{
    $cut = strrpos($symbol, '::');

    return $cut === false ? '' : substr($symbol, 0, $cut);
}

/**
 * The member half of a `Class::MEMBER` symbol.
 */
function apiMemberName(string $symbol): string
{
    $cut = strrpos($symbol, '::');

    return $cut === false ? $symbol : substr($symbol, $cut + 2);
}

/**
 * The summary table's numbers, every one of them the record's own count rather than a
 * number written here: a page that dropped a row would disagree with itself in the same
 * table a reader trusts.
 *
 * @param array{stamp: string, files: list<array<string, string>>, methods: list<array<string, string>>, surface: list<array<string, string>>} $record
 * @return array<string, int>
 */
function apiCounts(array $record): array
{
    return [
        'files shipped' => count($record['files']),
        'public methods' => count($record['methods']),
        'config keys' => count(apiOfKind($record['surface'], 'config')),
        'public constants' => count(apiOfKind($record['surface'], 'const')),
        'environment variables read' => count(apiOfKind($record['surface'], 'env')),
        'public properties' => count(apiOfKind($record['surface'], 'property')),
        'enum cases' => count(apiOfKind($record['surface'], 'case')),
    ];
}

/**
 * A stored signature split into the two facts the page prints: how many of how many
 * arguments are required, and the arguments as they are declared.
 *
 * The signature is `required/total` then a space then the argument list, so the split is
 * at the first space — and a bare `0/0` with no list at all is the empty second half
 * rather than a missing one.
 *
 * @return array{requires: string, arguments: string}
 */
function apiShape(string $signature): array
{
    $space = strpos($signature, ' ');

    if ($space === false) {
        $shape = $signature;
        $arguments = '';
    } else {
        $shape = substr($signature, 0, $space);
        $arguments = substr($signature, $space + 1);
    }

    [$required, $total] = array_pad(explode('/', $shape, 2), 2, '0');

    return ['requires' => $required . ' of ' . $total, 'arguments' => $arguments];
}

/**
 * Whether this file is the one being run, rather than included by a test.
 *
 * The comparison is against `__FILE__` because `$argv[0]` is whatever the caller typed,
 * which on Windows may be a forward-slash path, a short path or a different case for the
 * same file. `realpath()` answers "which file is this", which is the question.
 */
function apiInvoked(): bool
{
    $script = $_SERVER['argv'][0] ?? '';

    if ($script === '') {
        return false;
    }

    $real = realpath($script);
    $self = realpath(__FILE__);

    if ($real === false || $self === false) {
        return false;
    }

    return str_replace('\\', '/', $real) === str_replace('\\', '/', $self);
}

/**
 * Write the page, or answer for it under `--check`.
 */
function apiMain(string $root, bool $check): int
{
    try {
        $record = apiRecord($root);
    } catch (RuntimeException $error) {
        fwrite(STDERR, PHP_EOL . '✗ Nothing to publish: ' . $error->getMessage() . '.' . PHP_EOL . PHP_EOL);

        return 1;
    }

    $page = apiMarkdown($record);
    $path = apiReportPath($root);
    $described = sprintf(
        '%d classes, %d public methods, described as %s',
        count(apiClasses($record['files'], $record['methods'])),
        count($record['methods']),
        $record['stamp'],
    );

    if ($check) {
        $onDisk = @file_get_contents($path);

        if ($onDisk !== false && str_replace(["\r\n", "\r"], "\n", $onDisk) === $page) {
            apiNote('api report is current — ' . $described);

            return 0;
        }

        fwrite(STDERR, PHP_EOL . '✗ The API report is out of step with the record:' . PHP_EOL . PHP_EOL);
        fwrite(STDERR, '    ' . basename($path) . ': ' . apiDrift(
            $onDisk === false ? '' : str_replace(["\r\n", "\r"], "\n", $onDisk),
            $page,
        ) . PHP_EOL);
        fwrite(STDERR, PHP_EOL . 'Run php bin/api.php to write it and commit it.' . PHP_EOL . PHP_EOL);

        return 1;
    }

    if (@file_put_contents($path, $page) === false) {
        fwrite(STDERR, '✗ Could not write ' . basename($path) . ' under ' . $root . '.' . PHP_EOL);

        return 1;
    }

    apiNote(basename($path) . ' — ' . $described);
    apiNote('stage it with: git add -- ' . basename($path));

    return 0;
}

/**
 * How the page on disk differs from the one the record renders — counts and the first
 * few lines either side, enough to see what moved without printing a whole file.
 */
function apiDrift(string $onDisk, string $expected): string
{
    if ($onDisk === '') {
        return 'the file is not there';
    }

    $have = lines($onDisk);
    $want = lines($expected);

    // Every line is the one that belongs, so a difference is in the bytes around them:
    // a blank line or a trailing space, not the rows. Not line endings — the caller
    // normalised those away.
    if ($have === $want) {
        return 'the rows are right but the bytes are not: a blank line or a trailing space somewhere (rewrite it to pin the file)';
    }

    $removed = array_values(array_diff($have, $want));
    $added = array_values(array_diff($want, $have));

    $parts = [sprintf('%d line(s) stale: %d gone, %d new', max(count($removed), count($added)), count($removed), count($added))];

    foreach (array_slice($removed, 0, 2) as $line) {
        $parts[] = 'gone: ' . apiShorter($line);
    }

    foreach (array_slice($added, 0, 2) as $line) {
        $parts[] = 'new:  ' . apiShorter($line);
    }

    return implode('; ', $parts);
}

function apiShorter(string $line): string
{
    return strlen($line) > 68 ? substr($line, 0, 67) . '…' : $line;
}

function apiNote(string $message): void
{
    echo '• ' . $message . PHP_EOL;
}

function apiUsage(): void
{
    echo <<<'TXT'
    bin/api.php — publish the inventory as API.md, the page a consumer reads.

    Usage:
      php bin/api.php [options]

    Options:
          --check          Compare with the record, write nothing, exit 1 when out of step.
          --root=PATH      Package root to describe (default: the parent of bin/).
      -h, --help           Show this help.

    The release script refreshes this page in the release commit, so running it by hand
    is for seeing what it would publish.

    TXT;
}
