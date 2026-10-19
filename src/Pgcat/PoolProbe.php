<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Pgcat;

/**
 * Is a running pgcat answerable for a pool it is supposed to serve?
 *
 * WHY THIS EXISTS
 *   The flipper's other self-heal asks supervisord whether pgcat is RUNNING, and a pooler can be
 *   RUNNING and still be the wrong thing: it holds a configuration with no pool for a database
 *   the application is connected to, so every read to that pool fails while `supervisorctl
 *   status` reports a healthy program. That is not hypothetical — a container served
 *   `FATAL: No pool configured for database: "reader_db_2"` for half an hour while supervisord
 *   called pgcat RUNNING, and the only thing that noticed was the application. supervisord cannot
 *   tell those two apart: a program that is up is up, whatever it is serving.
 *
 * WHAT IT ASKS, AND WHY IT NEEDS NO CREDENTIALS
 *   A client that names a database with no pool is answered with an ErrorResponse saying exactly
 *   that; a client that names a pool that does exist is answered with an AuthenticationRequest, or
 *   with the parameter and ready-for-query messages that follow a trusting one. So the probe is a
 *   PostgreSQL v3 startup packet and the first message of the server's reply, and nothing else — no
 *   password, no `psql`, no `PDO`, no database connection through the pool it is measuring. The
 *   pooler's own admission is the evidence.
 *
 * WHAT IT CAN AND CANNOT TELL APART
 *   That reading rests on the pooler deciding whether a pool exists *before* it authenticates, and
 *   not every pgcat does. The one this package is deployed against answers an AuthenticationRequest
 *   — `R`, with a fresh SCRAM challenge each time — to *every* name it is given: a pool the applied
 *   configuration declares, a pool only a variant declares, a pool commented out of both variants,
 *   and a name no file anywhere contains all come back as the same `R`. On such a pooler `missing`
 *   is never observed and `answered` is a statement about the pooler rather than about the pool.
 *
 *   `controlName()` is how the two poolers are told apart without a credential: it is the sentinel,
 *   a name no configuration declares, and `discriminates()` reads its answer. Refused, the pooler
 *   resolves a pool before it authenticates — so `answered` for a declared pool is proof that pool
 *   exists. Answered, the pooler authenticates first and answers every name, so a pool it accepted
 *   may or may not exist. The sweep does not publish that as `answered`: every pool in the sweep is
 *   then `indeterminate`, because an answer the sentinel shares proves nothing about the pool. That
 *   does not make a blanket pooler's pools provable at the front door — but it does not let the
 *   reading be mistaken for a pass either.
 *
 *   THE POOLER'S OWN INVENTORY, ASKED FIRST
 *   A pooler that authenticates first still knows which pools it holds, and pgcat says so itself:
 *   the admin console is the same listener under the database name `pgcat`, with its own
 *   credentials in the pooler's own `[general]` section, and `SHOW POOLS` lists every pool the
 *   process is serving. `adminPools()` asks exactly that, and it is the only place this class sends
 *   a credential — the pooler's own, read out of the file the process is running on, back to the
 *   pooler over the connection this probe opened.
 *
 *   That is the reading the sweep asks first, because it is the one that does not depend on the
 *   ordering: one connection names every pool the process holds, where the front door needs one
 *   packet per pool and on a pooler that authenticates first still cannot separate a pool from a
 *   name. The startup packet is therefore the fallback, for a console that cannot be read — no
 *   admin credentials in the file, a refusal, a SCRAM challenge, silence — and there the control
 *   name says what its answers are worth. A reading the console could not make is reported as
 *   that rather than as either answer.
 *
 * THE ANSWERS
 *   `answered`  the pooler accepted the pool: it asked for a password, reported parameters, or
 *               said the backend is ready — and the sentinel was refused, so the pool exists.
 *   `missing`   the pooler refused with "No pool configured for database: …". This is the one
 *               answer a flip repairs, because it is a statement about the pooler's own
 *               configuration rather than about the database behind it.
 *   `indeterminate`
 *               the pooler answered, but it answered the sentinel too, so the same answer would
 *               have come back for a name no configuration declares. Published by a sweep rather
 *               than by `classify()`, because one packet cannot know what every other name got;
 *               it is reported and never repaired.
 *   `silent`    no usable answer at all — the address refused the connection, the read timed out,
 *               or nothing came back. Reported, and deliberately *not* repaired here: a pooler
 *               that is not listening is the supervisor's question (`not_running`), and a probe
 *               pointed at the wrong address must not be able to restart a healthy pooler every
 *               minute for ever.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *   It does not connect as a client of the pooler, does not read or write a pool, and does not
 *   need the pool to be reachable behind pgcat — the question is what the pooler *has*, not what
 *   it can reach. It also does not mark anything or restart anything: the throw, the restart and
 *   the record belong to the caller, which is the same split `SingleHostProbe` draws for the
 *   replica sweep.
 */
final class PoolProbe
{
    /** The pooler accepted the pool: it exists. */
    public const STATUS_ANSWERED = 'answered';

    /** The pooler refused because no pool is configured for that database. */
    public const STATUS_MISSING = 'missing';

    /** No usable answer: the address refused, the read timed out, or nothing came back. */
    public const STATUS_SILENT = 'silent';

    /**
     * The pooler answered, but the sentinel was answered too, so the answer does not show that the
     * pool exists.
     *
     * Published by a sweep rather than returned by `ask()`: one startup packet cannot know what
     * every other name got, and the sentinel is asked beside the pools. A sweep that reported these
     * pools as `answered` would hand a surface a green reading of a question it could not ask —
     * which is exactly what a pooler that authenticates before it resolves pools would produce.
     */
    public const STATUS_INDETERMINATE = 'indeterminate';

    /** How long one pool may take to answer before it is reported as silent, in milliseconds. */
    public const DEFAULT_TIMEOUT_MS = 2000;

    /**
     * The prefix a control name carries, so it is recognisable in a log or a report. The suffix is
     * random; see `controlName()` for why the name cannot be a fixed string.
     */
    private const CONTROL_PREFIX = 'weighted-dbmanager-control-';

    /** PostgreSQL protocol version 3.0 as the startup packet encodes it: `3 << 16 | 0`. */
    private const PROTOCOL_VERSION_3 = 196608;

    /**
     * The marker pgcat's own refusal carries. Compared case-insensitively and never reworded: the
     * string is pgcat's vocabulary rather than this package's, and a probe that matched a
     * paraphrase would stop recognising the fault the day the pooler's wording moved.
     */
    private const MISSING_MARKER = 'no pool configured for database';

    /**
     * The database name pgcat answers its admin console on, on the same listener as every pool. A
     * startup packet naming it is how the console is separated from a client asking for a pool.
     */
    private const ADMIN_DATABASE = 'pgcat';

    /** The console's own list of what it holds. */
    private const SHOW_POOLS = 'SHOW POOLS';

    /** The console answered, and its pools are what `pools` carries. */
    public const ADMIN_STATUS_READ = 'read';

    /** The console was asked and would not answer: no credentials, a refusal, a challenge it cannot meet. */
    public const ADMIN_STATUS_REFUSED = 'refused';

    /** How many messages one console round trip may carry before it is read as a loop. */
    private const MAX_ADMIN_MESSAGES = 64;

    /** How much of the server's first message is read before it is classified. */
    private const MAX_ANSWER_BYTES = 4096;

    /** How many characters of an answer are folded into a sentence. */
    private const EVIDENCE_CHARS = 200;

    /**
     * Not instantiable: everything here is a pure function of an address, a name, or the bytes of
     * a configuration file, plus one optional injected exchange.
     */
    private function __construct()
    {
    }

    /**
     * The sentinel: a database name no configuration declares, asked to see whether the pooler
     * refuses names it does not hold.
     *
     * The suffix is random rather than a fixed string so that no file can have been written with
     * the name: the question is not what the name is, but whether the pooler can tell it from a
     * pool, and a name a configuration could hold would be a pool like any other. The prefix keeps
     * it recognisable in a report. `discriminates()` reads the answer, and a sweep turns every
     * `answered` pool into `indeterminate` when this one was answered.
     */
    public static function controlName(): string
    {
        return self::CONTROL_PREFIX.bin2hex(random_bytes(8));
    }

    /**
     * What a control name's answer says about the pooler, and therefore about every `answered` pool
     * in the same sweep.
     *
     * true  the control was refused, so this pooler resolves a pool before it authenticates: an
     *       `answered` pool is one it holds.
     * false the control was answered, so this pooler authenticates first and answers every name:
     *       an `answered` pool is not proof that it exists.
     * null  the control gave no usable answer, so neither can be concluded.
     */
    public static function discriminates(string $status): ?bool
    {
        return match ($status) {
            self::STATUS_MISSING => true,
            self::STATUS_ANSWERED => false,
            default => null,
        };
    }

    /**
     * Ask one pooler whether it is answerable for one database.
     *
     * @param \Closure(string, string, float): array{0: string, 1: string}|null $exchange
     *        how the startup packet is sent and the first message read: `[$packet, $address,
     *        $timeoutSeconds]` -> `[bytes received, error]`. Injected so a test can answer without
     *        a socket, and so an installation that reaches its pooler some other way can say so;
     *        the default opens a TCP connection.
     *
     * @return array{status: string, database: string, answered: bool, missing: bool, answer: string, evidence: string, host: string, port: int, address: string}
     */
    public static function ask(
        string $host,
        int $port,
        string $database,
        string $user,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        ?\Closure $exchange = null,
    ): array {
        $address = sprintf('tcp://%s:%d', $host, $port);
        $timeoutSeconds = max(0.1, $timeoutMs / 1000);
        $packet = self::startupPacket($user, $database);

        [$answer, $error] = ($exchange ?? self::socketExchange())($packet, $address, $timeoutSeconds);

        $verdict = self::classify($answer, $database);

        // The address and the reason a socket never opened are the one part of the verdict the
        // classifier cannot know — it sees bytes, not a connection — so the silent case's evidence
        // is completed here rather than guessed at there.
        $evidence = $verdict['status'] === self::STATUS_SILENT && $error !== ''
            ? sprintf('%s did not answer for pool "%s": %s', $address, $database, $error)
            : $verdict['evidence'];

        return [
            'status' => $verdict['status'],
            'database' => $verdict['database'],
            'answered' => $verdict['answered'],
            'missing' => $verdict['missing'],
            'answer' => $verdict['answer'],
            'evidence' => $evidence,
            'host' => $host,
            'port' => $port,
            'address' => $address,
        ];
    }

    /**
     * What the first message of the pooler's answer says about one pool.
     *
     * A PostgreSQL message is `[type][int32 length][payload]`, and the type alone decides every
     * answer but the refusal: `R` is an authentication request, `S`/`K`/`Z`/`N` follow a trusting
     * one, and `E` is an error. An `E` is read for pgcat's own sentence, because that is the whole
     * difference between "this pool is not configured" — the fault a flip repairs — and "the
     * password is wrong", which is a pool that exists with a different problem.
     *
     * @return array{status: string, database: string, answered: bool, missing: bool, answer: string, evidence: string}
     */
    public static function classify(string $answer, string $database): array
    {
        $type = $answer === '' ? '' : $answer[0];
        $message = self::errorMessage($answer);

        if ($type === '') {
            return [
                'status' => self::STATUS_SILENT,
                'database' => $database,
                'answered' => false,
                'missing' => false,
                'answer' => '',
                'evidence' => sprintf('the pooler gave no answer for pool "%s"', $database),
            ];
        }

        if ($type === 'E' && stripos($message, self::MISSING_MARKER) !== false) {
            return [
                'status' => self::STATUS_MISSING,
                'database' => $database,
                'answered' => false,
                'missing' => true,
                'answer' => $message,
                'evidence' => sprintf(
                    'pgcat is running but has no pool "%s": %s',
                    $database,
                    $message,
                ),
            ];
        }

        return [
            'status' => self::STATUS_ANSWERED,
            'database' => $database,
            'answered' => true,
            'missing' => false,
            'answer' => $message,
            'evidence' => $type === 'E'
                ? sprintf('the pooler answered for pool "%s" with an error: %s', $database, $message)
                : sprintf('the pooler answered for pool "%s"', $database),
        ];
    }

    /**
     * The bytes a PostgreSQL client sends to open a connection: the length, the protocol version,
     * then `key\0value\0` pairs closed by a final `\0`.
     *
     * `database` is the pool name. pgcat addresses a pool by the client's database parameter, which
     * is why this is the one field that has to be the pool's own name, and why a name with no pool
     * is answered with `No pool configured for database` — on a pooler that resolves the pool
     * before it asks anyone to authenticate. The class note says what the other kind answers.
     */
    public static function startupPacket(string $user, string $database): string
    {
        $parameters = '';

        foreach (['user' => $user, 'database' => $database, 'application_name' => 'weighted-dbmanager-pool-probe'] as $key => $value) {
            $parameters .= $key."\0".$value."\0";
        }

        $parameters .= "\0";

        return pack('N', 8 + strlen($parameters))
            .pack('N', self::PROTOCOL_VERSION_3)
            .$parameters;
    }

    /**
     * The pool names a pgcat TOML declares, in the order they appear: one per pool, read off the
     * first segment of each `[pools.<name>]` header and of each `[pools.<name>.<sub>.<n>]` one.
     *
     * That segment *is* the pool's name, and it is the same name clients put in their `database`
     * parameter — so this is the list of names a run's expected variant claims the
     * pooler should be answering for, which is exactly what a probe needs and why it is read off
     * the file the flip is about rather than configured a second time.
     *
     * The header is read as a *path* and its first segment as the name, rather than as a prefix
     * that happens to stop at the first dot. That matters because the deployed pgcat file declares
     * `writer_db_1` through its sub-tables alone — `[pools.writer_db_1.users.0]` and
     * `[pools.writer_db_1.shards.0]`, with no `[pools.writer_db_1]` line at all — and its pooler
     * answers for `writer_db_1`. `writer_db_1.users.0` is not a pool any client asks for, so a
     * sub-table must never become one: `users.0` and `shards.0` are segments one and two of the
     * path, and only segment zero is read. A quoted name keeps the dots it was quoted for, in
     * either TOML string form, and a bare one ends where the dot opening the next segment begins.
     *
     * A header with no closing bracket declares nothing: a truncated template is a file the shape
     * reading reports, not a pool to ask a pooler about.
     *
     * `PREG_UNMATCHED_AS_NULL` is what makes "which of the three matched" a question with an
     * answer: without it PHP reports the groups that did not take part as `null` when they are the
     * last ones and as empty strings when they are not, so the reading would depend on which
     * alternative matched.
     *
     * @return list<string>
     */
    public static function declaredPools(string $toml): array
    {
        if (preg_match_all('/^[ \t]*\[[ \t]*pools[ \t]*\.([^\]\r\n]*)\]/m', $toml, $matches) === false) {
            return [];
        }

        $names = [];

        foreach ($matches[1] as $path) {
            $name = self::firstPoolSegment($path);

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The first key of a `[pools…]` header's path, unquoted, or '' when the path does not begin
     * with a TOML key.
     *
     * The three key forms TOML allows are the three the reading accepts: a basic string
     * (`"writer.db"`, which keeps its dot because it is one name), a literal string
     * (`'writer.db'`), and a bare key (`writer_db_1`). Only the first segment is returned; the
     * rest of the path is sub-tables by definition, which is what keeps `users.0` out of a startup
     * packet.
     */
    private static function firstPoolSegment(string $path): string
    {
        if (preg_match('/^[ \t]*(?:"([^"]+)"|\'([^\']+)\'|([A-Za-z0-9_\-]+))[ \t]*(?:\.|$)/', $path, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return '';
        }

        return $match[1] ?? $match[2] ?? $match[3] ?? '';
    }

    /**
     * The port the pooler's own file says it listens on, or null when the file does not name one.
     *
     * WHY THE FILE, AND WHY ONLY THE FILE
     *   `[general] port` is the pooler stating its own listen port, and it belongs to the process
     *   rather than to a flip — but only when it is read from the file the process is *running on*.
     *   That is `config_path`, the target a swap renames over, and it is why this reading is not
     *   the one `declaredPools()` makes of a variant: a variant is one of the files a flip may put
     *   in front of the pooler, so a port taken from it would move with the flip instead of staying
     *   with the program. The caller names the file; this method reads bytes, which is what keeps it
     *   a pure function like the rest of the class.
     *
     *   There is no second source. A setting beside the probe could say a different port from the
     *   one the pooler names — that is the one thing this reading cannot survive, because an
     *   installation armed on such a port dials nothing and the silence reads as a pooler that is
     *   down. pgcat's convention (`6432`) is not used either: it is a guess, and a guess that lands
     *   on a PostgreSQL server is answered for every name, in that server's words, and would be read
     *   as a pooler holding them all. Null is the honest answer, and the caller arms no probe rather
     *   than dialing a port the pooler never named.
     *
     *   `[general]` is the only section read. A `port` under `[pools…]` is a backend's port, and a
     *   probe sent there would be measuring a database rather than the pooler.
     *
     * @param string $toml the bytes of the pooler's own config file, or '' when unreadable
     */
    public static function port(string $toml): ?int
    {
        $port = self::generalSettings($toml)['port'] ?? null;

        if ($port === null || preg_match('/^\d+$/', $port) !== 1) {
            return null;
        }

        $value = (int) $port;

        // A port outside the range a client can dial is the file's mistake rather than an address,
        // and it is not one this reading quietly replaces with a guess.
        return $value >= 1 && $value <= 65535 ? $value : null;
    }

    /**
     * The settings a pooler's own file carries under `[general]`, keyed lower-case.
     *
     * One reader for the two facts this class takes from that section — the listen port and the
     * admin console's credentials — so `port()` and `adminPools()` cannot disagree about what the
     * file says or about which section said it. `[pools…]` is never read: a port or a password
     * there belongs to a backend or to a pool's user, and a probe using one would be measuring
     * something other than the pooler.
     *
     * A trailing comment is not part of a value, and a line that is nothing but one is not a
     * setting: `port = 6432 # the one clients connect to` is a port, `# port = 1` is prose about
     * one. The first occurrence wins, which is how a TOML file is read.
     *
     * @return array<string, string>
     */
    private static function generalSettings(string $toml): array
    {
        $settings = [];
        $general = false;

        foreach (preg_split('/\R/', $toml) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\[\s*([^\]]+?)\s*\]$/', $line, $header) === 1) {
                $general = strtolower($header[1]) === 'general';

                continue;
            }

            if (! $general) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_\-]+)\s*=\s*(?:"([^"]*)"|(\S+))$/', $line, $match, PREG_UNMATCHED_AS_NULL) === 1) {
                $settings[strtolower((string) $match[1])] ??= $match[2] ?? $match[3] ?? '';
            }
        }

        return $settings;
    }

    /**
     * The pool names the pooler's own admin console lists: the reading the sweep asks first.
     *
     * WHY THE CONSOLE, AND NOT A CLIENT LOGIN
     *   A pooler that authenticates before it resolves a pool answers `R` for every name, so no
     *   number of startup packets can separate a pool it holds from one it does not: after `R`, a
     *   wrong password is refused the same way either way. What does know is the process itself,
     *   and pgcat exposes it — the admin console is the same listener under the database name
     *   `pgcat`, authenticated with `general.admin_username` and `general.admin_password` from the
     *   file the process is running on, and `SHOW POOLS` lists what it is serving. That is the
     *   reading this method makes, and it names pools rather than proving one: the caller decides
     *   which declared pool is absent from the list.
     *
     * WHY IT IS THE POOLER'S OWN CREDENTIAL
     *   Nothing new is configured and no secret leaves the installation: the username and password
     *   are already in the file the caller is reading, and they are sent to the pooler they belong
     *   to, over the connection this probe opened. They are never reported — `evidence` names the
     *   address and the console's own refusal, not the password — and the client-facing exchange
     *   still sends none.
     *
     * WHAT IT CANNOT DO
     *   A file that names no `admin_username` is refused rather than guessed at, because pgcat's
     *   defaults are a credential this package should not assume. A console that asks for
     *   SCRAM-SHA-256 is refused too: the challenge cannot be answered without a SASL exchange,
     *   and pretending otherwise would be a login attempt dressed as a reading. Both are reported
     *   as `refused` with the console's own words, and silence is `silent` — so the caller can say
     *   the pools were not proved rather than inventing which ones exist.
     *
     * @param string $toml the bytes of the pooler's own config file, or '' when unreadable
     * @param \Closure(string): array{0: string, 1: string}|null $exchange
     *        one round trip of the conversation: the bytes to send, answered with the server's
     *        reply up to the message that ends it — an error, a ready-for-query, or an
     *        authentication challenge. Injected so a test can script the exchange and so an
     *        installation that reaches its pooler some other way can say so; the default opens one
     *        TCP connection and keeps it for the whole conversation.
     *
     * @return array{status: string, database: string, pools: list<string>, evidence: string, host: string, port: int, address: string}
     */
    public static function adminPools(
        string $toml,
        string $host,
        int $port,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        ?\Closure $exchange = null,
    ): array {
        $address = sprintf('tcp://%s:%d', $host, $port);

        $refused = static fn (string $why): array => [
            'status' => self::ADMIN_STATUS_REFUSED,
            'database' => self::ADMIN_DATABASE,
            'pools' => [],
            'evidence' => $why,
            'host' => $host,
            'port' => $port,
            'address' => $address,
        ];

        $silent = static fn (string $why): array => [
            'status' => self::STATUS_SILENT,
            'database' => self::ADMIN_DATABASE,
            'pools' => [],
            'evidence' => $why,
            'host' => $host,
            'port' => $port,
            'address' => $address,
        ];

        $settings = self::generalSettings($toml);
        $username = $settings['admin_username'] ?? '';
        $password = $settings['admin_password'] ?? '';

        if ($username === '') {
            return $refused(sprintf(
                'the pooler\'s own file names no `[general] admin_username`, so its console at %s cannot be asked which pools it holds',
                $address,
            ));
        }

        $exchange ??= self::adminSocketExchange($address, max(0.1, $timeoutMs / 1000));

        [$reply, $error] = $exchange(self::startupPacket($username, self::ADMIN_DATABASE));

        if ($reply === '') {
            return $silent(sprintf(
                '%s did not answer for its admin console: %s',
                $address,
                $error !== '' ? $error : 'the console gave no answer',
            ));
        }

        if (self::firstType($reply) === 'E') {
            return $refused(sprintf(
                'the admin console at %s refused the pooler\'s own credentials: %s',
                $address,
                self::errorMessage($reply),
            ));
        }

        // The challenge decides the answer: md5 and cleartext can be met with the password from
        // the file, and SASL cannot — a SCRAM verifier is not a password, and this probe does not
        // pretend to hold one.
        $challenge = self::authenticationCode($reply);

        if ($challenge === 3 || $challenge === 5) {
            [$reply, $error] = $exchange(self::passwordMessage(
                $password,
                $username,
                $challenge === 5 ? self::md5Salt($reply) : null,
            ));

            if ($reply === '') {
                return $silent(sprintf(
                    '%s did not answer the password for its admin console: %s',
                    $address,
                    $error !== '' ? $error : 'the console gave no answer',
                ));
            }

            if (self::firstType($reply) === 'E') {
                return $refused(sprintf(
                    'the admin console at %s refused the pooler\'s own credentials: %s',
                    $address,
                    self::errorMessage($reply),
                ));
            }
        } elseif ($challenge === 10 || $challenge === 11) {
            return $refused(sprintf(
                'the admin console at %s asks for SCRAM-SHA-256, and this probe answers only the cleartext and md5 challenges — the pools cannot be listed without a SASL exchange',
                $address,
            ));
        }

        // A console that splits its greeting across two reads is still one greeting: the extra
        // read costs nothing and keeps the query from being sent into the middle of it.
        if (! self::hasReady($reply)) {
            [$more] = $exchange('');
            $reply .= $more;

            if (! self::hasReady($reply)) {
                return $silent(sprintf('%s did not finish its admin answer', $address));
            }
        }

        [$rows, $error] = $exchange(self::queryMessage(self::SHOW_POOLS));

        if ($rows === '') {
            return $silent(sprintf(
                '%s did not answer `%s`: %s',
                $address,
                self::SHOW_POOLS,
                $error !== '' ? $error : 'the console gave no answer',
            ));
        }

        if (self::firstType($rows) === 'E') {
            return $refused(sprintf(
                'the admin console at %s refused `%s`: %s',
                $address,
                self::SHOW_POOLS,
                self::errorMessage($rows),
            ));
        }

        $pools = self::showPools($rows);

        if ($pools === null) {
            return $refused(sprintf(
                'the admin console at %s answered `%s` without a pool column, so its list could not be read',
                $address,
                self::SHOW_POOLS,
            ));
        }

        return [
            'status' => self::ADMIN_STATUS_READ,
            'database' => self::ADMIN_DATABASE,
            'pools' => $pools,
            'evidence' => sprintf('the admin console at %s listed %d pool(s)', $address, count($pools)),
            'host' => $host,
            'port' => $port,
            'address' => $address,
        ];
    }

    /**
     * One PostgreSQL message: `[type][int32 length][payload]`, the length counting itself.
     */
    private static function message(string $type, string $payload): string
    {
        return $type.pack('N', 4 + strlen($payload)).$payload;
    }

    /**
     * The `p` message that answers a password challenge: the md5 form for a salted one, and the
     * password itself for a console configured as `trust` that still asks in the clear.
     *
     * The md5 answer is `"md5" . md5(md5(password . user) . salt)`, which is the whole reason the
     * pooler's own file is enough: no hashing scheme of ours is involved, and nothing is written
     * anywhere.
     */
    private static function passwordMessage(string $password, string $user, ?string $salt): string
    {
        $secret = $salt === null
            ? $password
            : 'md5'.md5(md5($password.$user).$salt);

        return self::message('p', $secret."\0");
    }

    /** The simple-query message the console answers with rows. */
    private static function queryMessage(string $sql): string
    {
        return self::message('Q', $sql."\0");
    }

    /** The type byte of the first message in a reply, or '' when there is none. */
    private static function firstType(string $reply): string
    {
        return $reply === '' ? '' : $reply[0];
    }

    /** The authentication code of a reply that starts with an `R`, or null when it starts otherwise. */
    private static function authenticationCode(string $reply): ?int
    {
        if (self::firstType($reply) !== 'R' || strlen($reply) < 9) {
            return null;
        }

        /** @var int $code */
        $code = unpack('N', substr($reply, 5, 4))[1] ?? 0;

        return $code;
    }

    /** The four salt bytes of an md5 challenge, or null when the challenge is another kind. */
    private static function md5Salt(string $reply): ?string
    {
        if (self::authenticationCode($reply) !== 5 || strlen($reply) < 13) {
            return null;
        }

        return substr($reply, 9, 4);
    }

    /** Whether a reply carries a ready-for-query message, which is where a round trip ends. */
    private static function hasReady(string $reply): bool
    {
        foreach (self::messages($reply) as [$type]) {
            if ($type === 'Z') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every whole message in a reply, as `[type, payload]`, stopping at the first frame that is
     * truncated or claims a length this class will not read.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function messages(string $bytes): array
    {
        $messages = [];
        $offset = 0;
        $length = strlen($bytes);

        while ($offset + 5 <= $length) {
            /** @var int $size */
            $size = unpack('N', substr($bytes, $offset + 1, 4))[1] ?? 0;

            if ($size < 4 || $offset + 1 + $size > $length) {
                break;
            }

            $messages[] = [$bytes[$offset], substr($bytes, $offset + 5, $size - 4)];
            $offset += 1 + $size;
        }

        return $messages;
    }

    /**
     * The names a pooler's `SHOW POOLS` row description gives the column holding the pool's own
     * name, in the order they are read.
     *
     * Upstream pgcat names it `database` — v1.2.0's `PoolStats::generate_header()` opens
     * `database, user, pool_mode, …`, pgbouncer's word for the name a client connects with — and
     * that is the image the live fixture runs. `pool` is read first because a console in that
     * vocabulary is the one this package was first measured against, and because a console that
     * carries both names is then read exactly as it always was.
     */
    private const POOL_COLUMNS = ['pool', 'database'];

    /**
     * The pool names in a `SHOW POOLS` answer: the values of the column the console names the pool
     * in.
     *
     * Read by name rather than by position, because the column order is the console's business and
     * a reader that assumed the first column would start reporting usernames the day it moved. A
     * `T` message names the columns and every `D` message carries one row, so an answer with
     * neither a `pool` nor a `database` column — or no row description at all — is unreadable
     * rather than empty, and `null` is how that is said.
     *
     * @return list<string>|null
     */
    private static function showPools(string $bytes): ?array
    {
        $names = null;
        $rows = [];

        foreach (self::messages($bytes) as [$type, $payload]) {
            if ($type === 'T') {
                $names = self::fieldNames($payload);
            } elseif ($type === 'D') {
                $rows[] = self::rowValues($payload);
            }
        }

        if ($names === null) {
            return null;
        }

        $columns = array_map(static fn (string $name): string => strtolower($name), $names);
        $index = null;

        foreach (self::POOL_COLUMNS as $candidate) {
            $found = array_search($candidate, $columns, true);

            if (is_int($found)) {
                $index = $found;

                break;
            }
        }

        if ($index === null) {
            return null;
        }

        $pools = [];

        foreach ($rows as $row) {
            $value = $row[$index] ?? null;

            if (is_string($value) && $value !== '' && ! in_array($value, $pools, true)) {
                $pools[] = $value;
            }
        }

        return $pools;
    }

    /**
     * The column names out of a RowDescription: an int16 count, then each name followed by the
     * eighteen fixed bytes that describe the field.
     *
     * @return list<string>
     */
    private static function fieldNames(string $payload): array
    {
        /** @var int $count */
        $count = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] ?? 0 : 0;
        $names = [];
        $offset = 2;

        for ($i = 0; $i < $count; $i++) {
            $end = strpos($payload, "\0", $offset);

            if ($end === false) {
                break;
            }

            $names[] = substr($payload, $offset, $end - $offset);
            $offset = $end + 1 + 18;

            if ($offset > strlen($payload)) {
                break;
            }
        }

        return $names;
    }

    /**
     * The values out of a DataRow: an int16 count, then each column as an int32 length (-1 for
     * null) and its bytes.
     *
     * @return list<string|null>
     */
    private static function rowValues(string $payload): array
    {
        /** @var int $count */
        $count = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] ?? 0 : 0;
        $values = [];
        $offset = 2;
        $length = strlen($payload);

        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                break;
            }

            /** @var int $size */
            $size = unpack('N', substr($payload, $offset, 4))[1] ?? 0;
            $offset += 4;

            if ($size < 0) {
                $values[] = null;

                continue;
            }

            if ($offset + $size > $length) {
                break;
            }

            $values[] = substr($payload, $offset, $size);
            $offset += $size;
        }

        return $values;
    }

    /**
     * The default console conversation: one TCP connection, kept for the whole exchange, and every
     * round trip bounded by the timeout the caller configured.
     *
     * A round trip ends where the server starts waiting for the client again — an error, a
     * ready-for-query, or a password challenge — and only the last of those is asked for more. The
     * connection is opened on the first round trip so a refused address is reported once, in the
     * first answer's error, rather than as a silent reading.
     *
     * @return \Closure(string): array{0: string, 1: string}
     */
    private static function adminSocketExchange(string $address, float $timeout): \Closure
    {
        /** @var resource|null $stream */
        $stream = null;
        $failure = '';

        return static function (string $client) use ($address, $timeout, &$stream, &$failure): array {
            if (! is_resource($stream)) {
                if ($failure !== '') {
                    return ['', $failure];
                }

                $errno = 0;
                $errstr = '';
                $stream = @stream_socket_client($address, $errno, $errstr, $timeout);

                if (! is_resource($stream)) {
                    $failure = is_string($errstr) && $errstr !== '' ? $errstr : sprintf('the connection to %s failed', $address);
                    $stream = null;

                    return ['', $failure];
                }

                @stream_set_timeout($stream, (int) max(1, ceil($timeout)));
            }

            if ($client !== '' && @fwrite($stream, $client) === false) {
                return ['', 'the admin console could not be written to'];
            }

            $reply = '';

            for ($i = 0; $i < self::MAX_ADMIN_MESSAGES; $i++) {
                $message = self::readMessage($stream);

                if ($message === null) {
                    break;
                }

                $reply .= $message;

                if ($message[0] === 'E' || $message[0] === 'Z') {
                    break;
                }

                if ($message[0] === 'R' && self::authenticationCode($message) !== 0) {
                    break;
                }
            }

            return [$reply, $reply === '' ? 'the admin console did not answer' : ''];
        };
    }

    /**
     * One whole message read off a stream, or null when the read timed out, the connection closed,
     * or the length does not describe a message this class will read.
     *
     * @param resource $stream
     */
    private static function readMessage($stream): ?string
    {
        $header = '';

        while (strlen($header) < 5) {
            $chunk = @fread($stream, 5 - strlen($header));

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $header .= $chunk;
        }

        /** @var int $size */
        $size = unpack('N', substr($header, 1, 4))[1] ?? 0;

        if ($size < 4 || $size > self::MAX_ANSWER_BYTES) {
            return null;
        }

        $payload = '';
        $remaining = $size - 4;

        while ($remaining > 0) {
            $chunk = @fread($stream, max(1, $remaining));

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $payload .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $header[0].pack('N', $size).$payload;
    }

    /**
     * The human sentence out of an ErrorResponse — the `M` field — or the answer itself when it is
     * not one.
     *
     * Fields are `[byte code][string value]`, terminated by a zero byte, and the message is the one
     * pgcat's refusal carries. Anything unreadable falls back to the raw bytes stripped of control
     * characters, so a pooler that answered in a shape this parser does not know is still reported
     * in words rather than as an empty string.
     */
    private static function errorMessage(string $answer): string
    {
        if ($answer === '' || $answer[0] !== 'E') {
            return self::printable($answer);
        }

        $payload = strlen($answer) > 5 ? substr($answer, 5) : '';
        $offset = 0;
        $length = strlen($payload);
        $message = '';

        while ($offset < $length) {
            $field = $payload[$offset];
            $offset++;

            if ($field === "\0") {
                break;
            }

            $end = strpos($payload, "\0", $offset);

            if ($end === false) {
                break;
            }

            $value = substr($payload, $offset, $end - $offset);
            $offset = $end + 1;

            if ($field === 'M') {
                $message = $value;

                break;
            }
        }

        return $message !== '' ? $message : self::printable($answer);
    }

    /**
     * An answer as one readable line, with control characters — including the message framing —
     * turned into spaces and a long answer clipped.
     */
    private static function printable(string $answer): string
    {
        $text = (string) preg_replace('/[^\x20-\x7E]+/', ' ', $answer);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return strlen($text) > self::EVIDENCE_CHARS
            ? substr($text, 0, self::EVIDENCE_CHARS).'...'
            : $text;
    }

    /**
     * The default exchange: open one TCP connection, send the startup packet, read the first whole
     * message back, close.
     *
     * The read is a bounded loop rather than a single `fread()`, because TCP is free to deliver the
     * type byte and the length separately and a half-read ErrorResponse would classify as an
     * answer. `stream_set_timeout()` bounds the whole exchange: a pooler that accepts the
     * connection and says nothing is a silent answer, not a hung run.
     *
     * @return \Closure(string, string, float): array{0: string, 1: string}
     */
    private static function socketExchange(): \Closure
    {
        return static function (string $packet, string $address, float $timeout): array {
            $errno = 0;
            $errstr = '';

            $stream = @stream_socket_client($address, $errno, $errstr, $timeout);

            if (! is_resource($stream)) {
                // `$errstr` is filled by reference, so it is read as "the socket's own words, when
                // there are any" rather than assumed: an extension that answers with nothing still
                // gets a sentence naming the address.
                $why = is_string($errstr) && $errstr !== '' ? $errstr : sprintf('the connection to %s failed', $address);

                return ['', $why];
            }

            try {
                @stream_set_timeout($stream, (int) max(1, ceil($timeout)));

                if (@fwrite($stream, $packet) === false) {
                    return ['', 'the startup packet could not be written'];
                }

                $buffer = '';

                while (strlen($buffer) < self::MAX_ANSWER_BYTES) {
                    $want = strlen($buffer) < 5 ? 5 - strlen($buffer) : self::MAX_ANSWER_BYTES - strlen($buffer);
                    $chunk = @fread($stream, max(1, $want));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $buffer .= $chunk;

                    if (strlen($buffer) >= 5) {
                        /** @var int $length */
                        $length = unpack('N', substr($buffer, 1, 4))[1] ?? 0;

                        if ($length < 4 || $length > self::MAX_ANSWER_BYTES) {
                            break;
                        }

                        if (strlen($buffer) >= 1 + $length) {
                            break;
                        }
                    }
                }

                return [$buffer, $buffer === '' ? 'the pooler did not answer' : ''];
            } finally {
                @fclose($stream);
            }
        };
    }
}
