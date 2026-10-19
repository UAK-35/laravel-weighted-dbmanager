# The pool probe, as something a monitor can page on

A design record for `db:pgcat-metrics`, for the exposition `PoolProbeMetrics` renders, and for the
alert rules the two exist to make possible.

The reading itself is not new. `PgcatConfigFlipper::poolProbeVerdict()` answers "does the running
pooler hold the pools the applied variant declares", `/health/db` publishes it as `pool_probe`, and
`db:doctor` fails a deploy on it. Both of those reach an operator late: the doctor row runs when a
release is being promoted, which is the worst moment to learn that the pooler stopped holding a pool
an hour ago, and the endpoint is an HTTP surface a monitor outside the container scrapes — a deploy
step inside the container has no business making a request to itself to reach a fact its own process
holds. Neither is the shape a Prometheus already scrapes.

So this is a fourth rendering of the same answer and not a fifth implementation of the question:
`PoolProbeMetrics::render()` is a pure function over the array the flipper returns, and
`db:pgcat-metrics` writes it to stdout.

---

## The answer in one line

**`php artisan db:pgcat-metrics` prints the pool probe's verdict in Prometheus text exposition —
`swrr_pgcat_pool_present{pool="…"}` is `1` for a pool the pooler holds, `0` for one it refused, and
absent for one the reading could not prove — and `--strict` exits `1` when the verdict has anything
to say, so a pipeline can stop before promoting on the same body a scraper archives.**

```text
swrr_pgcat_pool_present{pool="reader_db_1"} 1
swrr_pgcat_pool_present{pool="writer_db_1"} 0
swrr_pgcat_pool_probe_unverified 0
swrr_pgcat_pool_probe_failed 1
```

An alert on `swrr_pgcat_pool_present == 0` fires on the refusal itself, which is what the flip
repairs and what the doctor row fails on — hours before the deploy that would have discovered it.

---

## What is emitted

One family per fact, each a gauge, each written on every route — including the routes that asked
nothing, because a family that vanishes with its last sample is a family a dashboard cannot explain
at the moment it matters.

| Family | What it says | A monitor's use for it |
|---|---|---|
| `swrr_pgcat_pool_present{pool}` | `1` the pooler holds the pool, `0` it refused to serve it, no sample when the reading could not tell | the alert that exists for this feature: `== 0` for a missing pool |
| `swrr_pgcat_pool_probe_unverified` | how many declared pools have no `present` sample | `> 0` for "this reading could not tell", which is not a refusal |
| `swrr_pgcat_pool_probe_failed` | the verdict's `failed`: a declared pool is missing, past the boot window | the deploy gate; the same field `/health/db` fails on |
| `swrr_pgcat_pool_probe_warned` | the verdict's `warned`: nothing is missing and something could not be proved, past the window | page once it has lasted longer than a rollout |
| `swrr_pgcat_pool_probe_judged` | whether the boot window has closed, so the two verdicts above are judgements rather than descriptions | distinguishes "clean" from "not yet judged" |
| `swrr_pgcat_pool_probe_enabled` | whether a probe is armed at all | `== 0` is "nothing is watching this installation" |
| `swrr_pgcat_pool_probe_applicable` | whether the applied variant declared a pool to ask about | separates "no pools" from "no answer" |
| `swrr_pgcat_pool_probe_endpoint{address}` | where the packets actually went, read off the probe's own answer | the one fact that separates a pooler that is down from a probe aimed at the wrong port |

The labels are the pool's name as the variant declares it and the address the exchange named. Both
come from the verdict rather than from this package's configuration, so a probe injected by an
installation reports the endpoint it used, and a pool renamed in the variant is renamed in the
series.

## Why an unproven pool has no sample

The three states `PoolProbe` reports are not two states plus noise. `answered` means the pooler
holds the pool — the console listed it, or the front door answered for it and refused a sentinel
name no configuration declares. `missing` means the pooler refused it, in its own sentence. Both are
statements about the pool. `indeterminate` and `silent` are statements about the *reading*: the
pooler answered every name including the sentinel, so its answer proves nothing, or nothing came
back at all.

Turning those two into a number would be a lie in one direction or the other. `1` is the green
reading the sentinel exists to prevent — an installation that authenticates first and answers
everything would report a pool it does not hold. `0` pages somebody for a pool that is very likely
there, and it makes a probe pointed at the wrong port look exactly like a pooler that lost its
configuration, which are two different pages.

So the sample is absent and `unverified` counts it. A Prometheus reading the body can tell all three
apart: `present == 0` is "it is gone", `unverified > 0` is "I could not tell", and a clean `0` for
both with `enabled 1` and `applicable 1` is "everything the variant declares is held".

## Why the two verdicts are published beside the per-pool answer

`failed` and `warned` are the flipper's judgements, and both wait for the container's boot window to
close: a pooler a second old may not have read its file yet, and the repair that re-applies the
variant still has somewhere to run inside that window. `present` is what the pooler answered right
now, judged or not.

That is the difference between the two readers this feature has. An operator watching a rollout
wants the first refusal — `present == 0` — because the window is exactly when the pooler is being
replaced. A deploy gate wants `failed`, because past the window nothing will re-apply the file and
the state is standing. Publishing only one of the two would make one of those readers wrong.

## Alert rules

```yaml
groups:
  - name: pgcat-pools
    rules:
      - alert: PgcatPoolRefused
        expr: swrr_pgcat_pool_present == 0
        for: 5m
        annotations:
          summary: "pgcat is not serving a pool the applied variant declares"

      - alert: PgcatPoolProbeUnproven
        expr: swrr_pgcat_pool_probe_unverified > 0
        for: 15m
        annotations:
          summary: "the pool probe cannot tell whether pgcat holds a declared pool"

      - alert: PgcatPoolProbeOff
        expr: swrr_pgcat_pool_probe_enabled == 0
        for: 1h
        annotations:
          summary: "nothing is asking this container's pooler about its pools"
```

`for:` is what makes the first rule safe to add to a container that restarts: a pooler that comes up
without its pools is refusing them for the first seconds of its life too, and the flip is still
allowed to repair it. Five minutes is longer than the shipped check interval and shorter than the
boot window, so the page arrives while the self-heal is still working rather than after it has given
up. The second rule's fifteen minutes is deliberately past the window: an unproven reading that has
survived the window is the `warned` verdict, and it will not settle by itself.

The third rule is the one an operator adds when they want to be told that the probe is off, not that
a pool is missing. It is a separate decision — an installation may legitimately run without it — so
it is not folded into the first two.

## How the body reaches a Prometheus

Nothing here registers a route. `/health/db` is a controller the host application routes itself, and
a second route shipped by this package would expose a metrics surface no installation asked for. The
exposition is the part that has to be right; where it is served is the deployment's, and the two
ordinary ways of serving a command's output are both one line:

```cron
* * * * * php artisan db:pgcat-metrics > /var/lib/node_exporter/textfile/swrr_pgcat.prom.tmp \
          && mv /var/lib/node_exporter/textfile/swrr_pgcat.prom.tmp /var/lib/node_exporter/textfile/swrr_pgcat.prom
```

```yaml
# prometheus.yml, for a scrape that runs the command in the container
scrape_configs:
  - job_name: swrr-pgcat
    static_configs:
      - targets: ["swrr-pgcat-exporter:9101"]
```

The write-then-rename matters for the textfile collector: a scrape that reads the file between the
truncation and the last line reports `swrr_pgcat_pool_probe_failed 0` about a pooler it has not
looked at. That is the same ordering this package's own state files use, and for the same reason.

**Stdout is the exposition, always.** No header, no log line, no sentence about what happened — a
scraper reads every byte, and a body it cannot parse is a failed scrape, which a monitor reads as
the target being down rather than as this package having nothing to say. Everything a human needs to
know is a sample or an exit code.

## What `--strict` is for

By default the command exits `0` once the body is written, whatever the verdict says: writing it is
the job, and a scrape that failed because a pool was missing would report the target as down instead
of reporting the pool. `--strict` is the deploy gate's reader, and it exits `1` when the verdict has
anything to say — `failed`, `warned`, or no flipper bound at all — with exactly the same body on
stdout. A pipeline can therefore archive what it gated on, and a monitor and a pipeline can be
pointed at one command without either of them changing it.

An installation that switched pgcat off, or never armed the probe, is not refused by that flag:
`enabled 0` says nothing was asked, which is a decision rather than a fault, and the doctor's rows
treat both the same way. What `--strict` refuses is a reading that was supposed to exist and could
not be taken at all — no flipper bound — because there the container is missing the package rather
than the pooler.

The doctor row still exists and still fails first in the sequence this package recommends: the row
is the explanation, with the pooler's own sentence, and this command is the series. A deploy that
runs both gets a page from one and a refusal from the other.

## What was rejected

- **`-1` for an unproven pool.** It is a value Prometheus will happily store, and it makes every
  query that is not `== 1` wrong in a different way: `> 0` would count nothing, `< 1` would count it
  as missing, and a dashboard would have to know the sentinel. An absent sample is the format's own
  way of saying "no observation", and `unverified` carries the count.
- **A `status` label with the four words in it.** `swrr_pgcat_pool_present{pool,status}` makes the
  value constant and the alert a label match, which is a different query dialect for no gain; it
  also multiplies the series by the vocabulary. The four words belong in the sentence the JSON
  surfaces publish, where a reader can use them.
- **A route shipped by the package.** See above: the route is the deployment's, and this package
  does not register one for `/health/db` either. Shipping a metrics route would also mean an
  authentication decision this record has no business making.
- **Scraping `/health/db` and reading `pool_probe` with `jq`.** That is an HTTP hop to the container
  itself from a step that runs inside it, through whatever the deployment put in front of the
  application, to reach one number. The endpoint remains the right surface for a monitor that can
  scrape it from outside; this command is the one for the reader that cannot.
- **A `db:doctor --json` field, read by the alerting system.** That is the deploy step this feature
  exists to precede, and it costs a full preflight — replicas, store, supervisor, pooler — to read
  one row.

## Where it is held

`PoolProbeMetricsTest` pins the rendering against verdicts written by hand: a held pool is `1`, a
refused pool is `0` with `failed` beside it, an indeterminate one has no sample and is counted, a
silent pooler leaves every pool without one, an open boot window publishes `judged 0` and no
verdict, an unarmed probe still renders a parseable body, every family carries one `HELP` and one
`TYPE`, a label value escapes the characters the format cannot carry, the pools keep the variant's
order, the endpoint is a series only when an address was read, and an entry in another shape is
dropped rather than turned into a sample.

`DbPgcatMetricsCommandTest` pins the command's two channels: the body on a console that lists every
pool and a `0`; a missing pool as `0` with `failed 1`, the body unchanged by `--strict` and the code
`1`; an unproven reading as a warning that `--strict` stops on; an open boot window that publishes
the answer without the verdict; an unarmed probe that still writes a body; and a container with no
flipper, whose body says `enabled 0` and whose `--strict` run is the only one that fails.
