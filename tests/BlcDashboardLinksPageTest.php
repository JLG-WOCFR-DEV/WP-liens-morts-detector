<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BlcDashboardLinksPageTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $options = [];

    /**
     * @var object|null
     */
    private $previous_wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        if (!class_exists('WP_List_Table')) {
            require_once __DIR__ . '/stubs/WP_List_Table.php';
        }

        $this->options = [
            'blc_last_check_time' => 0,
        ];

        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

        $GLOBALS['wpdb'] = new class() {
            /** @var string */
            public $prefix = 'wp_';

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function get_var($query)
            {
                return 0;
            }

            public function get_row($query, $output = ARRAY_A)
            {
                return ['total' => 0, 'internal_count' => 0, 'external_count' => 0];
            }

            public function get_results($query, $output = ARRAY_A)
            {
                return [];
            }

            public function esc_like($text)
            {
                return $text;
            }
        };

        $test_case = $this;

        Functions\when('get_option')->alias(static function ($name, $default = false) use ($test_case) {
            return $test_case->getStoredOption((string) $name, $default);
        });
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('remove_query_arg')->alias(static fn($key, $url = null) => 'admin.php');
        Functions\when('add_query_arg')->alias(static function ($key, $value = null, $url = null) {
            $args = is_array($key) ? $key : [$key => $value];

            return 'admin.php?' . http_build_query($args);
        });
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_nonce_field')->alias(static function () {
            echo '';

            return '';
        });
        Functions\when('wp_kses')->alias(static fn($string) => $string);
        Functions\when('wp_kses_post')->alias(static fn($string) => $string);
        Functions\when('wp_unslash')->alias(static fn($value) => $value);
        Functions\when('sanitize_text_field')->alias(static fn($value) => is_scalar($value) ? (string) $value : '');
        Functions\when('number_format_i18n')->alias(static function ($number, $decimals = 0) {
            return number_format((float) $number, (int) $decimals);
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();

        if ($this->previous_wpdb !== null) {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
        }

        $_POST = [];
    }

    public function test_last_check_time_uses_site_timezone(): void
    {
        $timestamp = gmmktime(23, 0, 0, 12, 31, 2023);
        $this->setStoredOption('blc_last_check_time', $timestamp);

        $timezone = new \DateTimeZone('Pacific/Kiritimati');

        Functions\when('wp_timezone')->alias(static fn() => $timezone);
        Functions\when('wp_date')->alias(static function ($format, $timestamp = null, $tz = null) use ($timezone) {
            $timestamp = $timestamp ?? time();
            $target_tz = $tz ?? $timezone;

            $date = new \DateTime('@' . $timestamp);
            $date->setTimezone($target_tz);

            return $date->format($format);
        });

        ob_start();
        blc_dashboard_links_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('1 Jan 2024', $output);
    }

    /**
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed
     */
    private function getStoredOption(string $name, $default = false)
    {
        return array_key_exists($name, $this->options) ? $this->options[$name] : $default;
    }

    /**
     * @param string $name
     * @param mixed  $value
     */
    private function setStoredOption(string $name, $value): void
    {
        $this->options[$name] = $value;
    }
}

}

