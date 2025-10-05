<?php

namespace {
    require_once __DIR__ . '/../../vendor/autoload.php';
    if (!class_exists('WP_Query')) {
        class WP_Query
        {
            /** @var array<int, object> */
            public $posts = [];

            /** @var int */
            public $max_num_pages = 0;

            /** @var array<mixed> */
            public $args = [];

            public function __construct($args = [])
            {
                $this->args = is_array($args) ? $args : [];

                $scenario = [];
                if (!empty($GLOBALS['wp_query_queue'])) {
                    $scenario = array_shift($GLOBALS['wp_query_queue']);
                }

                $this->posts = $scenario['posts'] ?? [];
                $this->max_num_pages = isset($scenario['max_num_pages']) ? (int) $scenario['max_num_pages'] : 0;

                if (!isset($GLOBALS['wp_query_last_args'])) {
                    $GLOBALS['wp_query_last_args'] = [];
                }

                $GLOBALS['wp_query_last_args'][] = $this->args;
            }
        }
    }
}

namespace Tests\Scanner {

use Brain\Monkey\Functions;
use JLG\BrokenLinks\Scanner\RemoteRequestClient;
use JLG\BrokenLinks\Scanner\ScanQueue;

final class ScanQueueRunBatchTest extends ScannerTestCase
{
    public function test_run_batch_indexes_global_sources(): void
    {
        $GLOBALS['wp_query_queue'] = [[
            'posts'         => [],
            'max_num_pages' => 0,
        ]];
        $GLOBALS['wp_query_last_args'] = [];

        $this->options['widget_text'] = [
            2 => [
                'title' => 'Widget Shortcode',
                'text'  => '[cta] see more',
            ],
            '_multiwidget' => 1,
        ];

        $this->options['widget_custom_html'] = [
            3 => [
                'title'   => 'Custom HTML',
                'content' => '<div><a href="https://example.com/custom">Custom HTML</a></div>',
            ],
            4 => [
                'title'   => 'Empty Widget',
                'content' => 'Just text without links',
            ],
        ];

        Functions\when('do_shortcode')->alias(function ($content) {
            return str_replace('[cta]', '<a href="https://example.com/shortcode">Shortcode</a>', (string) $content);
        });

        Functions\when('wp_get_nav_menus')->alias(function () {
            return [
                (object) [
                    'term_id' => 123,
                    'name'    => 'Header',
                ],
            ];
        });

        Functions\when('wp_get_nav_menu_items')->alias(function ($menu) {
            return [
                (object) [
                    'ID'    => 501,
                    'title' => 'About',
                    'url'   => 'https://example.com/about',
                ],
                (object) [
                    'ID'    => 502,
                    'title' => 'Placeholder',
                    'url'   => '#',
                ],
            ];
        });

        Functions\when('get_post_types')->alias(fn() => ['post']);
        Functions\when('blc_get_scannable_post_statuses')->alias(fn() => ['publish']);
        Functions\when('blc_acquire_link_scan_lock')->alias(fn() => 'lock-token');
        Functions\when('blc_release_link_scan_lock')->alias(function () {
        });
        Functions\when('blc_refresh_link_scan_lock')->alias(function () {
        });
        Functions\when('blc_generate_scan_run_token')->alias(fn() => 'scan-run');
        Functions\when('blc_stage_dataset_refresh')->alias(fn() => 0);
        Functions\when('blc_commit_dataset_refresh')->alias(fn() => 0);
        Functions\when('blc_restore_dataset_refresh')->alias(function () {
        });
        Functions\when('blc_adjust_dataset_storage_footprint')->alias(function () {
        });
        Functions\when('blc_get_notification_webhook_settings')->alias(fn() => ['enabled' => false]);
        Functions\when('blc_should_notify_on_failure')->alias(fn() => false);
        Functions\when('blc_notify_broken_link')->alias(function () {
        });
        Functions\when('blc_is_webhook_notification_configured')->alias(fn() => false);
        Functions\when('wp_reset_postdata')->alias(function () {
        });
        Functions\when('blc_get_scan_cache_context')->alias(fn() => [
            'key'       => 'cache-key',
            'option'    => 'blc_active_link_scan_key',
            'transient' => 'blc_scan_cache_link_cache-key',
            'data'      => [],
        ]);
        Functions\when('blc_save_scan_cache')->alias(function () {
        });
        Functions\when('blc_get_request_timeout_constraints')->alias(fn() => [
            'head' => ['default' => 5, 'min' => 1, 'max' => 10],
            'get'  => ['default' => 5, 'min' => 1, 'max' => 10],
        ]);
        Functions\when('blc_calculate_row_storage_footprint_bytes')->alias(fn() => 0);
        Functions\when('blc_prepare_url_for_storage')->alias(fn($url) => (string) $url);
        Functions\when('blc_prepare_text_field_for_storage')->alias(fn($text) => (string) $text);
        Functions\when('blc_prepare_context_html_for_storage')->alias(fn($html) => (string) $html);
        Functions\when('blc_prepare_context_excerpt_for_storage')->alias(fn($excerpt) => (string) $excerpt);
        Functions\when('blc_get_url_metadata_for_storage')->alias(fn() => [
            'host'        => 'example.com',
            'is_internal' => 0,
        ]);

        $processedSources = [];
        Functions\when('blc_process_link_nodes_from_html')->alias(function ($html, $charset, $callbacks) use (&$processedSources) {
            $processedSources[] = $html;
            $index = count($processedSources);
            $href = 'https://scanned.test/' . $index;

            $callback = null;
            if (is_array($callbacks)) {
                if (isset($callbacks['link']) && is_callable($callbacks['link'])) {
                    $callback = $callbacks['link'];
                } else {
                    $first = reset($callbacks);
                    if (is_callable($first)) {
                        $callback = $first;
                    }
                }
            } elseif (is_callable($callbacks)) {
                $callback = $callbacks;
            }

            if (is_callable($callback)) {
                $callback(
                    $href,
                    'Anchor ' . $index,
                    '<a href="' . $href . '">Anchor</a>',
                    'Anchor ' . $index,
                    ['type' => 'link']
                );
            }

            return true;
        });

        $queue = new ScanQueue(new RemoteRequestClient());
        $result = $queue->runBatch(0, true, true);

        $this->assertNull($result, 'runBatch should return null when the scan succeeds.');

        $this->assertCount(3, $processedSources, 'Only valid global sources should be processed.');
        $this->assertCount(3, $this->wpdb->insertedRows, 'Each processed source should insert one link row.');

        $storageTitles = array_map(static function ($row) {
            return $row['data']['post_title'] ?? '';
        }, $this->wpdb->insertedRows);

        $this->assertContains('Widget texte « Widget Shortcode »', $storageTitles);
        $this->assertContains('Widget HTML personnalisé « Custom HTML »', $storageTitles);
        $this->assertContains('Menu « Header » — About', $storageTitles);

        foreach ($this->wpdb->insertedRows as $row) {
            $this->assertSame(0, $row['data']['post_id'] ?? null, 'Global sources must be stored with post_id = 0.');
        }
    }
}

}
