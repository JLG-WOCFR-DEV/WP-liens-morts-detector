<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';

    if (!function_exists('sanitize_key')) {
        function sanitize_key($key)
        {
            $key = strtolower((string) $key);

            return preg_replace('/[^a-z0-9_\-]/', '', $key);
        }
    }
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

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

        require_once __DIR__ . '/wp-option-stubs.php';
        OptionsStore::reset();
        OptionsStore::$options['date_format'] = 'Y-m-d';
        OptionsStore::$options['time_format'] = 'H:i';
        OptionsStore::$options['timezone_string'] = 'UTC';
        OptionsStore::$options['gmt_offset'] = 0;

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
        Functions\when('get_post_types')->alias(static function ($args = [], $output = 'names') {
            return ['post', 'page'];
        });
        Functions\when('get_post_type_object')->alias(static function ($post_type) {
            return (object) [
                'labels' => (object) [
                    'singular_name' => ucfirst((string) $post_type),
                ],
                'label' => ucfirst((string) $post_type),
            ];
        });
        Functions\when('wp_timezone')->alias(static fn() => new \DateTimeZone('UTC'));
        Functions\when('wp_timezone_string')->alias(static fn() => 'UTC');

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
        OptionsStore::reset();
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
                'http_status' => ($i % 2 === 0) ? 404 : null,
                'last_checked_at' => '1970-01-01 00:00:00',
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

    public function test_links_columns_display_status_and_timestamp(): void
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

            protected function get_row_actions($item)
            {
                return [];
            }

            public function row_actions($actions, $always_visible = false)
            {
                return '';
            }
        };

        $columns = $table->get_columns();
        $this->assertArrayHasKey('http_status', $columns);
        $this->assertArrayHasKey('last_checked_at', $columns);

        $item = [
            'http_status'     => 410,
            'last_checked_at' => '1970-01-01 00:00:00',
            'post_id'         => 1,
            'post_title'      => 'Post 1',
            'url'             => 'https://example.com',
        ];

        $this->assertSame('410', $table->renderHttpStatus($item));
        $this->assertSame('1970-01-01 00:00', $table->renderLastChecked($item));

        $empty = [
            'http_status'     => null,
            'last_checked_at' => '',
            'post_id'         => 2,
            'post_title'      => 'Post 2',
            'url'             => 'https://example.com/empty',
        ];

        $this->assertSame('—', $table->renderHttpStatus($empty));
        $this->assertSame('—', $table->renderLastChecked($empty));
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

    public function test_links_prepare_items_filters_by_selected_post_type(): void
    {
        global $wpdb;
        $wpdb = new DummyWpdb();
        $wpdb->get_var_return_values = [12];
        $expected_items = $this->createItems(3, 'filtered');
        $wpdb->results_to_return = $expected_items;

        $_GET['post_type'] = 'page';

        $table = new \BLC_Links_List_Table();
        $table->prepare_items();

        $this->assertSame($expected_items, $table->items);
        $this->assertStringContainsString("post_type = 'page'", $wpdb->last_get_var_query);
        $this->assertStringContainsString("post_type = 'page'", $wpdb->last_get_results_query);
    }

    public function test_links_extra_tablenav_displays_post_type_filter(): void
    {
        $_GET['post_type'] = 'page';

        $table = new class() extends \BLC_Links_List_Table {
            public function captureExtraTablenav($which)
            {
                ob_start();
                parent::extra_tablenav($which);

                return ob_get_clean();
            }
        };

        $output = $table->captureExtraTablenav('top');

        $this->assertStringContainsString('name="post_type"', $output);
        $this->assertStringContainsString('id="blc-post-type-filter"', $output);
        $this->assertStringContainsString('Filtrer par type de contenu', $output);
        $this->assertStringContainsString('<option value="page" selected="selected">', $output);
    }

    public function test_images_columns_display_status_and_timestamp(): void
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

        $columns = $table->get_columns();
        $this->assertArrayHasKey('http_status', $columns);
        $this->assertArrayHasKey('last_checked_at', $columns);

        $item = [
            'http_status'     => null,
            'last_checked_at' => '1970-01-01 00:00:00',
            'url'             => 'https://example.com/image.jpg',
            'anchor'          => 'image.jpg',
            'post_id'         => 7,
            'post_title'      => 'Gallery',
        ];

        $this->assertSame('—', $table->renderHttpStatus($item));
        $this->assertSame('1970-01-01 00:00', $table->renderLastChecked($item));

        $missing = [
            'http_status'     => null,
            'last_checked_at' => '',
            'url'             => '',
            'anchor'          => '',
            'post_id'         => 8,
            'post_title'      => 'Empty',
        ];

        $this->assertSame('—', $table->renderHttpStatus($missing));
        $this->assertSame('—', $table->renderLastChecked($missing));
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
                'http_status' => null,
                'last_checked_at' => '1970-01-01 00:00:00',
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
        $this->assertStringContainsString("WHERE type IN ('image','remote-image')", $wpdb->last_get_results_query);
        $this->assertStringContainsString('LIMIT 20', $wpdb->last_get_results_query);
        $this->assertStringContainsString('OFFSET 0', $wpdb->last_get_results_query);
    }

    public function test_images_prepare_items_returns_empty_pagination_when_row_types_missing(): void
    {
        global $wpdb;
        $wpdb = new DummyWpdb();

        $registered_filters = [];
        Functions\when('add_filter')->alias(static function ($hook, $callback, $priority = 10, $accepted_args = 1) use (&$registered_filters) {
            $registered_filters[$hook][$priority][] = [
                'callback'      => $callback,
                'accepted_args' => $accepted_args,
            ];

            return true;
        });
        Functions\when('apply_filters')->alias(static function ($hook, $value, ...$args) use (&$registered_filters) {
            if (!isset($registered_filters[$hook])) {
                return $value;
            }

            ksort($registered_filters[$hook]);
            $params = array_merge([$value], $args);

            foreach ($registered_filters[$hook] as $callbacks) {
                foreach ($callbacks as $handler) {
                    $callback = $handler['callback'];
                    $accepted_args = max(0, (int) $handler['accepted_args']);

                    $arg_count = $accepted_args;
                    try {
                        if (is_array($callback) && count($callback) === 2) {
                            $reflection = new \ReflectionMethod($callback[0], $callback[1]);
                        } elseif (is_string($callback) && str_contains($callback, '::')) {
                            $reflection = new \ReflectionMethod($callback);
                        } elseif (is_object($callback) && !($callback instanceof \Closure) && method_exists($callback, '__invoke')) {
                            $reflection = new \ReflectionMethod($callback, '__invoke');
                        } else {
                            $reflection = new \ReflectionFunction($callback);
                        }

                        if (!$reflection->isVariadic()) {
                            $arg_count = min($arg_count, $reflection->getNumberOfParameters());
                        }
                    } catch (\ReflectionException $e) {
                        // Fallback to accepted args when reflection fails.
                    }

                    $callback_args = array_slice($params, 0, max(0, $arg_count));
                    $value = $callback(...$callback_args);
                    $params[0] = $value;
                }
            }

            return $value;
        });

        add_filter('blc_dataset_row_types', fn() => [], 10, 2);

        $table = new \BLC_Images_List_Table();
        $table->prepare_items();

        $this->assertSame([], $table->items);
        $this->assertSame([
            'total_items' => 0,
            'per_page'    => 20,
        ], $table->get_pagination_args());
        $this->assertNull($wpdb->last_get_var_query);
        $this->assertNull($wpdb->last_get_results_query);
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
