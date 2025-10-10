<?php

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

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

class BlcGoogleSheetsConnectorTest extends TestCase
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
        Functions\when('wp_json_encode')->alias(static fn($value) => json_encode($value));
        Functions\when('apply_filters')->alias(static fn($hook, $value) => $value);

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-google-sheets.php';
    }

    protected function tearDown(): void
    {
        OptionsStore::reset();
        Monkey\tearDown();

        parent::tearDown();
    }

    public function test_maybe_refresh_token_skips_when_access_token_valid(): void
    {
        $this->options['blc_google_sheets_settings'] = [
            'enabled'                 => true,
            'spreadsheet_id'          => 'spreadsheet-123',
            'client_id'               => 'client-1',
            'client_secret'           => 'secret-1',
            'access_token'            => 'token-valid',
            'refresh_token'           => 'refresh-1',
            'access_token_expires_at' => time() + 600,
        ];

        Functions\expect('wp_remote_post')->never();

        $settings = \blc_google_sheets_maybe_refresh_token(\blc_get_google_sheets_settings());

        $this->assertSame('token-valid', $settings['access_token']);
    }

    public function test_maybe_refresh_token_requests_new_token(): void
    {
        $now = time();

        $this->options['blc_google_sheets_settings'] = [
            'enabled'                 => true,
            'spreadsheet_id'          => 'spreadsheet-123',
            'client_id'               => 'client-1',
            'client_secret'           => 'secret-1',
            'access_token'            => 'expired-token',
            'refresh_token'           => 'refresh-1',
            'access_token_expires_at' => $now - 10,
        ];

        Functions\when('wp_remote_post')->alias(static function ($url, $args) {
            return [
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'access_token' => 'fresh-token',
                    'expires_in'   => 1800,
                ], JSON_THROW_ON_ERROR),
            ];
        });

        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($response) {
            return $response['response']['code'] ?? 0;
        });

        Functions\when('wp_remote_retrieve_body')->alias(static function ($response) {
            return $response['body'] ?? '';
        });

        $settings = \blc_google_sheets_maybe_refresh_token(\blc_get_google_sheets_settings());

        $this->assertSame('fresh-token', $settings['access_token']);
        $this->assertGreaterThan($now, $settings['access_token_expires_at']);
    }

    public function test_handle_report_export_pushes_values_to_google_sheets(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'blc-gsheet-test-');
        file_put_contents($csv, "dataset,url\nlink,https://example.com\n");

        $this->options['blc_google_sheets_settings'] = [
            'enabled'                 => true,
            'spreadsheet_id'          => 'spreadsheet-123',
            'client_id'               => 'client-1',
            'client_secret'           => 'secret-1',
            'access_token'            => '',
            'refresh_token'           => 'refresh-1',
            'access_token_expires_at' => time() - 10,
            'ranges'                  => ['link' => 'Liens!A1'],
        ];

        $requests = [];

        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$requests) {
            $requests[] = ['url' => $url, 'args' => $args];

            if (str_contains($url, 'oauth2.googleapis.com')) {
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'access_token' => 'new-token',
                        'expires_in'   => 3600,
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['updatedRange' => 'Liens!A1:B2'], JSON_THROW_ON_ERROR),
            ];
        });

        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($response) {
            return $response['response']['code'] ?? 0;
        });

        Functions\when('wp_remote_retrieve_body')->alias(static function ($response) {
            return $response['body'] ?? '';
        });

        Functions\when('apply_filters')->alias(static function ($hook, $value) {
            return $value;
        });

        \blc_google_sheets_handle_report_export('link', [
            'file_path' => $csv,
            'row_count' => 2,
        ], [
            'state' => 'idle',
        ]);

        $this->assertGreaterThan(0, $this->options['blc_google_sheets_settings']['last_synced_at']);
        $this->assertSame('link', $this->options['blc_google_sheets_settings']['last_synced_dataset']);
        $this->assertSame('', $this->options['blc_google_sheets_settings']['last_error']);

        $this->assertCount(2, $requests);

        $batch_request = $requests[1];
        $this->assertStringContainsString('values:batchUpdate', $batch_request['url']);

        $payload = json_decode($batch_request['args']['body'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('RAW', $payload['valueInputOption']);
        $this->assertSame('Liens!A1', $payload['data'][0]['range']);
        $this->assertSame(['dataset', 'url'], $payload['data'][0]['values'][0]);
        $this->assertSame(['link', 'https://example.com'], $payload['data'][0]['values'][1]);
    }
}

}

