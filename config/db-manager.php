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
            // What a flip runs when supervisord reports pgcat is *not running* (STARTING, BACKOFF,
            // EXITED, FATAL). Neither command above can help there — `restart` and `signal HUP`
            // both answer `ERROR (not running)` for a program that is down — so the flip starts it
            // instead, and this is the one command it can run. Left unset, it is derived from the
            // command above with `start` in the verb position, which keeps the binary, the group
            // name and the quoting that were already judged to resolve.
            'start_command' => env('SWRR_PGCAT_START_COMMAND', 'supervisorctl start "pgcat:*"'),
            // How hard the repair tries: `start_attempts` starts, `start_retry_delay_ms` between
            // them, with pgcat's state read back after each one. More than one attempt because a
            // crash-looping program needs the start *and* the seconds supervisor's own
            // `startsecs` takes to bring it up; the file stays in place if they all fail, and no
            // mode is recorded, so the next poll tries again.
            'start_attempts' => (int) env('SWRR_PGCAT_START_ATTEMPTS', 3),
            'start_retry_delay_ms' => (int) env('SWRR_PGCAT_START_RETRY_DELAY_MS', 2000),
            // Read as a switch too, so 'yes'/'no' work and a value that is neither is
            // refused rather than cast. true = the reload command, false = the restart one.
            'use_reload' => true, // use reload command (true) or restart command (false)
            'state_file' => sys_get_temp_dir() . '/pgcat-flip-state.json',
            'lock_file' => sys_get_temp_dir() . '/pgcat-flip.lock',

            // ── The boot window ─────────────────────────────────────────────────────
            //
            // How long this container may spend getting pgcat into a working shape before the
            // flip stops trying and `/health/db` reports the container as failed. Without a
            // bound, a pooler that will never come up is retried every minute for as long as
            // the container lives, and a container nobody replaced looks busy instead of broken.
            //
            // `flip_window_seconds` is the window *in seconds* — the shipped value is 480 (eight
            // minutes) and `SWRR_PGCAT_FLIP_WINDOW_SECONDS=600` raises it to ten. Eight because
            // ECS reports a healthy container within six or seven minutes of task start, so a
            // shorter window would declare a container failed while it was still legitimately
            // coming up, and because eight per-minute attempts is far more than a working flip
            // needs — a failing container gets its 7 or 8 tries, a working one converges on the
            // first or second. Change it here, or in the class that owns the default —
            // `Uak35\WeightedDbManager\Pgcat\FlipWindow::DEFAULT_SECONDS`, which is where the
            // reasoning behind the number is written down. A zero or negative value is refused
            // and replaced by that default rather than meaning "no window".
            'flip_window_seconds' => (int) env('SWRR_PGCAT_FLIP_WINDOW_SECONDS', 480),

            // Where `entrypoint.sh` stamps the container's boot time (`date +%s`), which is what
            // the window is measured from. Deliberately not the PHP process start: every Octane
            // and queue worker has its own, and the whole point is a moment the container cannot
            // re-begin. With no stamp the window is *not judged* — `closed()` stays false and
            // nothing fails — so a local run or an older image is quiet instead of guessing.
            'boot_file' => env('SWRR_PGCAT_BOOT_FILE', sys_get_temp_dir() . '/container-booted-at'),
        ],

        // ── Boot self-audit (optional) ───────────────────────────────────────────
        //
        // At boot the provider logs the settings that read as on while doing nothing:
        // pgcat switched on where it cannot act, a reader fallback that can never
        // apply, a primary store that is not the one running, a weight formula this
        // package does not have. Each warning is closed out by the boot that sees the
        // finding gone, and what still stands is remembered in `file`.
        //
        // `file` is one record per installation, written by every boot of it, so the
        // read-merge-write is taken under an advisory lock on `file`.lock — a companion
        // empty file the kernel releases when the process ends however it ends, left in
        // place on purpose. It is not a sentinel and is not meant to be deleted by hand:
        // nothing holds it but an open descriptor, so a worker killed mid-write leaves it
        // behind and the next boot simply takes it. The directory `file` names therefore
        // has to accept a file, or the audit's findings are logged and never remembered;
        // `db:doctor` fails on that, and the record itself is never locked — the write
        // replaces it by renaming a temp over it.
        //
        // `store_probe_seconds` is the only entry that costs anything: it is how often
        // the primary store may be PINGed, which is what keeps the probe off the
        // request path under PHP-FPM (0 never probes).
        'audit' => [
            'file' => env('SWRR_AUDIT_FILE', sys_get_temp_dir() . '/swrr-audit.json'),
            'store_probe_seconds' => (int) env('SWRR_AUDIT_STORE_PROBE_SECONDS', 60),
        ],

        // ── Health endpoint (optional) ───────────────────────────────────────────
        //
        // /health/db runs one real query — `select 1` — on the connection this package
        // follows ('connection' above, else database.default) and answers `ok` only when
        // it comes back. Without that query the endpoint's verdict is the state store's,
        // which can be healthy while every application query fails, and a deploy gate
        // that promotes on it is promoting on the wrong fact.
        //
        // Set this off only where there is no database behind the connection at all —
        // a unit-test fixture, a configuration inspection. Off, the endpoint says
        // `"pinned":{"checked":false,"ok":null}` and nothing more about reachability,
        // so anything reading it must read that flag rather than the status. A value
        // this is not a switch itself resolves to the documented default, on.
        'health' => [
            'pinned_query' => env('SWRR_HEALTH_PINNED_QUERY', true),
        ],
    ],

];
