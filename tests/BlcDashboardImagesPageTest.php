<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';
    require_once __DIR__ . '/stubs/cron-stubs.php';
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

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

    /**
     * @var array<int, array{0:string,1:array<int, mixed>}>
     */
    public array $wpdbPrepareCalls = [];

    /**
     * @var array<int, string>
     */
    public array $wpdbGetVarQueries = [];

    /**
     * @var array<int, string>
     */
    public array $wpdbGetResultsQueries = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $wpdbResults = [];

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

        if (!class_exists('WP_List_Table')) {
            require_once __DIR__ . '/stubs/WP_List_Table.php';
        }

        $this->options = [
            'blc_last_image_check_time' => 0,
            'blc_image_scan_schedule_enabled' => false,
            'blc_image_scan_frequency' => 'weekly',
            'blc_image_scan_frequency_custom_hours' => 168,
            'blc_image_scan_frequency_custom_time' => '02:00',
        ];

        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdbPrepareCalls = [];
        $this->wpdbGetVarQueries = [];
        $this->wpdbGetResultsQueries = [];
        $this->wpdbResults = [];
        $GLOBALS['wpdb'] = new class($this) {
            /** @var string */
            public $prefix = 'wp_';
            /** @var BlcDashboardImagesPageTest */
            private $test_case;

            public function __construct($test_case)
            {
                $this->test_case = $test_case;
            }

            public function prepare($query, ...$args)
            {
                $this->test_case->wpdbPrepareCalls[] = [$query, $args];

                return $query;
            }

            public function get_var($query)
            {
                $this->test_case->wpdbGetVarQueries[] = $query;

                return 0;
            }

            public function get_results($query, $output = ARRAY_A)
            {
                $this->test_case->wpdbGetResultsQueries[] = $query;

                return $this->test_case->getWpdbResults();
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
        Functions\when('sanitize_key')->alias(static fn($value) => is_scalar($value) ? strtolower((string) $value) : '');
        Functions\when('sanitize_html_class')->alias(static fn($value) => is_scalar($value) ? (string) $value : '');
        Functions\when('number_format_i18n')->alias(static function ($number, $decimals = 0) {
            return number_format((float) $number, (int) $decimals);
        });
        Functions\when('wp_date')->alias(static function ($format, $timestamp = null, $timezone = null) {
            $timestamp = $timestamp ?? time();

            if ($timezone instanceof \DateTimeZone) {
                $date = new \DateTimeImmutable('@' . (int) $timestamp);
                $date = $date->setTimezone($timezone);

                return $date->format($format);
            }

            return gmdate($format, (int) $timestamp);
        });
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('blc_update_image_scan_status')->justReturn(true);
        Functions\when('blc_get_image_scan_status_payload')->justReturn([
            'state'             => 'idle',
            'message'           => '',
            'total_batches'     => 0,
            'processed_batches' => 0,
            'remaining_batches' => 0,
            'is_full_scan'      => true,
            'last_error'        => '',
            'started_at'        => 0,
            'ended_at'          => 0,
        ]);

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/class-blc-images-list-table.php';
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

    public function test_manual_image_check_triggers_spawn_cron_when_schedule_succeeds(): void
    {
        $_POST['blc_manual_image_check'] = '1';

        $calls = 0;
        $GLOBALS['__blc_spawn_cron_callback'] = static function () use (&$calls) {
            $calls++;

            return true;
        };

        ob_start();
        blc_dashboard_images_page();
        $output = (string) ob_get_clean();

        $this->assertSame(1, $calls);
        $this->assertStringContainsString("La vérification des images a été programmée", $output);
        $this->assertStringNotContainsString("Le déclenchement immédiat du cron a échoué", $output);
    }

    public function test_manual_image_check_shows_error_when_manual_trigger_fails(): void
    {
        $_POST['blc_manual_image_check'] = '1';

        $calls = 0;
        $GLOBALS['__blc_spawn_cron_callback'] = static function () use (&$calls) {
            $calls++;

            return false;
        };

        Functions\expect('error_log')->once()->withArgs(static function ($message) {
            return is_string($message)
                && str_contains($message, 'Manual cron trigger failed for image check');
        });

        ob_start();
        blc_dashboard_images_page();
        $output = (string) ob_get_clean();

        $this->assertSame(1, $calls);
        $this->assertStringContainsString("La vérification des images a été programmée", $output);
        $this->assertStringContainsString("Le déclenchement immédiat du cron a échoué", $output);
    }

    public function test_dashboard_images_page_shows_reschedule_description_when_schedule_disabled(): void
    {
        $this->setStoredOption('blc_image_scan_schedule_enabled', false);

        ob_start();
        blc_dashboard_images_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Activez la planification automatique', $output);
        $this->assertStringNotContainsString('Reprogrammer le scan automatique', $output);
    }

    public function test_dashboard_images_page_shows_next_schedule_when_enabled(): void
    {
        $this->setStoredOption('blc_image_scan_schedule_enabled', true);

        $timestamp = 1700000000;
        $calls = 0;

        Functions\when('wp_next_scheduled')->alias(static function ($hook, $args = array()) use ($timestamp, &$calls) {
            if ('blc_check_image_batch' === $hook && $args === array(0, true)) {
                $calls++;

                return $timestamp;
            }

            return false;
        });

        ob_start();
        blc_dashboard_images_page();
        $output = (string) ob_get_clean();

        $this->assertGreaterThan(0, $calls);
        $this->assertStringContainsString('Prochain scan automatique', $output);
        $this->assertStringContainsString(gmdate('j M Y H:i', $timestamp), $output);
    }

    public function test_reschedule_image_cron_triggers_schedule_when_enabled(): void
    {
        $this->setStoredOption('blc_image_scan_schedule_enabled', true);
        $_POST['blc_reschedule_image_cron'] = '1';

        $timestamp = 1700003600;

        Functions\expect('blc_reset_image_check_schedule')->once()->withArgs(static function ($args) {
            return isset($args['context']) && 'dashboard_reschedule' === $args['context'];
        })->andReturn([
            'success' => true,
        ]);

        $calls = 0;
        Functions\when('wp_next_scheduled')->alias(static function ($hook, $args = array()) use ($timestamp, &$calls) {
            if ('blc_check_image_batch' === $hook && $args === array(0, true)) {
                $calls++;

                return $timestamp;
            }

            return false;
        });

        ob_start();
        blc_dashboard_images_page();
        $output = (string) ob_get_clean();

        $this->assertSame(2, $calls);
        $this->assertStringContainsString('Le scan automatique des images a été reprogrammé selon les réglages actuels.', $output);
        $this->assertStringContainsString(gmdate('j M Y H:i', $timestamp), $output);
    }

    public function test_reschedule_image_cron_shows_warning_when_schedule_disabled(): void
    {
        $this->setStoredOption('blc_image_scan_schedule_enabled', false);
        $_POST['blc_reschedule_image_cron'] = '1';

        Functions\expect('blc_reset_image_check_schedule')->never();

        ob_start();
        blc_dashboard_images_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString(
            "La planification automatique des images est désactivée.",
            $output
        );
    }

    public function test_reschedule_image_cron_outputs_error_when_schedule_fails(): void
    {
        $this->setStoredOption('blc_image_scan_schedule_enabled', true);
        $_POST['blc_reschedule_image_cron'] = '1';

        Functions\expect('blc_reset_image_check_schedule')->once()->withArgs(static function ($args) {
            return isset($args['context']) && 'dashboard_reschedule' === $args['context'];
        })->andReturn([
            'success'           => false,
            'error_message'     => 'failure message',
            'restore_attempted' => true,
            'restored'          => false,
        ]);

        Functions\expect('error_log')->once()->withArgs(static function ($message) {
            return is_string($message) && str_contains($message, 'failure message');
        });

        ob_start();
        blc_dashboard_images_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString(
            "La planification automatique des images n'a pas pu être reprogrammée.",
            $output
        );
        $this->assertStringContainsString(
            "La planification précédente des images n'a pas pu être restaurée.",
            $output
        );
    }

    public function test_dashboard_images_page_displays_thumbnail_preview_when_available(): void
    {
        Functions\when('attachment_url_to_postid')->alias(static function ($url) {
            return 321;
        });
        Functions\when('wp_get_attachment_image')->alias(static function ($attachment_id, $size = 'thumbnail', $icon = false, $attr = array()) {
            $attributes = '';

            if (is_array($attr)) {
                foreach ($attr as $name => $value) {
                    $name = is_string($name) ? $name : (string) $name;
                    $value = is_scalar($value) ? (string) $value : '';
                    $attributes .= sprintf(' %s="%s"', esc_attr($name), esc_attr($value));
                }
            }

            return sprintf('<img src="https://example.com/uploads/thumb-%d.jpg"%s />', (int) $attachment_id, $attributes);
        });
        $list_table = new \BLC_Images_List_Table();
        $reflection = new \ReflectionMethod(\BLC_Images_List_Table::class, 'column_image_details');
        $reflection->setAccessible(true);

        $item = [
            'url'             => 'https://example.com/uploads/broken-image.jpg',
            'anchor'          => 'broken-image.jpg',
            'post_id'         => 42,
            'post_title'      => 'Sample Post',
            'http_status'     => 404,
            'last_checked_at' => '2024-01-10 12:34:56',
        ];

        $output = (string) $reflection->invoke($list_table, $item);

        $this->assertStringContainsString('class="blc-image-preview"', $output);
        $this->assertStringContainsString('<img src="https://example.com/uploads/thumb-321.jpg"', $output);
        $this->assertStringContainsString('aria-hidden="false"', $output);
    }

    public function test_dashboard_images_page_handles_empty_dataset_row_types(): void
    {
        $registered_filters = [];
        Functions\when('add_filter')->alias(static function ($hook, $callback, $priority = 10, $accepted_args = 1) use (&$registered_filters) {
            $registered_filters[$hook][$priority][] = [
                'callback'      => $callback,
                'accepted_args' => $accepted_args,
            ];

            return true;
        });
        Functions\when('apply_filters')->alias(static function ($hook, $value, ...$args) use (&$registered_filters) {
            if (!isset($registered_filters[$hook])) {
                return $value;
            }

            ksort($registered_filters[$hook]);
            $params = array_merge([$value], $args);

            foreach ($registered_filters[$hook] as $callbacks) {
                foreach ($callbacks as $handler) {
                    $callback = $handler['callback'];
                    $accepted_args = max(0, (int) $handler['accepted_args']);

                    $arg_count = $accepted_args;
                    try {
                        if (is_array($callback) && count($callback) === 2) {
                            $reflection = new \ReflectionMethod($callback[0], $callback[1]);
                        } elseif (is_string($callback) && str_contains($callback, '::')) {
                            $reflection = new \ReflectionMethod($callback);
                        } elseif (is_object($callback) && !($callback instanceof \Closure) && method_exists($callback, '__invoke')) {
                            $reflection = new \ReflectionMethod($callback, '__invoke');
                        } else {
                            $reflection = new \ReflectionFunction($callback);
                        }

                        if (!$reflection->isVariadic()) {
                            $arg_count = min($arg_count, $reflection->getNumberOfParameters());
                        }
                    } catch (\ReflectionException $e) {
                        // Fallback to accepted args when reflection fails.
                    }

                    $callback_args = array_slice($params, 0, max(0, $arg_count));
                    $value = $callback(...$callback_args);
                    $params[0] = $value;
                }
            }

            return $value;
        });
        Functions\expect('blc_get_dataset_storage_footprint_bytes')->never();

        add_filter('blc_dataset_row_types', fn() => [], 10, 2);

        ob_start();
        blc_dashboard_images_page();
        $output = (string) ob_get_clean();

        $this->assertSame([], $this->wpdbPrepareCalls);
        $this->assertSame([], $this->wpdbGetVarQueries);
        $this->assertSame([], $this->wpdbGetResultsQueries);
        $this->assertStringContainsString('<span class="blc-stat-value">0</span>', $output);
        $this->assertStringContainsString('0.00', $output);
        $this->assertStringContainsString('Jamais', $output);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWpdbResults(): array
    {
        return $this->wpdbResults;
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
