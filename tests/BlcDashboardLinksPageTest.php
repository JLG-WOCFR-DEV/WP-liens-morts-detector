<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';
    require_once __DIR__ . '/stubs/cron-stubs.php';

    if (!function_exists('sanitize_key')) {
        function sanitize_key($key)
        {
            $key = strtolower((string) $key);

            return preg_replace('/[^a-z0-9_\-]/', '', $key);
        }
    }
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

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

        require_once __DIR__ . '/wp-option-stubs.php';
        OptionsStore::reset();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }

        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }

        if (!defined('WEEK_IN_SECONDS')) {
            define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);
        }

        if (!class_exists('WP_List_Table')) {
            require_once __DIR__ . '/stubs/WP_List_Table.php';
        }

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/class-blc-links-list-table.php';

        $this->options = [
            'blc_last_check_time'        => 0,
            'blc_frequency'              => 'weekly',
            'blc_frequency_custom_hours' => 24,
            'blc_frequency_custom_time'  => '02:00',
            'blc_recheck_interval_days'  => 7,
        ];

        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

        $GLOBALS['wpdb'] = new class() {
            /** @var string */
            public $prefix = 'wp_';

            /** @var string */
            public $posts = 'wp_posts';

            /** @var array<int, array<string, mixed>> */
            public $prepared_calls = [];

            /** @var int */
            public $mock_total = 0;

            /** @var array<string, int>|null */
            public $mock_counts_row = null;

            /** @var array<int, array<string, mixed>> */
            public $mock_results = [];

            /** @var string|null */
            public $last_get_var_query = null;

            /** @var string|null */
            public $last_get_row_query = null;

            /** @var string|null */
            public $last_get_results_query = null;

            public function prepare($query, ...$args)
            {
                $params = [];
                if (!empty($args)) {
                    if (is_array($args[0])) {
                        $params = $args[0];
                    } else {
                        $params = $args;
                    }
                }

                $this->prepared_calls[] = [
                    'query'  => $query,
                    'params' => $params,
                ];

                return $query;
            }

            public function get_var($query)
            {
                $this->last_get_var_query = $query;

                return $this->mock_total;
            }

            public function get_row($query, $output = ARRAY_A)
            {
                $this->last_get_row_query = $query;

                if (is_array($this->mock_counts_row)) {
                    return $this->mock_counts_row;
                }

                return [
                    'total'               => 0,
                    'internal_count'      => 0,
                    'not_found_count'     => 0,
                    'server_error_count'  => 0,
                    'redirect_count'      => 0,
                    'needs_recheck_count' => 0,
                ];
            }

            public function get_results($query, $output = ARRAY_A)
            {
                $this->last_get_results_query = $query;

                return $this->mock_results;
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
        Functions\when('wp_timezone_string')->justReturn('');
        Functions\when('wp_timezone')->alias(static function () {
            return new \DateTimeZone('UTC');
        });
        Functions\when('wp_date')->alias(static function ($format, $timestamp = null, $tz = null) {
            $timestamp = $timestamp ?? time();
            $timezone = $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone('UTC');

            $date = new \DateTime('@' . $timestamp);
            $date->setTimezone($timezone);

            return $date->format($format);
        });
        Functions\when('wp_next_scheduled')->alias(static function ($hook) {
            if ('blc_check_links' === $hook) {
                return 1700003600;
            }

            return false;
        });
        Functions\when('wp_get_schedule')->alias(static function ($hook) {
            if ('blc_check_links' === $hook) {
                return 'weekly';
            }

            return false;
        });
        Functions\when('wp_get_schedules')->alias(static function () {
            return [
                'blc_hourly'        => ['interval' => HOUR_IN_SECONDS],
                'blc_six_hours'     => ['interval' => 6 * HOUR_IN_SECONDS],
                'blc_twelve_hours'  => ['interval' => 12 * HOUR_IN_SECONDS],
                'daily'             => ['interval' => DAY_IN_SECONDS],
                'weekly'            => ['interval' => WEEK_IN_SECONDS],
                'monthly'           => ['interval' => 30 * DAY_IN_SECONDS],
                'blc_custom_interval' => ['interval' => DAY_IN_SECONDS],
            ];
        });
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
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
        Functions\when('get_post_types')->alias(static function ($args = [], $output = 'names') {
            return ['post', 'page'];
        });
        Functions\when('get_post_type_object')->alias(static function ($post_type) {
            return (object) [
                'labels' => (object) [
                    'singular_name' => ucfirst((string) $post_type),
                ],
                'label' => ucfirst((string) $post_type),
            ];
        });
        Functions\when('current_time')->alias(static function ($type, $gmt = 0) {
            if ($type === 'timestamp') {
                return 1700000000;
            }

            if ($type === 'mysql') {
                return gmdate('Y-m-d H:i:s', 1700000000);
            }

            return 1700000000;
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        OptionsStore::reset();
        parent::tearDown();

        if ($this->previous_wpdb !== null) {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
        }

        $_POST = [];
        unset($GLOBALS['__blc_spawn_cron_callback'], $GLOBALS['__blc_wp_cron_callback']);
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

    public function test_manual_check_shows_error_notice_when_schedule_fails(): void
    {
        $_POST['blc_manual_check'] = '1';
        $_POST['blc_full_scan'] = '1';

        Functions\when('wp_schedule_single_event')->justReturn(false);
        Functions\expect('error_log')->once()->withArgs(static function ($message) {
            return is_string($message)
                && str_contains($message, 'Failed to schedule manual link check');
        });
        Functions\expect('do_action')->once()->withArgs(static function ($hook, $is_full, $bypass_rest_window) {
            return 'blc_manual_check_schedule_failed' === $hook
                && true === $is_full
                && true === $bypass_rest_window;
        })->andReturnNull();

        ob_start();
        blc_dashboard_links_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('notice-error', $output);
        $this->assertStringContainsString("La vérification des liens n'a pas pu être programmée.", $output);
        $this->assertStringNotContainsString("La vérification des liens a été programmée", $output);
    }

    public function test_manual_check_triggers_spawn_cron_when_schedule_succeeds(): void
    {
        $_POST['blc_manual_check'] = '1';

        $calls = 0;
        $GLOBALS['__blc_spawn_cron_callback'] = static function () use (&$calls) {
            $calls++;

            return true;
        };

        ob_start();
        blc_dashboard_links_page();
        $output = (string) ob_get_clean();

        $this->assertSame(1, $calls);
        $this->assertStringContainsString("La vérification des liens a été programmée", $output);
        $this->assertStringNotContainsString("Le déclenchement immédiat du cron a échoué", $output);
    }

    public function test_manual_check_shows_error_when_manual_trigger_fails(): void
    {
        $_POST['blc_manual_check'] = '1';

        $calls = 0;
        $GLOBALS['__blc_spawn_cron_callback'] = static function () use (&$calls) {
            $calls++;

            return false;
        };

        Functions\expect('error_log')->once()->withArgs(static function ($message) {
            return is_string($message)
                && str_contains($message, 'Manual cron trigger failed for link check');
        });

        ob_start();
        blc_dashboard_links_page();
        $output = (string) ob_get_clean();

        $this->assertSame(1, $calls);
        $this->assertStringContainsString("La vérification des liens a été programmée", $output);
        $this->assertStringContainsString("Le déclenchement immédiat du cron a échoué", $output);
    }

    public function test_reschedule_cron_displays_success_notice(): void
    {
        $_POST['blc_reschedule_cron'] = '1';

        ob_start();
        blc_dashboard_links_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString("La vérification automatique a été reprogrammée avec succès.", $output);
    }

    public function test_reschedule_cron_displays_error_notice_on_failure(): void
    {
        $_POST['blc_reschedule_cron'] = '1';

        $scheduleCalls = 0;
        Functions\when('wp_schedule_event')->alias(static function ($timestamp, $recurrence, $hook) use (&$scheduleCalls) {
            $scheduleCalls++;

            return false;
        });

        Functions\expect('error_log')->once()->withArgs(static function ($message) {
            return is_string($message)
                && str_contains($message, 'BLC: Failed to schedule automatic link check');
        });

        Functions\expect('do_action')
            ->once()
            ->withArgs(static function ($hook, $schedule, $context) {
                return 'blc_check_links_schedule_failed' === $hook
                    && 'weekly' === $schedule
                    && 'dashboard' === $context;
            })
            ->andReturnNull();

        ob_start();
        blc_dashboard_links_page();
        $output = (string) ob_get_clean();

        $this->assertGreaterThanOrEqual(1, $scheduleCalls);
        $this->assertStringContainsString("La reprogrammation de l'analyse automatique a échoué.", $output);
    }

    public function test_views_include_additional_filters(): void
    {
        $GLOBALS['wpdb']->mock_total = 5;
        $GLOBALS['wpdb']->mock_counts_row = [
            'total'               => 5,
            'internal_count'      => 2,
            'not_found_count'     => 1,
            'server_error_count'  => 1,
            'redirect_count'      => 1,
            'needs_recheck_count' => 2,
        ];

        ob_start();
        blc_dashboard_links_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString("404 / 410", $output);
        $this->assertStringContainsString("5xx", $output);
        $this->assertStringContainsString("Redirections", $output);
        $this->assertStringContainsString("À revérifier", $output);
        $this->assertStringContainsString("404 / 410 <span class='count'>(1)</span>", $output);
        $this->assertStringContainsString("5xx <span class='count'>(1)</span>", $output);
        $this->assertStringContainsString("Redirections <span class='count'>(1)</span>", $output);
        $this->assertStringContainsString("À revérifier <span class='count'>(2)</span>", $output);
    }

    public function test_hidden_page_field_not_rendered_for_non_scalar_request(): void
    {
        $_REQUEST['page'] = ['foo'];

        $errors = [];
        set_error_handler(static function ($severity, $message) use (&$errors) {
            $errors[] = $message;

            return true;
        });

        ob_start();
        blc_dashboard_links_page();
        $output = (string) ob_get_clean();

        restore_error_handler();
        unset($_REQUEST['page']);

        $this->assertSame([], $errors);
        $this->assertStringNotContainsString('<input type="hidden" name="page"', $output);
    }

    public function test_prepare_items_filters_by_post_type_joins_posts_table(): void
    {
        $_GET['post_type'] = 'page';
        $list_table = new \BLC_Links_List_Table();

        $list_table->prepare_items();

        unset($_GET['post_type']);

        $this->assertNotNull($GLOBALS['wpdb']->last_get_var_query);
        $this->assertNotNull($GLOBALS['wpdb']->last_get_results_query);
        $this->assertStringContainsString('LEFT JOIN wp_posts AS posts', $GLOBALS['wpdb']->last_get_var_query);
        $this->assertStringContainsString('LEFT JOIN wp_posts AS posts', $GLOBALS['wpdb']->last_get_results_query);
        $this->assertStringContainsString('posts.post_type = %s', $GLOBALS['wpdb']->last_get_var_query);
        $this->assertStringContainsString('posts.post_type = %s', $GLOBALS['wpdb']->last_get_results_query);

        $matching_calls = array_filter(
            $GLOBALS['wpdb']->prepared_calls,
            static fn(array $call): bool => isset($call['query'], $call['params'])
                && is_string($call['query'])
                && str_contains($call['query'], 'posts.post_type = %s')
        );

        $this->assertNotEmpty($matching_calls);

        foreach ($matching_calls as $call) {
            $this->assertContains('page', $call['params']);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function statusViewProvider(): array
    {
        return [
            'not_found'  => ['status_404_410', 'http_status IN (404, 410)'],
            'server'     => ['status_5xx', '(http_status BETWEEN 500 AND 599)'],
            'redirects'  => ['status_redirects', '(http_status BETWEEN 300 AND 399)'],
        ];
    }

    /**
     * @dataProvider statusViewProvider
     */
    public function test_prepare_items_applies_http_status_filters(string $view, string $expectedSql): void
    {
        $_GET['link_type'] = $view;
        $list_table = new \BLC_Links_List_Table();

        $list_table->prepare_items();

        unset($_GET['link_type']);

        $this->assertNotEmpty($GLOBALS['wpdb']->prepared_calls);

        $matching_calls = array_filter(
            $GLOBALS['wpdb']->prepared_calls,
            static fn(array $call): bool => isset($call['query']) && is_string($call['query']) && str_contains($call['query'], $expectedSql)
        );

        $this->assertNotEmpty($matching_calls);
    }

    public function test_prepare_items_applies_needs_recheck_filter(): void
    {
        $_GET['link_type'] = 'needs_recheck';
        $list_table = new \BLC_Links_List_Table();

        $list_table->prepare_items();

        unset($_GET['link_type']);

        $needs_recheck_snippet = 'last_checked_at IS NULL OR last_checked_at = %s OR last_checked_at <= %s';
        $recheck_days = (int) $this->getStoredOption('blc_recheck_interval_days', 7);
        $expected_threshold = gmdate('Y-m-d H:i:s', 1700000000 - ($recheck_days * DAY_IN_SECONDS));

        $matching_calls = array_filter(
            $GLOBALS['wpdb']->prepared_calls,
            static fn(array $call): bool => isset($call['query']) && is_string($call['query']) && str_contains($call['query'], $needs_recheck_snippet)
        );

        $this->assertNotEmpty($matching_calls);

        foreach ($matching_calls as $call) {
            $this->assertContains('link', $call['params']);
            $this->assertContains('0000-00-00 00:00:00', $call['params']);
            $this->assertContains($expected_threshold, $call['params']);
        }
    }

    public function test_prepare_items_respects_custom_recheck_interval(): void
    {
        $_GET['link_type'] = 'needs_recheck';
        $this->setStoredOption('blc_recheck_interval_days', 3);

        $list_table = new \BLC_Links_List_Table();

        $list_table->prepare_items();

        unset($_GET['link_type']);

        $needs_recheck_snippet = 'last_checked_at IS NULL OR last_checked_at = %s OR last_checked_at <= %s';
        $expected_threshold = gmdate('Y-m-d H:i:s', 1700000000 - (3 * DAY_IN_SECONDS));

        $matching_calls = array_filter(
            $GLOBALS['wpdb']->prepared_calls,
            static fn(array $call): bool => isset($call['query']) && is_string($call['query']) && str_contains($call['query'], $needs_recheck_snippet)
        );

        $this->assertNotEmpty($matching_calls);

        foreach ($matching_calls as $call) {
            $this->assertContains($expected_threshold, $call['params']);
        }
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

