<?php

namespace {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

namespace Tests\Scanner {

use Brain\Monkey\Functions;
use JLG\BrokenLinks\Scanner\ScanPreflight;

final class ScanPreflightTest extends ScannerTestCase
{
    public function test_collect_normalises_configuration(): void
    {
        $this->options['blc_debug_mode'] = true;
        $this->options['blc_rest_start_hour'] = '22';
        $this->options['blc_rest_end_hour'] = '06';
        $this->options['blc_link_delay'] = '150';
        $this->options['blc_batch_delay'] = '75';

        Functions\when('current_filter')->alias(fn() => 'blc_check_links');
        Functions\when('apply_filters')->alias(function ($hook, $value) {
            if ($hook === 'blc_link_scan_lock_timeout') {
                return '1800';
            }

            return $value;
        });

        $collector = new ScanPreflight();
        $result = $collector->collect(3, false, true);

        self::assertSame(3, $result['batch']);
        self::assertTrue($result['is_full_scan']);
        self::assertFalse($result['bypass_rest_window']);
        self::assertTrue($result['debug_mode']);
        self::assertSame('blc_check_links', $result['current_hook']);
        self::assertSame(22, $result['rest_start_hour']);
        self::assertSame(6, $result['rest_end_hour']);
        self::assertSame(150, $result['link_delay_ms']);
        self::assertSame(75, $result['batch_delay_s']);
        self::assertSame(1800, $result['lock_timeout']);
    }
}

}
