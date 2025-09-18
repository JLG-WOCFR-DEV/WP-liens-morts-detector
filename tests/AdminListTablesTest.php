<?php

namespace Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class AdminListTablesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        if (!class_exists('WP_List_Table')) {
            require_once __DIR__ . '/stubs/WP_List_Table.php';
        }

        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('sanitize_text_field')->alias(function ($value) {
            return is_string($value) ? trim($value) : $value;
        });
        Functions\when('wp_unslash')->alias(fn($value) => $value);
        Functions\when('esc_url')->alias(fn($value) => $value);
        Functions\when('esc_attr')->alias(fn($value) => $value);
        Functions\when('remove_query_arg')->alias(fn($key, $url = null) => 'admin.php');
        Functions\when('add_query_arg')->alias(function ($key, $value, $url = null) {
            $param = is_array($key) ? $key : [$key => $value];
            return 'admin.php?' . http_build_query($param);
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/class-blc-links-list-table.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/class-blc-images-list-table.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        $_GET = [];
        $_REQUEST = [];
        global $wpdb;
        $wpdb = null;
        parent::tearDown();
    }

    private function createItems(int $count, string $prefix): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = [
                'url'        => sprintf('https://example.com/%s-%d', $prefix, $i),
                'anchor'     => sprintf('%s-%d', $prefix, $i),
                'post_id'    => $i + 1,
                'post_title' => sprintf('Post %d', $i + 1),
            ];
        }

        return $items;
    }

    public function test_links_prepare_items_supports_injected_data_with_pagination(): void
    {
        $_REQUEST['paged'] = 2;
        $table = new \BLC_Links_List_Table();

        $items = $this->createItems(55, 'link');
        $table->prepare_items($items);

        $this->assertCount(20, $table->items);
        $this->assertSame('https://example.com/link-20', $table->items[0]['url']);
        $this->assertSame(55, $table->get_pagination_args()['total_items']);
    }

    public function test_links_prepare_items_uses_paginated_queries(): void
    {
        global $wpdb;
        $wpdb = new DummyWpdb();
        $wpdb->get_var_return_values = [45];
        $expected_items = $this->createItems(20, 'db-link');
        $wpdb->results_to_return = $expected_items;

        $table = new \BLC_Links_List_Table();
        $table->prepare_items();

        $this->assertCount(20, $table->items);
        $this->assertSame($expected_items, $table->items);
        $this->assertStringContainsString('COUNT(*)', $wpdb->last_get_var_query);
        $this->assertStringContainsString('LIMIT 20', $wpdb->last_get_results_query);
        $this->assertStringContainsString('OFFSET 0', $wpdb->last_get_results_query);
    }

    public function test_links_prepare_items_internal_filter_adds_like_condition(): void
    {
        global $wpdb;
        $wpdb = new DummyWpdb();
        $wpdb->get_var_return_values = [5];
        $wpdb->results_to_return = $this->createItems(5, 'internal');

        $_GET['link_type'] = 'internal';

        $table = new \BLC_Links_List_Table();
        $table->prepare_items();

        $this->assertStringContainsString("(url LIKE 'https://example.com%')", $wpdb->last_get_var_query);
        $this->assertStringContainsString("(url LIKE '//example.com%')", $wpdb->last_get_var_query);
        $this->assertStringContainsString("(url LIKE '/%')", $wpdb->last_get_var_query);
        $this->assertStringContainsString("(url NOT LIKE '%://%' AND url NOT LIKE '//%' AND url NOT LIKE '%:%'", $wpdb->last_get_var_query);

        $this->assertStringContainsString("(url LIKE 'https://example.com%')", $wpdb->last_get_results_query);
        $this->assertStringContainsString("(url LIKE '//example.com%')", $wpdb->last_get_results_query);
        $this->assertStringContainsString("(url LIKE '/%')", $wpdb->last_get_results_query);
        $this->assertStringContainsString("(url NOT LIKE '%://%' AND url NOT LIKE '//%' AND url NOT LIKE '%:%'", $wpdb->last_get_results_query);
    }

    public function test_links_prepare_items_external_filter_adds_not_like_condition(): void
    {
        global $wpdb;
        $wpdb = new DummyWpdb();
        $wpdb->get_var_return_values = [7];
        $wpdb->results_to_return = $this->createItems(7, 'external');

        $_GET['link_type'] = 'external';

        $table = new \BLC_Links_List_Table();
        $table->prepare_items();

        $this->assertStringContainsString("NOT (url LIKE 'https://example.com%')", $wpdb->last_get_var_query);
        $this->assertStringContainsString("NOT (url LIKE '//example.com%')", $wpdb->last_get_var_query);
        $this->assertStringContainsString("NOT (url LIKE '/%')", $wpdb->last_get_var_query);
        $this->assertStringContainsString("NOT ((url NOT LIKE '%://%' AND url NOT LIKE '//%' AND url NOT LIKE '%:%'", $wpdb->last_get_var_query);

        $this->assertStringContainsString("NOT (url LIKE 'https://example.com%')", $wpdb->last_get_results_query);
        $this->assertStringContainsString("NOT (url LIKE '//example.com%')", $wpdb->last_get_results_query);
        $this->assertStringContainsString("NOT (url LIKE '/%')", $wpdb->last_get_results_query);
        $this->assertStringContainsString("NOT ((url NOT LIKE '%://%' AND url NOT LIKE '//%' AND url NOT LIKE '%:%'", $wpdb->last_get_results_query);
        $this->assertStringContainsString('url IS NULL', $wpdb->last_get_results_query);
        $this->assertStringContainsString("url = ''", $wpdb->last_get_results_query);
    }

    public function test_links_get_views_counts_internal_patterns(): void
    {
        global $wpdb;
        $wpdb = new DummyWpdb();
        $wpdb->get_row_return_value = [
            'total'          => 3,
            'internal_count' => 2,
            'external_count' => 1,
        ];

        $table = new \BLC_Links_List_Table();
        $method = new \ReflectionMethod($table, 'get_views');
        $method->setAccessible(true);
        $views = $method->invoke($table);

        $this->assertArrayHasKey('internal', $views);
        $this->assertArrayHasKey('external', $views);
        $this->assertStringContainsString("CASE WHEN url LIKE 'https://example.com%' THEN 1", $wpdb->last_get_row_query);
        $this->assertStringContainsString("'//example.com%'", $wpdb->last_get_row_query);
        $this->assertStringContainsString("'/%'", $wpdb->last_get_row_query);
        $this->assertStringContainsString("WHEN (url NOT LIKE '%://%' AND url NOT LIKE '//%' AND url NOT LIKE '%:%'", $wpdb->last_get_row_query);

        $prepare_call = end($wpdb->prepare_calls);
        $this->assertSame(
            [
                "SELECT\n                    COUNT(*) AS total,\n                    COALESCE(SUM(CASE WHEN url LIKE %s THEN 1 WHEN url LIKE %s THEN 1 WHEN url LIKE %s THEN 1 WHEN (url NOT LIKE %s AND url NOT LIKE %s AND url NOT LIKE %s AND url <> '' AND url IS NOT NULL) THEN 1 ELSE 0 END), 0) AS internal_count,\n                    (COUNT(*) - COALESCE(SUM(CASE WHEN url LIKE %s THEN 1 WHEN url LIKE %s THEN 1 WHEN url LIKE %s THEN 1 WHEN (url NOT LIKE %s AND url NOT LIKE %s AND url NOT LIKE %s AND url <> '' AND url IS NOT NULL) THEN 1 ELSE 0 END), 0)) AS external_count\n                 FROM wp_blc_broken_links\n                 WHERE type = %s",
                [
                    'https://example.com%',
                    '//example.com%',
                    '/%',
                    '%://%',
                    '//%',
                    '%:%',
                    'https://example.com%',
                    '//example.com%',
                    '/%',
                    '%://%',
                    '//%',
                    '%:%',
                    'link',
                ],
            ],
            $prepare_call
        );
    }

    public function test_images_prepare_items_supports_injected_data_with_pagination(): void
    {
        $_REQUEST['paged'] = 3;
        $table = new \BLC_Images_List_Table();

        $items = $this->createItems(65, 'image');
        $table->prepare_items($items);

        $this->assertCount(20, $table->items);
        $this->assertSame('https://example.com/image-40', $table->items[0]['url']);
        $this->assertSame(65, $table->get_pagination_args()['total_items']);
    }

    public function test_images_prepare_items_uses_paginated_queries(): void
    {
        global $wpdb;
        $wpdb = new DummyWpdb();
        $wpdb->get_var_return_values = [88];
        $expected_items = $this->createItems(20, 'db-image');
        $wpdb->results_to_return = $expected_items;

        $table = new \BLC_Images_List_Table();
        $table->prepare_items();

        $this->assertCount(20, $table->items);
        $this->assertSame($expected_items, $table->items);
        $this->assertStringContainsString("WHERE type = 'image'", $wpdb->last_get_results_query);
        $this->assertStringContainsString('LIMIT 20', $wpdb->last_get_results_query);
        $this->assertStringContainsString('OFFSET 0', $wpdb->last_get_results_query);
    }
}

class DummyWpdb
{
    public $prefix = 'wp_';
    public $get_var_return_values = [];
    public $results_to_return;
    public $last_get_var_query;
    public $last_get_results_query;
    public $get_row_return_value;
    public $last_get_row_query;
    public $prepare_calls = [];

    public function esc_like(string $text): string
    {
        return addcslashes($text, "\\%_");
    }

    public function prepare(string $query, $args = null): string
    {
        if ($args === null) {
            return $query;
        }

        if (!is_array($args)) {
            $args = array_slice(func_get_args(), 1);
        }

        $this->prepare_calls[] = [$query, $args];

        $escaped = array_map(function ($value) {
            if (is_string($value)) {
                return addslashes($value);
            }

            if (is_bool($value)) {
                return $value ? 1 : 0;
            }

            return $value;
        }, $args);

        $query = str_replace('%s', "'%s'", $query);
        $query = str_replace('%d', '%d', $query);
        $query = str_replace('%f', '%F', $query);

        return vsprintf($query, $escaped);
    }

    public function get_var(string $query)
    {
        $this->last_get_var_query = $query;
        return array_shift($this->get_var_return_values);
    }

    public function get_results(string $query, $output = ARRAY_A)
    {
        $this->last_get_results_query = $query;

        if (is_callable($this->results_to_return)) {
            return call_user_func($this->results_to_return, $query, $output);
        }

        return $this->results_to_return;
    }

    public function get_row(string $query, $output = ARRAY_A)
    {
        $this->last_get_row_query = $query;

        if (is_callable($this->get_row_return_value)) {
            return call_user_func($this->get_row_return_value, $query, $output);
        }

        if ($this->get_row_return_value !== null) {
            return $this->get_row_return_value;
        }

        return [
            'total'          => 0,
            'internal_count' => 0,
            'external_count' => 0,
        ];
    }
}
