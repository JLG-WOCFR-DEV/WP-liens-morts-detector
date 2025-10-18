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

    if (!function_exists('wp_parse_url')) {
        function wp_parse_url($url, $component = -1)
        {
            return parse_url($url, $component);
        }
    }

    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags($string)
        {
            return trim(strip_tags((string) $string));
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing)
        {
            return $thing instanceof \WP_Error;
        }
    }

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
                $this->code = (string) $code;
                $this->message = (string) $message;
                $this->data = $data;
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
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

class BlcAutomatedReportTest extends TestCase
{
    /** @var mixed */
    private $previous_wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        OptionsStore::reset();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        Functions\when('add_action')->alias(static function ($hook, $callback, $priority = 10, $accepted_args = 1) {
            if (!isset($GLOBALS['blc_actions'])) {
                $GLOBALS['blc_actions'] = [];
            }

            $GLOBALS['blc_actions'][$hook][] = $callback;

            return true;
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-utils.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-reporting.php';

        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previous_wpdb !== null) {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }

        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_schedule_automated_report_generation_records_event(): void
    {
        Functions\when('time')->justReturn(1_700_000_000);
        Functions\when('apply_filters')->alias(static function ($tag, $value) {
            return $value;
        });
        Functions\when('wp_next_scheduled')->justReturn(false);

        $scheduled = [];
        Functions\when('wp_schedule_single_event')->alias(function ($timestamp, $hook, $args = []) use (&$scheduled) {
            $scheduled[] = [
                'timestamp' => $timestamp,
                'hook'      => $hook,
                'args'      => $args,
            ];

            return true;
        });

        $result = \blc_schedule_automated_report_generation('link', [
            'job_id'    => ' job-42 ',
            'started_at'=> 1_700_000_000,
            'ended_at'  => 1_700_000_600,
        ]);

        $this->assertTrue($result, 'Scheduling should succeed.');
        $this->assertCount(1, $scheduled, 'Exactly one event should be scheduled.');

        $event = $scheduled[0];
        $this->assertSame('blc_generate_automated_report', $event['hook']);
        $this->assertSame(1_700_000_000 + 30, $event['timestamp']);
        $this->assertIsArray($event['args']);
        $this->assertCount(2, $event['args']);
        $this->assertSame('link', $event['args'][0]);
        $context = $event['args'][1];
        $this->assertSame('job-42', $context['job_id']);
        $this->assertSame(1_700_000_000, $context['started_at']);
        $this->assertSame(1_700_000_600, $context['ended_at']);
        $this->assertSame('csv', $context['format']);
    }

    public function test_schedule_automated_report_generation_deduplicates_existing_events(): void
    {
        $times = [
            1_700_000_000,
            1_700_000_030,
            1_700_000_060,
            1_700_000_090,
            1_700_000_120,
        ];

        Functions\when('time')->alias(static function () use (&$times) {
            $value = array_shift($times);

            return $value !== null ? $value : 1_700_000_120;
        });

        Functions\when('apply_filters')->alias(static function ($tag, $value) {
            return $value;
        });

        $scheduled = [];

        Functions\when('wp_schedule_single_event')->alias(function ($timestamp, $hook, $args = []) use (&$scheduled) {
            $scheduled[] = [
                'timestamp' => $timestamp,
                'hook'      => $hook,
                'args'      => $args,
            ];

            return true;
        });

        Functions\when('wp_next_scheduled')->alias(static function ($hook, $args) use (&$scheduled) {
            foreach ($scheduled as $event) {
                if ($event['hook'] === $hook && $event['args'] === $args) {
                    return $event['timestamp'];
                }
            }

            return false;
        });

        $context = [
            'job_id'     => 'job-007',
            'started_at' => 1_699_999_400,
            'ended_at'   => 1_699_999_900,
        ];

        $first = \blc_schedule_automated_report_generation('link', $context);
        $second = \blc_schedule_automated_report_generation('link', $context);

        $this->assertTrue($first, 'Initial scheduling should succeed.');
        $this->assertTrue($second, 'Subsequent scheduling with the same context should also succeed.');
        $this->assertCount(1, $scheduled, 'Only one cron event should be registered for identical contexts.');

        $event = $scheduled[0];
        $this->assertSame('blc_generate_automated_report', $event['hook']);
        $this->assertSame(1_699_999_900, $event['args'][1]['completed_at'], 'Completed timestamp should reuse the ended_at value for stability.');
    }

    public function test_generate_automated_report_csv_creates_report_file(): void
    {
        $temp_dir = sys_get_temp_dir() . '/blc-report-test-' . uniqid('', true);
        $this->assertTrue(mkdir($temp_dir));

        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => $temp_dir,
            'baseurl' => 'https://example.com/wp-content/uploads',
            'error'   => '',
        ]);
        Functions\when('apply_filters')->alias(static function ($tag, $value) {
            return $value;
        });
        Functions\when('do_action')->alias(function () {
            // No-op for tests.
        });
        Functions\when('wp_mkdir_p')->alias(static function ($dir) {
            if (is_dir($dir)) {
                return true;
            }

            return mkdir($dir, 0755, true);
        });
        Functions\when('wp_delete_file')->alias(static function ($path) {
            if (is_file($path)) {
                unlink($path);
            }
        });

        $rows = [[
            'id'                 => 10,
            'url'                => 'https://example.com/broken',
            'anchor'             => '<strong>=SUM(A1)</strong>',
            'context_excerpt'    => '',
            'context_html'       => '<p>+Malicious context.</p>',
            'post_id'            => 123,
            'post_title'         => 'Sample article',
            'type'               => 'link',
            'occurrence_index'   => 0,
            'url_host'           => 'example.com',
            'is_internal'        => 1,
            'http_status'        => 404,
            'last_checked_at'    => '2024-06-15 12:00:00',
            'ignored_at'         => null,
            'redirect_target_url'=> 'https://example.com/new',
            'post_type'          => 'post',
        ]];

        $GLOBALS['wpdb'] = new class($rows) {
            public $prefix = 'wp_';
            public $posts = 'wp_posts';

            /** @var array<int, array<string, mixed>> */
            private $rows;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function prepare($query, $args = null)
            {
                return 'SQL';
            }

            public function get_results($query, $output = ARRAY_A)
            {
                return $this->rows;
            }
        };

        $context = [
            'job_id'     => 'export-99',
            'started_at' => 1_700_000_000,
            'ended_at'   => 1_700_000_300,
            'completed_at' => 1_700_000_360,
        ];

        $result = \blc_generate_automated_report_csv('link', $context);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('file_path', $result);
        $this->assertFileExists($result['file_path']);
        $this->assertSame(1, $result['row_count']);
        $this->assertSame('export-99', $result['context']['job_id']);

        $handle = fopen($result['file_path'], 'rb');
        $this->assertIsResource($handle);
        // Skip BOM
        $first_bytes = fread($handle, 3);
        $this->assertSame("\xEF\xBB\xBF", $first_bytes);
        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        $this->assertIsArray($headers);
        $row = fgetcsv($handle, 0, ',', '"', '\\');
        $this->assertIsArray($row);
        fclose($handle);

        $this->assertContains("'=SUM(A1)", $row, 'Anchor text should be protected against formula injection.');
        $this->assertContains("'+Malicious context.", $row, 'Context excerpt should be protected against formula injection.');

        $index = get_option('blc_automated_report_index', []);
        $this->assertArrayHasKey('link', $index);
        $this->assertNotEmpty($index['link']);
        $this->assertSame($result['file_path'], $index['link'][0]['file_path']);

        // Cleanup temporary directory.
        foreach (glob($temp_dir . '/blc-reports/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($temp_dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($temp_dir . '/blc-reports');
        @rmdir($temp_dir);
    }

    public function test_generate_automated_report_csv_returns_error_when_write_fails(): void
    {
        $temp_dir = sys_get_temp_dir() . '/blc-report-test-' . uniqid('', true);
        $this->assertTrue(mkdir($temp_dir));

        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => $temp_dir,
            'baseurl' => 'https://example.com/wp-content/uploads',
            'error'   => '',
        ]);
        Functions\when('apply_filters')->alias(static function ($tag, $value) {
            return $value;
        });
        Functions\when('do_action')->alias(function () {
            // No-op for tests.
        });
        Functions\when('wp_mkdir_p')->alias(static function ($dir) {
            if (is_dir($dir)) {
                return true;
            }

            return mkdir($dir, 0755, true);
        });
        $deleted_paths = [];
        Functions\when('wp_delete_file')->alias(static function ($path) use (&$deleted_paths) {
            $deleted_paths[] = $path;
            if (is_file($path)) {
                unlink($path);
            }
        });
        Functions\expect('blc_write_csv_row')->once()->andReturn(false);

        $rows = [[
            'id'                 => 10,
            'url'                => 'https://example.com/broken',
            'anchor'             => '<strong>Broken</strong>',
            'context_excerpt'    => '',
            'context_html'       => '<p>Broken link in content.</p>',
            'post_id'            => 123,
            'post_title'         => 'Sample article',
            'type'               => 'link',
            'occurrence_index'   => 0,
            'url_host'           => 'example.com',
            'is_internal'        => 1,
            'http_status'        => 404,
            'last_checked_at'    => '2024-06-15 12:00:00',
            'ignored_at'         => null,
            'redirect_target_url'=> 'https://example.com/new',
            'post_type'          => 'post',
        ]];

        $GLOBALS['wpdb'] = new class($rows) {
            public $prefix = 'wp_';
            public $posts = 'wp_posts';

            /** @var array<int, array<string, mixed>> */
            private $rows;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function prepare($query, $args = null)
            {
                return 'SQL';
            }

            public function get_results($query, $output = ARRAY_A)
            {
                return $this->rows;
            }
        };

        $result = \blc_generate_automated_report_csv('link', []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('blc_report_file_write_failed', $result->get_error_code());

        $index = get_option('blc_automated_report_index', []);
        $this->assertSame([], $index, 'No report reference should be stored on failure.');

        $files = glob($temp_dir . '/blc-reports/*');
        $existing_files = array_filter($files ?: [], 'is_file');
        $this->assertSame([], $existing_files, 'Partial files should be removed when writing fails.');
        $this->assertNotEmpty($deleted_paths, 'Failed writes should trigger a deletion attempt.');

        foreach (glob($temp_dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($temp_dir . '/blc-reports');
        @rmdir($temp_dir);
    }
}

}
