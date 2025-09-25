<?php

namespace Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class RequirePostParamsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        Functions\when('plugin_dir_path')->alias(static function ($file) {
            return dirname($file) . '/';
        });
        Functions\when('plugin_dir_url')->justReturn('http://example.com/');
        Functions\when('register_activation_hook')->justReturn();
        Functions\when('register_deactivation_hook')->justReturn();
        Functions\when('add_action')->justReturn();
        Functions\when('add_filter')->justReturn();
        Functions\when('wp_unslash')->alias(static function ($value) {
            return $value;
        });

        require_once __DIR__ . '/../liens-morts-detector-jlg/liens-morts-detector-jlg.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        $_POST = [];
        parent::tearDown();
    }

    public function test_returns_sanitized_values_when_params_are_valid(): void
    {
        $_POST = [
            'post_id'          => '  123 ',
            'row_id'           => ' 45 ',
            'occurrence_index' => ' 2 ',
            'old_url'          => ' https://example.com/old  ',
            'new_url'          => "\t\nhttps://example.com/new\t ",
        ];

        Functions\expect('wp_send_json_error')->never();

        $result = blc_require_post_params(['post_id', 'row_id', 'occurrence_index', 'old_url', 'new_url']);

        $this->assertSame([
            'post_id'          => '123',
            'row_id'           => '45',
            'occurrence_index' => '2',
            'old_url'          => 'https://example.com/old',
            'new_url'          => 'https://example.com/new',
        ], $result);
    }

    public function missingPostParamProvider(): array
    {
        return [
            'missing post_id' => [
                ['row_id' => '1', 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'post_id',
            ],
            'null post_id' => [
                ['post_id' => null, 'row_id' => '1', 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'post_id',
            ],
            'empty post_id' => [
                ['post_id' => '   ', 'row_id' => '1', 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'post_id',
            ],
            'missing row_id' => [
                ['post_id' => '10', 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'row_id',
            ],
            'null row_id' => [
                ['post_id' => '10', 'row_id' => null, 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'row_id',
            ],
            'empty row_id' => [
                ['post_id' => '10', 'row_id' => '   ', 'occurrence_index' => '0', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'row_id',
            ],
            'missing occurrence_index' => [
                ['post_id' => '10', 'row_id' => '3', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'occurrence_index',
            ],
            'null occurrence_index' => [
                ['post_id' => '10', 'row_id' => '3', 'occurrence_index' => null, 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'occurrence_index',
            ],
            'empty occurrence_index' => [
                ['post_id' => '10', 'row_id' => '3', 'occurrence_index' => '   ', 'old_url' => 'http://old.com', 'new_url' => 'http://new.com'],
                'occurrence_index',
            ],
            'missing old_url' => [
                ['post_id' => '10', 'row_id' => '3', 'occurrence_index' => '1', 'new_url' => 'http://new.com'],
                'old_url',
            ],
            'null old_url' => [
                ['post_id' => '10', 'row_id' => '3', 'occurrence_index' => '1', 'old_url' => null, 'new_url' => 'http://new.com'],
                'old_url',
            ],
            'empty old_url' => [
                ['post_id' => '10', 'row_id' => '3', 'occurrence_index' => '1', 'old_url' => '   ', 'new_url' => 'http://new.com'],
                'old_url',
            ],
            'missing new_url' => [
                ['post_id' => '10', 'row_id' => '3', 'occurrence_index' => '1', 'old_url' => 'http://old.com'],
                'new_url',
            ],
            'null new_url' => [
                ['post_id' => '10', 'row_id' => '3', 'occurrence_index' => '1', 'old_url' => 'http://old.com', 'new_url' => null],
                'new_url',
            ],
            'empty new_url' => [
                ['post_id' => '10', 'row_id' => '3', 'occurrence_index' => '1', 'old_url' => 'http://old.com', 'new_url' => '   '],
                'new_url',
            ],
        ];
    }

    /**
     * @dataProvider missingPostParamProvider
     */
    public function test_calls_wp_send_json_error_for_missing_or_empty_params(array $post_data, string $missing_param): void
    {
        $_POST = $post_data;

        Functions\expect('wp_send_json_error')
            ->once()
            ->with(['message' => sprintf('Le paramètre requis "%s" est manquant ou vide.', $missing_param)], 400)
            ->andReturnUsing(static function () {
                throw new \RuntimeException('wp_send_json_error called');
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_send_json_error called');

        blc_require_post_params(['post_id', 'row_id', 'occurrence_index', 'old_url', 'new_url']);
    }
}
