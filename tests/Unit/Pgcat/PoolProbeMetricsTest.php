<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Pgcat;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Pgcat\PoolProbe;
use Uak35\WeightedDbManager\Pgcat\PoolProbeMetrics;

/**
 * The pool probe's verdict as Prometheus text exposition: one sample per pool the pooler spoke
 * about, a family per fact a monitor needs, and nothing that could be read as proof the reading
 * did not establish.
 *
 * The questions here are about the *shape* rather than about the pooler — the verdict is the
 * flipper's and its own tests pin it against a socket and a console. What this file pins is the
 * translation: `answered` is `1`, `missing` is `0`, a pool the reading could not prove has no
 * sample at all, and every family Prometheus reads is written once, with its value where the
 * exposition format says a value goes. The bodies are written by hand rather than built through
 * a flipper, because a fixture that had to be right about the console and the control name would
 * be testing the reading this class is handed.
 */
final class PoolProbeMetricsTest extends TestCase
{
    /** The reading a console that listed both pools produces, past the boot window. */
    private static function listed(bool $judged = true): array
    {
        return [
            'enabled' => true,
            'applicable' => true,
            'answered' => true,
            'discriminates' => null,
            'verified' => true,
            'judged' => $judged,
            'control' => null,
            'admin' => [
                'status' => PoolProbe::ADMIN_STATUS_READ,
                'pools' => ['reader_db_1', 'writer_db_1'],
                'evidence' => 'the admin console at tcp://127.0.0.1:5433 listed 2 pool(s)',
            ],
            'host' => '127.0.0.1',
            'port' => 5433,
            'address' => 'tcp://127.0.0.1:5433',
            'failed' => false,
            'warned' => false,
            'pools' => [
                ['database' => 'reader_db_1', 'status' => PoolProbe::STATUS_ANSWERED, 'evidence' => 'the pooler\'s own admin console lists pool "reader_db_1"'],
                ['database' => 'writer_db_1', 'status' => PoolProbe::STATUS_ANSWERED, 'evidence' => 'the pooler\'s own admin console lists pool "writer_db_1"'],
            ],
            'reason' => null,
        ];
    }

    /**
     * The families' names, in the order they are written, so a test can assert on the whole body
     * without naming each one again.
     *
     * @return list<string>
     */
    private static function families(): array
    {
        return [
            PoolProbeMetrics::ENABLED,
            PoolProbeMetrics::APPLICABLE,
            PoolProbeMetrics::PRESENT,
            PoolProbeMetrics::UNVERIFIED,
            PoolProbeMetrics::FAILED,
            PoolProbeMetrics::WARNED,
            PoolProbeMetrics::JUDGED,
        ];
    }

    /**
     * The sample lines of a body, as `name => value` for the ones without labels and
     * `name{labels} => value` for the ones with them — one entry per line, so a duplicate is a
     * key that appears once and a line the format would refuse is a key that cannot be parsed.
     *
     * @return array<string, int>
     */
    private static function samples(string $body): array
    {
        $samples = [];

        foreach (explode("\n", trim($body)) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $samples[$line] = 1;
        }

        return $samples;
    }

    /**
     * The value of one sample, addressed by the whole line minus its value — which is how a test
     * says "the sample for this pool" without a parser for the braces.
     */
    private static function value(string $body, string $series): int
    {
        foreach (explode("\n", trim($body)) as $line) {
            if (str_starts_with($line, $series.' ')) {
                return (int) substr($line, strlen($series) + 1);
            }
        }

        self::fail(sprintf('The body has no sample for [%s]:'.PHP_EOL.'%s', $series, $body));
    }

    /**
     * A pool the pooler holds is a `1`, and the harmless verdict beside it is a zero on both of the
     * families a deploy gates on.
     */
    public function test_a_pool_the_pooler_holds_is_one_and_the_verdict_is_quiet(): void
    {
        $body = PoolProbeMetrics::render(self::listed());

        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_present{pool="reader_db_1"}'));
        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_present{pool="writer_db_1"}'));
        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_probe_enabled'));
        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_probe_applicable'));
        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_probe_judged'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_unverified'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_failed'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_warned'));
    }

    /**
     * The fault the whole feature exists for: a declared pool the pooler refused is a zero, and the
     * verdict beside it is the one a pipeline stops on.
     */
    public function test_a_pool_the_pooler_refuses_is_zero_and_the_verdict_failed(): void
    {
        $verdict = self::listed();
        $verdict['answered'] = false;
        $verdict['failed'] = true;
        $verdict['pools'][1]['status'] = PoolProbe::STATUS_MISSING;
        $verdict['pools'][1]['evidence'] = 'the pooler\'s own admin console lists no pool "writer_db_1"';

        $body = PoolProbeMetrics::render($verdict);

        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_present{pool="reader_db_1"}'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_present{pool="writer_db_1"}'));
        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_probe_failed'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_warned'), 'a missing pool is proven, so nothing is unproven');
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_unverified'), 'a refusal is an answer: the pool is known to be absent rather than unknown');
    }

    /**
     * The reading that proves nothing — the pooler answered, and it answered the sentinel too — is
     * the one a bare gauge would turn green. It has no sample at all, and the count says so.
     */
    public function test_a_pool_the_reading_could_not_prove_has_no_sample_and_is_counted(): void
    {
        $verdict = self::listed();
        $verdict['answered'] = false;
        $verdict['warned'] = true;
        $verdict['verified'] = false;
        $verdict['discriminates'] = false;
        $verdict['pools'][0]['status'] = PoolProbe::STATUS_INDETERMINATE;

        $body = PoolProbeMetrics::render($verdict);

        $this->assertStringNotContainsString('pool="reader_db_1"', $body, 'an answer the sentinel shares is not a sample saying the pool exists');
        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_present{pool="writer_db_1"}'));
        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_probe_unverified'));
        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_probe_warned'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_failed'), 'nothing was refused, so nothing was repaired and nothing failed');
    }

    /**
     * A pooler that said nothing is the same shape as an unproven one — no sample, and a count —
     * and the difference between the two is the sentence the JSON surfaces carry, not a number the
     * exposition should invent.
     */
    public function test_a_silent_pooler_leaves_the_pool_without_a_sample(): void
    {
        $verdict = self::listed();
        $verdict['answered'] = false;
        $verdict['warned'] = true;
        $verdict['verified'] = false;
        $verdict['pools'] = array_map(
            static fn (array $pool): array => ['status' => PoolProbe::STATUS_SILENT] + $pool,
            $verdict['pools'],
        );

        $body = PoolProbeMetrics::render($verdict);

        $this->assertStringNotContainsString('swrr_pgcat_pool_present{', $body);
        $this->assertSame(2, self::value($body, 'swrr_pgcat_pool_probe_unverified'));
        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_probe_warned'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_failed'), 'silence is not the refusal a flip repairs or a deploy stops on');
    }

    /**
     * A container still inside its boot window is not judged, and the exposition says so rather
     * than leaving an operator to infer it from a zero.
     */
    public function test_an_open_boot_window_is_published_as_not_judged(): void
    {
        $verdict = self::listed(judged: false);
        $verdict['pools'][0]['status'] = PoolProbe::STATUS_SILENT;

        $body = PoolProbeMetrics::render($verdict);

        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_judged'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_failed'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_warned'));
        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_probe_unverified'), 'the doubt is reported either way; only the verdict waits');
    }

    /**
     * A container with no flipper, or one whose probe is switched off, still has to answer a scrape
     * with a body a Prometheus can parse — an empty one would be a failed scrape, which a monitor
     * reads as the target being down rather than as nothing having been asked.
     */
    public function test_an_unarmed_probe_still_renders_a_body_a_scraper_can_parse(): void
    {
        $body = PoolProbeMetrics::render([]);

        $this->assertStringContainsString("# HELP swrr_pgcat_pool_probe_enabled", $body);
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_enabled'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_applicable'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_judged'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_unverified'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_failed'));
        $this->assertSame(0, self::value($body, 'swrr_pgcat_pool_probe_warned'));
        $this->assertStringNotContainsString('swrr_pgcat_pool_present{', $body);
        $this->assertStringNotContainsString('swrr_pgcat_pool_probe_endpoint', $body, 'no address was read, so none is claimed');
    }

    /**
     * Every family carries its two comments exactly once and its samples under them: a body with a
     * `TYPE` for a name nothing follows, or a sample whose name no `HELP` introduced, is a scrape
     * Prometheus accepts and a dashboard cannot explain.
     */
    public function test_every_family_is_declared_once_with_its_help_and_type(): void
    {
        $body = PoolProbeMetrics::render(self::listed());
        $lines = explode("\n", trim($body));

        $help = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, '# HELP ')));
        $type = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, '# TYPE ')));

        $this->assertCount(count(self::families()) + 1, $help, 'the seven verdict families plus the endpoint, which this body has an address for');
        $this->assertCount(count($help), $type);

        foreach (self::families() as $name) {
            $this->assertSame(1, substr_count($body, '# HELP '.$name.' '), "the [{$name}] family is introduced once");
            $this->assertSame(1, substr_count($body, '# TYPE '.$name.' gauge'), "the [{$name}] family is typed once");
        }

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z_:][a-zA-Z0-9_:]*(\{[a-zA-Z_][a-zA-Z0-9_]*="[^"]*"\})? -?\d+$/',
                $line,
                'a sample line is a name, optional labels and one integer value — and no timestamp, which the scraper stamps',
            );
        }
    }

    /**
     * The one label value this package writes is a pool's name, and the exposition format cannot
     * carry a backslash, a quote or a newline in one. Escaped rather than stripped: a name an
     * operator can find in the variant is the only reason to put it in a label at all.
     */
    public function test_a_label_value_escapes_the_characters_the_format_cannot_carry(): void
    {
        $verdict = self::listed();
        $verdict['pools'] = [
            ['database' => "odd\"name\\here\nnext", 'status' => PoolProbe::STATUS_ANSWERED],
        ];

        $body = PoolProbeMetrics::render($verdict);

        $this->assertStringContainsString('pool="odd\"name\\\\here\\nnext"', $body);
        $this->assertStringNotContainsString("odd\"name\\here\nnext", $body);
    }

    /**
     * The pools are written in the order the variant declared them, which is the order the doctor
     * row and the endpoint list too — so a reader comparing the three does not have to sort them.
     */
    public function test_the_pools_keep_the_order_the_variant_declares(): void
    {
        $verdict = self::listed();
        $verdict['pools'] = [
            ['database' => 'writer_db_1', 'status' => PoolProbe::STATUS_ANSWERED],
            ['database' => 'reader_db_1', 'status' => PoolProbe::STATUS_MISSING],
        ];

        $body = PoolProbeMetrics::render($verdict);

        $this->assertLessThan(
            strpos($body, 'pool="reader_db_1"'),
            strpos($body, 'pool="writer_db_1"'),
            'the file order is the report order',
        );
    }

    /**
     * The endpoint is the one fact that separates "the pooler is down" from "the probe was pointed
     * at the wrong port", so it is a series of its own — and it is absent, not empty, when the probe
     * named no address: a scrape must be able to tell an unread address from a blank one.
     */
    public function test_the_endpoint_is_a_series_only_when_an_address_was_read(): void
    {
        $body = PoolProbeMetrics::render(self::listed());

        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_probe_endpoint{address="tcp://127.0.0.1:5433"}'));

        $verdict = self::listed();
        $verdict['address'] = null;

        $this->assertStringNotContainsString('swrr_pgcat_pool_probe_endpoint', PoolProbeMetrics::render($verdict));
    }

    /**
     * Every pool entry is read by name and status, and an entry in another shape is dropped rather
     * than turned into a number: a body with a sample named `pool=""` would be a pool an alert
     * could never match to a variant.
     */
    public function test_a_pool_entry_in_another_shape_is_not_turned_into_a_sample(): void
    {
        $verdict = self::listed();
        $verdict['pools'] = [
            ['database' => 'reader_db_1', 'status' => PoolProbe::STATUS_ANSWERED],
            ['status' => PoolProbe::STATUS_ANSWERED],
            ['database' => '', 'status' => PoolProbe::STATUS_ANSWERED],
            'not a pool at all',
        ];

        $body = PoolProbeMetrics::render($verdict);

        $this->assertSame(1, self::value($body, 'swrr_pgcat_pool_present{pool="reader_db_1"}'));
        $this->assertSame(1, substr_count($body, 'swrr_pgcat_pool_present{'), 'the one named pool is the only sample');
        $this->assertStringNotContainsString('pool=""', $body);
    }
}
