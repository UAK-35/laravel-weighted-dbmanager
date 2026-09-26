<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console\Commands;

use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Support\BootAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan db:replica-status [connection]
 *
 * Prints a table showing each read replica's hardware spec, resolved weight,
 * traffic share %, and current health — plus the active SWRR formula and
 * state-store backend, and the boot audit's standing findings: the settings that
 * read as on but cannot act, which otherwise exist only in a boot log.
 */
class DbReplicaStatus extends Command
{
    protected $signature = 'db:replica-status {connection=pgsql : Database connection name}';

    protected $description = 'Show weighted read replica traffic distribution';

    public function handle(): int
    {
        $argument = $this->argument('connection');
        $connection = is_string($argument) ? $argument : 'pgsql';

        $manager = DB::getFacadeRoot();

        if (!$manager instanceof WeightedDatabaseManager) {
            $this->error('WeightedDatabaseManager is not registered. Check WeightedDatabaseServiceProvider.');

            return self::FAILURE;
        }

        $summary = $manager->healthSummary($connection);

        if (empty($summary['replicas'])) {
            $this->warn("No weighted read replicas found for connection [{$connection}].");

            // Findings are about the installation, not this connection: "this
            // connection has no replicas" does not make a misconfiguration elsewhere
            // less true, and this is often the run an operator starts with.
            $this->auditBlock();

            return self::SUCCESS;
        }

        $this->info("Read replica distribution for connection: <comment>{$connection}</comment>");
        $this->newLine();

        $this->table(
            ['Host', 'Port', 'CPU', 'RAM', 'Weight', 'Share', 'Healthy', 'Failures'],
            array_map(fn ($r) => [
                $r['host'],
                $r['port'],
                $r['cpu_cores'] ?? '—',
                isset($r['ram_gb']) ? $r['ram_gb'] . ' GB' : '—',
                $r['weight'],
                $r['share_pct'] . ' %',
                $r['healthy']
                    ? '<fg=green>✓ yes</>'
                    : '<fg=red>✗ no </>',
                $r['failure_count'] ?: '—',
            ], $summary['replicas']),
        );

        $this->newLine();

        // Formula + factors.
        $formula = $summary['formula'];
        $cpu = $summary['cpu_factor'];
        $ram = $summary['ram_factor'];

        $formulaDisplay = match ($formula) {
            'diminishing' => "weight = (cores^0.7 × {$cpu}) + (√ram × {$ram})",
            default => "weight = (cores × {$cpu}) + (ram × {$ram})",
        };

        $this->line("Formula:    <info>{$formulaDisplay}</info>");
        $this->line("Pool cache: <info>{$summary['resolver_cache_size']} signature(s)</info>");
        $this->line("Store:      <info>{$summary['store']}</info>");

        $storeHealth = $summary['store_healthy']
            ? '<fg=green>healthy</>'
            : '<fg=red>UNHEALTHY</>';

        $this->line("Store state: {$storeHealth}");

        if ($pgcat = $this->pgcatLine()) {
            $this->line("Pgcat:      {$pgcat}");
        }

        if ($warning = $this->pgcatWarning()) {
            $this->line("Warning:    <fg=red>{$warning}</>");
        }

        if ($summary['degraded']) {
            $this->line(
                "Primary:    <fg=red>{$summary['primary_store']} is down — this process is serving "
                ."reads from {$summary['store']}. Cross-worker rotation is lost until it recovers.</>"
            );
        }

        $this->auditBlock();

        return self::SUCCESS;
    }

    /**
     * The boot audit's standing findings, oldest first: the setting that reads as on but
     * cannot act, the sentence the boot log carried for it, and how long it has stood.
     *
     * The record is the boot's — this only reads it — and nothing here changes the exit
     * code: the command reports, and `db:doctor --strict` is the gate.
     *
     * Four states, four lines, and the same four the payload distinguishes: findings
     * standing, nothing standing, no audit registered, and a record that could not be read.
     * The block is `BootAudit::reported()`, the one place either surface decides whether
     * there is a record at all, so the terminal and `/health/db` cannot disagree about it —
     * and they did, because the third state used to print nothing here: an operator could
     * not tell an installation with no audit from one whose record happened to be empty.
     */
    private function auditBlock(): void
    {
        $report = BootAudit::reported($this->audit());
        $error = $report['error'];

        $this->newLine();

        if (! $report['available']) {
            $this->line($error === null
                ? 'Audit:      <fg=yellow>not registered</> — no record is kept, so no finding is reported here'
                : 'Audit:      <fg=yellow>unreadable</> — ' . $error);

            return;
        }

        $findings = $report['findings'];

        if ($findings === []) {
            $this->line(
                'Audit:      <fg=green>nothing standing</> — no setting is recorded as reading on without being able to act'
            );

            return;
        }

        $this->line(sprintf(
            'Audit:      <fg=yellow>%d finding%s standing</> — oldest %s',
            count($findings),
            count($findings) === 1 ? '' : 's',
            $findings[0]['age'],
        ));

        foreach ($findings as $finding) {
            $this->line(sprintf(
                '  %s  <comment>%s</comment>',
                $finding['level'] === 'error' ? '<fg=red>error</>' : '<fg=yellow>warning</>',
                $finding['key'],
            ));

            if ($finding['warning'] !== '') {
                $this->line('    ' . $finding['warning']);
            }

            $this->line(sprintf(
                '    standing %s — first reported %s',
                $finding['age'],
                $finding['first_reported_at'],
            ));
        }
    }

    /**
     * The audit this application registered, or null when nothing did.
     *
     * The same question the health payload's optional dependency answers, and the reason the
     * two surfaces agree on what "not registered" is: a container with no boot audit under
     * that name is null here and a defaulted null there, and `BootAudit::reported()` turns
     * both into the same block. `bound()` first, because a `BootAudit` cannot be autowired —
     * its record file is configuration — so asking a container that does not hold one would
     * throw rather than answer null.
     */
    private function audit(): ?BootAudit
    {
        /** @var mixed $audit */
        $audit = app()->bound(BootAudit::class) ? app(BootAudit::class) : null;

        return $audit instanceof BootAudit ? $audit : null;
    }

    /**
     * The 'Pgcat:' line, rendered from PgcatConfigFlipper::healthSummary() — the
     * same snapshot /health/db embeds and db:pgcat-flip --status prints.
     *
     * Returns null when the provider is not registered, or when the flipper
     * cannot be built.
     */
    private function pgcatLine(): ?string
    {
        if (!app()->bound(PgcatConfigFlipper::class)) {
            return null;
        }

        /** @var PgcatConfigFlipper|null $flipper */
        $flipper = app(PgcatConfigFlipper::class);

        if (!$flipper instanceof PgcatConfigFlipper) {
            return null;
        }

        $pgcat = $flipper->healthSummary();

        if (!$pgcat['enabled']) {
            return '<fg=yellow>inactive</> — '.($pgcat['reason'] ?? 'disabled');
        }

        return sprintf(
            '<fg=green>active</> — %s on %s, mode %s',
            $pgcat['driver'] !== '' ? $pgcat['driver'] : 'unknown driver',
            $pgcat['connection'] !== '' ? $pgcat['connection'] : 'unknown connection',
            $pgcat['resolver_mode'],
        );
    }

    /**
     * The 'Warning:' line for a flipper that is expected to act but cannot:
     * switched on for a connection pgcat cannot front, or armed without the
     * paths a flip needs. Null when there is nothing to warn about, and on an
     * app whose provider is not registered.
     */
    private function pgcatWarning(): ?string
    {
        if (!app()->bound(PgcatConfigFlipper::class)) {
            return null;
        }

        /** @var PgcatConfigFlipper|null $flipper */
        $flipper = app(PgcatConfigFlipper::class);

        return $flipper instanceof PgcatConfigFlipper ? $flipper->healthSummary()['warning'] : null;
    }
}
