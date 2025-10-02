<?php

namespace JLG\BrokenLinks\Scanner;

use WP_Error;
use WP_Query;

class LinkScanController
{
    private \wpdb $wpdb;
    private ScanQueue $scanQueue;
    private bool $debugMode;
    private int $restStartHour;
    private int $restEndHour;
    private int $batchDelay;
    private int $lockTimeout;
    private int $lastCheckTime;
    private $acquireLock;
    private $releaseLock;
    private $scheduleEvent;
    private $doAction;
    private $errorLogger;
    private $timeProvider;
    private $currentFilter;

    public function __construct(
        \wpdb $wpdb,
        ScanQueue $scanQueue,
        array $options,
        callable $acquireLock,
        callable $releaseLock,
        callable $scheduleEvent,
        callable $doAction,
        callable $errorLogger,
        callable $timeProvider,
        ?callable $currentFilter = null
    ) {
        $this->wpdb = $wpdb;
        $this->scanQueue = $scanQueue;
        $this->debugMode = (bool) ($options['debug_mode'] ?? false);
        $this->restStartHour = (int) ($options['rest_start_hour'] ?? 0);
        $this->restEndHour = (int) ($options['rest_end_hour'] ?? 0);
        $this->batchDelay = (int) ($options['batch_delay'] ?? 0);
        $this->lockTimeout = (int) ($options['lock_timeout'] ?? 0);
        $this->lastCheckTime = (int) ($options['last_check_time'] ?? 0);
        $this->acquireLock = $acquireLock;
        $this->releaseLock = $releaseLock;
        $this->scheduleEvent = $scheduleEvent;
        $this->doAction = $doAction;
        $this->errorLogger = $errorLogger;
        $this->timeProvider = $timeProvider;
        $this->currentFilter = $currentFilter;
    }

    /**
     * @return array{lock_token: string, posts: array<int, \WP_Post>, query: WP_Query, is_full_scan: bool, bypass_rest_window: bool}|WP_Error|null
     */
    public function runBatch(int $batch, bool $isFullScan, bool $bypassRestWindow)
    {
        $currentHook = $this->getCurrentHook();
        if (!$isFullScan && $currentHook === 'blc_check_links') {
            $isFullScan = true;
        }

        if ($currentHook === 'blc_check_links') {
            $bypassRestWindow = false;
        }

        $lockToken = (string) call_user_func($this->acquireLock, $this->lockTimeout);
        if ($lockToken === '') {
            if ($currentHook === 'blc_check_links') {
                if ($this->debugMode) {
                    $this->log('Analyse de liens déjà en cours, reprogrammation du lot.');
                }

                $retryDelay = max(60, $this->batchDelay);
                $this->scheduleRetry(
                    $retryDelay,
                    $batch,
                    $isFullScan,
                    $bypassRestWindow,
                    'lock_unavailable',
                    sprintf('BLC: Failed to reschedule link batch #%d while waiting for lock.', $batch)
                );
                return null;
            }

            return new WP_Error(
                'blc_link_scan_in_progress',
                __('Une analyse des liens est déjà en cours. Veuillez réessayer plus tard.', 'liens-morts-detector-jlg')
            );
        }

        $currentHour = (int) current_time('H');
        if ($this->isInRestWindow($currentHour) && !$bypassRestWindow) {
            if ($this->debugMode) {
                $this->log('Scan arrêté : dans la plage horaire de repos.');
            }

            $this->scheduleRestWindow($batch, $isFullScan, $bypassRestWindow, $currentHour);
            $this->releaseLock($lockToken);
            return null;
        }

        if ($this->isServerLoadTooHigh($batch, $isFullScan, $bypassRestWindow)) {
            $this->releaseLock($lockToken);
            return null;
        }

        $batchData = $this->scanQueue->loadBatch($batch, $isFullScan, $this->lastCheckTime);

        return [
            'lock_token'           => $lockToken,
            'posts'                => $batchData['posts'],
            'query'                => $batchData['query'],
            'is_full_scan'         => $isFullScan,
            'bypass_rest_window'   => $bypassRestWindow,
        ];
    }

    public function releaseLock(string $lockToken): void
    {
        if ($lockToken === '') {
            return;
        }

        call_user_func($this->releaseLock, $lockToken);
    }

    public function scheduleNextBatchIfNeeded(
        WP_Query $query,
        int $currentBatch,
        bool $isFullScan,
        bool $bypassRestWindow
    ): bool {
        return $this->scanQueue->scheduleNextBatchIfNeeded($query, $currentBatch, $isFullScan, $bypassRestWindow);
    }

    public function scheduleRetry(
        int $delaySeconds,
        int $batch,
        bool $isFullScan,
        bool $bypassRestWindow,
        string $reason,
        ?string $failureMessage = null
    ): bool {
        if ($delaySeconds < 0) {
            $delaySeconds = 0;
        }

        $timestamp = $this->now() + $delaySeconds;
        $scheduled = call_user_func(
            $this->scheduleEvent,
            $timestamp,
            'blc_check_batch',
            [$batch, $isFullScan, $bypassRestWindow]
        );

        if (false === $scheduled) {
            if ($failureMessage === null) {
                $failureMessage = sprintf('BLC: Failed to schedule link batch #%d.', $batch);
            }
            $this->log($failureMessage);
            call_user_func(
                $this->doAction,
                'blc_check_batch_schedule_failed',
                $batch,
                $isFullScan,
                $bypassRestWindow,
                $reason
            );
            return false;
        }

        return true;
    }

    public function getLastCheckTime(): int
    {
        return $this->lastCheckTime;
    }

    public function getBatchDelay(): int
    {
        return $this->batchDelay;
    }

    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    private function isInRestWindow(int $currentHour): bool
    {
        if ($this->restStartHour <= $this->restEndHour) {
            return $currentHour >= $this->restStartHour && $currentHour < $this->restEndHour;
        }

        return $currentHour >= $this->restStartHour || $currentHour < $this->restEndHour;
    }

    private function scheduleRestWindow(
        int $batch,
        bool $isFullScan,
        bool $bypassRestWindow,
        int $currentHour
    ): void {
        $currentTimestamp = $this->now();
        $timezone = $this->resolveTimezone();
        $currentDateTime = (new \DateTimeImmutable('@' . $currentTimestamp))->setTimezone($timezone);
        $nextRun = $currentDateTime->setTime($this->restEndHour, 0, 0);

        if ($this->restStartHour > $this->restEndHour) {
            if ($currentHour >= $this->restStartHour) {
                $nextRun = $nextRun->modify('+1 day');
            } elseif ($nextRun <= $currentDateTime) {
                $nextRun = $nextRun->modify('+1 day');
            }
        } elseif ($nextRun <= $currentDateTime) {
            $nextRun = $nextRun->modify('+1 day');
        }

        $nextTimestamp = $nextRun->getTimestamp();
        if ($nextTimestamp <= $currentTimestamp) {
            $nextTimestamp = $currentTimestamp + 60;
        }

        $scheduled = call_user_func(
            $this->scheduleEvent,
            $nextTimestamp,
            'blc_check_batch',
            [$batch, $isFullScan, $bypassRestWindow]
        );

        if (false === $scheduled) {
            $this->log(sprintf('BLC: Failed to schedule link batch #%d during rest window.', $batch));
            call_user_func(
                $this->doAction,
                'blc_check_batch_schedule_failed',
                $batch,
                $isFullScan,
                $bypassRestWindow,
                'rest_window'
            );
        }
    }

    private function resolveTimezone(): \DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            $timezone = wp_timezone();
            if ($timezone instanceof \DateTimeZone) {
                return $timezone;
            }
        }

        if (function_exists('wp_timezone_string')) {
            $timezoneString = wp_timezone_string();
            if (is_string($timezoneString) && $timezoneString !== '') {
                try {
                    return new \DateTimeZone($timezoneString);
                } catch (\Exception $e) {
                    // Fall back to offset handling below.
                }
            }
        }

        $offset = (float) get_option('gmt_offset', 0);
        $offsetSeconds = (int) round($offset * 3600);
        $timezoneName = timezone_name_from_abbr('', $offsetSeconds, 0);

        if (is_string($timezoneName) && $timezoneName !== '') {
            try {
                return new \DateTimeZone($timezoneName);
            } catch (\Exception $e) {
                // Continue to manual offset formatting.
            }
        }

        $sign = $offset >= 0 ? '+' : '-';
        $absOffset = abs($offset);
        $hours = (int) floor($absOffset);
        $minutes = (int) round(($absOffset - $hours) * 60);

        if ($minutes === 60) {
            $hours += 1;
            $minutes = 0;
        }

        $formattedOffset = sprintf('%s%02d:%02d', $sign, $hours, $minutes);

        try {
            return new \DateTimeZone($formattedOffset);
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }

    private function isServerLoadTooHigh(int $batch, bool $isFullScan, bool $bypassRestWindow): bool
    {
        if (!function_exists('sys_getloadavg')) {
            if ($this->debugMode) {
                $this->log('Contrôle de charge ignoré : sys_getloadavg() n\'est pas disponible.');
            }
            return false;
        }

        $loadValues = sys_getloadavg();
        if (!is_array($loadValues) || empty($loadValues)) {
            if ($this->debugMode) {
                $this->log('Contrôle de charge ignoré : sys_getloadavg() n\'a pas retourné de données valides.');
            }
            return false;
        }

        $currentLoad = reset($loadValues);
        if (!is_numeric($currentLoad)) {
            if ($this->debugMode) {
                $this->log('Contrôle de charge ignoré : la première valeur retournée par sys_getloadavg() n\'est pas numérique.');
            }
            return false;
        }

        $currentLoad = (float) $currentLoad;
        $maxLoadThreshold = (float) apply_filters('blc_max_load_threshold', 2.0);

        if ($maxLoadThreshold > 0 && $currentLoad > $maxLoadThreshold) {
            $retryDelay = (int) apply_filters('blc_load_retry_delay', 300);
            if ($retryDelay < 0) {
                $retryDelay = 0;
            }

            if ($this->debugMode) {
                $this->log('Scan reporté : charge serveur trop élevée (' . $currentLoad . ').');
            }

            $this->scheduleRetry(
                $retryDelay,
                $batch,
                $isFullScan,
                $bypassRestWindow,
                'server_load',
                sprintf('BLC: Failed to schedule link batch #%d after high load.', $batch)
            );
            return true;
        }

        return false;
    }

    private function getCurrentHook(): string
    {
        if ($this->currentFilter !== null) {
            return (string) call_user_func($this->currentFilter);
        }

        if (function_exists('current_filter')) {
            return (string) current_filter();
        }

        return '';
    }

    private function now(): int
    {
        return (int) call_user_func($this->timeProvider);
    }

    private function log(string $message): void
    {
        call_user_func($this->errorLogger, $message);
    }
}
