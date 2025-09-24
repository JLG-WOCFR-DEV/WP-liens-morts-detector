<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';

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

    /** @var array<int, array{method: string, url: string, args: array}> */
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
        Functions\when('site_url')->alias(function ($path = '', $scheme = null) {
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
        Functions\when('wp_parse_url')->alias(function ($url, $component = -1) {
            if ($component === -1) {
                return parse_url((string) $url);
            }

            return parse_url((string) $url, $component);
        });
        Functions\when('dns_get_record')->alias(function (string $hostname, ?int $type = null) {
            $records = [];

            $wants_ipv4 = true;
            $wants_ipv6 = true;

            if ($type !== null) {
                $wants_ipv4 = !defined('DNS_A') || ($type & DNS_A) === DNS_A;
                $wants_ipv6 = !defined('DNS_AAAA') || ($type & DNS_AAAA) === DNS_AAAA;
            }

            if ($wants_ipv4) {
                $records[] = ['ip' => '93.184.216.34'];
            }

            if ($wants_ipv6) {
                $records[] = ['ipv6' => '2001:4860:4860::8888'];
            }

            return $records;
        });
        Functions\when('gethostbynamel')->alias(fn(string $hostname) => ['93.184.216.34']);
        Functions\when('wp_reset_postdata')->justReturn(null);
        Functions\when('wp_kses_bad_protocol')->alias(function ($string, $allowed_protocols = []) {
            $string = (string) $string;
            $allowed_protocols = array_map('strtolower', (array) $allowed_protocols);

            if (preg_match('#^([a-z0-9+.-]+):#i', $string, $matches)) {
                $scheme = strtolower($matches[1]);

                if (!in_array($scheme, $allowed_protocols, true)) {
                    return preg_replace('#^[a-z0-9+.-]+:#i', '', $string);
                }
            }

            return $string;
        });
        $testCase = $this;
        Functions\when('current_time')->alias(function (string $type, $gmt = 0) use ($testCase) {
            if ($type === 'timestamp') {
                $timestamp = $testCase->utcNow;

                if ($gmt) {
                    return $timestamp;
                }

                $timezone = function_exists('wp_timezone') ? \wp_timezone() : null;
                if ($timezone instanceof \DateTimeZone) {
                    $offset = $timezone->getOffset(new \DateTimeImmutable('@' . $timestamp));
                    return $timestamp + $offset;
                }

                return $timestamp;
            }

            if ($type === 'mysql') {
                return '1970-01-01 00:00:00';
            }

            return $testCase->currentHour;
        });
        Functions\when('trailingslashit')->alias(function ($value) {
            return rtrim((string) $value, "\\/\t\n\r\f ") . '/';
        });
        Functions\when('get_permalink')->alias(function ($post = null) {
            if (is_object($post) && isset($post->ID)) {
                return 'https://example.com/post-' . $post->ID . '/';
            }

            if (is_numeric($post)) {
                return 'https://example.com/post-' . ((int) $post) . '/';
            }

            return 'https://example.com/post/';
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
        Functions\when('wp_basename')->alias(fn($path, $suffix = '') => basename((string) $path, (string) $suffix));
        Functions\when('wp_strip_all_tags')->alias(fn(string $text) => strip_tags($text));
        Functions\when('wp_remote_get')->alias(function (string $url, array $args = []) {
            return $this->mockHttpRequest('GET', $url, $args);
        });
        Functions\when('wp_remote_head')->alias(function (string $url, array $args = []) {
            return $this->mockHttpRequest('HEAD', $url, $args);
        });
        Functions\when('wp_safe_remote_get')->alias(function (string $url, array $args = []) {
            return $this->mockHttpRequest('GET', $url, $args);
        });
        Functions\when('wp_safe_remote_head')->alias(function (string $url, array $args = []) {
            return $this->mockHttpRequest('HEAD', $url, $args);
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

    private function mockHttpRequest(string $method, string $url, array $args = []): array
    {
        $this->httpRequests[] = ['method' => $method, 'url' => $url, 'args' => $args];
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
            public string $posts = 'wp_posts';
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

    public function test_blc_normalize_link_url_uses_site_origin_for_root_relative_paths(): void
    {
        $this->assertSame(
            'https://example.com/contact',
            blc_normalize_link_url('/contact', 'https://example.com/blog/', 'https')
        );
    }

    public function test_blc_normalize_link_url_resolves_relative_paths_against_permalink(): void
    {
        $permalink = 'https://example.com/blog/articles/mon-post/';

        $this->assertSame(
            'https://example.com/blog/articles/mon-post/section/page.html',
            blc_normalize_link_url('section/page.html', 'https://example.com/blog/', 'https', $permalink)
        );
    }

    public function test_blc_normalize_link_url_supports_parent_directory_segments(): void
    {
        $permalink = 'https://example.com/blog/articles/mon-post/';

        $this->assertSame(
            'https://example.com/blog/articles/fichier',
            blc_normalize_link_url('../fichier', 'https://example.com/blog/', 'https', $permalink)
        );
    }

    public function test_blc_perform_check_resolves_relative_links_using_permalink(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 321,
            'post_title' => 'Relative Link',
            'post_content' => '<a href="section/page.html">Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 0,
        ];

        blc_perform_check(0, false);

        $this->assertNotEmpty($this->httpRequests, 'Relative links should trigger an HTTP request with the resolved URL.');
        $this->assertSame('https://example.com/post-321/section/page.html', $this->httpRequests[0]['url']);
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
        $this->assertSame([3, false, false], $event['args']);
        $this->assertGreaterThan($this->utcNow, $event['timestamp']);
        $this->assertSame(20 * 3600, $event['timestamp']);
    }

    public function test_blc_perform_check_reschedules_during_overnight_rest_period(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_rest_start_hour'] = '22';
        $this->options['blc_rest_end_hour']   = '06';
        $this->currentHour                    = '23';
        $this->utcNow                         = 23 * 3600;

        blc_perform_check(5, false);

        $this->assertSame([], $GLOBALS['wp_query_last_args'], 'No query should run during the overnight rest window.');
        $this->assertSame([], $wpdb->queries, 'Database queries must not be executed during rest hours.');
        $this->assertSame([], $wpdb->inserted, 'No records should be inserted during rest hours.');
        $this->assertSame([], $wpdb->deleted, 'No deletions should be issued during rest hours.');

        $this->assertCount(1, $this->scheduledEvents, 'A rescheduled scan should be queued when inside the rest window.');
        $event = $this->scheduledEvents[0];

        $this->assertSame('blc_check_batch', $event['hook']);
        $this->assertSame([5, false, false], $event['args']);
        $this->assertGreaterThan($this->utcNow, $event['timestamp']);
        $this->assertSame(30 * 3600, $event['timestamp']);
    }

    public function test_blc_perform_check_bypass_rest_window_runs_immediately(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_rest_start_hour'] = '08';
        $this->options['blc_rest_end_hour']   = '20';
        $this->currentHour                    = '09';

        $post = (object) [
            'ID' => 13,
            'post_title' => 'Bypass Post',
            'post_content' => '<a href="http://ok.com/resource">Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 2,
        ];

        blc_perform_check(0, true, true);

        $this->assertNotSame([], $GLOBALS['wp_query_last_args'], 'Manual scans should run immediately even during rest hours.');
        $this->assertCount(1, $this->scheduledEvents, 'Follow-up batch should be scheduled when more pages remain.');
        $event = $this->scheduledEvents[0];

        $this->assertSame('blc_check_batch', $event['hook']);
        $this->assertSame([1, true, true], $event['args']);
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

        $this->setHttpResponse('HEAD', 'http://allowed.com/bad', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertCount(1, $this->httpRequests, 'Only the non-excluded URL should be requested.');
        $this->assertSame('http://allowed.com/bad', $this->httpRequests[0]['url']);
        $this->assertSame('HEAD', $this->httpRequests[0]['method']);

        $this->assertCount(1, $wpdb->inserted, 'Only one broken link should be inserted.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('wp_blc_broken_links', $insert['table']);
        $this->assertSame('http://allowed.com/bad', $insert['data']['url']);
        $this->assertSame('link', $insert['data']['type']);
        $this->assertSame([], $this->scheduledEvents, 'No follow-up batch should be scheduled.');
        $this->assertSame($this->utcNow, $this->updatedOptions['blc_last_check_time'] ?? null, 'Last check time should be updated.');
    }

    public function test_blc_perform_check_requests_internal_hosts_even_when_dns_points_to_private_network(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_scan_method'] = 'precise';

        Functions\when('dns_get_record')->alias(function (...$args) {
            return [
                ['ip' => '127.0.0.1'],
                ['ipv6' => '::1'],
            ];
        });
        Functions\when('gethostbynamel')->alias(fn(string $hostname) => ['127.0.0.1']);

        $post = (object) [
            'ID' => 88,
            'post_title' => 'Local Link Post',
            'post_content' => '<a href="https://example.com/internal">Internal</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'https://example.com/internal', ['response' => ['code' => 500]]);

        blc_perform_check(0, false);

        $this->assertCount(1, $this->httpRequests, 'Internal links should still be requested despite private DNS resolution.');
        $this->assertSame('HEAD', $this->httpRequests[0]['method']);
        $this->assertSame('https://example.com/internal', $this->httpRequests[0]['url']);

        $this->assertCount(1, $wpdb->inserted, 'The failing internal link must be recorded.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('https://example.com/internal', $insert['data']['url']);
        $this->assertSame('link', $insert['data']['type']);
    }

    public function test_blc_perform_check_allows_existing_upload_with_encoded_url(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $uploads_dir = sys_get_temp_dir() . '/uploads-test';
        $encoded_dir = $uploads_dir . '/2024/05';
        if (!is_dir($encoded_dir) && !mkdir($encoded_dir, 0777, true) && !is_dir($encoded_dir)) {
            $this->fail('Unable to create uploads directory for test.');
        }

        $encoded_filename = 'sample file #1.pdf';
        $encoded_path = $encoded_dir . '/' . $encoded_filename;
        if (file_put_contents($encoded_path, 'pdf') === false) {
            $this->fail('Unable to create uploads file for test.');
        }

        $encoded_url = 'https://example.com/wp-content/uploads/2024/05/sample%20file%20%231.pdf';

        $post = (object) [
            'ID' => 402,
            'post_title' => 'Encoded Upload Link',
            'post_content' => '<a href="' . $encoded_url . '">Download</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', $encoded_url, ['response' => ['code' => 200]]);

        try {
            blc_perform_check(0, false);
        } finally {
            @unlink($encoded_path);
            @rmdir($encoded_dir);
            @rmdir(dirname($encoded_dir));
        }

        $this->assertCount(0, $wpdb->inserted, 'Existing uploads with encoded URLs should not be marked as missing.');
    }

    public function test_blc_perform_check_records_domain_without_ip_without_http_requests(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_scan_method'] = 'precise';

        Functions\when('dns_get_record')->alias(function (string $hostname, $type = null) {
            if ($hostname === 'no-ip.test') {
                return [];
            }

            return [
                ['ip' => '93.184.216.34'],
                ['ipv6' => '2001:4860:4860::8888'],
            ];
        });
        Functions\when('gethostbynamel')->alias(function (string $hostname) {
            if ($hostname === 'no-ip.test') {
                return [];
            }

            return ['93.184.216.34'];
        });

        $post = (object) [
            'ID' => 501,
            'post_title' => 'Unsafe Host Post',
            'post_content' => '<a href="http://no-ip.test/missing">Broken</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 0,
        ];

        blc_perform_check(0, false);

        $this->assertCount(0, $this->httpRequests, 'Unsafe hosts must not trigger outbound HTTP requests.');
        $this->assertCount(1, $wpdb->inserted, 'Unsafe hosts should be recorded as broken links.');

        $insert = $wpdb->inserted[0];
        $this->assertSame('wp_blc_broken_links', $insert['table']);
        $this->assertSame('http://no-ip.test/missing', $insert['data']['url']);
        $this->assertSame('Broken', $insert['data']['anchor']);
        $this->assertSame('link', $insert['data']['type']);
        $this->assertSame(501, $insert['data']['post_id']);
    }

    public function test_blc_is_safe_remote_host_rejects_private_only_ipv6_records(): void
    {
        Functions\when('dns_get_record')->alias(function (string $hostname, ?int $type = null) {
            if ($type === null) {
                return [
                    ['type' => 'AAAA', 'ipv6' => '::1'],
                ];
            }

            return [];
        });

        Functions\when('gethostbynamel')->alias(fn(string $hostname) => []);

        $this->assertFalse(
            blc_is_safe_remote_host('ipv6-only.test'),
            'Hosts resolving exclusively to private IPv6 addresses must be rejected.'
        );
    }

    public function test_blc_is_safe_remote_host_accepts_idn_hosts(): void
    {
        if (!function_exists('idn_to_ascii')) {
            $this->markTestSkipped('idn_to_ascii() is required to validate IDN hostnames.');
        }

        $queriedHosts = [];

        Functions\when('dns_get_record')->alias(function (string $hostname, ?int $type = null) use (&$queriedHosts) {
            $queriedHosts[] = [
                'function' => 'dns_get_record',
                'type'     => $type,
                'host'     => $hostname,
            ];

            return [
                ['ip' => '93.184.216.34'],
            ];
        });

        Functions\when('gethostbynamel')->alias(function (string $hostname) use (&$queriedHosts) {
            $queriedHosts[] = [
                'function' => 'gethostbynamel',
                'host'     => $hostname,
            ];

            return ['93.184.216.34'];
        });

        $this->assertTrue(
            blc_is_safe_remote_host('bücher.example'),
            'IDN hostnames should resolve successfully when they map to public IP addresses.'
        );

        $queriedHostnames = array_map(
            static fn(array $entry): string => $entry['host'],
            $queriedHosts
        );

        $this->assertContains(
            'xn--bcher-kva.example',
            $queriedHostnames,
            'IDN hostnames must be converted to ASCII before performing DNS lookups.'
        );
    }

    public function test_blc_perform_check_limits_head_requests_and_falls_back_to_get_when_needed(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_scan_method'] = 'precise';

        $post = (object) [
            'ID' => 43,
            'post_title' => 'Head Fallback Post',
            'post_content' => '<a href="http://example.com/no-head">Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://example.com/no-head', ['response' => ['code' => 405]]);
        $this->setHttpResponse('GET', 'http://example.com/no-head', ['response' => ['code' => 200]]);

        blc_perform_check(0, false);

        $this->assertCount(2, $this->httpRequests, 'HEAD followed by GET should be performed when HEAD is not allowed.');

        $headRequest = $this->httpRequests[0];
        $this->assertSame('HEAD', $headRequest['method']);
        $this->assertSame('http://example.com/no-head', $headRequest['url']);
        $this->assertArrayHasKey('limit_response_size', $headRequest['args']);
        $this->assertSame(1024, $headRequest['args']['limit_response_size']);

        $getRequest = $this->httpRequests[1];
        $this->assertSame('GET', $getRequest['method']);
        $this->assertSame('http://example.com/no-head', $getRequest['url']);
        $this->assertArrayHasKey('limit_response_size', $getRequest['args']);
        $this->assertSame(131072, $getRequest['args']['limit_response_size']);

        $this->assertCount(0, $wpdb->inserted, 'Successful GET fallback should prevent false positives.');
    }

    public function test_blc_perform_check_uses_gmt_dates_for_incremental_scans(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        Functions\when('wp_timezone')->alias(fn() => new \DateTimeZone('Europe/Paris'));
        Functions\when('wp_timezone_string')->alias(fn() => 'Europe/Paris');

        $this->utcNow = 7200;
        $this->options['blc_last_check_time'] = $this->utcNow - 3600;
        $this->options['blc_scan_method'] = 'precise';

        $post = (object) [
            'ID' => 404,
            'post_title' => 'Recently Updated',
            'post_content' => '<a href="http://example.net/bad">Broken</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://example.net/bad', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertNotEmpty($GLOBALS['wp_query_last_args'], 'WP_Query should be instantiated for incremental scans.');
        $lastArgs = end($GLOBALS['wp_query_last_args']);
        $this->assertIsArray($lastArgs, 'WP_Query arguments should be an array.');

        $expectedThreshold = gmdate('Y-m-d H:i:s', $this->options['blc_last_check_time']);
        $this->assertArrayHasKey('date_query', $lastArgs, 'Incremental scans should set a date_query clause.');
        $this->assertSame('post_modified_gmt', $lastArgs['date_query'][0]['column']);
        $this->assertSame($expectedThreshold, $lastArgs['date_query'][0]['after']);

        $this->assertSame($this->utcNow, $this->updatedOptions['blc_last_check_time'] ?? null, 'Last check time should be updated using GMT timestamps.');
    }

    public function test_blc_perform_check_rescans_recent_posts_with_timezone_offset(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        Functions\when('wp_timezone')->alias(fn() => new \DateTimeZone('America/New_York'));
        Functions\when('wp_timezone_string')->alias(fn() => 'America/New_York');

        $this->options['blc_last_check_time'] = $this->utcNow - 600;
        $this->options['blc_scan_method'] = 'precise';

        $post = (object) [
            'ID' => 405,
            'post_title' => 'Modified After Last Scan',
            'post_content' => '<a href="http://example.org/missing">Broken Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://example.org/missing', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertNotEmpty($this->httpRequests, 'Incremental scans should request links from recently modified posts.');
        $this->assertSame('http://example.org/missing', $this->httpRequests[0]['url']);

        $this->assertCount(1, $wpdb->inserted, 'A broken link detected during an incremental scan should be recorded.');

        $this->assertNotEmpty($GLOBALS['wp_query_last_args'], 'WP_Query should be called to fetch recently modified posts.');
        $lastArgs = end($GLOBALS['wp_query_last_args']);
        $this->assertIsArray($lastArgs, 'WP_Query arguments should be an array for incremental scans.');

        $expectedThreshold = gmdate('Y-m-d H:i:s', $this->options['blc_last_check_time']);
        $this->assertArrayHasKey('date_query', $lastArgs, 'Incremental scans must restrict posts by modification date in GMT.');
        $this->assertSame('post_modified_gmt', $lastArgs['date_query'][0]['column']);
        $this->assertSame($expectedThreshold, $lastArgs['date_query'][0]['after']);

        $this->assertSame($this->utcNow, $this->updatedOptions['blc_last_check_time'] ?? null, 'Last check time should be updated in GMT after the scan.');
    }

    public function test_blc_perform_check_records_and_uses_utc_timestamps_with_positive_timezone_offset(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        Functions\when('wp_timezone')->alias(fn() => new \DateTimeZone('Europe/Paris'));
        Functions\when('wp_timezone_string')->alias(fn() => 'Europe/Paris');

        $this->utcNow = 10000;

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [],
            'max_num_pages' => 1,
        ];

        blc_perform_check(0, true);

        $this->assertSame(
            $this->utcNow,
            $this->updatedOptions['blc_last_check_time'] ?? null,
            'The last check time must be stored as a UTC timestamp regardless of the site timezone.'
        );

        $this->options['blc_last_check_time'] = $this->updatedOptions['blc_last_check_time'];
        $this->options['blc_scan_method'] = 'precise';
        $this->updatedOptions = [];
        $this->httpRequests = [];
        $this->scheduledEvents = [];
        $GLOBALS['wp_query_last_args'] = [];
        $GLOBALS['wp_query_queue'] = [[
            'posts' => [
                (object) [
                    'ID' => 501,
                    'post_title' => 'Recently Updated With Offset',
                    'post_content' => '<a href="http://example.org/fail">Broken</a>',
                    'post_modified_gmt' => gmdate('Y-m-d H:i:s', $this->options['blc_last_check_time'] + 60),
                ],
            ],
            'max_num_pages' => 1,
        ]];

        $this->setHttpResponse('HEAD', 'http://example.org/fail', ['response' => ['code' => 404]]);

        $this->utcNow += 120;

        blc_perform_check(0, false);

        $this->assertNotEmpty(
            $this->httpRequests,
            'Incremental scans must request links from posts modified after the previous run even with positive timezone offsets.'
        );
        $this->assertSame('http://example.org/fail', $this->httpRequests[0]['url']);

        $this->assertNotEmpty($GLOBALS['wp_query_last_args'], 'WP_Query should run during the incremental scan.');
        $queryArgs = end($GLOBALS['wp_query_last_args']);
        $this->assertIsArray($queryArgs, 'WP_Query arguments should be available for assertions.');

        $expectedThreshold = gmdate('Y-m-d H:i:s', $this->options['blc_last_check_time']);
        $this->assertArrayHasKey('date_query', $queryArgs, 'Incremental scans must filter posts using GMT thresholds.');
        $this->assertSame('post_modified_gmt', $queryArgs['date_query'][0]['column']);
        $this->assertSame(
            $expectedThreshold,
            $queryArgs['date_query'][0]['after'],
            'The GMT threshold should rely on the UTC timestamp recorded after the previous run.'
        );
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

        $this->setHttpResponse('HEAD', 'http://ALLOWED.com/bad', ['response' => ['code' => 404]]);

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

        $this->setHttpResponse('HEAD', 'http://notexample.com/bad', ['response' => ['code' => 404]]);

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
        $this->assertSame([0, false, false], $event['args']);
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
        $this->assertSame([2, false, false], $event['args']);
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

        $this->setHttpResponse('HEAD', 'https://cdn.example.com/foo', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertCount(1, $this->httpRequests, 'Scheme-relative URLs should be requested once.');
        $this->assertSame('https://cdn.example.com/foo', $this->httpRequests[0]['url']);

        $this->assertCount(1, $wpdb->inserted, 'Broken scheme-relative URL should be recorded.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('//cdn.example.com/foo', $insert['data']['url'], 'Original URL should be stored for UI consistency.');
        $this->assertSame('link', $insert['data']['type']);
        $this->assertSame('CDN Link', $insert['data']['anchor']);
    }

    /**
     * @return array<string, array{int}>
     */
    public function headStatusFallbackProvider(): array
    {
        return [
            'forbidden'    => [403],
            'rate_limited' => [429],
        ];
    }

    /**
     * @dataProvider headStatusFallbackProvider
     */
    public function test_blc_perform_check_recovers_from_head_status(int $headStatus): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_scan_method'] = 'precise';

        $post = (object) [
            'ID' => 777,
            'post_title' => 'Temporary Protection',
            'post_content' => '<a href="http://temp.example.com/protected">Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://temp.example.com/protected', ['response' => ['code' => $headStatus]]);
        $this->setHttpResponse('GET', 'http://temp.example.com/protected', ['response' => ['code' => 200]]);

        blc_perform_check(0, false);

        $this->assertCount(2, $this->httpRequests, 'HEAD fallbacks should trigger a follow-up GET request.');
        $this->assertSame('HEAD', $this->httpRequests[0]['method']);
        $this->assertSame('GET', $this->httpRequests[1]['method']);
        $this->assertSame('http://temp.example.com/protected', $this->httpRequests[1]['url']);

        $this->assertCount(0, $wpdb->inserted, 'Temporary responses must not be stored as broken links.');
        $this->assertSame([], $this->scheduledEvents, 'No retry should be scheduled when the GET request succeeds.');
        $this->assertSame(
            $this->utcNow,
            $this->updatedOptions['blc_last_check_time'] ?? null,
            'Successful recovery should update the last check timestamp.'
        );
    }

    public function test_blc_perform_check_marks_forbidden_as_broken_without_retry(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 811,
            'post_title' => 'Forbidden Resource',
            'post_content' => '<a href="http://forbidden.example.com/page">Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://forbidden.example.com/page', ['response' => ['code' => 403]]);
        $this->setHttpResponse('GET', 'http://forbidden.example.com/page', ['response' => ['code' => 403]]);

        blc_perform_check(0, false);

        $this->assertSame('HEAD', $this->httpRequests[0]['method']);
        $this->assertSame('GET', $this->httpRequests[1]['method']);
        $this->assertCount(1, $wpdb->inserted, 'Forbidden responses should be recorded as broken links after the GET fallback.');
        $this->assertSame([], $this->scheduledEvents, 'Forbidden responses must not be retried indefinitely.');
    }

    public function test_blc_perform_check_normalizes_host_with_port_without_scheme(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 415,
            'post_title' => 'Port Without Scheme',
            'post_content' => '<a href="www.example.com:8080/path">External Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'https://www.example.com:8080/path', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertCount(1, $this->httpRequests, 'Links with explicit ports should trigger one remote request.');
        $this->assertSame('https://www.example.com:8080/path', $this->httpRequests[0]['url']);

        $this->assertCount(1, $wpdb->inserted, 'A failing external link must be recorded.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('www.example.com:8080/path', $insert['data']['url'], 'Original URL should be preserved for storage.');
        $this->assertSame('link', $insert['data']['type']);
    }

    public function test_blc_perform_check_normalizes_host_with_query_without_scheme(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 416,
            'post_title' => 'Query Without Scheme',
            'post_content' => '<a href="example.com?foo=bar">External Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'https://example.com?foo=bar', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertCount(1, $this->httpRequests, 'Links with a query should trigger one remote request.');
        $this->assertSame('https://example.com?foo=bar', $this->httpRequests[0]['url']);

        $this->assertCount(1, $wpdb->inserted, 'A failing external link must be recorded.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('example.com?foo=bar', $insert['data']['url'], 'Original URL should be preserved for storage.');
        $this->assertSame('link', $insert['data']['type']);
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

    public function test_blc_ajax_edit_link_callback_preserves_relative_href_in_dom(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post_id = 742;
        $original_content = '<p><a href="https://example.com/old">Old</a></p>';

        Functions\when('get_post')->alias(function (int $requested_post_id) use ($post_id, $original_content) {
            if ($requested_post_id === $post_id) {
                return (object) ['ID' => $post_id, 'post_content' => $original_content];
            }

            return null;
        });

        Functions\when('get_permalink')->alias(function ($post = null) use ($post_id) {
            if (is_object($post) && isset($post->ID) && $post->ID === $post_id) {
                return 'https://example.com/blog/articles/mon-post/';
            }

            if (is_numeric($post) && (int) $post === $post_id) {
                return 'https://example.com/blog/articles/mon-post/';
            }

            return 'https://example.com/post/';
        });

        $_POST = [
            'post_id'    => (string) $post_id,
            'old_url'    => 'https://example.com/old',
            'new_url'    => 'section/page.html',
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
        $this->assertStringContainsString('section/page.html', $update['post_content']);
        $this->assertStringNotContainsString('https://example.com/blog/articles/mon-post/section/page.html', $update['post_content']);

        $this->assertCount(1, $wpdb->deleted, 'Original URL should be removed from the broken links table.');
        $this->assertSame('https://example.com/old', $wpdb->deleted[0]['where']['url']);
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

        $this->assertCount(2, $wpdb->queries, 'Existing entries for the batch should be cleared.');
        $this->assertCount(0, $wpdb->inserted, 'No broken links should be recorded for healthy responses.');
        $this->assertCount(1, $this->scheduledEvents, 'Next batch should be scheduled.');
        $event = $this->scheduledEvents[0];
        $this->assertSame($this->utcNow + 60, $event['timestamp']);
        $this->assertSame('blc_check_batch', $event['hook']);
        $this->assertSame([1, true, false], $event['args']);
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
        $this->assertSame([1, false, false], $event['args']);
    }

    public function test_blc_perform_check_removes_entries_for_deleted_posts_before_inserting(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 55,
            'post_title' => 'Cleanup Post',
            'post_content' => '<a href="http://cleanup.example.com">Broken Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://cleanup.example.com', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $cleanupQueryFound = false;
        foreach ($wpdb->queries as $query) {
            $sql = $query['sql'] ?? '';
            if (strpos($sql, 'DELETE blc FROM wp_blc_broken_links AS blc') !== false
                && strpos($sql, 'LEFT JOIN wp_posts AS posts') !== false
                && strpos($sql, 'posts.ID IS NULL') !== false) {
                $cleanupQueryFound = true;
                break;
            }
        }

        $this->assertTrue($cleanupQueryFound, 'Cleanup query should remove orphaned entries before inserting new ones.');
        $this->assertNotEmpty($wpdb->inserted, 'Broken links should continue to be recorded after cleanup.');
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

    public function test_blc_perform_image_check_records_missing_cdn_upload_image(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $uploads_dir = sys_get_temp_dir() . '/uploads-test-cdn';
        Functions\when('wp_upload_dir')->alias(function () use ($uploads_dir) {
            return [
                'baseurl' => 'https://cdn.example.org/wp-content/uploads',
                'basedir' => $uploads_dir,
            ];
        });

        $post = (object) [
            'ID' => 104,
            'post_title' => 'CDN Missing Image',
            'post_content' => '<img src="https://cdn.example.org/wp-content/uploads/2024/05/missing-cdn.jpg" />',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        blc_perform_image_check(0, true);

        $this->assertCount(1, $wpdb->inserted, 'Missing CDN upload image should be recorded.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('image', $insert['data']['type']);
        $this->assertSame('missing-cdn.jpg', $insert['data']['anchor']);
        $this->assertSame(104, $insert['data']['post_id']);

        Functions\when('wp_upload_dir')->alias(function () {
            return [
                'baseurl' => 'https://example.com/wp-content/uploads',
                'basedir' => sys_get_temp_dir() . '/uploads-test',
            ];
        });
    }

    public function test_blc_perform_image_check_allows_existing_upload_with_query(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $uploads_dir = sys_get_temp_dir() . '/uploads-test';
        $image_dir = $uploads_dir . '/2024/05';
        if (!is_dir($image_dir) && !mkdir($image_dir, 0777, true) && !is_dir($image_dir)) {
            $this->fail('Unable to create uploads directory for test.');
        }

        $image_file = $image_dir . '/present.jpg';
        if (file_put_contents($image_file, 'img') === false) {
            $this->fail('Unable to create uploads image file for test.');
        }

        $post = (object) [
            'ID' => 92,
            'post_title' => 'Uploads Image With Query',
            'post_content' => '<img src="https://example.com/wp-content/uploads/2024/05/present.jpg?ver=123" />'
                . '<img src="https://example.com/wp-content/uploads/2024/05/missing-query.jpg?ver=456" />',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        try {
            blc_perform_image_check(0, true);
        } finally {
            @unlink($image_file);
            @rmdir($image_dir);
            @rmdir(dirname($image_dir));
        }

        $this->assertCount(1, $wpdb->inserted, 'Only the missing upload should be flagged even when query strings are present.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('image', $insert['data']['type']);
        $this->assertSame('missing-query.jpg', $insert['data']['anchor']);
        $this->assertSame(
            'https://example.com/wp-content/uploads/2024/05/missing-query.jpg?ver=456',
            $insert['data']['url']
        );
    }

    public function test_blc_perform_image_check_allows_existing_upload_with_encoded_url(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $uploads_dir = sys_get_temp_dir() . '/uploads-test';
        $image_dir = $uploads_dir . '/2024/06';
        if (!is_dir($image_dir) && !mkdir($image_dir, 0777, true) && !is_dir($image_dir)) {
            $this->fail('Unable to create uploads directory for test.');
        }

        $encoded_filename = 'encoded image #1.png';
        $image_file = $image_dir . '/' . $encoded_filename;
        if (file_put_contents($image_file, 'img') === false) {
            $this->fail('Unable to create encoded uploads image file for test.');
        }

        $encoded_url = 'https://example.com/wp-content/uploads/2024/06/encoded%20image%20%231.png';

        $post = (object) [
            'ID' => 193,
            'post_title' => 'Uploads Image Encoded URL',
            'post_content' => '<img src="' . $encoded_url . '" />',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        try {
            blc_perform_image_check(0, true);
        } finally {
            @unlink($image_file);
            @rmdir($image_dir);
            @rmdir(dirname($image_dir));
        }

        $this->assertCount(0, $wpdb->inserted, 'Existing uploads with encoded URLs should not be reported as missing images.');
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

    public function test_blc_perform_image_check_records_document_relative_missing_images(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 94,
            'post_title' => 'Document Relative Image Post',
            'post_content' => '<img src="images/foo.jpg" />',
        ];

        Functions\when('get_permalink')->alias(function ($post_arg = null) use ($post) {
            if (is_object($post_arg) && isset($post_arg->ID)) {
                if ($post_arg->ID === $post->ID) {
                    return 'https://example.com/wp-content/uploads/2024/05/sample-post/';
                }

                return 'https://example.com/post-' . $post_arg->ID . '/';
            }

            if (is_numeric($post_arg)) {
                return 'https://example.com/post-' . ((int) $post_arg) . '/';
            }

            return 'https://example.com/post/';
        });

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        blc_perform_image_check(0, true);

        $this->assertCount(1, $wpdb->inserted, 'Document-relative upload image should be recorded when file is missing.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('images/foo.jpg', $insert['data']['url']);
        $this->assertSame('foo.jpg', $insert['data']['anchor']);
        $this->assertSame(94, $insert['data']['post_id']);
        $this->assertSame('image', $insert['data']['type']);
    }

    public function test_blc_perform_image_check_handles_uppercase_upload_urls(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $uploads_dir = sys_get_temp_dir() . '/uploads-test';
        $image_dir = $uploads_dir . '/2024/05';
        if (!is_dir($image_dir) && !mkdir($image_dir, 0777, true) && !is_dir($image_dir)) {
            $this->fail('Unable to create uploads directory for test.');
        }

        $existing_file = $image_dir . '/PRESENT-UPPER.jpg';
        if (file_put_contents($existing_file, 'img') === false) {
            $this->fail('Unable to create uploads image file for test.');
        }

        $post = (object) [
            'ID' => 93,
            'post_title' => 'Uppercase Uploads',
            'post_content' => '<img src="HTTPS://EXAMPLE.COM/WP-CONTENT/UPLOADS/2024/05/PRESENT-UPPER.jpg" />'
                . '<img src="HTTPS://EXAMPLE.COM/WP-CONTENT/UPLOADS/2024/05/MISSING-UPPER.jpg" />',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        try {
            blc_perform_image_check(0, true);
        } finally {
            @unlink($existing_file);
            @rmdir($image_dir);
            @rmdir(dirname($image_dir));
        }

        $this->assertCount(
            1,
            $wpdb->inserted,
            'Only the missing uppercase upload should be flagged despite case differences.'
        );

        $insert = $wpdb->inserted[0];
        $this->assertSame('image', $insert['data']['type']);
        $this->assertSame('MISSING-UPPER.jpg', $insert['data']['anchor']);
        $this->assertSame(
            'HTTPS://EXAMPLE.COM/WP-CONTENT/UPLOADS/2024/05/MISSING-UPPER.jpg',
            $insert['data']['url']
        );
        $this->assertSame(93, $insert['data']['post_id']);
    }
}
}
