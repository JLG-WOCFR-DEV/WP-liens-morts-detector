<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BlcSettingsPageTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        $this->options = [
            'blc_frequency'        => 'weekly',
            'blc_rest_start_hour'  => '08',
            'blc_rest_end_hour'    => '20',
            'blc_link_delay'       => 200,
            'blc_batch_delay'      => 60,
            'blc_scan_method'      => 'precise',
            'blc_excluded_domains' => "x.com\ntwitter.com\nlinkedin.com",
            'blc_debug_mode'       => false,
            'timezone_string'      => '',
            'gmt_offset'           => 0,
        ];

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';

        $test_case = $this;

        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('wp_unslash')->alias(static fn($value) => $value);
        Functions\when('sanitize_text_field')->alias(static function ($value) {
            if (is_scalar($value)) {
                return trim((string) $value);
            }

            return '';
        });
        Functions\when('sanitize_textarea_field')->alias(static function ($value) {
            if (is_scalar($value)) {
                return trim((string) $value);
            }

            return '';
        });
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($test_case) {
            return $test_case->getStoredOption((string) $name, $default);
        });
        Functions\when('update_option')->alias(static function ($name, $value) use ($test_case) {
            $test_case->setStoredOption((string) $name, $value);

            return true;
        });
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('wp_nonce_field')->alias(static function ($action = -1, $name = '_wpnonce', $referer = true, $echo = true) {
            echo '';

            return '';
        });
        Functions\when('submit_button')->alias(static function ($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null) {
            echo '';

            return '';
        });
        Functions\when('wp_kses')->alias(static fn($string, $allowed_html = null, $allowed_protocols = []) => $string);
        Functions\when('wp_kses_post')->alias(static fn($string) => $string);
        Functions\when('selected')->alias(static function ($value, $compare, $echo = true) {
            $result = ((string) $value === (string) $compare) ? 'selected="selected"' : '';

            if ($echo) {
                echo $result;
            }

            return $result;
        });
        Functions\when('checked')->alias(static function ($value, $compare, $echo = true) {
            $result = ((string) $value === (string) $compare) ? 'checked="checked"' : '';

            if ($echo) {
                echo $result;
            }

            return $result;
        });
        Functions\when('wp_timezone_string')->justReturn('');
        Functions\when('wp_timezone')->alias(static function () {
            return new \DateTimeZone('UTC');
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
        $_POST = [];
    }

    public function test_invalid_frequency_falls_back_to_previous_value(): void
    {
        $_POST = [
            'blc_save_settings'    => '1',
            'blc_frequency'        => 'yearly',
            'blc_rest_start_hour'  => '09',
            'blc_rest_end_hour'    => '18',
            'blc_link_delay'       => '100',
            'blc_batch_delay'      => '50',
            'blc_scan_method'      => 'fast',
            'blc_excluded_domains' => 'example.com',
        ];

        $expected_frequency = 'weekly';

        Functions\expect('wp_schedule_event')
            ->once()
            ->withArgs(function ($timestamp, $recurrence, $hook) use ($expected_frequency) {
                return is_int($timestamp)
                    && $timestamp > 0
                    && $recurrence === $expected_frequency
                    && $hook === 'blc_check_links';
            })
            ->andReturn(true);

        ob_start();
        blc_settings_page();
        $output = ob_get_clean();

        $this->assertSame($expected_frequency, $this->getStoredOption('blc_frequency'));
        $this->assertStringContainsString('La fréquence choisie est invalide', (string) $output);
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

