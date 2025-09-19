<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class BlcAjaxCallbacksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        Functions\when('register_activation_hook')->justReturn();
        Functions\when('register_deactivation_hook')->justReturn();
        Functions\when('add_action')->justReturn();
        Functions\when('add_filter')->justReturn();
        Functions\when('plugin_dir_path')->alias(function ($file) {
            return dirname($file) . '/';
        });
        Functions\when('plugin_dir_url')->justReturn('http://example.com/');
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('trailingslashit')->alias(function ($value) {
            return rtrim((string) $value, "\\/\t\n\r\f ") . '/';
        });
        Functions\when('set_url_scheme')->alias(function ($url, $scheme = null) {
            $scheme = $scheme ?: 'http';

            if (strpos($url, '//') === 0) {
                return $scheme . ':' . $url;
            }

            return preg_replace('#^[a-z0-9+.-]+://#i', $scheme . '://', $url);
        });
        Functions\when('wp_kses_decode_entities')->alias(function ($value, $keep_special_chars = false, $charset = null) {
            $charset = $charset ?: 'UTF-8';

            return html_entity_decode((string) $value, ENT_QUOTES, $charset);
        });
        Functions\when('wp_unslash')->alias(function ($value) {
            return $value;
        });
        Functions\when('wp_slash')->alias(function ($value) {
            return $value;
        });
        Functions\when('wp_http_validate_url')->alias(function ($url) {
            return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
        });
        Functions\when('is_wp_error')->alias(function () {
            return false;
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/liens-morts-detector-jlg.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
        $_POST = [];
        global $wpdb;
        $wpdb = null;
    }

    public function editLinkMissingParamProvider(): array
    {
        return [
            'missing post_id' => [
                ['old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'post_id',
            ],
            'null post_id' => [
                ['post_id' => null, 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'post_id',
            ],
            'empty post_id' => [
                ['post_id' => '   ', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'post_id',
            ],
            'missing old_url' => [
                ['post_id' => 5, 'new_url' => 'http://new.com'],
                'old_url',
            ],
            'null old_url' => [
                ['post_id' => 5, 'old_url' => null, 'new_url' => 'http://new.com'],
                'old_url',
            ],
            'empty old_url' => [
                ['post_id' => 5, 'old_url' => '   ', 'new_url' => 'http://new.com'],
                'old_url',
            ],
            'missing new_url' => [
                ['post_id' => 5, 'old_url' => 'http://old.com'],
                'new_url',
            ],
            'null new_url' => [
                ['post_id' => 5, 'old_url' => 'http://old.com', 'new_url' => null],
                'new_url',
            ],
            'empty new_url' => [
                ['post_id' => 5, 'old_url' => 'http://old.com', 'new_url' => '   '],
                'new_url',
            ],
        ];
    }

    /**
     * @dataProvider editLinkMissingParamProvider
     */
    public function test_edit_link_returns_error_when_required_param_is_missing(array $post_data, string $missing_param): void
    {
        $_POST = $post_data;

        Functions\expect('check_ajax_referer')->once()->with('blc_edit_link_nonce')->andReturn(true);

        $expected_message = sprintf('Le paramètre requis "%s" est manquant ou vide.', $missing_param);
        Functions\expect('wp_send_json_error')->once()->with(['message' => $expected_message])->andReturnUsing(function () {
            throw new \Exception('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function unlinkMissingParamProvider(): array
    {
        return [
            'missing post_id' => [
                ['url_to_unlink' => 'http://old.com'],
                'post_id',
            ],
            'null post_id' => [
                ['post_id' => null, 'url_to_unlink' => 'http://old.com'],
                'post_id',
            ],
            'empty post_id' => [
                ['post_id' => '   ', 'url_to_unlink' => 'http://old.com'],
                'post_id',
            ],
            'missing url_to_unlink' => [
                ['post_id' => 6],
                'url_to_unlink',
            ],
            'null url_to_unlink' => [
                ['post_id' => 6, 'url_to_unlink' => null],
                'url_to_unlink',
            ],
            'empty url_to_unlink' => [
                ['post_id' => 6, 'url_to_unlink' => '   '],
                'url_to_unlink',
            ],
        ];
    }

    /**
     * @dataProvider unlinkMissingParamProvider
     */
    public function test_unlink_returns_error_when_required_param_is_missing(array $post_data, string $missing_param): void
    {
        $_POST = $post_data;

        Functions\expect('check_ajax_referer')->once()->with('blc_unlink_nonce')->andReturn(true);

        $expected_message = sprintf('Le paramètre requis "%s" est manquant ou vide.', $missing_param);
        Functions\expect('wp_send_json_error')->once()->with(['message' => $expected_message])->andReturnUsing(function () {
            throw new \Exception('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_edit_link_denied_for_user_without_permission(): void
    {
        $_POST['post_id'] = 1;
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'http://new.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 1)->andReturn(false);
        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
        };
        Functions\expect('get_post')->once()->with(1)->andReturn((object) ['post_content' => '<a href="http://old.com">Link</a>']);
        Functions\expect('wp_send_json_error')->once()->with(['message' => 'Permissions insuffisantes.'])->andReturnUsing(function () {
            throw new \Exception('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_edit_link_callback();
    }

    public function test_edit_link_allows_user_with_permission(): void
    {
        $_POST['post_id'] = 2;
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'http://new.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 2)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Link</a>'];
        Functions\expect('get_post')->once()->with(2)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        Functions\expect('wp_update_post')->once()->andReturn(true);
        global $wpdb;
        $wpdb = new class {
            public $prefix = '';
            public function delete($table, $where, $formats)
            {
                return true;
            }
        };

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
    }

    public function test_edit_link_returns_success_when_post_has_been_deleted(): void
    {
        $_POST['post_id'] = 11;
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'http://new.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('get_post')->once()->with(11)->andReturn(null);
        Functions\expect('current_user_can')->never();

        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            public array $deleted = [];
            public function delete($table, $where, $formats)
            {
                $this->deleted[] = ['table' => $table, 'where' => $where, 'formats' => $formats];
                return 1;
            }
        };

        Functions\expect('wp_send_json_success')->once()->with(['purged' => true])->andReturnUsing(function () {
            throw new \Exception('success');
        });

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $exception) {
            $this->assertSame('success', $exception->getMessage());
        }

        $this->assertCount(1, $wpdb->deleted, 'Cleanup should remove the orphaned database row.');
        $deleted = $wpdb->deleted[0];
        $this->assertSame(11, $deleted['where']['post_id']);
        $this->assertSame('link', $deleted['where']['type']);
    }

    public function test_edit_link_succeeds_when_no_database_rows_deleted(): void
    {
        $_POST['post_id'] = 9;
        $_POST['old_url'] = 'http://old.com';
        $_POST['new_url'] = 'http://new.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 9)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Link</a>'];
        Functions\expect('get_post')->once()->with(9)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        Functions\expect('wp_update_post')->once()->andReturn(true);
        global $wpdb;
        $wpdb = new class {
            public $prefix = '';
            public function delete($table, $where, $formats)
            {
                return 0;
            }
        };

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
    }

    public function test_edit_link_preserves_scheme_relative_url_for_xpath_and_database(): void
    {
        $_POST['post_id'] = 12;
        $_POST['old_url'] = '//example.com/cdn/asset.js';
        $_POST['new_url'] = 'https://cdn.example.com/asset.js';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 12)->andReturn(true);

        $post = (object) ['post_content' => '<p><a href="//example.com/cdn/asset.js">CDN asset</a></p>'];
        Functions\expect('get_post')->once()->with(12)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();
            return true;
        });

        global $wpdb;
        $wpdb = new class {
            public $prefix = '';
            public $delete_args = null;
            public function delete($table, $where, $formats)
            {
                $this->delete_args = [$table, $where, $formats];
                return true;
            }
        };

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_edit_link_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(12, $captured_update[0]['ID']);
        $this->assertStringContainsString('https://cdn.example.com/asset.js', $captured_update[0]['post_content']);
        $this->assertStringNotContainsString('//example.com/cdn/asset.js', $captured_update[0]['post_content']);

        $this->assertIsArray($wpdb->delete_args);
        $this->assertSame('blc_broken_links', $wpdb->delete_args[0]);
        $this->assertSame(
            ['post_id' => 12, 'url' => '//example.com/cdn/asset.js', 'type' => 'link'],
            $wpdb->delete_args[1]
        );
        $this->assertSame(['%d', '%s', '%s'], $wpdb->delete_args[2]);
    }

    public function test_edit_and_unlink_handle_long_urls(): void
    {
        $long_old_url = 'https://example.com/' . str_repeat('very-long-path/', 16) . '?query=' . str_repeat('a', 140);
        $long_new_url = 'https://cdn.example.net/' . str_repeat('another-segment/', 16) . '?token=' . str_repeat('b', 140);

        $this->assertGreaterThan(255, strlen($long_old_url));
        $this->assertGreaterThan(255, strlen($long_new_url));

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->alias(function ($capability, $post_id) {
            return $capability === 'edit_post';
        });
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });
        Functions\when('wp_send_json_error')->alias(function () {
            throw new \RuntimeException('error');
        });

        $posts = [
            (object) ['post_content' => '<p><a href="' . $long_old_url . '">Long link</a></p>'],
            (object) ['post_content' => '<p><a href="' . $long_new_url . '">Long link</a></p>'],
        ];

        Functions\when('get_post')->alias(function () use (&$posts) {
            return array_shift($posts);
        });

        $update_calls = [];
        Functions\when('wp_update_post')->alias(function () use (&$update_calls) {
            $update_calls[] = func_get_args();
            return true;
        });

        $success_calls = 0;
        Functions\when('wp_send_json_success')->alias(function () use (&$success_calls) {
            $success_calls++;
            throw new \RuntimeException('success-' . $success_calls);
        });

        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $delete_calls = [];
            public function delete($table, $where, $formats)
            {
                $this->delete_calls[] = [$table, $where, $formats];
                return true;
            }
        };

        $_POST = [
            'post_id' => 42,
            'old_url' => $long_old_url,
            'new_url' => $long_new_url,
        ];

        try {
            blc_ajax_edit_link_callback();
            $this->fail('Expected the edit callback to signal success.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('success-1', $exception->getMessage());
        }

        $this->assertSame(1, $success_calls);
        $this->assertCount(1, $update_calls);
        $this->assertSame(1, count($wpdb->delete_calls));
        $this->assertSame('wp_blc_broken_links', $wpdb->delete_calls[0][0]);
        $this->assertSame($long_old_url, $wpdb->delete_calls[0][1]['url']);
        $this->assertGreaterThan(255, strlen($wpdb->delete_calls[0][1]['url']));
        $this->assertStringContainsString($long_new_url, $update_calls[0][0]['post_content']);
        $this->assertStringNotContainsString($long_old_url, $update_calls[0][0]['post_content']);

        $_POST = [
            'post_id' => 42,
            'url_to_unlink' => $long_new_url,
        ];

        try {
            blc_ajax_unlink_callback();
            $this->fail('Expected the unlink callback to signal success.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('success-2', $exception->getMessage());
        }

        $this->assertSame(2, $success_calls);
        $this->assertCount(2, $update_calls);
        $this->assertSame(2, count($wpdb->delete_calls));
        $this->assertSame($long_new_url, $wpdb->delete_calls[1][1]['url']);
        $this->assertStringNotContainsString($long_new_url, $update_calls[1][0]['post_content']);
        $this->assertStringContainsString('Long link', $update_calls[1][0]['post_content']);
    }

    public function test_unlink_denied_for_user_without_permission(): void
    {
        $_POST['post_id'] = 3;
        $_POST['url_to_unlink'] = 'http://old.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 3)->andReturn(false);
        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
        };
        Functions\expect('get_post')->once()->with(3)->andReturn((object) ['post_content' => '<a href="http://old.com">Link</a>']);
        Functions\expect('wp_send_json_error')->once()->with(['message' => 'Permissions insuffisantes.'])->andReturnUsing(function () {
            throw new \Exception('error');
        });

        $this->expectExceptionMessage('error');
        blc_ajax_unlink_callback();
    }

    public function test_unlink_allows_user_with_permission(): void
    {
        $_POST['post_id'] = 4;
        $_POST['url_to_unlink'] = 'http://old.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 4)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Link</a>'];
        Functions\expect('get_post')->once()->with(4)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        Functions\expect('wp_update_post')->once()->andReturn(true);
        global $wpdb;
        $wpdb = new class {
            public $prefix = '';
            public function delete($table, $where, $formats)
            {
                return true;
            }
        };

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_unlink_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
    }

    public function test_unlink_returns_success_when_post_has_been_deleted(): void
    {
        $_POST['post_id'] = 21;
        $_POST['url_to_unlink'] = 'http://old.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('get_post')->once()->with(21)->andReturn(null);
        Functions\expect('current_user_can')->never();

        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            public array $deleted = [];
            public function delete($table, $where, $formats)
            {
                $this->deleted[] = ['table' => $table, 'where' => $where, 'formats' => $formats];
                return 1;
            }
        };

        Functions\expect('wp_send_json_success')->once()->with(['purged' => true])->andReturnUsing(function () {
            throw new \Exception('success');
        });

        try {
            blc_ajax_unlink_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $exception) {
            $this->assertSame('success', $exception->getMessage());
        }

        $this->assertCount(1, $wpdb->deleted, 'Cleanup should remove the orphaned database row.');
        $deleted = $wpdb->deleted[0];
        $this->assertSame(21, $deleted['where']['post_id']);
        $this->assertSame('link', $deleted['where']['type']);
    }

    public function test_unlink_succeeds_when_no_database_rows_deleted(): void
    {
        $_POST['post_id'] = 10;
        $_POST['url_to_unlink'] = 'http://old.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 10)->andReturn(true);

        $post = (object) ['post_content' => '<a href="http://old.com">Link</a>'];
        Functions\expect('get_post')->once()->with(10)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        Functions\expect('wp_update_post')->once()->andReturn(true);
        global $wpdb;
        $wpdb = new class {
            public $prefix = '';
            public function delete($table, $where, $formats)
            {
                return 0;
            }
        };

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_unlink_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
    }

    public function test_unlink_relative_url_updates_content_and_database(): void
    {
        $_POST['post_id'] = 7;
        $_POST['url_to_unlink'] = '/relative/path';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 7)->andReturn(true);

        $post = (object) ['post_content' => '<p><a href="/relative/path">Relative</a> link</p>'];
        Functions\expect('get_post')->once()->with(7)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();
            return true;
        });

        global $wpdb;
        $wpdb = new class {
            public $prefix = '';
            public $delete_args = null;
            public function delete($table, $where, $formats)
            {
                $this->delete_args = [$table, $where, $formats];
                return true;
            }
        };

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_unlink_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(7, $captured_update[0]['ID']);
        $this->assertSame('<p>Relative link</p>', trim($captured_update[0]['post_content']));
        $this->assertTrue($captured_update[1]);

        $this->assertSame('', $wpdb->prefix);
        $this->assertIsArray($wpdb->delete_args);
        $this->assertSame('blc_broken_links', $wpdb->delete_args[0]);
        $this->assertSame(
            ['post_id' => 7, 'url' => '/relative/path', 'type' => 'link'],
            $wpdb->delete_args[1]
        );
        $this->assertSame(['%d', '%s', '%s'], $wpdb->delete_args[2]);
    }

    public function test_unlink_scheme_relative_url_updates_content_and_database(): void
    {
        $_POST['post_id'] = 8;
        $_POST['url_to_unlink'] = '//example.com/image.png';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 8)->andReturn(true);

        $post = (object) ['post_content' => '<p>Image: <a href="//example.com/image.png">logo</a></p>'];
        Functions\expect('get_post')->once()->with(8)->andReturn($post);
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });

        $captured_update = null;
        Functions\expect('wp_update_post')->once()->andReturnUsing(function () use (&$captured_update) {
            $captured_update = func_get_args();
            return true;
        });

        global $wpdb;
        $wpdb = new class {
            public $prefix = '';
            public $delete_args = null;
            public function delete($table, $where, $formats)
            {
                $this->delete_args = [$table, $where, $formats];
                return true;
            }
        };

        Functions\expect('wp_send_json_success')->once()->andReturnUsing(function () {
            throw new \Exception('success');
        });

        $initial = libxml_use_internal_errors();

        try {
            blc_ajax_unlink_callback();
            $this->fail('wp_send_json_success was not called');
        } catch (\Exception $e) {
            $this->assertSame('success', $e->getMessage());
        }

        $this->assertSame($initial, libxml_use_internal_errors());
        $this->assertIsArray($captured_update);
        $this->assertCount(2, $captured_update);
        $this->assertIsArray($captured_update[0]);
        $this->assertSame(8, $captured_update[0]['ID']);
        $this->assertStringNotContainsString('<a href="//example.com/image.png">', $captured_update[0]['post_content']);
        $this->assertStringContainsString('Image: logo', preg_replace('/\s+/', ' ', trim($captured_update[0]['post_content'])));

        $this->assertIsArray($wpdb->delete_args);
        $this->assertSame('blc_broken_links', $wpdb->delete_args[0]);
        $this->assertSame(
            ['post_id' => 8, 'url' => '//example.com/image.png', 'type' => 'link'],
            $wpdb->delete_args[1]
        );
        $this->assertSame(['%d', '%s', '%s'], $wpdb->delete_args[2]);
    }
}
