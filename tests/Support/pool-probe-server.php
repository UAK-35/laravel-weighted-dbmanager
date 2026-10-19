<?php

declare(strict_types=1);

/**
 * A tiny PostgreSQL-shaped listener, the other half of `PoolProbeSweepTest`.
 *
 * WHY A PROCESS
 * -------------
 * The sweep holds every connection open at once and waits on them together. A server in the same
 * process could not answer while that wait is running — the probe would be blocked inside
 * `stream_select()` and the test would deadlock rather than fail — so the listener is a process of
 * its own, started by the test and stopped by it.
 *
 * WHAT IT PROVES
 * --------------
 * `barrier` accepts `expect` connections and answers none of them until all have arrived. A probe
 * that asked its names one after another would never get past the first name: the server is
 * waiting for connections that a serial probe would not open until that first answer came back, so
 * the first read would time out. A concurrent probe opens all of them and every name is answered.
 * The barrier is what makes the reading about concurrency rather than about wall-clock speed.
 *
 * `silent` accepts `expect` connections and writes nothing at all. It is the timeout fixture: one
 * budget means the run ends once, not once per name.
 *
 * A name beginning `missing_` is refused with pgcat's own sentence, so a batch can carry all three
 * answers in one wave.
 *
 * Usage: php tests/Support/pool-probe-server.php <barrier|silent> <expect> <port-file>
 */

$arguments = array_slice($argv, 1);

if (count($arguments) !== 3) {
    fwrite(STDERR, "usage: pool-probe-server.php <barrier|silent> <expect> <port-file>\n");
    exit(2);
}

[$mode, $expect, $portFile] = $arguments;

if (! in_array($mode, ['barrier', 'silent'], true)) {
    fwrite(STDERR, "unknown mode: {$mode}\n");
    exit(2);
}

$expect = max(1, (int) $expect);

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if (! is_resource($server)) {
    fwrite(STDERR, 'the fixture could not listen: ' . ($errstr !== '' ? $errstr : (string) $errno) . "\n");
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
$port = (int) substr($name, strrpos($name, ':') + 1);
@stream_set_blocking($server, false);

if (@file_put_contents($portFile, (string) $port) === false) {
    fwrite(STDERR, "the fixture could not write its port to {$portFile}\n");
    exit(1);
}

/** @var array<int, resource> $clients */
$clients = [];
$deadline = microtime(true) + 15.0;

while (count($clients) < $expect && microtime(true) < $deadline) {
    $read = [$server];
    $write = [];
    $except = null;
    $ready = @stream_select($read, $write, $except, 0, 100_000);

    if ($ready === false || $ready === 0) {
        continue;
    }

    $client = @stream_socket_accept($server, 0);

    if (is_resource($client)) {
        @stream_set_blocking($client, false);
        $clients[] = $client;
    }
}

if (count($clients) < $expect) {
    fwrite(STDERR, sprintf('the fixture accepted %d of %d connections', count($clients), $expect));
    exit(1);
}

if ($mode === 'silent') {
    // Hold the connections open and say nothing until the test stops this process. The probe has to
    // reach its one deadline with every name still waiting.
    sleep(30);
    exit(0);
}

/** One PostgreSQL message: `[type][int32 length][payload]`, the length counting itself. */
$message = static fn (string $type, string $payload): string
    => $type . pack('N', 4 + strlen($payload)) . $payload;

/** The bytes a client sends to open a connection: length, version, then `key\0value\0` pairs. */
$databaseOf = static function (string $packet): ?string {
    $length = strlen($packet);

    if ($length < 8) {
        return null;
    }

    $offset = 8;
    $name = null;

    while ($offset < $length) {
        $end = strpos($packet, "\0", $offset);

        if ($end === false) {
            break;
        }

        $key = substr($packet, $offset, $end - $offset);
        $offset = $end + 1;
        $end = strpos($packet, "\0", $offset);

        if ($end === false) {
            break;
        }

        $value = substr($packet, $offset, $end - $offset);
        $offset = $end + 1;

        if ($key === 'database') {
            $name = $value;
        }

        if ($key === '') {
            break;
        }
    }

    return $name;
};

foreach ($clients as $client) {
    $packet = '';
    $readDeadline = microtime(true) + 5.0;

    // A startup packet has no type byte: four length bytes first, then the body.
    while (strlen($packet) < 4 && microtime(true) < $readDeadline) {
        $chunk = @fread($client, 4096);

        if (is_string($chunk) && $chunk !== '') {
            $packet .= $chunk;
        } elseif ($chunk === '' && feof($client)) {
            break;
        }
    }

    $length = strlen($packet) >= 4 ? (int) (unpack('N', substr($packet, 0, 4))[1] ?? 0) : 0;

    while ($length > 4 && strlen($packet) < $length && microtime(true) < $readDeadline) {
        $chunk = @fread($client, $length - strlen($packet));

        if (is_string($chunk) && $chunk !== '') {
            $packet .= $chunk;
        } elseif ($chunk === '' && feof($client)) {
            break;
        }
    }

    $database = $databaseOf($packet) ?? '';

    if (str_starts_with($database, 'missing_')) {
        $payload = "SFATAL\0"
            . 'MNo pool configured for database: "' . $database . "\"\0"
            . "\0";

        @fwrite($client, $message('E', $payload));
    } else {
        // AuthenticationOk: `R`, length 8, code 0. Enough for `classify()` to read an answer.
        @fwrite($client, $message('R', pack('N', 0)));
    }

    @fclose($client);
}

@fclose($server);
exit(0);
