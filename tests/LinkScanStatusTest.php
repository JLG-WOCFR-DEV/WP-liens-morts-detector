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

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        $this->options = [];

        Functions\when('add_action')->justReturn(true);
        Functions\when('apply_filters')->alias(static fn($hook, $value) => $value);
        Functions\when('do_action')->justReturn(null);
        Functions\when('rest_ensure_response')->alias(static fn($value) => $value);

        $testCase = $this;
        Functions\when('get_option')->alias(function ($name, $default = false) use ($testCase) {
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

    public function test_reset_link_scan_status_deletes_option(): void
    {
        $this->options['blc_link_scan_status'] = ['state' => 'completed'];

        \blc_reset_link_scan_status();

        $this->assertArrayNotHasKey('blc_link_scan_status', $this->options);
    }
}

}
