<?php

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/translation-stubs.php';
    require_once __DIR__ . '/wp-option-stubs.php';

    if (!function_exists('blc_render_notification_message_template')) {
        function blc_render_notification_message_template($template, $summary)
        {
            return isset($summary['subject']) ? (string) $summary['subject'] : '';
        }
    }

    if (!function_exists('blc_build_notification_webhook_payload')) {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-notification-payloads.php';
    }

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
use Tests\Stubs\OptionsStore;

class NotificationManagerTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $sentEmails = [];

    /** @var array<int, array<string, mixed>> */
    private array $httpRequests = [];

    /** @var array<int, string> */
    private array $errorLogs = [];

    private ?int $throttleWindow = null;

    /** @var array<string, mixed> */
    private array $webhookSettings = [];

    /** @var array<string, mixed> */
    private array $escalationSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        Monkey\setUp();

        OptionsStore::reset();
        $this->sentEmails     = [];
        $this->httpRequests   = [];
        $this->errorLogs      = [];
        $this->throttleWindow = null;

        $testCase = $this;

        Functions\when('apply_filters')->alias(static function ($hook, $value) use ($testCase) {
            if ($hook === 'blc_notification_throttle_window') {
                return $testCase->throttleWindow !== null ? $testCase->throttleWindow : $value;
            }

            return $value;
        });

        Functions\when('blc_is_webhook_notification_configured')->alias(static function ($settings = null) {
            return is_array($settings) && !empty($settings['url']);
        });

        $this->webhookSettings = array(
            'url'              => 'https://hooks.example.test',
            'channel'          => 'slack',
            'message_template' => '{{subject}}',
            'severity'         => 'warning',
        );

        $this->escalationSettings = array(
            'mode'             => 'disabled',
            'url'              => '',
            'channel'          => 'disabled',
            'message_template' => '{{subject}}',
            'severity'         => 'critical',
        );

        Functions\when('blc_get_notification_webhook_settings')->alias(static function ($overrides = array()) use ($testCase) {
            $settings = $testCase->webhookSettings;
            if (is_array($overrides)) {
                foreach ($overrides as $key => $value) {
                    $settings[$key] = $value;
                }
            }

            return $settings;
        });

        Functions\when('blc_get_notification_escalation_settings')->alias(static function ($overrides = array()) use ($testCase) {
            $settings = $testCase->escalationSettings;
            if (is_array($overrides)) {
                foreach ($overrides as $key => $value) {
                    $settings[$key] = $value;
                }
            }

            return $settings;
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
            'severity'      => 'critical',
        );

        $recipients = array('admin@example.test');
        $settings   = array('url' => 'https://hooks.example.test', 'channel' => 'slack', 'severity' => 'warning');

        $first = $manager->sendSummaryNotifications('link', $summary, $recipients, array(
            'context'          => 'scan',
            'webhook_settings' => $settings,
        ));

        $this->assertSame('sent', $first['email']['status']);
        $this->assertSame('sent', $first['webhook']['status']);
        $this->assertSame(200, $first['webhook']['code']);
        $this->assertSame('skipped', $first['escalation']['status']);

        $this->assertArrayHasKey('blc_notification_delivery_log', OptionsStore::$options);
        $this->assertCount(2, OptionsStore::$options['blc_notification_delivery_log']);

        $second = $manager->sendSummaryNotifications('link', $summary, $recipients, array(
            'context'          => 'scan',
            'webhook_settings' => $settings,
        ));

        $this->assertSame('throttled', $second['email']['status']);
        $this->assertSame('throttled', $second['webhook']['status']);
        $this->assertSame('skipped', $second['escalation']['status']);
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
            'severity'      => 'critical',
        );

        $recipients = array('admin@example.test');
        $settings   = array('url' => 'https://hooks.example.test', 'channel' => 'slack', 'severity' => 'warning');

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
    public function test_webhook_skipped_when_severity_below_threshold(): void
    {
        $manager = new NotificationManager(static function () {
            return 3000;
        });

        $summary = array(
            'subject'       => 'Résumé',
            'message'       => 'Contenu',
            'dataset_label' => 'Analyse des liens',
            'severity'      => 'info',
        );

        $recipients = array('admin@example.test');
        $settings   = array('url' => 'https://hooks.example.test', 'channel' => 'slack', 'severity' => 'critical');

        $result = $manager->sendSummaryNotifications('link', $summary, $recipients, array(
            'context'          => 'scan',
            'webhook_settings' => $settings,
        ));

        $this->assertSame('sent', $result['email']['status']);
        $this->assertSame('skipped', $result['webhook']['status']);
        $this->assertSame('skipped', $result['escalation']['status']);

        $history = $manager->getHistoryEntries();
        $this->assertSame('webhook', $history[1]['channel']);
        $this->assertSame('skipped', $history[1]['status']);
        $this->assertSame('below_severity', $history[1]['reason']);
        $this->assertSame('info', $history[1]['severity']);
        $this->assertSame('critical', $history[1]['severity_threshold']);
    }

    public function test_escalation_triggered_when_severity_meets_threshold(): void
    {
        $this->escalationSettings = array(
            'mode'             => 'webhook',
            'url'              => 'https://hooks.example.test/escalate',
            'channel'          => 'generic',
            'message_template' => '{{subject}}',
            'severity'         => 'warning',
        );

        $manager = new NotificationManager(static function () {
            return 4000;
        });

        $summary = array(
            'subject'       => 'Résumé',
            'message'       => 'Contenu',
            'dataset_label' => 'Analyse des liens',
            'severity'      => 'critical',
        );

        $recipients = array('admin@example.test');
        $settings   = array('url' => 'https://hooks.example.test', 'channel' => 'slack', 'severity' => 'warning');

        $result = $manager->sendSummaryNotifications('link', $summary, $recipients, array(
            'context'          => 'scan',
            'webhook_settings' => $settings,
        ));

        $this->assertSame('sent', $result['webhook']['status']);
        $this->assertSame('sent', $result['escalation']['status']);
        $this->assertCount(2, $this->httpRequests);
        $this->assertSame('https://hooks.example.test/escalate', $this->httpRequests[1]['url']);

        $history = $manager->getHistoryEntries();
        $channels = array_column($history, 'channel');
        $this->assertContains('escalation', $channels);
    }

}
}
