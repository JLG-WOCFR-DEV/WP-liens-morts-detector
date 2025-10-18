<?php

namespace Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class SurveillanceEscalationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/translation-stubs.php';
        require_once __DIR__ . '/wp-option-stubs.php';

        Monkey\setUp();

        Functions\when('sanitize_key')->alias(static function ($value) {
            $value = is_scalar($value) ? (string) $value : '';
            $value = strtolower($value);

            return preg_replace('/[^a-z0-9_\-]/', '', $value);
        });

        Functions\when('get_option')->alias(static function ($option, $default = false) {
            switch ($option) {
                case 'blc_surveillance_escalation_email_levels':
                    return array('warning', 'critical');
                case 'blc_surveillance_escalation_webhook_levels':
                    return array('critical');
                case 'blc_surveillance_escalation_cooldown_warning':
                    return 1800;
                case 'blc_surveillance_escalation_cooldown_critical':
                    return 600;
                default:
                    return $default;
            }
        });

        Functions\when('get_bloginfo')->alias(static function () {
            return 'Site Test';
        });

        Functions\when('number_format_i18n')->alias(static function ($number, $decimals = 0) {
            return number_format((float) $number, (int) $decimals, ',', ' ');
        });

        Functions\when('__')->alias(static function ($text) {
            return $text;
        });

        Functions\when('apply_filters')->alias(static function ($hook, $value) {
            return $value;
        });

        Functions\when('do_action')->justReturn(null);

        Functions\when('blc_get_notification_recipients_list')->justReturn(array('alerts@example.test'));
        Functions\when('blc_get_notification_webhook_settings')->justReturn(array('channel' => 'slack', 'url' => 'https://hooks.example.test'));
        Functions\when('blc_is_webhook_notification_configured')->justReturn(true);

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-surveillance.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-surveillance-escalation.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_handle_surveillance_threshold_alerts_dispatches_notifications(): void
    {
        $alerts = array(
            array(
                'id'         => 'global_ratio',
                'label'      => 'Ratio de liens cassés',
                'metric'     => 'broken_ratio',
                'threshold'  => 5.0,
                'comparison' => 'gte',
                'value'      => 7.5,
                'severity'   => 'warning',
                'scope'      => 'global',
            ),
            array(
                'id'         => 'taxonomy_category_all',
                'label'      => 'Catégorie News',
                'metric'     => 'count',
                'threshold'  => 3,
                'comparison' => 'gte',
                'value'      => 5,
                'severity'   => 'critical',
                'scope'      => 'taxonomy',
                'taxonomy'   => 'category',
                'taxonomy_label' => 'Catégorie',
                'term_name'  => 'News',
            ),
        );

        $metrics = array(
            'global' => array(
                'broken_ratio' => 7.5,
                'broken_count' => 12,
                'total_tracked'=> 160,
            ),
        );

        $dispatchCalls = array();

        Functions\when('blc_dispatch_scan_summary_notifications')->alias(static function ($dataset, $summary, $recipients, $args) use (&$dispatchCalls) {
            $dispatchCalls[] = array(
                'dataset'    => $dataset,
                'summary'    => $summary,
                'recipients' => $recipients,
                'args'       => $args,
            );

            return array();
        });

        blc_handle_surveillance_threshold_alerts($alerts, $metrics, array(), 'link', array());

        $this->assertCount(2, $dispatchCalls, 'Expected two dispatches (warning + critical).');

        $warningCall = $dispatchCalls[0];
        $criticalCall = $dispatchCalls[1];

        $this->assertSame('link', $warningCall['dataset']);
        $this->assertSame(array('alerts@example.test'), $warningCall['recipients']);
        $this->assertSame('surveillance', $warningCall['args']['context']);
        $this->assertSame('warning', $warningCall['args']['surveillance_severity']);
        $this->assertStringContainsString('Surveillance liens cassés', $warningCall['summary']['subject']);

        $this->assertSame('critical', $criticalCall['args']['surveillance_severity']);
        $this->assertSame(array('alerts@example.test'), $criticalCall['recipients']);
        $this->assertIsArray($criticalCall['args']['webhook_settings']);
    }

    public function test_adjust_surveillance_notification_throttle_window_uses_severity_values(): void
    {
        $resultWarning = blc_adjust_surveillance_notification_throttle_window(900, 'email', 'link', 'surveillance', array(
            'surveillance_severity' => 'warning',
        ));

        $resultCritical = blc_adjust_surveillance_notification_throttle_window(900, 'webhook', 'link', 'surveillance', array(
            'surveillance_severity' => 'critical',
        ));

        $this->assertSame(1800, $resultWarning);
        $this->assertSame(600, $resultCritical);
    }
}
