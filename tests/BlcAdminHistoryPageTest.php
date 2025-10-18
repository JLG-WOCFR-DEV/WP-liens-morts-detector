<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';
    require_once __DIR__ . '/wp-option-stubs.php';

    if (!function_exists('sanitize_key')) {
        function sanitize_key($key)
        {
            $key = strtolower((string) $key);

            return preg_replace('/[^a-z0-9_\-]/', '', $key);
        }
    }
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

class BlcAdminHistoryPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        OptionsStore::reset();

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';

        Functions\when('admin_url')->alias(static function ($path = '') {
            return 'admin.php?page=' . ltrim((string) $path, '?');
        });

        Functions\when('number_format_i18n')->alias(static function ($number, $decimals = 0) {
            return number_format((float) $number, (int) $decimals, ',', ' ');
        });

        Functions\when('wp_date')->alias(static function ($format, $timestamp = null) {
            $timestamp = $timestamp ?? time();

            return gmdate($format, $timestamp);
        });

        Functions\when('blc_current_user_can_view_reports')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_history_page_renders_manual_reset_entry(): void
    {
        OptionsStore::$options['blc_link_scan_history'] = [
            [
                'event'        => 'reset',
                'dataset_type' => 'link',
                'timestamp'    => 1700000000,
                'user'         => [
                    'id'           => 42,
                    'login'        => 'editor',
                    'display_name' => 'Éditeur Test',
                ],
            ],
        ];

        OptionsStore::$options['blc_link_scan_metrics_history'] = [];

        ob_start();
        blc_scan_history_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Remise à zéro manuelle', $output);
        $this->assertStringContainsString('Déclenchée par Éditeur Test', $output);
        $this->assertStringContainsString('role="status"', $output);
    }
}

}
