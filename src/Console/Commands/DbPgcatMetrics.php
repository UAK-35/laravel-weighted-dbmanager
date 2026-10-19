<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console\Commands;

use Illuminate\Console\Command;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\PoolProbeMetrics;

/**
 * php artisan db:pgcat-metrics {--strict}
 *
 * The pool probe's verdict, in the format a Prometheus scrape reads, on stdout and nothing else.
 *
 *   # a node_exporter textfile collector, refreshed by the scheduler
 *   * * * * * php artisan db:pgcat-metrics > /var/lib/node_exporter/textfile/swrr_pgcat.prom.tmp \
 *             && mv /var/lib/node_exporter/textfile/swrr_pgcat.prom.tmp \
 *                   /var/lib/node_exporter/textfile/swrr_pgcat.prom
 *
 * WHY A COMMAND, WHEN `/health/db` ALREADY PUBLISHES THE SAME READING
 *   The endpoint is the right surface for a monitor that can scrape HTTP from outside the container,
 *   and it is the wrong one for the monitor that watches the container: it would have to guess the
 *   application's port, authenticate through whatever the deployment put in front of it, and parse a
 *   JSON body to reach one number. It is also the wrong one for a deploy step, which runs inside the
 *   container and has no business making an HTTP request to itself to ask a question its own process
 *   can answer. Neither surface is replaced here: this is the same verdict, rendered once, and
 *   `PoolProbeMetrics` is a pure function over the array the flipper already returns, so there is no
 *   second reading for the three to disagree about.
 *
 * WHY IT DOES NOT WRITE TO THE ENDPOINT INSTEAD
 *   A scrape that has to be pulled *from* the process needs a route the host application owns, and
 *   this package does not register routes — `/health/db` is a controller a consumer routes itself.
 *   Shipping a second route would make every installation expose a metrics port it did not ask for,
 *   and the exposition is the part that has to be right; where it is served is the deployment's.
 *
 * WHAT STDOUT IS, AND WHAT THE EXIT CODE IS
 *   Stdout is the exposition — always, on every route, including the one where no flipper is bound
 *   and the body therefore says `swrr_pgcat_pool_probe_enabled 0`. A scanner reads every line of it,
 *   so nothing else is ever written there: no header, no warning, no sentence about what happened.
 *   Anything this package has to say about a reading is a sample in it.
 *
 *   The exit code is the deploy step's channel rather than the scraper's. By default the command
 *   exits `0` once the exposition is written, because writing it is the whole job: a scrape that
 *   failed because a pool was missing would report the target as down instead of reporting the pool.
 *   `--strict` is the other reader — the pipeline that wants to stop before promoting — and it exits
 *   `1` when the verdict has anything to say: a declared pool missing from the running pooler
 *   (`swrr_pgcat_pool_probe_failed`), a reading that proved nothing about one
 *   (`swrr_pgcat_pool_probe_warned`), or no flipper to ask at all. The two files are the same on
 *   both routes; only the code differs, so one body can be archived by both readers.
 *
 * WHY IT ASKS THE FLIPPER AND NOT THE POOLER
 *   The arm/declared-pool/boot-window/console-then-front-door logic is `poolProbeVerdict()`'s, and
 *   the docblock there says why each step is the step it is. A command that asked the pooler itself
 *   would be a fourth implementation of that order, and the first time one of them was repaired the
 *   other three would describe a different pooler. This file's only decisions are what an unbound
 *   flipper renders as, and what `--strict` counts as a failure.
 */
class DbPgcatMetrics extends Command
{
    protected $signature = 'db:pgcat-metrics
                            {--strict : Exit 1 when the verdict has anything to say — a missing pool, an unproven reading, or no flipper to ask — instead of always exiting 0}';

    protected $description = 'Print the running pooler\'s pool-probe verdict as Prometheus text exposition';

    public function handle(): int
    {
        // The one route this file decides: a container without the flipper cannot ask its pooler
        // anything, and the empty verdict renders as `enabled 0` with no pool samples — which is the
        // same body a disabled probe produces, because from a scraper's side they are the same fact.
        // `db:doctor` is where the difference is named, in a row that fails on the missing binding.
        $flipper = $this->laravel->bound(PgcatConfigFlipper::class)
            ? $this->laravel->make(PgcatConfigFlipper::class)
            : null;

        if (! $flipper instanceof PgcatConfigFlipper) {
            $this->output->write(PoolProbeMetrics::render([]));

            return $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $verdict = $flipper->poolProbeVerdict();

        $this->output->write(PoolProbeMetrics::render($verdict));

        if (! $this->option('strict')) {
            return self::SUCCESS;
        }

        // The two flags are the flipper's judgement, and it already waits for the boot window to
        // close on both — a container that is still converging is not failed here, however loudly a
        // monitor watching `swrr_pgcat_pool_present` should be told about a pool that has gone.
        return $verdict['failed'] || $verdict['warned']
            ? self::FAILURE
            : self::SUCCESS;
    }
}
