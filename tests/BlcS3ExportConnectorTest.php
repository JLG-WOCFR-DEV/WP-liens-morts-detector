<?php

namespace {
    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            /** @var string */
            private $code;

            /** @var string */
            private $message;

            /** @var mixed */
            private $data;

            public function __construct($code = '', $message = '', $data = null)
            {
                $this->code    = (string) $code;
                $this->message = (string) $message;
                $this->data    = $data;
            }

            public function get_error_code()
            {
                return $this->code;
            }

            public function get_error_message()
            {
                return $this->message;
            }

            public function get_error_data()
            {
                return $this->data;
            }
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing)
        {
            return $thing instanceof \WP_Error;
        }
    }
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

class BlcS3ExportConnectorTest extends TestCase
{
    /**
     * @var array<string,mixed>
     */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../vendor/autoload.php';

        Monkey\setUp();

        require_once __DIR__ . '/translation-stubs.php';
        require_once __DIR__ . '/wp-option-stubs.php';

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        OptionsStore::reset();
        $this->options = &OptionsStore::$options;

        Functions\when('add_action')->justReturn(true);
        Functions\when('register_rest_route')->justReturn(true);
        Functions\when('rest_ensure_response')->alias(static fn($value) => $value);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(static function ($value) {
            return is_scalar($value) ? trim((string) $value) : '';
        });
        Functions\when('apply_filters')->alias(static fn($hook, $value) => $value);

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-s3-exports.php';
    }

    protected function tearDown(): void
    {
        OptionsStore::reset();
        Monkey\tearDown();

        parent::tearDown();
    }

    public function test_handle_report_export_uploads_to_s3(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'blc-s3-test-');
        file_put_contents($csv, "dataset,url\nlink,https://example.com\n");

        $this->options['blc_s3_export_settings'] = [
            'enabled'           => true,
            'bucket'            => 'reports-bucket',
            'region'            => 'eu-west-3',
            'endpoint'          => 'https://s3.example.com',
            'access_key_id'     => 'AKIAIOSAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCY',
            'object_prefix'     => 'exports',
            'session_token'     => '',
        ];

        $requests = [];

        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$requests) {
            $requests[] = ['url' => $url, 'args' => $args];

            return [
                'response' => ['code' => 200],
                'body'     => '',
            ];
        });

        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($response) {
            return $response['response']['code'] ?? 0;
        });

        Functions\when('wp_remote_retrieve_body')->alias(static function ($response) {
            return $response['body'] ?? '';
        });

        \blc_s3_handle_report_export('link', [
            'file_path'    => $csv,
            'job_id'       => 'job-42',
            'completed_at' => 1696110000,
        ], [
            'state' => 'idle',
        ]);

        $settings = \blc_get_s3_settings();

        $this->assertNotEmpty($settings['last_synced_at']);
        $this->assertSame('link', $settings['last_synced_dataset']);
        $this->assertStringStartsWith('exports/link/20230930/', $settings['last_synced_key']);
        $this->assertSame('', $settings['last_error']);

        $this->assertCount(1, $requests);
        $request = $requests[0];

        $this->assertSame('PUT', $request['args']['method']);
        $this->assertStringContainsString('https://reports-bucket.s3.example.com', $request['url']);

        $headers = $request['args']['headers'];
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertMatchesRegularExpression(
            '/Credential=AKIAIOSAMPLE\/[0-9]{8}\/eu-west-3\/s3\/aws4_request/',
            $headers['Authorization']
        );
        $this->assertArrayHasKey('X-Amz-Date', $headers);
        $this->assertMatchesRegularExpression('/^[0-9]{8}T[0-9]{6}Z$/', $headers['X-Amz-Date']);
    }

    public function test_handle_report_export_stores_error_on_failure(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'blc-s3-test-');
        file_put_contents($csv, 'dataset,url');

        $this->options['blc_s3_export_settings'] = [
            'enabled'           => true,
            'bucket'            => 'reports-bucket',
            'region'            => 'eu-west-3',
            'endpoint'          => 'https://s3.example.com',
            'access_key_id'     => 'AKIAIOSAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCY',
        ];

        Functions\when('wp_remote_request')->alias(static function () {
            return new \WP_Error('s3_error', 'Unable to upload');
        });

        \blc_s3_handle_report_export('link', [
            'file_path'    => $csv,
            'completed_at' => 1696110000,
        ], []);

        $settings = \blc_get_s3_settings();

        $this->assertSame('s3_error', $settings['last_error_code']);
        $this->assertSame('Unable to upload', $settings['last_error']);
    }
}

}
