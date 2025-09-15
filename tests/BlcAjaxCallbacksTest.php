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
