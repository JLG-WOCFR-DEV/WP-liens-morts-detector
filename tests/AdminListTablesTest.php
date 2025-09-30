<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';
}

namespace Tests {

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
        Functions\when('trailingslashit')->alias(static function ($value) {
            $value = (string) $value;
            if ($value === '') {
                return '/';
            }

            return rtrim($value, '/') . '/';
        });
        Functions\when('get_permalink')->alias(static fn($post_id) => sprintf('https://example.com/post-%d/', $post_id));
        Functions\when('remove_query_arg')->alias(fn($key, $url = null) => 'admin.php');
        Functions\when('add_query_arg')->alias(function ($key, $value, $url = null) {
            $param = is_array($key) ? $key : [$key => $value];
            return 'admin.php?' . http_build_query($param);
        });
        Functions\when('get_option')->alias(function ($option, $default = false) {
            switch ($option) {
                case 'date_format':
                    return 'Y-m-d';
                case 'time_format':
                    return 'H:i';
                case 'timezone_string':
                    return '';
                case 'gmt_offset':
                    return 0;
                default:
                    return $default;
            }
        });
        Functions\when('get_date_from_gmt')->alias(function ($date_string, $format = 'Y-m-d H:i:s') {
            $timestamp = strtotime($date_string . ' UTC');
            if ($timestamp === false) {
                return $format === 'U' ? 0 : '';
            }

            if ($format === 'U') {
                return $timestamp;
            }

            return gmdate($format, $timestamp);
        });
        Functions\when('date_i18n')->alias(function ($format, $timestamp) {
            return gmdate($format, (int) $timestamp);
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';
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
                'http_status' => 400 + $i,
                'last_checked_at' => sprintf('2024-01-%02d 12:00:00', $i + 1),
            ];
        }

        return $items;
    }

    public function test_links_column_url_normalizes_relative_href(): void
    {
        $table = new class() extends \BLC_Links_List_Table {
            public function renderColumnUrl(array $item)
            {
                return parent::column_url($item);
            }

            protected function get_row_actions($item)
            {
                return [];
            }

            public function row_actions($actions, $always_visible = false)
            {
                return '';
            }
        };

        $html = $table->renderColumnUrl([
            'url'        => 'images/photo.jpg',
            'anchor'     => 'Photo',
            'post_id'    => 12,
            'post_title' => 'Sample Post',
        ]);

        $this->assertStringContainsString('href="https://example.com/post-12/images/photo.jpg"', $html);
        $this->assertStringContainsString('>images/photo.jpg<', $html);
    }

    public function test_links_columns_render_status_and_last_checked(): void
    {
        $table = new class() extends \BLC_Links_List_Table {
            public function renderHttpStatus(array $item)
            {
                return parent::column_http_status($item);
            }

            public function renderLastChecked(array $item)
            {
                return parent::column_last_checked_at($item);
            }
        };

        $this->assertSame('404', $table->renderHttpStatus(['http_status' => 404]));
        $this->assertSame('—', $table->renderHttpStatus(['http_status' => null]));

        $formatted = $table->renderLastChecked(['last_checked_at' => '2024-01-01 12:34:00']);
        $this->assertSame('2024-01-01 12:34', $formatted);
        $this->assertSame('—', $table->renderLastChecked(['last_checked_at' => '']));
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

    public function test_links_prepare_items_adds_search_term_conditions(): void
    {
        global $wpdb;
        $wpdb = new DummyWpdb();
        $wpdb->get_var_return_values = [0];
        $wpdb->results_to_return = [];

        $_REQUEST['s'] = 'broken link';

        $table = new \BLC_Links_List_Table();
        $table->prepare_items();

        $expected_like = "(url LIKE '%broken link%' OR anchor LIKE '%broken link%' OR post_title LIKE '%broken link%')";

        $this->assertStringContainsString($expected_like, $wpdb->last_get_var_query);
        $this->assertStringContainsString($expected_like, $wpdb->last_get_results_query);
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

        $this->assertStringContainsString("(url LIKE 'https://example.com%' AND url REGEXP '^https://example\\\.com(?:[/?#]|$)')", $wpdb->last_get_var_query);
        $this->assertStringContainsString("(url LIKE 'http://example.com%' AND url REGEXP '^http://example\\\.com(?:[/?#]|$)')", $wpdb->last_get_var_query);
        $this->assertStringContainsString("(url LIKE '//example.com%' AND url REGEXP '^//example\\\.com(?:[/?#]|$)')", $wpdb->last_get_var_query);
        $this->assertStringContainsString("url LIKE '/%' AND url NOT LIKE '//%'", $wpdb->last_get_var_query);
        $this->assertStringContainsString("(url NOT LIKE '%://%' AND url NOT LIKE '//%' AND url NOT LIKE '%:%'", $wpdb->last_get_var_query);

        $this->assertStringContainsString("(url LIKE 'https://example.com%' AND url REGEXP '^https://example\\\.com(?:[/?#]|$)')", $wpdb->last_get_results_query);
        $this->assertStringContainsString("(url LIKE 'http://example.com%' AND url REGEXP '^http://example\\\.com(?:[/?#]|$)')", $wpdb->last_get_results_query);
        $this->assertStringContainsString("(url LIKE '//example.com%' AND url REGEXP '^//example\\\.com(?:[/?#]|$)')", $wpdb->last_get_results_query);
        $this->assertStringContainsString("url LIKE '/%' AND url NOT LIKE '//%'", $wpdb->last_get_results_query);
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

        $this->assertStringContainsString("NOT ((url LIKE 'https://example.com%' AND url REGEXP '^https://example\\\.com(?:[/?#]|$)'))", $wpdb->last_get_var_query);
        $this->assertStringContainsString("NOT ((url LIKE 'http://example.com%' AND url REGEXP '^http://example\\\.com(?:[/?#]|$)'))", $wpdb->last_get_var_query);
        $this->assertStringContainsString("NOT ((url LIKE '//example.com%' AND url REGEXP '^//example\\\.com(?:[/?#]|$)'))", $wpdb->last_get_var_query);
        $this->assertStringContainsString("NOT ((url LIKE '/%' AND url NOT LIKE '//%'))", $wpdb->last_get_var_query);
        $this->assertStringContainsString("NOT ((url NOT LIKE '%://%' AND url NOT LIKE '//%' AND url NOT LIKE '%:%'", $wpdb->last_get_var_query);

        $this->assertStringContainsString("NOT ((url LIKE 'https://example.com%' AND url REGEXP '^https://example\\\.com(?:[/?#]|$)'))", $wpdb->last_get_results_query);
        $this->assertStringContainsString("NOT ((url LIKE 'http://example.com%' AND url REGEXP '^http://example\\\.com(?:[/?#]|$)'))", $wpdb->last_get_results_query);
        $this->assertStringContainsString("NOT ((url LIKE '//example.com%' AND url REGEXP '^//example\\\.com(?:[/?#]|$)'))", $wpdb->last_get_results_query);
        $this->assertStringContainsString("NOT ((url LIKE '/%' AND url NOT LIKE '//%'))", $wpdb->last_get_results_query);
        $this->assertStringContainsString("NOT ((url NOT LIKE '%://%' AND url NOT LIKE '//%' AND url NOT LIKE '%:%'", $wpdb->last_get_results_query);
        $this->assertStringContainsString('url IS NULL', $wpdb->last_get_results_query);
        $this->assertStringContainsString("url = ''", $wpdb->last_get_results_query);
    }

    public function test_links_prepare_items_external_filter_includes_lookalike_domains(): void
    {
        global $wpdb;
        $wpdb = new DummyWpdb();
        $wpdb->get_var_return_values = [1];

        $evil_url = 'https://example.com.evil.com/page';
        $wpdb->results_to_return = [
            [
                'url'        => $evil_url,
                'anchor'     => 'Evil link',
                'post_id'    => 42,
                'post_title' => 'Traps',
            ],
        ];

        $_GET['link_type'] = 'external';

        $table = new \BLC_Links_List_Table();
        $table->prepare_items();

        $this->assertSame([$wpdb->results_to_return[0]], $table->items);
        $this->assertStringContainsString("NOT ((url LIKE 'https://example.com%' AND url REGEXP '^https://example\\\.com(?:[/?#]|$)'))", $wpdb->last_get_results_query);
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
        $this->assertStringContainsString("CASE WHEN (url LIKE 'https://example.com%' AND url REGEXP '^https://example\\\.com(?:[/?#]|$)') THEN 1", $wpdb->last_get_row_query);
        $this->assertStringContainsString("WHEN (url LIKE 'http://example.com%' AND url REGEXP '^http://example\\\.com(?:[/?#]|$)') THEN 1", $wpdb->last_get_row_query);
        $this->assertStringContainsString("(url LIKE '//example.com%' AND url REGEXP '^//example\\\.com(?:[/?#]|$)')", $wpdb->last_get_row_query);
        $this->assertStringContainsString("'/%'", $wpdb->last_get_row_query);
        $this->assertStringContainsString("WHEN (url NOT LIKE '%://%' AND url NOT LIKE '//%' AND url NOT LIKE '%:%'", $wpdb->last_get_row_query);
        $prepare_call = end($wpdb->prepare_calls);
        $this->assertIsArray($prepare_call);
        $this->assertSame('link', end($prepare_call[1]));
        $this->assertContains('https://example.com%', $prepare_call[1]);
        $this->assertContains('^https://example\\.com(?:[/?#]|$)', $prepare_call[1]);
        $this->assertContains('http://example.com%', $prepare_call[1]);
        $this->assertContains('^http://example\\.com(?:[/?#]|$)', $prepare_call[1]);
        $this->assertContains('//example.com%', $prepare_call[1]);
        $this->assertContains('^//example\\.com(?:[/?#]|$)', $prepare_call[1]);
        $this->assertContains('/%', $prepare_call[1]);
        $this->assertContains('%://%', $prepare_call[1]);
        $this->assertContains('//%', $prepare_call[1]);
        $this->assertContains('%:%', $prepare_call[1]);
    }

    public function test_links_get_views_counts_internal_includes_http_links(): void
    {
        global $wpdb;
        $wpdb = new DummyWpdb();
        $wpdb->get_row_return_value = [
            'total'          => 2,
            'internal_count' => 1,
            'external_count' => 1,
        ];

        $table = new \BLC_Links_List_Table();
        $method = new \ReflectionMethod($table, 'get_views');
        $method->setAccessible(true);
        $views = $method->invoke($table);

        $this->assertStringContainsString("url LIKE 'http://example.com%'", $wpdb->last_get_row_query);
        $this->assertArrayHasKey('internal', $views);
        $this->assertStringContainsString("Internes <span class='count'>(1)</span>", $views['internal']);
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

    public function test_images_columns_render_status_and_last_checked(): void
    {
        $table = new class() extends \BLC_Images_List_Table {
            public function renderHttpStatus(array $item)
            {
                return parent::column_http_status($item);
            }

            public function renderLastChecked(array $item)
            {
                return parent::column_last_checked_at($item);
            }
        };

        $this->assertSame('—', $table->renderHttpStatus(['http_status' => 0]));
        $this->assertSame('410', $table->renderHttpStatus(['http_status' => 410]));

        $formatted = $table->renderLastChecked(['last_checked_at' => '2024-02-02 08:00:00']);
        $this->assertSame('2024-02-02 08:00', $formatted);
        $this->assertSame('—', $table->renderLastChecked(['last_checked_at' => null]));
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
}
