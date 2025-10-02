<?php

namespace Tests\Scanner;

use Brain\Monkey\Functions;

final class ScanQueueTest extends ScannerTestCase
{
    public function test_get_scan_cache_context_reuses_existing_cache(): void
    {
        $this->options['blc_active_link_scan_key'] = 'existing-key';
        $transient = blc_build_scan_cache_transient_name('link', 'existing-key');
        $this->transients[$transient] = [
            'value'      => ['cached' => ['foo' => 'bar']],
            'expiration' => 3600,
        ];

        $context = blc_get_scan_cache_context('link', 1);

        $this->assertSame('existing-key', $context['key']);
        $this->assertSame('blc_active_link_scan_key', $context['option']);
        $this->assertSame(
            ['cached' => ['foo' => 'bar']],
            $context['data'],
            'Stored scan cache should be loaded when a key is present.'
        );
    }

    public function test_get_scan_cache_context_generates_new_key_for_first_batch(): void
    {
        $context = blc_get_scan_cache_context('link', 0);

        $this->assertArrayHasKey('key', $context);
        $this->assertNotSame('', $context['key']);
        $this->assertSame('blc_active_link_scan_key', $context['option']);
        $this->assertSame(
            $context['key'],
            $this->options['blc_active_link_scan_key'] ?? '',
            'Fresh scan key should be persisted for subsequent batches.'
        );
        $this->assertSame([], $context['data']);
    }

    public function test_save_scan_cache_persists_payload_with_filtered_expiration(): void
    {
        $context = blc_get_scan_cache_context('link', 0);
        $transientName = $context['transient'];

        Functions\when('apply_filters')->alias(function ($hook, $value, ...$args) use ($context) {
            if ($hook === 'blc_scan_cache_expiration') {
                return 7200;
            }

            return $value;
        });

        $payload = ['items' => [1, 2, 3]];
        blc_save_scan_cache($context, $payload);

        $this->assertSame($payload, $context['data']);
        $this->assertArrayHasKey($transientName, $this->transients);
        $this->assertSame($payload, $this->transients[$transientName]['value']);
        $this->assertSame(7200, $this->transients[$transientName]['expiration']);
    }

    public function test_clear_scan_cache_removes_transient_and_active_option(): void
    {
        $context = blc_get_scan_cache_context('link', 0);
        blc_save_scan_cache($context, ['foo' => 'bar']);

        blc_clear_scan_cache($context);

        $this->assertArrayNotHasKey($context['transient'], $this->transients);
        $this->assertArrayNotHasKey('blc_active_link_scan_key', $this->options);
    }
}
