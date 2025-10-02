<?php

namespace JLG\BrokenLinks\Scanner;

use WP_Query;

class ScanQueue
{
    private \wpdb $wpdb;
    private int $batchSize;
    private int $batchDelay;
    private $scheduleEvent;
    private $doAction;
    private $errorLogger;
    private $timeProvider;
    private $postStatusResolver;
    private $publicPostTypesResolver;

    public function __construct(
        \wpdb $wpdb,
        int $batchSize,
        int $batchDelay,
        callable $scheduleEvent,
        callable $doAction,
        callable $errorLogger,
        callable $timeProvider,
        ?callable $postStatusResolver = null,
        ?callable $publicPostTypesResolver = null
    ) {
        $this->wpdb = $wpdb;
        $this->batchSize = $batchSize;
        $this->batchDelay = max(0, $batchDelay);
        $this->scheduleEvent = $scheduleEvent;
        $this->doAction = $doAction;
        $this->errorLogger = $errorLogger;
        $this->timeProvider = $timeProvider;
        $this->postStatusResolver = $postStatusResolver;
        $this->publicPostTypesResolver = $publicPostTypesResolver;
    }

    /**
     * @return array{posts: array<int, \WP_Post>, query: WP_Query}
     */
    public function loadBatch(int $batch, bool $isFullScan, int $lastCheckTime): array
    {
        $publicPostTypes = $this->resolvePublicPostTypes();
        if ($publicPostTypes === []) {
            $publicPostTypes = ['post'];
        }

        $args = [
            'post_type'      => $publicPostTypes,
            'post_status'    => $this->resolvePostStatuses(),
            'posts_per_page' => $this->batchSize,
            'paged'          => $batch + 1,
        ];

        if (!$isFullScan && $lastCheckTime > 0) {
            $threshold = gmdate('Y-m-d H:i:s', $lastCheckTime);
            $args['date_query'] = [[
                'column' => 'post_modified_gmt',
                'after'  => $threshold,
            ]];
        }

        $query = new WP_Query($args);

        return [
            'posts' => $query->posts,
            'query' => $query,
        ];
    }

    public function scheduleNextBatchIfNeeded(
        WP_Query $query,
        int $currentBatch,
        bool $isFullScan,
        bool $bypassRestWindow
    ): bool {
        if ($query->max_num_pages > ($currentBatch + 1)) {
            $timestamp = $this->now() + $this->batchDelay;
            $scheduled = call_user_func(
                $this->scheduleEvent,
                $timestamp,
                'blc_check_batch',
                [$currentBatch + 1, $isFullScan, $bypassRestWindow]
            );

            if (false === $scheduled) {
                call_user_func(
                    $this->errorLogger,
                    sprintf('BLC: Failed to schedule next link batch #%d.', $currentBatch + 1)
                );
                call_user_func(
                    $this->doAction,
                    'blc_check_batch_schedule_failed',
                    $currentBatch + 1,
                    $isFullScan,
                    $bypassRestWindow,
                    'next_batch'
                );
            }

            return true;
        }

        return false;
    }

    public function getBatchDelay(): int
    {
        return $this->batchDelay;
    }

    private function resolvePostStatuses(): array
    {
        if ($this->postStatusResolver !== null) {
            $statuses = call_user_func($this->postStatusResolver);
            if (is_array($statuses)) {
                return $statuses;
            }
        }

        $stored_statuses = blc_get_scannable_post_statuses();
        return is_array($stored_statuses) ? $stored_statuses : ['publish'];
    }

    private function resolvePublicPostTypes(): array
    {
        if ($this->publicPostTypesResolver !== null) {
            $postTypes = call_user_func($this->publicPostTypesResolver);
            if (is_array($postTypes)) {
                return $this->normalizePostTypes($postTypes);
            }
        }

        $postTypes = get_post_types(['public' => true], 'names');
        if (!is_array($postTypes)) {
            $postTypes = [];
        }

        return $this->normalizePostTypes($postTypes);
    }

    private function normalizePostTypes(array $postTypes): array
    {
        return array_values(
            array_filter(
                array_map('strval', $postTypes),
                static function ($postType) {
                    return $postType !== '';
                }
            )
        );
    }

    private function now(): int
    {
        return (int) call_user_func($this->timeProvider);
    }
}
