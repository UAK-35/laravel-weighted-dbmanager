#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/publish-config.php
 *
 * Publishes this package's config into a Laravel application, as the
 * `db-manager-config` tag:
 *
 *   php bin/publish-config.php [app-path]
 *
 * From an application root the path may be omitted. From anywhere else — which
 * is what a linked checkout looks like — pass the application directory:
 *
 *   php bin/publish-config.php ../../site
 *
 * WHY THIS IS A SCRIPT AND NOT JUST A COMPOSER EVENT
 * --------------------------------------------------
 * Composer only executes the scripts of the *root* package: a script declared
 * in this file's composer.json does not run when an application requires this
 * package as a dependency. The `post-install-cmd` / `post-update-cmd` entries
 * here therefore cover a checkout of this package, and consuming applications
 * declare the call themselves, from their own composer.json:
 *
 *   "@php vendor/uak35/laravel-weighted-dbmanager/bin/publish-config.php"
 *
 * CREATE-IF-MISSING, NEVER OVERWRITE
 * ----------------------------------
 * `vendor:publish` without --force copies a file only when the destination does
 * not exist; when it does, the framework reports SKIPPED and leaves it alone.
 * That is the behaviour the package's setup depends on: the published copy is
 * the application's configuration and the installer edits it, so running this
 * on every install must never write over those edits. Re-publishing on purpose
 * is a `php artisan vendor:publish --tag=db-manager-config --force` away.
 *
 * USAGE
 * -----
 *   php bin/publish-config.php                publish into the current directory
 *   php bin/publish-config.php path/to/app    publish into another application
 *   php bin/publish-config.php --help
 *
 * Exit code is 0 when the config is in place (published now or already there),
 * 1 when artisan failed, 2 for a usage error.
 */

$arguments = array_slice($argv, 1);

if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    echo <<<'HELP'
    Publish db-manager-config into a Laravel application.

    Usage:
      php bin/publish-config.php [app-path]

    Arguments:
      app-path    Directory holding the application's artisan file.
                  Defaults to the current working directory.

    The file is only written when it is missing, so an application's own
    config/db-manager.php is never overwritten by this script.

    HELP, PHP_EOL;

    exit(0);
}

$path = $arguments[0] ?? (string) getcwd();
$resolved = realpath($path);

if ($resolved === false) {
    fwrite(STDERR, "publish-config: no such directory: {$path}" . PHP_EOL);
    fwrite(STDERR, 'Usage: php bin/publish-config.php [app-path]' . PHP_EOL);

    exit(2);
}

$app = rtrim(str_replace('\\', '/', $resolved), '/');

if (!is_file($app . '/artisan')) {
    // Not an application: a checkout of this package, most likely, where the
    // events that call this script fire but there is nothing to publish into.
    echo "publish-config: {$app} is not a Laravel application (no artisan file) — nothing to publish." . PHP_EOL;

    exit(0);
}

if (!is_file($app . '/vendor/autoload.php')) {
    fwrite(STDERR, "publish-config: {$app} has no vendor/autoload.php — run `composer install` there first." . PHP_EOL);

    exit(1);
}

if (!is_dir($app . '/vendor/uak35/laravel-weighted-dbmanager')) {
    echo "publish-config: {$app} does not have uak35/laravel-weighted-dbmanager installed — nothing to publish." . PHP_EOL;

    exit(0);
}

$command = escapeshellarg(PHP_BINARY)
    . ' artisan vendor:publish --tag=db-manager-config --ansi';

$previousDirectory = (string) getcwd();
chdir($app);

passthru($command, $status);

chdir($previousDirectory);

if ($status !== 0) {
    fwrite(STDERR, "publish-config: artisan exited {$status} — config/db-manager.php not published." . PHP_EOL);

    exit(1);
}

$config = $app . '/config/db-manager.php';

if (is_file($config)) {
    echo "publish-config: {$config} is in place." . PHP_EOL;

    exit(0);
}

// The tag comes from the provider's boot(), so no tag means the provider is not
// registered — the one step that does not happen on its own.
fwrite(STDERR, 'publish-config: no db-manager-config tag resolved — is '
    . 'WeightedDatabaseServiceProvider registered in the application\'s config/app.php?' . PHP_EOL);

exit(1);
