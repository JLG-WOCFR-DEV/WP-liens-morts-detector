<?php

namespace JLG\BrokenLinks\Scanner\QueueDrivers;

require_once __DIR__ . '/../ScanQueue.php';

use JLG\BrokenLinks\Scanner\QueueDriverInterface;

class WpCronQueueDriver implements QueueDriverInterface
{
    /**
     * @var string
     */
    private $hook;

    public function __construct(string $hook = 'blc_check_batch')
    {
        $this->hook = $hook;
    }

    public function getSlug(): string
    {
        return 'wp_cron';
    }

    public function getLabel(): string
    {
        if (function_exists('__')) {
            return __('WP-Cron natif', 'liens-morts-detector-jlg');
        }

        return 'WP-Cron';
    }

    public function scheduleBatch(array $job, int $delaySeconds = 0): bool
    {
        $delaySeconds = max(0, $delaySeconds);
        $timestamp = time() + $delaySeconds;

        $batch = isset($job['batch']) ? (int) $job['batch'] : 0;
        $is_full_scan = isset($job['is_full_scan']) ? (bool) $job['is_full_scan'] : false;
        $bypass_rest_window = isset($job['bypass_rest_window']) ? (bool) $job['bypass_rest_window'] : false;
        $context = isset($job['context']) && is_array($job['context']) ? $job['context'] : [];

        $result = wp_schedule_single_event($timestamp, $this->hook, [$batch, $is_full_scan, $bypass_rest_window, $context]);

        if (false === $result && function_exists('do_action')) {
            do_action('blc_queue_schedule_failed', $job, $delaySeconds, $this);
        }

        return false !== $result;
    }

    public function receiveBatch(): ?array
    {
        return null;
    }

    public function acknowledge(array $job): void
    {
        // Nothing to do – WP-Cron removes the event when executed.
    }

    public function reportFailure(array $job, \Throwable $error): void
    {
        if (function_exists('do_action')) {
            do_action('blc_queue_job_failed', $job, $error, $this);
        }
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function supportsAsyncPull(): bool
    {
        return false;
    }
}

