<?php

namespace Tests\Scanner;

use Brain\Monkey\Functions;

final class LinkScanControllerTest extends ScannerTestCase
{
    public function test_acquire_lock_generates_token_when_unlocked(): void
    {
        Functions\when('blc_generate_lock_token')->alias(fn() => 'lock-token');

        $token = blc_acquire_link_scan_lock(300);

        $this->assertSame('lock-token', $token);
        $this->assertSame(
            [
                'token'     => 'lock-token',
                'locked_at' => $this->currentTime,
            ],
            $this->options['blc_link_scan_lock'] ?? null,
            'Lock state should be stored with the generated token.'
        );
        $this->assertSame(
            'lock-token',
            $this->options['blc_link_scan_lock_token'] ?? null,
            'Token mirror option should be updated for watchdog checks.'
        );
    }

    public function test_acquire_lock_returns_empty_string_when_lock_is_active(): void
    {
        $this->options['blc_link_scan_lock'] = [
            'token'     => 'existing-token',
            'locked_at' => $this->currentTime,
        ];

        $token = blc_acquire_link_scan_lock(120);

        $this->assertSame('', $token);
        $this->assertSame(
            'existing-token',
            $this->options['blc_link_scan_lock']['token'] ?? null,
            'Existing lock token should remain untouched when lock is active.'
        );
        $this->assertArrayNotHasKey(
            'blc_link_scan_lock_token',
            $this->options,
            'Mirror token option should not be overwritten when acquisition fails.'
        );
    }

    public function test_release_lock_clears_stored_options(): void
    {
        $this->options['blc_link_scan_lock'] = [
            'token'     => 'release-me',
            'locked_at' => $this->currentTime,
        ];
        $this->options['blc_link_scan_lock_token'] = 'release-me';

        blc_release_link_scan_lock('release-me');

        $this->assertArrayNotHasKey('blc_link_scan_lock', $this->options);
        $this->assertArrayNotHasKey('blc_link_scan_lock_token', $this->options);
    }

    public function test_perform_check_reschedules_batch_inside_rest_window(): void
    {
        $this->options['blc_rest_start_hour'] = '08';
        $this->options['blc_rest_end_hour'] = '20';
        $this->options['blc_batch_delay'] = 300;
        $this->options['blc_debug_mode'] = false;

        $this->setCurrentTime(strtotime('2023-01-01 12:34:00 UTC'));

        Functions\when('blc_acquire_link_scan_lock')->alias(fn() => 'token-123');
        Functions\expect('blc_release_link_scan_lock')->once()->with('token-123');
        Functions\when('current_filter')->alias(fn() => 'blc_check_links');

        blc_perform_check(0, true);

        $this->assertCount(1, $this->scheduledEvents, 'A follow-up batch should be scheduled during the rest window.');
        $scheduled = $this->scheduledEvents[0];
        $this->assertSame('blc_check_batch', $scheduled['hook']);
        $this->assertSame([0, true, false], $scheduled['args']);
        $this->assertSame(
            strtotime('2023-01-01 20:00:00 UTC'),
            $scheduled['timestamp'],
            'Next run should align with the configured rest window exit.'
        );
    }
}
