<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One real query on the connection this package follows — `select 1`, asked through the
 * application's own path.
 *
 * WHY THIS IS ONE CLASS AND NOT TWO CALLS
 * ---------------------------------------
 *   Two surfaces ask this question and each one is a *claim about a database*: `GET /health/db`
 *   answers `ok` only when it came back, and `db:doctor --real-test` is what a deploy gate runs to
 *   prove a route to the pooler before it promotes an image. A second spelling of "connect and run
 *   one statement" would be a second answer to the same question — a different query, a different
 *   timing, a different sentence for the failure — and the day one of them changed, an endpoint
 *   reporting `ok` and a gate refusing the same installation would both be right about their own
 *   reading. So the query, the path it is asked through and the shape of the answer live here, and
 *   both callers report what this returns.
 *
 *   The statement is deliberately trivial: it asserts reachability and authentication, which is the
 *   health claim an endpoint a load balancer polls is allowed to make, and says nothing about
 *   schema. It is asked through `DB::connection()`, so a read/write connection is read through the
 *   weighted factory exactly as a request would read it — the failure a caller sees is the failure
 *   a request would have had, which is the whole point of running it rather than asking the
 *   driver's configuration.
 *
 * THE TWO READS THAT ARE NOT A QUERY
 * ----------------------------------
 *   An installation that names no connection at all is reported as the failure it is rather than
 *   as an absent check: a name the package cannot resolve is a database nothing can query, and
 *   that sentence is here rather than in each caller so the endpoint and the preflight say it the
 *   same way. `checked` is what tells "no query was run" apart from "the query failed" — a caller
 *   that turns the reading off (the endpoint's own `swrr.health.pinned_query` switch, which is the
 *   endpoint's and not this class's) reports `checked: false` and `ok: null` for itself.
 */
final class PinnedQuery
{
    /** The one statement: reachability and auth, and nothing about schema. */
    public const QUERY = 'select 1';

    /**
     * The query, its outcome, and the reading's own numbers.
     *
     * `error` is the driver's own words, unedited: the sentence an operator pastes into a search,
     * and for PostgreSQL the one carrying the SQLSTATE a deploy gate matches on. It is the only
     * field here that could carry a credential, and it is reported as the driver wrote it rather
     * than reworded.
     *
     * `latency_ms` is rounded to hundredths because it is a reading an operator compares between
     * runs rather than a measurement: the difference between 0.42 and 0.43 is noise, and a
     * sentence carrying four decimals invites a reader to treat it as one. It is `null` only where
     * no statement was run at all — a measurement of nothing is not zero milliseconds.
     *
     * @return array{
     *   connection: string,
     *   query: string,
     *   checked: bool,
     *   ok: bool,
     *   latency_ms: float|null,
     *   error: string|null,
     * }
     */
    public static function run(string $connection): array
    {
        $probe = [
            'connection' => $connection,
            'query' => self::QUERY,
            'checked' => true,
            'ok' => false,
            'latency_ms' => null,
            'error' => null,
        ];

        if ($connection === '') {
            $probe['error'] = 'no connection is pinned: db-manager.swrr.connection is unset and database.default is empty';

            return $probe;
        }

        $startedAt = hrtime(true);

        try {
            DB::connection($connection)->select(self::QUERY);
            $probe['ok'] = true;
        } catch (Throwable $e) {
            $probe['error'] = $e->getMessage();
        }

        $probe['latency_ms'] = round((hrtime(true) - $startedAt) / 1_000_000, 2);

        return $probe;
    }
}
