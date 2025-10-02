<?php

namespace Tests\Scanner;

use Brain\Monkey\Functions;

final class RemoteRequestClientTest extends ScannerTestCase
{
    public function test_get_request_timeout_constraints_merges_filter_values(): void
    {
        Functions\when('apply_filters')->alias(function ($hook, $value, ...$args) {
            if ($hook === 'blc_request_timeout_constraints') {
                return [
                    'head' => ['default' => 8, 'min' => 2, 'max' => 12],
                    'get'  => ['default' => 15, 'min' => 5, 'max' => 45],
                ];
            }

            return $value;
        });

        $constraints = blc_get_request_timeout_constraints();

        $this->assertSame(
            ['default' => 8.0, 'min' => 2.0, 'max' => 12.0],
            $constraints['head']
        );
        $this->assertSame(
            ['default' => 15.0, 'min' => 5.0, 'max' => 45.0],
            $constraints['get']
        );
    }

    public function test_normalize_timeout_option_clamps_and_parses_values(): void
    {
        $this->assertSame(4.5, blc_normalize_timeout_option('4,5', 5.0, 1.0, 10.0));
        $this->assertSame(1.0, blc_normalize_timeout_option('0', 5.0, 1.0, 10.0));
        $this->assertSame(10.0, blc_normalize_timeout_option('50', 5.0, 1.0, 10.0));
        $this->assertSame(5.0, blc_normalize_timeout_option('n/a', 5.0, 1.0, 10.0));
    }

    public function test_is_public_ip_address_rejects_private_and_loopback_ranges(): void
    {
        $this->assertFalse(blc_is_public_ip_address('192.168.1.20'));
        $this->assertFalse(blc_is_public_ip_address('127.0.0.1'));
        $this->assertFalse(blc_is_public_ip_address('::1'));
        $this->assertFalse(blc_is_public_ip_address('fe80::1'));
    }

    public function test_is_public_ip_address_accepts_public_addresses(): void
    {
        $this->assertTrue(blc_is_public_ip_address('93.184.216.34'));
        $this->assertTrue(blc_is_public_ip_address('2001:4860:4860::8888'));
    }

    public function test_normalize_remote_host_lowercases_and_trims(): void
    {
        $this->assertSame('example.com', blc_normalize_remote_host(' Example.COM '));
        $this->assertSame('2001:db8::1', blc_normalize_remote_host('2001:DB8::1'));
        $this->assertSame('203.0.113.5', blc_normalize_remote_host('203.0.113.5'));
    }
}
