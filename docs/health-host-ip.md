# Which host answered the request?

A design record for `pinned.host_ip` in `GET /health/db`, `HostAddress::ipv4()`, and the interface
name that field is fixed to.

The endpoint already says which *connection* the payload is about — `pinned.connection` and
`pinned.source` — and whether that connection can answer `select 1`. Neither says which container
answered. Behind a load balancer, a rolling deploy, or a pooler per replica, "the database is
reachable" is not the same claim as "the database is reachable from *this* process", and a payload
that cannot name the process leaves an operator comparing two responses with no way to tell whether
they came from the same host. `pinned.host_ip` is that name: the IPv4 address of the interface the
container serves on, read while the response is built, and `null` when it cannot be read.

Everything below is implemented in `src/Support/HostAddress.php` and
`DatabaseHealthController::pinnedProbe()`. The rules are pinned by the tests named at the end.

---

## The answer in one line

**The pinned block carries the IPv4 address of `eth1` on the serving host, read with
`net_get_interfaces()`, and answers `null` rather than a guess when that address does not exist.**

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

## Why the standard library

`net_get_interfaces()` has enumerated a machine's adapters since PHP 7.3, and this package
requires `^8.4`, so the reading is a function call rather than a dependency. It returns the listing
this field needs: the interfaces keyed by name, each with `up` and a `unicast` list whose entries
carry `family`, `address` and `netmask`, with `2` for `AF_INET`.

Every other route answers a different question, and the table below is the comparison that decided
it.

| route | gives `eth1`'s IPv4? | why not |
|---|---|---|
| `$_SERVER['SERVER_ADDR']` | no | the address the SAPI bound, which can be `0.0.0.0`, the pool's own address, or absent under CLI — and it is not interface-selective |
| `gethostbyname(gethostname())` | no | usually `127.0.1.1` in a container, a name that does not resolve, or another interface's address |
| a connected socket's local address | no | whatever the route table picked, which may be `eth0` |
| `hostname -I` | no | every address, no interface selection, and a binary |
| `ip -4 -o addr show dev eth1` | yes | the right answer bought with a shell in a health route |
| `ifconfig eth1` | yes | the same, with an older output shape and `net-tools` |
| `/proc/net/fib_trie` + `/proc/net/route` | yes, with work | pure PHP, but the address has to be correlated with the interface through the route table — more parsing than the native call, fragile across kernels |
| `FFI` + `getifaddrs(3)` | yes | a second implementation of what the standard library already does, behind `ffi.enable`, which production often disables |
| `nmcli` / `networkctl` | yes | not present in most application containers |

**No shell fallback.** A fallback would make the answer depend on whether `exec` is enabled and on
which binary happens to be installed, and it would silently switch *source* the moment one route
failed — a behaviour a reader could not see and a test would have to prove twice. The native call
either answers or the field is `null`.

---

## Why `null` is an answer

Five states have no address this field can honestly publish: the interface is absent, it is down,
it carries only IPv6, it carries only a link-local address, or the runtime cannot enumerate
interfaces at all (`disable_functions` lists `net_get_interfaces`, so `function_exists()` is false).
Each answers `null`.

The alternatives were rejected one at a time:

- **`eth0` as a fallback** would publish an address the contract does not name, and the reader has no
  way to tell which interface answered.
- **A link-local address** (`169.254.0.0/16`) is what an interface carries when DHCP failed or has
  not finished. It is real, and it is not where the host can be reached, so publishing it would be a
  reading worse than none.
- **The last known address of a down interface** is what some kernels keep in `unicast`. `up` is
  read for exactly that reason.
- **Whichever family comes first** — an IPv6 address under an IPv4 field — is not the reading the
  name promises.
- **A cached value** would describe the network as it was at boot. The call is one syscall per
  response, and the endpoint already runs `select 1`.

A warning from the function is `@`-suppressed and its `false` return is the `null` case, because a
Laravel error handler turns a warning into an exception — a missing address must be reported, not
become a 500.

---

## Why the interface is fixed to `eth1`

The field was asked for as `eth1`, and a `swrr.health.host_interface` key was considered and not
taken: it would raise the published config's generation (`docs/config-generations.md`), need a
schema release row, a README row, a doctor state and a republish cycle for every installation — for
a field whose name is already decided here. If a deployment turns out to serve on `eth0`, the
promotion is written down: add the key with `eth1` as its documented default, raise the generation,
and keep `HostAddress::INTERFACE` as the fallback. Docker's default bridge is exactly that case, and
it is documented as such rather than silently accommodated.

The address is the *serving* host's. An FPM worker, a CLI command and a queue worker in one
container see the same `eth1`; a health request answered by a different pod sees that pod's `eth1`.
It is not the node's address and not the caller's — a proxy in front of the route does not change
what is published, which is why the deployment check compares the payload against `ip` inside the
same container.

---

## Why the field is in the pinned block

- **`status` never reads it.** A host with no readable address still answers `200`/`ok`; the field is
  metadata, not a condition. Folding it into `$allHealthy` would make a missing interface look like
  a failed database.
- **It survives the query switch.** `swrr.health.pinned_query` governs `select 1`, not where the
  request was answered, so `checked: false` still carries `host_ip`. A key list that depended on a
  setting it has nothing to do with is a consumer's key list that changes twice.
- **It is appended, not inserted.** `host_ip` follows `error`, so a consumer reading the documented
  order is unaffected.
- **Not on the `misconfigured` early return.** That response has no `pinned` block at all, so there is
  no block to append to.
- **Not in `PinnedQuery`.** That class answers whether the pinned connection can run `select 1`; the
  address is a property of the host, and composition belongs in the controller.
- **Disclosure.** The address is internal and the route is public. The payload already discloses
  connection names and driver errors, so this is an accepted low-severity exposure rather than a new
  class of leak; the record says so rather than leaving it to be discovered.

---

## The tests that pin it

| rule | test |
|---|---|
| the address is published | `HostAddressTest::test_the_eth1_ipv4_address_is_published` |
| absent, down, IPv6-only, malformed, link-local, failed call answer `null` | `HostAddressTest::test_an_absent_interface_answers_null`, `HostAddressTest::test_an_interface_that_is_down_answers_null`, `HostAddressTest::test_an_interface_with_only_ipv6_answers_null`, `HostAddressTest::test_a_malformed_address_answers_null`, `HostAddressTest::test_a_link_local_address_answers_null`, `HostAddressTest::test_a_failed_system_call_answers_null` |
| the primary of several is chosen, link-local skipped | `HostAddressTest::test_the_first_routable_ipv4_address_is_taken` |
| no fallback to another interface | `HostAddressTest::test_another_interface_being_up_does_not_answer_for_eth1` |
| the payload carries it | `DatabaseHealthControllerTest::test_the_pinned_block_reports_the_hosts_eth1_address` |
| `null` is still `200`/`ok` | `DatabaseHealthControllerTest::test_the_pinned_block_reports_a_null_host_ip_when_eth1_cannot_be_read` |
| it survives the query switch | `DatabaseHealthControllerTest::test_the_host_ip_survives_a_switched_off_pinned_query` |

The controller's default reading is never exercised by the suite on purpose: that would assert the
interfaces of the machine running it. The injectable `?\Closure` is how the payload half is tested
without that, and a test that reads this host's `eth1` would be a test about the host.

---

## How to see it fail correctly

Inside the container, not on a development host:

```bash
php -r '$i = net_get_interfaces(); var_export($i["eth1"] ?? null);'
ip -4 -o addr show dev eth1
curl -fsS http://127.0.0.1/health/db | jq -r '.pinned.host_ip'
```

- `host_ip` is `null` and `php -r` prints `NULL` for `eth1` → the deployment names the interface
  `eth0` (the Docker default) or has no secondary interface; the field is behaving, the contract is
  not what that installation needs.
- `host_ip` is `null` but `ip` shows an address → the address is link-local, the interface is down,
  or `net_get_interfaces` is disabled (`php -r 'var_dump(function_exists("net_get_interfaces"));'`).
- the two disagree → the request is answered by a different process or network namespace than the
  shell, or a proxy is in front of the route.

Performance: one `net_get_interfaces()` call per health poll, no shell, no DNS, no file reads; the
endpoint's cost is still dominated by `select 1`.
