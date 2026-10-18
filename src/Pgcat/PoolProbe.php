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
 *   `controlName()` is how the two poolers are told apart without a credential: it is a name no
 *   configuration declares, and `discriminates()` reads its answer. Refused, the pooler resolves a
 *   pool before it authenticates — so `answered` for a declared pool is proof that pool exists.
 *   Answered, the pooler authenticates first and answers every name, so `answered` proves only that
 *   the pooler is reachable and willing to talk. Nothing usable, and the reading says it could not
 *   be checked. That does not make a blanket pooler's pools provable — it makes the reading say
 *   which question it was able to ask: *does this pool exist*, or only *is this pooler one whose
 *   answers could prove it*.
 *
 * THE THREE ANSWERS
 *   `answered`  the pooler accepted the pool: it asked for a password, reported parameters, or
 *               said the backend is ready. The pool exists.
 *   `missing`   the pooler refused with "No pool configured for database: …". This is the one
 *               answer a flip repairs, because it is a statement about the pooler's own
 *               configuration rather than about the database behind it.
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
     * The port pgcat listens on when nothing else can say: its own convention, which is not
     * PostgreSQL's. A probe aimed at a PostgreSQL server is answered for every name, in that
     * server's words rather than the pooler's, which is the one mistake this class exists to keep
     * out of a repair.
     */
    private const CONVENTIONAL_PORT = 6432;

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
     * A database name no configuration declares, asked to see whether the pooler refuses names it
     * does not hold.
     *
     * The suffix is random rather than a fixed string so that a pool an installation happens to
     * carry cannot turn the control into a false positive: the question is not what the name is,
     * but whether the pooler can tell it from a pool — and a name written into a file would be a
     * pool like any other. The prefix keeps it recognisable in a report.
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
     * The name is the *first* segment of the header's path, and a header that is nothing but a
     * pool's sub-tables still declares that pool: the file this was measured against has no
     * `[pools.writer_db_1]` line at all — only `[pools.writer_db_1.users.0]` and
     * `[pools.writer_db_1.shards.0]` — and its pooler answers for `writer_db_1`. A dotted header is
     * therefore a path rather than a name: `writer_db_1.users.0` is not a pool any client asks for,
     * and putting it in a startup packet is a probe measuring its own arithmetic. So the segment is
     * read as a quoted key — which keeps the dots it was quoted for — or as a bare one that ends
     * where the dot opening the next segment begins.
     *
     * `PREG_UNMATCHED_AS_NULL` is what makes "which of the two matched" a question with an answer:
     * without it PHP reports the group that did not take part as `null` when it is the last one and
     * as an empty string when it is not, so the reading would depend on which alternative matched.
     *
     * @return list<string>
     */
    public static function declaredPools(string $toml): array
    {
        if (preg_match_all('/^[ \t]*\[\s*pools\s*\.\s*(?:"([^"]+)"|([A-Za-z0-9_\-]+))(?=[\s.\]])/m', $toml, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
            return [];
        }

        $names = [];

        foreach ($matches as $match) {
            $name = $match[1] ?? $match[2] ?? '';

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The port a probe should dial, read in the order of authority: what the installation
     * configured, then what the pooler's own file says it listens on, then pgcat's convention.
     *
     * WHY THE FILE, AND WHICH FILE
     *   `[general] port` is the pooler stating its own listen port, and it belongs to the process
     *   rather than to a flip — but only when it is read from the file the process is *running on*.
     *   That is `config_path`, the target a swap renames over, and it is why this reading is not
     *   the one `declaredPools()` makes of a variant: a variant is one of the files a flip may put
     *   in front of the pooler, so a port taken from it would move with the flip instead of staying
     *   with the program. The caller names the file; this method reads bytes, which is what keeps it
     *   a pure function like the rest of the class.
     *
     *   `[general]` is the only section read. A `port` under `[pools…]` is a backend's port, and a
     *   probe sent there would be measuring a database rather than the pooler.
     *
     * WHY THE CONVENTION IS LAST
     *   An installation that configured no port and whose file cannot be read is one the probe will
     *   report as silent, and silence is reported rather than repaired — so the fallback only
     *   decides which address is named in that report. It is pgcat's port rather than PostgreSQL's
     *   for the reason the class note gives: a PostgreSQL server answers every name, and would be
     *   read as a pooler holding them all.
     *
     * @param string   $toml       the bytes of the pooler's own config file, or '' when unreadable
     * @param int|null $configured the port the installation configured, or null/0 when it names none
     */
    public static function port(string $toml, ?int $configured = null): int
    {
        if ($configured !== null && $configured > 0) {
            return $configured;
        }

        $general = false;

        foreach (preg_split('/\R/', $toml) ?: [] as $line) {
            // A trailing comment is not part of the value, and a line that is nothing but one is
            // not a setting: `port = 6432 # the one clients connect to` is a port, `# port = 1`
            // is prose about one.
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

            if (preg_match('/^port\s*=\s*"?(\d+)"?$/', $line, $match) === 1) {
                $port = (int) $match[1];

                // A port outside the range a client can dial is the file's mistake rather than
                // an address, so it falls through to the convention like a missing one does.
                return $port >= 1 && $port <= 65535 ? $port : self::CONVENTIONAL_PORT;
            }
        }

        return self::CONVENTIONAL_PORT;
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
