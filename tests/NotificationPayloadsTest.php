<?php

namespace {
    if (!function_exists('blc_normalize_hour_option')) {
        function blc_normalize_hour_option($value)
        {
            return $value;
        }
    }

    if (!function_exists('blc_reset_link_check_schedule')) {
        function blc_reset_link_check_schedule(array $args = array()): array
        {
            $defaults = array(
                'frequency'           => null,
                'custom_hours'        => null,
                'custom_time'         => null,
                'context'             => 'settings',
                'reference_timestamp' => null,
            );

            $args = array_merge($defaults, $args);

            $frequency = $args['frequency'] ?? null;
            if ($frequency === null && function_exists('get_option')) {
                $frequency = get_option('blc_frequency', 'daily');
            }

            if ($frequency === null || $frequency === '') {
                $frequency = 'daily';
            }

            $schedule_slug = function_exists('blc_resolve_cron_schedule_slug')
                ? blc_resolve_cron_schedule_slug($frequency)
                : (string) $frequency;

            if ($schedule_slug === '') {
                $schedule_slug = 'daily';
            }

            $timestamp = time();
            if ($schedule_slug === 'blc_custom_interval' && isset($args['reference_timestamp']) && is_int($args['reference_timestamp'])) {
                $timestamp = $args['reference_timestamp'];
            }

            $result = array(
                'success'            => false,
                'schedule'           => $schedule_slug,
                'timestamp'          => $timestamp,
                'restore_attempted'  => false,
                'restored'           => false,
                'previous_timestamp' => null,
                'previous_schedule'  => null,
                'error_code'         => '',
                'error_message'      => '',
            );

            if (function_exists('wp_get_schedules')) {
                $schedules = wp_get_schedules();
                if (is_array($schedules) && !isset($schedules[$schedule_slug])) {
                    $result['error_code']    = 'missing_schedule';
                    $result['error_message'] = sprintf('BLC: Schedule "%s" is not registered.', $schedule_slug);

                    return $result;
                }
            }

            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook('blc_check_links');
            }

            $scheduled = true;
            if (function_exists('wp_schedule_event')) {
                $scheduled = wp_schedule_event($timestamp, $schedule_slug, 'blc_check_links');
            }

            if ($scheduled === false) {
                $result['error_code']    = 'schedule_failed';
                $result['error_message'] = sprintf(
                    'BLC: Failed to schedule automatic link check (frequency: %s, context: %s).',
                    $schedule_slug,
                    isset($args['context']) ? (string) $args['context'] : 'unknown'
                );

                if (function_exists('error_log')) {
                    error_log($result['error_message']);
                }

                if (function_exists('do_action')) {
                    do_action('blc_check_links_schedule_failed', $schedule_slug, $args['context']);
                }

                return $result;
            }

            $result['success'] = true;

            if (function_exists('do_action')) {
                do_action(
                    'blc_check_links_schedule_updated',
                    $schedule_slug,
                    $timestamp,
                    array(
                        'frequency'    => $frequency,
                        'custom_hours' => $args['custom_hours'],
                        'custom_time'  => $args['custom_time'],
                        'context'      => $args['context'],
                    )
                );
            }

            return $result;
        }
    }

    if (!function_exists('blc_get_notification_status_filter_definitions')) {
        function blc_get_notification_status_filter_definitions(): array
        {
            return array(
                'status_404_410' => array(
                    'label' => '404 / 410',
                    'sql'   => static function ($column) {
                        return sprintf('%s IN (404, 410)', $column);
                    },
                ),
                'status_5xx' => array(
                    'label' => 'Erreurs 5xx',
                    'sql'   => static function ($column) {
                        return sprintf('(%s BETWEEN 500 AND 599)', $column);
                    },
                ),
                'status_redirects' => array(
                    'label' => 'Redirections (3xx)',
                    'sql'   => static function ($column) {
                        return sprintf('(%s BETWEEN 300 AND 399)', $column);
                    },
                ),
                'status_other' => array(
                    'label' => 'Autres statuts',
                    'sql'   => static function ($column) {
                        return sprintf('(%s IS NULL OR %s = 0)', $column, $column);
                    },
                ),
            );
        }
    }

    if (!function_exists('blc_get_default_cron_schedules')) {
        function blc_get_default_cron_schedules(): array
        {
            return array();
        }
    }

    if (!function_exists('blc_render_notification_message_template')) {
        function blc_render_notification_message_template($template, array $summary): string
        {
            $template = (string) $template;
            if ($template === '') {
                return '';
            }

            $replacements = array(
                '{{subject}}'       => isset($summary['subject']) ? (string) $summary['subject'] : '',
                '{{message}}'       => isset($summary['message']) ? (string) $summary['message'] : '',
                '{{dataset_type}}'  => isset($summary['dataset_type']) ? (string) $summary['dataset_type'] : '',
                '{{dataset_label}}' => isset($summary['dataset_label']) ? (string) $summary['dataset_label'] : '',
                '{{broken_count}}'  => isset($summary['broken_count']) ? (string) (int) $summary['broken_count'] : '0',
                '{{report_url}}'    => isset($summary['report_url']) ? (string) $summary['report_url'] : '',
                '{{site_name}}'     => isset($summary['site_name']) ? (string) $summary['site_name'] : '',
            );

            return trim(strtr($template, $replacements));
        }
    }
}

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class NotificationPayloadsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/translation-stubs.php';

        Monkey\setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('register_setting')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(static function ($value) {
            if (!is_scalar($value)) {
                return '';
            }

            return trim((string) $value);
        });
        Functions\when('esc_url_raw')->alias(static function ($url) {
            if (!is_scalar($url)) {
                return '';
            }

            $url = trim((string) $url);
            if ($url === '') {
                return '';
            }

            return filter_var($url, FILTER_SANITIZE_URL);
        });
        Functions\when('wp_unslash')->alias(static fn($value) => $value);
        Functions\when('apply_filters')->alias(static fn($hook, $value) => $value);

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-settings-fields.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-notification-payloads.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_slack_payload_honours_customizations(): void
    {
        $summary = array(
            'subject'        => 'Alerte Liens',
            'message'        => '7 liens à corriger',
            'site_name'      => 'Exemple.fr',
            'dataset_label'  => 'Audit manuel',
            'dataset_type'   => 'link',
            'broken_count'   => 7,
            'difference'     => 3,
            'previous_count' => 4,
            'status_filters' => array('4xx'),
            'top_issues'     => array(
                array(
                    'url'              => 'https://example.fr/404',
                    'http_status'      => 404,
                    'occurrence_count' => 3,
                    'post_title'       => 'Page test',
                ),
            ),
            'report_url'     => 'https://example.fr/wp-admin/report',
        );

        $settings = array(
            'slack_channel_override' => '#incidents ',
            'slack_username'         => '  Robot QA ',
            'slack_icon'             => ':rotating_light:',
            'slack_title_template'   => '{{site_name}} • {{broken_count}} erreurs',
            'slack_show_filters'     => false,
            'slack_show_top_issues'  => true,
        );

        $payload = \blc_build_slack_notification_payload('Résumé du scan', $summary, $settings);

        $this->assertSame('#incidents', $payload['channel']);
        $this->assertSame('Robot QA', $payload['username']);
        $this->assertSame(':rotating_light:', $payload['icon_emoji']);
        $this->assertArrayNotHasKey('icon_url', $payload);

        $this->assertNotEmpty($payload['blocks']);
        $headerBlock = $payload['blocks'][0];
        $this->assertSame('plain_text', $headerBlock['text']['type']);
        $this->assertSame('Exemple.fr • 7 erreurs', $headerBlock['text']['text']);

        $blockTypes = array_map(static fn($block) => $block['type'], $payload['blocks']);
        $this->assertNotContains('context', $blockTypes, 'Filters context should be hidden when disabled.');

        $issuesBlockFound = false;
        foreach ($payload['blocks'] as $block) {
            if (isset($block['type'], $block['text']['text'])
                && $block['type'] === 'section'
                && strpos($block['text']['text'], 'Problèmes principaux') !== false
            ) {
                $issuesBlockFound = true;
                break;
            }
        }

        $this->assertTrue($issuesBlockFound, 'Top issues section should remain visible.');
    }

    public function test_slack_payload_supports_icon_url_and_hides_issues(): void
    {
        $summary = array(
            'subject'        => '',
            'message'        => 'Scan terminé',
            'site_name'      => 'Site B',
            'dataset_label'  => 'Images distantes',
            'dataset_type'   => 'image',
            'broken_count'   => 0,
            'difference'     => 0,
            'previous_count' => 0,
            'status_filters' => array('5xx'),
            'top_issues'     => array(
                array(
                    'url'              => 'https://site-b.example/cdn.png',
                    'http_status'      => 500,
                    'occurrence_count' => 1,
                    'post_title'       => 'Image héros',
                ),
            ),
        );

        $settings = array(
            'slack_channel_override' => 'c1234abc',
            'slack_username'         => 'Observabilité',
            'slack_icon'             => 'https://cdn.example.com/icon.png',
            'slack_title_template'   => '{{dataset_label}}',
            'slack_show_filters'     => true,
            'slack_show_top_issues'  => false,
        );

        $payload = \blc_build_slack_notification_payload('Analyse des images', $summary, $settings);

        $this->assertSame('C1234ABC', $payload['channel']);
        $this->assertSame('Observabilité', $payload['username']);
        $this->assertSame('https://cdn.example.com/icon.png', $payload['icon_url']);
        $this->assertArrayNotHasKey('icon_emoji', $payload);

        $headerBlock = $payload['blocks'][0];
        $this->assertSame('Images distantes', $headerBlock['text']['text']);

        $blockTypes = array_map(static fn($block) => $block['type'], $payload['blocks']);
        $this->assertContains('context', $blockTypes, 'Filters context should appear when enabled.');

        foreach ($payload['blocks'] as $block) {
            if (isset($block['text']['text']) && strpos($block['text']['text'], 'Problèmes principaux') !== false) {
                $this->fail('Top issues section should be hidden when disabled.');
            }
        }
    }
}

}
