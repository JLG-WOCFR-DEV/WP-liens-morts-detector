<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';

    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            /** @var string */
            private $code;

            /** @var string */
            private $message;

            /** @var mixed */
            private $data;

            public function __construct($code = '', $message = '', $data = null)
            {
                $this->code    = (string) $code;
                $this->message = (string) $message;
                $this->data    = $data;
            }

            public function get_error_code()
            {
                return $this->code;
            }

            public function get_error_message()
            {
                return $this->message;
            }

            public function get_error_data()
            {
                return $this->data;
            }
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing)
        {
            return $thing instanceof \WP_Error;
        }
    }

    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }

    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }

    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    if (!isset($GLOBALS['blc_report_test_link_status'])) {
        $GLOBALS['blc_report_test_link_status'] = [
            'state'      => 'idle',
            'ended_at'   => 0,
            'updated_at' => 0,
        ];
    }

    if (!function_exists('blc_get_link_scan_status')) {
        function blc_get_link_scan_status()
        {
            return is_array($GLOBALS['blc_report_test_link_status'])
                ? $GLOBALS['blc_report_test_link_status']
                : [
                    'state'      => 'idle',
                    'ended_at'   => 0,
                    'updated_at' => 0,
                ];
        }
    }
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BlcReportExportsTest extends TestCase
{
    /**
     * @var array<string,mixed>
     */
    private array $options = [];

    /**
     * @var array<int,array{timestamp:int, schedule:string, hook:string}>
     */
    private array $scheduledEvents = [];

    /**
     * @var array<int,string>
     */
    private array $clearedHooks = [];

    /**
     * @var array<int,string>
     */
    private array $errorLogs = [];

    /**
     * @var string
     */
    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        $this->options = [];
        $this->scheduledEvents = [];
        $this->clearedHooks = [];
        $this->errorLogs = [];
        $this->uploadsDir = sys_get_temp_dir() . '/blc-report-tests-' . uniqid();

        Functions\when('apply_filters')->alias(static fn($hook, $value) => $value);
        Functions\when('sanitize_key')->alias(static function ($value) {
            $value = strtolower(is_scalar($value) ? (string) $value : '');

            return preg_replace('/[^a-z0-9_\-]/', '', $value);
        });

        $test = $this;

        Functions\when('get_option')->alias(static function ($name, $default = false) use ($test) {
            return $test->options[$name] ?? $default;
        });

        Functions\when('update_option')->alias(static function ($name, $value) use ($test) {
            $test->options[$name] = $value;

            return true;
        });

        Functions\when('add_option')->alias(static function ($name, $value) use ($test) {
            if (!array_key_exists($name, $test->options)) {
                $test->options[$name] = $value;
            }

            return true;
        });

        Functions\when('wp_get_schedules')->alias(static function () {
            return [
                'hourly' => [
                    'interval' => HOUR_IN_SECONDS,
                    'display'  => 'Hourly',
                ],
                'daily' => [
                    'interval' => DAY_IN_SECONDS,
                    'display'  => 'Daily',
                ],
            ];
        });

        Functions\when('wp_schedule_event')->alias(function ($timestamp, $schedule, $hook) use ($test) {
            $test->scheduledEvents[] = [
                'timestamp' => (int) $timestamp,
                'schedule'  => (string) $schedule,
                'hook'      => (string) $hook,
            ];

            return true;
        });

        Functions\when('wp_next_scheduled')->alias(static fn($hook) => false);

        Functions\when('wp_clear_scheduled_hook')->alias(function ($hook) use ($test) {
            $test->clearedHooks[] = (string) $hook;

            return true;
        });

        Functions\when('error_log')->alias(function ($message) use ($test) {
            $test->errorLogs[] = (string) $message;

            return true;
        });

        Functions\when('wp_upload_dir')->alias(function () use ($test) {
            return [
                'basedir' => $test->uploadsDir,
                'baseurl' => 'https://example.com/uploads',
                'error'   => false,
            ];
        });

        Functions\when('wp_mkdir_p')->alias(static function ($dir) {
            if (is_dir($dir)) {
                return true;
            }

            return mkdir($dir, 0777, true);
        });

        Functions\when('time')->alias(static function () {
            return 1700000000;
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-cron.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-reports.php';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadsDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->uploadsDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile()) {
                    @unlink($fileInfo->getPathname());
                } else {
                    @rmdir($fileInfo->getPathname());
                }
            }

            @rmdir($this->uploadsDir);
        }

        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_sync_schedule_schedules_event_when_enabled(): void
    {
        $this->options['blc_report_export_enabled'] = true;
        Functions\when('wp_next_scheduled')->alias(static fn($hook) => false);

        \blc_sync_report_export_schedule();

        $this->assertNotEmpty($this->scheduledEvents, 'The report export event should be scheduled when enabled.');
        $this->assertSame('blc_generate_report_exports', $this->scheduledEvents[0]['hook']);
    }

    public function test_sync_schedule_clears_event_when_disabled(): void
    {
        $this->options['blc_report_export_enabled'] = false;
        Functions\when('wp_next_scheduled')->alias(static fn($hook) => 1234567890);

        \blc_sync_report_export_schedule();

        $this->assertContains('blc_generate_report_exports', $this->clearedHooks);
    }

    public function test_run_exports_creates_csv_for_completed_scan(): void
    {
        $this->options['blc_report_export_enabled'] = true;
        $this->options['blc_report_export_frequency'] = 'daily';
        $this->options['blc_report_export_status'] = [];

        $GLOBALS['blc_report_test_link_status'] = [
            'state'      => 'completed',
            'ended_at'   => 1700000000,
            'updated_at' => 1700000000,
        ];

        global $wpdb;
        $rows = [
            [
                'url' => 'https://example.com/broken',
                'http_status' => 404,
                'post_id' => 12,
                'post_title' => 'Broken Link',
                'type' => 'link',
                'occurrence_index' => 0,
                'is_internal' => 1,
                'ignored_at' => null,
                'last_checked_at' => '2024-01-01 10:00:00',
                'redirect_target_url' => '',
                'context_excerpt' => 'Sample excerpt',
            ],
        ];

        $wpdb = new class($rows) {
            public $prefix = 'wp_';

            /** @var array<int,array<string,mixed>> */
            private array $rows;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function prepare($query, $args = null)
            {
                if ($args === null) {
                    return $query;
                }

                if (!is_array($args)) {
                    $args = array_slice(func_get_args(), 1);
                }

                foreach ($args as $value) {
                    $query = preg_replace('/%s/', "'" . addslashes((string) $value) . "'", $query, 1);
                }

                return $query;
            }

            public function get_results($query, $output = ARRAY_A)
            {
                if ($output !== ARRAY_A) {
                    return [];
                }

                return $this->rows;
            }
        };

        \blc_run_automated_report_exports();

        $exportDir = $this->uploadsDir . '/blc-report-exports';
        $files = is_dir($exportDir) ? array_values(array_diff(scandir($exportDir) ?: [], ['.', '..'])) : [];

        $this->assertNotEmpty($files, 'The report export directory should contain a CSV file.');

        $status = $this->options['blc_report_export_status']['link'] ?? [];
        $this->assertNotEmpty($status, 'Export metadata should be stored.');
        $this->assertSame(1, $status['row_count']);
        $this->assertSame('blc-report-link-20231114-221320.csv', $status['relative_path']);
        $this->assertFalse($status['skipped']);
    }

    public function test_run_exports_skips_when_scan_not_completed(): void
    {
        $this->options['blc_report_export_enabled'] = true;
        $this->options['blc_report_export_status'] = [];

        $GLOBALS['blc_report_test_link_status'] = [
            'state'      => 'running',
            'ended_at'   => 0,
            'updated_at' => 1700000000,
        ];

        \blc_run_automated_report_exports();

        $status = $this->options['blc_report_export_status']['link'] ?? null;
        $this->assertNotNull($status, 'A skipped attempt should still be recorded.');
        $this->assertTrue($status['skipped']);
        $this->assertArrayNotHasKey('file_path', $status);
    }
}

}
