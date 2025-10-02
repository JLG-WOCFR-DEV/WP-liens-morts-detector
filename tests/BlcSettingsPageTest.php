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

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $settingsErrors = [];

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
            'blc_post_statuses'    => ['publish'],
        ];

        Functions\when('add_action')->justReturn(true);
        Functions\when('register_setting')->justReturn(true);
        Functions\when('add_settings_section')->justReturn(true);
        Functions\when('add_settings_field')->justReturn(true);

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-settings-fields.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';

        $test_case = $this;

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
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_get_schedule')->justReturn(false);
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('wp_kses')->alias(static fn($string, $allowed_html = null, $allowed_protocols = []) => $string);
        Functions\when('wp_kses_post')->alias(static fn($string) => $string);
        Functions\when('selected')->alias(static function ($value, $compare, $echo = true) {
            $result = ((string) $value === (string) $compare) ? 'selected="selected"' : '';

            if ($echo) {
                echo $result;
            }

            return $result;
        });
        Functions\when('checked')->alias(static function ($value, $compare = true, $echo = true) {
            $result = ($value == $compare) ? 'checked="checked"' : '';

            if ($echo) {
                echo $result;
            }

            return $result;
        });
        Functions\when('wp_timezone_string')->justReturn('');
        Functions\when('wp_timezone')->alias(static function () {
            return new \DateTimeZone('UTC');
        });
        Functions\when('get_post_stati')->alias(static function ($args = [], $output = 'names', $operator = 'and') {
            $statuses = [
                'publish' => (object) ['label' => 'Publié'],
                'draft'   => (object) ['label' => 'Brouillon'],
                'pending' => (object) ['label' => 'En attente'],
            ];

            if ($output === 'objects') {
                return $statuses;
            }

            return array_keys($statuses);
        });
        Functions\when('error_log')->justReturn(null);
        Functions\when('do_action')->justReturn(null);
        Functions\when('add_settings_error')->alias(function ($setting, $code, $message, $type = 'error') use ($test_case) {
            $test_case->settingsErrors[] = [
                'setting' => $setting,
                'code'    => $code,
                'message' => $message,
                'type'    => $type,
            ];
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
        $this->settingsErrors = [];
    }

    public function test_settings_page_uses_settings_api_calls(): void
    {
        Functions\expect('settings_errors')->once()->withNoArgs()->andReturnNull();
        Functions\expect('settings_fields')->once()->with('blc_settings')->andReturnNull();
        Functions\expect('do_settings_sections')->once()->with('blc-settings')->andReturnNull();
        Functions\expect('submit_button')
            ->once()
            ->withArgs(static function ($text) {
                return is_string($text) && str_contains($text, 'Enregistrer');
            })
            ->andReturnNull();

        ob_start();
        blc_settings_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('<form method="post" action="options.php">', (string) $output);
        $this->assertStringContainsString('<div class="wrap">', (string) $output);
    }

    public function test_invalid_frequency_falls_back_to_previous_value(): void
    {
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

        $result = blc_sanitize_frequency_option('yearly');

        $this->assertSame($expected_frequency, $result);

        $warning = $this->findSettingsErrorByCode('blc_frequency_warning');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('La fréquence choisie est invalide', (string) $warning['message']);

        $success = $this->findSettingsErrorByCode('blc_settings_saved');
        $this->assertNotNull($success);
        $this->assertSame('updated', $success['type']);
    }

    /**
     * @param string $code
     * @return array<string, mixed>|null
     */
    private function findSettingsErrorByCode(string $code): ?array
    {
        foreach ($this->settingsErrors as $error) {
            if ($error['code'] === $code) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @param string $name
     * @param mixed  $default
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

