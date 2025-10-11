<?php

namespace Tests\Scanner;

use Brain\Monkey\Functions;
use JLG\BrokenLinks\Scanner\ProxyPool;

final class ProxyPoolTest extends ScannerTestCase
{
    public function test_acquire_rotates_between_highest_priority_proxies(): void
    {
        Functions\when('time')->justReturn(1_700_000_000);

        update_option('blc_proxy_pool_enabled', true);
        update_option('blc_proxy_pool_entries', [
            [
                'id'       => 'proxy-a',
                'url'      => 'http://proxy-a:8080',
                'regions'  => ['eu'],
                'priority' => 100,
                'headers'  => [],
            ],
            [
                'id'       => 'proxy-b',
                'url'      => 'http://proxy-b:8080',
                'regions'  => ['eu'],
                'priority' => 100,
                'headers'  => [],
            ],
            [
                'id'       => 'proxy-c',
                'url'      => 'http://proxy-c:8080',
                'regions'  => ['global'],
                'priority' => 50,
                'headers'  => [],
            ],
        ]);
        update_option('blc_proxy_pool_strategy', [
            'mappings'  => [],
            'fallbacks' => [
                'eu'      => ['eu', 'global'],
                'default' => ['global'],
            ],
        ]);
        update_option('blc_proxy_pool_health', []);

        $pool = blc_get_proxy_pool_instance(true);
        $this->assertInstanceOf(ProxyPool::class, $pool);

        $first = $pool->acquire(['host' => 'example.fr', 'region' => 'eu']);
        $second = $pool->acquire(['host' => 'example.fr', 'region' => 'eu']);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('proxy-a', $first['id']);
        $this->assertSame('proxy-b', $second['id']);

        $pool->reportOutcome('proxy-a', true, 1_700_000_000);
        $pool->reportOutcome('proxy-b', false, 1_700_000_000);

        $third = $pool->acquire(['host' => 'example.fr', 'region' => 'eu']);
        $this->assertNotNull($third);
        $this->assertSame('proxy-a', $third['id']);

        $health = get_option('blc_proxy_pool_health', []);
        $this->assertArrayHasKey('proxy-b', $health);
        $this->assertGreaterThan(0, $health['proxy-b']['failure_count']);
    }

    public function test_acquire_returns_null_when_all_proxies_suspended(): void
    {
        Functions\when('time')->justReturn(1_700_000_100);

        update_option('blc_proxy_pool_enabled', true);
        update_option('blc_proxy_pool_entries', [
            [
                'id'       => 'proxy-one',
                'url'      => 'http://proxy-one:8080',
                'regions'  => ['global'],
                'priority' => 100,
                'headers'  => [],
            ],
            [
                'id'       => 'proxy-two',
                'url'      => 'http://proxy-two:8080',
                'regions'  => ['global'],
                'priority' => 90,
                'headers'  => [],
            ],
        ]);
        update_option('blc_proxy_pool_strategy', [
            'mappings'  => [],
            'fallbacks' => [
                'default' => ['global'],
            ],
        ]);
        update_option('blc_proxy_pool_health', []);

        $pool = blc_get_proxy_pool_instance(true);
        $this->assertInstanceOf(ProxyPool::class, $pool);

        $selectionOne = $pool->acquire(['host' => 'example.com']);
        $this->assertNotNull($selectionOne);
        $pool->reportOutcome($selectionOne['id'], false, 1_700_000_100);

        $selectionTwo = $pool->acquire(['host' => 'example.com']);
        $this->assertNotNull($selectionTwo);
        $pool->reportOutcome($selectionTwo['id'], false, 1_700_000_101);

        $selectionThree = $pool->acquire(['host' => 'example.com']);
        $this->assertNull($selectionThree);
    }

    public function test_inject_proxy_arguments_adds_headers_and_curl_options(): void
    {
        $pool = new ProxyPool([
            'enabled'     => true,
            'proxies'     => [
                [
                    'id'       => 'proxy-secure',
                    'url'      => 'http://secure-proxy:8888',
                    'regions'  => ['global'],
                    'priority' => 100,
                    'headers'  => ['X-Proxy-Trace' => 'enabled'],
                ],
            ],
            'credentials' => ['proxy-secure' => 'user:pass'],
            'strategy'    => ['fallbacks' => ['default' => ['global']], 'mappings' => []],
            'health'      => [],
        ]);

        $args = $pool->injectProxyArguments(
            'https://example.com',
            [],
            [
                'id'          => 'proxy-secure',
                'url'         => 'http://secure-proxy:8888',
                'region'      => 'global',
                'priority'    => 100,
                'headers'     => ['X-Proxy-Trace' => 'enabled'],
                'credentials' => 'user:pass',
            ]
        );

        $this->assertArrayHasKey('headers', $args);
        $this->assertSame('Basic ' . base64_encode('user:pass'), $args['headers']['Proxy-Authorization']);
        $this->assertSame('enabled', $args['headers']['X-Proxy-Trace']);
        $this->assertArrayHasKey('curl', $args);
        $this->assertSame('http://secure-proxy:8888', $args['curl'][CURLOPT_PROXY]);
        $this->assertArrayHasKey('stream_context', $args);
        $this->assertSame('http://secure-proxy:8888', $args['stream_context']['http']['proxy']);
    }
}
