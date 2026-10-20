# The `pinned.host_ip` plan

Plan only: nothing in `src/`, `config/`, `tests/` or the records has been touched. This file is the
change to implement later.

`GET /health/db` answers with a `pinned` block, built by
`DatabaseHealthController::pinnedProbe()` (`src/Http/Controllers/DatabaseHealthController.php:712`)
and embedded at `:252`. The block currently carries `connection`, `driver`, `source`, `query`,
`checked`, `refused`, `ok`, `latency_ms` and `error`. The change adds one field, `host_ip`: the IPv4
address of the serving container's `eth1` interface, read when the response is built, and `null` when
that interface cannot be read. It is a **pinned-contract addition**, not a new block: the key is
always present, and it never feeds `status`, `$allHealthy`, the audit or a log line.

```json
"pinned": {
    "connection": "pgsql",
    "driver": "pgsql",
    "source": "database.default",
    "query": "select 1",
    "checked": true,
    "refused": null,
    "ok": true,
    "latency_ms": 1.21,
    "error": null,
    "host_ip": "10.0.3.17"
}
```

---

## Part 1 — can PHP read an interface address?

### The native answer: `net_get_interfaces()`

Yes. Since PHP 7.3 the standard library exposes `net_get_interfaces()`, which enumerates the
adapters of the machine (or, inside a container, of its network namespace) without a shell, a binary
or an extension. This package requires PHP `^8.4`, so the function is present unless the runtime
disables it.

Measured on this checkout's interpreter (PHP 8.4.26):

```
php -r 'var_dump(function_exists("net_get_interfaces"), ini_get("disable_functions"));'
  bool(true)
  string(0) ""

php -r 'var_dump(extension_loaded("sockets"));'
  bool(true)

php -r 'var_export(net_get_interfaces());'   # one adapter, abridged
  array (
    '{7B7855D7-…}' => array (
      'description' => 'Realtek Gaming 2.5GbE Family Controller',
      'mac' => 'D8:5E:D3:D1:80:99',
      'mtu' => 1500,
      'unicast' => array (
        0 => array (
          'flags' => 197,
          'family' => 2,
          'address' => '169.254.242.7',
          'netmask' => '255.255.0.0',
        ),
      ),
      'up' => false,
    ),
  )
```

The shape is what the reading needs: the array is keyed by interface name, each entry has `up`, and
each `unicast` entry carries `family` (`2` is IPv4, the value `AF_INET`), `address` and `netmask`.
The interface name is exactly `eth1` on Linux, so no name translation is needed. (On Windows the
keys are adapter GUIDs, which is why the pure reading below is tested with fixtures rather than with
the development host.)

Constraints that belong in the implementation and its record:

* **`disable_functions`** — when the runtime lists `net_get_interfaces`, `function_exists()` returns
  false and the reading is `null`. Nothing else is attempted.
* **A warning on failure** — the function emits `E_WARNING` and returns `false`. Laravel turns a
  warning into an `ErrorException` under its error handler, so the call must be `@`-suppressed, the
  same way this package already suppresses `@fopen`, `@chmod` and `@file_put_contents`.
* **The namespace is the answer** — an FPM worker, a CLI command and a queue worker in one container
  see the same `eth1`; a health request answered by a different pod sees that pod's `eth1`. This is
  the serving host's address, not the node's and not the caller's.
* **Down is not absent** — `up => false` means the adapter exists but carries no usable address.
  Returning the stale `unicast` entry would publish an address nothing routes to.
* **Aliases** — a Linux interface may list more than one IPv4 (secondary addresses). The first
  entry is the primary; the plan takes it and documents that choice rather than sorting.
* **Link-local** — `169.254.0.0/16` is what an interface gets when DHCP failed or has not finished.
  It is an address, but publishing it as the host's address is misleading; the plan skips it and
  answers `null` when it is the only one.

### Roads that do not answer "eth1's IPv4"

| route | gives it? | needs | verdict |
|---|---|---|---|
| `$_SERVER['SERVER_ADDR']` | no | — | the SAPI's bound address; can be `0.0.0.0`, the pool's address, or absent under CLI, and it is not interface-selective |
| `gethostbyname(gethostname())` | no | resolvable hostname | often `127.0.1.1`, a container name that does not resolve, or another interface's address |
| `stream_socket_get_name()` after a connect | no | a reachable peer | reports the source address the *route* picked, which may be `eth0` |
| `socket_getsockname()` | no | a bound socket | only the address the socket was bound to |
| `hostname -I` | no | `hostname`, shell | every address, no interface selection |
| `ip -4 -o addr show dev eth1` | yes | `iproute2`, `exec`/`shell_exec` | parse `inet 10.0.3.17/24`; the usual Linux fallback, but a runtime shell in a health route |
| `ifconfig eth1` | yes | `net-tools`, shell | same as above, older output shape |
| `busybox ip -4 addr show dev eth1` | yes | busybox, shell | the Alpine image's spelling of the same command |
| `/proc/net/fib_trie` + `/proc/net/route` | yes, with work | readable `/proc`, no binary | pure PHP, but the address must be correlated with the interface through the route table; more parsing than the native call, and fragile across kernels |
| `FFI` + `getifaddrs(3)` | yes | `ffi.enable`, libc headers | a second implementation of what the standard library already does; often disabled in production |
| `nmcli` / `networkctl` | yes | NetworkManager/systemd-networkd | not present in most application containers |

**Recommendation:** use `net_get_interfaces()` only. Do **not** add a shell fallback: the package's
health route should not depend on `exec` being enabled or on a binary being installed, and a
fallback that silently switches source is one more behaviour a test would have to prove. When the
function is unavailable the field is `null`, and that is a truthful answer for a pinned key.

---

## Part 2 — design

### A new support class

`src/Support/HostAddress.php`, final, no state, two methods: a pure reader that takes the array
`net_get_interfaces()` returns, and a thin caller of the system function.

```php
final class HostAddress
{
    /** The interface the field reports, fixed by the endpoint's contract. */
    public const INTERFACE = 'eth1';

    /**
     * The IPv4 address of the named interface on this host, or null when it cannot be read.
     */
    public static function ipv4(string $interface = self::INTERFACE): ?string
    {
        if (!function_exists('net_get_interfaces')) {
            return null;
        }

        return self::from(@net_get_interfaces(), $interface);
    }

    /**
     * The pure half: an interface listing in, the address out. No system call, so the cases that
     * matter — absent, down, IPv6-only, link-local, several addresses — are fixtures rather than
     * facts about the machine running the suite.
     *
     * @param array<string, mixed>|false $interfaces
     */
    public static function from(array|false $interfaces, string $interface): ?string
    {
        if (!is_array($interfaces) || !is_array($interfaces[$interface] ?? null)) {
            return null;
        }

        $link = $interfaces[$interface];

        if (($link['up'] ?? false) !== true || !is_array($link['unicast'] ?? null)) {
            return null;
        }

        $first = null;

        foreach ($link['unicast'] as $entry) {
            if (!is_array($entry) || ($entry['family'] ?? null) !== 2) {
                continue;                                   // 2 is AF_INET; anything else is IPv6
            }

            $address = $entry['address'] ?? null;

            if (!is_string($address)
                || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            if (str_starts_with($address, '169.254.')) {
                continue;                                   // DHCP failed; not a serving address
            }

            $first ??= $address;
        }

        return $first;
    }
}
```

`AF_INET` is provided by ext/sockets, which is not a declared requirement; `2` is the platform's
`AF_INET` value on every host this package supports and is commented as such. If a future reader
prefers the constant, `defined('AF_INET') ? AF_INET : 2` is the spelling — but it buys nothing here.

No cache: the call is one syscall per health response, the endpoint already runs `select 1`, and a
process-lifetime memo would go stale if the container's network changes without a restart.

### The controller's one line

`DatabaseHealthController` already takes its optional collaborators through the constructor
(`:130`). Add a third, injectable reading so the suite never depends on the machine's interfaces:

```php
public function __construct(
    private readonly ?PgcatConfigFlipper $pgcat = null,
    private readonly ?BootAudit $audit = null,
    private readonly ?\Closure $hostIp = null,
) {
}
```

and append the key to the block `pinnedProbe()` builds, after `error`:

```php
'host_ip' => ($this->hostIp ?? static fn (): ?string => HostAddress::ipv4())(),
```

Why in `pinnedProbe()` rather than `PinnedQuery`: the field is a property of the host answering the
request, while `PinnedQuery` answers whether the pinned connection can run `select 1`; keeping the
composition in the controller keeps the query helper about the query. Why after `error`: it appends
to the documented block rather than reordering keys a consumer may already read.

The field is read whether `swrr.health.pinned_query` is on or off — it is host metadata, not part of
the query, so `checked: false` does not suppress it. It is not built on the `misconfigured` early
return, because that response has no `pinned` block at all.

### No new setting

The interface is `eth1` as asked. A `swrr.health.host_interface` key would be more flexible, but it
raises the published config's generation from 2 to 3 (`docs/config-generations.md`), needs a
`ConfigSchema::RELEASED_IN` release row, a config-table row in README, a doctor state, and a
republish cycle for every installation — a large surface for a field the request fixes to one name.
If a later deployment turns out to use `eth0`, the promotion is: add the key with `'eth1'` as its
documented default, raise the generation, and keep `HostAddress::INTERFACE` as the fallback.

### What does not change

* `status`/HTTP code: `host_ip` never participates in `$allHealthy`.
* Logs: no new line, and no `logDegraded()` parameter.
* `db:doctor`, `db:replica-status`, `/health/db`'s other blocks: unchanged.
* `config/db-manager.php`: unchanged, and its generation stays 2.

---

## Part 3 — files the implementation touches

| path | change |
|---|---|
| `src/Support/HostAddress.php` | new class above |
| `src/Http/Controllers/DatabaseHealthController.php` | constructor parameter, `use` line, one key in `pinnedProbe()`, docblock sentence |
| `tests/Unit/Support/HostAddressTest.php` | new: the pure cases below |
| `tests/Unit/Http/DatabaseHealthControllerTest.php` | three cases: value, `null`, and the key surviving a switched-off query |
| `README.md` | the pinned prose sentence and both JSON examples gain `host_ip`; one paragraph on what `null` means |
| `CHANGELOG.md` | one `### Added` entry under `## Unreleased` |
| `docs/health-host-ip.md` | new decision record: why native, why null, why not configurable, the rejected alternatives |
| `API.md`, `files.tsv`, `methods.tsv`, `surface.tsv` | regenerated by `php bin/inventory.php`, not hand-edited |
| `HANDOFF.md`, `tests/Support/Handoff.php` | only if the note is retaken while the new files are still uncommitted; the pending set names them |

Expected inventory movement: files `50 → 51`, classes/interfaces `48 → 49`, public methods
`256 → 258` (two public statics), config keys and environment variables unchanged.

---

## Part 4 — tests

`tests/Unit/Support/HostAddressTest.php` (pure, no sockets):

| case | expected |
|---|---|
| `eth1` up, IPv4 `10.0.3.17` | `10.0.3.17` |
| `eth1` absent | `null` |
| `eth1` present but `up => false` | `null` |
| only IPv6 entries (`family` 10) | `null` |
| malformed address string | `null` |
| only `169.254.x.x` | `null` |
| `10.0.3.17` and `10.0.3.18` | the first |
| `unicast` absent or not a list | `null` |
| listing `false` | `null` |
| another interface up (`eth0`), `eth1` absent | `null` — no fallback to another name |

`tests/Unit/Http/DatabaseHealthControllerTest.php`:

1. `test_the_pinned_block_reports_the_hosts_eth1_address` — bind
   `new DatabaseHealthController(null, null, static fn (): ?string => '10.0.3.17')` in the container
   (or `$this->app->instance(DatabaseHealthController::class, …)`) before registering the route, and
   assert `pinned.host_ip === '10.0.3.17'` beside the existing pinned assertions.
2. `test_the_pinned_block_reports_a_null_host_ip_when_eth1_cannot_be_read` — the closure returns
   `null`, and the payload is still `200`/`ok` with the key present.
3. `test_the_host_ip_survives_a_switched_off_pinned_query` — query off, `checked: false`, key still
   present.

The existing HTTP tests need no edits: none asserts an exact payload shape or the full key list of
`pinned`; they assert named paths. The controller's default reading is never exercised by the suite,
which is why the injectable closure is not optional sugar.

---

## Part 5 — records and the guards that read them

* **README** — the two `pinned` JSON examples (`README.md:734`, `:764`) and the "carries the
  connection, its driver, the config key …" sentence gain `host_ip`. The new paragraph states:
  the address of `eth1` on the host answering the request; `null` when the interface is absent,
  down, IPv6-only, link-local or the runtime cannot enumerate interfaces; it is not the caller's
  address, not the node's, and it is never guessed from the hostname. If it is added to the
  alerting cookbook, it must be a path the payload actually has — `ReadmeAlertingTest` runs every
  field path the section names against a real payload.
* **CHANGELOG** — under `## Unreleased` → `### Added`, one entry stating the key, its source and its
  null contract. No `### Removed`; nothing is renamed or dropped.
* **`docs/health-host-ip.md`** — the decision record: the native call over a shell, `null` over a
  guess, `eth1` fixed over a setting, link-local skipped, first-of-several chosen, and the
  alternatives in Part 1 that cannot answer the question. Cite `HostAddressTest` and the controller
  test by name; `DocCitationsTest` reads the record.
* **`API.md` / `files.tsv` / `methods.tsv` / `surface.tsv`** — write them with
  `php bin/inventory.php`, then hold them with `--check`. `PublicApiReportTest` will stay red on the
  release-tag page until a version is cut, exactly as it is now.
* **HANDOFF** — `php bin/handoff.php` after the implementation commit; if the plan is committed
  separately first, the note's pending set names it and `tests/Support/Handoff.php` carries its
  sentence.

---

## Part 6 — how to know it works

Local:

```bash
php bin/tool.php phpunit tests/Unit/Support/HostAddressTest.php tests/Unit/Http/DatabaseHealthControllerTest.php
php bin/checks.php --only=counts,sentences,pint,phpstan
php bin/inventory.php && php bin/inventory.php --check
php bin/handoff.php && php bin/handoff.php --check
php bin/checks.php            # 12 of 13 until a release rewrites API.md's tag page
```

On the running site (inside the container, not on the development host):

```bash
# 1. What does the runtime itself see?
php -r '$i = net_get_interfaces(); var_export($i["eth1"] ?? null);'

# 2. What does the kernel say?
ip -4 -o addr show dev eth1

# 3. What does the endpoint publish?
curl -fsS http://127.0.0.1/health/db | jq -r '.pinned.host_ip'

# 4. ...and is it the same address?
test "$(curl -fsS http://127.0.0.1/health/db | jq -r '.pinned.host_ip')" \
  = "$(ip -4 -o addr show dev eth1 | awk '{print $4}' | cut -d/ -f1)" \
  && echo "host_ip matches eth1"
```

Expected failure readings are as important as the success one:

* `host_ip` is `null` and `php -r` prints `NULL` for `eth1` → the deployment names the interface
  `eth0` (the Docker default) or has no secondary interface; the field is behaving, the contract is
  not what that installation needs.
* `host_ip` is `null` but `ip` shows an address → the address is link-local, the interface is down,
  or `net_get_interfaces` is disabled (`php -r 'var_dump(function_exists("net_get_interfaces"));'`).
* the two disagree → the health request is answered by a different process/netns than the shell;
  compare inside the same container, and check for a proxy in front of the route.

Performance: one `net_get_interfaces()` call per health poll, no shell, no DNS, no file reads; the
endpoint's cost is still dominated by `select 1`.

---

## Part 7 — risks and edge cases

| risk | handling |
|---|---|
| Docker's default bridge names the interface `eth0`, not `eth1` | documented in README and the record; `null` is the answer, and the config-key promotion is recorded for a later change |
| Kubernetes secondary CNI interface (`eth1`) is pod-local | stated in the record: this is the pod's address, not the node's |
| Public `/health/db` discloses an internal address | noted in the record; the payload already discloses connection names and driver errors, so it is an accepted low-severity exposure rather than a new class of leak |
| A warnings-to-exceptions handler turns a failed call into a 500 | `@net_get_interfaces()` and the `false` branch |
| `family` values other than 2 | skipped; IPv6 is not published under an IPv4 field |
| Interface with several IPv4 addresses | first entry, documented; no sorting or preference beyond skipping link-local |
| Losing the field when the query is switched off | deliberate: the key is composed independently of the switch and has its own test |
| Test suite depending on the CI host's interfaces | impossible through the injectable closure and the pure `from()` cases |
| Static analysis of `array|false` | `from()` declares the union and narrows before use |

---

## Part 8 — order of work and done criteria

1. `HostAddress` + `HostAddressTest` (pure cases first).
2. Controller parameter and key + the three HTTP cases.
3. Runtime check in a container that actually has `eth1`.
4. README, CHANGELOG and `docs/health-host-ip.md`.
5. `php bin/inventory.php`, `php bin/counts.php` (no number is stated by this change, so this should
   be a no-op), `php bin/handoff.php`.
6. Fast gate, focused suites, then the full gate; commit the code and the records in the groups the
   package's `pkg-commit` skill asks for. This plan file is one more file for the handoff's pending
   set until that commit lands.

Done when: a healthy payload carries `pinned.host_ip` equal to `ip -4 -o addr show dev eth1`; an
installation without `eth1` carries `null` and stays `200`/`ok`; the key is present when the pinned
query is off; the gates named above pass; and the README, the decision record, the changelog and the
regenerated inventories all state the same contract.

Rough size: one small class, one controller field, two test files, three prose records, four
generated records — an afternoon including the guard runs, not a multi-day change.

---

## Appendix — measured before writing this plan

* `composer.json` requires `php: ^8.4`; the checkout's interpreter is 8.4.26.
* `function_exists('net_get_interfaces')` is `true`; `disable_functions` is empty; ext/sockets is
  loaded.
* `net_get_interfaces()` on the development host returns eight Windows adapter GUIDs, none named
  `eth1`, with `family => 2` for IPv4 and `up` present — which is exactly the `null` case the plan
  expects on a host without that interface.
* This shell has no `ip` and no `ifconfig` (only `hostname`), so the shell fallbacks cannot be
  exercised here; that is a dev-host fact, not a statement about the Linux site.
* The pinned block has no exact-key assertion in the suite; the HTTP tests read named paths, so the
  new key cannot break them by its presence.
