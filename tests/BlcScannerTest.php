<?php

namespace {
    if (!class_exists('WP_Query')) {
        class WP_Query
        {
            public $posts = [];
            public $max_num_pages = 0;
            public $args = [];

            public function __construct($args = [])
            {
                $this->args = $args;
                $scenario = [];
                if (!empty($GLOBALS['wp_query_queue'])) {
                    $scenario = array_shift($GLOBALS['wp_query_queue']);
                }

                $this->posts = $scenario['posts'] ?? [];
                $this->max_num_pages = $scenario['max_num_pages'] ?? 0;

                if (!isset($GLOBALS['wp_query_last_args'])) {
                    $GLOBALS['wp_query_last_args'] = [];
                }
                $GLOBALS['wp_query_last_args'][] = $args;
            }
        }
    }
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BlcScannerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options;

    /** @var array<int, array{method: string, url: string}> */
    private array $httpRequests = [];

    /** @var array<string, array<string, mixed>> */
    private array $httpResponses = [];

    /** @var array<int, array{timestamp: int, hook: string, args: array, unique: bool}> */
    private array $scheduledEvents = [];

    /** @var array<string, mixed> */
    private array $updatedOptions = [];

    /** @var array<int, float> */
    private array $serverLoad = [0.5, 0.4, 0.3];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        $this->options = [
            'blc_debug_mode'       => false,
            'blc_rest_start_hour'  => '00',
            'blc_rest_end_hour'    => '00',
            'blc_link_delay'       => 0,
            'blc_batch_delay'      => 60,
            'blc_scan_method'      => 'precise',
            'blc_excluded_domains' => '',
            'blc_last_check_time'  => 0,
        ];

        $this->httpRequests = [];
        $this->httpResponses = [];
        $this->scheduledEvents = [];
        $this->updatedOptions = [];
        $this->serverLoad = [0.5, 0.4, 0.3];
        $GLOBALS['wp_query_queue'] = [];
        $GLOBALS['wp_query_last_args'] = [];

        Functions\when('get_option')->alias(fn(string $name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('home_url')->justReturn('http://example.com');
        Functions\when('current_time')->alias(function (string $type) {
            if ($type === 'timestamp') {
                return 1000;
            }

            if ($type === 'mysql') {
                return '1970-01-01 00:00:00';
            }

            return '00';
        });
        Functions\when('trailingslashit')->alias(function ($value) {
            return rtrim((string) $value, "\\/\t\n\r\f ") . '/';
        });
        Functions\when('wp_upload_dir')->alias(function () {
            return [
                'baseurl' => 'http://example.com/wp-content/uploads',
                'basedir' => sys_get_temp_dir() . '/uploads-test',
            ];
        });
        Functions\when('wp_list_pluck')->alias(function (array $list, string $field) {
            $values = [];
            foreach ($list as $item) {
                if (is_object($item) && isset($item->$field)) {
                    $values[] = $item->$field;
                } elseif (is_array($item) && isset($item[$field])) {
                    $values[] = $item[$field];
                }
            }
            return $values;
        });
        Functions\when('wp_kses_decode_entities')->alias(fn($value) => $value);
        Functions\when('wp_strip_all_tags')->alias(fn(string $text) => strip_tags($text));
        Functions\when('wp_remote_get')->alias(function (string $url, array $args = []) {
            return $this->mockHttpRequest('GET', $url);
        });
        Functions\when('wp_remote_head')->alias(function (string $url, array $args = []) {
            return $this->mockHttpRequest('HEAD', $url);
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(function ($response) {
            return $response['response']['code'] ?? 0;
        });
        Functions\when('is_wp_error')->alias(function ($thing): bool {
            return $thing instanceof \WP_Error;
        });
        Functions\when('wp_normalize_path')->alias(function ($path) {
            return str_replace('\\', '/', (string) $path);
        });
        Functions\when('get_bloginfo')->alias(function (string $show) {
            if ($show === 'charset') {
                return 'UTF-8';
            }

            return '';
        });
        Functions\when('update_option')->alias(function (string $option, $value) {
            $this->updatedOptions[$option] = $value;
            return true;
        });
        Functions\when('wp_schedule_single_event')->alias(function (int $timestamp, string $hook, array $args = [], bool $unique = true) {
            $this->scheduledEvents[] = [
                'timestamp' => $timestamp,
                'hook'      => $hook,
                'args'      => $args,
                'unique'    => $unique,
            ];
            return true;
        });
        Functions\when('sys_getloadavg')->alias(fn() => $this->serverLoad);
        Functions\when('time')->justReturn(1000);
        Functions\when('wp_unslash')->alias(fn($value) => $value);
        Functions\when('wp_slash')->alias(fn($value) => $value);

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
        unset($GLOBALS['wp_query_queue'], $GLOBALS['wp_query_last_args']);
        global $wpdb;
        $wpdb = null;
    }

    private function mockHttpRequest(string $method, string $url): array
    {
        $this->httpRequests[] = ['method' => $method, 'url' => $url];
        $key = $method . ' ' . $url;

        return $this->httpResponses[$key] ?? ['response' => ['code' => 200]];
    }

    private function setHttpResponse(string $method, string $url, array $response): void
    {
        $key = $method . ' ' . $url;
        $this->httpResponses[$key] = $response;
    }

    private function createWpdbStub(): object
    {
        return new class {
            public string $prefix = 'wp_';
            /** @var array<int, array<string, mixed>> */
            public array $queries = [];
            /** @var array<int, array<string, mixed>> */
            public array $inserted = [];

            public function query(string $sql)
            {
                $this->queries[] = ['sql' => $sql];
                return true;
            }

            public function insert(string $table, array $data, array $formats)
            {
                $this->inserted[] = ['table' => $table, 'data' => $data, 'formats' => $formats];
                return true;
            }

            public function prepare(string $query, $args = null): string
            {
                if ($args === null) {
                    return $query;
                }

                if (!is_array($args)) {
                    $args = array_slice(func_get_args(), 1);
                }

                $escaped = array_map(function ($value) {
                    if (is_string($value)) {
                        return addslashes($value);
                    }

                    if (is_bool($value)) {
                        return $value ? 1 : 0;
                    }

                    return $value;
                }, $args);

                $query = str_replace('%s', "'%s'", $query);
                $query = str_replace('%d', '%d', $query);
                $query = str_replace('%f', '%F', $query);

                return vsprintf($query, $escaped);
            }
        };
    }

    public function test_blc_perform_check_skips_excluded_domains_and_records_failed_links(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_excluded_domains'] = "excluded.com\nignored.net";
        $this->options['blc_scan_method'] = 'precise';

        $post = (object) [
            'ID' => 42,
            'post_title' => 'Sample Post',
            'post_content' => '<a href="http://excluded.com/page">Excluded</a> <a href="http://allowed.com/bad">Allowed</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('GET', 'http://allowed.com/bad', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertCount(1, $this->httpRequests, 'Only the non-excluded URL should be requested.');
        $this->assertSame('http://allowed.com/bad', $this->httpRequests[0]['url']);

        $this->assertCount(1, $wpdb->inserted, 'Only one broken link should be inserted.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('wp_blc_broken_links', $insert['table']);
        $this->assertSame('http://allowed.com/bad', $insert['data']['url']);
        $this->assertSame('link', $insert['data']['type']);
        $this->assertSame([], $this->scheduledEvents, 'No follow-up batch should be scheduled.');
        $this->assertSame(1000, $this->updatedOptions['blc_last_check_time'] ?? null, 'Last check time should be updated.');
    }

    public function test_blc_perform_check_reschedules_when_server_load_is_high(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->serverLoad = [3.5, 2.0, 1.0];
        blc_perform_check(0, false);

        $this->assertSame([], $GLOBALS['wp_query_last_args'], 'WP_Query should not be instantiated when load is high.');
        $this->assertCount(1, $this->scheduledEvents, 'A retry should be scheduled when load is high.');
        $event = $this->scheduledEvents[0];
        $this->assertSame(1300, $event['timestamp']);
        $this->assertSame('blc_check_batch', $event['hook']);
        $this->assertSame([0, false], $event['args']);
        $this->assertSame([], $this->updatedOptions, 'No options should be updated when scan is postponed.');
        $this->assertCount(0, $wpdb->inserted, 'No database insertions should occur when load is high.');
    }

    public function test_blc_perform_check_skips_malformed_urls_without_warnings(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 100,
            'post_title' => 'Malformed URL Post',
            'post_content' => '<a href="http://:foo">Broken Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $errorHandler = function (int $severity, string $message): bool {
            if ($severity & (E_WARNING | E_NOTICE)) {
                throw new \ErrorException($message, 0, $severity);
            }

            return false;
        };

        set_error_handler($errorHandler);

        try {
            blc_perform_check(0, false);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(0, $this->httpRequests, 'Malformed URLs should not trigger HTTP requests.');
        $this->assertCount(0, $wpdb->inserted, 'Malformed URLs should be ignored.');
    }

    public function test_blc_perform_check_batches_and_reschedules_next_batch(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 7,
            'post_title' => 'Batch Post',
            'post_content' => '<a href="http://ok.com/resource">Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 3,
        ];

        blc_perform_check(0, true);

        $this->assertCount(1, $GLOBALS['wp_query_last_args'], 'WP_Query should be executed once.');
        $args = $GLOBALS['wp_query_last_args'][0];
        $this->assertSame(1, $args['paged']);
        $this->assertSame(20, $args['posts_per_page']);

        $this->assertCount(1, $wpdb->queries, 'Existing entries for the batch should be cleared.');
        $this->assertCount(0, $wpdb->inserted, 'No broken links should be recorded for healthy responses.');
        $this->assertCount(1, $this->scheduledEvents, 'Next batch should be scheduled.');
        $event = $this->scheduledEvents[0];
        $this->assertSame(1060, $event['timestamp']);
        $this->assertSame('blc_check_batch', $event['hook']);
        $this->assertSame([1, true], $event['args']);
        $this->assertSame([], $this->updatedOptions, 'Last check time should not be updated before the final batch.');
    }

    public function test_blc_perform_check_normalizes_negative_delays(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_link_delay'] = -150;
        $this->options['blc_batch_delay'] = -45;

        $post = (object) [
            'ID' => 9,
            'post_title' => 'Negative Delay Post',
            'post_content' => '<a href="http://ok.com/resource">Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 2,
        ];

        $errorHandler = function (int $severity, string $message): bool {
            if ($severity & (E_WARNING | E_NOTICE)) {
                throw new \ErrorException($message, 0, $severity);
            }

            return false;
        };

        set_error_handler($errorHandler);

        try {
            blc_perform_check(0, false);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $this->scheduledEvents, 'Next batch should still be scheduled.');
        $event = $this->scheduledEvents[0];
        $this->assertSame(1000, $event['timestamp'], 'Negative delays should be coerced to zero before scheduling.');
        $this->assertSame('blc_check_batch', $event['hook']);
        $this->assertSame([1, false], $event['args']);
    }

    public function test_blc_perform_image_check_cleans_table_and_reschedules(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 88,
            'post_title' => 'Images Post',
            'post_content' => '<img src="http://example.com/wp-content/uploads/2024/05/missing.jpg" />' .
                '<img src="http://cdn.example.com/image.jpg" />',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 2,
        ];

        blc_perform_image_check(0, true);

        $this->assertCount(1, $wpdb->queries, 'Image results table should be cleared at first batch.');
        $this->assertCount(1, $wpdb->inserted, 'Missing local image should be recorded.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('image', $insert['data']['type']);
        $this->assertSame('missing.jpg', $insert['data']['anchor']);
        $this->assertSame(88, $insert['data']['post_id']);
        $this->assertCount(1, $this->scheduledEvents, 'Image batches should schedule follow-ups.');
        $event = $this->scheduledEvents[0];
        $this->assertSame(1060, $event['timestamp']);
        $this->assertSame('blc_check_image_batch', $event['hook']);
        $this->assertSame([1, true], $event['args']);
        $this->assertSame([], $this->updatedOptions, 'Image scan should not update options.');
    }
}
}
