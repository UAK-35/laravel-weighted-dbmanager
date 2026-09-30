<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Docs;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;
use Uak35\WeightedDbManager\Http\Controllers\DatabaseHealthController;
use Uak35\WeightedDbManager\Providers\WeightedDatabaseServiceProvider;
use Uak35\WeightedDbManager\Support\BootAudit;
use Uak35\WeightedDbManager\Tests\Support\Readme;
use Uak35\WeightedDbManager\Tests\TestCase;

/**
 * The alerting cookbook's recipes, run rather than read.
 *
 * A cookbook is the one part of a README that is worse than useless when it is wrong: it is
 * pasted into a monitor nobody looks at until the night it is needed, and a rule that reads a
 * field the payload does not have fails silently — a `jq` expression over a missing key answers
 * `null`, which is neither true nor an error, so the alarm it was written for never fires. The
 * endpoint's field names are the thing that changes when they change, and they change in a
 * commit about something else entirely.
 *
 * So the recipes are treated as code: the gate the README pastes is extracted from the fenced
 * block and **run through `jq`** against a payload this endpoint actually produced — once
 * healthy, once with the pinned query failing — and every field path the section names is
 * resolved against that same payload. A renamed field, a moved block or a rule that has
 * stopped answering the question fails here instead of in production.
 *
 * What is not covered: `jq` has to be on the machine (the test skips without it, as
 * PushingDocTest skips a missing shell), and the log-side keys — `levels`, `kept`, `lock` —
 * are log context rather than payload fields, so they are asserted where they are written, by
 * `BootAuditTest`, and not by walking this payload.
 */
class ReadmeAlertingTest extends TestCase
{
    /** The heading the cookbook lives under, and the section every recipe is read from. */
    private const HEADING = '## Alerting: a cookbook';

    /** The connection the probe follows, as the controller's own test names it. */
    private const PINNED = 'pinned_sqlite';

    public function test_the_gate_the_readme_pastes_answers_the_payload_it_is_written_against(): void
    {
        $program = self::gate();
        $healthy = $this->payload(':memory:');

        $passed = $this->jq($program, $healthy);

        $this->assertSame(
            0,
            $passed['exit'],
            "The gate the README pastes fails a healthy payload, so it would page on a working installation. jq said {$passed['error']}, about:\n{$healthy}",
        );

        // A path under a directory that is never created: the pinned query fails at the
        // connector, which is the condition the gate exists for.
        $failing = $this->payload(sys_get_temp_dir().'/swrr-alert-'.bin2hex(random_bytes(4)).'/dead.sqlite', 503);

        $failed = $this->jq($program, $failing);

        $this->assertNotSame(
            0,
            $failed['exit'],
            'The gate the README pastes passes a payload whose pinned query failed, so it pages nobody.',
        );
    }

    /**
     * One case per condition the gate claims to cover and a fixture can reach here.
     *
     * A gate that passes everything is indistinguishable from a gate whose rules were dropped,
     * and the two conditions below are the ones this fixture can produce without inventing a
     * failing store: the query switched off (a payload that is `ok` and unprotected) and pgcat
     * armed on a driver it cannot front (a payload that is `ok` and never flips).
     *
     * `replicas.*.degraded` and `replicas.*.store_healthy` need a store that fails, which is
     * `FakeRedis` and the store's own tests rather than this payload; they are asserted to be
     * fields the payload has, above.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function uncoveredConditions(): array
    {
        return [
            'the pinned query was switched off' => [['db-manager.swrr.health.pinned_query' => false]],
            'pgcat is armed where it can never act' => [['db-manager.swrr.pgcat.enabled' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('uncoveredConditions')]
    public function test_the_gate_fails_each_condition_it_claims_to_cover(array $config): void
    {
        // A database that answers, so the status code is 200 and the condition is the only
        // thing the payload says that the gate is written to catch.
        $payload = $this->payload(':memory:', config: $config);

        $failed = $this->jq(self::gate(), $payload);

        $this->assertNotSame(
            0,
            $failed['exit'],
            "The gate passes a payload the cookbook says it fails:\n{$payload}",
        );
    }

    public function test_every_field_the_cookbook_reads_is_a_field_the_payload_has(): void
    {
        $payload = json_decode($this->payload(':memory:'), true);

        $this->assertIsArray($payload);

        $paths = self::paths();

        $this->assertNotSame(
            [],
            $paths,
            'The cookbook names no payload field at all, so this would agree with anything.',
        );

        $missing = [];

        foreach ($paths as $path) {
            if (!self::resolves($payload, $path)) {
                $missing[] = '.'.$path;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "The cookbook reads fields this payload does not have, so the rules built on them would silently never fire:\n  - "
            .implode("\n  - ", $missing),
        );
    }

    /**
     * The audit's live half, both ways: a finding this process re-derived must page, and one the
     * record holds from another scope must not.
     *
     * The pair is the whole reason the payload publishes `audit.current` beside the record. A rule
     * written against `audit.severity` alone pages whichever instance happens to answer for an entry
     * a different boot wrote — the migration container's refused value, say — and the operator is
     * sent to a host whose configuration is fine. The record says what the installation has been
     * claiming; the live half says what this process can see now; and the two are told apart by
     * reading them, which is what this runs the pasted rule to check.
     */
    public function test_the_live_half_gate_pages_a_finding_that_stands_here_and_not_one_written_elsewhere(): void
    {
        $program = self::liveGate();

        // A boot on another connection recorded a reader-window refusal. This process reads the
        // windows cleanly, so the entry stands in the record and not here: the record's `severity`
        // is `error` while the live half's is not, and the pasted rule must stay quiet.
        $elsewhere = $this->record([
            WeightedDatabaseServiceProvider::KEY_READER_WINDOWS_REFUSED => $this->entry(
                'swrr.reader_windows is "10:00-14:20", not a list of windows.',
                ['connection' => 'sqlite', 'driver' => 'sqlite', 'source' => 'db-manager.swrr.connection', 'app_env' => 'sqlite-live'],
            ),
        ]);

        $recordOnly = $this->payload(':memory:', config: ['db-manager.swrr.audit.file' => $elsewhere]);
        $block = json_decode($recordOnly, true);

        $this->assertSame('error', $block['audit']['severity'], 'the record still claims the finding');
        $this->assertNotSame('error', $block['audit']['current']['severity'], 'nothing stands here');
        $this->assertSame(
            ['evaluated' => true, 'standing' => false, 'scope_matches' => false],
            $block['audit']['findings'][0]['current'],
            'the key is re-read here, does not stand, and was written in another scope',
        );

        $passed = $this->jq($program, $recordOnly);

        $this->assertSame(
            0,
            $passed['exit'],
            "The live-half rule pages this instance for a finding written in another scope, which is the case it exists to exclude. jq said {$passed['error']}",
        );

        // The same shape, this time refused here too: the record's entry stands in this process as
        // well, so the page is this instance's after all.
        $here = $this->record([
            WeightedDatabaseServiceProvider::KEY_FALLBACK_REFUSED => $this->entry(
                'swrr.allow_local_fallback is "nope", not on or off.',
                ['connection' => 'sqlite', 'driver' => 'sqlite', 'source' => 'db-manager.swrr.connection', 'app_env' => 'sqlite-live'],
            ),
        ]);

        $standing = $this->payload(':memory:', config: [
            'db-manager.swrr.audit.file' => $here,
            'db-manager.swrr.allow_local_fallback' => 'nope',
        ]);
        $live = json_decode($standing, true);

        $this->assertSame('error', $live['audit']['current']['severity'], 'a refused value reads as refused here');
        $this->assertSame(
            ['evaluated' => true, 'standing' => true, 'scope_matches' => false],
            $live['audit']['findings'][0]['current'],
            'the entry stands in this process as well as in the record',
        );

        $paged = $this->jq($program, $standing);

        $this->assertNotSame(
            0,
            $paged['exit'],
            'The live-half rule passes a finding that stands in this very process, so it pages nobody.',
        );
    }

    /**
     * One `/health/db` body, as JSON, from the real controller.
     *
     * The fixture is the sibling HTTP test's: the connection the package follows is pinned at
     * SQLite so `select 1` really runs, and the fixture's replicas — addresses nothing answers
     * on — go with it, so the query is the only probe in the payload.
     *
     * `$config` is applied after that, for a test whose subject is one configuration instead of
     * one database.
     *
     * The connection is named after the database it points at, so two payloads in one test are
     * two connections. Laravel caches a resolved connection by name, and a second payload built
     * by re-pointing the first name at a database that cannot be opened would be answered by
     * the already-open PDO — a green payload pretending to be a failed one.
     */
    private function payload(string $database, int $expected = 200, array $config = []): string
    {
        $name = self::PINNED.'_'.substr(md5($database), 0, 8);

        Route::get('/health/db', [DatabaseHealthController::class, 'index']);

        // The baseline, stated rather than inherited: the query is on, so the status code is
        // the query's own answer, and pgcat is off, because a switch armed on the SQLite
        // connection this fixture pins is a `mismatch` — which the cookbook's table rightly
        // pages on, and which is a condition of its own in the provider below. Without this the
        // "healthy" payload is only healthy on a machine where nothing else armed pgcat first.
        $baseline = [
            'db-manager.swrr.health.pinned_query' => true,
            'db-manager.swrr.pgcat.enabled' => false,
        ];

        foreach (array_merge($baseline, $config) as $key => $value) {
            config()->set($key, $value);
        }

        // The audit is a singleton built from the config, so a test that points it at a record of
        // its own has to let it be rebuilt — otherwise the path it was constructed with wins and
        // the payload is answered from the default file.
        $this->app->forgetInstance(BootAudit::class);

        config()->set('database.connections', [
            $name => ['driver' => 'sqlite', 'database' => $database],
        ]);
        config()->set('database.default', $name);

        return (string) $this->getJson('/health/db')->assertStatus($expected)->getContent();
    }

    /**
     * The `jq` program the README pastes: the one `sh` block under the cookbook that calls
     * `jq`, up to the line that closes the quote.
     *
     * Read rather than restated, because the point of the test is that the program a reader
     * copies is the program that was run.
     *
     * @return string
     */
    private static function gate(): string
    {
        $blocks = Readme::fenced(self::HEADING, 'sh');

        foreach ($blocks as $block) {
            if (preg_match("/jq -e '(.+?)'\s*$/ms", $block, $matches) === 1) {
                return $matches[1];
            }
        }

        self::fail('The cookbook pastes no jq program, so there is no gate to run.');
    }

    /**
     * The live half's rule: the `sh` block that carries the marker, so the second paste-able rule is
     * run rather than counted. The first `jq -e` block under the heading is the endpoint gate above,
     * so a rule that reads the audit's live half says which half it reads and is found by that.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError when the cookbook has lost it
     */
    private static function liveGate(): string
    {
        foreach (Readme::fenced(self::HEADING, 'sh') as $block) {
            if (!str_contains($block, 'the live half of the audit')) {
                continue;
            }

            if (preg_match("/jq -e '(.+?)'\s*$/ms", $block, $matches) === 1) {
                return $matches[1];
            }
        }

        self::fail('The cookbook pastes no live-half rule, so there is no rule to run.');
    }

    /**
     * A boot audit record of this test's own, so a payload can be built with an entry a previous boot
     * wrote. Both keys are written because they are the two the file has: a reader that found one and
     * not the other would report the file rather than the finding, which is a different case.
     *
     * @param array<string, mixed> $findings
     */
    private function record(array $findings): string
    {
        $file = sys_get_temp_dir().'/swrr-alert-audit-'.bin2hex(random_bytes(6)).'.json';

        file_put_contents($file, (string) json_encode([
            'findings' => $findings,
            'store_probed_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $file;
    }

    /**
     * One recorded finding, in the shape a boot writes: enough to name it, date it, and say which
     * scope it was written in — the four things the live half is compared against.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function entry(string $warning, array $scope): array
    {
        return [
            'resolution' => 'The setting reads as written again.',
            'warning' => $warning,
            'level' => 'error',
            'context' => [],
            'first_reported_at' => '2026-09-21T08:15:00+00:00',
            'scope' => $scope,
        ];
    }

    /**
     * Every payload path the cookbook names, normalised: `replicas.*.degraded` (how a table
     * writes the per-connection summary) becomes `replicas[].degraded`, which is the shape the
     * resolver walks.
     *
     * Only paths rooted at a top-level key of this payload are read, so the section's prose
     * about log context (`levels`, `discarded`) is not mistaken for a field — those live in a
     * log line, not in this body, and BootAuditTest is where they are pinned.
     *
     * @return list<string>
     */
    private static function paths(): array
    {
        preg_match_all(
            '/(?<![\w.])(?:pinned|replicas|audit|pgcat|status|errors)(?:\[\]|\.(?:\*|[a-z_]+))+/',
            Readme::section(self::HEADING),
            $matches,
        );

        return array_values(array_unique(array_map(
            static fn (string $path): string => str_replace('.*', '[]', $path),
            $matches[0],
        )));
    }

    /**
     * Whether a payload has the field a documented path names: each segment must exist, and a
     * `[]` segment must hold the rest of the path in every element it has.
     *
     * An empty list resolves — it cannot contradict the rule, and a row is what the rule would
     * be built from. That is the one case this reader is allowed to be lenient in, and it is
     * lenient only about *instances*: the container is still asserted to be there, so a payload
     * that dropped `replicas` altogether still fails.
     *
     * @param array<array-key, mixed> $payload
     */
    private static function resolves(array $payload, string $path): bool
    {
        $segments = explode('.', ltrim($path, '.'));
        $segment = array_shift($segments);
        $list = str_ends_with($segment, '[]');

        if ($list) {
            $segment = substr($segment, 0, -2);
        }

        if (!array_key_exists($segment, $payload)) {
            return false;
        }

        $value = $payload[$segment];

        if ($segments === []) {
            return !$list || is_array($value);
        }

        $rest = implode('.', $segments);

        if (!$list) {
            return is_array($value) && self::resolves($value, $rest);
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $element) {
            if (!is_array($element) || !self::resolves($element, $rest)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One `jq` run, with the payload piped in.
     *
     * Not installed is a fact about this machine rather than about the recipe, so it skips
     * rather than failing — but only after the assertions that did not need the binary have
     * run, which is why the availability check is here rather than at the top of the test.
     *
     * @return array{exit: int, error: string}
     */
    private function jq(string $program, string $payload): array
    {
        if (!self::jqAvailable()) {
            $this->markTestSkipped('jq is not on this machine, so the gate the README pastes cannot be run.');
        }

        $process = new Process(['jq', '-e', $program]);
        $process->setInput($payload);
        $process->setTimeout(20);

        return ['exit' => $process->run(), 'error' => $process->getErrorOutput().$process->getOutput()];
    }

    /**
     * Whether this machine has `jq`. A missing binary arrives as an exception or as a non-zero
     * exit depending on the platform, so both are read as "not there".
     */
    private static function jqAvailable(): bool
    {
        try {
            $process = new Process(['jq', '--version']);
            $process->setTimeout(20);
            $process->run();
        } catch (ProcessRuntimeException) {
            return false;
        }

        return $process->getExitCode() === 0;
    }
}
