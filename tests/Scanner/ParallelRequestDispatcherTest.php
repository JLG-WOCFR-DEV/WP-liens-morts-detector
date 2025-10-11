<?php

namespace {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

namespace Tests\Scanner {

use Brain\Monkey\Functions;
use JLG\BrokenLinks\Scanner\ParallelRequestDispatcher;
use JLG\BrokenLinks\Scanner\HttpClientInterface;
use Mockery;
use WP_Error;

final class ParallelRequestDispatcherTest extends ScannerTestCase
{
    public function test_enqueue_executes_head_and_get_requests(): void
    {
        Functions\when('apply_filters')->alias(fn($hook, $value) => $value);

        $client = Mockery::mock(HttpClientInterface::class);
        $client->shouldReceive('head')->once()->andReturnUsing(function ($url, $args) {
            self::assertSame('https://example.test', $url);
            self::assertSame(1.5, $args['timeout']);
            return new WP_Error('head_failed', 'failure');
        });
        $client->shouldReceive('get')->once()->andReturn(['response' => ['code' => 200]]);
        $client->shouldReceive('responseCode')->andReturnUsing(function ($response) {
            if (is_array($response) && isset($response['response']['code'])) {
                return (int) $response['response']['code'];
            }

            return 0;
        });

        $dispatcher = new ParallelRequestDispatcher($client, 2, 0, static fn() => []);

        $captured = [];
        $dispatcher->enqueue(
            'https://example.test',
            ['timeout' => 1.5],
            ['timeout' => 1.5],
            'precise',
            [429],
            function ($response, $headDisallowed, $temporaryFallback, $usedGet) use (&$captured) {
                $captured = [
                    'response'           => $response,
                    'headDisallowed'     => $headDisallowed,
                    'temporaryFallback'  => $temporaryFallback,
                    'usedGet'            => $usedGet,
                ];
            }
        );

        $dispatcher->drain();

        self::assertSame(['response' => ['code' => 200]], $captured['response']);
        self::assertFalse($captured['headDisallowed']);
        self::assertFalse($captured['temporaryFallback']);
        self::assertTrue($captured['usedGet']);
    }
}

}
