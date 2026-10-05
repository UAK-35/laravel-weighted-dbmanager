#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/tool.php — run an installed tool by the name `bin/tools.php` gives it.
 *
 * WHY THIS EXISTS
 * ---------------
 *   A composer script can only name a command; it cannot read a file. So the paths live in
 *   `bin/tools.php` and every script that runs a tool goes through here:
 *
 *     composer lint        →  php bin/tool.php pint
 *     composer test:lint   →  php bin/tool.php pint --test
 *     composer test:types  →  php bin/tool.php phpstan analyse --ansi
 *     composer test:unit   →  php bin/tool.php phpunit --colors=always
 *
 *   A script that named its tool's path itself — `vendor/bin/pint`, say — would be a second
 *   copy of the manifest's one fact, and `composer lint` and `composer checks` would run two
 *   installs of one tool the first time a package moved its binary. Including the arguments:
 *   the ones written in composer.json are the ones a person runs by hand, which is what makes
 *   `composer test:types` reproducible as a single command.
 *
 * USAGE
 * -----
 *   php bin/tool.php <tool> [arguments …]   run it, passing every argument after the name on
 *   php bin/tool.php --list                 the tools this package installs, with the pin each
 *                                           is on and what it is for
 *   php bin/tool.php --help
 *
 * EXIT CODES
 * ----------
 *   0..n   the tool's own exit code, unchanged, so `composer test:unit` fails exactly when
 *          PHPUnit does and no wrapper can turn a failure into a pass
 *   1      the tool is not installed at the entry file the manifest names
 *   2      a usage error: no tool named, or a name that is not in the manifest
 *
 *   One and two are apart on purpose. A missing tool is a checkout nobody has run `composer
 *   install` in — the manifest is right and the fix is one command. An unknown name is a typo
 *   or a tool that has left the manifest, and no install fixes it.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 *   It captures nothing. stdin, stdout and stderr are handed to the child as this process's
 *   own, so a tool prints as it goes rather than at the end, sees the terminal it was started
 *   from, and colours its output accordingly — the behaviour of having run the entry file
 *   with PHP yourself, which is the whole point of the wrapper being this thin.
 *
 * PORTABILITY
 * -----------
 *   The command is an array, so no shell is involved: no quoting rules to get wrong on Windows,
 *   and no shell profile to depend on. The tool is invoked as `PHP_BINARY <entry file>` rather
 *   than through the `vendor/bin` shim, for the reason written down in `bin/tools.php`.
 */

$root = str_replace('\\', '/', dirname(__DIR__));

/**
 * The manifest: tool name => [package, pin, entry file, purpose], the entry relative to the
 * package root. See bin/tools.php, which is the only place any of the four is written down.
 *
 * @var array<string, array{package: string, pin: string|null, entry: string, purpose: string}> $tools
 */
$tools = require __DIR__ . '/tools.php';

$arguments = array_slice($argv, 1);

if ($arguments === []) {
    usage($tools);
    exit(2);
}

if ($arguments[0] === '--help' || $arguments[0] === '-h') {
    usage($tools);
    exit(0);
}

if ($arguments[0] === '--list') {
    // Two lines per tool: the name, the package and the pin it is asked to be at, and the entry
    // file PHP runs — then the one sentence the manifest keeps on what it is for. The suite and
    // the README's tools table read the same sentence, so this is the shape a person sees of a
    // table three guards are held to.
    foreach ($tools as $name => $tool) {
        printf(
            "%-10s %-24s %s%s",
            $name,
            $tool['package'] . ' ' . ($tool['pin'] ?? '(no pin)'),
            $tool['entry'],
            PHP_EOL,
        );

        printf("%-10s %s%s", '', $tool['purpose'], PHP_EOL);
    }

    exit(0);
}

$name = array_shift($arguments);

if (!array_key_exists($name, $tools)) {
    fwrite(STDERR, "Unknown tool: {$name}" . PHP_EOL . PHP_EOL);
    usage($tools);
    exit(2);
}

$entry = $root . '/' . $tools[$name]['entry'];

if (!is_file($entry)) {
    fwrite(STDERR, sprintf(
        "%s is not installed: no %s. Run `composer install`." . PHP_EOL,
        $name,
        $tools[$name]['entry'],
    ));

    exit(1);
}

exit(run([PHP_BINARY, $entry, ...$arguments], $root));

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run the tool, with this process's own streams.
 *
 * The child inherits stdin, stdout and stderr rather than being given pipes, and that is the
 * difference between a run that prints as it happens and one whose output arrives in a block:
 * PHPUnit's progress and a formatting diff both read as they go, a tool that colourises checks
 * whether stdout is a terminal, and anything a tool prints itself — a refusal, a prompt — is
 * not held back behind a buffer this script would have to flush. Nothing is read back, so
 * there is no output for a caller to summarise either: the exit code is the report.
 *
 * @param list<string> $command
 */
function run(array $command, string $cwd): int
{
    $process = @proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);

    if (!is_resource($process)) {
        fwrite(STDERR, 'Could not start: ' . implode(' ', $command) . PHP_EOL);

        return 127;
    }

    return proc_close($process);
}

/**
 * What this script is and which names it takes, in the shape `bin/checks.php` prints its own.
 *
 * The names are listed rather than the paths: a caller who has typed one wrong wants to see
 * what the manifest holds, and `--list` is the answer that shows the paths themselves.
 *
 * @param array<string, string> $tools
 */
function usage(array $tools): void
{
    echo <<<'TXT'
    bin/tool.php — run an installed tool by the name bin/tools.php gives it.

    Usage:
      php bin/tool.php <tool> [arguments …]   run it, passing the arguments through
      php bin/tool.php --list                 the tools this package installs
      php bin/tool.php --help                 this text

    TXT;

    echo 'Tools: ' . implode(', ', array_keys($tools)) . PHP_EOL;
}
