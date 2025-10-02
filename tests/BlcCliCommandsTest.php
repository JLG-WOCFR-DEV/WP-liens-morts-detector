<?php

namespace {
    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            public static array $commands = [];
            public static array $successMessages = [];

            public static function add_command($name, $callable, $args = [])
            {
                self::$commands[$name] = ['callable' => $callable, 'args' => $args];
            }

            public static function success($message)
            {
                self::$successMessages[] = $message;
            }
        }
    }
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BlcCliCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        Functions\when('plugin_dir_path')->alias(static fn($file) => dirname($file) . '/');
        Functions\when('plugin_basename')->alias(static fn($file) => basename(dirname($file)) . '/' . basename($file));
        Functions\when('load_plugin_textdomain')->justReturn(true);
        Functions\when('register_activation_hook')->justReturn(true);
        Functions\when('register_deactivation_hook')->justReturn(true);
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('wp_get_schedules')->alias(static fn() => ['daily' => ['interval' => 86400, 'display' => 'Daily']]);
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('load_plugin_textdomain')->justReturn(true);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('wp_remote_post')->justReturn(['success' => true]);
        Functions\when('rest_url')->alias(static fn($path = '') => 'https://example.com/wp-json/' . ltrim((string) $path, '/'));
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('as_schedule_single_action')->justReturn(1);
        Functions\when('apply_filters')->alias(static fn($hook, $value, ...$args) => $value);
        Functions\when('home_url')->alias(static fn() => 'https://example.com');
        Functions\when('admin_url')->alias(static fn($path = '') => 'https://example.com/wp-admin/' . ltrim((string) $path, '/'));
        Functions\when('set_url_scheme')->alias(static fn($url, $scheme = null) => $url);
        Functions\when('wp_parse_url')->alias(static fn($url, $component = -1) => parse_url((string) $url, $component));
        Functions\when('get_post_stati')->alias(static fn() => ['publish' => 'publish']);
        Functions\when('get_post_types')->alias(static fn() => ['post']);
        Functions\when('wp_get_schedule')->justReturn(false);
        Functions\when('wp_list_pluck')->alias(static fn(array $list, $field) => array_map(static fn($item) => $item->$field ?? null, $list));
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('wp_unslash')->alias(static fn($value) => $value);
        Functions\when('wp_slash')->alias(static fn($value) => $value);
        Functions\when('wp_send_json_success')->justReturn(true);
        Functions\when('wp_send_json_error')->justReturn(true);
        Functions\when('wp_remote_head')->justReturn(['response' => ['code' => 200]]);
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200]]);
        Functions\when('current_time')->alias(static fn() => gmdate('Y-m-d H:i:s'));
        Functions\when('time')->alias(static fn() => time());
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_schedule_event')->justReturn(true);

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/liens-morts-detector-jlg.php';

        \WP_CLI::$commands = [];
        \WP_CLI::$successMessages = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_cli_commands_are_registered(): void
    {
        blc_register_wp_cli_commands();

        $this->assertArrayHasKey('blc scan', \WP_CLI::$commands);
        $this->assertArrayHasKey('blc scan-images', \WP_CLI::$commands);
    }

    public function test_cli_scan_command_invokes_link_scan(): void
    {
        blc_register_wp_cli_commands();

        $callable = \WP_CLI::$commands['blc scan']['callable'];

        $calledWith = [];
        Functions\when('blc_perform_check')->alias(function ($batch, $is_full_scan, $bypass_rest) use (&$calledWith) {
            $calledWith = [$batch, $is_full_scan, $bypass_rest];
        });

        $callable([], ['batch' => '5', 'full' => 'true', 'bypass-rest' => 'yes']);

        $this->assertSame([5, true, true], $calledWith);
        $this->assertNotEmpty(\WP_CLI::$successMessages);
    }

    public function test_cli_scan_images_command_invokes_image_scan(): void
    {
        blc_register_wp_cli_commands();

        $callable = \WP_CLI::$commands['blc scan-images']['callable'];

        $calledWith = [];
        Functions\when('blc_perform_image_check')->alias(function ($batch, $is_full_scan) use (&$calledWith) {
            $calledWith = [$batch, $is_full_scan];
        });

        $callable([], ['batch' => '-1', 'full' => 'false']);

        $this->assertSame([0, false], $calledWith);
        $this->assertNotEmpty(\WP_CLI::$successMessages);
    }
}

}
