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
        Functions\when('blc_process_link_nodes_from_html')->alias(function ($html, $charset, $callback) use (&$processedSources) {
            $processedSources[] = $html;
            $index = count($processedSources);
            $href = 'https://scanned.test/' . $index;
            $callback($href, 'Anchor ' . $index, '<a href="' . $href . '">Anchor</a>', 'Anchor ' . $index);
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

    public function test_run_batch_detects_soft_404_with_suspicious_200(): void
    {
        $this->prepareLinkScanEnvironment();

        $GLOBALS['wp_query_queue'] = [[
            'posts' => [
                (object) [
                    'ID'           => 501,
                    'post_title'   => 'Soft 404 Page',
                    'post_content' => '<p>Dummy</p>',
                    'post_status'  => 'publish',
                    'post_type'    => 'post',
                ],
            ],
            'max_num_pages' => 1,
        ]];
        $GLOBALS['wp_query_last_args'] = [];

        $this->options['blc_soft_404_min_length'] = 120;
        $this->options['blc_soft_404_title_indicators'] = "Page Introuvable\nErreur";
        $this->options['blc_soft_404_body_indicators'] = "Erreur 404\nPage introuvable";
        $this->options['blc_soft_404_ignore_patterns'] = '';

        Functions\when('blc_process_link_nodes_from_html')->alias(function ($html, $charset, $callback) {
            $callback('https://example.com/soft-404', 'Soft link', '<a href="https://example.com/soft-404">Soft</a>', 'Soft link');
            return true;
        });

        Functions\when('wp_safe_remote_head')->alias(fn() => new \WP_Error('http_error', 'HEAD blocked'));

        Functions\when('wp_safe_remote_get')->alias(function () {
            return [
                'body'     => '<html><head><title>Page Introuvable</title></head><body><h1>Erreur 404</h1></body></html>',
                'response' => ['code' => 200],
            ];
        });

        $queue = new ScanQueue(new RemoteRequestClient());
        $result = $queue->runBatch(0, true, true);

        $this->assertNull($result);
        $this->assertCount(1, $this->wpdb->insertedRows, 'Soft 404 responses should be stored as broken links.');
        $row = $this->wpdb->insertedRows[0]['data'];
        $this->assertSame(200, $row['http_status']);
        $this->assertSame('https://example.com/soft-404', $row['url']);
    }

    public function test_run_batch_ignores_soft_404_when_pattern_matches(): void
    {
        $this->prepareLinkScanEnvironment();

        $GLOBALS['wp_query_queue'] = [[
            'posts' => [
                (object) [
                    'ID'           => 777,
                    'post_title'   => 'Profile Page',
                    'post_content' => '<p>Dummy</p>',
                    'post_status'  => 'publish',
                    'post_type'    => 'post',
                ],
            ],
            'max_num_pages' => 1,
        ]];
        $GLOBALS['wp_query_last_args'] = [];

        $this->options['blc_soft_404_min_length'] = 0;
        $this->options['blc_soft_404_title_indicators'] = "Profil introuvable";
        $this->options['blc_soft_404_body_indicators'] = "Profil introuvable";
        $this->options['blc_soft_404_ignore_patterns'] = "Profil introuvable";

        Functions\when('blc_process_link_nodes_from_html')->alias(function ($html, $charset, $callback) {
            $callback('https://example.com/profile', 'Profil', '<a href="https://example.com/profile">Profil</a>', 'Profil');
            return true;
        });

        Functions\when('wp_safe_remote_head')->alias(fn() => new \WP_Error('http_error', 'HEAD blocked'));

        Functions\when('wp_safe_remote_get')->alias(function () {
            return [
                'body'     => '<html><head><title>Profil introuvable</title></head><body><p>Profil introuvable</p></body></html>',
                'response' => ['code' => 200],
            ];
        });

        $queue = new ScanQueue(new RemoteRequestClient());
        $result = $queue->runBatch(0, true, true);

        $this->assertNull($result);
        $this->assertCount(0, $this->wpdb->insertedRows, 'Ignored motifs should prevent soft 404 insertion.');
    }

    private function prepareLinkScanEnvironment(): void
    {
        Functions\when('get_post_types')->alias(fn() => ['post']);
        Functions\when('blc_get_scannable_post_types')->alias(fn() => ['post']);
        Functions\when('blc_get_scannable_post_statuses')->alias(fn() => ['publish']);
        Functions\when('wp_get_nav_menus')->alias(fn() => []);
        Functions\when('wp_get_nav_menu_items')->alias(fn() => []);
        Functions\when('do_shortcode')->alias(fn($content) => $content);
        Functions\when('get_comments')->alias(fn() => []);
        Functions\when('JLG\\BrokenLinks\\Scanner\\get_comments')->alias(fn() => []);
        Functions\when('blc_get_notification_status_filters')->alias(fn() => []);
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
        Functions\when('blc_determine_response_target_url')->alias(fn() => '');
        Functions\when('blc_update_link_scan_status')->alias(function () {
        });
        Functions\when('blc_maybe_send_scan_summary')->alias(function () {
        });
    }
}

}
