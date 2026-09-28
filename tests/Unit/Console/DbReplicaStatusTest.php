<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Console;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Uak35\WeightedDbManager\Console\Commands\DbReplicaStatus;
use Uak35\WeightedDbManager\Console\JsonEnvelope;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Tests\Support\Readme;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The `Audit:` line of `db:replica-status` — `/health/db`'s audit block, on a terminal.
 *
 * The block has four states and the command prints a line for each: findings standing, nothing
 * standing, no audit registered, and a record that could not be read. The last one is the one
 * this file exists for. It needs a reader that refuses to round an unreadable file to an empty
 * one, because the two are not the same fact: an installation whose record is half-written has
 * findings nobody can see, and printing `nothing standing` over it would be the one answer the
 * file contradicts. An operator reads this line on the run they start with when pgcat or a
 * reader window misbehaves, so the wrong one sends them looking at the wrong thing — at the
 * configuration rather than at the record.
 *
 * The record is written here, over the one the fixture's boot left, because a previous boot is
 * what a reader reads: the command never writes, so every state below is reachable by making a
 * file the way that boot would have — or, in the unreadable case, the way a full disk would.
 *
 * The block has a second reader now, and it is the same one: `--json` writes the audit under
 * `audit`, which is the key `/health/db` carries it under, and it is the same block rather than a
 * second reading of the file — `auditReport()` reads once per run and hands the result to
 * whichever channel is being written. So the four states are data as well as lines, which is what
 * a job gating on "this installation has nothing standing" needs: the three states that are not
 * findings are told apart by `available` and `error`, not by matching an English sentence.
 */
final class DbReplicaStatusTest extends TestCase
{
    /**
     * The three routes a run can take, as the kind, code and sentence each one carries.
     *
     * This command has no exit matrix: it exits `0` on every path the suite drives apart from the
     * guard, which is the same guard `db:probe-replicas`'s matrix pins as its own row — see
     * `docs/documented-exit-codes.md`. The rows here are the routes of the *report* instead, and
     * they are what the kind table is bound against: a verdict a job can read has to be one a run
     * can reach, with the code beside it that the run exits.
     *
     * @return array<string, array{connection: string, bound: bool, kind: string, exit: int, reason: string|null}>
     */
    public static function routeProvider(): array
    {
        return [
            'a connection with weighted replicas' => [
                'connection' => self::CONNECTION,
                'bound' => true,
                'kind' => DbReplicaStatus::KIND_DISTRIBUTION,
                'exit' => 0,
                'reason' => null,
            ],
            'a connection with no read list' => [
                'connection' => 'writer_only',
                'bound' => true,
                'kind' => DbReplicaStatus::KIND_NO_REPLICAS,
                'exit' => 0,
                'reason' => 'No weighted read replicas found for connection [writer_only].',
            ],
            'the container has no weighted manager' => [
                'connection' => self::CONNECTION,
                'bound' => false,
                'kind' => DbReplicaStatus::KIND_UNBOUND,
                'exit' => 1,
                'reason' => 'WeightedDatabaseManager is not registered. Check WeightedDatabaseServiceProvider.',
            ],
        ];
    }

    /**
     * The run as data: the envelope `db:pgcat-flip` and `db:probe-replicas` write, with this
     * command's evidence after the five keys they share.
     *
     * Every route reports, including the one that never found a manager — that is the point of the
     * flag. A job that has to tell "this connection routes no reads" from "this deployment's
     * provider never registered" needs both sentences in the object it parses, and it needs the
     * exit code beside them, because the two exit differently.
     */
    #[DataProvider('routeProvider')]
    public function test_the_json_report_is_the_same_run_as_one_object(
        string $connection,
        bool $bound,
        string $kind,
        int $exit,
        ?string $reason,
    ): void {
        if (! $bound) {
            $this->app->instance('db', new DatabaseManager($this->app, $this->app->make('db.factory')));
        }

        $this->assertSame($exit, Artisan::call('db:replica-status', ['connection' => $connection, '--json' => true]));

        $output = Artisan::output();

        $this->assertStringStartsWith('{', ltrim($output), 'the object is the report, and the report is the object');
        $this->assertStringNotContainsString('ERROR', $output, 'a route that reports does not also write to the error channel');
        $this->assertStringNotContainsString('WARNING', $output);
        $this->assertStringNotContainsString('Pool cache:', $output, 'the rendered lines are not written beside the object');
        $this->assertStringNotContainsString('Store state:', $output);

        $report = $this->report($output);

        $this->assertSame(
            [...array_keys(JsonEnvelope::CORE), 'connection', 'status', 'pgcat', 'audit'],
            array_keys($report),
            'the envelope\'s keys first, read from the class rather than restated, then this command\'s evidence',
        );
        $this->assertSame('db:replica-status', $report['command']);
        $this->assertSame($kind, $report['kind'], 'The verdict of the route this row took');
        $this->assertSame($exit, $report['exit_code'], 'the code travels inside the report');
        $this->assertSame($connection, $report['connection']);
        $this->assertSame($reason, $report['reason']);

        if ($kind === DbReplicaStatus::KIND_UNBOUND) {
            $this->assertNull($report['status'], 'nothing was read, so there is no summary to carry');
            $this->assertNull($report['pgcat']);
            $this->assertNull($report['audit'], 'the route that never found a manager never read the record either');

            return;
        }

        // The manager's own summary, with none of the table's substitutions: a machine has to be
        // able to test a field for being unset without matching the way a table prints one.
        $status = $report['status'];
        $this->assertIsArray($status);
        $this->assertArrayHasKey('replicas', $status);
        $this->assertArrayHasKey('formula', $status);
        $this->assertArrayHasKey('degraded', $status);

        if ($kind === DbReplicaStatus::KIND_DISTRIBUTION) {
            $this->assertSame(['10.1.0.1', '10.1.0.2'], array_column($status['replicas'], 'host'));
            $this->assertSame('diminishing', $status['formula'], 'the formula in force, not the sentence rendered from it');
            $this->assertArrayHasKey('share_pct', $status['replicas'][0]);
        } else {
            $this->assertSame([], $status['replicas'], 'a connection with no read list has no replica to describe');
        }

        // The two blocks `/health/db` embeds under these names, carried here under the same ones:
        // a job reading a terminal report, a saved one and the endpoint is reading one vocabulary.
        $this->assertIsArray($report['pgcat']);
        $this->assertArrayHasKey('enabled', $report['pgcat']);

        $audit = $report['audit'];
        $this->assertIsArray($audit);
        $this->assertSame(
            BootAudit::reported($this->app->bound(BootAudit::class) ? $this->app->make(BootAudit::class) : null),
            $audit,
            'the block is the one both readers already share, not a second reading of the file',
        );
    }

    /**
     * A record nobody could read is a block the object carries, in the same two fields the terminal
     * renders it from — so a job can tell the four states apart as data.
     *
     * This is the terminal test's claim one channel over: `available: false` with an `error` naming
     * the file is a different fact from `available: true` with no findings, and a consumer that
     * rounded the first to the second would gate a release on "nothing is standing" about a record
     * it never read.
     */
    public function test_the_unreadable_record_is_a_block_the_object_carries(): void
    {
        $file = (string) config('db-manager.swrr.audit.file');

        file_put_contents($file, '{"findings": {"swrr.pgcat.gate": {"warning": ');

        $this->assertSame(0, Artisan::call('db:replica-status', ['connection' => self::CONNECTION, '--json' => true]));

        $audit = $this->report(Artisan::output())['audit'];

        $this->assertIsArray($audit);
        $this->assertFalse($audit['available'], 'a record that could not be read is not a record with nothing standing');
        $this->assertIsString($audit['error']);
        $this->assertStringContainsString($file, $audit['error'], 'the file to open, not just the fact that something is wrong');
        $this->assertSame([], $audit['findings'], 'nothing is known to stand in a record nobody could read');
    }

    /**
     * The JSON vocabulary is closed and documented, and the code beside each kind is the code the
     * route exits with.
     *
     * The same guard the flip's and the probe's kind tables have, and it matters more here: this
     * command exits `0` on the route a job is most likely to check (a connection that routes no
     * reads is not a failure), so `kind` is the only thing in the object that tells a job which of
     * the three runs it is reading.
     */
    public function test_every_json_kind_is_documented_with_its_exit_code(): void
    {
        $rows = Readme::table('### Reading the distribution: `db:replica-status`', 'kind');

        $produced = [];

        foreach (self::routeProvider() as $route) {
            $produced[$route['kind']] = $route['exit'];
        }

        $this->assertEqualsCanonicalizing(
            array_keys($produced),
            array_map([Readme::class, 'plain'], array_column($rows, 'kind')),
            'a kind the report can carry is documented, and every documented kind is one the report carries',
        );

        foreach ($rows as $row) {
            $kind = Readme::plain($row['kind']);

            $this->assertSame(
                $produced[$kind],
                Readme::code($row['exit']),
                sprintf('The README documents `%s` as exiting %s, while the routes assert %d.', $kind, $row['exit'], $produced[$kind]),
            );
        }
    }

    /**
     * The report a run printed, decoded. `JSON_THROW_ON_ERROR` turns "the output is not JSON" into
     * this test's failure, which is the one thing a consumer of this mode has to be told.
     *
     * @return array<string, mixed>
     */
    private function report(string $output): array
    {
        $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded, 'a report is one object');

        return $decoded;
    }
    /**
     * The line that used to be unreachable.
     *
     * While an unreadable record read as an empty one, this state printed the same sentence an
     * installation with nothing to report prints, and the branch that renders it could never
     * fire. It fires now: the command asks `BootAudit::standing()`, which throws for a file that
     * is there and is not a record, and `reported()` turns that into `available: false` with the
     * reason — the same block the payload embeds, so the terminal and `/health/db` say it alike.
     */
    public function test_a_record_that_cannot_be_read_is_a_line_rather_than_nothing_standing(): void
    {
        $file = (string) config('db-manager.swrr.audit.file');

        // Half a record: the key and the sentence are on disk, the JSON around them is not.
        file_put_contents($file, '{"findings": {"swrr.pgcat.gate": {"warning": ');

        $this->assertSame(0, Artisan::call('db:replica-status'), 'the command reports; `db:doctor --strict` is the gate');

        $output = Artisan::output();

        $this->assertStringContainsString('unreadable', $output);
        $this->assertStringContainsString($file, $output, 'the file to open, not just the fact that something is wrong');
        $this->assertStringContainsString('is not JSON', $output);

        // The sentence that would have been printed instead, and the reason it is not: a record
        // nobody could read says nothing about what is standing in it.
        $this->assertStringNotContainsString('nothing standing', $output);
    }

    /**
     * The neighbouring state, so that the line above is a distinction rather than a decoration:
     * a record that *was* read and holds nothing is good news, said in its own words.
     *
     * The two are one word apart in the reader and opposite in meaning — an installation that has
     * fixed everything it was told about, and one that cannot be told anything — which is why
     * `reported()` carries them as `available` with `error: null` and `available: false` with a
     * reason rather than as a list of findings either way.
     */
    public function test_a_record_with_nothing_standing_is_not_reported_as_unreadable(): void
    {
        file_put_contents((string) config('db-manager.swrr.audit.file'), (string) json_encode([
            'findings' => [],
            'store_probed_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->assertSame(0, Artisan::call('db:replica-status'));

        $output = Artisan::output();

        $this->assertStringContainsString('nothing standing', $output);
        $this->assertStringNotContainsString('unreadable', $output);
    }
}
