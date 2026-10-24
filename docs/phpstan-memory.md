# PHPStan dies on memory, and a check that fails with a message about itself

## What was seen

A gate run that had been green came back with one failed check and no findings:

```
phpstan    FAIL    36.4s  to get all reported errors.

Child process error: PHPStan process crashed because it reached configured PHP memory limit: 128M
Increase your memory limit in php.ini or run PHPStan with --memory-limit CLI option.
  while running parallel worker
```

Read quickly, that is a static-analysis failure. It is not: **the analysis never ran**. The row is red
because PHPStan reported its own death, and the message says so in the second line. Re-run it and it
passes — with a *cold* result cache, so the pass is the whole analysis rather than a cache hit:

```
$php bin/tool.php phpstan clear-result-cache
$php bin/checks.php --only=phpstan
  phpstan    PASS    41.7s  [OK] No errors
```

## Why the limit is not the gate's

| where a limit could come from | what it says |
|---|---|
| `phpstan.neon.dist` | nothing — the file sets `level: max` and `paths:` and no limit |
| `bin/checks.php`'s phpstan command | nothing — it passes no `--memory-limit` |
| the interpreter's own `php.ini` | `256M` **today**, and `128M` when the workers died |

So the parallel workers were running under whatever the machine's `php.ini` said at that moment, and
the gate had no opinion about it. Two consequences follow, and the second is the one worth fixing:

- **The row's verdict depends on the machine.** The same tree and the same tool pass on a host whose
  `php.ini` is generous and fail on one whose is not. A check that cannot state its own requirement
  is a check that measures the host.
- **A crash and a finding look the same.** PHPStan prints `Child process error` and the runner turns
  its non-zero exit into a red row. Nothing in the gate distinguishes "the tool died" from "the tool
  found something", so the honest reading of that row is a re-run — which is what this document came
  out of.

## The ways to fix it, and what each one costs

| fix | where it lands | verdict |
|---|---|---|
| raise `php.ini`'s `memory_limit` | the machine, outside this repository | **rejected** — `AGENTS.md` section 0 puts the interpreter's own tree out of bounds even to write, and a limit that lives there is invisible to the next checkout and cannot be reviewed |
| `php -d memory_limit=512M vendor/phpstan/phpstan/phpstan.phar analyse …` | `bin/checks.php` | works, but it wraps the tool in an interpreter invocation the rest of the gate does not use, and the ugliness invites somebody to remove it as noise |
| `--memory-limit=512M` on the gate's own phpstan command | `bin/checks.php` | **chosen** — the tool's own flag, read by PHPStan and passed on to the workers it spawns, so the value the row depends on is the value the gate writes down |
| `--memory-limit=-1` (unbounded) | `bin/checks.php` | rejected — an analysis that can grow without limit is one that can take the host down instead of the worker, and the crash is diagnosable precisely because a number was written down |
| `parallel.maximumNumberOfProcesses: 1` in `phpstan.neon.dist` | the config | a real fix of a different kind: one worker cannot lose a race it is not running. Rejected here because it trades the parallelism the analysis is fast for — a cold run is 41.7s over several workers — to hide a limit that can simply be stated |
| nothing, and re-run when it happens | — | what the incident did; kept as the reading of last resort, not as the practice |

The chosen one is one token added to a command the gate already builds:

```php
'run' => static fn (): array => runCommand(
    [PHP_BINARY, $tools['phpstan'], 'analyse', '--no-progress', '--no-ansi', '--memory-limit=512M'],
    $root,
),
```

## Why 512M and not 1G

The number has to clear the peak of a cold, parallel, level-max run over `src/`, and nothing more:

- a cold run passes under a `256M` ini today, so `512M` is double the value that works — room for the
  tree to grow rather than a number chosen to end the argument;
- the crash happened at `128M`, so the useful range is between that and the peak, and `512M` sits
  above the peak by a margin while staying a limit a runaway analysis can still hit;
- `1G`, which the analysis accepts and passes with, is headroom nothing here has needed. A limit
  nobody can reach is the same as no limit for the purpose of noticing one, and the reason the crash
  was diagnosable at all is that the number was written down.

## What this does not fix

- **A memory limit is not a time limit.** PHPStan runs under the gate's own timeout, and a tree that
  gets slow rather than large is a different failure with a different repair.
- **A worker can still die** — a genuinely enormous file, a broken extension, the machine under
  memory pressure from something else. What changes is that the gate no longer contributes to it: the
  limit it runs under is the one it wrote down, so a worker that dies now died on *that* number, and
  the next reader has a value to reason about instead of a host fact.
- **The result cache is machine-local.** It lives under the system temp directory and makes a second
  run fast (9.0s against the 41.7s cold). A pass read off a warm cache is the same analysis, and a
  failure read off one should still be re-run cold before it is believed.

## The rule

A tool the gate runs inherits the machine's `php.ini` unless the gate says otherwise, and a check
whose verdict moves with the host is not a check. Every tool the gate invokes with a resource
requirement of its own should state it in the command the gate builds — which is the same reason
`phpunit` is given its own configuration file, and the same reason the tools themselves are pinned.
