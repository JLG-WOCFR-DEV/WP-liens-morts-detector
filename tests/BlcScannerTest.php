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

    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('ARRAY_N')) {
        define('ARRAY_N', 'ARRAY_N');
    }
    if (!defined('OBJECT')) {
        define('OBJECT', 'OBJECT');
    }

    if (!function_exists('sanitize_key')) {
        function sanitize_key($key)
        {
            $key = strtolower((string) $key);

            return preg_replace('/[^a-z0-9_\-]/', '', $key);
        }
    }

    if (!function_exists('sanitize_email')) {
        function sanitize_email($email)
        {
            return filter_var((string) $email, FILTER_SANITIZE_EMAIL);
        }
    }

    if (!function_exists('is_email')) {
        function is_email($email)
        {
            return filter_var((string) $email, FILTER_VALIDATE_EMAIL) !== false;
        }
    }

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

    /** @var array<int, array{timestamp: int, hook: string, args: array, unique: bool}> */
    private array $failedScheduleAttempts = [];

    /** @var array<int, bool> */
    private array $scheduleSingleEventResults = [];

    /** @var array<int, array{hook: string, args: array}> */
    private array $triggeredActions = [];

    /** @var array<int, string> */
    private array $errorLogs = [];

    /** @var array<string, mixed> */
    private array $updatedOptions = [];

    /** @var array<int, array{to: array, subject: string, message: string, headers: mixed, attachments: mixed}> */
    private array $sentEmails = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<int, float> */
    private array $serverLoad = [0.5, 0.4, 0.3];

    /** @var array<int, array{text: string, domain: string|null}> */
    private array $translationCalls = [];

    private string $currentHour = '00';

    private int $utcNow = 1000;

    private string $currentMysqlTime = '1970-01-01 00:00:00';

    /** @var array<int, array<string, mixed>> */
    private array $updatedPosts = [];

    /** @var array{success: bool, data: mixed}|null */
    private ?array $ajaxResponse = null;

    /** @var array<int, string> */
    private array $publicPostTypes = [];

    /** @var array<int, array{args: array, output: string, operator: string}> */
    private array $getPostTypesCalls = [];

    private int $wpResetPostdataCalls = 0;

    /** @var array<string, array<string, string>> */
    private array $postStatuses = [];

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
            'blc_head_request_timeout' => 5,
            'blc_get_request_timeout'  => 10,
            'blc_scan_method'      => 'precise',
            'blc_excluded_domains' => '',
            'blc_last_check_time'  => 0,
            'blc_notification_recipients' => '',
            'blc_post_statuses'    => ['publish'],
        ];

        $this->httpRequests = [];
        $this->httpResponses = [];
        $this->scheduledEvents = [];
        $this->failedScheduleAttempts = [];
        $this->scheduleSingleEventResults = [];
        $this->triggeredActions = [];
        $this->errorLogs = [];
        $this->updatedOptions = [];
        $this->transients = [];
        $this->sentEmails = [];
        $this->serverLoad = [0.5, 0.4, 0.3];
        $GLOBALS['__translation_calls'] = [];
        $this->translationCalls = &$GLOBALS['__translation_calls'];
        $this->currentHour = '00';
        $this->updatedPosts = [];
        $this->ajaxResponse = null;
        $this->utcNow = 1000;
        $this->currentMysqlTime = '1970-01-01 00:00:00';
        $GLOBALS['wp_query_queue'] = [];
        $GLOBALS['wp_query_last_args'] = [];
        $this->publicPostTypes = ['post', 'page'];
        $this->getPostTypesCalls = [];
        $this->wpResetPostdataCalls = 0;
        $this->postStatuses = [
            'publish' => ['label' => 'Publié'],
            'draft'   => ['label' => 'Brouillon'],
            'pending' => ['label' => 'En attente'],
        ];

        Functions\when('get_option')->alias(fn(string $name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('get_transient')->alias(fn(string $key) => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, $value, $expiration) {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key) {
            unset($this->transients[$key]);
            return true;
        });
        Functions\when('apply_filters')->alias(fn(string $hook, $value, ...$args) => $value);
        Functions\when('home_url')->alias(function ($path = '', $scheme = null) {
            $base = 'https://example.com';
            if ($path !== '') {
                return rtrim($base, '/') . '/' . ltrim($path, '/');
            }

            return $base;
        });
        Functions\when('admin_url')->alias(function ($path = '', $scheme = 'admin') {
            $base = 'https://example.com/wp-admin/';
            if ($path === '') {
                return $base;
            }

            return $base . ltrim($path, '/');
        });
        Functions\when('esc_url_raw')->alias(static function ($url) {
            return (string) $url;
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
        Functions\when('wp_reset_postdata')->alias(function () {
            $this->wpResetPostdataCalls++;

            return null;
        });
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
                return $testCase->currentMysqlTime;
            }

            return $testCase->currentHour;
        });
        Functions\when('trailingslashit')->alias(function ($value) {
            return rtrim((string) $value, "\\/\t\n\r\f ") . '/';
        });
        Functions\when('get_post_stati')->alias(function ($args = [], $output = 'names', $operator = 'and') {
            if ($output === 'objects') {
                $objects = [];
                foreach ($this->postStatuses as $slug => $data) {
                    $objects[$slug] = (object) $data;
                }

                return $objects;
            }

            return array_keys($this->postStatuses);
        });
        Functions\when('get_post_types')->alias(function ($args = [], $output = 'names', $operator = 'and') {
            $this->getPostTypesCalls[] = [
                'args' => is_array($args) ? $args : [],
                'output' => (string) $output,
                'operator' => (string) $operator,
            ];

            return $this->publicPostTypes;
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

            if ($show === 'name') {
                return 'Example Blog';
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
        Functions\when('wp_mail')->alias(function ($to, $subject, $message, $headers = '', $attachments = []) use ($testCase) {
            $recipients = is_array($to) ? array_values($to) : [(string) $to];
            $testCase->sentEmails[] = [
                'to'          => $recipients,
                'subject'     => (string) $subject,
                'message'     => (string) $message,
                'headers'     => $headers,
                'attachments' => $attachments,
            ];

            return true;
        });
        Functions\when('update_option')->alias(function (string $option, $value) {
            $this->updatedOptions[$option] = $value;
            $this->options[$option] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $option) {
            unset($this->options[$option], $this->updatedOptions[$option]);
            return true;
        });
        Functions\when('wp_update_post')->alias(function (array $data, $wp_error = false) {
            $this->updatedPosts[] = ['data' => $data, 'wp_error' => $wp_error];
            return true;
        });
        Functions\when('wp_schedule_single_event')->alias(function (int $timestamp, string $hook, array $args = [], bool $unique = true) {
            $result = true;
            if (!empty($this->scheduleSingleEventResults)) {
                $result = array_shift($this->scheduleSingleEventResults);
            }

            $target = [
                'timestamp' => $timestamp,
                'hook'      => $hook,
                'args'      => $args,
                'unique'    => $unique,
            ];

            if ($result) {
                $this->scheduledEvents[] = $target;
            } else {
                $this->failedScheduleAttempts[] = $target;
            }

            return $result;
        });
        Functions\when('do_action')->alias(function (string $hook, ...$args) {
            $this->triggeredActions[] = [
                'hook' => $hook,
                'args' => $args,
            ];

            return null;
        });
        Functions\when('error_log')->alias(function ($message, $message_type = null, $destination = null, $extra_headers = null) {
            $this->errorLogs[] = (string) $message;

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
        Functions\when('wp_send_json_error')->alias(function ($data = null, $status_code = null) {
            $this->ajaxResponse = ['success' => false, 'data' => $data, 'status' => $status_code];
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
        unset($GLOBALS['__translation_calls']);
        unset($_POST);
        $this->ajaxResponse = null;
        $this->transients = [];
        $this->scheduledEvents = [];
        $this->failedScheduleAttempts = [];
        $this->scheduleSingleEventResults = [];
        $this->triggeredActions = [];
        $this->errorLogs = [];
        global $wpdb;
        $wpdb = null;
    }

    /**
     * @return array|\WP_Error
     */
    private function mockHttpRequest(string $method, string $url, array $args = [])
    {
        $this->httpRequests[] = ['method' => $method, 'url' => $url, 'args' => $args];
        $key = $method . ' ' . $url;

        return $this->httpResponses[$key] ?? ['response' => ['code' => 200]];
    }

    /**
     * @param array|\WP_Error $response
     */
    private function setHttpResponse(string $method, string $url, $response): void
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
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, array<string, mixed>> */
            public array $selectedRows = [];
            /** @var array<int, mixed> */
            public array $queryResults = [];
            /** @var array<int, mixed> */
            public array $getVarResults = [];
            /** @var array<string, int> */
            public array $summaryCounts = [];
            public int $insert_id = 0;
            public string $last_error = '';
            private int $autoIncrement = 1;

            public function query(string $sql)
            {
                $this->queries[] = ['sql' => $sql];
                if (!empty($this->queryResults)) {
                    return array_shift($this->queryResults);
                }

                return true;
            }

            public function insert(string $table, array $data, array $formats)
            {
                $this->inserted[] = ['table' => $table, 'data' => $data, 'formats' => $formats];
                $id = $this->autoIncrement++;
                $this->insert_id = $id;
                $this->rows[$id] = [
                    'id'    => $id,
                    'table' => $table,
                    'data'  => $data,
                ];

                return true;
            }

            public function delete(string $table, array $where, array $formats)
            {
                $this->deleted[] = ['table' => $table, 'where' => $where, 'formats' => $formats];

                $affected = 0;
                if (isset($where['id'])) {
                    $id = (int) $where['id'];
                    if (isset($this->rows[$id]) && $this->rows[$id]['table'] === $table) {
                        unset($this->rows[$id]);
                        $affected = 1;
                    }
                }

                return $affected;
            }

            public function get_var(string $query)
            {
                $this->queries[] = ['sql' => $query, 'type' => 'get_var'];
                if (!empty($this->getVarResults)) {
                    return array_shift($this->getVarResults);
                }

                if (!empty($this->summaryCounts) && strpos($query, 'COUNT(*)') !== false) {
                    if (strpos($query, "'link'") !== false && isset($this->summaryCounts['link'])) {
                        return $this->summaryCounts['link'];
                    }

                    if (strpos($query, "'image'") !== false && isset($this->summaryCounts['image'])) {
                        return $this->summaryCounts['image'];
                    }
                }

                return 0;
            }

            public function get_row(string $query, $output = ARRAY_A)
            {
                $this->queries[] = ['sql' => $query, 'type' => 'get_row'];
                if (empty($this->selectedRows)) {
                    return null;
                }

                $row = array_shift($this->selectedRows);
                if ($output === ARRAY_A) {
                    return $row;
                }

                return (object) $row;
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

    public function test_blc_stage_dataset_refresh_uses_plugin_domain_for_errors(): void
    {
        global $wpdb;
        $wpdb = new class {
            public $last_error = '';

            public function prepare($query, $args = null)
            {
                return false;
            }

            public function query($sql)
            {
                return 0;
            }
        };

        $initialCount = count($this->translationCalls);

        $result = blc_stage_dataset_refresh('wp_blc_links', 'link', 'scan-token');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('blc_stage_prepare_failed', $result->get_error_code());
        $this->assertSame($initialCount + 1, count($this->translationCalls));

        $lastCall = $this->translationCalls[$initialCount] ?? null;
        $this->assertNotNull($lastCall, 'Translation call should be recorded for staging errors.');
        $this->assertSame('liens-morts-detector-jlg', $lastCall['domain']);
    }

    public function test_blc_stage_dataset_refresh_translates_query_failures_with_plugin_domain(): void
    {
        global $wpdb;
        $wpdb = new class {
            public $last_error = '';

            public function prepare($query, $args = null)
            {
                return 'SQL';
            }

            public function query($sql)
            {
                return false;
            }
        };

        $initialCount = count($this->translationCalls);

        $result = blc_stage_dataset_refresh('wp_blc_links', 'link', 'scan-token');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('blc_stage_failed', $result->get_error_code());
        $this->assertSame('Failed to mark dataset rows for refresh.', $result->get_error_message());
        $this->assertSame($initialCount + 1, count($this->translationCalls));

        $lastCall = $this->translationCalls[$initialCount] ?? null;
        $this->assertNotNull($lastCall, 'Translation call should be recorded for staging query failures.');
        $this->assertSame('liens-morts-detector-jlg', $lastCall['domain']);
    }

    public function test_blc_commit_dataset_refresh_uses_plugin_domain_for_errors(): void
    {
        global $wpdb;
        $wpdb = new class {
            public $last_error = '';

            public function prepare($query, $args = null)
            {
                return false;
            }

            public function query($sql)
            {
                return 0;
            }

            public function get_var($query)
            {
                return 0;
            }
        };

        $initialCount = count($this->translationCalls);

        $result = blc_commit_dataset_refresh('wp_blc_links', 'link', 'scan-token', 'link');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('blc_commit_prepare_failed', $result->get_error_code());
        $this->assertSame($initialCount + 1, count($this->translationCalls));

        $lastCall = $this->translationCalls[$initialCount] ?? null;
        $this->assertNotNull($lastCall, 'Translation call should be recorded for commit errors.');
        $this->assertSame('liens-morts-detector-jlg', $lastCall['domain']);
    }

    public function test_blc_commit_dataset_refresh_translates_query_failures_with_plugin_domain(): void
    {
        global $wpdb;
        $wpdb = new class {
            public $last_error = '';

            public function prepare($query, $args = null)
            {
                return 'SQL';
            }

            public function query($sql)
            {
                return false;
            }

            public function get_var($query)
            {
                return 0;
            }
        };

        $initialCount = count($this->translationCalls);

        $result = blc_commit_dataset_refresh('wp_blc_links', 'link', 'scan-token', 'link');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('blc_commit_failed', $result->get_error_code());
        $this->assertSame('Failed to purge stale dataset entries.', $result->get_error_message());
        $this->assertSame($initialCount + 1, count($this->translationCalls));

        $lastCall = $this->translationCalls[$initialCount] ?? null;
        $this->assertNotNull($lastCall, 'Translation call should be recorded for commit query failures.');
        $this->assertSame('liens-morts-detector-jlg', $lastCall['domain']);
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

    public function test_blc_perform_check_sends_summary_email_when_recipients_configured(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_notification_recipients'] = 'admin@example.com, second@example.com';
        $this->currentHour = '07';

        $GLOBALS['wp_query_queue'][] = [
            'posts'        => [],
            'max_num_pages' => 0,
        ];

        $wpdb->getVarResults = [0];
        $wpdb->summaryCounts = ['link' => 5];

        blc_perform_check(0, false);

        $this->assertCount(1, $this->sentEmails, 'A summary email should be sent when recipients are configured.');
        $email = $this->sentEmails[0];
        $this->assertSame(['admin@example.com', 'second@example.com'], $email['to']);
        $this->assertStringContainsString('[Example Blog]', $email['subject']);
        $this->assertStringContainsString('Liens cassés détectés : 5', $email['message']);
        $this->assertStringContainsString('admin.php?page=blc-dashboard', $email['message']);
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

        $this->currentMysqlTime = '2024-01-02 03:04:05';

        blc_perform_check(0, false);

        $this->assertCount(1, $this->httpRequests, 'Only the non-excluded URL should be requested.');
        $this->assertSame('http://allowed.com/bad', $this->httpRequests[0]['url']);
        $this->assertSame('HEAD', $this->httpRequests[0]['method']);

        $this->assertCount(1, $wpdb->inserted, 'Only one broken link should be inserted.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('wp_blc_broken_links', $insert['table']);
        $this->assertSame('http://allowed.com/bad', $insert['data']['url']);
        $this->assertSame('link', $insert['data']['type']);
        $this->assertSame(404, $insert['data']['http_status']);
        $this->assertSame('2024-01-02 03:04:05', $insert['data']['last_checked_at']);
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

    public function test_blc_perform_check_skips_upload_checks_when_wp_upload_dir_unavailable(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_debug_mode'] = true;

        Functions\when('wp_upload_dir')->alias(function () {
            return [
                'baseurl' => false,
                'basedir' => false,
            ];
        });

        $post = (object) [
            'ID' => 512,
            'post_title' => 'Unavailable Uploads',
            'post_content' => '<a href="https://example.com/wp-content/uploads/2024/05/missing.pdf">Download</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        try {
            blc_perform_check(0, false);
        } finally {
            Functions\when('wp_upload_dir')->alias(function () {
                return [
                    'baseurl' => 'https://example.com/wp-content/uploads',
                    'basedir' => sys_get_temp_dir() . '/uploads-test',
                ];
            });
        }

        $this->assertCount(
            0,
            $wpdb->inserted,
            'Links should not be marked broken when uploads directory information is unavailable.'
        );

        $upload_logs = array_filter(
            $this->errorLogs,
            static fn(string $message) => strpos($message, 'wp_upload_dir() unavailable during link scan') !== false
        );
        $this->assertNotEmpty($upload_logs, 'A debug log should mention the unavailable uploads directory.');
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

    public function test_blc_perform_check_reuses_safe_host_cache_within_execution(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_scan_method'] = 'precise';

        $perHostLookupCalls = [];

        Functions\when('dns_get_record')->alias(function (string $hostname, ?int $type = null) use (&$perHostLookupCalls) {
            $normalized = blc_normalize_remote_host($hostname);
            $perHostLookupCalls[$normalized] = ($perHostLookupCalls[$normalized] ?? 0) + 1;

            if ($perHostLookupCalls[$normalized] > 6) {
                return [];
            }

            return [
                ['ip' => '93.184.216.34'],
                ['ipv6' => '2001:4860:4860::8888'],
            ];
        });

        Functions\when('gethostbynamel')->alias(function (string $hostname) use (&$perHostLookupCalls) {
            $normalized = blc_normalize_remote_host($hostname);
            $perHostLookupCalls[$normalized] = ($perHostLookupCalls[$normalized] ?? 0) + 1;

            if ($perHostLookupCalls[$normalized] > 6) {
                return [];
            }

            return ['93.184.216.34'];
        });

        $post = (object) [
            'ID' => 777,
            'post_title' => 'Duplicate Host',
            'post_content' => '<a href="http://Cache.example/first">One</a><a href="http://cache.example/second">Two</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 0,
        ];

        $this->setHttpResponse('HEAD', 'http://Cache.example/first', ['response' => ['code' => 200]]);
        $this->setHttpResponse('HEAD', 'http://cache.example/first', ['response' => ['code' => 200]]);
        $this->setHttpResponse('HEAD', 'http://cache.example/second', ['response' => ['code' => 200]]);

        blc_perform_check(0, false);

        $normalizedHost = blc_normalize_remote_host('cache.example');
        $lookupCount = $perHostLookupCalls[$normalizedHost] ?? 0;

        $this->assertGreaterThan(0, $lookupCount, 'The host should be resolved at least once.');
        $this->assertLessThanOrEqual(6, $lookupCount, 'Cached host results must prevent duplicate resolutions within the same run.');
        $this->assertCount(0, $wpdb->inserted, 'Safe hosts should not be flagged as broken when cache is reused.');
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

    public function test_blc_perform_check_fast_mode_handles_head_method_not_allowed(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_scan_method'] = 'fast';

        $post = (object) [
            'ID' => 44,
            'post_title' => 'Fast Head Fallback Post',
            'post_content' => '<a href="http://fast.example.com/no-head">Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://fast.example.com/no-head', ['response' => ['code' => 405]]);
        $this->setHttpResponse('GET', 'http://fast.example.com/no-head', ['response' => ['code' => 200]]);

        blc_perform_check(0, false);

        $this->assertCount(2, $this->httpRequests, 'Fast mode should fall back to GET when HEAD is not allowed.');
        $this->assertSame('HEAD', $this->httpRequests[0]['method']);
        $this->assertSame('GET', $this->httpRequests[1]['method']);
        $this->assertSame('http://fast.example.com/no-head', $this->httpRequests[1]['url']);

        $this->assertCount(0, $wpdb->inserted, 'HEAD 405 responses must not be recorded as broken links in fast mode.');
    }

    public function test_blc_perform_check_fast_mode_does_not_mark_broken_when_head_fallback_fails(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_scan_method'] = 'fast';

        $post = (object) [
            'ID' => 45,
            'post_title' => 'Fast Head Fallback Failure Post',
            'post_content' => '<a href="http://fast.example.com/failing-head">Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://fast.example.com/failing-head', ['response' => ['code' => 405]]);
        $this->setHttpResponse('GET', 'http://fast.example.com/failing-head', new \WP_Error('http_request_failed', 'GET fallback failed.'));

        blc_perform_check(0, false);

        $this->assertCount(2, $this->httpRequests, 'Fast mode should still attempt a GET fallback when HEAD is not allowed.');
        $this->assertSame('HEAD', $this->httpRequests[0]['method']);
        $this->assertSame('GET', $this->httpRequests[1]['method']);
        $this->assertCount(0, $wpdb->inserted, 'Failed GET fallback after HEAD 405 must not create broken link entries in fast mode.');
    }

    public function test_blc_perform_check_injects_configured_request_timeouts(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_head_request_timeout'] = '7.5';
        $this->options['blc_get_request_timeout'] = '120';

        $post = (object) [
            'ID' => 321,
            'post_title' => 'Custom Timeouts',
            'post_content' => '<a href="http://example.com/custom-timeouts">Timeouts</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://example.com/custom-timeouts', ['response' => ['code' => 405]]);
        $this->setHttpResponse('GET', 'http://example.com/custom-timeouts', ['response' => ['code' => 200]]);

        blc_perform_check(0, false);

        $this->assertCount(2, $this->httpRequests, 'HEAD followed by GET should be performed when HEAD is not allowed.');

        $headRequest = $this->httpRequests[0];
        $this->assertSame(7.5, $headRequest['args']['timeout']);

        $getRequest = $this->httpRequests[1];
        $this->assertSame(60.0, $getRequest['args']['timeout']);
    }

    public function test_blc_perform_check_enforces_timeout_boundaries(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_head_request_timeout'] = '0.25';
        $this->options['blc_get_request_timeout'] = '600';

        $post = (object) [
            'ID' => 654,
            'post_title' => 'Bounded Timeouts',
            'post_content' => '<a href="http://example.com/bounded-timeouts">Timeouts</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://example.com/bounded-timeouts', ['response' => ['code' => 405]]);
        $this->setHttpResponse('GET', 'http://example.com/bounded-timeouts', ['response' => ['code' => 200]]);

        blc_perform_check(0, false);

        $this->assertCount(2, $this->httpRequests, 'HEAD followed by GET should be performed when HEAD is not allowed.');

        $headRequest = $this->httpRequests[0];
        $this->assertSame(1.0, $headRequest['args']['timeout']);

        $getRequest = $this->httpRequests[1];
        $this->assertSame(60.0, $getRequest['args']['timeout']);
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

        $previousLastCheckTime = $this->options['blc_last_check_time'];

        blc_perform_check(0, false);

        $this->assertNotEmpty($GLOBALS['wp_query_last_args'], 'WP_Query should be instantiated for incremental scans.');
        $lastArgs = end($GLOBALS['wp_query_last_args']);
        $this->assertIsArray($lastArgs, 'WP_Query arguments should be an array.');

        $expectedThreshold = gmdate('Y-m-d H:i:s', $previousLastCheckTime);
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

        $previousLastCheckTime = $this->options['blc_last_check_time'];

        blc_perform_check(0, false);

        $this->assertNotEmpty($this->httpRequests, 'Incremental scans should request links from recently modified posts.');
        $this->assertSame('http://example.org/missing', $this->httpRequests[0]['url']);

        $this->assertCount(1, $wpdb->inserted, 'A broken link detected during an incremental scan should be recorded.');

        $this->assertNotEmpty($GLOBALS['wp_query_last_args'], 'WP_Query should be called to fetch recently modified posts.');
        $lastArgs = end($GLOBALS['wp_query_last_args']);
        $this->assertIsArray($lastArgs, 'WP_Query arguments should be an array for incremental scans.');

        $expectedThreshold = gmdate('Y-m-d H:i:s', $previousLastCheckTime);
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
        $previousLastCheckTime = $this->options['blc_last_check_time'];

        blc_perform_check(0, false);

        $this->assertNotEmpty(
            $this->httpRequests,
            'Incremental scans must request links from posts modified after the previous run even with positive timezone offsets.'
        );
        $this->assertSame('http://example.org/fail', $this->httpRequests[0]['url']);

        $this->assertNotEmpty($GLOBALS['wp_query_last_args'], 'WP_Query should run during the incremental scan.');
        $queryArgs = end($GLOBALS['wp_query_last_args']);
        $this->assertIsArray($queryArgs, 'WP_Query arguments should be available for assertions.');

        $expectedThreshold = gmdate('Y-m-d H:i:s', $previousLastCheckTime);
        $this->assertArrayHasKey('date_query', $queryArgs, 'Incremental scans must filter posts using GMT thresholds.');
        $this->assertSame('post_modified_gmt', $queryArgs['date_query'][0]['column']);
        $this->assertSame(
            $expectedThreshold,
            $queryArgs['date_query'][0]['after'],
            'The GMT threshold should rely on the UTC timestamp recorded after the previous run.'
        );
    }

    public function test_blc_perform_check_limits_queries_to_public_post_types(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->publicPostTypes = ['portfolio', 'case-study'];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [],
            'max_num_pages' => 1,
        ];

        blc_perform_check(0, true);

        $this->assertNotEmpty($GLOBALS['wp_query_last_args'], 'WP_Query should run during scans.');
        $args = end($GLOBALS['wp_query_last_args']);
        $this->assertNotEmpty($this->getPostTypesCalls, 'The scan should request the list of public post types.');
        $lastPostTypeCall = end($this->getPostTypesCalls);
        $this->assertSame(['public' => true], $lastPostTypeCall['args']);
        $this->assertSame('names', $lastPostTypeCall['output']);
        $this->assertSame('and', $lastPostTypeCall['operator']);
        $this->assertSame(
            $this->publicPostTypes,
            $args['post_type'] ?? null,
            'Link scans must restrict queries to the list of public post types.'
        );
    }

    public function test_blc_perform_check_uses_configured_post_statuses(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_post_statuses'] = ['publish', 'draft'];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [],
            'max_num_pages' => 1,
        ];

        blc_perform_check(0, true);

        $this->assertNotEmpty($GLOBALS['wp_query_last_args'], 'WP_Query should run when performing scans.');
        $args = end($GLOBALS['wp_query_last_args']);
        $this->assertSame(
            ['publish', 'draft'],
            $args['post_status'] ?? null,
            'Configured post statuses must be forwarded to WP_Query.'
        );
    }

    public function test_blc_perform_check_falls_back_to_post_type_when_public_list_is_empty(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->publicPostTypes = [];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [],
            'max_num_pages' => 1,
        ];

        blc_perform_check(0, true);

        $this->assertNotEmpty($GLOBALS['wp_query_last_args'], 'WP_Query should run even when no public post types are available.');
        $args = end($GLOBALS['wp_query_last_args']);
        $this->assertSame(
            ['post'],
            $args['post_type'] ?? null,
            'Link scans should default to the "post" type when the public list is empty.'
        );
    }

    public function test_blc_perform_image_check_falls_back_to_post_type_when_public_list_is_empty(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->publicPostTypes = [];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [],
            'max_num_pages' => 1,
        ];

        blc_perform_image_check(0, true);

        $this->assertNotEmpty($GLOBALS['wp_query_last_args'], 'WP_Query should still run with a fallback post type.');
        $args = end($GLOBALS['wp_query_last_args']);
        $this->assertSame(
            ['post'],
            $args['post_type'] ?? null,
            'Image scans should default to the "post" type when the public list is empty.'
        );
    }

    public function test_blc_perform_image_check_sends_summary_email_when_recipients_configured(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->options['blc_notification_recipients'] = 'media@example.com';

        $GLOBALS['wp_query_queue'][] = [
            'posts'        => [],
            'max_num_pages' => 0,
        ];

        $wpdb->getVarResults = [];
        $wpdb->summaryCounts = ['image' => 3];

        blc_perform_image_check(0, true);

        $this->assertCount(1, $this->sentEmails, 'Image scans should send a summary when recipients are configured.');
        $email = $this->sentEmails[0];
        $this->assertSame(['media@example.com'], $email['to']);
        $this->assertStringContainsString('analyse des images', strtolower($email['subject']));
        $this->assertStringContainsString('Images cassées détectées : 3', $email['message']);
        $this->assertStringContainsString('admin.php?page=blc-images-dashboard', $email['message']);
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

    public function test_blc_perform_check_avoids_duplicate_requests_for_identical_urls(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $firstPost = (object) [
            'ID' => 901,
            'post_title' => 'Duplicate Link A',
            'post_content' => '<a href="http://duplicate.example.com/broken">Broken</a>',
        ];

        $secondPost = (object) [
            'ID' => 902,
            'post_title' => 'Duplicate Link B',
            'post_content' => '<a href="http://duplicate.example.com/broken">Broken Again</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$firstPost, $secondPost],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://duplicate.example.com/broken', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertCount(1, $this->httpRequests, 'Duplicate URLs should reuse the cached HTTP response.');
        $this->assertSame('HEAD', $this->httpRequests[0]['method']);
        $this->assertSame('http://duplicate.example.com/broken', $this->httpRequests[0]['url']);

        $this->assertCount(2, $wpdb->inserted, 'Each occurrence of the broken link should be recorded.');
        $this->assertSame([], $this->scheduledEvents, 'Definitive errors should not schedule retries.');
        $this->assertSame(
            $this->utcNow,
            $this->updatedOptions['blc_last_check_time'] ?? null,
            'Completed scans must update the last check timestamp when no retries are scheduled.'
        );
    }

    public function test_blc_perform_check_treats_timeout_errors_as_temporary_and_schedules_retry(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 903,
            'post_title' => 'Timeout Link',
            'post_content' => '<a href="http://timeout.example.com/slow">Slow Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $timeoutError = new \WP_Error('http_request_failed', 'Operation timed out');
        $this->setHttpResponse('HEAD', 'http://timeout.example.com/slow', $timeoutError);
        $this->setHttpResponse('GET', 'http://timeout.example.com/slow', $timeoutError);

        blc_perform_check(0, false);

        $this->assertCount(2, $this->httpRequests, 'Timeouts should attempt both HEAD and GET before retrying.');
        $this->assertSame('HEAD', $this->httpRequests[0]['method']);
        $this->assertSame('GET', $this->httpRequests[1]['method']);
        $this->assertCount(0, $wpdb->inserted, 'Temporary network errors must not be recorded as broken links.');
        $this->assertCount(1, $this->scheduledEvents, 'A retry should be scheduled when a temporary error occurs.');
        $this->assertSame('blc_check_batch', $this->scheduledEvents[0]['hook']);
        $this->assertArrayNotHasKey(
            'blc_last_check_time',
            $this->updatedOptions,
            'Scans that schedule retries should postpone updating the last check timestamp.'
        );
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

    public function test_blc_perform_check_truncates_long_url_host_before_inserting(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $long_host_segments = [
            str_repeat('a', 63),
            str_repeat('b', 63),
            str_repeat('c', 63),
            'com',
        ];
        $long_host = implode('.', $long_host_segments);
        $url = 'https://' . $long_host . '/resource';

        $post = (object) [
            'ID' => 417,
            'post_title' => 'Very Long Host',
            'post_content' => '<a href="' . $url . '">Broken Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', $url, ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $this->assertCount(1, $wpdb->inserted, 'A failing external link with a long host must be recorded.');
        $insert = $wpdb->inserted[0];

        $expected_host = substr($long_host, 0, 191);
        $this->assertSame($expected_host, $insert['data']['url_host'], 'URL host should be truncated to the storage column length.');
        $this->assertSame(191, strlen($insert['data']['url_host']), 'URL host should not exceed the varchar(191) column.');
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

        $wpdb->selectedRows[] = [
            'id' => 1,
            'post_id' => $post_id,
            'url' => '//cdn.example.com/foo',
            'anchor' => '',
            'post_title' => '',
            'occurrence_index' => 0,
        ];

        $_POST = [
            'post_id'          => (string) $post_id,
            'row_id'           => '1',
            'occurrence_index' => '0',
            'old_url'          => '//cdn.example.com/foo',
            'new_url'          => 'https://cdn.example.com/bar',
            '_ajax_nonce'      => 'nonce',
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
        $this->assertSame(['id' => 1], $wpdb->deleted[0]['where']);
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

        $wpdb->selectedRows[] = [
            'id' => 2,
            'post_id' => $post_id,
            'url' => 'https://example.com/old',
            'anchor' => '',
            'post_title' => '',
            'occurrence_index' => 0,
        ];

        $_POST = [
            'post_id'          => (string) $post_id,
            'row_id'           => '2',
            'occurrence_index' => '0',
            'old_url'          => 'https://example.com/old',
            'new_url'          => 'section/page.html',
            '_ajax_nonce'      => 'nonce',
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
        $this->assertSame(['id' => 2], $wpdb->deleted[0]['where']);
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

        $markQueryFound = false;
        $deleteQueryFound = false;
        foreach ($wpdb->queries as $query) {
            $sql = $query['sql'] ?? '';
            if (strpos($sql, 'SET scan_run_id') !== false && strpos($sql, "type = 'link'") !== false) {
                $markQueryFound = true;
            }
            if (strpos($sql, 'DELETE FROM wp_blc_broken_links') !== false && strpos($sql, 'scan_run_id') !== false && strpos($sql, "type = 'link'") !== false) {
                $deleteQueryFound = true;
            }
        }
        $this->assertTrue($markQueryFound, 'Existing link rows should be staged before scheduling the next batch.');
        $this->assertTrue($deleteQueryFound, 'Stale link rows must be purged for completed posts.');
        $this->assertCount(0, $wpdb->inserted, 'No broken links should be recorded for healthy responses.');
        $this->assertCount(1, $this->scheduledEvents, 'Next batch should be scheduled.');
        $event = $this->scheduledEvents[0];
        $this->assertSame($this->utcNow + 60, $event['timestamp']);
        $this->assertSame('blc_check_batch', $event['hook']);
        $this->assertSame([1, true, false], $event['args']);
        $cacheKeyOptions = $this->updatedOptions;
        unset($cacheKeyOptions['blc_active_link_scan_key']);
        $this->assertSame([], $cacheKeyOptions, 'Last check time should not be updated before the final batch.');
    }

    public function test_blc_perform_check_logs_failure_when_next_batch_schedule_fails(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->scheduleSingleEventResults = [false];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [],
            'max_num_pages' => 2,
        ];

        blc_perform_check(0, false);

        $this->assertCount(0, $this->scheduledEvents, 'No successful scheduling should be recorded when cron fails.');
        $this->assertCount(1, $this->failedScheduleAttempts, 'The failed scheduling attempt should be tracked.');

        $attempt = $this->failedScheduleAttempts[0];
        $this->assertSame('blc_check_batch', $attempt['hook']);
        $this->assertSame([1, false, false], $attempt['args']);

        $this->assertNotEmpty($this->errorLogs, 'An error log entry should be recorded for scheduling failures.');
        $this->assertStringContainsString('Failed to schedule next link batch #1', $this->errorLogs[0]);

        $this->assertNotEmpty($this->triggeredActions, 'A failure hook should be triggered when scheduling fails.');
        $action = $this->triggeredActions[0];
        $this->assertSame('blc_check_batch_schedule_failed', $action['hook']);
        $this->assertSame([1, false, false, 'next_batch'], $action['args']);
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

    public function test_blc_perform_check_marks_rows_before_processing(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 501,
            'post_title' => 'Marked Post',
            'post_content' => '<a href="http://mark.example.com/broken">Broken Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://mark.example.com/broken', ['response' => ['code' => 404]]);

        blc_perform_check(0, false);

        $markQueryFound = false;
        $deleteQueryFound = false;
        foreach ($wpdb->queries as $query) {
            $sql = $query['sql'] ?? '';
            if (strpos($sql, 'SET scan_run_id') !== false && strpos($sql, "type = 'link'") !== false) {
                $markQueryFound = true;
            }
            if (strpos($sql, 'DELETE FROM wp_blc_broken_links') !== false && strpos($sql, 'scan_run_id') !== false && strpos($sql, "type = 'link'") !== false) {
                $deleteQueryFound = true;
            }
        }

        $this->assertTrue($markQueryFound, 'Existing link rows should be staged before recomputation.');
        $this->assertTrue($deleteQueryFound, 'Stale link rows must be purged after successful recomputation.');
    }

    public function test_blc_perform_check_restores_markers_when_interrupted(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 502,
            'post_title' => 'Interrupted Post',
            'post_content' => '<a href="http://interrupt.example.com/broken">Broken Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        Functions\when('wp_remote_head')->alias(function () {
            throw new \RuntimeException('link interruption');
        });
        Functions\when('wp_safe_remote_head')->alias(function () {
            throw new \RuntimeException('link interruption');
        });
        Functions\when('wp_safe_remote_get')->alias(function () {
            throw new \RuntimeException('link interruption');
        });

        $this->wpResetPostdataCalls = 0;
        try {
            blc_perform_check(0, false);
            $this->fail('Expected interruption to bubble up.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('link interruption', $exception->getMessage());
        }

        $this->assertSame(1, $this->wpResetPostdataCalls, 'Postdata should be reset after an interrupted link scan.');

        $restoreQueryFound = false;
        $deleteQueryFound = false;
        foreach ($wpdb->queries as $query) {
            $sql = $query['sql'] ?? '';
            if (strpos($sql, 'SET scan_run_id = NULL') !== false && strpos($sql, "type = 'link'") !== false) {
                $restoreQueryFound = true;
            }
            if (strpos($sql, 'DELETE FROM wp_blc_broken_links') !== false && strpos($sql, 'scan_run_id') !== false && strpos($sql, "type = 'link'") !== false) {
                $deleteQueryFound = true;
            }
        }

        $this->assertTrue($restoreQueryFound, 'Marked link rows should be restored after an interruption.');
        $this->assertFalse($deleteQueryFound, 'Stale link rows must not be purged when the batch fails.');

        Functions\when('wp_remote_head')->alias(function (string $url, array $args = []) {
            return $this->mockHttpRequest('HEAD', $url, $args);
        });
        Functions\when('wp_safe_remote_head')->alias(function (string $url, array $args = []) {
            return $this->mockHttpRequest('HEAD', $url, $args);
        });
        Functions\when('wp_safe_remote_get')->alias(function (string $url, array $args = []) {
            return $this->mockHttpRequest('GET', $url, $args);
        });
    }

    public function test_blc_perform_check_returns_wp_error_when_commit_fails(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();
        $wpdb->queryResults = [true, true, false, true];
        $wpdb->getVarResults = [0, 128];
        $wpdb->last_error = 'Simulated failure';

        $post = (object) [
            'ID' => 503,
            'post_title' => 'Commit Failure Post',
            'post_content' => '<a href="http://failure.example.com/broken">Broken Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://failure.example.com/broken', ['response' => ['code' => 404]]);

        $this->wpResetPostdataCalls = 0;
        $result = blc_perform_check(0, false);

        $this->assertInstanceOf(\WP_Error::class, $result, 'Commit failures should surface as WP_Error.');
        $this->assertSame('Simulated failure', $result->get_error_message(), 'Database error should be propagated.');
        $this->assertSame(1, $this->wpResetPostdataCalls, 'Postdata should be reset after a failed link scan.');

        $restoreQueryFound = false;
        foreach ($wpdb->queries as $query) {
            $sql = $query['sql'] ?? '';
            if (strpos($sql, 'SET scan_run_id = NULL') !== false && strpos($sql, "type = 'link'") !== false) {
                $restoreQueryFound = true;
                break;
            }
        }

        $this->assertTrue($restoreQueryFound, 'Markers should be restored after a failed commit.');
    }

    public function test_blc_perform_check_rolls_back_pending_rows_when_commit_fails(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();
        $wpdb->queryResults = [true, true, false, true];
        $wpdb->getVarResults = [0, 256];
        $wpdb->last_error = 'Simulated failure';

        $cacheKey = blc_get_dataset_size_cache_key('link');
        if ($cacheKey !== '') {
            $this->options[$cacheKey] = 0;
        }

        $post = (object) [
            'ID' => 504,
            'post_title' => 'Rollback Post',
            'post_content' => '<a href="http://rollback.example.com/broken">Broken Link</a>',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        $this->setHttpResponse('HEAD', 'http://rollback.example.com/broken', ['response' => ['code' => 404]]);

        $result = blc_perform_check(0, false);

        $this->assertInstanceOf(\WP_Error::class, $result, 'Commit failures should surface as WP_Error.');
        $this->assertSame('Simulated failure', $result->get_error_message(), 'Database error should be propagated.');

        $table_name = $wpdb->prefix . 'blc_broken_links';
        $remaining_rows = array_filter(
            $wpdb->rows,
            static fn($row) => ($row['table'] ?? '') === $table_name
        );
        $this->assertSame([], $remaining_rows, 'Pending link rows should be removed when the commit fails.');
        $this->assertNotEmpty($wpdb->deleted, 'Pending rows must be deleted after a failed commit.');

        if ($cacheKey !== '') {
            $this->assertSame(0, $this->options[$cacheKey] ?? null, 'Dataset footprint should be restored after rollback.');
        }
    }

    public function test_blc_perform_image_check_limits_queries_to_public_post_types(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->publicPostTypes = ['portfolio', 'case-study'];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [],
            'max_num_pages' => 1,
        ];

        blc_perform_image_check(0, true);

        $this->assertNotEmpty($GLOBALS['wp_query_last_args'], 'WP_Query should be executed during image scans.');
        $args = end($GLOBALS['wp_query_last_args']);
        $this->assertSame(
            $this->publicPostTypes,
            $args['post_type'] ?? null,
            'Image scans must restrict queries to the list of public post types.'
        );
    }

    public function test_blc_perform_image_check_restores_markers_when_interrupted(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 504,
            'post_title' => 'Interrupted Image Post',
            'post_content' => '<img src="https://example.com/wp-content/uploads/2024/05/missing-image.jpg" />',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        Functions\when('get_permalink')->alias(function () {
            throw new \RuntimeException('image interruption');
        });

        $this->wpResetPostdataCalls = 0;
        try {
            blc_perform_image_check(0, true);
            $this->fail('Expected interruption during image scan.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('image interruption', $exception->getMessage());
        }

        $this->assertSame(1, $this->wpResetPostdataCalls, 'Postdata should be reset after an interrupted image scan.');

        $restoreQueryFound = false;
        $deleteQueryFound = false;
        foreach ($wpdb->queries as $query) {
            $sql = $query['sql'] ?? '';
            if (strpos($sql, 'SET scan_run_id = NULL') !== false && strpos($sql, "type = 'image'") !== false) {
                $restoreQueryFound = true;
            }
            if (strpos($sql, 'DELETE FROM wp_blc_broken_links') !== false && strpos($sql, 'scan_run_id') !== false && strpos($sql, "type = 'image'") !== false) {
                $deleteQueryFound = true;
            }
        }

        $this->assertTrue($restoreQueryFound, 'Image markers should be restored after an interruption.');
        $this->assertFalse($deleteQueryFound, 'Stale image rows must not be purged when the batch fails.');

        Functions\when('get_permalink')->alias(function ($post = null) {
            if (is_object($post) && isset($post->ID)) {
                return 'https://example.com/post-' . $post->ID . '/';
            }

            if (is_numeric($post)) {
                return 'https://example.com/post-' . ((int) $post) . '/';
            }

            return 'https://example.com/post/';
        });
    }

    public function test_blc_is_image_scan_lock_active_treats_non_positive_timeout_as_expired(): void
    {
        $state = [
            'token'     => 'lock-token',
            'locked_at' => $this->utcNow - 30,
        ];

        $this->assertFalse(
            blc_is_image_scan_lock_active($state, 0),
            'A zero timeout should be treated as an expired image scan lock.'
        );

        $this->assertFalse(
            blc_is_image_scan_lock_active($state, -5),
            'Negative timeouts should force the image scan lock to be considered expired.'
        );

        $this->assertTrue(
            blc_is_image_scan_lock_active($state, 60),
            'Positive timeouts should keep recently refreshed locks active.'
        );
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

        $markQueryFound = false;
        $deleteQueryFound = false;
        foreach ($wpdb->queries as $query) {
            $sql = $query['sql'] ?? '';
            if (strpos($sql, 'SET scan_run_id') !== false && strpos($sql, "type = 'image'") !== false) {
                $markQueryFound = true;
            }
            if (strpos($sql, 'DELETE FROM wp_blc_broken_links') !== false && strpos($sql, 'scan_run_id') !== false && strpos($sql, "type = 'image'") !== false) {
                $deleteQueryFound = true;
            }
        }

        $this->assertTrue($markQueryFound, 'Image results should be staged before recomputation.');
        $this->assertTrue($deleteQueryFound, 'Stale image rows must be purged after successful recomputation.');
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
        $this->assertArrayHasKey('blc_image_scan_lock', $this->updatedOptions, 'Image scan should acquire a concurrency lock.');
        $this->assertArrayHasKey('blc_image_scan_lock_token', $this->updatedOptions, 'Lock token should be stored for subsequent batches.');
        $lock_state = $this->updatedOptions['blc_image_scan_lock'];
        $this->assertIsArray($lock_state);
        $this->assertArrayHasKey('token', $lock_state);
        $this->assertArrayHasKey('locked_at', $lock_state);
        $this->assertSame($lock_state['token'], $this->updatedOptions['blc_image_scan_lock_token']);
    }

    public function test_blc_is_image_scan_lock_active_expires_zero_or_negative_timeout(): void
    {
        $state = [
            'token'     => 'token-zero-timeout',
            'locked_at' => $this->utcNow,
        ];

        $this->assertFalse(
            blc_is_image_scan_lock_active($state, 0),
            'Zero timeout must be treated as an expired lock.'
        );

        $this->assertFalse(
            blc_is_image_scan_lock_active($state, -15),
            'Negative timeouts should also expire the lock.'
        );
    }

    public function test_blc_perform_image_check_reacquires_lock_when_timeout_is_zero(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $post = (object) [
            'ID' => 42,
            'post_title' => 'Batch Continuation',
            'post_content' => '<img src="https://example.com/wp-content/uploads/2024/05/photo.jpg" />',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 3,
        ];

        $stale_token = 'expired-lock';
        $this->options['blc_image_scan_lock'] = [
            'token'     => $stale_token,
            'locked_at' => $this->utcNow - 500,
        ];
        $this->options['blc_image_scan_lock_token'] = $stale_token;

        Functions\when('apply_filters')->alias(function (string $hook, $value, ...$args) {
            if ($hook === 'blc_image_scan_lock_timeout') {
                return 0;
            }

            return $value;
        });

        blc_perform_image_check(1, true);

        $this->assertArrayHasKey('blc_image_scan_lock', $this->updatedOptions, 'Reacquiring the image lock should persist the new state.');
        $this->assertArrayHasKey('blc_image_scan_lock_token', $this->options, 'A helper lock token should remain available after reacquiring.');
        $this->assertNotSame($stale_token, $this->options['blc_image_scan_lock_token'], 'Expired lock token should be replaced when timeout is zero.');
        $this->assertGreaterThan(0, strlen((string) $this->options['blc_image_scan_lock_token']), 'A non-empty lock token should be stored after reacquiring.');

        $lock_state = $this->updatedOptions['blc_image_scan_lock'];
        $this->assertSame($this->utcNow, $lock_state['locked_at'], 'The refreshed lock timestamp should use the current time.');

        $this->assertCount(1, $this->scheduledEvents, 'Follow-up batch should still be scheduled after reacquiring the lock.');
        $event = $this->scheduledEvents[0];
        $this->assertSame($this->utcNow + 60, $event['timestamp']);
        $this->assertSame('blc_check_image_batch', $event['hook']);
        $this->assertSame([2, true], $event['args']);
    }

    public function test_blc_perform_image_check_releases_lock_and_returns_error_when_scheduling_fails(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $this->scheduleSingleEventResults = [false];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [],
            'max_num_pages' => 2,
        ];

        $this->wpResetPostdataCalls = 0;
        $result = blc_perform_image_check(0, true);

        $this->assertInstanceOf(\WP_Error::class, $result, 'A scheduling failure should surface as a WP_Error.');
        $this->assertSame('blc_image_schedule_failed', $result->get_error_code());
        $this->assertStringContainsString('Failed to schedule next image batch #1', $result->get_error_message());
        $this->assertSame(1, $this->wpResetPostdataCalls, 'Postdata should be reset after a failed image scan.');

        $this->assertCount(0, $this->scheduledEvents, 'No successful scheduling should be recorded when cron fails.');
        $this->assertCount(1, $this->failedScheduleAttempts, 'The failed scheduling attempt should be tracked.');

        $attempt = $this->failedScheduleAttempts[0];
        $this->assertSame('blc_check_image_batch', $attempt['hook']);
        $this->assertSame([1, true], $attempt['args']);

        $this->assertArrayNotHasKey('blc_image_scan_lock', $this->options, 'The image scan lock should be released when scheduling fails.');
        $this->assertArrayNotHasKey('blc_image_scan_lock_token', $this->options, 'The helper lock token option should be cleared when scheduling fails.');

        $this->assertNotEmpty($this->errorLogs, 'An error should be logged when scheduling fails.');
        $this->assertStringContainsString('Failed to schedule next image batch #1', $this->errorLogs[0]);

        $this->assertNotEmpty($this->triggeredActions, 'A failure hook should be triggered when scheduling fails.');
        $action = $this->triggeredActions[0];
        $this->assertSame('blc_check_image_batch_schedule_failed', $action['hook']);
        $this->assertSame([1, true, 'next_batch'], $action['args']);
    }

    public function test_blc_perform_image_check_truncates_long_url_host_before_inserting(): void
    {
        global $wpdb;
        $wpdb = $this->createWpdbStub();

        $long_host_segments = [
            str_repeat('a', 63),
            str_repeat('b', 63),
            str_repeat('c', 63),
            'com',
        ];
        $long_host = implode('.', $long_host_segments);
        $long_base = 'https://' . $long_host;
        $long_base_with_slash = rtrim($long_base, '/') . '/';

        Functions\when('home_url')->alias(function ($path = '', $scheme = null) use ($long_base) {
            $base = $long_base;
            if ($path !== '') {
                return rtrim($base, '/') . '/' . ltrim($path, '/');
            }

            return $base;
        });
        Functions\when('site_url')->alias(function ($path = '', $scheme = null) use ($long_base) {
            $base = $long_base;
            if ($path !== '') {
                return rtrim($base, '/') . '/' . ltrim($path, '/');
            }

            return $base;
        });
        Functions\when('get_permalink')->alias(function ($post = null) use ($long_base_with_slash) {
            if (is_object($post) && isset($post->ID)) {
                return $long_base_with_slash . 'post-' . $post->ID . '/';
            }

            if (is_numeric($post)) {
                return $long_base_with_slash . 'post-' . ((int) $post) . '/';
            }

            return $long_base_with_slash . 'post/';
        });

        $uploads_dir = sys_get_temp_dir() . '/uploads-long-host-' . uniqid('', true);
        Functions\when('wp_upload_dir')->alias(function () use ($uploads_dir, $long_host) {
            return [
                'baseurl' => 'https://' . $long_host . '/wp-content/uploads',
                'basedir' => $uploads_dir,
            ];
        });

        $image_url = 'https://' . $long_host . '/wp-content/uploads/2024/05/missing.png';
        $post = (object) [
            'ID' => 718,
            'post_title' => 'Very Long Image Host',
            'post_content' => '<img src="' . $image_url . '" />',
        ];

        $GLOBALS['wp_query_queue'][] = [
            'posts' => [$post],
            'max_num_pages' => 1,
        ];

        blc_perform_image_check(0, true);

        $this->assertCount(1, $wpdb->inserted, 'Missing images hosted on long domains should be recorded.');
        $insert = $wpdb->inserted[0];

        $expected_host = substr($long_host, 0, 191);
        $this->assertSame($expected_host, $insert['data']['url_host'], 'Image host should be truncated to the storage column length.');
        $this->assertSame(191, strlen($insert['data']['url_host']), 'Image host should not exceed the varchar(191) column.');
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

        $this->currentMysqlTime = '2024-02-03 04:05:06';

        blc_perform_image_check(0, true);

        $this->assertCount(1, $wpdb->inserted, 'Missing CDN upload image should be recorded.');
        $insert = $wpdb->inserted[0];
        $this->assertSame('image', $insert['data']['type']);
        $this->assertSame('missing-cdn.jpg', $insert['data']['anchor']);
        $this->assertSame(104, $insert['data']['post_id']);
        $this->assertArrayHasKey('http_status', $insert['data']);
        $this->assertNull($insert['data']['http_status']);
        $this->assertSame('2024-02-03 04:05:06', $insert['data']['last_checked_at']);

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
