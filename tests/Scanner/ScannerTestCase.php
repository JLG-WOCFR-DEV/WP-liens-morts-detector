<?php

namespace {
    require_once __DIR__ . '/../../vendor/autoload.php';

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

    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }

    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/../..');
    }

}

namespace Tests\Scanner {

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

abstract class ScannerTestCase extends TestCase
{
    /** @var array<string, mixed> */
    protected array $options = [];

    /** @var array<string, array{value:mixed,expiration:int}> */
    protected array $transients = [];

    /** @var array<int, array{timestamp:int,hook:string,args:array}> */
    protected array $scheduledEvents = [];

    /** @var int */
    protected int $currentTime = 1_700_000_000;

    /** @var object|null */
    protected $wpdb;

    private static bool $bootstrapped = false;
    private static bool $pluginLoaded = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        require_once __DIR__ . '/../../vendor/autoload.php';
        require_once __DIR__ . '/../translation-stubs.php';

        self::$bootstrapped = true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!self::$pluginLoaded) {
            require_once __DIR__ . '/../../liens-morts-detector-jlg/includes/blc-utils.php';
            require_once __DIR__ . '/../../liens-morts-detector-jlg/includes/blc-scanner.php';
            self::$pluginLoaded = true;
        }

        $this->options = [];
        $this->transients = [];
        $this->scheduledEvents = [];
        $this->currentTime = 1_700_000_000;

        $this->wpdb = new class {
            public string $prefix = 'wp_';

            /** @var array<int, string> */
            public array $queries = [];

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function get_var($query)
            {
                $this->queries[] = (string) $query;
                return 0;
            }

            public function query($query)
            {
                $this->queries[] = (string) $query;
                return 0;
            }
        };

        $GLOBALS['wpdb'] = $this->wpdb;

        $this->stubWordPressFunctions();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    protected function setCurrentTime(int $timestamp): void
    {
        $this->currentTime = $timestamp;
    }

    private function stubWordPressFunctions(): void
    {
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $this->options[$name] ?? $default;
        });

        Functions\when('update_option')->alias(function ($name, $value, $autoload = null) {
            $this->options[$name] = $value;
            return true;
        });

        Functions\when('delete_option')->alias(function ($name) {
            unset($this->options[$name]);
            return true;
        });

        Functions\when('get_transient')->alias(function ($key) {
            $entry = $this->transients[$key] ?? false;
            if (is_array($entry) && array_key_exists('value', $entry)) {
                return $entry['value'];
            }

            return $entry;
        });

        Functions\when('set_transient')->alias(function ($key, $value, $expiration) {
            $this->transients[$key] = [
                'value'      => $value,
                'expiration' => (int) $expiration,
            ];
            return true;
        });

        Functions\when('delete_transient')->alias(function ($key) {
            unset($this->transients[$key]);
            return true;
        });

        Functions\when('apply_filters')->alias(fn($hook, $value, ...$args) => $value);
        Functions\when('do_action')->alias(function () {
        });

        Functions\when('home_url')->alias(function ($path = '', $scheme = null) {
            $path = (string) $path;
            if ($path === '') {
                return 'https://example.com';
            }

            return 'https://example.com/' . ltrim($path, '/');
        });

        Functions\when('site_url')->alias(function ($path = '', $scheme = null) {
            $path = (string) $path;
            if ($path === '') {
                return 'https://example.com';
            }

            return 'https://example.com/' . ltrim($path, '/');
        });

        Functions\when('admin_url')->alias(function ($path = '', $scheme = 'admin') {
            return 'https://example.com/wp-admin/' . ltrim((string) $path, '/');
        });

        Functions\when('wp_parse_url')->alias(fn($url, $component = -1) => parse_url($url, $component));
        Functions\when('trailingslashit')->alias(fn($value) => rtrim((string) $value, "/\\") . '/');
        Functions\when('wp_normalize_path')->alias(fn($path) => str_replace('\\', '/', (string) $path));

        Functions\when('wp_list_pluck')->alias(function ($input, $field) {
            $result = [];
            foreach ($input as $item) {
                if (is_array($item) && array_key_exists($field, $item)) {
                    $result[] = $item[$field];
                } elseif (is_object($item) && isset($item->$field)) {
                    $result[] = $item->$field;
                }
            }
            return $result;
        });

        Functions\when('wp_schedule_single_event')->alias(function ($timestamp, $hook, $args = []) {
            $this->scheduledEvents[] = [
                'timestamp' => (int) $timestamp,
                'hook'      => (string) $hook,
                'args'      => is_array($args) ? $args : [$args],
            ];
            return true;
        });

        Functions\when('wp_next_scheduled')->alias(fn($hook) => false);

        Functions\when('current_filter')->alias(fn() => '');

        Functions\when('time')->alias(fn() => $this->currentTime);
        Functions\when('current_time')->alias(function ($type, $gmt = 0) {
            $type = (string) $type;
            if ($type === 'timestamp') {
                return $this->currentTime;
            }
            if ($type === 'mysql') {
                return gmdate('Y-m-d H:i:s', $this->currentTime);
            }
            if ($type === 'H') {
                return gmdate('H', $this->currentTime);
            }

            return $this->currentTime;
        });

        Functions\when('wp_timezone')->alias(fn() => new \DateTimeZone('UTC'));
        Functions\when('wp_timezone_string')->alias(fn() => 'UTC');

        Functions\when('wp_safe_remote_head')->alias(fn($url, $args = []) => ['response' => ['code' => 200]]);
        Functions\when('wp_safe_remote_get')->alias(fn($url, $args = []) => ['response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->alias(function ($response) {
            if (is_array($response) && isset($response['response']['code'])) {
                return (int) $response['response']['code'];
            }
            return 0;
        });

        Functions\when('is_wp_error')->alias(fn($thing) => $thing instanceof \WP_Error);

        Functions\when('sanitize_text_field')->alias(function ($text) {
            if (is_scalar($text)) {
                return trim((string) $text);
            }

            return '';
        });

        if (!function_exists('sanitize_email')) {
            Functions\when('sanitize_email')->alias(function ($email) {
                if (!is_string($email)) {
                    return '';
                }

                return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
            });
        }

        if (!function_exists('is_email')) {
            Functions\when('is_email')->alias(function ($email) {
                return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            });
        }

        if (!function_exists('sanitize_key')) {
            Functions\when('sanitize_key')->alias(function ($key) {
                $key = strtolower(is_scalar($key) ? (string) $key : '');

                return preg_replace('/[^a-z0-9_\-]/', '', $key);
            });
        }

        Functions\when('maybe_unserialize')->alias(function ($value) {
            if (!is_string($value)) {
                return $value;
            }
            $unserialized = @unserialize($value);
            return $unserialized === false && $value !== 'b:0;' ? $value : $unserialized;
        });
        Functions\when('maybe_serialize')->alias(fn($value) => serialize($value));

        Functions\when('wp_generate_password')->alias(function ($length = 12) {
            $length = max(1, (int) $length);
            return substr(str_repeat('a', $length), 0, $length);
        });

        Functions\when('wp_upload_dir')->alias(fn() => [
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => '/var/www/html/wp-content/uploads',
            'error'   => false,
        ]);

        Functions\when('sys_getloadavg')->alias(fn() => [0.1, 0.1, 0.1]);

        Functions\when('wp_mail')->alias(function ($to, $subject, $message) {
            return true;
        });

        Functions\when('wp_unslash')->alias(fn($value) => $value);
        Functions\when('wp_json_encode')->alias(fn($value) => json_encode($value));

        Functions\when('wp_safe_redirect')->alias(function ($location, $status = 302) {
            return true;
        });

        Functions\when('wp_remote_retrieve_headers')->alias(fn($response) => []);
        Functions\when('wp_remote_retrieve_header')->alias(fn($response, $header) => null);
    }
}

}
