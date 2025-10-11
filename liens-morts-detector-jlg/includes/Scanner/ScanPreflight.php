<?php

namespace JLG\BrokenLinks\Scanner;

class ScanPreflight
{
    /**
     * Collect and normalise the preflight configuration before running a batch.
     *
     * @param int  $batch
     * @param bool $is_full_scan
     * @param bool $bypass_rest_window
     *
     * @return array<string, mixed>
     */
    public function collect($batch, $is_full_scan, $bypass_rest_window)
    {
        $debug_mode = get_option('blc_debug_mode', false);
        $current_hook = function_exists('current_filter') ? current_filter() : '';

        if (!$is_full_scan && $current_hook === 'blc_check_links') {
            $is_full_scan = true;
        }

        if ($current_hook === 'blc_check_links') {
            $bypass_rest_window = false;
        }

        $rest_start_hour_option = get_option('blc_rest_start_hour', '08');
        $rest_end_hour_option   = get_option('blc_rest_end_hour', '20');
        $rest_start_hour = (int) blc_normalize_hour_option($rest_start_hour_option, '08');
        $rest_end_hour   = (int) blc_normalize_hour_option($rest_end_hour_option, '20');
        $link_delay_ms   = max(0, (int) get_option('blc_link_delay', 200));
        $batch_delay_s   = max(0, (int) get_option('blc_batch_delay', 60));
        $default_lock_timeout = defined('MINUTE_IN_SECONDS') ? 15 * MINUTE_IN_SECONDS : 900;
        $lock_timeout = apply_filters('blc_link_scan_lock_timeout', $default_lock_timeout);
        if (!is_int($lock_timeout)) {
            $lock_timeout = (int) $lock_timeout;
        }
        if ($lock_timeout < 0) {
            $lock_timeout = 0;
        }

        return [
            'batch'               => (int) $batch,
            'is_full_scan'        => (bool) $is_full_scan,
            'bypass_rest_window'  => (bool) $bypass_rest_window,
            'debug_mode'          => (bool) $debug_mode,
            'current_hook'        => $current_hook,
            'rest_start_hour'     => $rest_start_hour,
            'rest_end_hour'       => $rest_end_hour,
            'link_delay_ms'       => $link_delay_ms,
            'batch_delay_s'       => $batch_delay_s,
            'lock_timeout'        => $lock_timeout,
        ];
    }
}
