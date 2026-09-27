<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | SWRR global settings
    |--------------------------------------------------------------------------
    |
    | This is the SAMPLE config. The values below show the shape of the config
    | rather than the values any particular application should run on, so every
    | install publishes this file into its own config/ and edits the published
    | copy — which then shadows this one.
    |
    | Publish it with:
    |   php artisan vendor:publish --tag=db-manager-config
    | and edit config/db-manager.php in the application, not this file.
    |
    | The 'swrr' block is global. Per-connection tunables (weight_formula,
    | weight_cpu_factor, weight_ram_factor) live on each entry of
    | config/database.php → 'connections', next to the 'read' list whose replicas
    | are being weighted.
    |
    */

    'swrr' => [
        // ── Active connection (optional) ─────────────────────────────────────────
        //
        // The connection the pgcat flipper follows, the pgcat gate judges and
        // `db:doctor` reports. Left unset, the package follows `database.default`,
        // which is right when the application's real data path *is* the default
        // connection. An application whose PostgreSQL path is a non-default
        // connection — e.g. one named by `app.default_api_connection` (env
        // `API_DB_CONNECTION`) while `database.default` stays on SQLite — names that
        // connection here, so the pgcat surfaces judge the connection queries run on.
        'connection' => env('SWRR_CONNECTION'),

        'primary_store' => env('DB_STORE_PRIMARY', 'redis'),   // 'redis' or 'local'
        'redis_connection' => env('SWRR_REDIS_CONNECTION', 'default'),
        'state_ttl' => (int) env('SWRR_STATE_TTL', 86400),
        'key_prefix' => env('SWRR_KEY_PREFIX', 'swrr'),
        // Deliberately NOT wrapped in (bool): `(bool) 'off'` is true, so casting here
        // would turn the spellings an operator actually writes into their opposite
        // before the package ever saw them. `env()` already answers a real bool for
        // 'true'/'false' and passes everything else through as written, which is what
        // Support\SwitchValue reads — '1'/'0', 'on'/'off', 'yes'/'no' — and refuses
        // (at boot, and as a db:doctor row) when it is none of them.
        'allow_local_fallback' => env('SWRR_ALLOW_LOCAL_FALLBACK', true),
        // ── Formula tunables (required, applies all connections) ─────────────────
        'default_weight_cpu_factor' => (float) env('DB_DEFAULT_WEIGHT_CPU_FACTOR', 3.0),
        'default_weight_ram_factor' => (float) env('DB_DEFAULT_WEIGHT_RAM_FACTOR', 3.375),
        'default_weight_formula' => env('DB_WEIGHT_FORMULA', 'linear'),   // 'linear' | 'diminishing'

        // ── Windowed read fallback (optional) ────────────────────────────────────
        //
        // Outside of every reader_window, on a reader_day, reads are served by the
        // writer instead of the weighted replica pool. Window start is inclusive,
        // window end is exclusive. Leave reader_windows unset and the resolver is
        // permissive: replicas are always used.
        //
        'reader_windows' => [
            ['start' => '10:00:00', 'end' => '14:20:00'],
            ['start' => '17:00:00', 'end' => '20:30:00'],
        ],
        'reader_days' => [1, 2, 3, 4, 5],   // ISO-8601: 1=Mon … 7=Sun
        'timezone' => 'UTC',

        // ── Pgcat toml flipper (optional) ────────────────────────────────────────
        //
        // Keeps pgcat.toml in lock-step with the reader/writer mode decided above.
        // Set 'enabled' => false to keep the resolver wiring but never touch pgcat.
        //
        'pgcat' => [
            // Same rule as allow_local_fallback above, and it matters more here: this
            // switch arms a file swap. A cast would read 'off' as on.
            'enabled' => env('SWRR_PGCAT_ENABLED', false),
            'config_path' => env('SWRR_PGCAT_CONFIG', '/etc/pgcat/pgcat.toml'),
            'readers_path' => env('SWRR_PGCAT_READERS', '/etc/pgcat/pgcat-readers.toml'),
            'no_readers_path' => env('SWRR_PGCAT_NO_READERS', '/etc/pgcat/pgcat-no-readers.toml'),
            // The program name is quoted because the command is a shell command line:
            // an unquoted pgcat:* is a glob the shell expands, and the name has to match
            // what supervisord runs — "pgcat:*" addresses every program in a group
            // called pgcat (the [program:pgcat] section above is one), a single program
            // by its own name. `php artisan db:doctor` checks both.
            'restart_command' => 'supervisorctl restart "pgcat:*"',
            'reload_command' => 'supervisorctl signal HUP "pgcat:*"',
            // Read as a switch too, so 'yes'/'no' work and a value that is neither is
            // refused rather than cast. true = the reload command, false = the restart one.
            'use_reload' => true, // use reload command (true) or restart command (false)
            'state_file' => sys_get_temp_dir() . '/pgcat-flip-state.json',
            'lock_file' => sys_get_temp_dir() . '/pgcat-flip.lock',
        ],

        // ── Boot self-audit (optional) ───────────────────────────────────────────
        //
        // At boot the provider logs the settings that read as on while doing nothing:
        // pgcat switched on where it cannot act, a reader fallback that can never
        // apply, a primary store that is not the one running, a weight formula this
        // package does not have. Each warning is closed out by the boot that sees the
        // finding gone, and what still stands is remembered in `file`.
        //
        // `store_probe_seconds` is the only entry that costs anything: it is how often
        // the primary store may be PINGed, which is what keeps the probe off the
        // request path under PHP-FPM (0 never probes).
        'audit' => [
            'file' => env('SWRR_AUDIT_FILE', sys_get_temp_dir() . '/swrr-audit.json'),
            'store_probe_seconds' => (int) env('SWRR_AUDIT_STORE_PROBE_SECONDS', 60),
        ],
    ],

];
