<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Uak35\WeightedDbManager\Support\HostAddress;

/**
 * `HostAddress` — the interface listing in, one address out, or null.
 *
 * The reading is one call into the standard library, and everything worth pinning about it is
 * what it does with the answer. So the system call is not part of these tests: every case here
 * hands `from()` a listing shaped the way `net_get_interfaces()` shapes one, which is what lets
 * the suite state what an absent interface, a down one, an IPv6-only one and a link-local one
 * answer without depending on the interfaces of the machine running it. The one case the fixtures
 * cannot reach — a host that names its interface something other than `eth1` — is the absent case,
 * and it is asserted by name so a reader can see the decision rather than infer it.
 *
 * `ipv4()` itself is deliberately not asserted against this machine: on the checkout this was
 * written on there is no `eth1`, so the honest assertion is `null`, and a test that passed only on
 * hosts without the interface would be measuring the machine rather than the class.
 */
final class HostAddressTest extends TestCase
{
    /**
     * The shape `net_get_interfaces()` returns for an adapter: the fields this reading uses, and
     * no others, because a fixture that carried the rest would suggest the rest is read.
     *
     * @param list<array<string, mixed>> $unicast
     * @return array<string, array<string, mixed>>
     */
    private static function listing(array $unicast, bool $up = true): array
    {
        return [
            'eth1' => [
                'up' => $up,
                'unicast' => $unicast,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function ipv4Entry(string $address): array
    {
        return [
            'flags' => 0,
            'family' => 2,
            'address' => $address,
            'netmask' => '255.255.255.0',
        ];
    }

    public function test_the_eth1_ipv4_address_is_published(): void
    {
        $address = HostAddress::from(self::listing([self::ipv4Entry('10.0.3.17')]), 'eth1');

        $this->assertSame('10.0.3.17', $address);
    }

    public function test_an_absent_interface_answers_null(): void
    {
        $this->assertNull(HostAddress::from([], 'eth1'), 'no listing entry means no address to publish');
        $this->assertNull(HostAddress::from(self::listing([self::ipv4Entry('10.0.3.17')]), 'eth2'));
    }

    /**
     * A kernel can keep an interface's last `unicast` entries after the link goes down, so the
     * flag decides rather than the entries being trusted on their own.
     */
    public function test_an_interface_that_is_down_answers_null(): void
    {
        $address = HostAddress::from(self::listing([self::ipv4Entry('10.0.3.17')], up: false), 'eth1');

        $this->assertNull($address);
    }

    public function test_an_interface_with_only_ipv6_answers_null(): void
    {
        $address = HostAddress::from(self::listing([
            ['family' => 10, 'address' => 'fd00::1', 'netmask' => 'ffff:ffff:ffff:ffff::'],
            ['family' => 23, 'address' => 'fe80::1', 'netmask' => 'ffff:ffff:ffff:ffff::'],
        ]), 'eth1');

        $this->assertNull($address, 'an IPv6 address is not an answer under an IPv4 field');
    }

    public function test_a_malformed_address_answers_null(): void
    {
        $address = HostAddress::from(self::listing([
            self::ipv4Entry('not-an-address'),
            self::ipv4Entry('10.0.3.'),
        ]), 'eth1');

        $this->assertNull($address);
    }

    /**
     * `169.254.0.0/16` is what an interface carries when DHCP failed or has not finished. It is a
     * real address, and it is not one the host can be reached on, so publishing it as the host's
     * address would be a reading that is worse than none.
     */
    public function test_a_link_local_address_answers_null(): void
    {
        $address = HostAddress::from(self::listing([self::ipv4Entry('169.254.242.7')]), 'eth1');

        $this->assertNull($address);
    }

    /**
     * A Linux interface may carry secondary addresses. The first entry is the primary, so the
     * reading takes it rather than sorting — and a link-local entry before a routable one is
     * skipped rather than becoming the primary by position.
     */
    public function test_the_first_routable_ipv4_address_is_taken(): void
    {
        $address = HostAddress::from(self::listing([
            self::ipv4Entry('169.254.242.7'),
            self::ipv4Entry('10.0.3.17'),
            self::ipv4Entry('10.0.3.18'),
        ]), 'eth1');

        $this->assertSame('10.0.3.17', $address);
    }

    public function test_a_listing_without_unicast_answers_null(): void
    {
        $this->assertNull(HostAddress::from(['eth1' => ['up' => true]], 'eth1'));
        $this->assertNull(HostAddress::from(['eth1' => ['up' => true, 'unicast' => 'none']], 'eth1'));
        $this->assertNull(HostAddress::from(['eth1' => 'eth1'], 'eth1'));
    }

    public function test_a_failed_system_call_answers_null(): void
    {
        $this->assertNull(HostAddress::from(false, 'eth1'));
    }

    /**
     * No fallback to another name: `eth0`, `en0` and Windows' GUID keys are all the absent case.
     * A field that silently reported whichever interface happened to be up would publish an
     * address the contract does not name, and no reader could tell it had.
     */
    public function test_another_interface_being_up_does_not_answer_for_eth1(): void
    {
        $listing = [
            'eth0' => ['up' => true, 'unicast' => [self::ipv4Entry('10.0.3.9')]],
            'lo' => ['up' => true, 'unicast' => [self::ipv4Entry('127.0.0.1')]],
        ];

        $this->assertNull(HostAddress::from($listing, 'eth1'));
        $this->assertSame('10.0.3.9', HostAddress::from($listing, 'eth0'), 'the name is asked for, not guessed');
    }

    public function test_the_default_interface_is_eth1(): void
    {
        $this->assertSame('eth1', HostAddress::INTERFACE);
    }
}
