<?php

namespace {
    if (!function_exists('sanitize_key')) {
        function sanitize_key($key)
        {
            $key = strtolower((string) $key);

            return preg_replace('/[^a-z0-9_\-]/', '', $key);
        }
    }

    if (!function_exists('add_action')) {
        function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
        {
            return true;
        }
    }

    if (!function_exists('do_action')) {
        function do_action($hook, ...$args)
        {
            return null;
        }
    }
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

class BlcScanHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        require_once __DIR__ . '/wp-option-stubs.php';
        OptionsStore::reset();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-stats.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scan-history.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';

        Functions\when('apply_filters')->alias(static fn($hook, $value, ...$args) => $value);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        OptionsStore::reset();
        parent::tearDown();
    }

    public function test_add_scan_history_entry_applies_retention(): void
    {
        Functions\when('apply_filters')->alias(static function ($hook, $value, ...$args) {
            if ($hook === 'blc_scan_history_max_entries') {
                return 3;
            }

            return $value;
        });

        for ($i = 1; $i <= 4; $i++) {
            blc_add_scan_history_entry('link', ['broken' => $i], 1000 + $i);
        }

        $history = get_option('blc_scan_history', []);
        $this->assertArrayHasKey('link', $history);
        $this->assertCount(3, $history['link']);

        $timestamps = array_column($history['link'], 'timestamp');
        $this->assertSame([1002, 1003, 1004], $timestamps);
    }

    public function test_update_link_scan_status_records_history_snapshot(): void
    {
        Functions\when('blc_get_link_status_counts')->justReturn([
            'active_count'        => 7,
            'not_found_count'     => 3,
            'server_error_count'  => 2,
            'redirect_count'      => 1,
            'needs_recheck_count' => 5,
        ]);

        blc_update_link_scan_status(['state' => 'completed']);

        $history = get_option('blc_scan_history', []);
        $this->assertArrayHasKey('link', $history);
        $this->assertNotEmpty($history['link']);

        $entry = end($history['link']);
        $this->assertSame(7, $entry['totals']['broken']);
        $this->assertSame(3, $entry['totals']['not_found']);
        $this->assertSame(2, $entry['totals']['server_error']);
        $this->assertSame(1, $entry['totals']['redirect']);
        $this->assertSame(5, $entry['totals']['needs_recheck']);
    }

    public function test_update_image_scan_status_records_history_snapshot(): void
    {
        Functions\when('blc_get_image_status_counts')->justReturn([
            'broken_count'       => 9,
            'not_found_count'    => 4,
            'server_error_count' => 1,
            'redirect_count'     => 2,
        ]);

        blc_update_image_scan_status(['state' => 'completed']);

        $history = get_option('blc_scan_history', []);
        $this->assertArrayHasKey('image', $history);
        $this->assertNotEmpty($history['image']);

        $entry = end($history['image']);
        $this->assertSame(9, $entry['totals']['broken']);
        $this->assertSame(4, $entry['totals']['not_found']);
        $this->assertSame(1, $entry['totals']['server_error']);
        $this->assertSame(2, $entry['totals']['redirect']);
    }
}
}
