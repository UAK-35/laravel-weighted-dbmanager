<?php

/**
 * bin/tools.php — where every tool this package runs is written down once.
 *
 * WHAT IT IS
 * ----------
 *   A map from the name a tool is run under — the name `vendor/bin` gives it — to a record with
 *   four facts about it: which composer package it comes from, which version constraint this
 *   package asks that package to be at, the entry file PHP runs for it, and what it is for.
 *   `bin/tool.php` reads it to run the composer scripts, and `bin/checks.php` reads it both to run
 *   the same tools under its own flags and to report what is installed against each pin.
 *
 * WHY IT EXISTS
 * -------------
 *   These paths used to be written down twice, and nothing compared the two: `composer.json`'s
 *   scripts named the tools bare — `pint`, `phpstan analyse --ansi`, `phpunit
 *   --colors=always` — and left Composer to resolve them against `vendor/bin`, while
 *   `bin/checks.php` held the same four as `$root . '/vendor/…'` strings. A package that moves
 *   its binary, or a tool that starts arriving from another package, then leaves `composer
 *   lint` and `composer checks` running two different builds of one tool, and the way to find
 *   that out is to read both files and notice. One map with two readers makes the
 *   disagreement a diff instead of a discovery.
 *
 * WHAT A RECORD HOLDS
 * -------------------
 *   package  the composer package the tool ships in. It is what a version is asked about: the
 *            installed version is read for this name, and `laravel/pint` is what
 *            `composer why laravel/pint` and every other Composer answer calls it.
 *
 *   pin      the constraint `composer.json`'s `require-dev` states for that package — what this
 *            package asks for, not what a machine happens to have. `null` is a tool that arrives
 *            with something else rather than being required here (symfony/yaml comes in with
 *            testbench), and it is written as `null` rather than guessed so that the table says
 *            which tools this package has an opinion about and which merely come along.
 *
 *   entry    the file PHP runs, relative to this package's root, so nothing here is about the
 *            machine the file is read on.
 *
 *   purpose  one line on what the tool is for — the same sentence `--list` prints, the gate's
 *            summary counts it against, and the README's tools table states. Read by three
 *            callers, written once, which is why it lives here rather than in any of them.
 *
 * WHY THE ENTRY FILE AND NOT THE SHIM
 * -----------------------------------
 *   `vendor/bin` holds a shim per tool: a shell script on Unix, a `.bat` on Windows, plus a
 *   `.bat` for the `cmd.exe` spelling on this platform. None of them can be handed to PHP as
 *   a program, and running one from another process means knowing which platform the caller is
 *   on. The entry file a shim delegates to is the same file everywhere, which is why
 *   `PHP_BINARY <entry file>` is the one form both readers use.
 *
 * WHO KEEPS IT TRUE
 * -----------------
 *   Two guards, because a pin can be wrong about the tree and wrong about the machine, and
 *   neither reader can see both: `tests/Unit/Docs/ToolTableTest.php` compares this map with the
 *   README's tools table and with `composer.json`'s `require-dev`, which are statements about the
 *   tree; the gate's own `tools` check compares each pin with the version Composer reports
 *   installed, which is a statement about the machine and goes stale without a diff when a
 *   constraint is bumped and `composer update` is not run.
 *
 * READ IT WITH `require`
 * ----------------------
 *   It returns an array and runs nothing — no shebang, and no statement but the return — so
 *   `require` is the whole contract. `bin/tool.php` is the runner; this file is the fact.
 *
 * @return array<string, array{package: string, pin: string|null, entry: string, purpose: string}>
 */

return [
    'pint' => [
        'package' => 'laravel/pint',
        'pin' => '^1.25',
        'entry' => 'vendor/laravel/pint/builds/pint',
        'purpose' => 'the formatter behind `composer lint`, and the style gate `composer test:lint` runs',
    ],
    'phpstan' => [
        'package' => 'phpstan/phpstan',
        'pin' => '^2.0',
        'entry' => 'vendor/phpstan/phpstan/phpstan.phar',
        'purpose' => 'static analysis of src/ at the level phpstan.neon.dist asks for',
    ],
    'phpunit' => [
        'package' => 'phpunit/phpunit',
        'pin' => '^11.5',
        'entry' => 'vendor/phpunit/phpunit/phpunit',
        'purpose' => 'the test runner the suite is written for',
    ],
    'yaml-lint' => [
        'package' => 'symfony/yaml',
        'pin' => null,
        'entry' => 'vendor/symfony/yaml/Resources/bin/yaml-lint',
        'purpose' => 'the linter the gate runs over the workflow YAML',
    ],
];
