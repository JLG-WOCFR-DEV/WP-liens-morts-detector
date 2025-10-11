<?php

declare(strict_types=1);

namespace Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BlcPriorityActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('__')->alias(static fn($text) => $text);
        Functions\when('_n')->alias(static function ($singular, $plural, $number, $domain = null) {
            return ($number === 1) ? $singular : $plural;
        });
        Functions\when('number_format_i18n')->alias(static function ($number, $decimals = 0) {
            return number_format((float) $number, (int) $decimals);
        });
        Functions\when('add_query_arg')->alias(static function ($key, $value = null, $url = null) {
            if (is_array($key)) {
                $args = $key;
                $target_url = (string) $value;
            } else {
                $args = array((string) $key => $value);
                $target_url = (string) $url;
            }

            if ($target_url === '') {
                $target_url = 'admin.php?page=blc-dashboard';
            }

            $separator = (strpos($target_url, '?') === false) ? '?' : '&';

            return $target_url . $separator . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
        });
        Functions\when('apply_filters')->alias(static fn($hook, $value) => $value);

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        if (!function_exists('blc_build_priority_action_queue')) {
            require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_global_actions_are_prioritized_before_domains(): void
    {
        $top_domains = [
            [
                'host'          => 'example.com',
                'count'         => 12,
                'server_errors' => 3,
                'client_errors' => 6,
                'redirects'     => 2,
                'other'         => 1,
            ],
            [
                'host'          => 'cdn.example.com',
                'count'         => 8,
                'server_errors' => 1,
                'client_errors' => 5,
                'redirects'     => 1,
                'other'         => 1,
            ],
        ];

        $count_keys = [
            'active_count'        => 40,
            'server_error_count'  => 4,
            'not_found_count'     => 12,
            'redirect_count'      => 8,
            'needs_recheck_count' => 6,
        ];

        $actions = blc_build_priority_action_queue($top_domains, 'admin.php?page=blc-dashboard', $count_keys);

        $this->assertCount(5, $actions);
        $this->assertSame('Stabiliser les erreurs serveur', $actions[0]['title']);
        $this->assertSame('Résorber les erreurs 4xx récurrentes', $actions[1]['title']);
        $this->assertSame('Optimiser les redirections détectées', $actions[2]['title']);
        $this->assertSame('Relancer les liens à re-tester', $actions[3]['title']);
        $this->assertSame('Stabiliser example.com', $actions[4]['title']);

        $this->assertStringContainsString('Impact', $actions[0]['meta']);
        $this->assertStringContainsString('Focus : example.com', $actions[0]['meta']);
        $this->assertSame('admin.php?page=blc-dashboard&link_type=status_5xx', $actions[0]['cta_url']);

        $parsed = [];
        parse_str((string) parse_url($actions[4]['cta_url'], PHP_URL_QUERY), $parsed);
        $this->assertSame('status_5xx', $parsed['link_type'] ?? '');
        $this->assertSame('example.com', $parsed['s'] ?? '');
    }

    public function test_domain_actions_still_return_when_no_global_threshold_met(): void
    {
        $top_domains = [
            [
                'host'          => 'low.example',
                'count'         => 3,
                'server_errors' => 0,
                'client_errors' => 2,
                'redirects'     => 1,
                'other'         => 0,
            ],
        ];

        $count_keys = [
            'active_count'        => 100,
            'server_error_count'  => 0,
            'not_found_count'     => 4,
            'redirect_count'      => 1,
            'needs_recheck_count' => 0,
        ];

        $actions = blc_build_priority_action_queue($top_domains, 'admin.php?page=blc-dashboard', $count_keys);

        $this->assertCount(1, $actions);
        $this->assertSame('Stabiliser low.example', $actions[0]['title']);
        $parsed = [];
        parse_str((string) parse_url($actions[0]['cta_url'], PHP_URL_QUERY), $parsed);
        $this->assertSame('low.example', $parsed['s'] ?? '');
    }

    public function test_returns_empty_array_when_no_counts_or_domains(): void
    {
        $actions = blc_build_priority_action_queue([], 'admin.php?page=blc-dashboard', [
            'active_count'        => 0,
            'server_error_count'  => 0,
            'not_found_count'     => 0,
            'redirect_count'      => 0,
            'needs_recheck_count' => 0,
        ]);

        $this->assertSame([], $actions);
    }
}
