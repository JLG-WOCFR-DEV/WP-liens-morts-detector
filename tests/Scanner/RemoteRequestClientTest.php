<?php

namespace Tests\Scanner;

use Brain\Monkey\Functions;
use JLG\BrokenLinks\Scanner\RemoteRequestClient;

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

    public function test_request_uses_custom_user_agent_header_case_insensitively(): void
    {
        $client = new RemoteRequestClient([], [], ['Custom Default']);

        $capturedArgs = null;
        Functions\when('wp_safe_remote_get')->alias(static function ($url, $args) use (&$capturedArgs) {
            $capturedArgs = $args;

            return ['response' => ['code' => 200]];
        });

        $client->get('https://example.com', [
            'headers' => ['user-agent' => 'My-Agent/1.0'],
        ]);

        $this->assertIsArray($capturedArgs);
        $this->assertArrayHasKey('headers', $capturedArgs);
        $this->assertSame('My-Agent/1.0', $capturedArgs['headers']['user-agent']);
        $this->assertSame('My-Agent/1.0', $capturedArgs['user-agent']);
    }

    public function test_request_replaces_empty_user_agent_with_pool_value(): void
    {
        $client = new RemoteRequestClient([], [], ['Pool-Agent/2.0']);

        $capturedArgs = null;
        Functions\when('wp_safe_remote_get')->alias(static function ($url, $args) use (&$capturedArgs) {
            $capturedArgs = $args;

            return ['response' => ['code' => 200]];
        });

        $client->get('https://example.com', [
            'headers' => ['USER-AGENT' => '   '],
        ]);

        $this->assertIsArray($capturedArgs);
        $this->assertArrayHasKey('headers', $capturedArgs);
        $this->assertSame('Pool-Agent/2.0', $capturedArgs['headers']['USER-AGENT']);
        $this->assertSame('Pool-Agent/2.0', $capturedArgs['user-agent']);
    }

    public function test_request_records_metrics_and_triggers_hook(): void
    {
        $metricsLog = [];
        $hookLog    = [];

        Functions\when('blc_record_remote_request_metrics')->alias(function ($metrics) use (&$metricsLog) {
            $metricsLog[] = $metrics;
        });

        Functions\when('do_action')->alias(function ($hook, ...$args) use (&$hookLog) {
            if ($hook === 'blc_remote_request_metrics') {
                $hookLog[] = $args;
            }
        });

        Functions\when('time')->justReturn(1700000000);

        $microtimeSequence = [1000.0, 1000.05, 1000.1, 1000.15, 1000.2, 1000.25, 1000.3, 1000.35];
        Functions\when('microtime')->alias(function ($as_float = false) use (&$microtimeSequence) {
            $value = array_shift($microtimeSequence);
            if ($value === null) {
                $value = 1000.4;
            }

            if ($as_float) {
                return $value;
            }

            $integer = (int) $value;
            $fraction = $value - $integer;

            return sprintf('%0.8f %d', $fraction, $integer);
        });

        Functions\when('usleep')->alias(static function ($milliseconds) {
            return true;
        });

        $responses = [
            ['response' => ['code' => 429], 'headers' => ['retry-after' => '3']],
            ['response' => ['code' => 200], 'headers' => []],
        ];

        Functions\when('wp_safe_remote_get')->alias(static function ($url, $args) use (&$responses) {
            return array_shift($responses);
        });

        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($response) {
            return isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        });

        Functions\when('wp_remote_retrieve_header')->alias(static function ($response, $header) {
            $header = strtolower((string) $header);
            if (isset($response['headers'][$header])) {
                return $response['headers'][$header];
            }

            return '';
        });

        $client = new RemoteRequestClient([], [
            'max_attempts'     => 2,
            'initial_delay_ms' => 10,
            'max_delay_ms'     => 20,
        ], []);

        $result = $client->get('https://example.com/resource', []);

        $this->assertSame(['response' => ['code' => 200], 'headers' => []], $result);
        $this->assertCount(2, $metricsLog);
        $this->assertCount(2, $hookLog);

        $first = $metricsLog[0];
        $this->assertSame('GET', $first['method']);
        $this->assertSame('https://example.com/resource', $first['url']);
        $this->assertSame('example.com', $first['host']);
        $this->assertSame('/resource', $first['path']);
        $this->assertSame(1, $first['attempt']);
        $this->assertSame(2, $first['max_attempts']);
        $this->assertSame(429, $first['response_code']);
        $this->assertFalse($first['success']);
        $this->assertTrue($first['will_retry']);
        $this->assertSame(3000, $first['retry_after_ms']);
        $this->assertSame(1700000000, $first['timestamp']);
        $this->assertSame(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0 Safari/537.36',
            $first['user_agent']
        );

        $second = $metricsLog[1];
        $this->assertSame(2, $second['attempt']);
        $this->assertSame(2, $second['max_attempts']);
        $this->assertSame(200, $second['response_code']);
        $this->assertTrue($second['success']);
        $this->assertFalse($second['will_retry']);
        $this->assertNull($second['retry_after_ms']);

        $this->assertSame($metricsLog[0], $hookLog[0][0]);
        $this->assertSame($metricsLog[1], $hookLog[1][0]);
    }
}
