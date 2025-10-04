<?php

namespace Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

class BlcScanSummaryEmailTest extends TestCase
{
    /** @var object|null */
    private $previous_wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        require_once __DIR__ . '/translation-stubs.php';
        require_once __DIR__ . '/wp-option-stubs.php';

        OptionsStore::reset();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

        $GLOBALS['wpdb'] = new class() {
            /** @var string */
            public $prefix = 'wp_';

            /** @var int */
            public $get_var_result = 0;

            /** @var array<int, array<string, mixed>> */
            public $get_results_result = [];

            /** @var array<int, array{query: string, args: array<int, mixed>}> */
            public $prepare_calls = [];

            /** @var string|null */
            public $last_get_var_query = null;

            /** @var string|null */
            public $last_get_results_query = null;

            /**
             * @param string $query
             * @param mixed  ...$args
             *
             * @return string
             */
            public function prepare($query, ...$args)
            {
                $flat_args = [];
                foreach ($args as $arg) {
                    if (is_array($arg)) {
                        $flat_args = array_merge($flat_args, $arg);
                    } else {
                        $flat_args[] = $arg;
                    }
                }

                $this->prepare_calls[] = [
                    'query' => (string) $query,
                    'args'  => $flat_args,
                ];

                return (string) $query;
            }

            /**
             * @param string $query
             *
             * @return int
             */
            public function get_var($query)
            {
                $this->last_get_var_query = (string) $query;

                return (int) $this->get_var_result;
            }

            /**
             * @param string $query
             * @param string $output
             *
             * @return array<int, array<string, mixed>>
             */
            public function get_results($query, $output = ARRAY_A)
            {
                $this->last_get_results_query = (string) $query;

                return $this->get_results_result;
            }
        };

        Functions\when('get_bloginfo')->justReturn('Mon Site');
        Functions\when('home_url')->justReturn('https://monsite.test');
        Functions\when('wp_parse_url')->alias('parse_url');
        Functions\when('admin_url')->alias(static function ($path = '') {
            return 'https://monsite.test/wp-admin/' . ltrim((string) $path, '/');
        });
        Functions\when('apply_filters')->alias(static function ($hook, $value, ...$args) {
            return $value;
        });
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previous_wpdb;
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_summary_includes_top_issues_and_trend(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-settings-fields.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';

        $default_filters = blc_get_default_notification_status_filters();
        $default_signature = implode('|', $default_filters);
        OptionsStore::$options['blc_last_scan_summary_counts'] = [
            'link' => [
                'count'   => 2,
                'filters' => $default_signature,
            ],
        ];

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->get_var_result = 5;
        $wpdb->get_results_result = [
            [
                'url'              => 'https://example.com/404',
                'http_status'      => 404,
                'post_title'       => 'Article 1',
                'occurrence_count' => 3,
            ],
            [
                'url'              => 'https://example.com/timeout',
                'http_status'      => null,
                'post_title'       => '',
                'occurrence_count' => 2,
            ],
        ];

        $summary = blc_generate_scan_summary_email('link');

        $this->assertIsArray($summary);
        $this->assertSame(5, $summary['broken_count']);
        $this->assertSame(3, $summary['difference']);
        $this->assertSame(2, $summary['previous_count']);
        $this->assertCount(2, $summary['top_issues']);
        $this->assertSame($default_filters, $summary['status_filters']);

        $message = $summary['message'];
        $this->assertStringContainsString('- Liens cassés détectés : 5', $message);
        $this->assertStringContainsString('- Évolution depuis le précédent scan : +3 (précédent : 2)', $message);
        $this->assertStringContainsString('Liens les plus problématiques :', $message);
        $this->assertStringContainsString('- https://example.com/404 — statut HTTP : 404 — occurrences : 3 — contenu : Article 1', $message);
        $this->assertStringContainsString('- https://example.com/timeout — statut HTTP : inconnu — occurrences : 2', $message);

        $this->assertSame(
            5,
            OptionsStore::$options['blc_last_scan_summary_counts']['link']['count']
        );
        $this->assertSame(
            $default_signature,
            OptionsStore::$options['blc_last_scan_summary_counts']['link']['filters']
        );
    }

    public function test_summary_handles_first_measure_without_top_issues(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-settings-fields.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->get_var_result = 1;
        $wpdb->get_results_result = [];

        $summary = blc_generate_scan_summary_email('image');

        $this->assertSame('image', $summary['dataset_type']);
        $this->assertNull($summary['previous_count']);
        $this->assertNull($summary['difference']);
        $this->assertSame([], $summary['top_issues']);

        $message = $summary['message'];
        $this->assertStringContainsString('- Images cassées détectées : 1', $message);
        $this->assertStringContainsString('- Première mesure disponible : comparaison à venir lors du prochain scan.', $message);
        $this->assertStringNotContainsString('Images les plus problématiques :', $message);

        $this->assertSame(
            1,
            OptionsStore::$options['blc_last_scan_summary_counts']['image']['count']
        );
    }

    public function test_summary_ignores_previous_trend_when_filters_change(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-settings-fields.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->get_var_result = 4;
        $wpdb->get_results_result = [];

        OptionsStore::$options['blc_notification_status_filters'] = ['status_404_410'];
        OptionsStore::$options['blc_last_scan_summary_counts'] = [
            'link' => [
                'count'   => 7,
                'filters' => 'status_404_410|status_5xx',
            ],
        ];

        $summary = blc_generate_scan_summary_email('link');

        $this->assertNull($summary['previous_count']);
        $this->assertNull($summary['difference']);
        $this->assertSame(
            4,
            OptionsStore::$options['blc_last_scan_summary_counts']['link']['count']
        );
        $this->assertSame(
            'status_404_410',
            OptionsStore::$options['blc_last_scan_summary_counts']['link']['filters']
        );
    }

    public function test_summary_accepts_status_filter_override(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-settings-fields.php';
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-scanner.php';

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->get_var_result = 2;
        $wpdb->get_results_result = [];

        $summary = blc_generate_scan_summary_email('link', ['status_filters' => ['status_5xx']]);

        $this->assertNotNull($summary);
        $this->assertSame(['status_5xx'], $summary['status_filters']);
        $this->assertStringContainsString('http_status BETWEEN 500 AND 599', (string) $wpdb->last_get_var_query);
        $this->assertStringNotContainsString('http_status IN (404, 410)', (string) $wpdb->last_get_var_query);
    }
}
