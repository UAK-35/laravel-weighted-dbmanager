# Should the boot log name the severity of what it reports?

## The answer in one line

Every line `BootAudit` writes carries the level it was written at, as `severity` in its
context, beside the `finding` key — so `severity == "error"` selects a refused value out of a
log with the same comparison `/health/db` publishes, without polling a host.

---

## Why this needed deciding at all

The two levels are the whole point of the audit: a **refused** value is input the
installation is running without, which is worth waking somebody for, and a setting that reads
as on but cannot act is a feature quietly not applying — a ticket, not a page.
[`/health/db`](boot-audit-surfaces.md) publishes that choice as one field, `severity`, and
its README table gives the alert rule as `severity == "error"`.

A monitor that would rather read lines than poll every host has the same question and a worse
tool to answer it with. The level of a log line lives in the transport, not in the payload:

| channel | where the level appears |
|---|---|
| Laravel's default `single`/`stack` line log | `production.ERROR` in the line prefix |
| Monolog's JSON formatter | `level_name` |
| syslog | a numeric priority |
| an APM handler | whatever that SDK normalises it to |

So a rule written against the log is a rule written against **the operator's handler
configuration** — and it is a rule about the application, not about the audit: `ERROR` in the
default channel is every error the app ever logs, which is the coarsest possible page.

The record already carries `level` per finding; the log did not. Each line did carry the
level as its own log level, which is exactly the field whose name and spelling nobody
promised.

---

## The candidates

### A — leave it to the channel's level (the status quo)

The finding line is already logged *at* the finding's level, and every handler renders that
somewhere. Rejected: the reading is of the envelope, whose shape belongs to whoever configured
it, and the values in it are the application's vocabulary — `ERROR`, `WARNING`, `NOTICE` —
not the package's two claims. A monitor has to know the operator's handler before it can be
written, which is not a rule the package can ship.

### B — name the context key `level`, as the record does

`BootAuditFinding::$level`, `Standing['level']` and the record's own `level` all use that
word, so reusing it in the context would be consistent with storage. Rejected: the log line is
an **alert surface**, and the package already has a name for the level as an alert reads it —
`severity`, from `standingSummary()` and the endpoint. The two names are two readers, not two
opinions: `level` is what a record stores, `severity` is what a monitor pages on. It also
avoids a real collision — a handler that flattens context into the record root would overwrite
the line's own level with a value the finding supplied.

### C — carry the level the closed finding stood at on its resolution line

The most tempting one. A refusal that is fixed would log `severity: "error", resolved: true`,
so a reader could tell a cleared page from a cleared ticket. Rejected: the README's rule is
`severity == "error"`, and under this option the line that *repairs* the problem would satisfy
it — a pager written exactly as documented would fire on the fix. It would also make
`severity` mean two things one line apart ("the level this finding is at" / "the loudest thing
standing"), and the audit's own vocabulary is the second. The level a finding stood at is in
the record and in every line it wrote while it stood; the clearing's claim is that it is gone,
and `resolved: true` says that.

### D — a dedicated channel or a per-boot summary line

Log the findings to a `weighted-db` channel so the operator's app log stays clean, or emit one
line per boot carrying the summary and its counts. Rejected: whether such a channel exists is
the operator's configuration, and routing findings *away* from the application log hides them
from the person who greps it — the surfaces decision already rejected a second place to look.
A summary line adds a second shape to parse and still has no level per finding, which is the
thing a page needs.

### E — make the message machine-parseable (`[WeightedDB][error] …`)

The message already opens with the package's own marker, so one more field in the prefix would
be free. Rejected: it makes every reader parse prose — wrapped at the terminal width, coloured
in a console, eventually translated — to recover a fact that belongs in the payload. The
prefix is for the person reading the log; `severity` is for the rule.

### F — a JSON envelope inside the message

Rejected as E's cousin, one level in: the logger already serialises the context *before* it
writes the line, so putting an object in the message is a payload inside a payload, escaped
twice and read only by handlers that have chosen to disregard context — the same handlers
that would then have to parse it back out of prose.

### G — document "the level is whatever your handler calls the log level"

Rejected: it is candidate A with a README paragraph attached, and it moves a package decision
into every consumer's handler config, where the package cannot test it.

### H — `severity` in the context of every line the audit writes (chosen)

One private helper puts the level into the payload it is logging, under the name the package
already publishes for it. `severity` is the second field a monitor needs and `finding` the
first, so a refused value is selected out of one object with `severity == "error"` — the same
comparison the endpoint answers — and that holds whichever transport carried the line.

---

## The chosen rule, in full

`BootAudit::logContext(string $level, array $context)` returns
`['severity' => $level] + $context`, and every `Log::` call in the class goes through it:

| line | written at | `severity` |
|---|---|---|
| a finding still standing | the finding's own level | that level — a refusal is `error` |
| the finding's resolution | `warning`, always | `warning` — a transition, not a standing claim |
| two findings sharing a key | `error` — a problem nobody reported is louder than either sentence | `error` |
| an entry another boot recorded being replaced | `error` | `error` |

The rule is stated over **all** of them rather than over the finding lines, because a rule a
reader has to know which line it is looking at before trusting is not a rule a monitor can be
written from. `Log::log`'s level is unchanged: this adds a name to the payload, it does not
move the loudness of anything.

**The line's own level wins a collision.** `+` puts `severity` first, so a finding whose
context happened to carry the key cannot make the line disagree with the level it was logged
at: `severity` is a claim about the line, not one of the finding's fields. Pinned by
`test_the_line_names_the_level_it_was_written_at_even_when_a_finding_context_carries_the_key`.

What a monitor reads, per key:

| what it sees | rule |
|---|---|
| `severity == "error"` and no `resolved` | page — a refused value is standing, and it is logged on every boot while it stands |
| `severity == "warning"` | ticket — a setting that cannot act |
| `severity == "warning"` with `resolved == true` | nothing is standing under this key any more: the pager stops on the line that repairs it |

The finding is logged on **every** boot while it stands, so the rule needs no state at all
beyond the last line per key — unlike the endpoint, which needs polling. That is the point of
the change.

---

## Tests that pin the rules

| rule | test |
|---|---|
| every line names the level it was written at | `assertSeverityStamped()` in `BootAuditTest`, asserted over the finding, collision and lost-update lines |
| a refused value is selectable by `severity`, and its clearing is not | `test_a_refused_value_is_selectable_from_the_log_by_its_severity` |
| a finding's context cannot overwrite the line's level | `test_the_line_names_the_level_it_was_written_at_even_when_a_finding_context_carries_the_key` |
| the real refusal path carries it, standing and clearing | `test_flat_string_reader_windows_are_refused_at_error_level`, `test_a_pgcat_switch_written_as_something_that_is_not_a_switch_is_refused_at_error_level` |

Two mutations confirm the assertions have teeth: dropping `severity` from `logContext()` fails
6 tests (3 errors, 3 failures), and letting the finding's context win the key (`$context +
['severity' => $level]`) fails 1 — the collision test, whose whole subject that is.

---

## Known limitations

1. **A handler that discards the line by minimum level still hides the finding.** If the
   audit's `warning` lines are below a channel's threshold, nothing here reaches the log at
   all. The record is written regardless, and `/health/db` and `db:replica-status` read it —
   which is the honest answer: a payload cannot fix a handler that refuses the line.
2. **The resolution line does not say which level cleared** (candidate C, rejected). A reader
   that never saw the finding standing cannot learn from the clearing alone that it was a
   refusal — it can only learn that the key stopped standing.
3. **`severity: "error"` covers more than refused values.** The collision line and the
   lost-update line are written at `error` too, deliberately: both are defects in the package,
   both are worth waking somebody for, and neither is about a setting an operator repairs. A
   rule that pages on `severity == "error"` may therefore surface a package defect — which is
   the intended reading, not a false positive.
4. **The values are the package's, not the application's.** `severity` is `error` or
   `warning`, never `ERROR`/`WARNING`/`CRITICAL`; a monitor that filters on its own level
   vocabulary needs one mapping, which is the point.

## What would change this decision

If the package ever owned its own channel or shipped a metrics exporter, the severity would
move to that surface as a first-class field and the context key would stay as the fallback.
Nothing else here depends on it: consumers read `severity` beside `finding`, and the record,
the endpoint and the CLI report are unaffected.

## Files

- `src/Support/BootAudit.php` — `logContext()`, the four call sites, and the `SEVERITY_*`
  docblock naming it.
- `tests/Unit/Support/BootAuditTest.php` — the stamped-severity helper and two tests.
- `tests/Unit/Weighted/WeightedDatabaseServiceProviderTest.php` — the real refusal paths.
- `README.md`, `CHANGELOG.md`, [`docs/boot-audit-surfaces.md`](boot-audit-surfaces.md) —
  the rule as documented where an alert is written.
