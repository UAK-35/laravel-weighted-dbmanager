<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

/**
 * The IPv4 address of one interface on the host answering the request.
 *
 * WHY THE STANDARD LIBRARY AND NOT A COMMAND
 * ------------------------------------------
 *   `net_get_interfaces()` has enumerated a machine's adapters since PHP 7.3, and this package
 *   requires 8.4, so the reading needs no binary, no `exec` and no extension of its own — which
 *   matters because this is called from a health route. The alternatives all answer a *different*
 *   question: `$_SERVER['SERVER_ADDR']` is the address the SAPI bound, not the interface's;
 *   `gethostbyname(gethostname())` is usually `127.0.1.1` inside a container; a connected socket's
 *   local address is whatever the route table picked, often `eth0`; and `ip -4 -o addr show dev
 *   eth1` is the right answer bought with a shell in a request path. The file that records the
 *   comparison, and the promotions it leaves for later, is `docs/health-host-ip.md`.
 *
 * WHY NULL IS AN ANSWER
 * ---------------------
 *   The interface is absent, down, IPv6-only, link-local only, or the runtime disabled the
 *   function — five ways to have no address this field can honestly publish. Each one answers
 *   `null` and nothing is attempted in its place: a guessed address is worse than a missing one,
 *   because a reader cannot tell a guess from a reading. The same rule drops `169.254.0.0/16`,
 *   which is what an interface carries when DHCP failed or has not finished — an address, but not
 *   one anything routes to.
 *
 *   `up` is read rather than ignored for the same reason: a down interface keeps its last known
 *   `unicast` entries on some kernels, and publishing one would name an address the host cannot
 *   answer on. Where an interface lists several IPv4 addresses the first one is the primary and is
 *   the answer; they are not sorted, because the kernel's order is the primary-first order.
 */
final class HostAddress
{
    /**
     * The interface the pinned block reports unless the configuration names another.
     *
     * `swrr.health.host_interface` is the setting; this is the documented default and the name the
     * field carried before the setting existed. An installation that serves on `eth0` — Docker's
     * default bridge — names it there, and a name that is empty or not a string resolves back to
     * this one rather than silencing the field.
     */
    public const INTERFACE = 'eth1';

    /**
     * `AF_INET` without ext/sockets: 2 is the value on every platform this package supports, and
     * the constant is not a declared requirement the way the function above is.
     */
    private const AF_INET = 2;

    /**
     * The IPv4 address of the named interface on this host, or null when it cannot be read.
     *
     * The call is `@`-suppressed because a failure emits `E_WARNING` and returns `false`, and a
     * Laravel error handler turns a warning into an exception — a health endpoint must report the
     * missing reading, not fail on it. Nothing is cached: the call is one syscall per response and
     * a process-lifetime memo would describe the network as it was at boot.
     */
    public static function ipv4(string $interface = self::INTERFACE): ?string
    {
        if (! function_exists('net_get_interfaces')) {
            return null;
        }

        return self::from(@net_get_interfaces(), $interface);
    }

    /**
     * The pure half: a listing in, the address out.
     *
     * No system call, so the cases that matter — absent, down, IPv6-only, link-local, several
     * addresses — are fixtures rather than facts about the machine running the suite. A host that
     * names its interfaces something else (Docker's default bridge is `eth0`, and Windows keys its
     * adapters by GUID) lands in the absent case, which is the truthful answer for this field.
     *
     * @param array<mixed>|false $interfaces the value `net_get_interfaces()` returned
     */
    public static function from(array|false $interfaces, string $interface): ?string
    {
        if (! is_array($interfaces) || ! array_key_exists($interface, $interfaces)) {
            return null;
        }

        $link = $interfaces[$interface];

        if (! is_array($link) || ($link['up'] ?? false) !== true) {
            return null;
        }

        $unicast = $link['unicast'] ?? null;

        if (! is_array($unicast)) {
            return null;
        }

        foreach ($unicast as $entry) {
            if (! is_array($entry) || ($entry['family'] ?? null) !== self::AF_INET) {
                continue;
            }

            $address = $entry['address'] ?? null;

            if (! is_string($address)
                || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            if (str_starts_with($address, '169.254.')) {
                continue;
            }

            return $address;
        }

        return null;
    }
}
