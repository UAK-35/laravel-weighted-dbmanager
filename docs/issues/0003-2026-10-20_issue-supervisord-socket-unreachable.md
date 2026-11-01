# Issue — the supervisor's control socket is unreachable, so a flip fails before it can repair a pool

| | |
|---|---|
| Package | `uak35/laravel-weighted-dbmanager` |
| Class | `Pgcat\SupervisorStep`, the `pgcat supervisor` row, the `supervisor` requirement |
| Symptom | every flip refuses before it swaps a file; no pool the pooler cannot serve is ever repaired |
| The socket | `/var/run/supervisor.sock`, `root:root`, mode `0700` on the task this was measured on |
| Owner of the socket | the LPR application's `docker-files/production/laravel/supervisord.conf` |

## The state

A flip judges the supervisor command **before it replaces anything** — it runs
`supervisorctl status "pgcat:*"` and refuses if that cannot answer, because a file swapped in
without a pooler that will re-read it is a read outage rather than a repair. The control socket
`supervisorctl` opens is supervisord's own, and on the task measured here it was `root:root` mode
`0700`: only `root` could open it, while the flip runs as the user the schedule runs as
(`www-data`). So `supervisorctl` answered

```
<class 'PermissionError'>, [Errno 13] Permission denied:
file: /usr/lib/python3/dist-packages/supervisor/xmlrpc.py line: 557
```

and every flip refused **before** it swapped anything — which made the pool self-heal unreachable
at all: a pool the pooler could not serve is repaired by a flip, and no flip could run.

## Why the package could report it and not fix it

The socket is not this package's to change. It is named by `[unix_http_server]` in supervisord's own
configuration, which is the application's file, so the package's half is to **name the fault and its
repair** where a deploy reads it, rather than to repair the socket itself.

The fault was being *reported* before this — but as the wrong thing. `SupervisorStep` told three
refusals apart (`proc_open` disabled, the binary not on `PATH`, the step's own timeout) and left a
socket refusal in the generic `errored` branch, whose sentence says only that the command failed
(`could not answer for "pgcat:*" (exit 1)`). The socket, and the two lines that fix it, were never
named.

## The repair

1. **The package names it.** A socket that is missing, refused, or one the schedule's user may not
   open is its own fault — `SupervisorStep::FAULT_UNREACHABLE` — set when `supervisorctl`'s answer
   is about the socket rather than a program (`looksLikeAnUnreachableSocket()`: `no such file`,
   `Permission denied`/`PermissionError`/`Errno 13`, `could not connect`/`Connection refused`). Its
   sentence names the socket and the repair:

   > What it opens is the daemon's control socket, not a program — so either supervisord is not
   > running to answer on it, or the user a flip runs as cannot open it. The socket belongs to
   > supervisord's own configuration: `[unix_http_server]` names it, and `chmod=0760` with
   > `chown=root:www-data` lets the application's group reach it.

   The `pgcat supervisor` row prints the same sentence, so `db:doctor` names it before a deploy
   rehearses a flip; and the deployment contract's `supervisor` requirement now states the socket
   ("a supervisor that can be spawned, reached on its control socket, and knows the pooler's
   program").

2. **The application fixes the socket.** In its `supervisord.conf`:

   ```ini
   [unix_http_server]
   file=/var/run/supervisor.sock
   chmod=0760
   chown=root:www-data
   ```

   then `supervisorctl reload`. That is the half this package cannot do, and the reason the reading
   exists: the preflight names it so the fix lands before a deploy rehearses the flip, rather than
   during one.

## Verification

- `SupervisorStepTest` — the three socket tests moved to `FAULT_UNREACHABLE`, plus a new
  `test_a_socket_this_user_may_not_open_names_the_repair` driving the measured `PermissionError`.
- `DbDoctorTest`'s supervisor row asserts the new sentence and that it carries `chmod=0760`.
- `SupervisorStepTest` + `DbDoctorTest` + `DeploymentContractTest` + `ProseNumbersTest` — OK (304
  tests); `checks --only=counts,sentences,pint,phpstan` all pass, phpstan level `max`.

## The boundary, stated

What is released and what a container runs are two different things: the package now *names* the
socket fault and its repair, and the socket is fixed in the application's own configuration. Neither
half repairs a pool on its own — the package cannot open a socket it is not allowed to, and the
application's configuration cannot report that the flip is refusing.
