<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

/**
 * The pool probe's verdict as Prometheus text exposition.
 *
 * WHY THIS EXISTS
 *   `db:doctor`'s `pool probe` row and `/health/db`'s `pool_probe` block both answer "does the
 *   running pooler hold the pools the applied variant declares", and both are read by a person or
 *   by a deploy step. A deploy step is late: it runs when a release is being promoted, so a pool the
 *   pooler stopped holding an hour ago is discovered at the worst moment, by the job that has to
 *   decide whether to promote. A monitor can be told far earlier, but only if the same reading is
 *   available in the shape a monitor scrapes.
 *
 *   This is that shape and nothing else: one pure function, the verdict in, exposition out. The
 *   question itself is not computed here — `PgcatConfigFlipper::poolProbeVerdict()` answers it, and
 *   a second reader of the pooler's config would be a second answer to one question. So the command
 *   that prints this, the endpoint that publishes it as JSON and the doctor row that fails on it
 *   cannot disagree about what the pooler holds.
 *
 * WHAT IS EMITTED, AND WHAT A SAMPLE BEING ABSENT MEANS
 *   `swrr_pgcat_pool_present{pool="…"}` is one sample per pool the applied variant declares, and
 *   its value is the pool's own answer: `1` the pooler holds it — the console listed it, or the
 *   front door answered for it with a control name refused — and `0` the pooler refused to serve it,
 *   which is the fault a flip repairs.
 *
 *   A pool the reading could not prove either way — `indeterminate` (the pooler answered the
 *   sentinel too, so its answer proves nothing) or `silent` (nothing came back) — has **no sample**.
 *   That is deliberate and it is the whole design: a gauge that said `1` there would be the green
 *   reading `indeterminate` exists to prevent, and one that said `0` would page somebody for a pool
 *   that is very likely there. `swrr_pgcat_pool_probe_unverified` counts those pools, so the doubt
 *   is a series of its own rather than an absence an alert has to infer, and the two readings stay
 *   separate: `present == 0` is "the pooler does not have this pool", `unverified > 0` is "this
 *   reading could not tell".
 *
 *   The remaining families describe the verdict rather than a pool: `enabled` (is a probe armed at
 *   all), `applicable` (was there a declared pool to ask about), `judged` (has the container's boot
 *   window closed, so `failed` and `warned` are verdicts rather than descriptions), `failed` (a
 *   declared pool is missing and nothing will re-apply the file again), `warned` (nothing is missing
 *   and something could not be proved) and `endpoint` (where the packets actually went, so a probe
 *   aimed at the wrong port is distinguishable from a pooler that is down — both are silence).
 *
 * WHY `failed` AND `present` ARE BOTH PUBLISHED
 *   They are not the same question, which is why this package already draws the distinction twice.
 *   `failed` is judged: it waits for the boot window to close, because a container still converging
 *   is not a broken one, and it is the field `/health/db` fails on and `db:doctor` blocks a deploy
 *   with. `present` is what the pooler answered at this moment, judged or not. An alert that fires
 *   on `failed` is an alert that fires when the repair path has stopped and nothing will fix it; one
 *   that fires on `present == 0` fires on the first refusal, inside the window, which is what an
 *   operator watching a rollout wants.
 *
 * WHY THE NUMBERS ARE INTEGERS RATHER THAN FLOATS
 *   Every family here is a boolean or a count, so the exposition writes `1` and `0` rather than
 *   `1.0` and `0.0`: a gauge's value is a float to Prometheus either way, and the integer form is
 *   what an operator reading the scrape by eye expects.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *   It writes no timestamp (the scraper stamps the sample, and a timestamp written by the process
 *   that measured it is how a stale scrape looks fresh), registers no collector, and keeps no
 *   state: it is a rendering of the answer it was handed. It also does not decide what a monitor
 *   should do with the numbers — the doc beside it writes the alert rules down, and the command's
 *   `--strict` flag is the one place this package turns a reading into an exit code.
 */
final class PoolProbeMetrics
{
    /** Whether the pooler holds a pool the applied variant declares. */
    public const PRESENT = 'swrr_pgcat_pool_present';

    /** Whether a probe is armed at all. */
    public const ENABLED = 'swrr_pgcat_pool_probe_enabled';

    /** Whether the applied variant declared a pool for the probe to ask about. */
    public const APPLICABLE = 'swrr_pgcat_pool_probe_applicable';

    /** Whether the boot window has closed, so `failed` and `warned` are verdicts. */
    public const JUDGED = 'swrr_pgcat_pool_probe_judged';

    /** How many declared pools the reading could not prove either way. */
    public const UNVERIFIED = 'swrr_pgcat_pool_probe_unverified';

    /** Whether a declared pool is missing from the pooler after the boot window closed. */
    public const FAILED = 'swrr_pgcat_pool_probe_failed';

    /** Whether the reading proved nothing about a declared pool after the boot window closed. */
    public const WARNED = 'swrr_pgcat_pool_probe_warned';

    /** Where the probe's packets went. */
    public const ENDPOINT = 'swrr_pgcat_pool_probe_endpoint';

    /**
     * The whole exposition for one verdict.
     *
     * The verdict is the array `PgcatConfigFlipper::poolProbeVerdict()` returns, read by key and
     * never by position, and every key is defaulted: a caller with no flipper passes `[]` and gets
     * the same parseable body a scrape always gets, with `enabled` at `0` saying why. A body that
     * could not be parsed would make a scrape fail, and a failed scrape is a silence a monitor
     * reads as the target being down rather than as this package having no pooler to ask about.
     *
     * @param array<string, mixed> $verdict the flipper's reading, or `[]` when nothing could ask it
     */
    public static function render(array $verdict): string
    {
        $pools = self::pools($verdict);
        $unverified = self::unverified($pools);

        $families = [
            self::family(
                self::ENABLED,
                'Whether the pool probe is armed (1) or the running pooler is taken at supervisord\'s word (0)',
                [self::sample(self::ENABLED, self::flag($verdict['enabled'] ?? null))],
            ),
            self::family(
                self::APPLICABLE,
                'Whether the applied variant declares a pool for the probe to ask about (1) or nothing was asked (0)',
                [self::sample(self::APPLICABLE, self::flag($verdict['applicable'] ?? null))],
            ),
            self::family(
                self::PRESENT,
                'Whether the running pooler holds a pool the applied variant declares: 1 held, 0 refused. No sample when the reading could not prove either way.',
                self::presentSamples($pools),
            ),
            self::family(
                self::UNVERIFIED,
                'The declared pools whose presence this reading could not prove',
                [self::sample(self::UNVERIFIED, $unverified)],
            ),
            self::family(
                self::FAILED,
                'Whether a declared pool is missing from the running pooler after the boot window closed (1) or not (0)',
                [self::sample(self::FAILED, self::flag($verdict['failed'] ?? null))],
            ),
            self::family(
                self::WARNED,
                'Whether the reading proved nothing about a declared pool after the boot window closed (1) or not (0)',
                [self::sample(self::WARNED, self::flag($verdict['warned'] ?? null))],
            ),
            self::family(
                self::JUDGED,
                'Whether the boot window has closed, so failed and warned are verdicts (1) rather than descriptions of a container still converging (0)',
                [self::sample(self::JUDGED, self::flag($verdict['judged'] ?? null))],
            ),
        ];

        $address = $verdict['address'] ?? null;

        if (is_string($address) && $address !== '') {
            $families[] = self::family(
                self::ENDPOINT,
                'Where the probe\'s packets went: the pooler\'s own address, read off its answer rather than off the settings',
                [self::sample(self::ENDPOINT, 1, 'address="'.self::labelValue($address).'"')],
            );
        }

        return implode("\n\n", $families)."\n";
    }

    /**
     * The pools this verdict names, in the order it named them, as strings this renderer can trust.
     *
     * Anything an injected probe or an older flipper returns in another shape is dropped rather than
     * guessed at: a pool entry with no name cannot be a label, and one with a status this class does
     * not know is a reading it will not turn into a number. Dropping it leaves it out of
     * `present` — there is no sample saying it exists — and `unverified` counts only what was named.
     *
     * @param array<string, mixed> $verdict
     * @return list<array{database: string, status: string}>
     */
    private static function pools(array $verdict): array
    {
        $pools = [];

        foreach (is_array($verdict['pools'] ?? null) ? $verdict['pools'] : [] as $pool) {
            if (!is_array($pool)) {
                continue;
            }

            $database = $pool['database'] ?? null;

            if (!is_string($database) || $database === '') {
                continue;
            }

            $pools[] = [
                'database' => $database,
                'status' => is_string($pool['status'] ?? null) ? $pool['status'] : PoolProbe::STATUS_SILENT,
            ];
        }

        return $pools;
    }

    /**
     * One sample per pool whose presence or absence the pooler actually stated.
     *
     * `answered` is proof the pool exists, whichever reading produced it — the console's list, or the
     * front door with a control name refused; the flipper publishes `indeterminate` in place of
     * `answered` when the front door proved nothing, so this class never has to decide that itself.
     * `missing` is the pooler's refusal, and it is `0` rather than no sample because it is a fact
     * about the pooler: the fault a flip repairs and the one a deploy should stop on.
     *
     * @param list<array{database: string, status: string}> $pools
     * @return list<string>
     */
    private static function presentSamples(array $pools): array
    {
        $samples = [];

        foreach ($pools as $pool) {
            $value = match ($pool['status']) {
                PoolProbe::STATUS_ANSWERED => 1,
                PoolProbe::STATUS_MISSING => 0,
                default => null,
            };

            if ($value === null) {
                continue;
            }

            $samples[] = self::sample(
                self::PRESENT,
                $value,
                'pool="'.self::labelValue($pool['database']).'"',
            );
        }

        return $samples;
    }

    /**
     * How many declared pools have no answer this reading can turn into a `present` value.
     *
     * Exactly the complement of the samples above, which is deliberate: a monitor that alerts on
     * `unverified > 0` and one that alerts on a pool's series disappearing are counting the same
     * pools, and the number here is the count of the samples this class did not write. A pool whose
     * status is an unrecognised string is counted rather than dropped, because a shape this class
     * cannot read is a reading it cannot prove — the same rule the flipper applies to a probe that
     * answers in another vocabulary.
     *
     * @param list<array{database: string, status: string}> $pools
     */
    private static function unverified(array $pools): int
    {
        $count = 0;

        foreach ($pools as $pool) {
            if ($pool['status'] !== PoolProbe::STATUS_ANSWERED && $pool['status'] !== PoolProbe::STATUS_MISSING) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * One family: its two comments and the samples under them, which is the block Prometheus reads.
     *
     * The comments are written even when there is no sample — a family whose `HELP` and `TYPE` vanish
     * with its last sample is a family a dashboard stops being able to explain at exactly the moment
     * it has something to explain.
     *
     * @param list<string> $samples
     */
    private static function family(string $name, string $help, array $samples): string
    {
        return implode("\n", [
            '# HELP '.$name.' '.self::helpText($help),
            '# TYPE '.$name.' gauge',
            ...$samples,
        ]);
    }

    /** One sample, with its labels when it has any. */
    private static function sample(string $name, int $value, string $labels = ''): string
    {
        return $labels === ''
            ? sprintf('%s %d', $name, $value)
            : sprintf('%s{%s} %d', $name, $labels, $value);
    }

    /**
     * A verdict flag as the integer a gauge carries.
     *
     * A missing or malformed key is `0` rather than an error: each flag is a claim about the
     * reading, and a reading that did not make the claim has not made it.
     */
    private static function flag(mixed $value): int
    {
        return $value === true ? 1 : 0;
    }

    /**
     * A label value with the three characters the exposition format cannot carry literally.
     *
     * The order matters: the backslash is escaped before the quote and the newline are, so the
     * backslashes those two introduce are not escaped a second time.
     */
    private static function labelValue(string $value): string
    {
        return str_replace(
            ["\\", "\"", "\n"],
            ["\\\\", "\\\"", "\\n"],
            $value,
        );
    }

    /** A `HELP` line's text, which carries escapes for exactly two characters — not the quote. */
    private static function helpText(string $text): string
    {
        return str_replace(["\\", "\n"], ["\\\\", "\\n"], $text);
    }
}
