<?php

namespace JLG\BrokenLinks\Scanner;

require_once __DIR__ . '/ScanQueue.php';

use WP_Error;

class ScanLockManager
{
    /**
     * @var QueueDriverInterface|null
     */
    private $queueDriver;

    public function __construct(?QueueDriverInterface $queueDriver = null)
    {
        $this->queueDriver = $queueDriver;
    }

    /**
     * Ensure there is a job identifier stored in the shared status payload.
     */
    public function ensureActiveJobId(): string
    {
        if (!function_exists('\\blc_get_link_scan_status')) {
            return '';
        }

        $status = \blc_get_link_scan_status();
        $job_id = isset($status['job_id']) ? (string) $status['job_id'] : '';

        if ($job_id !== '') {
            return $job_id;
        }

        $job_id = function_exists('\\blc_generate_link_scan_job_id')
            ? \blc_generate_link_scan_job_id()
            : uniqid('blc_', true);

        $attempt = isset($status['attempt']) ? max(1, (int) $status['attempt']) : 1;

        \blc_update_link_scan_status([
            'job_id'  => $job_id,
            'attempt' => $attempt,
        ]);

        return $job_id;
    }

    /**
     * Attempt to acquire the scan lock or reschedule depending on the hook context.
     *
     * @param string $current_hook
     * @param int    $batch
     * @param bool   $is_full_scan
     * @param bool   $bypass_rest_window
     * @param int    $batch_delay_s
     * @param int    $lock_timeout
     * @param bool   $debug_mode
     *
     * @return array{status:string,lock_token?:string,error?:WP_Error}
     */
    public function acquireOrReschedule(
        $current_hook,
        $batch,
        $is_full_scan,
        $bypass_rest_window,
        $batch_delay_s,
        $lock_timeout,
        $debug_mode,
        array $jobContext = []
    ) {
        $lock_token = blc_acquire_link_scan_lock($lock_timeout);

        if ($lock_token !== '') {
            return [
                'status'     => 'acquired',
                'lock_token' => $lock_token,
            ];
        }

        if ($current_hook === 'blc_check_links') {
            if ($debug_mode) {
                error_log('Analyse de liens déjà en cours, reprogrammation du lot.');
            }

            $retry_delay = max(60, $batch_delay_s);
            $this->scheduleBatch($batch, $is_full_scan, $bypass_rest_window, $jobContext, $retry_delay, 'lock_unavailable');

            return [
                'status' => 'rescheduled',
            ];
        }

        \blc_update_link_scan_status([
            'state'   => 'running',
            'message' => \__('Une analyse des liens est déjà en cours. Veuillez réessayer plus tard.', 'liens-morts-detector-jlg'),
        ]);

        return [
            'status' => 'error',
            'error'  => new WP_Error(
                'blc_link_scan_in_progress',
                __('Une analyse des liens est déjà en cours. Veuillez réessayer plus tard.', 'liens-morts-detector-jlg')
            ),
        ];
    }

    /**
     * Check the rest window and reschedule if necessary.
     *
     * @param array<string, mixed> $preflight
     * @param string               $lock_token
     */
    public function deferDuringRestWindow(array $preflight, $lock_token, $debug_mode, array $jobContext): array
    {
        $batch              = $preflight['batch'];
        $is_full_scan       = $preflight['is_full_scan'];
        $bypass_rest_window = $preflight['bypass_rest_window'];
        $rest_start_hour    = $preflight['rest_start_hour'];
        $rest_end_hour      = $preflight['rest_end_hour'];

        $current_hour = (int) current_time('H');
        $is_in_rest_window = false;

        if ($rest_start_hour <= $rest_end_hour) {
            $is_in_rest_window = ($current_hour >= $rest_start_hour && $current_hour < $rest_end_hour);
        } else {
            $is_in_rest_window = ($current_hour >= $rest_start_hour || $current_hour < $rest_end_hour);
        }

        if (!$is_in_rest_window || $bypass_rest_window) {
            return [
                'deferred'   => false,
                'lock_token' => $lock_token,
            ];
        }

        if ($debug_mode) {
            error_log('Scan arrêté : dans la plage horaire de repos.');
        }

        $current_gmt_timestamp = time();
        $timezone              = null;

        if (function_exists('wp_timezone')) {
            $maybe_timezone = wp_timezone();
            if ($maybe_timezone instanceof \DateTimeZone) {
                $timezone = $maybe_timezone;
            }
        }

        if (!$timezone instanceof \DateTimeZone && function_exists('wp_timezone_string')) {
            $timezone_string = wp_timezone_string();
            if (is_string($timezone_string) && $timezone_string !== '') {
                try {
                    $timezone = new \DateTimeZone($timezone_string);
                } catch (\Exception $e) {
                    $timezone = null;
                }
            }
        }

        if (!$timezone instanceof \DateTimeZone) {
            $offset         = (float) get_option('gmt_offset', 0);
            $offset_seconds = (int) round($offset * 3600);
            $timezone_name  = timezone_name_from_abbr('', $offset_seconds, 0);

            if (is_string($timezone_name) && $timezone_name !== '') {
                try {
                    $timezone = new \DateTimeZone($timezone_name);
                } catch (\Exception $e) {
                    $timezone = null;
                }
            }

            if (!$timezone instanceof \DateTimeZone) {
                $sign       = $offset >= 0 ? '+' : '-';
                $abs_offset = abs($offset);
                $hours      = (int) floor($abs_offset);
                $minutes    = (int) round(($abs_offset - $hours) * 60);

                if ($minutes === 60) {
                    $hours  += 1;
                    $minutes = 0;
                }

                $formatted_offset = sprintf('%s%02d:%02d', $sign, $hours, $minutes);

                try {
                    $timezone = new \DateTimeZone($formatted_offset);
                } catch (\Exception $e) {
                    $timezone = new \DateTimeZone('UTC');
                }
            }
        }

        if (!$timezone instanceof \DateTimeZone) {
            $timezone = new \DateTimeZone('UTC');
        }

        $current_datetime = (new \DateTimeImmutable('@' . $current_gmt_timestamp))->setTimezone($timezone);
        $next_run         = $current_datetime->setTime($rest_end_hour, 0, 0);

        if ($rest_start_hour > $rest_end_hour) {
            if ($current_hour >= $rest_start_hour) {
                $next_run = $next_run->modify('+1 day');
            } elseif ($next_run <= $current_datetime) {
                $next_run = $next_run->modify('+1 day');
            }
        } elseif ($next_run <= $current_datetime) {
            $next_run = $next_run->modify('+1 day');
        }

        $next_timestamp = $next_run->getTimestamp();

        if ($next_timestamp <= $current_gmt_timestamp) {
            $next_timestamp = $current_gmt_timestamp + 60;
        }

        $delay_seconds = max(0, $next_timestamp - time());
        $this->scheduleBatch($batch, $is_full_scan, $bypass_rest_window, $jobContext, $delay_seconds, 'rest_window');

        if ($lock_token !== '') {
            blc_release_link_scan_lock($lock_token);
            $lock_token = '';
        }

        return [
            'deferred'   => true,
            'lock_token' => $lock_token,
        ];
    }

    /**
     * Defer scanning when server load is too high.
     *
     * @param array<string, mixed> $preflight
     * @param string               $lock_token
     */
    public function deferForServerLoad(array $preflight, $lock_token, $debug_mode, array $jobContext): array
    {
        if (!function_exists('sys_getloadavg')) {
            return [
                'deferred'   => false,
                'lock_token' => $lock_token,
            ];
        }

        $load_values = sys_getloadavg();
        if (!is_array($load_values) || empty($load_values)) {
            return [
                'deferred'   => false,
                'lock_token' => $lock_token,
            ];
        }

        $current_load = reset($load_values);
        if (!is_numeric($current_load)) {
            return [
                'deferred'   => false,
                'lock_token' => $lock_token,
            ];
        }

        $current_load        = (float) $current_load;
        $max_load_threshold  = (float) apply_filters('blc_max_load_threshold', 2.0);
        $batch               = $preflight['batch'];
        $is_full_scan        = $preflight['is_full_scan'];
        $bypass_rest_window  = $preflight['bypass_rest_window'];

        if ($max_load_threshold <= 0 || $current_load <= $max_load_threshold) {
            return [
                'deferred'   => false,
                'lock_token' => $lock_token,
            ];
        }

        $retry_delay = (int) apply_filters('blc_load_retry_delay', 300);
        if ($retry_delay < 0) {
            $retry_delay = 0;
        }

        if ($debug_mode) {
            error_log('Scan reporté : charge serveur trop élevée (' . $current_load . ').');
        }

        $this->scheduleBatch($batch, $is_full_scan, $bypass_rest_window, $jobContext, $retry_delay, 'server_load');

        if ($lock_token !== '') {
            blc_release_link_scan_lock($lock_token);
            $lock_token = '';
        }

        return [
            'deferred'   => true,
            'lock_token' => $lock_token,
        ];
    }

    private function scheduleBatch(int $batch, bool $is_full_scan, bool $bypass_rest_window, array $jobContext, int $delay_seconds, string $reason): void
    {
        $delay_seconds = max(0, $delay_seconds);

        $job = [
            'batch'               => (int) $batch,
            'is_full_scan'        => (bool) $is_full_scan,
            'bypass_rest_window'  => (bool) $bypass_rest_window,
            'context'             => $jobContext,
        ];

        $scheduled = false;

        if ($this->queueDriver instanceof QueueDriverInterface) {
            $scheduled = $this->queueDriver->scheduleBatch($job, $delay_seconds);
        }

        if (!$scheduled) {
            $timestamp = time() + $delay_seconds;
            $args = [$batch, $is_full_scan, $bypass_rest_window, $jobContext];
            $scheduled = wp_schedule_single_event($timestamp, 'blc_check_batch', $args);
        }

        if (!$scheduled && function_exists('error_log')) {
            error_log(sprintf('BLC: Failed to schedule link batch #%d (%s).', $batch, $reason));
        }

        if (function_exists('do_action')) {
            if (!$scheduled) {
                do_action('blc_check_batch_schedule_failed', $batch, $is_full_scan, $bypass_rest_window, $reason, $job);
            } else {
                do_action('blc_queue_job_scheduled', $job, $delay_seconds, $reason, $this->queueDriver);
            }
        }
    }
}

