<?php

namespace JLG\BrokenLinks\Scanner;

use DOMDocument;
use DOMXPath;
use JLG\BrokenLinks\Scanner\QueueDrivers\WpCronQueueDriver;
use Requests_Exception;
use Requests_Response;
use WP_Error;
use WP_Query;

interface QueueDriverInterface
{
    public function getSlug(): string;

    public function getLabel(): string;

    public function scheduleBatch(array $job, int $delaySeconds = 0): bool;

    public function receiveBatch(): ?array;

    public function acknowledge(array $job): void;

    public function reportFailure(array $job, \Throwable $error): void;

    public function isConnected(): bool;

    public function supportsAsyncPull(): bool;
}

class ScanQueue {
        /** @var HttpClientInterface */
        private $remoteRequestClient;

    /** @var ScanPreflight */
    private $preflight;

    /** @var ScanLockManager */
    private $lockManager;

    /** @var QueueDriverInterface */
    private $queueDriver;

    /** @var array<string, mixed> */
    private $jobContext = [];

    public function __construct(
        HttpClientInterface $remoteRequestClient,
        ?ScanPreflight $preflight = null,
        ?ScanLockManager $lockManager = null,
        ?QueueDriverInterface $queueDriver = null
    ) {
        $this->remoteRequestClient = $remoteRequestClient;
        $this->preflight = $preflight ?: new ScanPreflight();
        $this->queueDriver = $queueDriver ?: new WpCronQueueDriver();
        $this->lockManager = $lockManager ?: new ScanLockManager($this->queueDriver);
    }

    public function getQueueDriver(): QueueDriverInterface
    {
        return $this->queueDriver;
    }

    public function getLockManager(): ScanLockManager
    {
        return $this->lockManager;
    }

    public function runBatch($batch = 0, $is_full_scan = false, $bypass_rest_window = false, array $jobContext = []) {
        $this->jobContext = $this->normalizeJobContext($jobContext);
        $remote_request_client = $this->remoteRequestClient;
        global $wpdb;

        $table_name = '';
        $link_dataset_row_types = [];
        $excluded_domains = [];
        $scan_cache_context = [
            'type'      => 'link',
            'option'    => 'blc_active_link_scan_key',
            'key'       => '',
            'transient' => '',
            'data'      => [],
        ];
        $scan_cache_data = [];
        $scan_cache_identifier = '';
        $scan_cache_dirty = false;
        /** @var WP_Query|null $wp_query */
        $wp_query = null;
        $posts = [];
        $scan_run_token = '';
        $global_link_sources = [];

        $preflight = $this->preflight->collect($batch, $is_full_scan, $bypass_rest_window);
        $batch = $preflight['batch'];
        $is_full_scan = $preflight['is_full_scan'];
        $bypass_rest_window = $preflight['bypass_rest_window'];
        $debug_mode = $preflight['debug_mode'];
        $current_hook = $preflight['current_hook'];
        $link_delay_ms = $preflight['link_delay_ms'];
        $batch_delay_s = $preflight['batch_delay_s'];
        $lock_timeout = $preflight['lock_timeout'];

        $total_batches = 0;
        $processed_batches = 0;
        $remaining_batches = 0;
        $total_items = 0;
        $processed_items = 0;

        if ($debug_mode) { error_log("--- Début du scan LIENS (Lot #$batch) ---"); }

        $job_id = $this->lockManager->ensureActiveJobId();

        $lock_outcome = $this->lockManager->acquireOrReschedule(
            $current_hook,
            $batch,
            $is_full_scan,
            $bypass_rest_window,
            $batch_delay_s,
            $lock_timeout,
            $debug_mode,
            $this->jobContext
        );

        if ($lock_outcome['status'] === 'rescheduled') {
            return;
        }

        if ($lock_outcome['status'] === 'error') {
            return $lock_outcome['error'];
        }

        $lock_token = $lock_outcome['lock_token'];

        $rest_window = $this->lockManager->deferDuringRestWindow($preflight, $lock_token, $debug_mode, $this->jobContext);
        $lock_token  = $rest_window['lock_token'];
        if ($rest_window['deferred']) {
            return;
        }

        $server_load = $this->lockManager->deferForServerLoad($preflight, $lock_token, $debug_mode, $this->jobContext);
        $lock_token  = $server_load['lock_token'];
        if ($server_load['deferred']) {
            return;
        }

        \blc_update_link_scan_status([
            'state'         => 'running',
            'current_batch' => (int) $batch,
            'is_full_scan'  => (bool) $is_full_scan,
            'message'       => \__('Analyse des liens en cours…', 'liens-morts-detector-jlg'),
        ]);

            $timeout_constraints = blc_get_request_timeout_constraints();
            $head_timeout_limits = $timeout_constraints['head'];
            $get_timeout_limits  = $timeout_constraints['get'];

            $head_request_timeout = blc_normalize_timeout_option(
                get_option('blc_head_request_timeout', $head_timeout_limits['default']),
                $head_timeout_limits['default'],
                $head_timeout_limits['min'],
                $head_timeout_limits['max']
            );
            $get_request_timeout = blc_normalize_timeout_option(
                get_option('blc_get_request_timeout', $get_timeout_limits['default']),
                $get_timeout_limits['default'],
                $get_timeout_limits['min'],
                $get_timeout_limits['max']
            );
            $scan_method     = get_option('blc_scan_method', 'precise');
            $soft_404_min_length_option = get_option('blc_soft_404_min_length', 120);
            $soft_404_error_patterns_option = get_option('blc_soft_404_error_patterns', '');
            $soft_404_ignore_selectors_option = get_option('blc_soft_404_ignore_selectors', '');
            $excluded_domains_raw = get_option('blc_excluded_domains', '');
            $max_concurrent_requests_option = get_option('blc_concurrent_requests', 3);
            if (!is_int($max_concurrent_requests_option)) {
                $max_concurrent_requests_option = (int) $max_concurrent_requests_option;
            }
            if ($max_concurrent_requests_option < 1) {
                $max_concurrent_requests_option = 1;
            }

            $max_concurrent_requests = apply_filters(
                'blc_concurrent_requests',
                $max_concurrent_requests_option,
                $batch,
                $is_full_scan
            );
            if (!is_int($max_concurrent_requests)) {
                $max_concurrent_requests = (int) $max_concurrent_requests;
            }
            if ($max_concurrent_requests < 1) {
                $max_concurrent_requests = 1;
            }

            $soft_404_config = blc_get_soft_404_heuristics();
            $soft_404_heuristics = new Soft404Heuristics($soft_404_config);
            $soft_404_min_length = $soft_404_heuristics->getMinLength();
            $soft_404_title_indicators = $soft_404_heuristics->getTitleIndicators();
            $soft_404_body_indicators = $soft_404_heuristics->getBodyIndicators();
            $soft_404_ignore_patterns = $soft_404_heuristics->getIgnorePatterns();

            $queue_concurrency_option = get_option('blc_queue_concurrency', 1);
            if (!is_int($queue_concurrency_option)) {
                $queue_concurrency_option = (int) $queue_concurrency_option;
            }
            if ($queue_concurrency_option < 1) {
                $queue_concurrency_option = 1;
            }

            $soft_404_extract_title = [$soft_404_heuristics, 'extractTitle'];
            $soft_404_strip_text = [$soft_404_heuristics, 'stripText'];
            $soft_404_matches_any = [$soft_404_heuristics, 'matchesAny'];

            $soft_404_context = [
                'min_length'       => $soft_404_min_length,
                'title_indicators' => $soft_404_title_indicators,
                'body_indicators'  => $soft_404_body_indicators,
                'ignore_patterns'  => $soft_404_ignore_patterns,
            ];

            $this->jobContext = array_merge(
                $this->jobContext,
                [
                    'scan_method'             => $scan_method,
                    'max_concurrent_requests' => $max_concurrent_requests,
                    'soft_404'                => $soft_404_context,
                    'queue_concurrency'       => $queue_concurrency_option,
                ]
            );

            $remote_request_queue = ParallelRequestDispatcher::fromFilters(
                $remote_request_client,
                $max_concurrent_requests,
                $link_delay_ms
            );

            $raw_home_url = '';
            if (function_exists('home_url')) {
                $maybe_home_url = home_url();
                if (is_string($maybe_home_url)) {
                    $raw_home_url = $maybe_home_url;
                }
            }

            $raw_site_url = '';
            if (function_exists('site_url')) {
                $maybe_site_url = site_url();
                if (is_string($maybe_site_url)) {
                    $raw_site_url = $maybe_site_url;
                }
            }

            $safe_internal_hosts = [];
            $register_internal_host = static function ($url) use (&$safe_internal_hosts) {
                if (!is_string($url) || $url === '') {
                    return;
                }

                $host = null;

                if (function_exists('wp_parse_url')) {
                    $host = wp_parse_url($url, PHP_URL_HOST);
                }

                if (!is_string($host) || $host === '') {
                    $host = parse_url($url, PHP_URL_HOST);
                }

                if (!is_string($host) || $host === '') {
                    return;
                }

                $normalized_host = blc_normalize_remote_host($host);
                if ($normalized_host === '') {
                    return;
                }

                $safe_internal_hosts[$normalized_host] = true;
            };

            $register_internal_host($raw_home_url);
            $register_internal_host($raw_site_url);

            $table_name = $wpdb->prefix . 'blc_broken_links';
            $link_dataset_row_types = blc_get_dataset_row_types('link');
            if (!is_array($link_dataset_row_types)) {
                $link_dataset_row_types = [];
            }
            $excluded_domains = $this->normalizeExcludedDomains($excluded_domains_raw);

            $maybe_scan_cache_context = blc_get_scan_cache_context('link', (int) $batch);
            if (is_array($maybe_scan_cache_context)) {
                $scan_cache_context = array_merge($scan_cache_context, $maybe_scan_cache_context);
            }
            $scan_cache_data = isset($scan_cache_context['data']) && is_array($scan_cache_context['data'])
                ? $scan_cache_context['data']
                : [];
            $scan_cache_identifier = isset($scan_cache_context['key']) ? (string) $scan_cache_context['key'] : '';
            $scan_cache_dirty = false;

            $batch_size_option = get_option('blc_link_batch_size', blc_get_link_batch_size_constraints()['default']);
            $batch_size = blc_normalize_link_batch_size($batch_size_option);
            $batch_size = apply_filters('blc_link_batch_size', $batch_size, $batch, $is_full_scan);
            $batch_size = blc_normalize_link_batch_size($batch_size);

            $query_args = [
                'post_type'      => blc_get_scannable_post_types(),
                'post_status'    => blc_get_scannable_post_statuses(),
                'posts_per_page' => $batch_size,
                'paged'          => max(1, (int) $batch + 1),
            ];
            $query_args = apply_filters('blc_link_scan_query_args', $query_args, $batch, $batch_size, $is_full_scan);
            if (!is_array($query_args)) {
                $query_args = [];
            }
            if (!isset($query_args['posts_per_page'])) {
                $query_args['posts_per_page'] = $batch_size;
            }
            if (!isset($query_args['paged'])) {
                $query_args['paged'] = max(1, (int) $batch + 1);
            }

            $wp_query = new WP_Query($query_args);
            $posts = is_array($wp_query->posts) ? $wp_query->posts : [];

            $total_items = isset($wp_query->found_posts) ? max(0, (int) $wp_query->found_posts) : count($posts);
            $max_pages = isset($wp_query->max_num_pages) ? (int) $wp_query->max_num_pages : 0;
            if ($max_pages <= 0) {
                if ($total_items > 0 && $batch_size > 0) {
                    $max_pages = (int) ceil($total_items / $batch_size);
                } elseif (!empty($posts) && $batch === 0) {
                    $max_pages = 1;
                } else {
                    $max_pages = max(1, (int) $batch + 1);
                }
            }

            $total_batches = max(1, $max_pages);
            $processed_batches = min($total_batches, (int) $batch + 1);
            $remaining_batches = max(0, $total_batches - $processed_batches);
            $posts_in_batch = is_array($posts) ? count($posts) : 0;
            $processed_items = max(0, ($batch * $batch_size) + $posts_in_batch);
            if ($total_items > 0) {
                $processed_items = min($processed_items, $total_items);
            }

            $status_message = sprintf(
                /* translators: 1: completed batch number, 2: total batch count. */
                __('Analyse du lot %1$d sur %2$d en cours…', 'liens-morts-detector-jlg'),
                $processed_batches,
                $total_batches
            );

            \blc_update_link_scan_status([
                'state'             => 'running',
                'current_batch'     => (int) $batch,
                'processed_batches' => $processed_batches,
                'total_batches'     => $total_batches,
                'remaining_batches' => $remaining_batches,
                'total_items'       => $total_items,
                'processed_items'   => $processed_items,
                'message'           => $status_message,
            ]);

            $post_ids_in_batch = [];
            foreach ($posts as $post_candidate) {
                if (is_object($post_candidate) && isset($post_candidate->ID)) {
                    $post_ids_in_batch[] = (int) $post_candidate->ID;
                } elseif (is_array($post_candidate) && isset($post_candidate['ID'])) {
                    $post_ids_in_batch[] = (int) $post_candidate['ID'];
                }
            }
            $post_ids_in_batch = array_values(array_unique(array_filter($post_ids_in_batch, static function ($value) {
                if (!is_scalar($value) || !is_numeric($value)) {
                    return false;
                }

                return (int) $value >= 0;
            })));

            // --- 4. Boucle d'analyse des LIENS <a> ---
            $upload_dir_info = wp_upload_dir();
            $missing_upload_pieces = [];
            if (empty($upload_dir_info['baseurl'])) { $missing_upload_pieces[] = 'baseurl'; }
            if (empty($upload_dir_info['basedir'])) { $missing_upload_pieces[] = 'basedir'; }
            $upload_dir_has_error = !empty($upload_dir_info['error']) || !empty($missing_upload_pieces);

            if ($upload_dir_has_error) {
                if ($debug_mode) {
                    $upload_dir_error_message = !empty($upload_dir_info['error'])
                        ? (string) $upload_dir_info['error']
                        : ('Missing ' . implode(' & ', $missing_upload_pieces) . ' from wp_upload_dir().');
                    error_log('wp_upload_dir() unavailable during link scan: ' . $upload_dir_error_message);
                }

                $upload_baseurl  = '';
                $upload_basedir  = '';
                $normalized_upload_basedir = '';
            } else {
                $upload_baseurl  = trailingslashit((string) $upload_dir_info['baseurl']);
                $upload_basedir  = trailingslashit((string) $upload_dir_info['basedir']);
                $normalized_upload_basedir = $upload_basedir !== '' ? wp_normalize_path($upload_basedir) : '';
            }
            if ($raw_home_url === '' && function_exists('home_url')) {
                $raw_home_url = home_url();
            }
            $site_url        = trailingslashit($raw_home_url);
            $global_link_sources = $this->collectGlobalLinkSources($site_url);
            if (!empty($global_link_sources)) {
                $post_ids_in_batch[] = 0;
            }

            if (!empty($post_ids_in_batch)) {
                $post_ids_in_batch = array_values(array_unique(array_filter($post_ids_in_batch, static function ($value) {
                    if (!is_scalar($value) || !is_numeric($value)) {
                        return false;
                    }

                    return (int) $value >= 0;
                })));

                if (!empty($post_ids_in_batch)) {
                    $scan_run_token = blc_generate_scan_run_token();
                    $stage_result = blc_stage_dataset_refresh($table_name, $link_dataset_row_types, $scan_run_token, $post_ids_in_batch);
                    if (is_wp_error($stage_result)) {
                        if ($lock_token !== '') {
                            blc_release_link_scan_lock($lock_token);
                        }

                        return $stage_result;
                    }
                }
            }
            $site_scheme     = parse_url($raw_home_url, PHP_URL_SCHEME);
            if (!is_string($site_scheme) || $site_scheme === '') {
                $site_scheme = 'http';
            }
            $site_host = '';
            $site_parts = function_exists('wp_parse_url') ? wp_parse_url($site_url) : parse_url($site_url);
            $site_path = '';
            if (is_array($site_parts)) {
                if (!empty($site_parts['host'])) {
                    $site_host = strtolower((string) $site_parts['host']);
                }

                if (isset($site_parts['path']) && is_string($site_parts['path']) && $site_parts['path'] !== '') {
                    $site_path = rtrim($site_parts['path'], '/');
                }
            }
            $normalized_upload_baseurl = '';
            $upload_base_host = '';
            $upload_base_path = '';
            if ($upload_baseurl !== '') {
                $normalized_upload_baseurl = trailingslashit(set_url_scheme($upload_baseurl, $site_scheme));
                $upload_base_parts = parse_url($normalized_upload_baseurl);
                if (is_array($upload_base_parts)) {
                    $upload_base_host = isset($upload_base_parts['host']) ? strtolower($upload_base_parts['host']) : '';
                    if (isset($upload_base_parts['path'])) {
                        $upload_base_path = rtrim($upload_base_parts['path'], '/');
                    }
                }
            }

            $normalized_abspath = '';
            if (defined('ABSPATH')) {
                $abspath = (string) ABSPATH;
                if ($abspath !== '') {
                    if (function_exists('wp_normalize_path')) {
                        $normalized_abspath = wp_normalize_path($abspath);
                    } else {
                        $normalized_abspath = str_replace('\\', '/', $abspath);
                    }
                    $normalized_abspath = rtrim($normalized_abspath, "/\\");
                }
            }

            $blog_charset = get_bloginfo('charset');
            if (empty($blog_charset)) { $blog_charset = 'UTF-8'; }

            $resolve_internal_target = static function ($url) use (
                $normalized_abspath,
                $site_path,
                $upload_base_host,
                $upload_base_path,
                $normalized_upload_basedir
            ) {
                $unknown_result = [
                    'status'        => 'unknown',
                    'response_code' => null,
                ];

                if (!is_string($url) || $url === '') {
                    return $unknown_result;
                }

                $evaluate_internal_post = static function (int $post_id) {
                    if ($post_id <= 0) {
                        return null;
                    }

                    $post_status = function_exists('get_post_status') ? get_post_status($post_id) : null;
                    if (!is_string($post_status) || $post_status === '') {
                        return [
                            'status'        => 'missing',
                            'response_code' => 404,
                        ];
                    }

                    $post_type = function_exists('get_post_type') ? get_post_type($post_id) : null;
                    if ($post_type === 'attachment') {
                        $candidate_files = [];

                        if (function_exists('get_attached_file')) {
                            $attached_file = get_attached_file($post_id);
                            if (is_string($attached_file) && $attached_file !== '') {
                                $candidate_files[] = $attached_file;
                            }
                        }

                        if (function_exists('wp_get_original_image_path')) {
                            $original_path = wp_get_original_image_path($post_id);
                            if (is_string($original_path) && $original_path !== '') {
                                $candidate_files[] = $original_path;
                            }
                        }

                        $has_candidate = false;
                        foreach (array_unique($candidate_files) as $candidate_file) {
                            if (!is_string($candidate_file) || $candidate_file === '') {
                                continue;
                            }

                            $has_candidate = true;
                            if (file_exists($candidate_file)) {
                                return [
                                    'status'        => 'ok',
                                    'response_code' => 200,
                                ];
                            }
                        }

                        if ($has_candidate) {
                            return [
                                'status'        => 'missing',
                                'response_code' => 404,
                            ];
                        }
                    }

                    return [
                        'status'        => 'ok',
                        'response_code' => 200,
                    ];
                };

                if (function_exists('url_to_postid')) {
                    $maybe_post_id = url_to_postid($url);
                    if (is_numeric($maybe_post_id)) {
                        $result = $evaluate_internal_post((int) $maybe_post_id);
                        if (is_array($result)) {
                            return $result;
                        }
                    }
                }

                if (function_exists('attachment_url_to_postid')) {
                    $maybe_attachment_id = attachment_url_to_postid($url);
                    if (is_numeric($maybe_attachment_id)) {
                        $result = $evaluate_internal_post((int) $maybe_attachment_id);
                        if (is_array($result)) {
                            return $result;
                        }
                    }
                }

                $parser = function_exists('wp_parse_url') ? 'wp_parse_url' : 'parse_url';
                $parts = $parser($url);
                if (!is_array($parts)) {
                    return $unknown_result;
                }

                $path = isset($parts['path']) ? (string) $parts['path'] : '';
                if ($path === '') {
                    return $unknown_result;
                }

                $path = rawurldecode($path);
                $candidate_paths = [];

                if ($normalized_abspath !== '') {
                    $adjusted_path = $path;
                    if ($site_path !== '') {
                        if ($adjusted_path === $site_path) {
                            $adjusted_path = '';
                        } elseif (strpos($adjusted_path, $site_path . '/') === 0) {
                            $adjusted_path = substr($adjusted_path, strlen($site_path));
                        }
                    }

                    $adjusted_path = ltrim($adjusted_path, '/');
                    if ($adjusted_path !== '') {
                        $relative_with_slash = '/' . $adjusted_path;
                        $abspath_prefixes = ['/wp-content/', '/wp-includes/', '/wp-admin/'];
                        $should_attempt_abspath = false;

                        foreach ($abspath_prefixes as $prefix) {
                            if (strpos($relative_with_slash, $prefix) === 0) {
                                $should_attempt_abspath = true;
                                break;
                            }
                        }

                        if (!$should_attempt_abspath) {
                            $special_files = ['/robots.txt', '/favicon.ico', '/humans.txt'];
                            if (in_array($relative_with_slash, $special_files, true)) {
                                $should_attempt_abspath = true;
                            }
                        }

                        if ($should_attempt_abspath && $normalized_upload_basedir === '' && strpos($relative_with_slash, '/wp-content/uploads/') === 0) {
                            $should_attempt_abspath = false;
                        }

                        if ($should_attempt_abspath) {
                            $candidate_paths[] = $normalized_abspath . '/' . $adjusted_path;
                        }
                    }
                }

                if ($normalized_upload_basedir !== '') {
                    $host_matches_upload = true;
                    if (!empty($parts['host'])) {
                        $candidate_host = strtolower((string) $parts['host']);
                        if ($upload_base_host !== '' && $candidate_host !== $upload_base_host) {
                            $host_matches_upload = false;
                        }
                    }

                    if ($host_matches_upload) {
                        $path_for_upload = $path;
                        if ($upload_base_path !== '') {
                            if ($path_for_upload === $upload_base_path) {
                                $path_for_upload = '';
                            } elseif (strpos($path_for_upload, $upload_base_path . '/') === 0) {
                                $path_for_upload = substr($path_for_upload, strlen($upload_base_path));
                            } else {
                                $host_matches_upload = false;
                            }
                        }

                        if ($host_matches_upload) {
                            $relative_upload_path = ltrim($path_for_upload, '/');
                            if ($relative_upload_path !== '') {
                                $candidate_paths[] = rtrim($normalized_upload_basedir, '/\\') . '/' . $relative_upload_path;
                            }
                        }
                    }
                }

                if ($candidate_paths === []) {
                    return $unknown_result;
                }

                foreach ($candidate_paths as $candidate_path) {
                    if (!is_string($candidate_path) || $candidate_path === '') {
                        continue;
                    }

                    $normalized_candidate = $candidate_path;
                    if (function_exists('wp_normalize_path')) {
                        $normalized_candidate = wp_normalize_path($candidate_path);
                    } else {
                        $normalized_candidate = str_replace('\\', '/', $candidate_path);
                    }

                    if ($normalized_candidate !== '' && file_exists($normalized_candidate)) {
                        return [
                            'status'        => 'ok',
                            'response_code' => 200,
                        ];
                    }
                }

                return [
                    'status'        => 'missing',
                    'response_code' => 404,
                ];
            };

            $temporary_http_statuses = apply_filters(
                'blc_temporary_http_statuses',
                [429, 503]
            );
            if (!is_array($temporary_http_statuses)) {
                $temporary_http_statuses = [];
            }
            $temporary_http_statuses = array_values(array_unique(array_map('intval', $temporary_http_statuses)));

            $temporary_retry_scheduled = false;

            $batch_exception = null;
            $batch_wp_error = null;

            $pending_link_inserts = [];
            $register_pending_link_insert = static function ($post_id, $row_bytes) use (&$pending_link_inserts, $wpdb) {
                $post_id = (int) $post_id;
                if ($post_id < 0) {
                    return;
                }

                $row_id = isset($wpdb->insert_id) ? (int) $wpdb->insert_id : 0;
                if ($row_id <= 0) {
                    return;
                }

                if (!isset($pending_link_inserts[$post_id])) {
                    $pending_link_inserts[$post_id] = [];
                }

                $pending_link_inserts[$post_id][] = [
                    'id'    => $row_id,
                    'bytes' => (int) $row_bytes,
                ];
            };

            $link_occurrence_counters = [];

            $create_link_processor = static function (
                int $source_post_id,
                string $storage_title,
                string $permalink_for_links,
                string $reference_type
            ) use (
                &$link_occurrence_counters,
                $site_url,
                $site_scheme,
                $site_host,
                $register_pending_link_insert,
                $wpdb,
                $table_name,
                $resolve_internal_target,
                $upload_basedir,
                $upload_base_host,
                $upload_base_path,
                $normalized_upload_basedir,
                $excluded_domains,
                $safe_internal_hosts,
                &$temporary_retry_scheduled,
                $temporary_http_statuses,
                $batch_delay_s,
                $head_request_timeout,
                $get_request_timeout,
                $scan_method,
                &$scan_cache_data,
                &$scan_cache_dirty,
                $scan_cache_identifier,
                $debug_mode,
                $batch,
                $is_full_scan,
                $bypass_rest_window,
                $remote_request_client,
                $remote_request_queue,
                $soft_404_min_length_option,
                $soft_404_error_patterns_option,
                $soft_404_ignore_selectors_option
            ) {
                return function (
                    string $original_url,
                    string $anchor_text,
                    string $context_html,
                    string $context_excerpt,
                    string $detected_reference_type = '',
                    array $reference_metadata = []
                ) use (
                    &$link_occurrence_counters,
                    $source_post_id,
                    $storage_title,
                    $permalink_for_links,
                    $reference_type,
                    $site_url,
                    $site_scheme,
                    $site_host,
                    $register_pending_link_insert,
                    $wpdb,
                    $table_name,
                    $resolve_internal_target,
                    $upload_basedir,
                    $upload_base_host,
                    $upload_base_path,
                    $normalized_upload_basedir,
                    $excluded_domains,
                    $safe_internal_hosts,
                    &$temporary_retry_scheduled,
                    $temporary_http_statuses,
                    $batch_delay_s,
                    $head_request_timeout,
                    $get_request_timeout,
                    $scan_method,
                    &$scan_cache_data,
                    &$scan_cache_dirty,
                    $scan_cache_identifier,
                    $debug_mode,
                    $batch,
                    $is_full_scan,
                    $bypass_rest_window,
                    $remote_request_client,
                    $remote_request_queue,
                    $soft_404_min_length_option,
                    $soft_404_error_patterns_option,
                    $soft_404_ignore_selectors_option
                ) {
                    if (!isset($link_occurrence_counters[$source_post_id])) {
                        $link_occurrence_counters[$source_post_id] = [];
                    }
                    $post_counters = &$link_occurrence_counters[$source_post_id];

                    $detected_type = is_string($detected_reference_type) ? trim($detected_reference_type) : '';
                    if ($detected_type === '') {
                        $detected_type = $reference_type;
                    }

                    $url_for_storage    = blc_prepare_url_for_storage($original_url);
                    $anchor_for_storage = blc_prepare_text_field_for_storage($anchor_text);
                    $context_html_for_storage = blc_prepare_context_html_for_storage($context_html);
                    $context_excerpt_for_storage = blc_prepare_context_excerpt_for_storage($context_excerpt);

                    $normalized_url = blc_normalize_link_url($original_url, $site_url, $site_scheme, $permalink_for_links);
                    if ($normalized_url === '') {
                        return;
                    }

                    $metadata  = blc_get_url_metadata_for_storage($original_url, $normalized_url, $site_host);
                    $row_bytes = blc_calculate_row_storage_footprint_bytes(
                        $url_for_storage,
                        $anchor_for_storage,
                        $storage_title,
                        $context_html_for_storage,
                        $context_excerpt_for_storage
                    );
                    $checked_at_gmt = current_time('mysql', true);
                    $context_html_value = ($context_html_for_storage === '') ? null : $context_html_for_storage;
                    $context_excerpt_value = ($context_excerpt_for_storage === '') ? null : $context_excerpt_for_storage;
                    $detected_target_for_storage = blc_prepare_url_for_storage($normalized_url);

                    $parsed_url = parse_url($normalized_url);
                    if ($parsed_url === false) {
                        return;
                    }

                    if (empty($parsed_url['scheme']) || !in_array($parsed_url['scheme'], ['http', 'https'], true)) {
                        return;
                    }

                    $counter_key = $detected_type . '|' . $url_for_storage;
                    if (!isset($post_counters[$counter_key])) {
                        $post_counters[$counter_key] = 0;
                    }
                    $occurrence_index = $post_counters[$counter_key];
                    $post_counters[$counter_key]++;

                    if ($upload_basedir && $upload_base_host && isset($parsed_url['host']) && isset($parsed_url['path'])) {
                        if (strcasecmp($upload_base_host, $parsed_url['host']) === 0 && $upload_base_path !== '' && strpos($parsed_url['path'], $upload_base_path) === 0) {
                            $relative_path = ltrim(substr($parsed_url['path'], strlen($upload_base_path)), '/');
                            if ($relative_path === '') {
                                return;
                            }
                            $decoded_relative_path = rawurldecode($relative_path);
                            $decoded_relative_path = ltrim($decoded_relative_path, '/\\');
                            if ($decoded_relative_path === '') {
                                return;
                            }

                            if (preg_match('#(^|[\\/])\.\.([\\/]|$)#', $decoded_relative_path)) {
                                return;
                            }

                            $file_path = wp_normalize_path(trailingslashit($upload_basedir) . $decoded_relative_path);
                            if ($normalized_upload_basedir !== '' && strpos($file_path, $normalized_upload_basedir) !== 0) {
                                return;
                            }

                            if (!file_exists($file_path)) {
                                if ($debug_mode) { error_log("  -> Ressource locale introuvable : " . $normalized_url); }
                                $inserted = $wpdb->insert(
                                    $table_name,
                                    [
                                        'url'             => $url_for_storage,
                                        'anchor'          => $anchor_for_storage,
                                        'post_id'         => $source_post_id,
                                        'post_title'      => $storage_title,
                                        'type'            => $detected_type,
                                        'occurrence_index'=> $occurrence_index,
                                        'url_host'        => $metadata['host'],
                                        'is_internal'     => $metadata['is_internal'],
                                        'http_status'     => null,
                                        'last_checked_at' => $checked_at_gmt,
                                        'redirect_target_url' => $detected_target_for_storage,
                                        'context_html'   => $context_html_value,
                                        'context_excerpt'=> $context_excerpt_value,
                                    ],
                                    ['%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s']
                                );
                                if ($inserted) {
                                    $register_pending_link_insert($source_post_id, $row_bytes);
                                    blc_adjust_dataset_storage_footprint('link', $row_bytes);
                                }
                                return;
                            }
                        }
                    }

                    $host = parse_url($normalized_url, PHP_URL_HOST);
                    if (!is_string($host) || $host === '') {
                        return;
                    }
                    $normalized_host = blc_normalize_remote_host($host);
                    $is_excluded = false;
                    if (!empty($excluded_domains) && !empty($host)) {
                        foreach ($excluded_domains as $domain_to_exclude) {
                            if ($domain_to_exclude === '') {
                                continue;
                            }

                            if ($normalized_host === $domain_to_exclude) {
                                $is_excluded = true;
                                break;
                            }

                            $suffix = '.' . $domain_to_exclude;
                            $suffix_length = strlen($suffix);
                            if (strlen($normalized_host) > $suffix_length && substr($normalized_host, -$suffix_length) === $suffix) {
                                $is_excluded = true;
                                break;
                            }
                        }
                    }

                    if ($is_excluded) {
                        return;
                    }

                    $is_internal_safe_host = ($normalized_host !== '' && isset($safe_internal_hosts[$normalized_host]));
                    $is_safe_remote_host   = true;
                    $should_skip_remote_request = false;

                    if (!$is_internal_safe_host) {
                        $host_to_check = $normalized_host !== '' ? $normalized_host : $host;
                        $is_safe_remote_host = blc_is_safe_remote_host($host_to_check);

                        if (!$is_safe_remote_host) {
                            if ($debug_mode) { error_log("  -> Lien ignoré (IP non autorisée) : " . $normalized_url); }
                            $inserted = $wpdb->insert(
                                $table_name,
                                [
                                    'url'                 => $url_for_storage,
                                    'anchor'              => $anchor_for_storage,
                                    'post_id'             => $source_post_id,
                                    'post_title'          => $storage_title,
                                    'type'                => $detected_type,
                                    'occurrence_index'    => $occurrence_index,
                                    'url_host'            => $metadata['host'],
                                    'is_internal'         => $metadata['is_internal'],
                                    'http_status'         => null,
                                    'last_checked_at'     => $checked_at_gmt,
                                    'redirect_target_url' => $detected_target_for_storage,
                                    'context_html'        => $context_html_value,
                                    'context_excerpt'     => $context_excerpt_value,
                                ],
                                ['%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s']
                            );
                            if ($inserted) {
                                $register_pending_link_insert($source_post_id, $row_bytes);
                                blc_adjust_dataset_storage_footprint('link', $row_bytes);
                            }
                            $should_skip_remote_request = true;
                        }
                    }

                    if ($should_skip_remote_request) {
                        return;
                    }

                    $should_insert_broken_link = false;
                    $should_retry_later = false;
                    $response_code = null;
                    $cache_entry_key = '';
                    $cache_entry = null;
                    $should_use_cache = false;
                    $skip_remote_check = false;
                    $soft_404_reason = '';

                    if ($scan_cache_identifier !== '') {
                        $cache_entry_key = md5($normalized_url);
                        if (isset($scan_cache_data[$cache_entry_key]) && is_array($scan_cache_data[$cache_entry_key])) {
                            $cache_entry = $scan_cache_data[$cache_entry_key];
                        }
                    }

                    if (is_array($cache_entry) && isset($cache_entry['action'])) {
                        $cache_action = (string) $cache_entry['action'];
                        if ($cache_action === 'retry') {
                            $checked_at = isset($cache_entry['checked_at']) ? (int) $cache_entry['checked_at'] : 0;
                            $retry_ttl = apply_filters('blc_retry_cache_ttl', 300, $normalized_url, $cache_entry);
                            if (!is_int($retry_ttl)) {
                                $retry_ttl = 300;
                            }

                            if ($retry_ttl <= 0 || ($checked_at > 0 && (time() - $checked_at) <= $retry_ttl)) {
                                $should_retry_later = true;
                                $should_use_cache = true;
                            }
                        } elseif ($cache_action === 'broken') {
                            $should_insert_broken_link = true;
                            $should_use_cache = true;
                        } elseif ($cache_action === 'ok') {
                            $should_use_cache = true;
                        }

                        if (isset($cache_entry['soft_404_reason'])) {
                            $soft_404_reason = (string) $cache_entry['soft_404_reason'];
                        }

                        if ($should_use_cache && isset($cache_entry['response_code'])) {
                            $response_code = (int) $cache_entry['response_code'];
                        }
                    }

                    if ($is_internal_safe_host) {
                        $internal_resolution = $resolve_internal_target($normalized_url);
                        if (is_array($internal_resolution)) {
                            if (isset($internal_resolution['response_code'])) {
                                $response_code = (int) $internal_resolution['response_code'];
                            }

                            $resolution_status = isset($internal_resolution['status'])
                                ? (string) $internal_resolution['status']
                                : 'unknown';

                            if ($resolution_status === 'ok') {
                                $should_insert_broken_link = false;
                                $should_retry_later = false;
                                $skip_remote_check = true;
                            } elseif ($resolution_status === 'missing') {
                                $should_insert_broken_link = true;
                                $should_retry_later = false;
                                $skip_remote_check = true;
                            }
                        }
                    }

                    $finalize_remote_outcome = function () use (
                        &$should_retry_later,
                        &$should_insert_broken_link,
                        &$response_code,
                        &$soft_404_reason,
                        &$temporary_retry_scheduled,
                        $batch_delay_s,
                        $normalized_url,
                        $batch,
                        $is_full_scan,
                        $bypass_rest_window,
                        $wpdb,
                        $table_name,
                        $url_for_storage,
                        $anchor_for_storage,
                        $source_post_id,
                        $storage_title,
                        $occurrence_index,
                        $metadata,
                        $checked_at_gmt,
                        &$detected_target_for_storage,
                        $context_html_value,
                        $context_excerpt_value,
                        $register_pending_link_insert,
                        $row_bytes,
                        &$scan_cache_data,
                        &$scan_cache_dirty,
                        $cache_entry_key,
                        $should_use_cache,
                        $debug_mode,
                        $detected_type
                    ) {
                        if ($should_retry_later) {
                            if (!$temporary_retry_scheduled) {
                                $retry_delay = (int) apply_filters(
                                    'blc_temporary_retry_delay',
                                    max(60, $batch_delay_s),
                                    $normalized_url,
                                    $response_code
                                );
                                if ($retry_delay < 0) {
                                    $retry_delay = 0;
                                }

                                $scheduled = $this->scheduleBatch(
                                    $batch,
                                    $is_full_scan,
                                    $bypass_rest_window,
                                    $retry_delay,
                                    'temporary_retry'
                                );
                                if ($scheduled) {
                                    $temporary_retry_scheduled = true;
                                }
                            }
                        } elseif ($should_insert_broken_link) {
                            $inserted = $wpdb->insert(
                                $table_name,
                                [
                                    'url'             => $url_for_storage,
                                    'anchor'          => $anchor_for_storage,
                                    'post_id'         => $source_post_id,
                                    'post_title'      => $storage_title,
                                    'type'            => $detected_type,
                                    'occurrence_index'=> $occurrence_index,
                                    'url_host'        => $metadata['host'],
                                    'is_internal'     => $metadata['is_internal'],
                                    'http_status'     => ($response_code !== null) ? (int) $response_code : null,
                                    'last_checked_at' => $checked_at_gmt,
                                    'redirect_target_url' => $detected_target_for_storage,
                                    'context_html'   => $context_html_value,
                                    'context_excerpt'=> $context_excerpt_value,
                                ],
                                ['%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s']
                            );
                            if ($inserted) {
                                $register_pending_link_insert($source_post_id, $row_bytes);
                                blc_adjust_dataset_storage_footprint('link', $row_bytes);
                            }
                        }

                        if ($soft_404_reason !== '' && $debug_mode) {
                            error_log(sprintf('  -> Soft 404 détectée : %s (%s)', $normalized_url, $soft_404_reason));
                        }

                        if (!$should_use_cache && $cache_entry_key !== '') {
                            $cache_timestamp = time();
                            $cache_action = 'ok';
                            if ($should_retry_later) {
                                $cache_action = 'retry';
                            } elseif ($should_insert_broken_link) {
                                $cache_action = 'broken';
                            }

                            $new_cache_entry = [
                                'action'     => $cache_action,
                                'checked_at' => $cache_timestamp,
                            ];

                            if ($response_code !== null) {
                                $new_cache_entry['response_code'] = $response_code;
                            }

                            if ($soft_404_reason !== '') {
                                $new_cache_entry['soft_404_reason'] = $soft_404_reason;
                            }

                            $scan_cache_data[$cache_entry_key] = $new_cache_entry;
                            $scan_cache_dirty = true;
                        }
                    };

                    if (!$should_use_cache && !$skip_remote_check) {
                        $head_request_args = [
                            'user-agent'          => blc_get_http_user_agent(),
                            'timeout'             => $head_request_timeout,
                            'limit_response_size' => 1024,
                            'redirection'         => 5,
                        ];

                        $get_request_args = [
                            'timeout'             => $get_request_timeout,
                            'user-agent'          => blc_get_http_user_agent(),
                            'method'              => 'GET',
                            'limit_response_size' => 131072,
                        ];

                        $remote_request_queue->enqueue(
                            $normalized_url,
                            $head_request_args,
                            $get_request_args,
                            $scan_method,
                            $temporary_http_statuses,
                            function (
                                $response,
                                bool $head_request_disallowed,
                                bool $fallback_due_to_temporary_status,
                                bool $used_get_request
                            ) use (
                                &$should_retry_later,
                                &$should_insert_broken_link,
                                &$response_code,
                                &$detected_target_for_storage,
                                &$soft_404_reason,
                                $remote_request_client,
                                $temporary_http_statuses,
                                $normalized_url,
                                $finalize_remote_outcome,
                                $soft_404_min_length_option,
                                $soft_404_error_patterns_option,
                                $soft_404_ignore_selectors_option,
                                $get_request_args
                            ) {
                                $soft_404_reason = '';
                                if (!is_wp_error($response)) {
                                    $resolved_target_url = blc_determine_response_target_url($response, $normalized_url);
                                    if ($resolved_target_url !== '') {
                                        $detected_target_for_storage = blc_prepare_url_for_storage($resolved_target_url);
                                    }
                                }

                                if (is_wp_error($response)) {
                                    if ($head_request_disallowed || $fallback_due_to_temporary_status) {
                                        $should_retry_later = true;
                                    } else {
                                        $temporary_wp_error_codes = apply_filters(
                                            'blc_temporary_wp_error_codes',
                                            ['request_timed_out', 'connect_timeout', 'could_not_resolve_host', 'dns_unresolved_hostname', 'timeout']
                                        );
                                        if (!is_array($temporary_wp_error_codes)) {
                                            $temporary_wp_error_codes = [];
                                        }
                                        $temporary_wp_error_codes = array_values(array_unique(array_filter(array_map('strval', $temporary_wp_error_codes))));

                                        $error_code = method_exists($response, 'get_error_code') ? (string) $response->get_error_code() : '';
                                        if ($error_code !== '' && in_array($error_code, $temporary_wp_error_codes, true)) {
                                            $should_retry_later = true;
                                        } else {
                                            $temporary_wp_error_indicators = apply_filters(
                                                'blc_temporary_wp_error_indicators',
                                                ['timed out', 'timeout', 'temporarily unavailable', 'temporary failure', 'could not resolve host']
                                            );
                                            if (!is_array($temporary_wp_error_indicators)) {
                                                $temporary_wp_error_indicators = [];
                                            }

                                            $error_message = method_exists($response, 'get_error_message') ? (string) $response->get_error_message() : '';
                                            foreach ($temporary_wp_error_indicators as $indicator) {
                                                $indicator = (string) $indicator;
                                                if ($indicator === '') {
                                                    continue;
                                                }

                                                if ($error_message !== '' && stripos($error_message, $indicator) !== false) {
                                                    $should_retry_later = true;
                                                    break;
                                                }
                                            }

                                            if (!$should_retry_later && method_exists($response, 'get_error_data')) {
                                                $error_data = $response->get_error_data();
                                                if (is_array($error_data) && isset($error_data['status'])) {
                                                    $maybe_status = (int) $error_data['status'];
                                                    if (in_array($maybe_status, $temporary_http_statuses, true)) {
                                                        $should_retry_later = true;
                                                    }
                                                }
                                            }
                                        }

                                        if (!$should_retry_later) {
                                            $should_insert_broken_link = true;
                                        }
                                    }
                                } else {
                                    $effective_response = $response;
                                    $response_code = (int) $remote_request_client->responseCode($effective_response);
                                    if ($response_code >= 400) {
                                        if (in_array($response_code, $temporary_http_statuses, true)) {
                                            $should_retry_later = true;
                                        } else {
                                            $should_insert_broken_link = true;
                                        }
                                    } else {
                                        $body_content = '';
                                        if (is_array($effective_response) && array_key_exists('body', $effective_response)) {
                                            $body_content = (string) $effective_response['body'];
                                        } else {
                                            if (function_exists('wp_remote_retrieve_body')) {
                                                $body_content = (string) wp_remote_retrieve_body($effective_response);
                                            } else {
                                                if (is_array($effective_response) && isset($effective_response['body'])) {
                                                    $body_content = (string) $effective_response['body'];
                                                } elseif (is_object($effective_response) && isset($effective_response->body)) {
                                                    $body_content = (string) $effective_response->body;
                                                } else {
                                                    $body_content = '';
                                                }
                                            }
                                        }

                                        $followup_failed = false;
                                        if ($body_content === '' && !$used_get_request) {
                                            $followup_response = $remote_request_client->get($normalized_url, $get_request_args);
                                            if (is_wp_error($followup_response)) {
                                                $followup_failed = true;
                                            } else {
                                                $effective_response = $followup_response;
                                                $response_code = (int) $remote_request_client->responseCode($effective_response);
                                                if ($response_code >= 400) {
                                                    if (in_array($response_code, $temporary_http_statuses, true)) {
                                                        $should_retry_later = true;
                                                    } else {
                                                        $should_insert_broken_link = true;
                                                    }
                                                }

                                                if (!$should_insert_broken_link) {
                                                    $resolved_target_url = blc_determine_response_target_url($effective_response, $normalized_url);
                                                    if ($resolved_target_url !== '') {
                                                        $detected_target_for_storage = blc_prepare_url_for_storage($resolved_target_url);
                                                    }
                                                }

                                                if (is_array($effective_response) && array_key_exists('body', $effective_response)) {
                                                    $body_content = (string) $effective_response['body'];
                                                } else {
                                                    if (function_exists('wp_remote_retrieve_body')) {
                                                        $body_content = (string) wp_remote_retrieve_body($effective_response);
                                                    } else {
                                                        if (is_array($effective_response) && isset($effective_response['body'])) {
                                                            $body_content = (string) $effective_response['body'];
                                                        } elseif (is_object($effective_response) && isset($effective_response->body)) {
                                                            $body_content = (string) $effective_response->body;
                                                        } else {
                                                            $body_content = '';
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        if (!$should_retry_later && !$should_insert_broken_link && !$followup_failed) {
                                            $min_length = is_numeric($soft_404_min_length_option)
                                                ? (int) $soft_404_min_length_option
                                                : 0;
                                            $min_length = max(0, (int) apply_filters(
                                                'blc_soft_404_min_length',
                                                $min_length,
                                                $normalized_url,
                                                $effective_response
                                            ));

                                            $raw_patterns = (string) $soft_404_error_patterns_option;
                                            $pattern_candidates = [];
                                            if ($raw_patterns !== '') {
                                                $split_patterns = preg_split('/[\r\n,]+/', $raw_patterns);
                                                if (is_array($split_patterns)) {
                                                    $pattern_candidates = $split_patterns;
                                                }
                                            }

                                            $patterns = array_values(array_filter(array_map('trim', $pattern_candidates), static function ($value) {
                                                return $value !== '';
                                            }));
                                            $patterns = apply_filters(
                                                'blc_soft_404_error_patterns',
                                                $patterns,
                                                $normalized_url,
                                                $effective_response
                                            );
                                            if (!is_array($patterns)) {
                                                $patterns = [];
                                            }
                                            $patterns = array_values(array_filter(array_map('strval', $patterns), static function ($value) {
                                                return $value !== '';
                                            }));

                                            $raw_selectors = (string) $soft_404_ignore_selectors_option;
                                            $selector_candidates = [];
                                            if ($raw_selectors !== '') {
                                                $split_selectors = preg_split('/[\r\n,]+/', $raw_selectors);
                                                if (is_array($split_selectors)) {
                                                    $selector_candidates = $split_selectors;
                                                }
                                            }

                                            $ignore_selectors = array_values(array_filter(array_map('trim', $selector_candidates), static function ($value) {
                                                return $value !== '';
                                            }));
                                            $ignore_selectors = apply_filters(
                                                'blc_soft_404_ignore_selectors',
                                                $ignore_selectors,
                                                $normalized_url,
                                                $effective_response
                                            );
                                            if (!is_array($ignore_selectors)) {
                                                $ignore_selectors = [];
                                            }
                                            $ignore_selectors = array_values(array_filter(array_map('strval', $ignore_selectors), static function ($value) {
                                                return $value !== '';
                                            }));

                                            $title_text = '';
                                            $h1_text = '';
                                            $text_for_analysis = '';

                                            if ($body_content !== '') {
                                                $previous_internal_errors = libxml_use_internal_errors(true);
                                                $dom = new DOMDocument();
                                                $loaded = false;
                                                $html_to_parse = $body_content;
                                                if (stripos($html_to_parse, '<html') === false) {
                                                    $html_to_parse = '<!DOCTYPE html><html><body>' . $html_to_parse . '</body></html>';
                                                }

                                                try {
                                                    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html_to_parse);
                                                } catch (\Throwable $exception) {
                                                    $loaded = false;
                                                }

                                                if ($loaded) {
                                                    $xpath = new DOMXPath($dom);
                                                    $title_nodes = $dom->getElementsByTagName('title');
                                                    if ($title_nodes->length > 0) {
                                                        $title_text = trim($title_nodes->item(0)->textContent ?? '');
                                                    }

                                                    $h1_nodes = $dom->getElementsByTagName('h1');
                                                    if ($h1_nodes->length > 0) {
                                                        $h1_text = trim($h1_nodes->item(0)->textContent ?? '');
                                                    }

                                                    if (!empty($ignore_selectors)) {
                                                        $xpathLiteral = static function (string $value): string {
                                                            if (strpos($value, "'") === false) {
                                                                return "'" . $value . "'";
                                                            }

                                                            if (strpos($value, '"') === false) {
                                                                return '"' . $value . '"';
                                                            }

                                                            $parts = explode("'", $value);
                                                            $escapedParts = array_map(static function ($part) {
                                                                return "'" . $part . "'";
                                                            }, $parts);

                                                            return 'concat(' . implode(', "\'", ', $escapedParts) . ')';
                                                        };

                                                        foreach ($ignore_selectors as $selector) {
                                                            $selector = trim($selector);
                                                            if ($selector === '') {
                                                                continue;
                                                            }

                                                            $nodes_to_remove = [];
                                                            if (strpos($selector, '#') === 0) {
                                                                $id = substr($selector, 1);
                                                                if ($id !== '') {
                                                                    $node = $dom->getElementById($id);
                                                                    if ($node instanceof \DOMNode) {
                                                                        $nodes_to_remove[] = $node;
                                                                    }
                                                                }
                                                            } elseif (strpos($selector, '.') === 0) {
                                                                $class = substr($selector, 1);
                                                                if ($class !== '') {
                                                                    $query = sprintf(
                                                                        '//*[contains(concat(" ", normalize-space(@class), " "), %s)]',
                                                                        $xpathLiteral($class)
                                                                    );
                                                                    $matched_nodes = $xpath->query($query);
                                                                    if ($matched_nodes instanceof \DOMNodeList) {
                                                                        foreach ($matched_nodes as $matched_node) {
                                                                            $nodes_to_remove[] = $matched_node;
                                                                        }
                                                                    }
                                                                }
                                                            } else {
                                                                $tag = preg_replace('/[^A-Za-z0-9_-]/', '', $selector);
                                                                if ($tag !== '') {
                                                                    $matched_nodes = $dom->getElementsByTagName($tag);
                                                                    if ($matched_nodes instanceof \DOMNodeList) {
                                                                        foreach ($matched_nodes as $matched_node) {
                                                                            $nodes_to_remove[] = $matched_node;
                                                                        }
                                                                    }
                                                                }
                                                            }

                                                            foreach ($nodes_to_remove as $node_to_remove) {
                                                                if ($node_to_remove instanceof \DOMNode && $node_to_remove->parentNode instanceof \DOMNode) {
                                                                    $node_to_remove->parentNode->removeChild($node_to_remove);
                                                                }
                                                            }
                                                        }
                                                    }

                                                    $text_for_analysis = trim(preg_replace('/\s+/u', ' ', $dom->textContent ?? ''));
                                                }

                                                libxml_clear_errors();
                                                libxml_use_internal_errors($previous_internal_errors);

                                                if (!$loaded) {
                                                    $text_for_analysis = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($body_content)));
                                                }
                                            }

                                            if ($text_for_analysis === '') {
                                                $text_for_analysis = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($body_content)));
                                            }

                                            $length_callback = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
                                            $soft_length = (int) $length_callback($text_for_analysis);

                                            $lower_text = function_exists('mb_strtolower')
                                                ? mb_strtolower($text_for_analysis)
                                                : strtolower($text_for_analysis);

                                            $matched_patterns = [];
                                            foreach ($patterns as $pattern) {
                                                $pattern_lower = function_exists('mb_strtolower') ? mb_strtolower($pattern) : strtolower($pattern);
                                                if ($pattern_lower === '') {
                                                    continue;
                                                }

                                                $contains = false;
                                                if (function_exists('mb_stripos')) {
                                                    $contains = mb_stripos($lower_text, $pattern_lower) !== false;
                                                } else {
                                                    $contains = stripos($lower_text, $pattern_lower) !== false;
                                                }

                                                if ($contains) {
                                                    $matched_patterns[] = $pattern;
                                                }
                                            }

                                            $soft_404_reasons = [];
                                            if ($min_length > 0 && $soft_length < $min_length) {
                                                $soft_404_reasons[] = sprintf('longueur %d < %d', $soft_length, $min_length);
                                            }

                                            if (!empty($matched_patterns)) {
                                                $soft_404_reasons[] = sprintf('motifs : %s', implode(', ', $matched_patterns));
                                            }

                        
                                            if (!empty($soft_404_reasons)) {
                                                $soft_404_reason = sprintf(
                                                    '%s | title="%s" | h1="%s"',
                                                    implode('; ', $soft_404_reasons),
                                                    $title_text !== '' ? $title_text : '-',
                                                    $h1_text !== '' ? $h1_text : '-'
                                                );

                                                $should_insert_broken_link = true;
                                            }
                                        }
                                    }
                                }

                                $finalize_remote_outcome();
                            }
                        );

                        return;
                    }

                    $finalize_remote_outcome();
                };
            };

            $build_reference_callbacks = static function (
                int $source_post_id,
                string $storage_title,
                string $permalink_for_links
            ) use ($create_link_processor, $link_dataset_row_types) {
                $callbacks = [];

                foreach ($link_dataset_row_types as $row_type) {
                    if (!is_string($row_type)) {
                        continue;
                    }

                    $row_type = trim($row_type);
                    if ($row_type === '') {
                        continue;
                    }

                    $callbacks[$row_type] = $create_link_processor($source_post_id, $storage_title, $permalink_for_links, $row_type);
                }

                return $callbacks;
            };

            try {
                foreach ($posts as $post) {
                    blc_refresh_link_scan_lock($lock_token, $lock_timeout);
                    try {
                        $permalink = '';
                        if (function_exists('get_permalink')) {
                            $maybe_permalink = get_permalink($post);
                            if (is_string($maybe_permalink)) {
                                $permalink = $maybe_permalink;
                            }
                        }

                        $primary_storage_title = blc_prepare_text_field_for_storage($post->post_title);
                        $link_occurrence_counters[(int) $post->ID] = [];

                        $link_sources = [[
                            'html'              => (string) $post->post_content,
                            'post_id'           => (int) $post->ID,
                            'permalink'         => $permalink,
                            'storage_title'     => $primary_storage_title,
                            'debug_label'       => (string) $post->post_title,
                            'restore_on_failure'=> true,
                        ]];

                        $comment_items = get_comments([
                            'post_id' => $post->ID,
                            'status'  => 'approve',
                            'type'    => 'comment',
                        ]);
                        if (!is_array($comment_items)) {
                            $comment_items = [];
                        }

                        foreach ($comment_items as $comment_item) {
                            $comment_content = '';
                            $comment_id = 0;
                            if (is_object($comment_item)) {
                                $comment_content = isset($comment_item->comment_content) ? (string) $comment_item->comment_content : '';
                                $comment_id = isset($comment_item->comment_ID) ? (int) $comment_item->comment_ID : 0;
                            } elseif (is_array($comment_item)) {
                                $comment_content = isset($comment_item['comment_content']) ? (string) $comment_item['comment_content'] : '';
                                $comment_id = isset($comment_item['comment_ID']) ? (int) $comment_item['comment_ID'] : 0;
                            }

                            if (trim($comment_content) === '' || !\blc_html_contains_reference_candidates($comment_content)) {
                                continue;
                            }

                            $label = $comment_id > 0
                                ? sprintf(__('Commentaire #%1$d — %2$s', 'liens-morts-detector-jlg'), $comment_id, $post->post_title)
                                : sprintf(__('Commentaire — %s', 'liens-morts-detector-jlg'), $post->post_title);

                            $link_sources[] = [
                                'html'          => $comment_content,
                                'post_id'       => (int) $post->ID,
                                'permalink'     => $permalink,
                                'storage_title' => blc_prepare_text_field_for_storage($label),
                                'debug_label'   => $label,
                            ];
                        }

                        $meta_values = get_post_meta($post->ID);
                        if (!is_array($meta_values)) {
                            $meta_values = [];
                        }

                        foreach ($meta_values as $meta_key => $meta_entries) {
                            if (!is_array($meta_entries)) {
                                $meta_entries = [$meta_entries];
                            }

                            foreach ($meta_entries as $meta_entry) {
                                if (!is_scalar($meta_entry)) {
                                    continue;
                                }

                                $meta_content = (string) $meta_entry;
                                if (trim($meta_content) === '' || !\blc_html_contains_reference_candidates($meta_content)) {
                                    continue;
                                }

                                $meta_label = sprintf(__('Méta « %1$s » — %2$s', 'liens-morts-detector-jlg'), (string) $meta_key, $post->post_title);

                                $link_sources[] = [
                                    'html'          => $meta_content,
                                    'post_id'       => (int) $post->ID,
                                    'permalink'     => $permalink,
                                    'storage_title' => blc_prepare_text_field_for_storage($meta_label),
                                    'debug_label'   => $meta_label,
                                ];
                            }
                        }

                        foreach ($link_sources as $source) {
                            $html = isset($source['html']) ? (string) $source['html'] : '';
                            if (trim($html) === '' || !\blc_html_contains_reference_candidates($html)) {
                                continue;
                            }

                            $label = isset($source['debug_label']) ? (string) $source['debug_label'] : (string) $post->post_title;
                            if ($debug_mode) {
                                error_log("Analyse LIENS pour : '" . $label . "'");
                            }

                            $source_post_id = isset($source['post_id']) ? (int) $source['post_id'] : (int) $post->ID;
                            $permalink_for_links = isset($source['permalink']) ? (string) $source['permalink'] : '';
                            if ($permalink_for_links === '') {
                                $permalink_for_links = $site_url;
                            }

                            $storage_title = isset($source['storage_title']) ? (string) $source['storage_title'] : $primary_storage_title;

                            $reference_callbacks = $build_reference_callbacks($source_post_id, $storage_title, $permalink_for_links);
                            $dom_processed = blc_process_link_nodes_from_html($html, $blog_charset, $reference_callbacks);
                            $remote_request_queue->drain();

                            if (!$dom_processed) {
                                if (!empty($source['restore_on_failure']) && $scan_run_token !== '') {
                                    error_log(sprintf('BLC: DOM creation failed during link scan for post ID %d; restoring staged entries.', $post->ID));
                                    blc_restore_dataset_refresh($table_name, $link_dataset_row_types, $scan_run_token, [$post->ID]);
                                    continue 2;
                                }
                            }
                        }

                    } catch (\Throwable $caught_exception) {
                        $batch_exception = $caught_exception;
                        break;
                    }

                    if ($scan_run_token !== '') {
                        $commit_result = blc_commit_dataset_refresh($table_name, $link_dataset_row_types, $scan_run_token, 'link', [$post->ID]);
                        if (is_wp_error($commit_result)) {
                            $batch_wp_error = $commit_result;
                            break;
                        }

                        if (isset($pending_link_inserts[$post->ID])) {
                            unset($pending_link_inserts[$post->ID]);
                        }
                    }
                }

                $remote_request_queue->drain();

                if ($batch_exception === null && !($batch_wp_error instanceof \WP_Error) && !empty($global_link_sources)) {
                    if (!isset($link_occurrence_counters[0])) {
                        $link_occurrence_counters[0] = [];
                    }

                    foreach ($global_link_sources as $widget_source) {
                        $html = isset($widget_source['html']) ? (string) $widget_source['html'] : '';
                        if (trim($html) === '' || !\blc_html_contains_reference_candidates($html)) {
                            continue;
                        }

                        $label = isset($widget_source['debug_label']) ? (string) $widget_source['debug_label'] : '';
                        if ($label === '') {
                            $label = __('Widget texte', 'liens-morts-detector-jlg');
                        }

                        if ($debug_mode) {
                            error_log("Analyse LIENS pour : '" . $label . "'");
                        }

                        $storage_title = isset($widget_source['storage_title']) ? (string) $widget_source['storage_title'] : blc_prepare_text_field_for_storage($label);
                        $permalink_for_links = isset($widget_source['permalink']) ? (string) $widget_source['permalink'] : '';
                        if ($permalink_for_links === '') {
                            $permalink_for_links = $site_url;
                        }

                        $source_post_id = isset($widget_source['post_id']) ? (int) $widget_source['post_id'] : 0;
                        if (!isset($link_occurrence_counters[$source_post_id])) {
                            $link_occurrence_counters[$source_post_id] = [];
                        }

                        $reference_callbacks = $build_reference_callbacks($source_post_id, $storage_title, $permalink_for_links);
                        blc_process_link_nodes_from_html($html, $blog_charset, $reference_callbacks);
                        $remote_request_queue->drain();
                    }

                    if ($scan_run_token !== '') {
                        $commit_result = blc_commit_dataset_refresh($table_name, $link_dataset_row_types, $scan_run_token, 'link', [0]);
                        if (is_wp_error($commit_result)) {
                            $batch_wp_error = $commit_result;
                        }

                        if (isset($pending_link_inserts[0])) {
                            unset($pending_link_inserts[0]);
                        }
                    }
                }

                $should_cleanup_pending_links = ($batch_exception !== null || $batch_wp_error instanceof \WP_Error);
                if ($should_cleanup_pending_links && !empty($pending_link_inserts)) {
                    foreach ($pending_link_inserts as $post_pending_entries) {
                        foreach ($post_pending_entries as $entry) {
                            $row_id = isset($entry['id']) ? (int) $entry['id'] : 0;
                            $bytes  = isset($entry['bytes']) ? (int) $entry['bytes'] : 0;

                            if ($row_id > 0) {
                                $wpdb->delete($table_name, ['id' => $row_id], ['%d']);
                            }

                            if ($bytes !== 0) {
                                blc_adjust_dataset_storage_footprint('link', -$bytes);
                            }
                        }
                    }

                    $pending_link_inserts = [];
                }

                if ($scan_run_token !== '' && $should_cleanup_pending_links) {
                    blc_restore_dataset_refresh($table_name, $link_dataset_row_types, $scan_run_token);
                }

                if ($batch_exception instanceof \Throwable) {
                    throw $batch_exception;
                }

                if ($batch_wp_error instanceof \WP_Error) {
                    return $batch_wp_error;
                }

                // --- 5. Sauvegarde et planification ---

                if ($scan_cache_dirty) {
                    blc_save_scan_cache($scan_cache_context, $scan_cache_data);
                    $scan_cache_dirty = false;
                }

                if ($temporary_retry_scheduled) {
                    if ($debug_mode) { error_log('Scan reporté : statut HTTP temporaire détecté, nouveau passage planifié.'); }
                    \blc_update_link_scan_status([
                        'state'             => 'running',
                        'current_batch'     => (int) $batch,
                        'processed_batches' => $processed_batches,
                        'total_batches'     => $total_batches,
                        'remaining_batches' => $remaining_batches,
                        'total_items'       => $total_items,
                        'processed_items'   => $processed_items,
                        'message'           => \__('Analyse mise en pause : un nouveau passage sera tenté suite à un statut temporaire.', 'liens-morts-detector-jlg'),
                    ]);
                    return;
                }

                if ($wp_query->max_num_pages > ($batch + 1)) {
                    $scheduled = $this->scheduleBatch(
                        $batch + 1,
                        $is_full_scan,
                        $bypass_rest_window,
                        $batch_delay_s,
                        'next_batch'
                    );
                    if (!$scheduled) {
                        \blc_update_link_scan_status([
                            'state'           => 'failed',
                            'last_error'      => \__('Impossible de programmer le lot suivant.', 'liens-morts-detector-jlg'),
                            'message'         => \__('Impossible de programmer le lot suivant.', 'liens-morts-detector-jlg'),
                            'total_items'     => $total_items,
                            'processed_items' => $processed_items,
                        ]);
                    } else {
                        \blc_update_link_scan_status([
                            'state'             => 'running',
                            'current_batch'     => (int) $batch,
                            'processed_batches' => $processed_batches,
                            'total_batches'     => $total_batches,
                            'remaining_batches' => max(0, $remaining_batches),
                            'total_items'       => $total_items,
                            'processed_items'   => $processed_items,
                            'message'           => sprintf(
                                /* translators: 1: completed batch number, 2: next batch number. */
                                \__('Lot %1$d terminé. Lot %2$d planifié.', 'liens-morts-detector-jlg'),
                                $processed_batches,
                                min($total_batches, $processed_batches + 1)
                            ),
                        ]);
                    }
                } else {
                    update_option('blc_last_check_time', current_time('timestamp', true));
                    blc_clear_scan_cache($scan_cache_context);
                    blc_maybe_send_scan_summary('link');
                    $final_status = \blc_update_link_scan_status([
                        'state'             => 'completed',
                        'current_batch'     => (int) $batch,
                        'processed_batches' => $processed_batches,
                        'total_batches'     => $total_batches,
                        'remaining_batches' => 0,
                        'total_items'       => $total_items,
                        'processed_items'   => max($total_items, $processed_items),
                        'message'           => $total_items > 0
                            ? \__('Analyse terminée. Tous les lots ont été traités.', 'liens-morts-detector-jlg')
                            : \__('Analyse terminée. Aucun contenu à analyser.', 'liens-morts-detector-jlg'),
                    ]);
                    if (function_exists('\\blc_schedule_automated_report_generation')) {
                        $report_context = [
                            'job_id'       => isset($final_status['job_id']) ? (string) $final_status['job_id'] : '',
                            'started_at'   => isset($final_status['started_at']) ? (int) $final_status['started_at'] : 0,
                            'ended_at'     => isset($final_status['ended_at']) ? (int) $final_status['ended_at'] : 0,
                            'completed_at' => isset($final_status['updated_at']) ? (int) $final_status['updated_at'] : time(),
                            'source'       => 'link_scan',
                        ];
                        \blc_schedule_automated_report_generation('link', $report_context);
                    }
                }

                if ($debug_mode) { error_log("--- Fin du scan LIENS (Lot #$batch) ---"); }
            } finally {
                if ($lock_token !== '') {
                    blc_release_link_scan_lock($lock_token);
                }
                wp_reset_postdata();
            }
        }

    public function run($batch = 0, $is_full_scan = false, $bypass_rest_window = false, array $jobContext = [])
    {
        return $this->runBatch($batch, $is_full_scan, $bypass_rest_window, $jobContext);
    }

    /**
     * Normalize the excluded domain configuration into a list of hosts.
     *
     * @param mixed $raw Raw option value.
     *
     * @return string[]
     */
    private function normalizeExcludedDomains($raw): array
    {
        if (is_string($raw)) {
            $candidates = preg_split('/[\r\n,]+/', $raw);
            if (!is_array($candidates)) {
                $candidates = [];
            }
        } elseif (is_array($raw)) {
            $candidates = $raw;
        } elseif (is_scalar($raw)) {
            $candidates = [(string) $raw];
        } else {
            $candidates = [];
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) || is_object($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if ($value === '') {
                continue;
            }

            $host = blc_normalize_remote_host($value);
            if ($host === '') {
                continue;
            }

            $normalized[$host] = true;
        }

        return array_keys($normalized);
    }

    private function scheduleBatch(int $batch, bool $is_full_scan, bool $bypass_rest_window, int $delay_seconds, string $reason): bool
    {
        $delay_seconds = max(0, $delay_seconds);

        $job = [
            'batch'               => (int) $batch,
            'is_full_scan'        => (bool) $is_full_scan,
            'bypass_rest_window'  => (bool) $bypass_rest_window,
            'context'             => $this->jobContext,
        ];

        $scheduled = $this->queueDriver->scheduleBatch($job, $delay_seconds);

        if (!$scheduled) {
            $fallback = wp_schedule_single_event(time() + $delay_seconds, 'blc_check_batch', array(
                $batch,
                $is_full_scan,
                $bypass_rest_window,
                $this->jobContext,
            ));

            if (false !== $fallback) {
                $scheduled = true;
            }
        }

        if (!$scheduled) {
            if (function_exists('error_log')) {
                error_log(sprintf('BLC: Failed to schedule link batch #%d (%s).', $batch, $reason));
            }

            if (function_exists('do_action')) {
                do_action('blc_check_batch_schedule_failed', $batch, $is_full_scan, $bypass_rest_window, $reason, $job);
            }

            return false;
        }

        if (function_exists('do_action')) {
            do_action('blc_queue_job_scheduled', $job, $delay_seconds, $reason, $this->queueDriver);
        }

        return true;
    }

    private function normalizeJobContext(array $jobContext): array
    {
        $normalized = [];

        foreach ($jobContext as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        if (!isset($normalized['soft_404']) || !is_array($normalized['soft_404'])) {
            $normalized['soft_404'] = [];
        }

        if (!isset($normalized['queue_concurrency']) || !is_numeric($normalized['queue_concurrency'])) {
            $normalized['queue_concurrency'] = 1;
        }

        $normalized['queue_concurrency'] = max(1, (int) $normalized['queue_concurrency']);

        return $normalized;
    }

    /**
     * Collect global content sources such as widgets and menus.
     *
     * @param string $site_url Canonical site URL with trailing slash.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectGlobalLinkSources(string $site_url): array
    {
        $sources = [];

        $text_widgets = get_option('widget_text', []);
        if (is_array($text_widgets)) {
            foreach ($text_widgets as $widget_id => $widget_config) {
                if ($widget_id === '_multiwidget' || !is_array($widget_config)) {
                    continue;
                }

                $content = isset($widget_config['text']) ? (string) $widget_config['text'] : '';
                if ($content === '') {
                    continue;
                }

                if (function_exists('do_shortcode')) {
                    $content = do_shortcode($content);
                }

                $title = isset($widget_config['title']) ? trim((string) $widget_config['title']) : '';
                $label = $title !== ''
                    ? sprintf(__('Widget texte « %s »', 'liens-morts-detector-jlg'), $title)
                    : __('Widget texte', 'liens-morts-detector-jlg');

                $sources[] = [
                    'html'          => $content,
                    'post_id'       => 0,
                    'permalink'     => $site_url,
                    'storage_title' => blc_prepare_text_field_for_storage($label),
                    'debug_label'   => $label,
                ];
            }
        }

        $custom_widgets = get_option('widget_custom_html', []);
        if (is_array($custom_widgets)) {
            foreach ($custom_widgets as $widget_id => $widget_config) {
                if ($widget_id === '_multiwidget' || !is_array($widget_config)) {
                    continue;
                }

                $content = isset($widget_config['content']) ? (string) $widget_config['content'] : '';
                if ($content === '') {
                    continue;
                }

                $title = isset($widget_config['title']) ? trim((string) $widget_config['title']) : '';
                $label = $title !== ''
                    ? sprintf(__('Widget HTML personnalisé « %s »', 'liens-morts-detector-jlg'), $title)
                    : __('Widget HTML personnalisé', 'liens-morts-detector-jlg');

                $sources[] = [
                    'html'          => $content,
                    'post_id'       => 0,
                    'permalink'     => $site_url,
                    'storage_title' => blc_prepare_text_field_for_storage($label),
                    'debug_label'   => $label,
                ];
            }
        }

        if (function_exists('wp_get_nav_menus') && function_exists('wp_get_nav_menu_items')) {
            $menus = wp_get_nav_menus();
            if (is_array($menus)) {
                foreach ($menus as $menu) {
                    $menu_name = '';
                    if (is_object($menu)) {
                        $menu_name = isset($menu->name) ? (string) $menu->name : '';
                    } elseif (is_array($menu)) {
                        $menu_name = isset($menu['name']) ? (string) $menu['name'] : '';
                    }

                    $items = wp_get_nav_menu_items($menu);
                    if (!is_array($items)) {
                        continue;
                    }

                    foreach ($items as $item) {
                        if (is_object($item)) {
                            $url = isset($item->url) ? (string) $item->url : '';
                            $title = isset($item->title) ? (string) $item->title : '';
                        } elseif (is_array($item)) {
                            $url = isset($item['url']) ? (string) $item['url'] : '';
                            $title = isset($item['title']) ? (string) $item['title'] : '';
                        } else {
                            continue;
                        }

                        $url = trim($url);
                        if ($url === '' || $url === '#') {
                            continue;
                        }

                        $link_text = $title !== '' ? $title : $url;
                        $label = $menu_name !== ''
                            ? sprintf(__('Menu « %1$s » — %2$s', 'liens-morts-detector-jlg'), $menu_name, $link_text)
                            : sprintf(__('Menu — %s', 'liens-morts-detector-jlg'), $link_text);

                        if (function_exists('esc_attr')) {
                            $href = esc_attr($url);
                        } else {
                            $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                        }
                        $html = sprintf('<a href="%s">%s</a>', $href, $link_text);

                        $sources[] = [
                            'html'          => $html,
                            'post_id'       => 0,
                            'permalink'     => $site_url,
                            'storage_title' => blc_prepare_text_field_for_storage($label),
                            'debug_label'   => $label,
                        ];
                    }
                }
            }
        }

        return $sources;
    }


}
