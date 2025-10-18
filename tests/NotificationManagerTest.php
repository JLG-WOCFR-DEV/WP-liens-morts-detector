<?php

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/translation-stubs.php';

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
use JLG\BrokenLinks\Notifications\NotificationManager;
use PHPUnit\Framework\TestCase;

class NotificationManagerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array<string, mixed>> */
    private array $sentEmails = [];

    /** @var array<int, array<string, mixed>> */
    private array $httpRequests = [];

    /** @var array<int, string> */
    private array $errorLogs = [];

    private ?int $throttleWindow = null;

    protected function setUp(): void
    {
        parent::setUp();

        Monkey\setUp();

        $this->options        = [];
        $this->sentEmails     = [];
        $this->httpRequests   = [];
        $this->errorLogs      = [];
        $this->throttleWindow = null;

        $testCase = $this;

        Functions\when('get_option')->alias(static function ($name, $default = false) use ($testCase) {
            return $testCase->options[$name] ?? $default;
        });

        Functions\when('update_option')->alias(static function ($name, $value) use ($testCase) {
            $testCase->options[$name] = $value;

            return true;
        });

        Functions\when('apply_filters')->alias(static function ($hook, $value) use ($testCase) {
            if ($hook === 'blc_notification_throttle_window') {
                return $testCase->throttleWindow !== null ? $testCase->throttleWindow : $value;
            }

            return $value;
        });

        Functions\when('blc_is_webhook_notification_configured')->alias(static function ($settings = null) {
            return is_array($settings) && !empty($settings['url']);
        });

        Functions\when('blc_render_notification_message_template')->alias(static function ($template, $summary) {
            return isset($summary['subject']) ? (string) $summary['subject'] : '';
        });

        Functions\when('blc_build_notification_webhook_payload')->alias(static function ($channel, $message, $summary, $settings) {
            return array(
                'channel' => $channel,
                'text'    => $message,
            );
        });

        Functions\when('wp_json_encode')->alias(static fn($value) => json_encode($value));

        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($response) {
            return isset($response['code']) ? (int) $response['code'] : 200;
        });

        Functions\when('wp_mail')->alias(static function ($recipients, $subject, $message) use ($testCase) {
            $testCase->sentEmails[] = array(
                'recipients' => $recipients,
                'subject'    => $subject,
                'message'    => $message,
            );

            return true;
        });

        Functions\when('wp_remote_post')->alias(static function ($url, $args) use ($testCase) {
            $testCase->httpRequests[] = array('url' => $url, 'args' => $args);

            return array(
                'code' => 200,
                'body' => '{}',
            );
        });

        Functions\when('error_log')->alias(static function ($message) use ($testCase) {
            $testCase->errorLogs[] = (string) $message;

            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_send_summary_notifications_records_history_and_throttles(): void
    {
        $this->throttleWindow = 600;

        $times = [1000, 1000, 1200, 1200];
        $manager = new NotificationManager(static function () use (&$times) {
            return array_shift($times) ?? 1200;
        });

        $summary = array(
            'subject'       => 'Résumé',
            'message'       => 'Contenu',
            'dataset_label' => 'Analyse des liens',
        );

        $recipients = array('admin@example.test');
        $settings   = array('url' => 'https://hooks.example.test', 'channel' => 'slack');

        $first = $manager->sendSummaryNotifications('link', $summary, $recipients, array(
            'context'          => 'scan',
            'webhook_settings' => $settings,
        ));

        $this->assertSame('sent', $first['email']['status']);
        $this->assertSame('sent', $first['webhook']['status']);
        $this->assertSame(200, $first['webhook']['code']);

        $this->assertArrayHasKey('blc_notification_delivery_log', $this->options);
        $this->assertCount(2, $this->options['blc_notification_delivery_log']);

        $second = $manager->sendSummaryNotifications('link', $summary, $recipients, array(
            'context'          => 'scan',
            'webhook_settings' => $settings,
        ));

        $this->assertSame('throttled', $second['email']['status']);
        $this->assertSame('throttled', $second['webhook']['status']);
        $this->assertCount(1, $this->sentEmails);
        $this->assertCount(1, $this->httpRequests);

        $history = $manager->getHistoryEntries();
        $this->assertCount(4, $history);
        $this->assertSame('throttled', $history[2]['status']);
        $this->assertSame('throttled', $history[3]['status']);
    }

    public function test_send_summary_notifications_records_failed_email_when_wp_mail_returns_false(): void
    {
        $testCase = $this;

        Functions\when('wp_mail')->alias(static function ($recipients, $subject, $message) use ($testCase) {
            $testCase->sentEmails[] = array(
                'recipients' => $recipients,
                'subject'    => $subject,
                'message'    => $message,
            );

            return false;
        });

        $manager = new NotificationManager(static function () {
            return 1500;
        });

        $summary = array(
            'subject'       => 'Résumé',
            'message'       => 'Contenu',
            'dataset_label' => 'Analyse des liens',
        );

        $recipients = array('admin@example.test');
        $settings   = array('url' => 'https://hooks.example.test', 'channel' => 'slack');

        $result = $manager->sendSummaryNotifications('link', $summary, $recipients, array(
            'context'          => 'scan',
            'webhook_settings' => $settings,
        ));

        $failureMessage = 'Échec de l’envoi de l’e-mail pour l’analyse Analyse des liens.';

        $this->assertSame('failed', $result['email']['status']);
        $this->assertSame($failureMessage, $result['email']['error']);
        $this->assertCount(1, $this->errorLogs);
        $this->assertStringContainsString('Failed to send link summary email', $this->errorLogs[0]);

        $history = $manager->getHistoryEntries();
        $this->assertCount(2, $history);
        $this->assertSame('failed', $history[0]['status']);
        $this->assertSame('email', $history[0]['channel']);
        $this->assertSame($failureMessage, $history[0]['error']);
    }

    public function test_send_webhook_only_returns_wp_error_on_failure_and_logs_history(): void
    {
        $this->throttleWindow = 0;

        Functions\when('wp_remote_post')->alias(static function () {
            return new \WP_Error('http_request_failed', 'Timeout');
        });

        $manager = new NotificationManager(static function () {
            return 2000;
        });

        $summary = array(
            'subject' => 'Résumé',
            'message' => 'Contenu',
        );
        $settings = array('url' => 'https://hooks.example.test', 'channel' => 'slack');

        $result = $manager->sendWebhookOnly('link', $summary, $settings, array('context' => 'scan'));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('http_request_failed', $result->get_error_code());

        $history = $manager->getHistoryEntries();
        $this->assertCount(1, $history);
        $this->assertSame('failed', $history[0]['status']);
        $this->assertArrayHasKey('error', $history[0]);
        $this->assertSame('Timeout', $history[0]['error']);
        $this->assertNotEmpty($this->errorLogs);
    }
}
}
