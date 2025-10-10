<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';

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

class LinkScanStatusTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $options = [];

    private int $getOptionCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        $this->options = [];
        $this->getOptionCalls = 0;

        Functions\when('add_action')->justReturn(true);
        Functions\when('apply_filters')->alias(static fn($hook, $value) => $value);
        Functions\when('do_action')->justReturn(null);
        Functions\when('rest_ensure_response')->alias(static fn($value) => $value);

        $testCase = $this;
        Functions\when('get_option')->alias(function ($name, $default = false) use ($testCase) {
            $testCase->getOptionCalls++;

            return $testCase->getStoredOption((string) $name, $default);
        });
        Functions\when('update_option')->alias(function ($name, $value) use ($testCase) {
            $testCase->setStoredOption((string) $name, $value);

            return true;
        });
        Functions\when('delete_option')->alias(function ($name) use ($testCase) {
            $testCase->deleteStoredOption((string) $name);

            return true;
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';

        if (function_exists('\\blc_reset_link_scan_status')) {
            \blc_reset_link_scan_status();
        }
        if (function_exists('\\blc_reset_image_scan_status')) {
            \blc_reset_image_scan_status();
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed
     */
    private function getStoredOption(string $name, $default = false)
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * @param string $name
     * @param mixed  $value
     */
    private function setStoredOption(string $name, $value): void
    {
        $this->options[$name] = $value;
    }

    private function deleteStoredOption(string $name): void
    {
        unset($this->options[$name]);
    }

    public function test_get_link_scan_status_uses_request_cache(): void
    {
        $this->options['blc_link_scan_status'] = [
            'state' => 'running',
        ];

        $first = \blc_get_link_scan_status();
        $second = \blc_get_link_scan_status();

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->getOptionCalls);
    }

    public function test_get_link_scan_status_enforces_defaults(): void
    {
        $this->options['blc_link_scan_status'] = [
            'state' => 'invalid state',
            'current_batch' => -5,
            'processed_batches' => '3',
            'total_batches' => 'NaN',
            'remaining_batches' => 7,
            'is_full_scan' => '1',
            'message' => ['oops'],
            'last_error' => 123,
            'started_at' => '12',
            'ended_at' => -4,
            'updated_at' => '44',
            'total_items' => -2,
            'processed_items' => '8',
        ];

        $status = \blc_get_link_scan_status();

        $this->assertSame('idle', $status['state']);
        $this->assertSame(0, $status['current_batch']);
        $this->assertSame(3, $status['processed_batches']);
        $this->assertSame(0, $status['total_batches']);
        $this->assertSame(7, $status['remaining_batches']);
        $this->assertTrue($status['is_full_scan']);
        $this->assertSame('', $status['message']);
        $this->assertSame('', $status['last_error']);
        $this->assertSame(12, $status['started_at']);
        $this->assertSame(0, $status['ended_at']);
        $this->assertSame(44, $status['updated_at']);
        $this->assertSame(0, $status['total_items']);
        $this->assertSame(8, $status['processed_items']);
    }

    public function test_get_link_scan_status_cache_is_flushed_on_reset(): void
    {
        $this->options['blc_link_scan_status'] = [
            'state' => 'running',
        ];

        \blc_get_link_scan_status();
        $this->assertSame(1, $this->getOptionCalls);

        \blc_reset_link_scan_status();

        $this->options['blc_link_scan_status'] = [
            'state' => 'completed',
        ];

        $this->getOptionCalls = 0;
        $status = \blc_get_link_scan_status();

        $this->assertSame('completed', $status['state']);
        $this->assertSame(1, $this->getOptionCalls);
    }

    public function test_get_link_scan_status_backfills_completed_end_time(): void
    {
        $this->options['blc_link_scan_status'] = [
            'state' => 'completed',
            'ended_at' => 0,
            'updated_at' => 167,
        ];

        $status = \blc_get_link_scan_status();

        $this->assertSame(167, $status['ended_at']);
    }

    public function test_update_link_scan_status_sets_started_timestamp_when_entering_running(): void
    {
        Functions\when('time')->alias(static fn() => 100);

        $status = \blc_update_link_scan_status([
            'state' => 'running',
        ]);

        $this->assertSame('running', $status['state']);
        $this->assertSame(100, $status['started_at']);
        $this->assertSame(100, $status['updated_at']);
        $this->assertSame(0, $status['ended_at']);
    }

    public function test_update_link_scan_status_sets_ended_timestamp_when_finishing(): void
    {
        Functions\when('time')->alias(static fn() => 100);
        \blc_update_link_scan_status(['state' => 'running']);

        Functions\when('time')->alias(static fn() => 160);
        $status = \blc_update_link_scan_status(['state' => 'completed']);

        $this->assertSame('completed', $status['state']);
        $this->assertSame(100, $status['started_at']);
        $this->assertSame(160, $status['ended_at']);
        $this->assertSame(160, $status['updated_at']);
    }

    public function test_update_link_scan_status_resets_timestamps_when_idle(): void
    {
        Functions\when('time')->alias(static fn() => 50);
        \blc_update_link_scan_status(['state' => 'running']);

        Functions\when('time')->alias(static fn() => 75);
        $status = \blc_update_link_scan_status(['state' => 'idle']);

        $this->assertSame('idle', $status['state']);
        $this->assertSame(0, $status['started_at']);
        $this->assertSame(0, $status['ended_at']);
        $this->assertSame(75, $status['updated_at']);
    }

    public function test_update_link_scan_status_records_transition_log(): void
    {
        $now = 10;
        Functions\when('time')->alias(static function () use (&$now) {
            return $now;
        });

        $status = \blc_update_link_scan_status([
            'state'  => 'running',
            'job_id' => 'job-123',
        ]);

        $this->assertSame('running', $status['state']);

        $log = \blc_get_link_scan_transition_log();

        $this->assertNotEmpty($log);
        $entry = $log[0];

        $this->assertSame('idle', $entry['from']);
        $this->assertSame('running', $entry['to']);
        $this->assertTrue($entry['valid']);
        $this->assertSame('job-123', $entry['job_id']);
        $this->assertSame(10, $entry['timestamp']);
    }

    public function test_update_link_scan_status_rejects_invalid_transition(): void
    {
        $now = 100;
        Functions\when('time')->alias(static function () use (&$now) {
            return $now;
        });

        \blc_update_link_scan_status([
            'state'  => 'running',
            'job_id' => 'job-999',
        ]);

        $now = 200;

        \blc_update_link_scan_status([
            'state' => 'failed',
        ]);

        $now = 300;

        $status = \blc_update_link_scan_status([
            'state' => 'running',
        ]);

        $this->assertSame('failed', $status['state']);
        $this->assertStringContainsString('Transition d\'état invalide', $status['last_error']);

        $log = \blc_get_link_scan_transition_log();

        $this->assertNotEmpty($log);
        $entry = $log[0];

        $this->assertFalse($entry['valid']);
        $this->assertSame('failed', $entry['from']);
        $this->assertSame('running', $entry['to']);
        $this->assertSame('job-999', $entry['job_id']);
        $this->assertSame(300, $entry['timestamp']);
        $this->assertStringContainsString('Transition d\'état invalide', $entry['reason']);
    }

    public function test_get_image_scan_status_preserves_is_full_scan_flag(): void
    {
        $this->options['blc_image_scan_status'] = [
            'state' => 'running',
            'is_full_scan' => false,
        ];

        $status = \blc_get_image_scan_status();

        $this->assertFalse($status['is_full_scan']);
    }

    public function test_update_image_scan_status_accepts_partial_runs(): void
    {
        Functions\when('time')->alias(static fn() => 200);

        $status = \blc_update_image_scan_status([
            'state' => 'running',
            'is_full_scan' => false,
        ]);

        $this->assertFalse($status['is_full_scan']);
        $this->assertFalse($this->options['blc_image_scan_status']['is_full_scan']);
    }

    public function test_update_image_scan_status_records_transition_log(): void
    {
        $now = 100;
        Functions\when('time')->alias(static function () use (&$now) {
            return $now;
        });

        \blc_update_image_scan_status([
            'state'    => 'queued',
            'job_id'   => 'img-job',
            'attempt'  => 1,
        ]);

        $now = 140;

        $status = \blc_update_image_scan_status([
            'state' => 'running',
        ]);

        $this->assertSame('running', $status['state']);

        $log = \blc_get_image_scan_transition_log();
        $this->assertNotEmpty($log);
        $entry = $log[0];

        $this->assertTrue($entry['valid']);
        $this->assertSame('queued', $entry['from']);
        $this->assertSame('running', $entry['to']);
        $this->assertSame('img-job', $entry['job_id']);
        $this->assertSame(140, $entry['timestamp']);
    }

    public function test_update_image_scan_status_rejects_invalid_transition(): void
    {
        $now = 50;
        Functions\when('time')->alias(static function () use (&$now) {
            return $now;
        });

        \blc_update_image_scan_status([
            'state'  => 'running',
            'job_id' => 'img-123',
        ]);

        $now = 90;
        \blc_update_image_scan_status([
            'state' => 'failed',
        ]);

        $now = 120;
        $status = \blc_update_image_scan_status([
            'state' => 'running',
        ]);

        $this->assertSame('failed', $status['state']);
        $this->assertStringContainsString('Transition d\'état invalide', $status['last_error']);

        $log = \blc_get_image_scan_transition_log();
        $this->assertNotEmpty($log);
        $entry = $log[0];

        $this->assertFalse($entry['valid']);
        $this->assertSame('failed', $entry['from']);
        $this->assertSame('running', $entry['to']);
        $this->assertSame('img-123', $entry['job_id']);
        $this->assertSame(120, $entry['timestamp']);
    }

    public function test_update_image_scan_status_updates_history_entry(): void
    {
        $now = 200;
        Functions\when('time')->alias(static function () use (&$now) {
            return $now;
        });

        \blc_append_image_scan_history_entry([
            'job_id' => 'img-queue',
            'state'  => 'queued',
        ]);

        $now = 240;
        $status = \blc_update_image_scan_status([
            'state'           => 'running',
            'job_id'          => 'img-queue',
            'attempt'         => 3,
            'processed_items' => 5,
            'total_items'     => 10,
        ]);

        $this->assertSame('running', $status['state']);

        $history = \blc_get_image_scan_history();
        $this->assertNotEmpty($history);
        $entry = $history[0];

        $this->assertSame('img-queue', $entry['job_id']);
        $this->assertSame('running', $entry['state']);
        $this->assertSame(3, $entry['attempt']);
        $this->assertSame(5, $entry['processed_items']);
        $this->assertSame(10, $entry['total_items']);
        $this->assertSame($status['updated_at'], $entry['updated_at']);
    }

    public function test_get_link_scan_status_payload_includes_metrics(): void
    {
        $now = 200;
        Functions\when('time')->alias(static function () use (&$now) {
            return $now;
        });
        Functions\when('blc_get_link_scan_lock_state')->justReturn(['locked_at' => 0]);
        Functions\when('blc_is_link_scan_lock_active')->alias(static fn() => false);
        Functions\when('blc_get_next_link_batch_timestamp')->alias(static fn() => 0);

        $this->options['blc_link_scan_status'] = [
            'state' => 'running',
            'started_at' => 100,
            'updated_at' => 190,
            'processed_items' => 10,
            'total_items' => 40,
        ];

        $payload = \blc_get_link_scan_status_payload();

        $this->assertSame(25.0, $payload['progress_percentage']);
        $this->assertSame(30, $payload['remaining_items']);
        $this->assertSame(100, $payload['duration_seconds']);
        $this->assertSame(6.0, $payload['items_per_minute']);
        $this->assertSame(300, $payload['estimated_remaining_seconds']);
        $this->assertSame(500, $payload['estimated_completion_timestamp']);
        $this->assertSame(10, $payload['last_activity_delta']);
        $this->assertArrayHasKey('lock_active', $payload);
    }

    public function test_reset_link_scan_status_deletes_option(): void
    {
        $this->options['blc_link_scan_status'] = ['state' => 'completed'];

        \blc_reset_link_scan_status();

        $this->assertArrayNotHasKey('blc_link_scan_status', $this->options);
    }
}

}
