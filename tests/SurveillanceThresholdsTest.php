<?php

namespace Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

class SurveillanceThresholdsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';

        Monkey\setUp();

        require_once __DIR__ . '/translation-stubs.php';
        require_once __DIR__ . '/wp-option-stubs.php';

        OptionsStore::reset();

        Functions\when('apply_filters')->alias(static function ($hook, $value) {
            return $value;
        });
        Functions\when('do_action')->justReturn(null);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_defaults_include_ratio_threshold(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-surveillance.php';

        $definitions = blc_get_surveillance_threshold_definitions();

        $this->assertArrayHasKey('global', $definitions);
        $ratio_thresholds = array_filter($definitions['global'], static function ($definition) {
            return isset($definition['metric']) && $definition['metric'] === 'broken_ratio';
        });

        $this->assertNotEmpty($ratio_thresholds, 'Expected default ratio threshold to be present.');
    }

    public function test_evaluate_thresholds_triggers_alerts_with_custom_metrics(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-surveillance.php';

        OptionsStore::$options['blc_surveillance_thresholds'] = array(
            'global' => array(
                array(
                    'id'         => 'ratio_warning',
                    'metric'     => 'broken_ratio',
                    'threshold'  => 10.0,
                    'comparison' => 'gte',
                    'label'      => 'Ratio > 10%',
                ),
                array(
                    'id'         => 'absolute_volume',
                    'metric'     => 'broken_count',
                    'threshold'  => 40,
                    'comparison' => 'gte',
                    'label'      => 'Plus de 40 liens',
                    'severity'   => 'critical',
                ),
            ),
            'taxonomy' => array(
                array(
                    'taxonomy'  => 'category',
                    'term_ids'  => array(7),
                    'threshold' => 5,
                    'comparison'=> 'gte',
                    'label'     => 'Catégorie Actualités',
                ),
            ),
        );

        $evaluation = blc_evaluate_surveillance_thresholds('link', array(
            'metrics' => array(
                'global' => array(
                    'broken_ratio' => 12.5,
                    'broken_count' => 48,
                    'total_tracked'=> 380,
                ),
                'taxonomy' => array(
                    'category' => array(
                        7 => array(
                            'term_id' => 7,
                            'name'    => 'Actualités',
                            'slug'    => 'actualites',
                            'count'   => 6,
                        ),
                        8 => array(
                            'term_id' => 8,
                            'name'    => 'Blog',
                            'slug'    => 'blog',
                            'count'   => 2,
                        ),
                    ),
                ),
            ),
        ));

        $alerts = isset($evaluation['alerts']) ? $evaluation['alerts'] : array();

        $alert_ids = array();
        foreach ($alerts as $alert) {
            if (isset($alert['id'])) {
                $alert_ids[] = (string) $alert['id'];
            }
        }

        $this->assertContains('ratio_warning', $alert_ids);
        $this->assertContains('absolute_volume', $alert_ids);

        $taxonomy_alerts = array_filter($alerts, static function ($alert) {
            return is_array($alert) && isset($alert['scope']) && $alert['scope'] === 'taxonomy';
        });

        $this->assertNotEmpty($taxonomy_alerts);

        $taxonomy_alert = array_values($taxonomy_alerts)[0];
        $this->assertSame('Catégorie Actualités', $taxonomy_alert['label']);
        $this->assertSame(6, $taxonomy_alert['value']);
    }

    public function test_taxonomy_threshold_applies_to_all_terms_when_requested(): void
    {
        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-surveillance.php';

        OptionsStore::$options['blc_surveillance_thresholds'] = array(
            'taxonomy' => array(
                array(
                    'taxonomy'  => 'post_tag',
                    'threshold' => 3,
                    'comparison'=> 'gte',
                ),
            ),
        );

        $evaluation = blc_evaluate_surveillance_thresholds('link', array(
            'metrics' => array(
                'global' => array(
                    'broken_ratio' => 1.0,
                    'broken_count' => 2,
                    'total_tracked'=> 200,
                ),
                'taxonomy' => array(
                    'post_tag' => array(
                        3 => array(
                            'term_id' => 3,
                            'name'    => 'Prioritaire',
                            'slug'    => 'prioritaire',
                            'count'   => 4,
                        ),
                        5 => array(
                            'term_id' => 5,
                            'name'    => 'Divers',
                            'slug'    => 'divers',
                            'count'   => 2,
                        ),
                    ),
                ),
            ),
        ));

        $alerts = isset($evaluation['alerts']) ? $evaluation['alerts'] : array();

        $this->assertCount(1, $alerts);
        $this->assertSame(3, $alerts[0]['term_id']);
        $this->assertSame('post_tag', $alerts[0]['taxonomy']);
    }
}
