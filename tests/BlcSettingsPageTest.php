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

    private ?string $upgradeStubPath = null;

    private bool $createdUpgradeStub = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        $this->createdUpgradeStub = false;

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        $this->upgradeStubPath = ABSPATH . 'wp-admin/includes/upgrade.php';
        $upgradeDirectory      = dirname((string) $this->upgradeStubPath);

        if (!is_dir($upgradeDirectory)) {
            mkdir($upgradeDirectory, 0777, true);
        }

        if (!file_exists((string) $this->upgradeStubPath)) {
            $stub  = "<?php\n";
            $stub .= "if (!function_exists('dbDelta')) {\n";
            $stub .= "    function dbDelta(\$sql) {\n";
            $stub .= "        return true;\n";
            $stub .= "    }\n";
            $stub .= "}\n";

            file_put_contents((string) $this->upgradeStubPath, $stub);
            $this->createdUpgradeStub = true;
        }

        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }

        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }

        $this->options = [
            'blc_frequency'        => 'weekly',
            'blc_frequency_custom_hours' => 24,
            'blc_frequency_custom_time'  => '02:00',
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
        Functions\when('wp_get_schedules')->alias(static function () {
            return [
                'blc_hourly'        => ['interval' => HOUR_IN_SECONDS],
                'blc_six_hours'     => ['interval' => 6 * HOUR_IN_SECONDS],
                'blc_twelve_hours'  => ['interval' => 12 * HOUR_IN_SECONDS],
                'daily'             => ['interval' => DAY_IN_SECONDS],
                'weekly'            => ['interval' => 7 * DAY_IN_SECONDS],
                'monthly'           => ['interval' => 30 * DAY_IN_SECONDS],
                'blc_custom_interval' => ['interval' => DAY_IN_SECONDS],
            ];
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
        Functions\when('wp_unslash')->alias(static fn($value) => $value);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('add_settings_error')->alias(function ($setting, $code, $message, $type = 'error') use ($test_case) {
            $test_case->settingsErrors[] = [
                'setting' => $setting,
                'code'    => $code,
                'message' => $message,
                'type'    => $type,
            ];
        });
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-activation.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
        $this->settingsErrors = [];

        if ($this->createdUpgradeStub && $this->upgradeStubPath && file_exists($this->upgradeStubPath)) {
            unlink($this->upgradeStubPath);
        }

        if (isset($GLOBALS['wpdb'])) {
            unset($GLOBALS['wpdb']);
        }
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

    public function test_activation_schedules_daily_fallback_when_initial_schedule_fails(): void
    {
        $currentTime = time();

        $GLOBALS['wpdb'] = new class () {
            public string $prefix = 'wp_';

            public function get_charset_collate(): string
            {
                return 'utf8mb4_general_ci';
            }

            public function esc_like($text)
            {
                return $text;
            }

            public function prepare($query, ...$args)
            {
                if (!empty($args)) {
                    return vsprintf(str_replace('%s', '%s', $query), $args);
                }

                return $query;
            }

            public function get_var($query = null)
            {
                return '';
            }
        };

        Functions\expect('blc_reset_link_check_schedule')
            ->once()
            ->andReturn([
                'success'       => false,
                'error_code'    => 'missing_schedule',
                'error_message' => '',
                'schedule'      => 'weekly',
            ]);

        $scheduledEvents = [];

        Functions\when('wp_schedule_event')->alias(function ($timestamp, $recurrence, $hook) use (&$scheduledEvents) {
            $scheduledEvents[] = [
                'timestamp'  => $timestamp,
                'recurrence' => $recurrence,
                'hook'       => $hook,
            ];

            return true;
        });

        blc_activate_site();

        $this->assertCount(1, $scheduledEvents, 'Exactly one fallback event should be scheduled.');

        $event = $scheduledEvents[0];
        $upperBound = $currentTime + (defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600) + 5;

        $this->assertIsInt($event['timestamp']);
        $this->assertGreaterThanOrEqual($currentTime, $event['timestamp']);
        $this->assertLessThanOrEqual($upperBound, $event['timestamp']);
        $this->assertSame('daily', $event['recurrence']);
        $this->assertSame('blc_check_links', $event['hook']);
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

