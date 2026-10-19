<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\PoolProbe;

/**
 * The probe's three answers held against a pgcat that is actually running.
 *
 * WHY THIS EXISTS
 *   Everything `PoolProbeTest` pins is a reading of bytes a test wrote. That is the right shape for
 *   the classifier, and it cannot notice the assumptions the bytes rest on: that pgcat's console
 *   names the pool column `pool`, that a startup packet for a name no file declares is answered
 *   rather than refused, that the md5 answer to the console's challenge is the one its file implies,
 *   or that `SHOW POOLS` carries the pools at all. The live job runs upstream pgcat from its
 *   published image and holds the answers against it, so one of those assumptions moving is a red
 *   test rather than a paragraph nobody can check. Two have already been wrong: the image's console
 *   calls the column `database`, and the version the fixture runs answers every name before it looks
 *   one up.
 *
 * HOW IT IS RUN
 *   `PGCAT_LIVE_CONFIG` names the file the pooler is running on — the CI job mounts one into the
 *   image and sets it — and the host, port and user default to `127.0.0.1`, the file's own
 *   `[general] port`, and `postgres`, which the fixture declares; `PGCAT_LIVE_HOST` and
 *   `PGCAT_LIVE_PORT` override the address for a pooler that is not on the loopback, or one whose
 *   port is published somewhere other than where its file says. Without it every test here skips,
 *   which keeps a checkout without Docker green; `PGCAT_LIVE_REQUIRED=1` — what the job sets — turns
 *   the missing fixture into a failure instead, so a job that never reached the pooler cannot pass
 *   by skipping. Both variables are read in `setUp()` and nowhere else.
 *
 *   It lives beside the unit suite because that is the only suite this package has; the class is
 *   marked by what it needs, not by a directory, and the skips are why `composer test:unit` stays
 *   offline. The fixture itself is the repository's workflow, not this file: `tests/Unit` never
 *   starts a container.
 *
 * WHAT IT PINS
 *   `answered` for the pool the running file declares, `silent` for an address that is not
 *   listening, and `missing` through the reading that can produce it on this pooler — the console's
 *   list, held against a variant that declares one pool more than the process holds. The front
 *   door's ordering is pinned as a fact rather than wished away:
 *   `test_a_name_no_file_declares_is_answered_too_so_the_front_door_cannot_prove_one()` fails if
 *   upstream starts refusing unknown names before it authenticates, which is the day the front door
 *   becomes a reading the sweep could rely on and this record has to say so.
 */
final class PoolProbeAgainstPgcatTest extends TestCase
{
    /** The file the pooler is running on, as the CI job mounts it into the image. */
    private const CONFIG_ENV = 'PGCAT_LIVE_CONFIG';

    /** Set by the job: an absent fixture is a failure rather than a skip. */
    private const REQUIRED_ENV = 'PGCAT_LIVE_REQUIRED';

    /** The pool the fixture's running file declares. */
    private const HELD_POOL = 'writer_db_1';

    /** The pool only the variant the flipper is given declares — the one the pooler does not hold. */
    private const ABSENT_POOL = 'reader_db_2';

    /** The user the fixture declares its pool for. */
    private const USER = 'postgres';

    /** @var array{config: string, toml: string, host: string, port: int}|null */
    private ?array $pooler = null;

    /** The directory this test writes its declared variant and boot stamp into. */
    private string $scratch = '';

    protected function setUp(): void
    {
        $config = getenv(self::CONFIG_ENV);
        $config = is_string($config) ? $config : '';
        $toml = $config !== '' && is_readable($config) ? @file_get_contents($config) : false;

        if (! is_string($toml)) {
            $required = getenv(self::REQUIRED_ENV);

            if (is_string($required) && $required === '1') {
                $this->fail(sprintf(
                    '%s=1, but %s names no readable pooler file (%s) — nothing was held against a real pgcat',
                    self::REQUIRED_ENV,
                    self::CONFIG_ENV,
                    $config === '' ? 'unset' : $config,
                ));
            }

            $this->markTestSkipped(sprintf(
                'No live pgcat: set %s to the file the pooler runs on. The CI job mounts one into ghcr.io/postgresml/pgcat and sets this variable.',
                self::CONFIG_ENV,
            ));
        }

        $host = getenv('PGCAT_LIVE_HOST');
        $port = getenv('PGCAT_LIVE_PORT');

        $this->pooler = [
            'config' => $config,
            'toml' => $toml,
            'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
            'port' => is_string($port) && ctype_digit($port) && (int) $port > 0
                ? (int) $port
                : PoolProbe::port($toml),
        ];

        $this->scratch = sys_get_temp_dir().'/pgcat-live-'.bin2hex(random_bytes(6));
        @mkdir($this->scratch, 0o777, true);
    }

    protected function tearDown(): void
    {
        if ($this->scratch === '') {
            return;
        }

        foreach (glob($this->scratch.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->scratch);
        $this->scratch = '';
    }

    /**
     * The reading the sweep asks first, against a console that is really there: the file's own admin
     * credentials answer its md5 challenge, and `SHOW POOLS` names the pool the file declares.
     *
     * The row description this reads is the image's own. Upstream pgcat v1.2.0 calls the pool column
     * `database`, which is why this test exists beside the scripted one: a console read by a name it
     * does not use is a whole sweep sent to the front door, and prose could not tell that.
     */
    public function test_the_running_console_lists_the_pool_its_file_declares(): void
    {
        $pooler = $this->pooler();

        $admin = PoolProbe::adminPools($pooler['toml'], $pooler['host'], $pooler['port'], 5000);

        $this->assertSame(PoolProbe::ADMIN_STATUS_READ, $admin['status'], $admin['evidence']);
        $this->assertContains(self::HELD_POOL, $admin['pools']);
        $this->assertNotContains(self::ABSENT_POOL, $admin['pools']);
    }

    /**
     * `answered`: the pool the running file declares.
     */
    public function test_a_pool_the_pooler_holds_is_answered(): void
    {
        $pooler = $this->pooler();

        $verdict = PoolProbe::ask($pooler['host'], $pooler['port'], self::HELD_POOL, self::USER, 5000);

        $this->assertSame(PoolProbe::STATUS_ANSWERED, $verdict['status'], $verdict['evidence']);
        $this->assertTrue($verdict['answered']);
        $this->assertFalse($verdict['missing']);
    }

    /**
     * `missing` is not something this pooler's front door can say. v1.2.0 answers the md5 challenge
     * first and looks a pool up only after the password, so a name no file declares is answered
     * exactly like one the file holds — and `discriminates()` is false, which is what tells the
     * sweep those answers are not proof.
     *
     * This is the assumption the record used to state in prose, now a fixture: a pgcat that started
     * refusing unknown names before it authenticates fails this test, and that is a change worth
     * being told about rather than one to discover in a repair log.
     */
    public function test_a_name_no_file_declares_is_answered_too_so_the_front_door_cannot_prove_one(): void
    {
        $pooler = $this->pooler();

        $absent = PoolProbe::ask($pooler['host'], $pooler['port'], self::ABSENT_POOL, self::USER, 5000);
        $control = PoolProbe::ask($pooler['host'], $pooler['port'], PoolProbe::controlName(), self::USER, 5000);

        $this->assertSame(
            PoolProbe::STATUS_ANSWERED,
            $absent['status'],
            'pgcat authenticates before it resolves a pool, so a name no file declares is answered too',
        );
        $this->assertSame(
            PoolProbe::STATUS_ANSWERED,
            $control['status'],
            'and the control name is answered, which is how the sweep knows the answer proves nothing',
        );
        $this->assertFalse(PoolProbe::discriminates($control['status']));
    }

    /**
     * `silent`: an address that refuses the connection — the answer the repair must never mistake for
     * a refusal, because restarting a healthy pooler on a mistyped port is worse than the fault the
     * probe exists for.
     *
     * The port is one the kernel has just handed out and the test has closed, so nothing is listening
     * on it by the time the probe dials.
     */
    public function test_an_address_that_is_not_listening_is_silent(): void
    {
        $pooler = $this->pooler();
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        $this->assertIsResource($server, 'the test needs one free port to close: '.$errstr);

        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port, 'the socket named the port it had bound');

        $verdict = PoolProbe::ask($pooler['host'], $port, self::HELD_POOL, self::USER, 1000);

        $this->assertSame(PoolProbe::STATUS_SILENT, $verdict['status'], $verdict['evidence']);
        $this->assertFalse($verdict['answered']);
        $this->assertFalse($verdict['missing']);
    }

    /**
     * `missing`, through the only reading that can produce it on this pooler: the process runs a file
     * that declares one pool, the variant a flip would apply declares two, and the console's own list
     * says which one it does not hold.
     *
     * That is the fault this whole record exists for, with a real pooler on the other end instead of
     * a scripted console: pgcat is RUNNING, supervisord has nothing to say about it, and every read to
     * `reader_db_2` would fail. The boot stamp is an hour old so the reading is judged rather than
     * deferred, which is the line a surface folds into `/health/db`.
     */
    public function test_a_pool_only_the_variant_declares_is_the_missing_answer(): void
    {
        $pooler = $this->pooler();

        $declared = $this->scratch.'/pgcat-readers.toml';
        file_put_contents($declared, $this->declaredVariant($pooler['toml']));

        $boot = $this->scratch.'/boot';
        file_put_contents($boot, (string) (time() - 3600));

        $flipper = new PgcatConfigFlipper(
            resolver: new TimeWindowResolver(
                readerWindows: [['start' => '00:00:00', 'end' => '23:59:59']],
                readerDays: [1, 2, 3, 4, 5, 6, 7],
            ),
            config: [
                'enabled' => true,
                'config_path' => $pooler['config'],
                'readers_path' => $declared,
                'no_readers_path' => $declared,
                'boot_file' => $boot,
                'flip_window_seconds' => 480,
                'pool_probe' => [
                    'enabled' => true,
                    'host' => $pooler['host'],
                    'port' => $pooler['port'],
                    'timeout_ms' => 5000,
                ],
            ],
            stateFile: $this->scratch.'/state.json',
            lockFile: $this->scratch.'/flip.lock',
        );

        $verdict = $flipper->poolProbeVerdict();

        $this->assertTrue($verdict['verified'], 'the console was read, so its list is the reading');
        $this->assertSame(
            PoolProbe::ADMIN_STATUS_READ,
            $verdict['admin']['status'] ?? null,
            (string) ($verdict['admin']['evidence'] ?? ''),
        );
        $this->assertSame(
            [self::HELD_POOL => PoolProbe::STATUS_ANSWERED, self::ABSENT_POOL => PoolProbe::STATUS_MISSING],
            array_column($verdict['pools'], 'status', 'database'),
        );
        $this->assertTrue($verdict['judged'], 'an hour-old boot stamp is past the eight-minute window');
        $this->assertTrue($verdict['failed'], 'the running pooler does not hold the pool the variant declares');
        $this->assertStringContainsString('"'.self::ABSENT_POOL.'"', (string) $verdict['reason']);
        $this->assertStringContainsString('admin console lists no pool', (string) $verdict['reason']);
    }

    /**
     * The variant a flip would apply: the running file's own `[general]` section — so the console's
     * credentials and listen port are the ones the process is actually using — plus both pools.
     *
     * Only the pool headers matter to `declaredPools()`, but keeping the real `[general]` section in
     * the file means the fixture is a file this package would accept, not a shape made up for a test.
     */
    private function declaredVariant(string $running): string
    {
        $general = [];
        $inGeneral = false;

        foreach (preg_split('/\R/', $running) ?: [] as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]/', $line, $header) === 1) {
                $inGeneral = strtolower(trim($header[1])) === 'general';

                if ($inGeneral) {
                    $general[] = $line;
                }

                continue;
            }

            if ($inGeneral) {
                $general[] = $line;
            }
        }

        return implode("\n", $general)
            ."\n\n[pools.".self::HELD_POOL."]\npool_mode = \"transaction\"\n"
            ."\n[pools.".self::ABSENT_POOL."]\npool_mode = \"transaction\"\n";
    }

    /**
     * The live pooler's address and file, or a failure — `setUp()` either configured it or ended the
     * test, so this is only reached when it was.
     *
     * @return array{config: string, toml: string, host: string, port: int}
     */
    private function pooler(): array
    {
        if ($this->pooler === null) {
            $this->fail('the live pooler was not configured, and setUp() should have skipped this test');
        }

        return $this->pooler;
    }
}
