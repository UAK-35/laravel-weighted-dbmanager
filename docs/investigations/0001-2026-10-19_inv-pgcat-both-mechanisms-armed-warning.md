# Investigation — `[WeightedDB] two mechanisms own the pooler's mode`

| | |
|---|---|
| Cluster | `lpr-backend-cluster-01` |
| Service | `lpr-backend-sites-svc-02` |
| Container | `lpr-backend-cntnr-01` |
| Task | `030552d97a024b828fea6cf14a5d6da1` |
| Region / profile | `us-east-1` / `lpr` |
| Log group | `/ecs/lpr-backend-sites-tdef-01` |
| Code folder | `/app/site0/html` |
| Config read | `/app/site0/html/config/db-manager.php`, `/etc/pgcat/*.toml` |

## The warning

```
[WeightedDB] two mechanisms own the pooler's mode: swrr.pgcat.schedule.enabled and
swrr.pgcat.schedule.windows.enabled are both on. They read the same resolver, so no
boundary is contended — this entry stops at the end of the boot window (480s after the
container starts) — but they do not share a record of what they did: a mode the window
tasks apply goes through the flipper's forced path, which is not written as a boot
convergence, so the readings that describe the entry's convergence describe a mechanism
that has stopped acting. Arm one and disarm the other in the same change: this is a
warning rather than a failure because an installation may be halfway through that.
```

It is the boot-audit finding **`swrr.pgcat.schedule.both_armed`**, at level `warning`.

## Why it appears

It is produced by `WeightedDatabaseServiceProvider::flipMechanismFindings()`, which asks
`FlipSchedule::bothArmedSentence()`; that returns a sentence **only when both switches are on
and the flipper is armed**:

```php
if (!($perMinute['enabled'] ?? false) || !($windows['enabled'] ?? false)) {
    return null;          // one owner — no finding
}
```

The app's `config/db-manager.php` leaves both at their documented default **on**:

- `swrr.pgcat.schedule.enabled` → `env('SWRR_PGCAT_SCHEDULE_ENABLED', true)`
- `swrr.pgcat.schedule.windows.enabled` → `env('SWRR_PGCAT_WINDOW_FLIP_ENABLED', true)`

and no task-definition or environment override sets either, so the condition is permanently
true. `db:doctor` reports the same state: the `flip schedule` row warns "two mechanisms own the
pooler's mode … 4 boundary task(s)".

What it is warning about: the **per-minute entry** converges the pool at container start and is
then a no-op (bounded by the boot window); the **reader-window tasks** move the pool at each
boundary. One mode with two owners — and only one of them writes the record the surfaces read
(a mode the window tasks apply goes through the flipper's forced path, which is deliberately
not recorded as a boot convergence).

## Why it appears *repeatedly* — and how often

It is a **standing** finding, and `BootAudit` re-logs every standing finding **on every boot**:

- `BootAudit::fold()` logs each finding unconditionally, every boot.
- The only dedup is `private static bool $bootAudited` — *"one process audits once"*.
- Under **classic PHP-FPM** (nginx + php-fpm, no Octane) a `static` is reset per request, so
  "once per process" = **once per request**. The package's own design notes say so:
  - `docs/boot-audit-store-probe.md` — *"Under FPM that guard is worth nothing … the application
    boots per request, so a static means 'once per request'."*
  - `docs/boot-audit-surfaces.md` — *"under FPM it was written once per request."*

Measured in CloudWatch (`/ecs/lpr-backend-sites-tdef-01`, the task above): **~2,500 lines per
10 minutes ≈ ~250 per minute** — request-rate-bound. On top of that, one line per queue-worker
boot and one per scheduled `db:pgcat-flip` run (every minute).

## Should it appear repeatedly?

**By the current design, yes — but not at this rate, for this finding.**

The design *intends* the repeat:

> `docs/boot-audit-store-probe.md` — *"While a finding stands it is logged **on every boot** …
> a warning from three weeks ago with nothing to say whether it is still true is worse than a
> repeat."*

But that rule was written for boot-time observations. `both_armed` is a **static configuration
state**: it cannot change without editing configuration and restarting processes, so each repeat
carries no new information. The last line per `finding` key is already the state, and clearing is
announced by a single `resolved: true` line. The package already bounded the one thing that
actually cost per request — the store probe, via `swrr.audit.store_probe_seconds`, for precisely
this reason — but it did **not** bound the *logging* of findings, so FPM multiplies a
configuration warning into ~250 lines a minute.

## Suggested fix

1. **Remove the state (recommended — configuration only).** Arm one mechanism and disarm the
   other, as the sentence prescribes. This app owns the mode with the **window tasks** (4
   boundaries scheduled), so the per-minute entry is the redundant one:

   ```
   SWRR_PGCAT_SCHEDULE_ENABLED=false
   ```

   (`swrr.pgcat.schedule.enabled` — default `true`; leave `windows.enabled` on.) Then
   `bothArmedSentence()` returns `null`, the finding stops, and **one** `resolved: true` line is
   logged. Nothing is hidden: the state is still reported by `db:doctor`'s `flip schedule` row
   (which `--strict` fails) and by `/health/db`'s `audit` block.

2. **If the package should not flood (package change).** Throttle re-logging of a *standing*
   finding the way the probe is throttled — e.g. log it only when first seen, or once per
   interval per installation using the shared record's timestamp, so several FPM workers produce
   one line per interval rather than one per request — while keeping the first-sighting and the
   single resolution line unchanged (so the pager behaviour in `README`'s alerting table holds).

## Notes

- The finding is a `warning`, not a failure, on purpose: an installation may be mid-migration
  between the two mechanisms (disarm the per-minute entry in the same change that arms the
  tasks), and a preflight that failed the state in the middle of that change would refuse exactly
  the installation doing the right thing slowly.
- The setting that owns the addition is `swrr.pgcat.schedule.enabled`; its counterpart is
  `swrr.pgcat.schedule.windows.enabled`.
