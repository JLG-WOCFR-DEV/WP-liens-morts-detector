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
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

class BlcDatasetSizeCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        require_once __DIR__ . '/wp-option-stubs.php';
        OptionsStore::reset();

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-utils.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        unset($GLOBALS['wpdb']);
        OptionsStore::reset();
        parent::tearDown();
    }

    public function test_adjust_dataset_storage_footprint_updates_cache_without_query(): void
    {
        $type = 'link';
        $actualBytes = 0;

        $wpdbStub = new class {
            public string $prefix = 'wp_';
            public int $getVarCalls = 0;

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function get_var($query)
            {
                $this->getVarCalls++;

                return 0;
            }
        };

        $GLOBALS['wpdb'] = $wpdbStub;

        $cacheKey = \blc_get_dataset_size_cache_key($type);
        $this->assertNotSame('', $cacheKey);

        $actualBytes += 120;
        \blc_adjust_dataset_storage_footprint($type, 120);
        $this->assertSame($actualBytes, OptionsStore::$options[$cacheKey] ?? null);

        $actualBytes = max(0, $actualBytes - 50);
        \blc_adjust_dataset_storage_footprint($type, -50);
        $this->assertSame($actualBytes, OptionsStore::$options[$cacheKey] ?? null);

        $actualBytes = max(0, $actualBytes - 500);
        \blc_adjust_dataset_storage_footprint($type, -500);
        $this->assertSame($actualBytes, OptionsStore::$options[$cacheKey] ?? null);

        $this->assertSame(0, $GLOBALS['wpdb']->getVarCalls);
    }

    public function test_adjust_dataset_storage_footprint_ignores_invalid_cache_value(): void
    {
        $type = 'image';
        $cacheKey = \blc_get_dataset_size_cache_key($type);
        $this->assertNotSame('', $cacheKey);

        OptionsStore::$options[$cacheKey] = 'not-a-number';

        \blc_adjust_dataset_storage_footprint($type, 75);

        $this->assertSame(75, OptionsStore::$options[$cacheKey] ?? null);
    }
}
}
