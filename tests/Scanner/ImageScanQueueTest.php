<?php

namespace {
    if (!class_exists('WP_Query')) {
        class WP_Query
        {
            public $posts = [];

            public $max_num_pages = 0;

            public $found_posts = 0;

            public $args = [];

            public function __construct($args = [])
            {
                $this->args = $args;
                $scenario = [];
                if (!empty($GLOBALS['wp_query_queue'])) {
                    $scenario = array_shift($GLOBALS['wp_query_queue']);
                }

                $this->posts = $scenario['posts'] ?? [];
                $this->max_num_pages = $scenario['max_num_pages'] ?? 0;
                if (isset($scenario['found_posts'])) {
                    $this->found_posts = (int) $scenario['found_posts'];
                } else {
                    $this->found_posts = is_array($this->posts) ? count($this->posts) : 0;
                }
            }
        }
    }
}

namespace Tests\Scanner {

use Brain\Monkey\Functions;

class ImageScanQueueTest extends ScannerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_basename')->alias('basename');
        Functions\when('get_permalink')->alias(fn($post) => 'https://example.com/sample-post');
        Functions\when('blc_adjust_dataset_storage_footprint')->alias(function () {
        });
        Functions\when('blc_refresh_image_scan_lock')->alias(function () {
        });
        Functions\when('blc_is_safe_remote_host')->alias(fn() => true);
        Functions\when('file_exists')->alias(fn() => false);
        Functions\when('get_post_types')->alias(fn($args = [], $output = 'names') => ['post']);
        Functions\when('get_post_stati')->alias(fn($args = [], $output = 'names') => ['publish']);
        Functions\when('wp_reset_postdata')->alias(function () {
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_query_queue'], $GLOBALS['wp_query_last_args']);
        parent::tearDown();
    }

    public function test_run_reschedules_when_lock_unavailable(): void
    {
        Functions\expect('blc_acquire_image_scan_lock')->once()->andReturn('');

        $queue = new \ImageScanQueue();
        $queue->run(0, true);

        $this->assertCount(1, $this->scheduledEvents);
        $event = $this->scheduledEvents[0];
        $this->assertSame('blc_check_image_batch', $event['hook']);
        $this->assertSame([0, true], $event['args']);
        $this->assertSame([], $this->wpdb->insertedRows);
    }

    public function test_run_records_remote_images_when_enabled(): void
    {
        $this->options['blc_remote_image_scan_enabled'] = true;
        $GLOBALS['wp_query_queue'] = [[
            'posts' => [
                (object) [
                    'ID'           => 111,
                    'post_title'   => 'Remote image allowed',
                    'post_content' => '<p><img src="https://cdn.example.net/wp-content/uploads/2024/05/photo.jpg" /></p>',
                ],
            ],
            'max_num_pages' => 1,
        ]];

        Functions\expect('blc_acquire_image_scan_lock')->once()->andReturn('remote-lock');
        Functions\expect('blc_generate_scan_run_token')->once()->andReturn('run-token');
        Functions\expect('blc_stage_dataset_refresh')->once()->andReturn(true);
        Functions\expect('blc_commit_dataset_refresh')->once()->andReturn(true);
        Functions\expect('blc_maybe_send_scan_summary')->once()->with('image');

        $release_calls = [];
        Functions\when('blc_release_image_scan_lock')->alias(function ($token) use (&$release_calls) {
            $release_calls[] = $token;
        });

        $queue = new \ImageScanQueue();
        $queue->run(0, true);

        $this->assertCount(1, $this->wpdb->insertedRows, 'Remote broken image should be recorded when scanning is enabled.');
        $row = $this->wpdb->insertedRows[0];
        $this->assertSame('remote-image', $row['data']['type']);
        $this->assertSame('remote-lock', $release_calls[0] ?? null, 'Lock should be released after successful remote scan.');
    }

    public function test_run_skips_remote_images_when_disabled(): void
    {
        $this->options['blc_remote_image_scan_enabled'] = false;
        $GLOBALS['wp_query_queue'] = [[
            'posts' => [
                (object) [
                    'ID'           => 101,
                    'post_title'   => 'Remote image post',
                    'post_content' => '<p><img src="https://cdn.example.net/wp-content/uploads/2024/01/photo.jpg" /></p>',
                ],
            ],
            'max_num_pages' => 1,
        ]];

        Functions\expect('blc_acquire_image_scan_lock')->once()->andReturn('image-lock');
        Functions\expect('blc_generate_scan_run_token')->once()->andReturn('run-token');
        Functions\expect('blc_stage_dataset_refresh')->once()->andReturn(true);
        Functions\expect('blc_commit_dataset_refresh')->once()->andReturn(true);
        Functions\expect('blc_maybe_send_scan_summary')->once()->with('image');

        $release_calls = [];
        Functions\when('blc_release_image_scan_lock')->alias(function ($token) use (&$release_calls) {
            $release_calls[] = $token;
        });

        $queue = new \ImageScanQueue();
        $queue->run(0, true);

        $this->assertSame([], $this->wpdb->insertedRows, 'No broken images should be recorded when remote scanning is disabled.');
        $this->assertSame(['image-lock'], $release_calls, 'Lock should be released after successful run.');
    }

    public function test_run_cleans_up_on_exception(): void
    {
        $this->options['blc_remote_image_scan_enabled'] = false;
        $GLOBALS['wp_query_queue'] = [[
            'posts' => [
                (object) [
                    'ID'           => 202,
                    'post_title'   => 'Broken local image',
                    'post_content' => '<p><img src="https://example.com/wp-content/uploads/2024/02/missing.jpg" /></p>',
                ],
            ],
            'max_num_pages' => 1,
        ]];

        $adjustments = [];
        Functions\when('blc_adjust_dataset_storage_footprint')->alias(function ($type, $delta) use (&$adjustments) {
            $adjustments[] = [$type, $delta];
        });

        Functions\expect('blc_acquire_image_scan_lock')->once()->andReturn('error-lock');
        $release_calls = [];
        Functions\when('blc_release_image_scan_lock')->alias(function ($token) use (&$release_calls) {
            $release_calls[] = $token;
        });
        Functions\expect('blc_generate_scan_run_token')->once()->andReturn('run-token');
        Functions\expect('blc_stage_dataset_refresh')->once()->andReturn(true);
        Functions\expect('blc_commit_dataset_refresh')->once()->andThrow(new \RuntimeException('Commit failure'));
        Functions\expect('blc_maybe_send_scan_summary')->never();

        $restore_calls = [];
        Functions\when('blc_restore_dataset_refresh')->alias(function (...$args) use (&$restore_calls) {
            $restore_calls[] = $args;
        });

        $queue = new \ImageScanQueue();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Commit failure');
        try {
            $queue->run(0, true);
        } finally {
            $this->assertNotEmpty($this->wpdb->insertedRows, 'Broken image insertion should have been attempted.');
            $this->assertNotEmpty($adjustments, 'Storage footprint adjustments should be recorded.');
            $this->assertSame('image', $adjustments[0][0]);
            $this->assertGreaterThan(0, $adjustments[0][1]);
            $this->assertNotEmpty($restore_calls, 'Dataset refresh should be restored when an exception occurs.');
            $this->assertSame('error-lock', $release_calls[0] ?? null, 'Lock should be released after exception.');
        }
    }
}
}
