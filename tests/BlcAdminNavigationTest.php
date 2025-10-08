<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';

    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/../');
    }
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BlcAdminNavigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../vendor/autoload.php';

        Monkey\setUp();

        Functions\when('admin_url')->alias(static function ($path = '') {
            $path = (string) $path;

            return 'admin.php?page=' . preg_replace('/^admin\.php\?page=/', '', $path);
        });

        Functions\when('add_query_arg')->alias(static function ($key, $value, $url) {
            $separator = (false === strpos($url, '?')) ? '?' : '&';

            return $url . $separator . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();

        parent::tearDown();
    }

    public function test_navigation_has_accessible_label_and_active_link(): void
    {
        ob_start();
        blc_render_dashboard_tabs('links');
        $html = (string) ob_get_clean();

        $this->assertNotSame('', $html, 'Expected navigation markup to be rendered.');
        $this->assertStringContainsString('<nav class="blc-admin-tabs"', $html);
        $this->assertStringContainsString('aria-label="Navigation du tableau de bord Liens Morts"', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertStringContainsString('href="admin.php?page=blc-dashboard"', $html);
        $this->assertStringContainsString('class="blc-admin-tabs__link is-active"', $html);
    }

    public function test_navigation_marks_requested_tab_as_current(): void
    {
        ob_start();
        blc_render_dashboard_tabs('history');
        $html = (string) ob_get_clean();

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertStringContainsString('href="admin.php?page=blc-history" aria-current="page"', $html);
        $this->assertStringNotContainsString('href="admin.php?page=blc-dashboard" aria-current="page"', $html);
        $this->assertStringContainsString('class="blc-admin-tabs__link is-active" href="admin.php?page=blc-history"', $html);
    }
}

}
