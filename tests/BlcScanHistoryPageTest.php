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

        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
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

    public function test_calculate_link_scan_metrics_trends_computes_moving_average_and_week_delta(): void
    {
        $latest_timestamp = 1_700_000_000;
        $metrics_history = [
            [
                'timestamp'       => $latest_timestamp,
                'duration_ms'     => 420000,
                'processed_items' => 210,
            ],
            [
                'timestamp'       => $latest_timestamp - (8 * DAY_IN_SECONDS),
                'duration_ms'     => 360000,
                'processed_items' => 180,
            ],
            [
                'timestamp'       => $latest_timestamp - (9 * DAY_IN_SECONDS),
                'duration_ms'     => 390000,
                'processed_items' => 200,
            ],
        ];

        $trends = blc_calculate_link_scan_metrics_trends($metrics_history, array(
            'window'           => 2,
            'sparkline_length' => 3,
        ));

        $this->assertArrayHasKey('duration_ms', $trends);
        $this->assertArrayHasKey('throughput', $trends);

        $duration_trend = $trends['duration_ms'];
        $this->assertSame(420000.0, $duration_trend['latest']);
        $this->assertEqualsWithDelta(390000.0, $duration_trend['moving_average'], 0.1);
        $this->assertSame(60000.0, $duration_trend['change_7d']);
        $this->assertCount(3, $duration_trend['sparkline']);
        $this->assertEqualsWithDelta(
            $duration_trend['latest'],
            $duration_trend['sparkline'][count($duration_trend['sparkline']) - 1],
            0.1
        );

        $throughput_trend = $trends['throughput'];
        $this->assertGreaterThan(0, $throughput_trend['latest']);
        $this->assertCount(3, $throughput_trend['sparkline']);
        $this->assertEqualsWithDelta(
            $throughput_trend['latest'],
            $throughput_trend['sparkline'][count($throughput_trend['sparkline']) - 1],
            0.1
        );
        $this->assertSame($throughput_trend['window'], 2);
    }

    public function test_render_sparkline_returns_svg_markup(): void
    {
        $values = [10, 20, 15, 30];

        $svg = blc_render_sparkline($values, 100, 20);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('polyline', $svg);
        $this->assertStringContainsString('100', $svg);
        $this->assertStringContainsString('20', $svg);
        $this->assertMatchesRegularExpression('/points="[0-9.,\s]+"/', $svg);
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
