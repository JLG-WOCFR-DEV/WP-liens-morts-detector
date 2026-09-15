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
use JLG\BrokenLinks\Admin\AdminAssets;
use PHPUnit\Framework\TestCase;

class Phase2AdminCharterAndWp71Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        Functions\when('apply_filters')->alias(static function ($hook, $value, ...$args) {
            return $value;
        });
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('admin_url')->alias(static function ($path = '') {
            $path = (string) $path;

            return 'admin.php?page=' . preg_replace('/^admin\.php\?page=/', '', $path);
        });
        Functions\when('add_query_arg')->alias(static function ($key, $value, $url) {
            $separator = (false === strpos($url, '?')) ? '?' : '&';

            return $url . $separator . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        });
        Functions\when('wp_unslash')->alias(static fn($value) => $value);
        Functions\when('is_admin')->justReturn(true);
        Functions\when('wp_is_block_editor')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('attachment_url_to_postid')->justReturn(0);
        Functions\when('wp_get_attachment_image')->justReturn('');
        Functions\when('get_permalink')->justReturn('');
    }

    protected function tearDown(): void
    {
        unset($_GET['page'], $_GET['canvas'], $_GET['context']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_plugin_headers_declare_wordpress_71(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../liens-morts-detector-jlg/liens-morts-detector-jlg.php');
        $readme = (string) file_get_contents(__DIR__ . '/../liens-morts-detector-jlg/readme.txt');
        $rootReadme = (string) file_get_contents(__DIR__ . '/../README.md');

        $this->assertMatchesRegularExpression('/Requires at least:\s*5\.8/', $plugin);
        $this->assertMatchesRegularExpression('/Tested up to:\s*7\.1/', $plugin);
        $this->assertMatchesRegularExpression('/Requires PHP:\s*7\.4/', $plugin);

        $this->assertMatchesRegularExpression('/Requires at least:\s*5\.8/', $readme);
        $this->assertMatchesRegularExpression('/Tested up to:\s*7\.1/', $readme);
        $this->assertMatchesRegularExpression('/Requires PHP:\s*7\.4/', $readme);

        $this->assertStringContainsString('Tested up to: 7.1', $rootReadme);
        $this->assertStringContainsString('Requires at least: 5.8', $rootReadme);
        $this->assertStringContainsString('Requires PHP: 7.4', $rootReadme);
    }

    public function test_admin_heading_places_nav_tabs_under_h1(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';

        ob_start();
        blc_render_admin_page_header('Réglages', 'settings', '');
        $html = (string) ob_get_clean();

        $wrapPos = strpos($html, 'class="wrap blc-wrap"');
        $h1Pos = strpos($html, '<h1>Réglages</h1>');
        $tabPos = strpos($html, '<nav class="nav-tab-wrapper"');

        $this->assertNotFalse($wrapPos);
        $this->assertNotFalse($h1Pos);
        $this->assertNotFalse($tabPos);
        $this->assertLessThan($h1Pos, $wrapPos);
        $this->assertLessThan($tabPos, $h1Pos);
        $this->assertStringContainsString('class="nav-tab nav-tab-active"', $html);

        $source = (string) file_get_contents(__DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php');
        $this->assertSame(5, substr_count($source, 'blc_render_admin_page_header('));
        $this->assertDoesNotMatchRegularExpression(
            '/blc_render_dashboard_tabs\([^)]+\);\s*<h1>/s',
            $source
        );
    }

    public function test_settings_page_keeps_settings_api_and_core_controls(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-settings-fields.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';

        Functions\when('get_option')->justReturn('simple');
        Functions\expect('settings_errors')->once()->withNoArgs()->andReturnNull();
        Functions\expect('settings_fields')->once()->with('blc_settings')->andReturnNull();
        Functions\expect('do_settings_sections')->once()->with('blc-settings')->andReturnNull();
        Functions\expect('submit_button')
            ->once()
            ->andReturnUsing(static function () {
                echo '<p class="submit"><input type="submit" class="button button-primary" value="Enregistrer les modifications"></p>';
            });

        ob_start();
        blc_settings_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<div class="wrap blc-wrap">', $html);
        $this->assertStringContainsString('<h1>Réglages</h1>', $html);
        $this->assertStringContainsString('<nav class="nav-tab-wrapper"', $html);
        $this->assertLessThan(
            strpos($html, '<nav class="nav-tab-wrapper"'),
            strpos($html, '<h1>Réglages</h1>')
        );
        $this->assertStringContainsString('class="button button-primary"', $html);
        $this->assertStringContainsString('<form method="post" action="options.php"', $html);

        $source = (string) file_get_contents(__DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php');
        $this->assertStringContainsString('table class="form-table blc-settings-table"', $source);
        $this->assertStringContainsString("settings_fields('blc_settings')", $source);
    }

    public function test_admin_css_does_not_restyle_wp_chrome(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../liens-morts-detector-jlg/assets/css/blc-admin-styles.css');

        $this->assertStringNotContainsString('body[class*="blc-"] #wpbody-content', $css);
        $this->assertDoesNotMatchRegularExpression('/#wpbody-content[^{]*\{[^}]*font-family:[^}]*Inter/s', $css);
        $this->assertStringNotContainsString('--blc-admin-accent: #6e56cf', $css);
        $this->assertMatchesRegularExpression('/--blc-admin-accent:\s*#2271b1/', $css);
        $this->assertMatchesRegularExpression('/--blc-admin-accent-strong:\s*#135e96/', $css);
        $this->assertStringNotContainsString('prefers-color-scheme: dark', $css);
        $this->assertDoesNotMatchRegularExpression('/(?:^|\n)\s*\.button-primary\s*\{/m', $css);
        $this->assertDoesNotMatchRegularExpression('/(?:^|\n)\s*\.notice\s*\{/m', $css);
        $this->assertStringNotContainsString('.wp-admin.blc-preset--bootstrap-audit .wrap .nav-tab', $css);
        $this->assertStringNotContainsString('.wp-admin.blc-preset--wordpress-classic .wrap .nav-tab', $css);
        $this->assertStringNotContainsString('.wp-admin.blc-preset--anime-motion .wrap {', $css);
        $this->assertStringNotContainsString('.wp-admin.blc-ui-enhanced .form-table th', $css);
        $this->assertStringNotContainsString('"Inter"', $css);
    }

    public function test_default_ui_preset_is_wordpress_classic_and_others_are_experimental(): void
    {
        Functions\when('get_option')->justReturn(false);
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-settings-fields.php';

        $this->assertSame('wordpress-classic', blc_get_ui_preset_default());
        $this->assertSame('wordpress-classic', blc_get_active_ui_preset());

        $presets = blc_get_ui_presets();
        $this->assertArrayHasKey('wordpress-classic', $presets);
        $this->assertSame('#2271b1', $presets['wordpress-classic']['accent']);
        $this->assertArrayNotHasKey('experimental', $presets['wordpress-classic']);

        foreach (['headless-minimal', 'shadcn-clean', 'radix-structured', 'bootstrap-audit', 'semantic-insight', 'anime-motion'] as $slug) {
            $this->assertArrayHasKey($slug, $presets);
            $this->assertTrue(!empty($presets[$slug]['experimental']));
        }
    }

    public function test_admin_assets_skip_iframed_editor_screens(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/Admin/AdminAssets.php';

        $assets = new AdminAssets(__DIR__ . '/../liens-morts-detector-jlg/liens-morts-detector-jlg.php');

        $_GET['page'] = 'blc-dashboard';
        $this->assertTrue($assets->shouldEnqueue('toplevel_page_blc-dashboard'));
        $this->assertFalse($assets->isBlockEditorRequest('toplevel_page_blc-dashboard'));

        unset($_GET['page']);
        $this->assertFalse($assets->shouldEnqueue('post.php'));
        $this->assertFalse($assets->shouldEnqueue('post-new.php'));
        $this->assertFalse($assets->shouldEnqueue('site-editor.php'));
        $this->assertTrue($assets->isBlockEditorRequest('site-editor.php'));

        $_GET['canvas'] = 'edit';
        $this->assertFalse($assets->shouldEnqueue('toplevel_page_blc-dashboard'));
        $this->assertTrue($assets->isBlockEditorRequest('index.php'));

        unset($_GET['canvas']);
        $_GET['context'] = 'edit';
        $this->assertTrue($assets->isBlockEditorRequest('index.php'));
        $this->assertFalse($assets->shouldEnqueue('index.php'));
    }

    public function test_admin_script_stack_includes_iframe_guard(): void
    {
        $assets = (string) file_get_contents(__DIR__ . '/../liens-morts-detector-jlg/includes/Admin/AdminAssets.php');
        $adminJs = (string) file_get_contents(__DIR__ . '/../liens-morts-detector-jlg/assets/js/blc-admin-scripts.js');

        $this->assertStringContainsString('blc-editor-iframe-guard', $assets);
        $this->assertStringContainsString('assets/js/editor-iframe-guard.js', $assets);
        $this->assertStringContainsString('blcIsIframedEditorContext', $adminJs);
        $this->assertFileExists(__DIR__ . '/../liens-morts-detector-jlg/assets/js/editor-iframe-guard.js');
    }

    public function test_list_tables_declare_primary_columns_for_wp71_row_headers(): void
    {
        if (!class_exists('WP_List_Table')) {
            require_once __DIR__ . '/stubs/WP_List_Table.php';
        }

        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('add_filter')->justReturn(true);

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/class-blc-links-list-table.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/class-blc-images-list-table.php';

        $links = new class() extends \BLC_Links_List_Table {
            public function getColumnHeaders(): array
            {
                return $this->_column_headers;
            }

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

        $links->prepare_items([]);
        $headers = $links->getColumnHeaders();
        $this->assertSame('url', $headers[3]);

        $primary = new \ReflectionMethod(\BLC_Links_List_Table::class, 'get_primary_column_name');
        $primary->setAccessible(true);
        $this->assertSame('url', $primary->invoke($links));
        $this->assertStringContainsString('class="row-title"', $links->renderColumnUrl([
            'url'     => 'https://example.com/dead',
            'post_id' => 0,
        ]));

        $images = new class() extends \BLC_Images_List_Table {
            public function getColumnHeaders(): array
            {
                return $this->_column_headers;
            }

            public function renderImageDetails(array $item)
            {
                return parent::column_image_details($item);
            }
        };

        $images->prepare_items([]);
        $imageHeaders = $images->getColumnHeaders();
        $this->assertSame('image_details', $imageHeaders[3]);

        $imagePrimary = new \ReflectionMethod(\BLC_Images_List_Table::class, 'get_primary_column_name');
        $imagePrimary->setAccessible(true);
        $this->assertSame('image_details', $imagePrimary->invoke($images));
        $this->assertStringContainsString('class="row-title"', $images->renderImageDetails([
            'url'    => 'https://example.com/broken.jpg',
            'anchor' => 'broken.jpg',
        ]));

        $rowHtmlSource = (string) file_get_contents(__DIR__ . '/../liens-morts-detector-jlg/includes/class-blc-links-list-table.php');
        $this->assertMatchesRegularExpression(
            '/function render_row_html[\s\S]*_column_headers\s*=\s*\[[\s\S]*get_primary_column_name\(\)/',
            $rowHtmlSource
        );
    }
}

}
