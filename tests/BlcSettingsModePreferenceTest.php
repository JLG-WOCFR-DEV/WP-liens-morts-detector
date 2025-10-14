<?php

namespace Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BlcSettingsModePreferenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        Functions\when('sanitize_key')->alias(static function ($value) {
            return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_settings_mode_defaults_to_simple_when_meta_missing(): void
    {
        Functions\when('get_current_user_id')->justReturn(12);
        Functions\when('get_user_meta')->justReturn('');

        $this->assertSame('simple', blc_get_settings_mode());
    }

    public function test_get_settings_mode_returns_advanced_when_stored(): void
    {
        Functions\when('get_current_user_id')->justReturn(34);
        Functions\when('get_user_meta')->justReturn('advanced');

        $this->assertSame('advanced', blc_get_settings_mode());
    }

    public function test_update_settings_mode_persists_normalized_value(): void
    {
        $captured = [];

        Functions\when('get_current_user_id')->justReturn(56);
        Functions\when('update_user_meta')->alias(static function ($user_id, $key, $value) use (&$captured) {
            $captured = [$user_id, $key, $value];

            return true;
        });

        $result = blc_update_settings_mode('ADVANCED');

        $this->assertSame('advanced', $result);
        $this->assertSame([56, BLC_SETTINGS_MODE_META_KEY, 'advanced'], $captured);
    }

    public function test_update_settings_mode_falls_back_to_simple_on_invalid_value(): void
    {
        $captured = [];

        Functions\when('get_current_user_id')->justReturn(78);
        Functions\when('update_user_meta')->alias(static function ($user_id, $key, $value) use (&$captured) {
            $captured = [$user_id, $key, $value];

            return true;
        });

        $result = blc_update_settings_mode('unexpected');

        $this->assertSame('simple', $result);
        $this->assertSame([78, BLC_SETTINGS_MODE_META_KEY, 'simple'], $captured);
    }
}
