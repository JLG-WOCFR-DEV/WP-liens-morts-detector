<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BlcDashboardImagesPageTest extends TestCase
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
            'blc_last_image_check_time' => 0,
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

            public function get_results($query, $output = ARRAY_A)
            {
                return [];
            }
        };

        $test_case = $this;

        Functions\when('get_option')->alias(static function ($name, $default = false) use ($test_case) {
            return $test_case->getStoredOption((string) $name, $default);
        });
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
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

    public function test_manual_image_check_shows_error_notice_when_schedule_fails(): void
    {
        $_POST['blc_manual_image_check'] = '1';

        Functions\when('wp_schedule_single_event')->justReturn(false);
        Functions\expect('error_log')->once()->withArgs(static function ($message) {
            return is_string($message)
                && str_contains($message, 'Failed to schedule manual image check');
        });
        Functions\expect('do_action')->once()->withArgs(static function ($hook) {
            return 'blc_manual_image_check_schedule_failed' === $hook;
        })->andReturnNull();

        ob_start();
        blc_dashboard_images_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('notice-error', $output);
        $this->assertStringContainsString("La vérification des images n'a pas pu être programmée.", $output);
        $this->assertStringNotContainsString("La vérification des images a été programmée", $output);
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
