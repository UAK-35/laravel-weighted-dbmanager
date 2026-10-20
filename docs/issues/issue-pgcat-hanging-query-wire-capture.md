# Issue — capturing the wire traffic of a hanging query, so a client-side wait can be told from a pooler-side one

| | |
|---|---|
| Cluster | `lpr-backend-cluster-01` |
| Service | `lpr-backend-sites-svc-02` |
| Container | `lpr-backend-cntnr-01` |
| Task read | `22eac9365c13476cb1667949a9389874` |
| Region / profile | `us-east-1` / `lpr` |
| Pooler | pgcat listening `0.0.0.0:5433`; its admin console answers on the same port, database `pgcat`, user `pgcAdmin` |
| Config read | `/etc/pgcat/pgcat.toml` — the pool and admin passwords are read out of it in the same shell pipeline that uses them, and never echoed |
| Session | every command below was run on the task through `aws ecs execute-command --container lpr-backend-cntnr-01` |

## What was asked

A query is reported to hang through the pooler. The question this record answers is the mechanical
one: how do you capture that query's PostgreSQL wire traffic, and decode it, so that a wait on the
*client* side can be told from a wait on the *pooler* side — rather than guessing from the SQL text
or from how long a prompt sat there.

That capture is the third fix in `docs/investigations/investigation-pgcat-select-1-hang.md`, which
says to take a `tcpdump` of port 5433 on the task and read the frames. **That advice cannot be
followed on this deployment**, because the tool is not in the image — which is the fault this
record is written around.

## The fault: the capture the runbook asks for is not in the container

Probing the task's tool chain:

| Tool | Present? |
|---|---|
| `tcpdump` | no |
| `tshark` | no |
| `dumpcap` | no |
| `strace` / `ltrace` | no |
| `socat` | no |
| `psql` | yes — `/usr/bin/psql` |
| `python3` | yes — `/usr/bin/python3` |
| `nc` | yes |
| `ss` | yes |
| `php` | yes — `/usr/local/bin/php` |

So the one instruction a reader has for "capture the wire instead of guessing" fails before it
starts, and there is no packet capture on the task at all. The session runs as `root` and its
capabilities include `CAP_NET_RAW` (`CapEff 00000000a80425fb`), so a raw-socket sniffer *could* be
written — but a sniffer is the wrong shape here anyway, for the reason below.

## The method: a logging proxy in front of the pooler

The traffic is captured where it can be decoded rather than off the interface: a tiny TCP proxy that
sits in front of pgcat, forwards every byte both ways, and decodes each PostgreSQL v3 message as it
passes — with a millisecond timestamp for each direction.

```
client (psql)  ->  127.0.0.1:5499   [proxy: decodes and forwards]   127.0.0.1:5433  ->  pgcat
```

Why a proxy and not a sniffer:

- it is written in `python3`, which the image already has, and needs no capability at all;
- it decodes the *messages* rather than the packets, so a stall shows as the last message in each
  direction rather than as a byte count;
- it can hold the connection open with nothing to say — which is exactly the state a hang *is* — and
  say so, instead of a reader inferring it from a silence.

The proxy is the whole of the capture mechanism, and it is short:

```python
import socket, select, struct, time, threading

LISTEN = ("127.0.0.1", 5499)
TARGET = ("127.0.0.1", 5433)

FRONT = {b"Q":"Query", b"P":"Parse", b"B":"Bind", b"D":"Describe", b"E":"Execute",
         b"S":"Sync", b"X":"Terminate", b"C":"Close", b"p":"Password"}
BACK  = {b"R":"Auth", b"S":"ParameterStatus", b"K":"BackendKeyData", b"Z":"ReadyForQuery",
         b"T":"RowDescription", b"D":"DataRow", b"C":"CommandComplete", b"E":"ErrorResponse",
         b"N":"NoticeResponse", b"1":"ParseComplete", b"2":"BindComplete", b"n":"NoData",
         b"t":"ParameterDescription"}

# A StartupMessage and an SSLRequest/GSSENCRequest are length-prefixed and carry NO type byte;
# a v3 message does. The first byte tells them apart.
SSLREQ  = {80877103: "SSLRequest", 80877104: "GSSENCRequest"}
SSLRESP = {b"S": "SSL accepted", b"N": "SSL refused"}

T0 = time.time()
lock = threading.Lock()

def ts():  return "%8.3f" % (time.time() - T0)
def log(m):
    with lock: print(ts() + "  " + m, flush=True)

def decode(b, fwd):
    out, i = [], 0
    while i + 5 <= len(b):
        if b[i:i+1] == b"\x00":                      # Startup / SSLRequest: no type byte
            ln = struct.unpack("!I", b[i:i+4])[0]
            if ln < 8 or i + ln > len(b): break
            name = SSLREQ.get(struct.unpack("!I", b[i+4:i+8])[0], "StartupMessage")
            parts = [p for p in b[i+8:i+ln].decode("latin1").split(chr(0)) if p]
            out.append((name, ln, " ".join(parts[:5]))); i += ln; continue
        t = b[i:i+1]; ln = struct.unpack("!I", b[i+1:i+5])[0]
        if ln < 4 or i + 1 + ln > len(b): break
        out.append((((FRONT if fwd else BACK).get(t) or hex(t[0])), ln,
                    b[i+5:i+1+ln][:56].decode("latin1").replace(chr(10), " "))); i += 1 + ln
    return out, b[i:]

def handle(cl, n):
    cid = "c%d" % n
    be = socket.create_connection(TARGET)
    cl.setblocking(False); be.setblocking(False)
    buf = {cl: b"", be: b""}; pend = None; at = None; ssl_waiting = False
    while True:
        r, _, _ = select.select([cl, be], [], [], 1.0)
        if not r:
            if pend is not None:
                log("[%s] ~~~ SILENCE %.2fs since client sent %s and no byte came back"
                    % (cid, time.time() - at, pend)); pend = None
            continue
        for s in r:
            fwd = (s is cl); data = s.recv(65536)
            if not data:
                log("[%s] connection closed" % cid); return
            buf[s] += data
            if (not fwd) and ssl_waiting and buf[s]:   # the one-byte reply to SSLRequest
                log("[%s] %-11s %-18s len=%-5d %s" % (cid, "pgcat->client",
                    "<- " + SSLRESP.get(buf[s][:1], "?"), 1, ""))
                buf[s] = buf[s][1:]; ssl_waiting = False
            msgs, buf[s] = decode(buf[s], fwd)
            for nm, ln, peek in msgs:
                log("[%s] %-11s %-18s len=%-5d %s" % (cid, "client->pgcat" if fwd else "pgcat->client", nm, ln, peek))
                if nm in ("SSLRequest", "GSSENCRequest"): ssl_waiting = True
                if fwd and nm in ("Query", "Execute"): pend, at = nm, time.time()
                if not fwd: pend = None
            (be if fwd else cl).sendall(data)          # the byte that makes it a proxy

def serve():
    c = socket.socket(); c.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    c.bind(LISTEN); c.listen(16); log("proxy up: 127.0.0.1:5499 -> 127.0.0.1:5433")
    n = 0
    while True:
        cl, _ = c.accept(); n += 1
        threading.Thread(target=handle, args=(cl, n), name="c%d" % n, daemon=True).start()

serve()
```

Three things the decoder has to special-case, and each one was found by getting it wrong first —
they are worth naming because a decoder that misses one reports a stall that is not there:

1. **An SSLRequest is answered with a single byte, not a framed message.** `psql` and PDO open with
   an 8-byte SSLRequest and pgcat replies with one byte, `S` or `N`. A decoder that frames that byte
   as a message desynchronises the whole backend stream and logs nothing after it — the first
   version of this proxy did exactly that, and the client side decoded while the pooler side was
   silent.
2. **The first frontend message has no type byte.** A `StartupMessage` is a bare length and body;
   only a v3 message has a leading type byte. A `0x00` first byte is what tells them apart.
3. **The bytes have to be forwarded.** The first version decoded and logged but never wrote the
   bytes to the other socket, so `psql` hung — a capture that is also a black hole is
   indistinguishable from the fault being studied.

## Evidence — the decode, both directions

A normal query through the proxy (`select 1 as ok`), full handshake to `Terminate`:

```
   0.000  proxy up: 127.0.0.1:5499 -> 127.0.0.1:5433
   1.018  [c1] client->pgcat SSLRequest         len=8
   1.031  [c1] pgcat->client <- SSL refused     len=1
   1.031  [c1] client->pgcat StartupMessage     len=92    user postgresAdmin database reader_db_1 application_name
   1.031  [c1] pgcat->client Auth               len=12
   1.033  [c1] client->pgcat Password           len=40    md5…            (redacted)
   1.033  [c1] pgcat->client Auth               len=8
   1.033  [c1] pgcat->client ParameterStatus    len=22    in_hot_standby on
   1.033  [c1] pgcat->client ParameterStatus    len=26    scram_iterations 4096
   1.033  [c1] pgcat->client ParameterStatus    len=24    server_version 17.9
   …      (fifteen ParameterStatus messages in all)
   1.033  [c1] pgcat->client BackendKeyData     len=12
   1.033  [c1] pgcat->client ReadyForQuery      len=5     I
   1.066  [c1] client->pgcat Query              len=19    select 1 as ok
   1.069  [c1] pgcat->client RowDescription     len=33
   1.069  [c1] pgcat->client DataRow            len=10
   1.069  [c1] pgcat->client CommandComplete    len=13    SELECT 1
   1.069  [c1] pgcat->client ReadyForQuery      len=5     I
   1.069  [c1] client->pgcat Terminate          len=4
   1.069  [c1] connection closed
```

The client waited **3 ms** for its answer, and every frame is accounted for — so a query like this
has no wait to explain. That is the baseline the next capture is read against.

## Evidence — a query that genuinely waits, and where the wait is

`select pg_sleep(4)` is a query that is *supposed* to take four seconds, so it is the control: the
wait is real, the question is whose it is.

```
   1.058  [c1] client->pgcat Query              len=23    select pg_sleep(4)
   2.059  [c1] ~~~ SILENCE 1.00s since client sent Query and no byte came back
   5.079  [c1] pgcat->client RowDescription     len=33
   5.079  [c1] pgcat->client DataRow            len=10
   5.080  [c1] pgcat->client CommandComplete    len=13    SELECT 1
   5.080  [c1] pgcat->client ReadyForQuery      len=5     I
   5.080  [c1] client->pgcat Terminate          len=4
```

The `Query` goes out at `1.058` and the first byte comes back at `5.079` — **4.02 s later**, with
the proxy naming the silence once a second while it holds.

Taken on its own the proxy can only say "the client is waiting". Whether the pooler ever handed the
statement to a server, or whether the client is still queued for a server it has not got, is the
second half of the question — and the pooler answers that itself.

## The discriminator — pgcat's own console, taken while the query waits

pgcat serves an admin console on the same port it pools on (`-d pgcat -U pgcAdmin`). `SHOW POOLS`
is the pooler's own account of every client and server slot, and the two columns that matter are
`cl_waiting` (clients waiting for a server) and `maxwait` (how long the longest of them has waited).

Taken **2 s into the `pg_sleep(4)` wait above**:

```
  database    |     user      | pool_mode   | cl_idle | cl_active | cl_waiting | sv_active | sv_idle | maxwait | maxwait_us
--------------+---------------+-------------+---------+-----------+------------+-----------+---------+---------+-----------
 reader_db_1 | postgresAdmin | transaction |      36 |         1 |          0 |         1 |       9 |       0 |          0
 writer_db_1 | postgresAdmin | transaction |      35 |         1 |          0 |         1 |      11 |       0 |          0
```

`reader_db_1` shows **`cl_active = 1` and `sv_active = 1` with `cl_waiting = 0`**: the one waiting
client has already been given a server, and that server is running the statement. The wait is the
database's — a *server-side* wait, which the pooler merely relayed. Nothing was queueing.

Taken at rest, the same reading is `cl_active = 0`, `cl_waiting = 0`, `maxwait = 0` against both
pools, with `sv_idle` at `14` and `18` — servers available, no queue.

So the two readings together are the answer:

| The proxy's timeline | `SHOW POOLS` while it waits | The wait is |
|---|---|---|
| `Query` sent, no byte back | `cl_waiting > 0`, `maxwait` rising | **pooler-side** — the client has not been given a server, and pgcat's `max_wait_time` is the bound on it |
| `Query` sent, no byte back | `cl_active = 1`, `sv_active = 1`, `cl_waiting = 0` | **server-side** — the statement is on a backend, and the database is what is slow |
| `Query` sent, a `Parse`/`ParseComplete` exchange, then nothing | `cl_active = 1`, `sv_active = 1` | inside the statement's own protocol step — the pooler relayed it and is not the party holding it |

`cl_waiting` is the one column that cannot be read off the wire at all: a client queued for a server
has sent nothing the proxy can see and received nothing to show, so its timeline is identical to a
client whose statement is running. That is why the capture and the console are one instrument, not
two.

## The boundary — what this cannot say

- **It sees one client.** The proxy is per-connection and per-client; it is put in front of the
  traffic a *session* drives, not in front of the pooler. To watch the application's own traffic the
  application has to be pointed at `5499` for the capture, which is a change to what is being
  measured.
- **It cannot see the pooler's checkout.** A client waiting in pgcat's queue sends and receives
  nothing, so the proxy shows an empty timeline — the same shape as a client whose query is running.
  Only `SHOW POOLS` distinguishes them.
- **It cannot see pgcat talking to RDS.** The backend half of the pooler's own traffic (the
  `5433 → RDS` leg) is not on this loopback path, so a stall *between* pgcat and the database is not
  in this capture. `SHOW SERVERS` in the same console is the reading for that leg.
- **It is a diagnostic, not a monitor.** It is a foreground `python3` process on one task, holding a
  listener for as long as it runs; it is for the minutes around a reproduction, not for a service.

## How to run it

The proxy and the driver go up as files on the task (base64-encoded, so no quoting layer can eat
them), then one command runs both so the capture is alive while the query is:

```sh
python3 /tmp/pgsniff.py > /tmp/sniff.log 2>&1 &
sleep 1
P=$(sed -n 's/^password *= *"\(.*\)".*/\1/p' /etc/pgcat/pgcat.toml | head -1)
PGPASSWORD=$P psql "host=127.0.0.1 port=5499 user=postgresAdmin dbname=reader_db_1" -c "select pg_sleep(4)"
sleep 6
cat /tmp/sniff.log
```

Two mechanics that cost a session each time they are got wrong:

- **`pkill -f pgsniff.py` kills the session running it.** The `sh -c` that writes the script has the
  string in its own argv, so the pattern matches the shell. `pkill -f 'python3 /tmp/pgsniff\.py'`
  matches the proxy alone.
- **Do not `wait` on the proxy.** It never exits, so a `wait` blocks for ever; a fixed `sleep` past
  the query is what lets the log be read.

## The fix

**Use the proxy rather than the `tcpdump` line, and correct the runbook.** The container has no
packet capture; it has `python3`, and the decode is what the question actually needs. The record
this replaces asked for a `.pcap`, and a reader who follows it today reaches for a binary that is not
there.

If a real packet capture is wanted — for pgcat's leg to RDS, or for the traffic of a client that
cannot be pointed at a different port — then `tcpdump` has to be added to the image (the container is
Debian-shaped: `apt-get install -y tcpdump` at build time), and it has to be done in the image rather
than on a running task, because a task's filesystem is replaced on the next deployment.

The proxy's script is the artefact worth keeping: it is the working capture-and-decode for a hang on
this deployment, and every special case above is one a reader would otherwise have to rediscover.
