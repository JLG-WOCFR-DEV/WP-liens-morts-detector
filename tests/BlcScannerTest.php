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

    private string $currentHour = '00';

    private int $utcNow = 1000;

    /** @var array<int, array<string, mixed>> */
    private array $updatedPosts = [];

    /** @var array{success: bool, data: mixed}|null */
    private ?array $ajaxResponse = null;

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
        $this->currentHour = '00';
        $this->updatedPosts = [];
        $this->ajaxResponse = null;
        $this->utcNow = 1000;
        $GLOBALS['wp_query_queue'] = [];
        $GLOBALS['wp_query_last_args'] = [];

        Functions\when('get_option')->alias(fn(string $name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('apply_filters')->alias(fn(string $hook, $value, ...$args) => $value);
        Functions\when('home_url')->alias(function ($path = '', $scheme = null) {
            $base = 'https://example.com';
            if ($path !== '') {
                return rtrim($base, '/') . '/' . ltrim($path, '/');
            }

            return $base;
        });
        Functions\when('set_url_scheme')->alias(function ($url, $scheme = null) {
            $scheme = $scheme ?: 'http';

            if (strpos($url, '//') === 0) {
                return $scheme . ':' . $url;
            }

            return preg_replace('#^[a-z0-9+.-]+://#i', $scheme . '://', $url);
        });
        $testCase = $this;
        Functions\when('current_time')->alias(function (string $type, $gmt = 0) use ($testCase) {
            if ($type === 'timestamp') {
                return $testCase->utcNow;
            }

            if ($type === 'mysql') {
                return '1970-01-01 00:00:00';
            }

            return $testCase->currentHour;
        });
        Functions\when('trailingslashit')->alias(function ($value) {
            return rtrim((string) $value, "\\/\t\n\r\f ") . '/';
        });
        Functions\when('wp_timezone')->alias(fn() => new \DateTimeZone('UTC'));
        Functions\when('wp_timezone_string')->alias(fn() => 'UTC');
        Functions\when('plugin_dir_path')->alias(fn($file) => rtrim(dirname((string) $file), '/\\') . '/');
        Functions\when('register_activation_hook')->alias(function () { return null; });
        Functions\when('register_deactivation_hook')->alias(function () { return null; });
        Functions\when('set_url_scheme')->alias(function ($url, $scheme = null) {
            $scheme = $scheme ?? 'http';
            if (strpos($url, '//') === 0) {
                $url = $scheme . ':' . $url;
            }

            if (!preg_match('#^https?://#i', $url)) {
                return $url;
            }

            return preg_replace('#^https?://#i', $scheme . '://', $url);
        });
        Functions\when('wp_upload_dir')->alias(function () {
            return [
                'baseurl' => 'https://example.com/wp-content/uploads',
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
        Functions\when('wp_safe_remote_get')->alias(function (string $url, array $args = []) {
            return $this->mockHttpRequest('GET', $url);
        });
        Functions\when('wp_safe_remote_head')->alias(function (string $url, array $args = []) {
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
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post')->alias(fn(int $post_id) => null);
        Functions\when('wp_http_validate_url')->alias(function ($url) {
            if (!is_string($url)) {
                return false;
            }

            $url = trim($url);
            if ($url === '') {
                return false;
            }

            if (preg_match('#^https?://#i', $url) === 1) {
                return $url;
            }

            return false;
        });
        Functions\when('esc_url_raw')->alias(fn($url) => is_string($url) ? $url : '');
        Functions\when('update_option')->alias(function (string $option, $value) {
            $this->updatedOptions[$option] = $value;
            return true;
        });
        Functions\when('wp_update_post')->alias(function (array $data, $wp_error = false) {
            $this->updatedPosts[] = ['data' => $data, 'wp_error' => $wp_error];
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
        Functions\when('time')->alias(fn() => $this->utcNow);
        Functions\when('wp_unslash')->alias(fn($value) => $value);
        Functions\when('wp_slash')->alias(fn($value) => $value);
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->ajaxResponse = ['success' => true, 'data' => $data];
            throw new \RuntimeException('wp_send_json_success');
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            $this->ajaxResponse = ['success' => false, 'data' => $data];
            throw new \RuntimeException('wp_send_json_error');
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/liens-morts-detector-jlg.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
        unset($GLOBALS['wp_query_queue'], $GLOBALS['wp_query_last_args']);
        unset($_POST);
        $this->ajaxResponse = null;
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
            /** @var array<int, array<string, mixed>> */
            public array $deleted = [];

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

            public function delete(string $table, array $where, array $formats)
            {
                $this->deleted[] = ['table' => $table, 'where' => $where, 'formats' => $formats];
                return 1;
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

    public function test_blc_perform_check_delays_during_rest_period(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_rest_start_hour'] = '08:15';
        $this->options['blc_rest_end_hour']   = '20:45';
        $this->currentHour                    = '09';

        blc_perform_check(0, false);

        $this->assertSame([], $GLOBALS['wp_query_last_args'], 'No query should run during the configured rest window.');
        $this->assertSame([], $wpdb->queries, 'Database queries must not be executed during rest hours.');
    }

    public function test_blc_perform_check_schedules_next_event_after_rest_period(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_rest_start_hour'] = '08';
        $this->options['blc_rest_end_hour']   = '20';
        $this->currentHour                    = '09';
        $this->utcNow                         = 9 * 3600;

        blc_perform_check(3, false);

        $this->assertCount(1, $this->scheduledEvents, 'A rescheduled scan should be queued when inside the rest window.');
        $event = $this->scheduledEvents[0];

        $this->assertSame('blc_check_batch', $event['hook']);
        $this->assertSame([3, false], $event['args']);
        $this->assertGreaterThan($this->utcNow, $event['timestamp']);
        $this->assertSame(20 * 3600, $event['timestamp']);
    }

    public function test_blc_perform_check_runs_outside_rest_period(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_rest_start_hour'] = '08';
        $this->options['blc_rest_end_hour']   = '20';
        $this->currentHour                    = '07';

        $GLOBALS['wp_query_queue'][] = [
            'posts'        => [],
            'max_num_pages' => 0,
        ];

        blc_perform_check(0, false);

        $this->assertNotSame([], $GLOBALS['wp_query_last_args'], 'A scan should start normally outside the rest window.');
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
        $this->assertSame($this->utcNow, $this->updatedOptions['blc_last_check_time'] ?? null, 'Last check time should be updated.');
    }

    public function test_blc_perform_check_skips_excluded_domains_case_insensitively(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_excluded_domains'] = "EXCLUDED.COM\nIGNORED.NET";
        $this->options['blc_scan_method'] = 'precise';

        $post = (object) [
            'ID' => 84,
            'post_title' => 'Case Sensitivity Post',
            'post_content' => '<a href="http://Excluded.com/page">Excluded</a> <a href="http://ALLOWED.com/bad">Allowed</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('GET', 'http://ALLOWED.com/bad', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertCount(1, $this->httpRequests, 'Only the non-excluded URL should be requested when exclusions differ by case.');
        $this->assertSame('http://ALLOWED.com/bad', $this->httpRequests[0]['url']);

        $this->assertCount(1, $wpdb->inserted, 'Only one broken link should be inserted when exclusions differ by case.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('wp_blc_broken_links', $insert['table']);
        $this->assertSame('http://ALLOWED.com/bad', $insert['data']['url']);
        $this->assertSame('link', $insert['data']['type']);
        $this->assertSame([], $this->scheduledEvents, 'No follow-up batch should be scheduled.');
        $this->assertSame($this->utcNow, $this->updatedOptions['blc_last_check_time'] ?? null, 'Last check time should be updated.');
    }

    public function test_blc_perform_check_excludes_subdomains_but_not_similar_domains(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_excluded_domains'] = "example.com";
        $this->options['blc_scan_method'] = 'precise';

        $post = (object) [
            'ID' => 21,
            'post_title' => 'Subdomain Exclusion Post',
            'post_content' => implode(' ', [
                '<a href="http://example.com/page">Root Domain</a>',
                '<a href="http://sub.example.com/page">Subdomain</a>',
                '<a href="http://notexample.com/bad">Similar But Allowed</a>',
            ]),
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('GET', 'http://notexample.com/bad', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertCount(1, $this->httpRequests, 'Only the non-excluded host should trigger an HTTP request.');
        $this->assertSame('http://notexample.com/bad', $this->httpRequests[0]['url']);

        $this->assertCount(1, $wpdb->inserted, 'Only the non-excluded host should be recorded as broken.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('http://notexample.com/bad', $insert['data']['url']);
        $this->assertSame('link', $insert['data']['type']);
        $this->assertSame([], $this->scheduledEvents, 'The scan should complete without scheduling another batch.');
        $this->assertSame($this->utcNow, $this->updatedOptions['blc_last_check_time'] ?? null, 'Last check time should be updated at the end of the scan.');
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
        $this->assertSame($this->utcNow + 300, $event['timestamp']);
        $this->assertSame('blc_check_batch', $event['hook']);
        $this->assertSame([0, false], $event['args']);
        $this->assertSame([], $this->updatedOptions, 'No options should be updated when scan is postponed.');
        $this->assertCount(0, $wpdb->inserted, 'No database insertions should occur when load is high.');
    }

    public function test_blc_perform_check_honors_max_load_threshold_filter(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->serverLoad = [3.5, 2.0, 1.0];
        Functions\when('apply_filters')->alias(function (string $hook, $value, ...$args) {
            if ($hook === 'blc_max_load_threshold') {
                return 4.0;
            }

            return $value;
        });

        blc_perform_check(0, false);

        $this->assertSame([], $this->scheduledEvents, 'The scan should continue when the threshold filter raises the limit.');
        $this->assertCount(1, $GLOBALS['wp_query_last_args'], 'WP_Query should run when the load threshold allows it.');
    }

    public function test_blc_perform_check_honors_load_retry_delay_filter(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->serverLoad = [3.5, 2.0, 1.0];
        Functions\when('apply_filters')->alias(function (string $hook, $value, ...$args) {
            if ($hook === 'blc_load_retry_delay') {
                return 600;
            }

            return $value;
        });

        blc_perform_check(2, false);

        $this->assertCount(1, $this->scheduledEvents, 'A retry should still be scheduled when the delay filter changes the timing.');
        $event = $this->scheduledEvents[0];
        $this->assertSame($this->utcNow + 600, $event['timestamp']);
        $this->assertSame('blc_check_batch', $event['hook']);
        $this->assertSame([2, false], $event['args']);
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

    public function test_blc_perform_check_handles_scheme_relative_urls(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 314,
            'post_title' => 'CDN Link Post',
            'post_content' => '<a href="//cdn.example.com/foo">CDN Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('GET', 'https://cdn.example.com/foo', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertCount(1, $this->httpRequests, 'Scheme-relative URLs should be requested once.');
        $this->assertSame('https://cdn.example.com/foo', $this->httpRequests[0]['url']);

        $this->assertCount(1, $wpdb->inserted, 'Broken scheme-relative URL should be recorded.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('//cdn.example.com/foo', $insert['data']['url'], 'Original URL should be stored for UI consistency.');
        $this->assertSame('link', $insert['data']['type']);
        $this->assertSame('CDN Link', $insert['data']['anchor']);
    }

    public function test_blc_ajax_edit_link_callback_updates_scheme_relative_url(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post_id = 512;
        $original_content = '<p><a href="//cdn.example.com/foo">CDN Link</a></p>';

        Functions\when('get_post')->alias(function (int $requested_post_id) use ($post_id, $original_content) {
            if ($requested_post_id === $post_id) {
                return (object) ['ID' => $post_id, 'post_content' => $original_content];
            }

            return null;
        });

        $_POST = [
            'post_id'    => (string) $post_id,
            'old_url'    => '//cdn.example.com/foo',
            'new_url'    => 'https://cdn.example.com/bar',
            '_ajax_nonce' => 'nonce',
        ];

        try {
            blc_ajax_edit_link_callback();
            $this->fail('Expected wp_send_json_success to terminate execution.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('wp_send_json_success', $exception->getMessage());
        }

        $this->assertNotNull($this->ajaxResponse, 'AJAX response should be captured.');
        $this->assertTrue($this->ajaxResponse['success'], 'AJAX call should succeed.');

        $this->assertCount(1, $this->updatedPosts, 'The post content should be updated once.');
        $update = $this->updatedPosts[0]['data'];
        $this->assertSame($post_id, $update['ID']);
        $this->assertStringContainsString('https://cdn.example.com/bar', $update['post_content']);
        $this->assertStringNotContainsString('//cdn.example.com/foo', $update['post_content']);

        $this->assertCount(1, $wpdb->deleted, 'Original URL should be removed from the broken links table.');
        $this->assertSame('//cdn.example.com/foo', $wpdb->deleted[0]['where']['url']);
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
        $this->assertSame($this->utcNow + 60, $event['timestamp']);
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
        $this->assertSame($this->utcNow, $event['timestamp'], 'Negative delays should be coerced to zero before scheduling.');
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
            'post_content' => '<img src="https://example.com/wp-content/uploads/2024/05/missing.jpg" />' .
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
        $this->assertSame($this->utcNow + 60, $event['timestamp']);
        $this->assertSame('blc_check_image_batch', $event['hook']);
        $this->assertSame([1, true], $event['args']);
        $this->assertSame([], $this->updatedOptions, 'Image scan should not update options.');
    }

    public function test_blc_perform_image_check_ignores_traversal_urls(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 90,
            'post_title' => 'Traversal Post',
            'post_content' => '<img src="https://example.com/wp-content/uploads/2024/../secret.jpg" />',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        blc_perform_image_check(0, true);

        $this->assertCount(0, $wpdb->inserted, 'Traversal URLs should be ignored and not marked as broken images.');
    }

    public function test_blc_perform_image_check_records_relative_missing_images(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 91,
            'post_title' => 'Relative Image Post',
            'post_content' => '<img src="/wp-content/uploads/missing.jpg" />',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        blc_perform_image_check(0, true);

        $this->assertCount(1, $wpdb->inserted, 'Relative upload image should be recorded when file is missing.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('/wp-content/uploads/missing.jpg', $insert['data']['url']);
        $this->assertSame('missing.jpg', $insert['data']['anchor']);
        $this->assertSame(91, $insert['data']['post_id']);
    }
}
}
