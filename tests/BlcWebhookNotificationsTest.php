<?php

namespace {
    require_once __DIR__ . '/translation-stubs.php';

    if (!function_exists('blc_get_notification_status_filter_choices')) {
        function blc_get_notification_status_filter_choices(): array
        {
            return array(
                'status_404_410' => '404 / 410',
                'status_5xx'     => 'Erreurs 5xx',
            );
        }
    }
}

namespace Tests {

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class BlcWebhookNotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../vendor/autoload.php';

        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();

        parent::tearDown();
    }

    public function test_build_slack_payload_includes_blocks_and_top_issues(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-notification-payloads.php';

        $summary = array(
            'subject'        => 'Résumé analyse liens',
            'site_name'      => 'Site Démo',
            'dataset_label'  => 'Analyse des liens',
            'dataset_type'   => 'link',
            'broken_count'   => 7,
            'report_url'     => 'https://example.test/report',
            'difference'     => -2,
            'previous_count' => 9,
            'status_filters' => array('status_404_410', 'status_5xx'),
            'top_issues'     => array(
                array(
                    'url'              => 'https://example.test/404',
                    'http_status'      => 404,
                    'occurrence_count' => 3,
                    'post_title'       => 'Article 404',
                ),
                array(
                    'url'              => 'https://example.test/timeout',
                    'http_status'      => null,
                    'occurrence_count' => 2,
                    'post_title'       => '',
                ),
            ),
        );

        $payload = blc_build_notification_webhook_payload('slack', 'fallback message', $summary);

        $this->assertArrayHasKey('blocks', $payload);
        $this->assertSame('fallback message', $payload['text']);
        $this->assertGreaterThanOrEqual(3, count($payload['blocks']));

        $header = $payload['blocks'][0];
        $this->assertSame('header', $header['type']);

        $actions = $payload['blocks'][count($payload['blocks']) - 1];
        $this->assertSame('actions', $actions['type']);
        $this->assertSame('button', $actions['elements'][0]['type']);
        $this->assertSame('https://example.test/report', $actions['elements'][0]['url']);

        $issues_section = $payload['blocks'][count($payload['blocks']) - 2];
        $this->assertSame('section', $issues_section['type']);
        $this->assertStringContainsString('https://example.test/404', $issues_section['text']['text']);
        $this->assertStringContainsString('statut : 404', $issues_section['text']['text']);
    }

    public function test_build_teams_payload_adds_facts_and_actions(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-notification-payloads.php';

        $summary = array(
            'subject'        => 'Résumé analyse images',
            'site_name'      => 'Site Démo',
            'dataset_label'  => 'Analyse des images',
            'dataset_type'   => 'image',
            'broken_count'   => 0,
            'report_url'     => 'https://example.test/images-report',
            'difference'     => 0,
            'previous_count' => 0,
            'status_filters' => array('status_404_410'),
            'top_issues'     => array(
                array(
                    'url'              => 'https://example.test/missing.jpg',
                    'http_status'      => 404,
                    'occurrence_count' => 5,
                    'post_title'       => 'Page Média',
                ),
            ),
        );

        $payload = blc_build_notification_webhook_payload('teams', 'fallback message', $summary);

        $this->assertSame('MessageCard', $payload['@type']);
        $this->assertSame('https://schema.org/extensions', $payload['@context']);
        $this->assertSame('Résumé analyse images', $payload['title']);
        $this->assertSame('fallback message', $payload['text']);
        $this->assertNotEmpty($payload['sections']);

        $facts = $payload['sections'][0]['facts'];
        $fact_names = array_column($facts, 'name');
        $this->assertContains(__('Éléments cassés', 'liens-morts-detector-jlg'), $fact_names);
        $this->assertContains(__('Tendance', 'liens-morts-detector-jlg'), $fact_names);

        $actions = $payload['potentialAction'];
        $this->assertNotEmpty($actions);
        $this->assertSame('OpenUri', $actions[0]['@type']);
        $this->assertSame('https://example.test/images-report', $actions[0]['targets'][0]['uri']);

        $this->assertStringContainsString('https://example.test/missing.jpg', $payload['sections'][1]['text']);
        $this->assertStringContainsString('occurrences : 5', $payload['sections'][1]['text']);
    }

    public function test_unknown_channel_falls_back_to_generic_payload(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-notification-payloads.php';

        $summary = array(
            'subject'      => 'Test',
            'dataset_type' => 'link',
            'broken_count' => 4,
            'report_url'   => 'https://example.test/report',
            'site_name'    => 'Démo',
        );

        $payload = blc_build_notification_webhook_payload('unknown', 'fallback', $summary);

        $this->assertArrayHasKey('message', $payload);
        $this->assertSame('fallback', $payload['message']);
        $this->assertSame('Test', $payload['subject']);
        $this->assertSame('https://example.test/report', $payload['report_url']);
    }
}

}
