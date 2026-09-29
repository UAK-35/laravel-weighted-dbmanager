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
     * These are the *routes*, one per verdict; the exit matrix below is these against the two
     * channels. The rows here are what the report is bound against — a verdict a job can read has
     * to be one a run can reach, with the code beside it that the run exits — and the code in a row
     * is the one both channels return, which is the matrix's claim rather than this table's.
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
     * The exit matrix: every route against every channel it can be asked for.
     *
     * Six cells rather than three, because the one route that is not `0` returns from two places —
     * `--json` writes an object and its code, the terminal writes the sentence and its code — and
     * a matrix over routes alone would leave the second return unread. That is the hole this table
     * closes: the guard was driven through `--json` and the rendered run was not, so the row an
     * operator meets (a container with no weighted manager, `ERROR` on the terminal, exit `1`) was
     * documented in the README and pinned by nothing here. The other two routes are cells for the
     * same reason, and their rows are where the per-channel claims live: the sentence a route
     * carries, and whether the run wrote an object at all.
     *
     * The cells are *derived* from `routeProvider()` rather than written a second time, so a route
     * added there arrives as two cells with the code that route declares.
     *
     * @return array<string, array{connection: string, bound: bool, kind: string, exit: int, reason: string|null, json: bool}>
     */
    public static function exitCodeProvider(): array
    {
        $cells = [];

        foreach (self::routeProvider() as $route => $row) {
            foreach (['on the terminal' => false, 'as one object' => true] as $channel => $json) {
                $cells["{$route}, {$channel}"] = $row + ['json' => $json];
            }
        }

        return $cells;
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
     * The code is a function of the route and the channel, and the one route that is not `0` exits
     * `1` down both of them.
     *
     * The channels are what a run can be asked through, so a cell is a run: the rendered one writes
     * its sentence and returns the code, and `--json` writes one object carrying the same code
     * inside it. Both halves of each cell are asserted, because either alone passes while the other
     * is wrong: a command that returned `1` and printed nothing leaves a scheduler a code with no
     * reason, and one that printed the reason and returned `0` is the failure a deploy gate is
     * written against.
     */
    #[DataProvider('exitCodeProvider')]
    public function test_the_exit_code_is_a_function_of_the_route_and_the_channel(
        string $connection,
        bool $bound,
        string $kind,
        int $exit,
        ?string $reason,
        bool $json,
    ): void {
        if (! $bound) {
            $this->app->instance('db', new DatabaseManager($this->app, $this->app->make('db.factory')));
        }

        $actual = Artisan::call('db:replica-status', ['connection' => $connection] + ($json ? ['--json' => true] : []));
        $output = Artisan::output();

        $this->assertSame($exit, $actual, "The {$kind} route exits {$exit} on this channel:\n".$output);

        // The sentence the route carries, down whichever channel is writing it: the two routes that
        // found nothing say so, and the guard names the dependency to check.
        if ($reason !== null) {
            $this->assertStringContainsString($reason, $output, "The {$kind} route says what its exit code means");
        }

        if (! $json) {
            $this->assertStringStartsNotWith('{', ltrim($output), 'the rendered channel renders');

            return;
        }

        $this->assertStringStartsWith('{', ltrim($output), 'the object is the report, and the report is the object');
        $this->assertSame($exit, $this->report($output)['exit_code'], 'the code travels inside the report');
    }

    /**
     * The README row each cell of the matrix is an instance of.
     *
     * The matrix is the routes against the channels, and the table is written for an operator, so
     * each row of the table is two cells — the code is the same either way, which is the claim the
     * table makes by having one `exit` column. This map is the correspondence, and it is the only
     * thing either side has to keep in step: `test_the_matrix_agrees_with_the_readme_exit_table()`
     * fails when the two disagree in either direction. The row is named by its sentence rather than
     * by its `kind`, because the sentence is what an operator reads the table for; the kind column is
     * bound separately, against the vocabulary the reports produce.
     *
     * @return array<string, string> key of exitCodeProvider() => the README row it documents
     */
    private static function documentedSituations(): array
    {
        return [
            'a connection with weighted replicas, on the terminal' => 'the weighted replicas were read',
            'a connection with weighted replicas, as one object' => 'the weighted replicas were read',
            'a connection with no read list, on the terminal' => 'the connection has no weighted read replica to describe',
            'a connection with no read list, as one object' => 'the connection has no weighted read replica to describe',
            'the container has no weighted manager, on the terminal' => 'the container has no weighted manager, so nothing was read',
            'the container has no weighted manager, as one object' => 'the container has no weighted manager, so nothing was read',
        ];
    }

    /**
     * The JSON vocabulary is closed and documented, and the code beside each kind is the code every
     * cell of the matrix exits with.
     *
     * The same guard the flip's and the probe's tables have, and it matters more here: this command
     * exits `0` on the route a job is most likely to check (a connection that routes no reads is not
     * a failure), so `kind` is the only thing in the object that tells a job which of the three runs
     * it is reading — and the one kind that exits `1` is the guard, which is the row this table had
     * documented and nothing here had driven.
     */
    public function test_the_matrix_agrees_with_the_readme_exit_table(): void
    {
        $rows = Readme::table('### Reading the distribution: `db:replica-status`', 'what it means');
        $situations = self::documentedSituations();

        $this->assertEqualsCanonicalizing(
            array_keys(self::exitCodeProvider()),
            array_keys($situations),
            'every cell of the matrix names the README row it is an instance of, and nothing else',
        );

        $this->assertEqualsCanonicalizing(
            array_map([Readme::class, 'plain'], array_values(array_unique($situations))),
            array_map([Readme::class, 'plain'], array_column($rows, 'what it means')),
            'the README documents a route the matrix is not written as, or the matrix is written as one the README does not document',
        );

        // The vocabulary a job branches on, compared with the column a job reads it out of: a kind
        // the report can carry is documented, and every documented kind is one the report carries.
        $this->assertEqualsCanonicalizing(
            array_values(array_unique(array_column(self::routeProvider(), 'kind'))),
            array_map([Readme::class, 'plain'], array_column($rows, 'kind')),
            'a kind the report can carry is documented, and every documented kind is one the report carries',
        );

        foreach (self::exitCodeProvider() as $name => $cell) {
            $label = $situations[$name] ?? $this->fail("No README row is assigned to the [{$name}] cell.");
            $documented = Readme::row($rows, 'what it means', $label);

            $this->assertSame(
                $cell['exit'],
                Readme::code($documented['exit']),
                sprintf(
                    'The README documents [%s] as "%s", while the matrix asserts %d on every channel.',
                    $label,
                    $documented['exit'],
                    $cell['exit'],
                ),
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
