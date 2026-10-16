<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Weighted;

use Closure;
use PDO;
use RuntimeException;
use Uak35\WeightedDbManager\Database\Weighted\EndpointReadings;
use Uak35\WeightedDbManager\Database\Weighted\WeightedDatabaseManager;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * What the endpoints on a read path say about themselves: the caps, the standby feedback setting, and
 * the `read`/`write` pair a pinned statement depends on.
 *
 * WHY THIS IS A FILE OF ITS OWN
 *   The readings are the one part of the preflight that leaves the process — one connection per
 *   endpoint — so the question they answer is a fact about *servers*, and the shapes it comes in are
 *   the interesting part rather than the values. PostgreSQL prints a `statement_timeout` in the
 *   largest unit that divides it exactly, so one cap arrives as `90s`, another as `2min` and a third
 *   as `600ms`; `0` is the documented way to switch a cap off and is not the same reading as an
 *   endpoint nobody asked; a standby that is not in recovery has no feedback setting to judge; and an
 *   endpoint that refused is a silence rather than a failure. Each of those is a row here.
 *
 *   The sweep is injected, so no row opens a socket: the closure is handed the base config, the host
 *   map and the statement, and answers with a row per endpoint — which is also where the two
 *   invariants that only exist on the way out are asserted. The base config carries the connect bound
 *   (`PDO::ATTR_TIMEOUT`, from `swrr.read_path.timeout_ms`), because a preflight that waited on an
 *   endpoint that is not there would be a preflight nobody runs; and the statement it is handed is
 *   the one that asks for the settings being reported, because a reading that asked for something
 *   else would report it under a name it does not have.
 *
 *   What the *rows* do with a reading — the verdict a warning becomes, the switch that arms the
 *   sweep, the mode that leaves it out — belongs to `DbDoctorTest`, where the fixtures for a whole
 *   run are.
 *
 * @see \Uak35\WeightedDbManager\Tests\Unit\Console\DbDoctorTest
 */
final class EndpointReadingsTest extends TestCase
{
    /** The writer most rows are configured with. */
    private const WRITER = 'writer.example';

    /** The same host as the sentences spell it. */
    private const WRITER_ADDRESS = 'writer.example:5432';

    /** A read endpoint that answers with a 90 s cap. */
    private const READER_ONE = '10.1.0.1';

    /** A second read endpoint. */
    private const READER_TWO = '10.1.0.2';

    /**
     * A standby's own answer: capped at 90 s, in recovery, keeping the feedback setting on.
     *
     * @return array<string, string>
     */
    private function standby(string $cap = '90s', string $feedback = 'on', string $recovery = 't'): array
    {
        return [
            'statement_timeout' => $cap,
            'hot_standby_feedback' => $feedback,
            'in_recovery' => $recovery,
        ];
    }

    /**
     * A sweep that answers per endpoint, and refuses the ones it has no answer for.
     *
     * The refusal is a throw, which is what a connection that is not listening does — the closure
     * stands in for `SingleHostProbe`, so the shape a real refusal arrives in is the shape a test
     * hands it. The statement and the connect bound are asserted here rather than in one row, because
     * every reading goes through this closure: a bound that stopped being applied would be a fact
     * about all of them.
     *
     * @param array<string, array<string, string>> $answers keyed by host
     * @return Closure(array<string, mixed>, array<string, mixed>, string): list<array<string, mixed>>
     */
    private function answering(array $answers, string $failure = 'SQLSTATE[08006] connection to server failed'): Closure
    {
        return function (array $base, array $host, string $sql) use ($answers, $failure): array {
            $this->assertStringContainsString(
                'statement_timeout',
                $sql,
                'the reading asks the endpoint for the settings it reports',
            );
            $this->assertSame(
                2,
                $base['options'][PDO::ATTR_TIMEOUT] ?? null,
                'the connect is bounded by the timeout the config names',
            );

            $key = $host['host'] ?? $base['host'];

            if (!is_string($key) || !array_key_exists($key, $answers)) {
                throw new RuntimeException($failure);
            }

            return [$answers[$key]];
        };
    }

    /**
     * The connection every row reads: a writer, and whatever read list the row is about.
     *
     * @param list<array<string, mixed>> $read
     */
    private function configure(array $read = [], bool $write = true, string $host = 'writer.example'): void
    {
        config()->set('database.connections.weighted', array_filter([
            'driver' => 'pgsql',
            'host' => $host,
            'port' => 5432,
            'read' => $read === [] ? null : $read,
            'write' => $write ? ['host' => self::WRITER, 'port' => 5432] : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /** The manager the readings are read through — the real one, so the config is read as routing reads it. */
    private function manager(): WeightedDatabaseManager
    {
        $manager = $this->app->make('db');

        $this->assertInstanceOf(WeightedDatabaseManager::class, $manager);

        return $manager;
    }

    /**
     * @param array<string, array<string, string>> $answers
     */
    private function readings(
        array $answers,
        bool $armed = true,
        ?Closure $query = null,
        int $timeoutMs = EndpointReadings::DEFAULT_TIMEOUT_MS,
    ): EndpointReadings {
        return EndpointReadings::of(
            $this->manager(),
            'weighted',
            $armed,
            $timeoutMs,
            $query ?? $this->answering($answers),
        );
    }

    /**
     * The one reading under test, as `level|sentence` — so a row asserts which of them a shape
     * produces rather than reading two arrays twice.
     *
     * @param array{level: string, sentence: string} $reading
     */
    private function flat(array $reading): string
    {
        return $reading['level'].'|'.$reading['sentence'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // caps(): what each endpoint is capped at
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The pair the recording under `docs/` was measured on, and the reason the row exists at all: two
     * sides capped differently, on purpose, so a read a read endpoint cut can be answered by the
     * writer. A fact rather than a warning — the whole reason a statement that times out on a replica
     * can be escalated is that the caps differ.
     */
    public function test_the_two_sides_capped_differently_is_reported_as_the_reason_escalation_works(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $caps = $this->readings([
            self::READER_ONE => $this->standby('90s'),
            'writer.example' => $this->standby('2min', 'off', 'f'),
        ])->caps();

        $this->assertSame(EndpointReadings::LEVEL_OK, $caps['level']);
        $this->assertStringContainsString('capped differently on purpose', $caps['sentence']);
        $this->assertStringContainsString('10.1.0.1:5432 at 90 s', $caps['sentence']);
        $this->assertStringContainsString('the writer at 2 minute(s)', $caps['sentence']);
        $this->assertStringContainsString('escalated', $caps['sentence']);
    }

    /**
     * The state a cap far below the writer's is: the read endpoint cuts statements the writer was
     * configured not to cut, so the routing decision is what turns a slow read into a cancelled one.
     * The bound is a fraction rather than a number of milliseconds, so the row says which of the two
     * things to change.
     */
    public function test_a_read_endpoint_capped_far_below_the_writer_is_a_warning(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $caps = $this->readings([
            self::READER_ONE => $this->standby('600ms'),
            'writer.example' => $this->standby('2min', 'off', 'f'),
        ])->caps();

        $this->assertSame(EndpointReadings::LEVEL_WARN, $caps['level']);
        $this->assertStringContainsString('a read cut on [weighted] is one the writer\'s cap could have answered', $caps['sentence']);
        $this->assertStringContainsString('10.1.0.1:5432 is capped at 600 ms', $caps['sentence']);
        $this->assertStringContainsString('Raise `statement_timeout` on that parameter group, or take the endpoint out', $caps['sentence']);
    }

    /**
     * The other half of the same rule: 90 s against 120 s is a difference a read survives, and a row
     * that warned about every difference would fail the installation the recording was measured on.
     */
    public function test_a_cap_below_the_writer_but_not_far_below_is_not_a_warning(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $caps = $this->readings([
            self::READER_ONE => $this->standby('90s'),
            'writer.example' => $this->standby('120s', 'off', 'f'),
        ])->caps();

        $this->assertSame(EndpointReadings::LEVEL_OK, $caps['level']);
        $this->assertStringContainsString('capped differently on purpose', $caps['sentence']);
    }

    /** An uncapped read endpoint is not "far below" anything: nothing cuts a statement there. */
    public function test_an_uncapped_read_endpoint_is_a_reading_and_not_a_comparison(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $caps = $this->readings([
            self::READER_ONE => $this->standby('0'),
            'writer.example' => $this->standby('2min', 'off', 'f'),
        ])->caps();

        $this->assertSame(EndpointReadings::LEVEL_OK, $caps['level']);
        $this->assertStringContainsString('uncapped (statement_timeout is 0)', $caps['sentence']);
    }

    /** Both sides uncapped is the same fact twice, and it is stated as one. */
    public function test_both_sides_uncapped_are_the_same_cap(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $caps = $this->readings([
            self::READER_ONE => $this->standby('0'),
            'writer.example' => $this->standby('0', 'off', 'f'),
        ])->caps();

        $this->assertSame(EndpointReadings::LEVEL_OK, $caps['level']);
        $this->assertStringContainsString('capped the same way', $caps['sentence']);
        $this->assertStringContainsString('uncapped (statement_timeout is 0)', $caps['sentence']);
    }

    /** Equal caps are a fact worth stating: escalating a cut read buys the caller no more time. */
    public function test_equal_caps_say_that_escalating_buys_nothing(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $caps = $this->readings([
            self::READER_ONE => $this->standby('90s'),
            'writer.example' => $this->standby('90s', 'off', 'f'),
        ])->caps();

        $this->assertSame(EndpointReadings::LEVEL_OK, $caps['level']);
        $this->assertStringContainsString('capped the same way', $caps['sentence']);
        $this->assertStringContainsString('buys its caller no more time', $caps['sentence']);
    }

    /** A connection with no read list has one endpoint to report, and it is the writer. */
    public function test_a_connection_with_no_read_list_reports_the_writer_alone(): void
    {
        $this->configure();

        $caps = $this->readings(['writer.example' => $this->standby('2min', 'off', 'f')])->caps();

        $this->assertSame(EndpointReadings::LEVEL_OK, $caps['level']);
        $this->assertStringContainsString('every read on [weighted] uses the writer, which is capped at 2 minute(s)', $caps['sentence']);
        $this->assertStringContainsString('no second endpoint here', $caps['sentence']);
    }

    /**
     * An endpoint that did not answer is named with what it said, beside the ones that did, and the
     * verdict does not move: reachability is the health monitor's question.
     */
    public function test_an_endpoint_that_did_not_answer_is_named_beside_the_caps_that_did(): void
    {
        $this->configure([
            ['host' => self::READER_ONE, 'port' => 5432],
            ['host' => self::READER_TWO, 'port' => 5432],
        ]);

        $caps = $this->readings([
            self::READER_ONE => $this->standby('90s'),
            'writer.example' => $this->standby('120s', 'off', 'f'),
        ])->caps();

        $this->assertSame(EndpointReadings::LEVEL_OK, $caps['level']);
        $this->assertStringContainsString('capped differently on purpose', $caps['sentence']);
        $this->assertStringContainsString(
            '10.1.0.2:5432 did not answer (SQLSTATE[08006] connection to server failed)',
            $caps['sentence'],
        );
        $this->assertStringContainsString('and nothing here is read from', $caps['sentence']);
    }

    /** Nothing answered at all: every endpoint is named, and the writer's answer is still stated. */
    public function test_no_read_endpoint_answering_is_a_silence_that_names_every_one(): void
    {
        $this->configure([
            ['host' => self::READER_ONE, 'port' => 5432],
            ['host' => self::READER_TWO, 'port' => 5432],
        ]);

        $caps = $this->readings(['writer.example' => $this->standby('120s', 'off', 'f')])->caps();

        $this->assertSame(EndpointReadings::LEVEL_OK, $caps['level']);
        $this->assertStringContainsString('no read endpoint on [weighted] could be asked what it is capped at', $caps['sentence']);
        $this->assertStringContainsString('10.1.0.1:5432 did not answer', $caps['sentence']);
        $this->assertStringContainsString('10.1.0.2:5432 did not answer', $caps['sentence']);
        $this->assertStringContainsString('the writer answered (2 minute(s))', $caps['sentence']);
    }

    /** A writer that did not answer cannot be compared with anything, and the row says so. */
    public function test_a_writer_that_did_not_answer_leaves_the_read_caps_uncompared(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $caps = $this->readings([self::READER_ONE => $this->standby('90s')])->caps();

        $this->assertSame(EndpointReadings::LEVEL_OK, $caps['level']);
        $this->assertStringContainsString('the writer of [weighted] could not be asked what it is capped at', $caps['sentence']);
        $this->assertStringContainsString('10.1.0.1:5432 at 90 s', $caps['sentence']);
        $this->assertStringContainsString('the two sides cannot be compared', $caps['sentence']);
    }

    /**
     * A cap PostgreSQL prints in a unit this cannot read is not read as a cap: `statement_timeout` is
     * the value a sentence reports, and reporting one it invented would be worse than reporting that
     * the endpoint did not say.
     */
    public function test_a_cap_this_cannot_read_is_reported_as_an_endpoint_that_did_not_answer(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $caps = $this->readings([
            self::READER_ONE => $this->standby('forever'),
            'writer.example' => $this->standby('2min', 'off', 'f'),
        ])->caps();

        $this->assertSame(EndpointReadings::LEVEL_OK, $caps['level']);
        $this->assertStringContainsString(
            'it answered with a `statement_timeout` this cannot read (\'forever\')',
            $caps['sentence'],
        );
    }

    /** An answer with no settings in it at all is a silence, not a zero. */
    public function test_an_answer_without_the_settings_is_a_silence(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $caps = $this->readings([
            self::READER_ONE => [],
            'writer.example' => $this->standby('2min', 'off', 'f'),
        ])->caps();

        $this->assertStringContainsString(
            'it answered without the settings this reading asks for',
            $caps['sentence'],
        );
    }

    /**
     * The units PostgreSQL prints: the largest one that divides the value exactly. One cap arrives
     * three ways depending only on its value, so the parser reads all three — and `0`, which is the
     * documented way to switch a cap off, is a cap of nothing rather than a missing answer.
     */
    public function test_the_caps_postgres_prints_are_read_in_the_unit_they_come_in(): void
    {
        foreach ([
            '90s' => '90 s',
            '2min' => '2 minute(s)',
            '600ms' => '600 ms',
            '1h' => '60 minute(s)',
            '1.5s' => '1500 ms',
            '90000' => '90 s',
            '90000ms' => '90 s',
            '0' => 'uncapped (statement_timeout is 0)',
        ] as $printed => $reads) {
            $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

            $caps = $this->readings([
                self::READER_ONE => $this->standby((string) $printed),
                self::WRITER => $this->standby((string) $printed, 'off', 'f'),
            ])->caps();

            $this->assertStringContainsString(
                '10.1.0.1:5432 at '.$reads,
                $caps['sentence'],
                "PostgreSQL printed [{$printed}] for this cap",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // feedback(): whether the standbys keep the primary's row versions
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The failure the recording under `docs/` is about: a standby that does not ask the primary to
     * hold a query's snapshot can have the read cancelled by a conflict with recovery, and the
     * sentence names both repairs rather than one value.
     */
    public function test_a_standby_without_the_feedback_setting_is_a_warning(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $feedback = $this->readings([
            self::READER_ONE => $this->standby('90s', 'off'),
            'writer.example' => $this->standby('120s', 'off', 'f'),
        ])->feedback();

        $this->assertSame(EndpointReadings::LEVEL_WARN, $feedback['level']);
        $this->assertStringContainsString('a read on [weighted] can be cancelled by a conflict with recovery', $feedback['sentence']);
        $this->assertStringContainsString('10.1.0.1:5432 at 90 s in recovery with `hot_standby_feedback` off', $feedback['sentence']);
        $this->assertStringContainsString('SQLSTATE 40001', $feedback['sentence']);
        $this->assertStringContainsString('Turn the setting on for that parameter group, or take the endpoint out', $feedback['sentence']);
    }

    /** Every standby keeping it on is the state a read path wants, and the row says which ones. */
    public function test_every_standby_keeping_the_setting_on_is_a_pass(): void
    {
        $this->configure([
            ['host' => self::READER_ONE, 'port' => 5432],
            ['host' => self::READER_TWO, 'port' => 5432],
        ]);

        $feedback = $this->readings([
            self::READER_ONE => $this->standby('90s', 'on'),
            self::READER_TWO => $this->standby('90s', 'true'),
            'writer.example' => $this->standby('120s', 'off', 'f'),
        ])->feedback();

        $this->assertSame(EndpointReadings::LEVEL_OK, $feedback['level']);
        $this->assertStringContainsString('every standby [weighted] sends reads to keeps `hot_standby_feedback` on', $feedback['sentence']);
        $this->assertStringContainsString('10.1.0.1:5432 at 90 s', $feedback['sentence']);
        $this->assertStringContainsString('10.1.0.2:5432 at 90 s', $feedback['sentence']);
    }

    /**
     * A read list that reaches a primary has nothing to be cancelled by, which is the *other* reason
     * the setting is off — so the row says which of the two it found rather than judging `off`.
     */
    public function test_a_read_endpoint_that_is_not_in_recovery_has_no_feedback_setting_to_judge(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $feedback = $this->readings([
            self::READER_ONE => $this->standby('90s', 'off', 'f'),
            'writer.example' => $this->standby('120s', 'off', 'f'),
        ])->feedback();

        $this->assertSame(EndpointReadings::LEVEL_OK, $feedback['level']);
        $this->assertStringContainsString('no endpoint in the read list of [weighted] is in recovery', $feedback['sentence']);
        $this->assertStringContainsString('reach a primary and nothing there is cancelled by a conflict with recovery', $feedback['sentence']);
    }

    /** A connection with no read list has no standby for the question to be about. */
    public function test_a_connection_with_no_read_list_has_no_standby(): void
    {
        $this->configure();

        $feedback = $this->readings(['writer.example' => $this->standby('2min', 'off', 'f')])->feedback();

        $this->assertSame(EndpointReadings::LEVEL_OK, $feedback['level']);
        $this->assertStringContainsString('every read on [weighted] reaches the writer', $feedback['sentence']);
        $this->assertStringContainsString('no standby to read `hot_standby_feedback` from', $feedback['sentence']);
    }

    /** An unreadable feedback value is a silence about that endpoint, not `off`. */
    public function test_a_feedback_setting_that_did_not_arrive_is_not_read_as_off(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $feedback = $this->readings([
            self::READER_ONE => ['statement_timeout' => '90s', 'in_recovery' => 't'],
            'writer.example' => $this->standby('120s', 'off', 'f'),
        ])->feedback();

        $this->assertSame(
            EndpointReadings::LEVEL_OK,
            $feedback['level'],
            'an endpoint that answered without the setting is not reported as one that has it off: '.$this->flat($feedback),
        );
        $this->assertStringContainsString(
            '10.1.0.1:5432 answered in recovery without the setting, so whether it keeps it on is not known here',
            $feedback['sentence'],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // pair(): what a statement pinned to the write connection reaches
    // ─────────────────────────────────────────────────────────────────────────

    /** Both halves declared: a pin escapes the pool, which is the shape the application pins against. */
    public function test_both_halves_declared_say_a_pin_is_answered_by_the_writer(): void
    {
        $this->configure([
            ['host' => self::READER_ONE, 'port' => 5432],
            ['host' => self::READER_TWO, 'port' => 5432],
        ]);

        $pair = $this->readings([])->pair();

        $this->assertSame(EndpointReadings::LEVEL_OK, $pair['level']);
        $this->assertStringContainsString('connection [weighted] declares both halves of the pair', $pair['sentence']);
        $this->assertStringContainsString('reads reach 2 read endpoint(s) (10.1.0.1:5432, 10.1.0.2:5432)', $pair['sentence']);
        $this->assertStringContainsString('writes reach '.self::WRITER_ADDRESS.' from its `write` block', $pair['sentence']);
    }

    /**
     * The reading is answered whether or not the probe is armed — it asks nothing — which is the one
     * of the three that costs nothing and the reason it is not in `--connection-only`'s list.
     */
    public function test_the_pair_is_read_from_the_config_whether_or_not_the_probe_is_armed(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $armed = $this->readings([self::READER_ONE => $this->standby('90s'), 'writer.example' => $this->standby('120s', 'off', 'f')])->pair();
        $unarmed = $this->readings([], armed: false)->pair();

        $this->assertSame($armed, $unarmed, 'the pair is a fact about the configuration, and the switch is not');
        $this->assertStringContainsString('reads reach 1 read endpoint(s) (10.1.0.1:5432)', $armed['sentence']);
    }

    /** A `write` block and no read list: every read already uses the writer. */
    public function test_a_write_block_without_a_read_list_says_a_pin_changes_nothing(): void
    {
        $this->configure();

        $pair = $this->readings(['writer.example' => $this->standby('2min', 'off', 'f')])->pair();

        $this->assertSame(EndpointReadings::LEVEL_OK, $pair['level']);
        $this->assertStringContainsString('declares a `write` block and no `read` list', $pair['sentence']);
        $this->assertStringContainsString('reads and writes both reach '.self::WRITER_ADDRESS, $pair['sentence']);
        $this->assertStringContainsString('pinning a statement to it changes nothing', $pair['sentence']);
    }

    /** A read list and no `write` block: a pinned statement reaches the connection's own host. */
    public function test_a_read_list_without_a_write_block_names_the_connections_own_host(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]], write: false, host: 'pooler.example');

        $pair = $this->readings([self::READER_ONE => $this->standby('90s'), 'pooler.example' => $this->standby('120s', 'off', 'f')])->pair();

        $this->assertStringContainsString('declares a `read` list and no `write` block', $pair['sentence']);
        $this->assertStringContainsString('writes reach pooler.example:5432', $pair['sentence']);
        $this->assertStringContainsString('the connection\'s own host', $pair['sentence']);
    }

    /** Neither half: one host answers everything, so a pin has nothing to escape. */
    public function test_neither_half_says_one_host_answers_everything(): void
    {
        $this->configure([], write: false);

        $pair = $this->readings([])->pair();

        $this->assertStringContainsString('declares neither half of the pair', $pair['sentence']);
        $this->assertStringContainsString('one host ('.self::WRITER_ADDRESS.') answers every read and every write', $pair['sentence']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // the switch, the bound, and a connection nothing can be read from
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The switch off is not silence: both probing rows name it, and one of them names the recording
     * the failure they exist for is written in.
     */
    public function test_the_sweep_not_being_armed_is_reported_by_both_probing_rows(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $readings = $this->readings([], armed: false);

        $this->assertStringContainsString(
            'the probe is not armed (db-manager.swrr.read_path.enabled)',
            $readings->caps()['sentence'],
        );
        $this->assertStringContainsString(
            'the probe is not armed (db-manager.swrr.read_path.enabled)',
            $readings->feedback()['sentence'],
        );
        $this->assertStringContainsString('docs/read-path-cancellations.md', $readings->feedback()['sentence']);
    }

    /** A connection this package cannot resolve is one fact, named by the row that owns it. */
    public function test_a_connection_that_cannot_be_read_is_not_judged_by_any_of_the_three(): void
    {
        config()->set('database.connections.weighted', ['driver' => 'pgsql']);

        $readings = EndpointReadings::of($this->manager(), 'gone', true, EndpointReadings::DEFAULT_TIMEOUT_MS, $this->answering([]));

        foreach ([$readings->caps(), $readings->feedback(), $readings->pair()] as $reading) {
            $this->assertSame(EndpointReadings::LEVEL_OK, $reading['level']);
            $this->assertStringContainsString('the connection [gone] could not be read', $reading['sentence']);
            $this->assertStringContainsString('`replica metadata` is the row that reports', $reading['sentence']);
        }
    }

    /**
     * The connect bound is the config's, rounded up to whole seconds because that is the unit the
     * driver takes: a preflight that could not be bounded is a preflight somebody has to wait out.
     */
    public function test_the_probe_reports_its_own_bound_and_never_waits_longer_than_a_second(): void
    {
        $this->configure([['host' => self::READER_ONE, 'port' => 5432]]);

        $seen = [];

        $readings = $this->readings(
            [self::READER_ONE => $this->standby('90s'), 'writer.example' => $this->standby('120s', 'off', 'f')],
            query: function (array $base, array $host, string $sql) use (&$seen): array {
                $seen[] = $base['options'][PDO::ATTR_TIMEOUT] ?? null;

                return [['statement_timeout' => '90s', 'hot_standby_feedback' => 'on', 'in_recovery' => 't']];
            },
            timeoutMs: 250,
        );

        $readings->caps();

        $this->assertSame([1, 1], $seen, 'a sub-second bound is still one whole second, which is the driver\'s unit');
    }

    /** A connection whose `read` is one map rather than a list is one read endpoint, as routing reads it. */
    public function test_a_read_list_written_as_one_map_is_one_read_endpoint(): void
    {
        config()->set('database.connections.weighted', [
            'driver' => 'pgsql',
            'host' => 'writer.example',
            'port' => 5432,
            'read' => ['host' => self::READER_ONE, 'port' => 5432],
            'write' => ['host' => self::WRITER, 'port' => 5432],
        ]);

        $pair = $this->readings([self::READER_ONE => $this->standby('90s'), self::WRITER => $this->standby('120s', 'off', 'f')])->pair();

        $this->assertStringContainsString('reads reach 1 read endpoint(s) (10.1.0.1:5432)', $pair['sentence']);
        $this->assertStringContainsString('writes reach '.self::WRITER_ADDRESS, $pair['sentence']);
    }
}
