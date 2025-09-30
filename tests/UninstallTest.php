<?php

namespace Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../vendor/autoload.php';

        Monkey\setUp();

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();

        parent::tearDown();
    }

    public function test_uninstall_deletes_dataset_size_options(): void
    {
        $deleted_options = [];
        $deleted_site_options = [];

        Functions\when('delete_option')->alias(static function (string $option) use (&$deleted_options): void {
            $deleted_options[] = $option;
        });

        Functions\when('delete_site_option')->alias(static function (string $option) use (&$deleted_site_options): void {
            $deleted_site_options[] = $option;
        });

        Functions\when('is_multisite')->justReturn(false);
        Functions\when('wp_clear_scheduled_hook')->justReturn();

        global $wpdb;

        $wpdb = new class {
            public string $prefix = 'wp_';

            public array $queries = [];

            public function query(string $sql): void
            {
                $this->queries[] = $sql;
            }
        };

        require __DIR__ . '/../liens-morts-detector-jlg/uninstall.php';

        self::assertContains('blc_dataset_size_link', $deleted_options);
        self::assertContains('blc_dataset_size_image', $deleted_options);
        self::assertContains('blc_dataset_size_link', $deleted_site_options);
        self::assertContains('blc_dataset_size_image', $deleted_site_options);
    }

    public function test_uninstall_drops_table_with_backticks(): void
    {
        Functions\when('delete_option')->justReturn();
        Functions\when('delete_site_option')->justReturn();
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('wp_clear_scheduled_hook')->justReturn();

        global $wpdb;

        $wpdb = new class {
            public string $prefix = 'wp-test_';

            public array $queries = [];

            public function query(string $sql): void
            {
                $this->queries[] = $sql;
            }
        };

        require __DIR__ . '/../liens-morts-detector-jlg/uninstall.php';

        self::assertSame(['DROP TABLE IF EXISTS `wp-test_blc_broken_links`'], $wpdb->queries);
    }
}

