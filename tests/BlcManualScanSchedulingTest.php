<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';

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
                $this->code    = (string) $code;
                $this->message = (string) $message;
                $this->data    = $data;
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

    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing)
        {
            return $thing instanceof \WP_Error;
        }
    }

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

class BlcManualScanSchedulingTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $options = [];

    /**
     * @var array<int, array{hook: string, args: array}>
     */
    private array $dispatchedActions = [];

    /**
     * @var array<int, array{hook: string, args: mixed}>
     */
    private array $clearedHooks = [];

    /**
     * @var array<int, string>
     */
    private array $errorLogs = [];

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        if (!defined('DISABLE_WP_CRON')) {
            define('DISABLE_WP_CRON', false);
        }

        $this->options          = [];
        $this->dispatchedActions = [];
        $this->clearedHooks      = [];
        $this->errorLogs         = [];

        $test_case = $this;

        Functions\when('get_option')->alias(static function ($name, $default = false) use ($test_case) {
            return $test_case->options[$name] ?? $default;
        });

        Functions\when('update_option')->alias(static function ($name, $value) use ($test_case) {
            $test_case->options[$name] = $value;

            return true;
        });

        Functions\when('delete_option')->alias(static function ($name) use ($test_case) {
            unset($test_case->options[$name]);

            return true;
        });

        Functions\when('wp_clear_scheduled_hook')->alias(static function ($hook, $args = null) use ($test_case) {
            $test_case->clearedHooks[] = [
                'hook' => (string) $hook,
                'args' => $args,
            ];

            return true;
        });

        Functions\when('do_action')->alias(static function ($hook, ...$args) use ($test_case) {
            $test_case->dispatchedActions[] = [
                'hook' => (string) $hook,
                'args' => $args,
            ];
        });

        Functions\when('error_log')->alias(static function ($message) use ($test_case) {
            $test_case->errorLogs[] = (string) $message;

            return true;
        });

        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_die')->alias(static function ($message) {
            throw new \RuntimeException((string) $message);
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-utils.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-cron.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_it_returns_failure_when_scheduling_the_manual_scan_fails(): void
    {
        Functions\when('wp_schedule_single_event')->justReturn(false);

        $result = \blc_schedule_manual_link_scan(true);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['manual_trigger_failed']);
        $this->assertNotEmpty($this->errorLogs, 'An error should be logged when the schedule fails.');

        $this->assertSame('blc_manual_check_batch', $this->clearedHooks[0]['hook']);

        $status = $this->options['blc_link_scan_status'] ?? [];

        $this->assertSame('failed', $status['state']);
        $this->assertTrue($status['is_full_scan']);
        $this->assertSame($status['message'], $result['message']);
        $this->assertSame(0, $status['started_at']);
        $this->assertGreaterThan(0, $status['ended_at']);

        $this->assertSame('blc_manual_check_schedule_failed', $this->dispatchedActions[0]['hook']);
        $this->assertSame([true, true], $this->dispatchedActions[0]['args']);
    }

    public function test_it_updates_status_and_message_when_scheduling_succeeds(): void
    {
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('spawn_cron')->justReturn(true);

        $result = \blc_schedule_manual_link_scan(false);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['manual_trigger_failed']);
        $this->assertSame([], $this->dispatchedActions, 'No failure action should be triggered on success.');
        $this->assertSame([], $this->errorLogs, 'No error should be logged on success.');

        $status = $this->options['blc_link_scan_status'] ?? [];

        $this->assertSame('queued', $status['state']);
        $this->assertFalse($status['is_full_scan']);
        $this->assertStringContainsString('Analyse programmée.', $status['message']);
        $this->assertStringContainsString("La vérification des liens a été programmée", $result['message']);
        $this->assertSame(0, $status['started_at']);
        $this->assertSame(0, $status['ended_at']);
    }

    public function test_it_marks_manual_trigger_failure_when_spawn_cron_returns_false(): void
    {
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('spawn_cron')->justReturn(false);

        $result = \blc_schedule_manual_link_scan(true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['manual_trigger_failed']);
        $this->assertNotEmpty($this->errorLogs, 'A warning should be logged when the manual trigger fails.');

        $status = $this->options['blc_link_scan_status'] ?? [];

        $this->assertSame('queued', $status['state']);
        $this->assertTrue($status['is_full_scan']);
        $this->assertStringContainsString('Analyse programmée.', $status['message']);
        $this->assertStringContainsString('Le déclenchement immédiat du cron a échoué.', $status['message']);
        $this->assertStringContainsString("La vérification des liens a été programmée", $result['message']);
        $this->assertSame(0, $status['started_at']);
        $this->assertSame(0, $status['ended_at']);
    }
}
}
