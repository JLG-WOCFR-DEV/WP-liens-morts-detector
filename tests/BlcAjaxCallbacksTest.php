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
        Functions\when('wp_unslash')->alias(function ($value) {
            return $value;
        });
        Functions\when('wp_http_validate_url')->alias(function ($url) {
            return $url;
        });
        Functions\when('esc_url_raw')->alias(function ($url) {
            return $url;
        });
        Functions\when('is_wp_error')->alias(function ($thing): bool {
            return is_object($thing) && method_exists($thing, 'get_error_message');
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

    public function test_unlink_denied_for_user_without_permission(): void
    {
        $_POST['post_id'] = 3;
        $_POST['url_to_unlink'] = 'http://old.com';

        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_post', 3)->andReturn(false);
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
}
