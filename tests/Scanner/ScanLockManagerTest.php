<?php

namespace {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

namespace Tests\Scanner {

use Brain\Monkey\Functions;
use JLG\BrokenLinks\Scanner\ScanLockManager;
use WP_Error;

final class ScanLockManagerTest extends ScannerTestCase
{
    public function test_ensure_active_job_id_generates_identifier(): void
    {
        $statusUpdates = [];
        Functions\when('blc_get_link_scan_status')->alias(fn() => ['attempt' => 2]);
        Functions\when('blc_generate_link_scan_job_id')->alias(fn() => 'job-xyz');
        Functions\when('blc_update_link_scan_status')->alias(function ($status) use (&$statusUpdates) {
            $statusUpdates[] = $status;
        });

        $manager = new ScanLockManager();
        $jobId = $manager->ensureActiveJobId();

        self::assertSame('job-xyz', $jobId);
        self::assertCount(1, $statusUpdates);
        self::assertSame('job-xyz', $statusUpdates[0]['job_id']);
        self::assertSame(2, $statusUpdates[0]['attempt']);
    }

    public function test_acquire_or_reschedule_acquires_lock(): void
    {
        Functions\when('blc_acquire_link_scan_lock')->alias(fn() => 'token-123');

        $manager = new ScanLockManager();
        $result = $manager->acquireOrReschedule('manual', 1, true, false, 60, 120, false);

        self::assertSame('acquired', $result['status']);
        self::assertSame('token-123', $result['lock_token']);
    }

    public function test_acquire_or_reschedule_reschedules_when_busy_during_cron(): void
    {
        $this->scheduledEvents = [];
        Functions\when('blc_acquire_link_scan_lock')->alias(fn() => '');
        Functions\when('wp_schedule_single_event')->alias(function ($timestamp, $hook, $args = []) {
            $this->scheduledEvents[] = [
                'timestamp' => $timestamp,
                'hook'      => $hook,
                'args'      => $args,
            ];
            return true;
        });

        $manager = new ScanLockManager();
        $result = $manager->acquireOrReschedule('blc_check_links', 2, false, false, 90, 0, false);

        self::assertSame('rescheduled', $result['status']);
        self::assertCount(1, $this->scheduledEvents);
        self::assertSame('blc_check_batch', $this->scheduledEvents[0]['hook']);
        self::assertSame([2, false, false], $this->scheduledEvents[0]['args']);
    }

    public function test_acquire_or_reschedule_returns_error_for_manual_runs(): void
    {
        $statusUpdates = [];
        Functions\when('blc_acquire_link_scan_lock')->alias(fn() => '');
        Functions\when('blc_update_link_scan_status')->alias(function ($status) use (&$statusUpdates) {
            $statusUpdates[] = $status;
        });

        $manager = new ScanLockManager();
        $result = $manager->acquireOrReschedule('manual', 3, false, false, 30, 0, false);

        self::assertSame('error', $result['status']);
        self::assertInstanceOf(WP_Error::class, $result['error']);
        self::assertNotEmpty($statusUpdates);
        self::assertSame('running', $statusUpdates[0]['state']);
    }

    public function test_defer_during_rest_window_releases_lock_and_schedules(): void
    {
        $this->setCurrentTime(strtotime('2023-01-01 23:15:00'));
        $this->scheduledEvents = [];
        Functions\when('wp_schedule_single_event')->alias(function ($timestamp, $hook, $args = []) {
            $this->scheduledEvents[] = compact('timestamp', 'hook', 'args');
            return true;
        });
        Functions\when('blc_release_link_scan_lock')->alias(function ($token) use (&$releasedToken) {
            $releasedToken = $token;
        });

        $manager = new ScanLockManager();
        $preflight = [
            'batch'              => 1,
            'is_full_scan'       => false,
            'bypass_rest_window' => false,
            'rest_start_hour'    => 22,
            'rest_end_hour'      => 6,
        ];

        $result = $manager->deferDuringRestWindow($preflight, 'token', true);

        self::assertTrue($result['deferred']);
        self::assertSame('', $result['lock_token']);
        self::assertSame('token', $releasedToken ?? null);
        self::assertNotEmpty($this->scheduledEvents);
        self::assertSame('blc_check_batch', $this->scheduledEvents[0]['hook']);
    }

    public function test_defer_for_server_load_reschedules_when_threshold_exceeded(): void
    {
        Functions\when('sys_getloadavg')->alias(fn() => [5.0, 5.0, 5.0]);
        Functions\when('blc_release_link_scan_lock')->alias(function ($token) use (&$releasedToken) {
            $releasedToken = $token;
        });

        $manager = new ScanLockManager();
        $preflight = [
            'batch'             => 4,
            'is_full_scan'      => true,
            'bypass_rest_window'=> false,
        ];

        $result = $manager->deferForServerLoad($preflight, 'token-load', true);

        self::assertTrue($result['deferred']);
        self::assertSame('', $result['lock_token']);
        self::assertSame('token-load', $releasedToken ?? null);
    }
}

}
