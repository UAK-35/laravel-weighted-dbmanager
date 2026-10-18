<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Pgcat\PoolProbe;

/**
 * The pool-answerability probe: what it sends, and what it makes of the answer.
 *
 * The class needs no credentials because the whole exchange is one startup packet and the first
 * message back: a name with no pool is refused in pgcat's own sentence, and a name the pooler will
 * talk about is answered — so the interesting assertions are about the *bytes*: a well-formed
 * startup packet, and a first message read as the three answers. Both are pure, which is why they
 * are tested here without a socket — the one test that does reach a socket injects the exchange, so
 * the answer is the test's rather than a pooler's. What a pooler does *not* prove by answering is
 * `PoolProbe`'s own note: the pooler this package was measured against answers an authentication
 * request for a name no configuration declares, so on that pooler `answered` is a statement about
 * the pooler. The control name and `discriminates()` are how the sweep says which of the two it is
 * looking at, and their two tests are here beside the three answers they qualify.
 */
final class PoolProbeTest extends TestCase
{
    /**
     * A PostgreSQL message: `[type][int32 length][payload]`, the length counting itself.
     */
    private static function message(string $type, string $payload): string
    {
        return $type.pack('N', 4 + strlen($payload)).$payload;
    }

    /**
     * An ErrorResponse carrying one message — the field pgcat's refusal is written in.
     */
    private static function error(string $message): string
    {
        return self::message('E', 'S'."FATAL\0".'M'.$message."\0"."\0");
    }

    /**
     * The startup packet is the whole reason no credentials are needed, so its shape is pinned:
     * the length prefix counts itself, the protocol is 3.0, and the pool name travels as the
     * `database` parameter — that is the field pgcat matches a pool against.
     */
    public function test_the_startup_packet_is_a_well_formed_postgresql_v3_open(): void
    {
        $packet = PoolProbe::startupPacket('postgresAdmin', 'reader_db_2');

        /** @var int $length */
        $length = unpack('N', substr($packet, 0, 4))[1];
        /** @var int $protocol */
        $protocol = unpack('N', substr($packet, 4, 4))[1];

        $this->assertSame(strlen($packet), $length, 'the length prefix counts itself');
        $this->assertSame(196608, $protocol, 'protocol version 3.0');
        $this->assertStringContainsString("user\0postgresAdmin\0", $packet);
        $this->assertStringContainsString("database\0reader_db_2\0", $packet);
        $this->assertStringEndsWith("\0", $packet, 'the parameter list is closed by a final zero byte');
    }

    /**
     * The pool names come off the variant the flip is about, so the probe has no second list to
     * keep in step with the file. A header that is not a pool section is not one.
     */
    public function test_the_declared_pools_are_the_section_headers_and_nothing_else(): void
    {
        $toml = <<<'TOML'
        [general]
        host = "0.0.0.0"
        port = 5433

        [pools.reader_db_2]
        pool_mode = "transaction"

        [pools.reader_db_3]
        pool_mode = "transaction"

        [pools.reader_db_2]
        duplicated = true

        [pools."writer_db"]
        pool_mode = "transaction"
        TOML;

        $this->assertSame(
            ['reader_db_2', 'reader_db_3', 'writer_db'],
            PoolProbe::declaredPools($toml),
        );

        $this->assertSame([], PoolProbe::declaredPools("[general]\nport = 5433\n"));
    }

    /**
     * The header shape the deployed pgcat file actually has, taken from it: `writer_db_1` is
     * declared by nothing but its `users.0` and `shards.0` sub-tables — there is no
     * `[pools.writer_db_1]` line in that file at all — and the pooler answers for `writer_db_1`,
     * because a pool's name is the first segment of a header's path. A reading that took the whole
     * path asked the pooler about `writer_db_1.users.0`, which no client ever sends; one that
     * insisted on a bare `[pools.<name>]` header would ask about nothing at all.
     */
    public function test_a_pool_declared_by_its_sub_tables_is_read_by_the_first_segment(): void
    {
        $toml = <<<'TOML'
        [general]
        host = "0.0.0.0"
        port = 5433

        [pools.writer_db_1.users.0]
        username = "pgcAdmin"
        pool_size = 1
        pool_mode = "transaction"

        [pools.writer_db_1.shards.0]
        servers = [["lpr-db-01", 5432, "primary"]]

        [pools.reader_db_2]
        pool_mode = "transaction"

        [pools."writer.db".users.0]
        username = "pgcAdmin"
        TOML;

        $this->assertSame(
            ['writer_db_1', 'reader_db_2', 'writer.db'],
            PoolProbe::declaredPools($toml),
            'the name is the first segment of the header path, and a quoted segment keeps its dots',
        );
    }

    /**
     * The control name: a name no configuration declares, so a pooler that refuses it is one that
     * resolves a pool before it authenticates. The suffix is random so that a pool an installation
     * happens to have named cannot make the control answer for a pool; the prefix is what a report
     * recognises.
     */
    public function test_the_control_is_a_name_no_configuration_declares(): void
    {
        $control = PoolProbe::controlName();

        $this->assertStringStartsWith('weighted-dbmanager-control-', $control);
        $this->assertSame(27, strlen('weighted-dbmanager-control-'), 'the prefix is the recognisable half');
        $this->assertSame(27 + 16, strlen($control), 'and the hexadecimal suffix is what makes it a name no file holds');
        $this->assertMatchesRegularExpression('/^weighted-dbmanager-control-[0-9a-f]{16}$/', $control);
    }

    /**
     * What the control's answer says about every other answer in the sweep: a refusal means the
     * pooler read the name before it asked to authenticate, so the pools it accepted exist; an
     * answer means it authenticates first and answers every name; silence concludes nothing.
     */
    public function test_the_control_distinguishes_a_pooler_that_reads_pools_from_one_that_does_not(): void
    {
        $this->assertTrue(PoolProbe::discriminates(PoolProbe::STATUS_MISSING));
        $this->assertFalse(PoolProbe::discriminates(PoolProbe::STATUS_ANSWERED));
        $this->assertNull(PoolProbe::discriminates(PoolProbe::STATUS_SILENT));
    }

    /**
     * The three answers. `R` is the pooler asking for a password, which is an answer rather than a
     * refusal — and deliberately the strongest thing this probe says: the two are not the same
     * claim, because a pooler can ask before it looks the pool up — which is why the sweep asks a
     * control name beside it; see `test_the_control_distinguishes_a_pooler_that_reads_pools_from_one_that_does_not()`
     * and `PoolProbe`'s own note for what that costs a caller.
     */
    public function test_an_authentication_request_is_an_answer_rather_than_a_refusal(): void
    {
        $verdict = PoolProbe::classify(self::message('R', pack('N', 5)), 'reader_db_2');

        $this->assertSame(PoolProbe::STATUS_ANSWERED, $verdict['status']);
        $this->assertTrue($verdict['answered']);
        $this->assertFalse($verdict['missing']);
    }

    /**
     * The exact fault this exists for, and the exact bytes pgcat answers it with.
     */
    public function test_pgcats_own_refusal_is_the_missing_pool(): void
    {
        $verdict = PoolProbe::classify(
            self::error('No pool configured for database: "reader_db_2", user: "postgresAdmin"'),
            'reader_db_2',
        );

        $this->assertSame(PoolProbe::STATUS_MISSING, $verdict['status']);
        $this->assertFalse($verdict['answered']);
        $this->assertTrue($verdict['missing']);
        $this->assertStringContainsString('reader_db_2', $verdict['evidence']);
        $this->assertStringContainsString('No pool configured for database', $verdict['answer']);
    }

    /**
     * An error that is *not* a missing pool is a pool that exists with a different problem: the
     * pooler got past the pool lookup to find it. Reported as answered so it is never the reason a
     * healthy pooler is restarted.
     */
    public function test_another_error_is_a_pool_that_exists_with_a_different_problem(): void
    {
        $verdict = PoolProbe::classify(self::error('password authentication failed'), 'reader_db_2');

        $this->assertSame(PoolProbe::STATUS_ANSWERED, $verdict['status']);
        $this->assertTrue($verdict['answered']);
        $this->assertFalse($verdict['missing']);
        $this->assertStringContainsString('password authentication failed', $verdict['evidence']);
    }

    /**
     * Nothing back is silent, never "missing": a pooler that did not answer has said nothing about
     * its pools, and treating that as a fault is how a probe on the wrong address restarts a
     * healthy pooler.
     */
    public function test_no_answer_at_all_is_silent_rather_than_missing(): void
    {
        $verdict = PoolProbe::classify('', 'reader_db_2');

        $this->assertSame(PoolProbe::STATUS_SILENT, $verdict['status']);
        $this->assertFalse($verdict['answered']);
        $this->assertFalse($verdict['missing']);
    }

    /**
     * The probe over an injected exchange — the shape the flipper calls. The exchange is handed the
     * startup packet, the address and the timeout, and its answer is what is classified.
     */
    public function test_ask_sends_the_packet_and_classifies_the_answer(): void
    {
        $seen = [];

        $verdict = PoolProbe::ask(
            '127.0.0.1',
            5433,
            'reader_db_2',
            'postgresAdmin',
            1500,
            function (string $packet, string $address, float $timeout) use (&$seen): array {
                $seen = [$packet, $address, $timeout];

                return [self::error('No pool configured for database: "reader_db_2"'), ''];
            },
        );

        $this->assertSame('tcp://127.0.0.1:5433', $seen[1]);
        $this->assertSame(1.5, $seen[2]);
        $this->assertStringContainsString("database\0reader_db_2\0", (string) $seen[0]);

        $this->assertSame(PoolProbe::STATUS_MISSING, $verdict['status']);
        $this->assertTrue($verdict['missing']);
        $this->assertSame('127.0.0.1', $verdict['host']);
        $this->assertSame(5433, $verdict['port']);
        $this->assertSame('tcp://127.0.0.1:5433', $verdict['address']);
    }

    /**
     * A connection that never opened carries the socket's own reason into the evidence, so an
     * operator reads why the probe was silent rather than only that it was.
     */
    public function test_a_connection_that_fails_is_silent_and_says_why(): void
    {
        $verdict = PoolProbe::ask(
            '10.0.0.9',
            5433,
            'reader_db_2',
            'postgresAdmin',
            500,
            static fn (string $packet, string $address, float $timeout): array
                => ['', 'Connection refused'],
        );

        $this->assertSame(PoolProbe::STATUS_SILENT, $verdict['status']);
        $this->assertFalse($verdict['missing']);
        $this->assertStringContainsString('10.0.0.9:5433', $verdict['evidence']);
        $this->assertStringContainsString('Connection refused', $verdict['evidence']);
    }
}
