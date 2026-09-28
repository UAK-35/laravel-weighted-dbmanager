<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Console\Commands;

use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Support\BootAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan db:replica-status [connection] [--json]
 *
 * Prints a table showing each read replica's hardware spec, resolved weight,
 * traffic share %, and current health — plus the active SWRR formula and
 * state-store backend, and the boot audit's standing findings: the settings that
 * read as on but cannot act, which otherwise exist only in a boot log.
 *
 * JSON
 * ----
 * `--json` writes the same run as one object, in the envelope `db:pgcat-flip` and
 * `db:probe-replicas` write: the command, the verdict, the exit code, and the evidence —
 * which here is the manager's own `healthSummary()` under `status`, the flipper's
 * `healthSummary()` under `pgcat`, and the boot audit's block under `audit`. The last two are
 * the same arrays `/health/db` embeds, under the same two key names, so a job reading a
 * terminal report, a saved report and the endpoint is reading one vocabulary rather than
 * three. `reason` carries the sentence the rendered run prints for the two routes that have
 * no distribution to describe.
 *
 * The exit code does not move, and that is the point of this command: it reports, and
 * `db:doctor --strict` is the gate. Degradation is therefore a fact in the object
 * (`status.degraded`, `status.store_healthy`) rather than a verdict of its own — a job that
 * wants "reads are being served from the local store" to stop a deploy asserts on the field.
 *
 * @phpstan-import-type Block from BootAudit
 */
class DbReplicaStatus extends Command
{
    /** The distribution was read: at least one weighted replica is configured. */
    public const KIND_DISTRIBUTION = 'distribution';

    /** The connection has no weighted read replicas, so there is nothing to describe. */
    public const KIND_NO_REPLICAS = 'no_replicas';

    /** The container has no weighted manager, so nothing could be read. */
    public const KIND_UNBOUND = 'unbound';

    /**
     * The evidence a distribution report carries, and the value an absent one takes.
     *
     * `status` is the manager's `healthSummary()` — the same array the table and the formula
     * lines are rendered from, with the replicas, the formula, its two factors, the store and
     * the degradation flag in it. `pgcat` and `audit` are the blocks `/health/db` carries under
     * those names, and are `null` on the route that never got as far as reading them.
     */
    private const EVIDENCE = [
        'connection' => null,
        'status' => null,
        'pgcat' => null,
        'audit' => null,
    ];

    protected $signature = 'db:replica-status
                            {connection=pgsql : Database connection name}
                            {--json : Print one JSON object — the verdict, the exit code, the distribution and the audit — instead of the table}';

    protected $description = 'Show weighted read replica traffic distribution';

    public function handle(): int
    {
        $argument = $this->argument('connection');
        $connection = is_string($argument) ? $argument : 'pgsql';

        $manager = DB::getFacadeRoot();

        if (!$manager instanceof WeightedDatabaseManager) {
            $reason = 'WeightedDatabaseManager is not registered. Check WeightedDatabaseServiceProvider.';

            if ($this->option('json')) {
                return $this->report(self::KIND_UNBOUND, $connection, $reason, self::FAILURE);
            }

            $this->error($reason);

            return self::FAILURE;
        }

        $summary = $manager->healthSummary($connection);

        if (empty($summary['replicas'])) {
            $reason = "No weighted read replicas found for connection [{$connection}].";

            // Findings are about the installation, not this connection: "this
            // connection has no replicas" does not make a misconfiguration elsewhere
            // less true, and this is often the run an operator starts with.
            $audit = $this->auditReport();
            $flipper = $this->flipper();

            if ($this->option('json')) {
                return $this->report(
                    self::KIND_NO_REPLICAS,
                    $connection,
                    $reason,
                    self::SUCCESS,
                    $summary,
                    $audit,
                    $flipper?->healthSummary(),
                );
            }

            $this->warn($reason);
            $this->pgcatBlock($flipper);
            $this->auditBlock($audit);

            return self::SUCCESS;
        }

        $audit = $this->auditReport();
        $flipper = $this->flipper();

        if ($this->option('json')) {
            return $this->report(
                self::KIND_DISTRIBUTION,
                $connection,
                null,
                self::SUCCESS,
                $summary,
                $audit,
                $flipper?->healthSummary(),
            );
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

        $this->pgcatBlock($flipper);

        if ($summary['degraded']) {
            $this->line(
                "Primary:    <fg=red>{$summary['primary_store']} is down — this process is serving "
                ."reads from {$summary['store']}. Cross-worker rotation is lost until it recovers.</>"
            );
        }

        $this->auditBlock($audit);

        return self::SUCCESS;
    }

    /**
     * The run as one JSON object on stdout, and nothing else — written by `JsonEnvelope`, the same
     * envelope `db:pgcat-flip` and `db:probe-replicas` write.
     *
     * `status`, `pgcat` and `audit` are passed through exactly as the classes that own them
     * computed them: no `(not set)` substitutions, no `—` for an absent number. A machine has to be
     * able to test a field for being unset without matching the way a table prints an unset one,
     * which is the rule the flip's `--status` report follows for the same reason.
     *
     * @param array<string, mixed> $status
     * @param array<string, mixed>|null $audit
     * @param array<string, mixed>|null $pgcat
     */
    private function report(
        string $kind,
        string $connection,
        ?string $reason,
        int $exitCode,
        array $status = [],
        ?array $audit = null,
        ?array $pgcat = null,
    ): int {
        return JsonEnvelope::write($this->output, 'db:replica-status', self::EVIDENCE, [
            'kind' => $kind,
            'reason' => $reason,
            'connection' => $connection,
            'status' => $status === [] ? null : $status,
            'pgcat' => $pgcat,
            'audit' => $audit,
        ], $exitCode);
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
     *
     * The block is computed by `auditReport()` and handed to both channels, so the object
     * carries the same four states the lines describe rather than a second reading of the
     * file that could land on the other side of a write.
     *
     * @param Block $report
     */
    private function auditBlock(array $report): void
    {
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
     * The audit's block, read once per run and handed to whichever channel is being written.
     *
     * `Block` is `BootAudit`'s own type, imported rather than restated: the object carries this
     * array verbatim, so a field added to the block reaches the report without a second
     * declaration here to keep in step.
     *
     * @return Block
     */
    private function auditReport(): array
    {
        return BootAudit::reported($this->audit());
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
     * The flipper itself, or null when the provider is not registered or the container does not
     * hold one.
     *
     * The flipper rather than its snapshot, so that the shape of the block is the one
     * `PgcatConfigFlipper::healthSummary()` declares — the object carries the array verbatim, and
     * the terminal renders the same call, rather than either of them re-describing a block this
     * class does not own.
     */
    private function flipper(): ?PgcatConfigFlipper
    {
        if (!app()->bound(PgcatConfigFlipper::class)) {
            return null;
        }

        /** @var PgcatConfigFlipper|null $flipper */
        $flipper = app(PgcatConfigFlipper::class);

        return $flipper instanceof PgcatConfigFlipper ? $flipper : null;
    }

    /**
     * The two lines pgcat is described in, on every route that read it — so the terminal carries
     * the same blocks the object does, and neither channel says more than the other.
     *
     * The 'Pgcat:' line is `PgcatConfigFlipper::healthSummary()` — the same snapshot /health/db
     * embeds and `db:pgcat-flip --status` prints. The 'Warning:' line is for a flipper that is
     * expected to act but cannot: switched on for a connection pgcat cannot front, or armed
     * without the paths a flip needs. The snapshot is read once here, and the warning is null when
     * there is nothing to warn about.
     */
    private function pgcatBlock(?PgcatConfigFlipper $flipper): void
    {
        if ($flipper === null) {
            return;
        }

        $pgcat = $flipper->healthSummary();

        $this->line($pgcat['enabled']
            ? sprintf(
                'Pgcat:      <fg=green>active</> — %s on %s, mode %s',
                $pgcat['driver'] !== '' ? $pgcat['driver'] : 'unknown driver',
                $pgcat['connection'] !== '' ? $pgcat['connection'] : 'unknown connection',
                $pgcat['resolver_mode'],
            )
            : sprintf('Pgcat:      <fg=yellow>inactive</> — %s', $pgcat['reason'] ?? 'disabled'));

        if ($warning = $pgcat['warning'] ?? null) {
            $this->line("Warning:    <fg=red>{$warning}</>");
        }
    }
}
