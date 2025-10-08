<?php

namespace Tests {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\OptionsStore;

class BlcTopDomainsCacheTest extends TestCase
{

    /**
     * @var array<string, array{value:mixed,ttl:int}>
     */
    private array $transients = [];

    /**
     * @var array<int, array{0:string,1:array<int,mixed>}> 
     */
    private array $actions = [];

    /**
     * @var object|null
     */
    private $previousWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../vendor/autoload.php';
        Monkey\setUp();

        require_once __DIR__ . '/wp-option-stubs.php';
        OptionsStore::reset();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/../');
        }

        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }

        if (!function_exists('blc_normalize_hour_option')) {
            function blc_normalize_hour_option($value)
            {
                return $value;
            }
        }

        if (!function_exists('blc_get_dataset_row_types')) {
            function blc_get_dataset_row_types($dataset_type)
            {
                return array('link');
            }
        }

        Functions\when('apply_filters')->alias(function ($hook, $value) {
            return $value;
        });

        $transients =& $this->transients;
        Functions\when('set_transient')->alias(static function ($key, $value, $expiration) use (&$transients) {
            $transients[$key] = array(
                'value' => $value,
                'ttl'   => (int) $expiration,
            );

            return true;
        });

        Functions\when('get_transient')->alias(static function ($key) use (&$transients) {
            if (!isset($transients[$key])) {
                return false;
            }

            return $transients[$key]['value'];
        });

        Functions\when('delete_transient')->alias(static function ($key) use (&$transients) {
            unset($transients[$key]);

            return true;
        });

        $actions =& $this->actions;
        Functions\when('do_action')->alias(static function ($hook, ...$args) use (&$actions) {
            $actions[] = array($hook, $args);
        });

        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class() {
            /** @var string */
            public $prefix = 'wp_';

            /** @var array<int, array<string, mixed>> */
            public $mock_results = array();

            /** @var array<int, array<int, array<string, mixed>>> */
            public $mock_results_queue = array();

            /** @var int */
            public $get_results_calls = 0;

            /** @var array<int, array{query:string,params:array<int,mixed>}> */
            public $prepared_calls = array();

            public function prepare($query, ...$args)
            {
                if (!empty($args)) {
                    if (is_array($args[0])) {
                        $params = $args[0];
                    } else {
                        $params = $args;
                    }
                } else {
                    $params = array();
                }

                $this->prepared_calls[] = array(
                    'query'  => $query,
                    'params' => $params,
                );

                return $query;
            }

            public function get_results($query, $output = ARRAY_A)
            {
                $this->get_results_calls++;

                if (!empty($this->mock_results_queue)) {
                    return array_shift($this->mock_results_queue);
                }

                return $this->mock_results;
            }
        };

        require_once __DIR__ . '/../liens-morts-detector-jlg/includes/blc-admin-pages.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        OptionsStore::reset();
        $this->transients  = [];
        $this->actions     = [];

        if ($this->previousWpdb !== null) {
            $GLOBALS['wpdb'] = $this->previousWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }

        parent::tearDown();
    }

    public function test_results_are_cached_and_reused(): void
    {
        $firstBatch = array(
            array(
                'url_host'             => 'example.com',
                'total_count'          => 4,
                'client_error_count'   => 1,
                'server_error_count'   => 1,
                'redirect_count'       => 1,
                'other_count'          => 1,
            ),
        );

        $GLOBALS['wpdb']->mock_results_queue = array($firstBatch);

        $domains = blc_get_top_broken_link_domains(5);

        $this->assertSame(1, $GLOBALS['wpdb']->get_results_calls);
        $this->assertSame(
            array(
                array(
                    'host'          => 'example.com',
                    'count'         => 4,
                    'client_errors' => 1,
                    'server_errors' => 1,
                    'redirects'     => 1,
                    'other'         => 1,
                ),
            ),
            $domains
        );

        $GLOBALS['wpdb']->mock_results_queue = array(array(
            array(
                'url_host' => 'second.test',
                'total_count' => 9,
            ),
        ));

        $cachedDomains = blc_get_top_broken_link_domains(5);

        $this->assertSame(1, $GLOBALS['wpdb']->get_results_calls, 'Cache miss triggered an unnecessary query.');
        $this->assertSame($domains, $cachedDomains, 'Cached payload differs from original result.');
    }

    public function test_invalidation_bumps_version_and_queries_again(): void
    {
        $firstBatch = array(
            array(
                'url_host'       => 'foo.test',
                'total_count'    => 3,
                'client_error_count' => 1,
                'server_error_count' => 1,
                'redirect_count' => 0,
                'other_count'    => 1,
            ),
        );

        $secondBatch = array(
            array(
                'url_host'       => 'bar.test',
                'total_count'    => 7,
                'client_error_count' => 2,
                'server_error_count' => 3,
                'redirect_count' => 1,
                'other_count'    => 1,
            ),
        );

        $GLOBALS['wpdb']->mock_results_queue = array($firstBatch, $secondBatch);

        $initial = blc_get_top_broken_link_domains(5);
        $this->assertSame(1, $GLOBALS['wpdb']->get_results_calls);
        $this->assertSame('foo.test', $initial[0]['host']);

        $this->assertSame(2, blc_invalidate_top_domain_stats_cache());
        $this->assertNotEmpty($this->actions);
        $lastAction = end($this->actions);
        $this->assertSame('blc_top_domain_cache_version_changed', $lastAction[0]);

        $refreshed = blc_get_top_broken_link_domains(5);

        $this->assertSame(2, $GLOBALS['wpdb']->get_results_calls);
        $this->assertSame('bar.test', $refreshed[0]['host']);
    }

    public function test_empty_results_are_cached(): void
    {
        $GLOBALS['wpdb']->mock_results_queue = array(array());

        $initial = blc_get_top_broken_link_domains(2);

        $this->assertSame(array(), $initial);
        $this->assertSame(1, $GLOBALS['wpdb']->get_results_calls);

        $cached = blc_get_top_broken_link_domains(2);

        $this->assertSame(1, $GLOBALS['wpdb']->get_results_calls, 'Empty datasets should still be cached.');
        $this->assertSame($initial, $cached);
    }
}

}
