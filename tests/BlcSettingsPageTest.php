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

    /**
     * @var string
     */
    private string $settingsMode = 'simple';

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
            'blc_image_scan_frequency' => 'weekly',
            'blc_image_scan_schedule_enabled' => false,
        ];

        Functions\when('add_action')->justReturn(true);
        Functions\when('register_setting')->justReturn(true);
        Functions\when('add_settings_section')->justReturn(true);
        Functions\when('add_settings_field')->justReturn(true);

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-settings-fields.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-cron.php';

        $test_case = $this;
        $this->settingsMode = 'simple';

        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('get_user_meta')->alias(static function ($user_id, $key, $single = false) use ($test_case) {
            if ((string) $key === BLC_SETTINGS_MODE_META_KEY) {
                return $test_case->settingsMode;
            }

            return '';
        });

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
        Functions\when('esc_sql')->alias(static function ($value) {
            return is_scalar($value) ? (string) $value : '';
        });
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($test_case) {
            return $test_case->getStoredOption((string) $name, $default);
        });
        Functions\when('update_option')->alias(static function ($name, $value) use ($test_case) {
            $test_case->setStoredOption((string) $name, $value);

            return true;
        });
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('add_option')->alias(static function ($name, $value, $deprecated = '', $autoload = 'yes') use ($test_case) {
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
        $_POST = [];

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

        $this->assertStringContainsString('<form method="post" action="options.php"', (string) $output);
        $this->assertStringContainsString('<form method="post" action="options.php" class="blc-settings-form">', (string) $output);
        $this->assertStringContainsString('<div class="wrap blc-wrap">', (string) $output);
    }

    public function test_activation_schedules_daily_fallback_when_initial_schedule_fails(): void
    {
        $currentTime = time();

        $GLOBALS['wpdb'] = new class () {
            public string $prefix = 'wp_';
            public bool $table_exists = false;

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
                return $this->table_exists ? 'wp_blc_broken_links' : '';
            }

            public function get_row($query = null)
            {
                return null;
            }

            public function query($query)
            {
                return true;
            }
        };

        $wpdb_stub = $GLOBALS['wpdb'];
        $test_case = $this;

        Functions\when('dbDelta')->alias(function ($sql) use ($wpdb_stub, $test_case) {
            $test_case->assertStringContainsString('CREATE TABLE wp_blc_broken_links', (string) $sql);
            $wpdb_stub->table_exists = true;

            return true;
        });

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

    public function test_database_upgrade_creates_table_when_missing(): void
    {
        $tableExists = false;

        $GLOBALS['wpdb'] = new class ($tableExists) {
            public string $prefix = 'wp_';
            private bool $table_exists;

            public function __construct(bool &$table_exists)
            {
                $this->table_exists = &$table_exists;
            }

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
                    return vsprintf(str_replace('%s', '%s', (string) $query), $args);
                }

                return (string) $query;
            }

            public function get_var($query = null)
            {
                return $this->table_exists ? 'wp_blc_broken_links' : '';
            }

            public function get_row($query = null)
            {
                return null;
            }

            public function query($query)
            {
                return true;
            }
        };

        $test_case = $this;

        Functions\when('dbDelta')->alias(function ($sql) use (&$tableExists, $test_case) {
            $test_case->assertStringContainsString('CREATE TABLE wp_blc_broken_links', (string) $sql);
            $tableExists = true;

            return true;
        });

        blc_maybe_upgrade_database();

        $this->assertTrue($tableExists, 'The broken links table should be created when missing.');
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

        $this->assertSame(
            0,
            $this->countSettingsErrorsByCode('blc_settings_saved'),
            'WordPress options.php already emits a single settings-updated notice.'
        );
    }

    public function test_omitted_image_frequency_on_simple_save_keeps_previous_without_warning(): void
    {
        $this->setStoredOption('blc_image_scan_frequency', 'weekly');

        $_POST = [
            'option_page'   => 'blc_settings',
            'blc_frequency' => 'daily',
        ];

        Functions\when('wp_schedule_event')->justReturn(true);

        $result = blc_sanitize_image_frequency_option(null);

        $this->assertSame('weekly', $result);
        $this->assertNull(
            $this->findSettingsErrorByCode('blc_image_frequency_warning'),
            'A missing images-tab field must not be treated as an invalid frequency.'
        );
    }

    public function test_empty_image_frequency_on_simple_save_keeps_previous_without_warning(): void
    {
        $this->setStoredOption('blc_image_scan_frequency', 'weekly');

        $_POST = [
            'option_page' => 'blc_settings',
            'blc_frequency' => 'daily',
        ];

        Functions\when('wp_schedule_event')->justReturn(true);

        $result = blc_sanitize_image_frequency_option('');

        $this->assertSame('weekly', $result);
        $this->assertNull($this->findSettingsErrorByCode('blc_image_frequency_warning'));
        $this->assertStringNotContainsString(
            'La fréquence choisie pour les images est invalide',
            $this->implodeSettingsErrorMessages()
        );
    }

    public function test_invalid_submitted_image_frequency_still_warns_and_keeps_fallback(): void
    {
        $this->setStoredOption('blc_image_scan_frequency', 'weekly');

        $_POST = [
            'option_page' => 'blc_settings',
            'blc_image_scan_frequency' => 'yearly',
        ];

        Functions\when('wp_schedule_event')->justReturn(true);

        $result = blc_sanitize_image_frequency_option('yearly');

        $this->assertSame('weekly', $result);

        $warning = $this->findSettingsErrorByCode('blc_image_frequency_warning');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('La fréquence choisie pour les images est invalide', (string) $warning['message']);
        $this->assertMatchesRegularExpression('/Hebdomadaire|Une fois par semaine/', (string) $warning['message']);
    }

    public function test_simple_settings_save_does_not_emit_duplicate_plugin_success_notices(): void
    {
        $this->setStoredOption('blc_frequency', 'weekly');
        $this->setStoredOption('blc_image_scan_frequency', 'weekly');
        $this->setStoredOption('blc_image_scan_schedule_enabled', true);

        $_POST = [
            'option_page'   => 'blc_settings',
            'blc_frequency' => 'daily',
        ];

        Functions\when('wp_schedule_event')->justReturn(true);

        $this->assertSame('daily', blc_sanitize_frequency_option('daily'));
        $this->assertSame('weekly', blc_sanitize_image_frequency_option(null));
        $this->assertTrue(blc_sanitize_image_scan_schedule_enabled_option(null));

        $this->assertNull($this->findSettingsErrorByCode('blc_image_frequency_warning'));
        $this->assertSame(0, $this->countSettingsErrorsByCode('blc_settings_saved'));
        $this->assertSame(
            0,
            substr_count($this->implodeSettingsErrorMessages(), 'Réglages enregistrés !'),
            'Keep a single WordPress settings-updated notice instead of plugin duplicates.'
        );
    }

    public function test_advanced_settings_save_can_disable_image_schedule_when_checkbox_omitted(): void
    {
        $this->setStoredOption('blc_image_scan_schedule_enabled', true);
        $this->settingsMode = 'advanced';

        $_POST = [
            'option_page'   => 'blc_settings',
            'blc_frequency' => 'daily',
        ];

        Functions\when('wp_clear_scheduled_hook')->justReturn(true);

        $this->assertFalse(blc_sanitize_image_scan_schedule_enabled_option(null));
    }

    public function test_submitted_valid_image_frequency_is_accepted_without_warning(): void
    {
        $_POST = [
            'option_page' => 'blc_settings',
            'blc_image_scan_frequency' => 'daily',
            'blc_image_scan_schedule_enabled' => '1',
        ];

        Functions\when('wp_schedule_event')->justReturn(true);

        $result = blc_sanitize_image_frequency_option('daily');

        $this->assertSame('daily', $result);
        $this->assertNull($this->findSettingsErrorByCode('blc_image_frequency_warning'));
        $this->assertSame(0, $this->countSettingsErrorsByCode('blc_settings_saved'));
    }

    public function test_sanitize_frequency_does_not_bootstrap_a_scan(): void
    {
        $now = time();
        $scheduled = [];
        $firedHooks = [];

        Functions\when('wp_schedule_event')->alias(function ($timestamp, $recurrence, $hook) use (&$scheduled) {
            $scheduled[] = [
                'timestamp'  => (int) $timestamp,
                'recurrence' => (string) $recurrence,
                'hook'       => (string) $hook,
            ];

            return true;
        });
        Functions\expect('blc_perform_check')->never();
        Functions\expect('spawn_cron')->never();
        Functions\expect('wp_cron')->never();
        Functions\when('do_action')->alias(function ($hook, ...$args) use (&$firedHooks) {
            $firedHooks[] = (string) $hook;
            if (in_array($hook, ['blc_check_links', 'blc_check_batch', 'blc_manual_check_batch'], true)) {
                throw new \RuntimeException('Scan hook ' . $hook . ' must not fire while sanitizing settings.');
            }

            return null;
        });

        $result = blc_sanitize_frequency_option('daily');

        $this->assertSame('daily', $result);
        $this->assertCount(1, $scheduled);
        $this->assertSame('blc_check_links', $scheduled[0]['hook']);
        $this->assertSame('daily', $scheduled[0]['recurrence']);
        $this->assertGreaterThan($now, $scheduled[0]['timestamp'], 'Saving settings must not schedule blc_check_links for now.');
        $this->assertGreaterThanOrEqual(
            $now + (defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600),
            $scheduled[0]['timestamp']
        );
        $this->assertNotContains('blc_check_links', $firedHooks);
        $this->assertContains('blc_check_links_schedule_updated', $firedHooks);
    }

    public function test_sanitize_surveillance_thresholds_does_not_persist_the_same_option(): void
    {
        $updated = [];
        $test_case = $this;

        Functions\when('update_option')->alias(function ($name, $value, $autoload = null) use (&$updated, $test_case) {
            $updated[] = (string) $name;
            $test_case->setStoredOption((string) $name, $value);

            return true;
        });
        Functions\expect('blc_perform_check')->never();

        $normalized = blc_sanitize_surveillance_thresholds_option([
            'global' => [
                [
                    'metric'     => 'broken_ratio',
                    'comparison' => 'gte',
                    'threshold'  => 8,
                    'severity'   => 'warning',
                    'label'      => 'Ratio critique',
                ],
            ],
            'taxonomy' => [],
        ]);

        $this->assertIsArray($normalized);
        $this->assertArrayHasKey('global', $normalized);
        $this->assertArrayHasKey('taxonomy', $normalized);
        $this->assertNotEmpty($normalized['global']);
        $this->assertSame([], $updated, 'Sanitize callbacks must not call update_option; the Settings API already persists.');
    }

    public function test_settings_api_save_of_surveillance_thresholds_does_not_recurse(): void
    {
        $depth = 0;
        $maxDepth = 0;
        $updateCalls = 0;
        $test_case = $this;

        Functions\when('update_option')->alias(function ($name, $value, $autoload = null) use (&$depth, &$maxDepth, &$updateCalls, $test_case) {
            $updateCalls++;
            $depth++;
            $maxDepth = max($maxDepth, $depth);

            if ($depth > 12) {
                $depth--;
                throw new \RuntimeException('Settings API save re-entered update_option until recursion (simulated OOM at apply_filters).');
            }

            if ((string) $name === 'blc_surveillance_thresholds') {
                $value = blc_sanitize_surveillance_thresholds_option($value);
            }

            $test_case->setStoredOption((string) $name, $value);
            $depth--;

            return true;
        });
        Functions\expect('blc_perform_check')->never();
        Functions\expect('spawn_cron')->never();

        $payload = [
            'global' => [
                [
                    'id'         => 'global_ratio_default',
                    'metric'     => 'broken_ratio',
                    'comparison' => 'gte',
                    'threshold'  => 5,
                    'severity'   => 'warning',
                    'label'      => 'Ratio de liens cassés critique',
                ],
            ],
            'taxonomy' => [],
        ];

        $this->assertTrue(update_option('blc_surveillance_thresholds', $payload));
        $this->assertSame(1, $updateCalls, 'options.php must persist surveillance thresholds once.');
        $this->assertSame(1, $maxDepth, 'sanitize_option must not call update_option of the same key.');

        $stored = $this->getStoredOption('blc_surveillance_thresholds');
        $this->assertIsArray($stored);
        $this->assertArrayHasKey('global', $stored);
        $this->assertSame('broken_ratio', $stored['global'][0]['metric']);
    }

    public function test_save_surveillance_thresholds_helper_does_not_recurse(): void
    {
        $depth = 0;
        $maxDepth = 0;
        $updateCalls = 0;
        $test_case = $this;

        Functions\when('update_option')->alias(function ($name, $value, $autoload = null) use (&$depth, &$maxDepth, &$updateCalls, $test_case) {
            $updateCalls++;
            $depth++;
            $maxDepth = max($maxDepth, $depth);

            if ($depth > 12) {
                $depth--;
                throw new \RuntimeException('blc_save_surveillance_thresholds re-entered update_option until recursion.');
            }

            if ((string) $name === 'blc_surveillance_thresholds') {
                $value = blc_sanitize_surveillance_thresholds_option($value);
            }

            $test_case->setStoredOption((string) $name, $value);
            $depth--;

            return true;
        });

        blc_save_surveillance_thresholds([
            'global' => [
                [
                    'metric'     => 'broken_ratio',
                    'comparison' => 'gte',
                    'threshold'  => 5,
                    'severity'   => 'warning',
                ],
            ],
            'taxonomy' => [],
        ]);

        $this->assertSame(1, $updateCalls);
        $this->assertSame(1, $maxDepth);
        $stored = $this->getStoredOption('blc_surveillance_thresholds');
        $this->assertIsArray($stored);
        $this->assertSame('broken_ratio', $stored['global'][0]['metric']);
    }

    public function test_surveillance_thresholds_are_registered_once_and_sanitize_does_not_save(): void
    {
        $settingsSource = (string) file_get_contents(
            dirname(__DIR__) . '/liens-morts-detector-jlg/includes/blc-settings-fields.php'
        );

        $this->assertSame(
            1,
            preg_match_all("/register_setting\\(\\s*\\\$option_group,\\s*'blc_surveillance_thresholds'/s", $settingsSource)
        );

        $reflection = new \ReflectionFunction('blc_sanitize_surveillance_thresholds_option');
        $lines = array_slice(
            (array) file((string) $reflection->getFileName()),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        );
        $body = implode('', $lines);

        $this->assertStringNotContainsString('blc_save_surveillance_thresholds', $body);
        $this->assertStringNotContainsString('update_option', $body);
    }

    public function test_accessibility_preferences_defaults_are_disabled(): void
    {
        unset($this->options['blc_accessibility_high_contrast'], $this->options['blc_accessibility_reduce_motion'], $this->options['blc_accessibility_large_font']);

        $preferences = blc_get_accessibility_preferences();

        $this->assertFalse($preferences['high_contrast']);
        $this->assertFalse($preferences['reduce_motion']);
        $this->assertFalse($preferences['large_font']);
    }

    public function test_accessibility_preferences_reflect_stored_options(): void
    {
        $this->setStoredOption('blc_accessibility_high_contrast', 1);
        $this->setStoredOption('blc_accessibility_reduce_motion', '1');
        $this->setStoredOption('blc_accessibility_large_font', true);

        $preferences = blc_get_accessibility_preferences();

        $this->assertTrue($preferences['high_contrast']);
        $this->assertTrue($preferences['reduce_motion']);
        $this->assertTrue($preferences['large_font']);
    }

    public function test_sanitize_accessibility_flag_casts_to_boolean(): void
    {
        $this->assertTrue(blc_sanitize_accessibility_flag_option('on'));
        $this->assertFalse(blc_sanitize_accessibility_flag_option(null));
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

    private function countSettingsErrorsByCode(string $code): int
    {
        $count = 0;

        foreach ($this->settingsErrors as $error) {
            if ($error['code'] === $code) {
                $count++;
            }
        }

        return $count;
    }

    private function implodeSettingsErrorMessages(): string
    {
        $messages = [];

        foreach ($this->settingsErrors as $error) {
            $messages[] = (string) $error['message'];
        }

        return implode("\n", $messages);
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

