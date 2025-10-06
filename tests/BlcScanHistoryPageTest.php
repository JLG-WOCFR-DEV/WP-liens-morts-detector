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

class BlcScanHistoryPageTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_calculate_link_scan_history_insights_returns_expected_metrics(): void
    {
        $history = [
            [
                'job_id'          => 'blc_123',
                'state'           => 'completed',
                'scheduled_at'    => 1700000000,
                'started_at'      => 1700000300,
                'ended_at'        => 1700000600,
                'processed_items' => 120,
                'total_items'     => 120,
                'metrics'         => [
                    'duration_ms'      => 300000,
                    'processed_items'  => 120,
                    'total_items'      => 120,
                    'processed_batches'=> 4,
                    'total_batches'    => 4,
                ],
            ],
            [
                'job_id'       => 'blc_456',
                'state'        => 'failed',
                'scheduled_at' => 1700000800,
                'attempt'      => 2,
            ],
        ];

        $insights = blc_calculate_link_scan_history_insights($history);

        $this->assertSame(2, $insights['total_runs']);
        $this->assertSame(1, $insights['completed_runs']);
        $this->assertSame(1, $insights['failed_runs']);
        $this->assertEqualsWithDelta(300000.0, $insights['average_duration_ms'], 0.1);
        $this->assertEqualsWithDelta(24.0, $insights['average_throughput'], 0.1);
        $this->assertSame('blc_123', $insights['last_job_summary']['job_id']);
        $this->assertSame('completed', $insights['last_job_summary']['state']);
    }

    public function test_history_page_renders_tables_and_summary(): void
    {
        OptionsStore::$options['blc_link_scan_history'] = [
            [
                'job_id'             => 'blc_123',
                'state'              => 'completed',
                'scheduled_at'       => 1700000000,
                'started_at'         => 1700000300,
                'ended_at'           => 1700000600,
                'processed_items'    => 120,
                'total_items'        => 120,
                'processed_batches'  => 4,
                'total_batches'      => 4,
                'is_full_scan'       => true,
                'attempt'            => 1,
                'message'            => 'Scan terminé avec succès.',
                'metrics'            => [
                    'duration_ms'      => 300000,
                    'processed_items'  => 120,
                    'total_items'      => 120,
                    'processed_batches'=> 4,
                    'total_batches'    => 4,
                    'timestamp'        => 1700000600,
                    'success'          => true,
                    'state'            => 'completed',
                    'is_full_scan'     => true,
                    'attempt'          => 1,
                ],
            ],
        ];

        OptionsStore::$options['blc_link_scan_metrics_history'] = [
            [
                'job_id'            => 'blc_123',
                'batch'             => 0,
                'duration_ms'       => 120000,
                'timestamp'         => 1700000400,
                'processed_items'   => 60,
                'total_items'       => 120,
                'processed_batches' => 2,
                'total_batches'     => 4,
                'success'           => true,
                'state'             => 'running',
                'is_full_scan'      => true,
                'attempt'           => 1,
            ],
        ];

        ob_start();
        blc_scan_history_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Historique des Analyses', $output);
        $this->assertStringContainsString('Analyses totales', $output);
        $this->assertStringContainsString('blc_123', $output);
        $this->assertStringContainsString('Scan terminé avec succès.', $output);
        $this->assertStringContainsString('Succès', $output);
        $this->assertStringContainsString('Analyse en cours', $output);
    }
}

}
