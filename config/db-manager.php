<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The key set this file is
    |--------------------------------------------------------------------------
    |
    | One whole number, raised when the *keys* below change — a key added, removed
    | or moved — and never for a changed default, a reworded comment or a new env
    | var behind a key that already exists. It is what makes a published copy
    | answerable: `config/db-manager.php` is copied into an application once and
    | then edited there, so a key added to this file afterwards is a key no
    | application ever receives, and the installation keeps reading the default for
    | it while the changelog describes a release that does something else. Nothing
    | about that is malformed, which is why it has to be a number a preflight can
    | compare rather than something a reader is expected to notice.
    |
    | `db:doctor`'s `published config` row compares the published copy's number with
    | this one and names the release each is from; `Support\ConfigSchema` owns the
    | number, the key and those sentences, and a test in
    | `tests/Unit/Support/ConfigSchemaTest.php` holds this literal to it in both
    | directions, so the two cannot drift.
    |
    | Spelling it as a number is the whole of the syntax. A value that is not a whole
    | number is reported as unreadable rather than resolved to a default, because
    | the two faults want different repairs: republish, or fix the value.
    |
    */

    'generation' => 1,

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

        // ── The endpoints on the read path (optional) ────────────────────────────
        //
        // Two of the things a routed read can be cut by are the servers' own settings
        // and are nowhere in this file: the `statement_timeout` each endpoint is
        // capped at, and whether a standby keeps `hot_standby_feedback` on. The caps
        // live in a parameter group rather than in a Laravel config file, so the only
        // way `db:doctor` can report them is to ask — one throwaway connection per
        // endpoint on the connection's `read` list, plus the writer, and one query.
        //
        // That is why it is opt-in and read as a switch. When it is on:
        //
        //   read caps        what each endpoint is capped at, and whether the writer's
        //                    cap is the longer one — which is the whole reason a read a
        //                    replica cut can be answered by escalating to the writer
        //   standby feedback whether every standby this package sends reads to keeps
        //                    `hot_standby_feedback` on. A standby without it asks the
        //                    primary to drop nothing, so a query there can be cancelled
        //                    by a conflict with recovery (SQLSTATE 40001) at any moment,
        //                    which is the failure the recording under `docs/` describes
        //
        // Neither row fails an installation over an endpoint that did not answer, and
        // neither is judged at all when this is off: the rows say the probe is not
        // armed and name this key, rather than reporting silence as if the endpoints
        // had been asked. `timeout_ms` bounds the connect, so a preflight cannot wait
        // on an endpoint that is not there.
        //
        // See Uak35\WeightedDbManager\Database\Weighted\EndpointReadings.
        'read_path' => [
            'enabled' => env('SWRR_READ_PATH_ENABLED', false),
            'timeout_ms' => (int) env('SWRR_READ_PATH_TIMEOUT_MS', 2000),
        ],

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

            // ── The pool probe (optional) ───────────────────────────────────────────
            //
            // The second self-heal, and the one supervisord cannot see: a program can be
            // RUNNING and still hold a configuration with no pool for a database the
            // application is connected to, so every read to that pool fails while
            // `supervisorctl status` calls pgcat healthy. When this is on, a poll that finds
            // the mode already applied asks the pooler about each pool the variant declares —
            // a PostgreSQL v3 startup packet and the first message back, with no credentials —
            // and re-applies the config when one is missing. The pooler's own refusal is the
            // evidence; see Uak35\WeightedDbManager\Pgcat\PoolProbe.
            //
            // Off by default, and read as a switch: an installation opts into a socket on the
            // poll path. `host`/`port` are where pgcat itself listens — the config paths above
            // name files, not an address — so the port here is the one the pooler's own
            // `[general]` section names, and the shipped value is pgcat's convention rather than
            // PostgreSQL's: a probe aimed at a PostgreSQL server is *answered* for every name, in
            // PostgreSQL's words rather than pgcat's, where the whole point is to hear the
            // pooler's own. `user` is the client name the startup packet carries, and no
            // password is ever sent. A `timeout_ms` that runs out is reported as silence, never
            // as a missing pool, so a probe pointed at the wrong address cannot restart a
            // healthy pooler — and a pooler that authenticates *before* it resolves a pool
            // answers every name, which is the limitation
            // Uak35\WeightedDbManager\Pgcat\PoolProbe writes down beside its measurement.
            'pool_probe' => [
                'enabled' => env('SWRR_PGCAT_POOL_PROBE_ENABLED', false),
                'host' => env('SWRR_PGCAT_POOL_PROBE_HOST', '127.0.0.1'),
                'port' => (int) env('SWRR_PGCAT_POOL_PROBE_PORT', 6432),
                'user' => env('SWRR_PGCAT_POOL_PROBE_USER', 'postgres'),
                'timeout_ms' => (int) env('SWRR_PGCAT_POOL_PROBE_TIMEOUT_MS', 2000),
            ],

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

            // ── The per-minute schedule (optional) ──────────────────────────────────
            //
            // The provider registers `db:pgcat-flip` on the container's schedule the first
            // time that schedule is resolved — `schedule:run` and `schedule:list` are what do
            // that — so there is nothing to paste into the application's own scheduling code.
            // Uak35\WeightedDbManager\Pgcat\FlipSchedule is where the reasoning lives: why
            // every minute, why the attempts stop at the boot window above, and why the mutex
            // is named per container rather than left as `withoutOverlapping()` names it.
            //
            // `enabled` is a switch, read as one — `(bool) 'off'` is true and this one decides
            // whether a file is swapped every minute — so `yes`/`no` work and a value that is
            // neither is reported instead of being cast. **Left unset it follows `enabled`
            // above**, and so does a refused value: an installation that has not armed the
            // flipper has nothing for a schedule to keep in step, and a typo must not be what
            // stops the flip.
            //
            // The default is deliberately *absent* rather than `false`. A `false` written here is
            // a value — the switch is then off on purpose, and `FlipSchedule::settings()` reads it
            // as one — so a copy declaring it would ship a schedule that stays off on an
            // installation whose flipper is armed, which is the opposite of the sentence above and
            // of what the class documents. Write `false` only to disarm the entry on an armed
            // installation; write nothing to let it follow.
            'schedule' => [
                'enabled' => env('SWRR_PGCAT_SCHEDULE_ENABLED'),
                // How often the flip runs, in whole minutes: 1 is the shipped cadence and 5 is
                // `SWRR_PGCAT_SCHEDULE_INTERVAL_MINUTES=5`, which is a step of five in the minute
                // field. Raising it is a trade rather than a saving — what bounds the attempts is
                // `flip_window_seconds` above, not this: eight minutes holds eight runs at one and
                // two at five, and an interval at or past the window is one attempt. Raise the
                // window with it, or the pooler being repaired is given less time, not less work.
                //
                // A value this cannot express — 0, a negative, a non-number, or more than 59,
                // because a minute step wraps rather than meaning what it says past the top of the
                // hour — resolves to 1 rather than being written into an expression that means
                // something else. `1` is the class's own default — `FlipSchedule::DEFAULT_INTERVAL_MINUTES`,
                // which is also what this file ships — and that is the whole of the rule: a copy that
                // declares `2` here still gets 1 for a value it cannot read, because every way this
                // setting can be wrong has to run *more* often than was asked for, never less — an
                // unreadable interval must not be the thing that leaves a pooler un-repaired. An
                // installation needing a cadence wider than an hour registers its own `db:pgcat-flip`
                // entry, which the duplicate guard leaves alone.
                'interval_minutes' => env('SWRR_PGCAT_SCHEDULE_INTERVAL_MINUTES', 1),
                // How many runs may go missing before the schedule counts as stopped, and
                // `db:doctor`'s `schedule beat` row, `/health/db` and `db:scheduler-watchdog` say
                // so. Five, which is five cadences: five minutes at the interval above, and
                // twenty-five at `interval_minutes: 5`. A count of runs rather than a number of
                // seconds, so the bound follows the cadence — a fixed duration would have to be
                // raised by hand every time the interval was, and the value somebody forgot would
                // fail a health check on a schedule that was doing exactly what it was configured
                // to do.
                //
                // It is judged against the beat the entry leaves in `state_file`: every run stamps
                // it, including the no-op runs after `flip_window_seconds` has closed, which is the
                // only record of the entry still firing. A schedule that stops is invisible without
                // it — the container keeps serving, keeps answering `select 1`, and keeps reporting
                // the mode the last run applied.
                //
                // `0` (or a negative) switches the judgement off, the way
                // `audit.store_probe_seconds = 0` switches the store probe off. That is reported
                // rather than silent: the row fails on it and `--strict` fails a release on it,
                // because an installation that has switched off the only check that notices a dead
                // cron is a fact a preflight should state.
                //
                // Left uncast, deliberately: `(int) 'five'` is 0, and 0 is switched off here — so a
                // value this cannot read resolves to the default rather than to the setting's
                // opposite. Spelling it as a number is the whole of the syntax; `Uak35\WeightedDb
                // Manager\Pgcat\ScheduleBeat` is where the tolerance and its sentences live.
                'stale_after_missed_runs' => env('SWRR_PGCAT_SCHEDULE_STALE_AFTER_MISSED_RUNS', 5),
                // What `schedule:list` shows the entry as, and what the duplicate guard matches
                // against: an entry already carrying this name, or one already running the
                // command, is left alone rather than joined by a second per-minute run.
                // Applications with their own task-name conventions set their own here.
                'name' => env('SWRR_PGCAT_SCHEDULE_NAME', 'pgcat_flip'),
                // Where each run's output is appended. Unset, it is
                // storage_path('logs/scheduled_tasks/pgcat_flip.log'), where Laravel itself
                // puts the output of a scheduled event it names. The parent directory is
                // created if it is missing, because a command event runs through a shell
                // redirect: a log that cannot be opened is a command that never runs.
                'log' => env('SWRR_PGCAT_SCHEDULE_LOG'),

                // ── The reader windows, as tasks (optional) ──────────────────────────
                //
                // The entry above converges the pool at container start and is then a no-op for
                // the rest of the container's life — the boot window bounds it, deliberately,
                // because that is what makes a container that could not converge look broken
                // rather than busy. These tasks are the other half: one moves the pool to the
                // reader configuration as each window opens and one back to writer-only as it
                // closes, for as long as the container lives.
                //
                // They are registered as `activate_readers:<HH:MM>` and
                // `deactivate_readers:<HH:MM>` — one pair per window in `swrr.reader_windows`,
                // named for the boundary they act at — and each runs `db:pgcat-window-flip` once
                // a minute from `lead_seconds` before its boundary to `grace_seconds` after it.
                // Every one of those runs probes the target the mode change would point at — the
                // replicas, or the writer — and the mode is applied at the boundary only once
                // that target has answered. A boundary whose target never answers is left alone
                // and reported: the pool stays where it was rather than being pointed at
                // something that is not there.
                //
                // LEFT OFF, AND DELIBERATELY SO. Turn this on and turn `enabled` above off in the
                // same change. The two read the same resolver and cannot both act on one boundary
                // — the per-minute entry checks the boot window before it takes its lock, so once
                // a container has converged it does nothing at a boundary — so this is a choice
                // about which mechanism owns the mode rather than a race to avoid. A mode this
                // pair applies goes through the flipper's forced path, which is deliberately not
                // recorded as a boot convergence, so the two do not share a record of what they
                // did either. `db:doctor`'s `flip schedule` row reports which of the two answers
                // a boundary, and warns when neither does.
                'windows' => [
                    'enabled' => env('SWRR_PGCAT_WINDOW_FLIP_ENABLED', false),
                    // How long before a boundary the probing starts. Eight minutes, the same eight
                    // the boot window is: long enough that a flapping replica is seen to be
                    // flapping rather than caught once, short enough that a window is not being
                    // probed for while the previous one still applies.
                    'lead_seconds' => (int) env('SWRR_PGCAT_WINDOW_FLIP_LEAD_SECONDS', 480),
                    // How long after a boundary it keeps asking, so a target that comes up late is
                    // still used rather than given up for the day. Nothing is applied twice: a run
                    // whose mode is already the applied one reports that there was nothing to do.
                    'grace_seconds' => (int) env('SWRR_PGCAT_WINDOW_FLIP_GRACE_SECONDS', 480),
                    // The directory one log per task is written into, under the application's
                    // storage path by default. A directory rather than a file, because a day of
                    // windows is several tasks with several boundaries: each is named after its
                    // task (`activate_readers-10-00.log`) and each holds one boundary's evidence.
                    // The directory is created if it is missing — a command event runs through a
                    // shell redirect, and a log that cannot be opened is a command that never runs.
                    'log_directory' => env('SWRR_PGCAT_WINDOW_FLIP_LOG_DIRECTORY', storage_path('logs/scheduled_tasks')),
                ],
            ],
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
