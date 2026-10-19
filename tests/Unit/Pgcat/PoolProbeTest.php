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
 * looking at, and their two tests are here beside the three answers they qualify. The one exchange
 * that does send a credential is the admin console's, and it sends the pooler's own: those tests
 * script the conversation so the md5 answer, the refusals and the reading of `SHOW POOLS` are
 * pinned as bytes rather than left to a pooler.
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

    /** An AuthenticationOk: the console took the password and is ready to be asked. */
    private static function authenticated(): string
    {
        return self::message('R', pack('N', 0)).self::message('Z', 'I');
    }

    /** An md5 challenge with the four salt bytes the answer has to fold in. */
    private static function md5Challenge(string $salt = 'abcd'): string
    {
        return self::message('R', pack('N', 5).$salt);
    }

    /**
     * A RowDescription — the message that names the columns of a `SHOW POOLS` answer, and the only
     * place the pool column is identified rather than assumed.
     *
     * @param list<string> $names
     */
    private static function rowDescription(array $names): string
    {
        $payload = pack('n', count($names));

        foreach ($names as $name) {
            // name, table oid, column number, type oid, type size, type modifier, format.
            $payload .= $name."\0".pack('N', 0).pack('n', 0).pack('N', 25).pack('n', -1).pack('N', -1).pack('n', 0);
        }

        return self::message('T', $payload);
    }

    /**
     * A DataRow: one value per column, or a null when the value is unset.
     *
     * @param list<string|null> $values
     */
    private static function dataRow(array $values): string
    {
        $payload = pack('n', count($values));

        foreach ($values as $value) {
            $payload .= $value === null
                ? pack('N', 0xFFFFFFFF)
                : pack('N', strlen($value)).$value;
        }

        return self::message('D', $payload);
    }

    /** The pooler's own file, with the console's credentials in `[general]`. */
    private static function poolerFile(): string
    {
        return <<<'TOML'
        [general]
        host = "0.0.0.0"
        port = 7432
        admin_username = "pgcAdmin"
        admin_password = "s3cret"

        [pools.reader_db_2]
        pool_mode = "transaction"
        TOML;
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

        [pools.'literal.db'.shards.0]
        servers = [["lpr-db-01", 5432, "primary"]]
        TOML;

        $this->assertSame(
            ['writer_db_1', 'reader_db_2', 'writer.db', 'literal.db'],
            PoolProbe::declaredPools($toml),
            'the name is the first segment of the header path, and a quoted segment keeps its dots',
        );
    }

    /**
     * The shape the pooler's own file has, taken from the workflow's fixture: a `[general]` block,
     * one pool with a parent table and its `users.0` and `shards.0` sub-tables, and a second pool
     * declared by nothing but those sub-tables. The reading is one name per pool — never
     * `writer_db_1.users.0`, never `writer_db_1.shards.0`, and never the same pool twice because
     * the parent and the sub-tables both name it.
     *
     * The second pool is the half a reading that stopped at the first dotted header would miss: a
     * header is a path, and the pool is the segment before every dot.
     */
    public function test_a_real_pgcat_config_names_each_pool_once_and_no_sub_table(): void
    {
        $toml = <<<'TOML'
        [general]
        host = "0.0.0.0"
        port = 6432
        admin_username = "pgcAdmin"
        admin_password = "s3cret"
        pool_mode = "transaction"
        enable_prometheus_exporter = false

        [pools.writer_db_1]
        pool_mode = "transaction"

        [pools.writer_db_1.users.0]
        username = "postgres"
        password = "postgres"
        pool_size = 1

        [pools.writer_db_1.shards.0]
        servers = [["127.0.0.1", 5432, "primary"]]
        database = "postgres"

        [pools.reader_db_2.users.0]
        username = "postgres"
        password = "postgres"
        pool_size = 1

        [pools.reader_db_2.shards.0]
        servers = [["reader-db-01", 5432, "replica"]]
        database = "postgres"
        TOML;

        $pools = PoolProbe::declaredPools($toml);

        $this->assertSame(['writer_db_1', 'reader_db_2'], $pools, 'each pool is named once, in file order');
        $this->assertNotContains('writer_db_1.users.0', $pools, 'a sub-table is not a pool a client asks for');
        $this->assertNotContains('writer_db_1.shards.0', $pools);
        $this->assertNotContains('reader_db_2.users.0', $pools);
        $this->assertNotContains('reader_db_2.shards.0', $pools);
    }

    /**
     * The port the probe dials is the one the pooler's own file names, and a file that names none
     * yields null rather than a guess.
     *
     * `[general] port` is the pooler's own statement of where it listens, and `7432` is deliberately
     * not `6432`: a reading that quietly fell back to pgcat's convention would pass a test written
     * against the conventional port, which is why this one is written against a file naming another
     * — and the file is written and read back rather than passed as a string, because the caller
     * this exists for has to read it from disk.
     *
     * The `port` under `[pools.reader_db_2]` is a backend's, and a probe sent there would measure a
     * database rather than the pooler, so it must not be what comes back. There is no second source
     * at all: no separate setting, and no convention for the cases the file cannot answer, because a
     * guessed port is what an installation arms the probe on while its pooler listens elsewhere.
     */
    public function test_the_port_is_the_one_the_poolers_own_file_names(): void
    {
        $file = sys_get_temp_dir().'/pgcat-general-port-'.bin2hex(random_bytes(6)).'.toml';
        file_put_contents($file, <<<'TOML'
        [general]
        host = "0.0.0.0"
        port = 7432

        [pools.reader_db_2]
        port = 9999
        TOML);

        $toml = (string) file_get_contents($file);
        @unlink($file);

        $this->assertSame(7432, PoolProbe::port($toml), "the pooler's own port, and the only source there is");
        $this->assertNull(PoolProbe::port("[general]\nhost = \"0.0.0.0\"\n"), 'a file that names no port states no address to dial');
        $this->assertNull(PoolProbe::port("[pools.reader_db_2]\nport = 9999\n"), 'a pool section is a backend, not the pooler');
        $this->assertNull(PoolProbe::port(''), 'and there is no convention for a file that could not be read');
        $this->assertNull(PoolProbe::port("[general]\nport = 70000\n"), 'a port outside the dialable range is not replaced with a guess');
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

    /**
     * The console conversation, in order: a startup packet naming `pgcat`, the md5 answer to the
     * console's challenge, and `SHOW POOLS` — with its rows read by the column the console names the
     * pool in rather than by position.
     *
     * The salt is fixed because the answer is arithmetic: `md5` . md5(md5(password . user) . salt)
     * is what the pooler compares, so the test can compute the bytes a console would accept.
     * Nothing else is asserted as spelled-out prose: the password itself must not appear in the
     * message, which is what makes this usable on a pooler whose file holds a real one.
     */
    public function test_the_console_is_asked_with_the_poolers_own_credentials(): void
    {
        $sent = [];
        $replies = [
            self::md5Challenge('abcd'),
            self::authenticated(),
            self::rowDescription(['pool', 'user', 'cl_active'])
                .self::dataRow(['writer_db_1', 'pgcAdmin', '0'])
                .self::dataRow(['reader_db_2', 'pgcAdmin', '0'])
                .self::message('C', "SHOW POOLS\0")
                .self::message('Z', 'I'),
        ];

        $result = PoolProbe::adminPools(
            self::poolerFile(),
            '127.0.0.1',
            7432,
            900,
            function (string $client) use (&$sent, &$replies): array {
                $sent[] = $client;

                return [array_shift($replies) ?? '', ''];
            },
        );

        $this->assertSame(PoolProbe::ADMIN_STATUS_READ, $result['status']);
        $this->assertSame(['writer_db_1', 'reader_db_2'], $result['pools']);
        $this->assertSame('tcp://127.0.0.1:7432', $result['address']);
        $this->assertStringContainsString('listed 2 pool(s)', $result['evidence']);

        $this->assertStringContainsString("database\0pgcat\0", $sent[0], 'the console is the admin database, not a pool');
        $this->assertStringContainsString("user\0pgcAdmin\0", $sent[0]);
        $this->assertStringContainsString('md5'.md5(md5('s3cret'.'pgcAdmin').'abcd'), $sent[1], 'the two-pass md5 the console compares');
        $this->assertStringNotContainsString('s3cret', $sent[1], 'the password itself is never sent for an md5 challenge');
        $this->assertSame('Q'.pack('N', 4 + strlen("SHOW POOLS\0"))."SHOW POOLS\0", $sent[2]);
        $this->assertCount(3, $sent, 'one round trip per question, and none for a greeting that was already complete');
    }

    /**
     * A console configured for a trusting admin gets no password at all, and the conversation is
     * one question shorter: the challenge is not there to answer.
     */
    public function test_a_console_that_asks_for_no_password_is_asked_without_one(): void
    {
        $sent = [];
        $replies = [
            self::authenticated(),
            self::rowDescription(['pool']).self::dataRow(['reader_db_2']).self::message('Z', 'I'),
        ];

        $result = PoolProbe::adminPools(
            self::poolerFile(),
            '127.0.0.1',
            7432,
            900,
            function (string $client) use (&$sent, &$replies): array {
                $sent[] = $client;

                return [array_shift($replies) ?? '', ''];
            },
        );

        $this->assertSame(PoolProbe::ADMIN_STATUS_READ, $result['status']);
        $this->assertSame(['reader_db_2'], $result['pools']);
        $this->assertCount(2, $sent, 'no password message for a console that never asked');
    }

    /**
     * The cleartext challenge a console behind an older pgbouncer-style configuration can send: the
     * password travels as itself, because there is no salt to fold in. Reported as the credential
     * that answers the challenge rather than as a candidate answer there is no such thing for.
     */
    public function test_a_cleartext_challenge_gets_the_password_itself(): void
    {
        $sent = [];
        $replies = [
            self::message('R', pack('N', 3)),
            self::authenticated(),
            self::rowDescription(['pool']).self::dataRow(['reader_db_2']).self::message('Z', 'I'),
        ];

        PoolProbe::adminPools(
            self::poolerFile(),
            '127.0.0.1',
            7432,
            900,
            function (string $client) use (&$sent, &$replies): array {
                $sent[] = $client;

                return [array_shift($replies) ?? '', ''];
            },
        );

        $this->assertSame('p'.pack('N', 4 + strlen("s3cret\0"))."s3cret\0", $sent[1]);
    }

    /**
     * A file that names no admin credentials is refused before a socket is opened: pgcat's defaults
     * are a credential this package should not assume, and a `[pools…]` section is a pool's user
     * rather than the console's. The refusal names the setting that is missing, so an operator can
     * add it rather than guess at which of the two the probe wanted.
     */
    public function test_a_file_without_the_consoles_credentials_is_refused_without_a_socket(): void
    {
        $asked = false;

        $result = PoolProbe::adminPools(
            "[general]\nport = 7432\n\n[pools.reader_db_2]\nadmin_username = \"x\"\nadmin_password = \"y\"\n",
            '127.0.0.1',
            7432,
            200,
            function () use (&$asked): array {
                $asked = true;

                return ['', ''];
            },
        );

        $this->assertSame(PoolProbe::ADMIN_STATUS_REFUSED, $result['status']);
        $this->assertSame([], $result['pools']);
        $this->assertStringContainsString('admin_username', $result['evidence']);
        $this->assertFalse($asked, 'a pool section is not the console, and no socket is opened without credentials');
    }

    /**
     * A wrong credential is the console's refusal, not silence: the difference matters because
     * silence is reported as unread and a refusal is a fact an operator can act on. The evidence
     * carries the console's sentence and never the password that was tried.
     */
    public function test_a_console_that_refuses_the_password_is_refused_not_silent(): void
    {
        $replies = [self::md5Challenge(), self::error('password authentication failed for user "pgcAdmin"')];

        $result = PoolProbe::adminPools(
            self::poolerFile(),
            '127.0.0.1',
            7432,
            900,
            function (string $client) use (&$replies): array {
                return [array_shift($replies) ?? '', ''];
            },
        );

        $this->assertSame(PoolProbe::ADMIN_STATUS_REFUSED, $result['status']);
        $this->assertStringContainsString('password authentication failed', $result['evidence']);
        $this->assertStringNotContainsString('s3cret', $result['evidence']);
    }

    /**
     * A console that asks for SASL is refused rather than answered with a guess: a SCRAM verifier is
     * not a password, and this probe does not pretend to hold one. Asked once, so the reading costs
     * one connection rather than a login attempt per theory.
     */
    public function test_a_console_that_asks_for_scram_is_refused(): void
    {
        $asked = 0;
        $reply = self::message('R', pack('N', 10)."SCRAM-SHA-256\0\0");

        $result = PoolProbe::adminPools(
            self::poolerFile(),
            '127.0.0.1',
            7432,
            900,
            function (string $client) use (&$asked, $reply): array {
                $asked++;

                return [$reply, ''];
            },
        );

        $this->assertSame(PoolProbe::ADMIN_STATUS_REFUSED, $result['status']);
        $this->assertStringContainsString('SCRAM-SHA-256', $result['evidence']);
        $this->assertSame(1, $asked, 'the challenge is not something a second question would answer');
    }

    /**
     * A console at an address that does not answer is silent, and the address and the socket's own
     * reason travel in the evidence — the same distinction the pool reading draws, for the same
     * reason: an unread console must not be reported as a poollist.
     */
    public function test_a_console_that_says_nothing_is_silent(): void
    {
        $result = PoolProbe::adminPools(
            self::poolerFile(),
            '10.0.0.9',
            7432,
            200,
            static fn (string $client): array => ['', 'Connection refused'],
        );

        $this->assertSame(PoolProbe::STATUS_SILENT, $result['status']);
        $this->assertSame([], $result['pools']);
        $this->assertStringContainsString('10.0.0.9:7432', $result['evidence']);
        $this->assertStringContainsString('Connection refused', $result['evidence']);
    }

    /**
     * The header upstream pgcat itself writes: v1.2.0's `SHOW POOLS` row description opens
     * `database, user, pool_mode, …` — pgbouncer's word for the name a client connects with — so the
     * reading has to know that name as well as `pool`. This is the shape the live fixture meets, and
     * a console read by a name it does not use is a whole sweep sent to the front door.
     */
    public function test_pgcats_own_show_pools_names_the_pool_column_database(): void
    {
        $replies = [
            self::authenticated(),
            self::rowDescription(['database', 'user', 'pool_mode', 'cl_active'])
                .self::dataRow(['writer_db_1', 'postgres', 'transaction', '0'])
                .self::dataRow(['reader_db_2', 'postgres', 'transaction', '0'])
                .self::message('Z', 'I'),
        ];

        $result = PoolProbe::adminPools(
            self::poolerFile(),
            '127.0.0.1',
            7432,
            900,
            function (string $client) use (&$replies): array {
                return [array_shift($replies) ?? '', ''];
            },
        );

        $this->assertSame(PoolProbe::ADMIN_STATUS_READ, $result['status']);
        $this->assertSame(['writer_db_1', 'reader_db_2'], $result['pools']);
    }

    /**
     * `SHOW POOLS` read by column name, not position: an answer whose columns are named something
     * else has no pool list in it, and saying so is better than taking the first column — which, on
     * this console, is the pool only because the order happens to be that way.
     */
    public function test_an_answer_without_a_pool_column_cannot_be_read(): void
    {
        $replies = [
            self::authenticated(),
            self::rowDescription(['name', 'user']).self::dataRow(['reader_db_2', 'pgcAdmin']).self::message('Z', 'I'),
        ];

        $result = PoolProbe::adminPools(
            self::poolerFile(),
            '127.0.0.1',
            7432,
            900,
            function (string $client) use (&$replies): array {
                return [array_shift($replies) ?? '', ''];
            },
        );

        $this->assertSame(PoolProbe::ADMIN_STATUS_REFUSED, $result['status']);
        $this->assertStringContainsString('pool column', $result['evidence']);
    }
}
