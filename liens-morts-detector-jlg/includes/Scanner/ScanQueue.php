<?php

namespace JLG\BrokenLinks\Scanner;

use Requests_Exception;
use Requests_Response;
use WP_Error;
use WP_Query;

class ScanQueue {
        /** @var RemoteRequestClient */
        private $remoteRequestClient;

        public function __construct(RemoteRequestClient $remoteRequestClient) {
            $this->remoteRequestClient = $remoteRequestClient;
        }

        public function runBatch($batch = 0, $is_full_scan = false, $bypass_rest_window = false) {
            $remote_request_client = $this->remoteRequestClient;
            global $wpdb;

            $total_batches = 0;
            $processed_batches = 0;
            $remaining_batches = 0;
            $total_items = 0;
            $processed_items = 0;

            // --- 1. Récupération des réglages ---
            $debug_mode = get_option('blc_debug_mode', false);
            if ($debug_mode) { error_log("--- Début du scan LIENS (Lot #$batch) ---"); }

            $current_hook = function_exists('current_filter') ? current_filter() : '';
            if (!$is_full_scan && $current_hook === 'blc_check_links') {
                $is_full_scan = true;
            }

            if ($current_hook === 'blc_check_links') {
                $bypass_rest_window = false;
            }

            $rest_start_hour_option = get_option('blc_rest_start_hour', '08');
            $rest_end_hour_option   = get_option('blc_rest_end_hour', '20');
            $rest_start_hour = (int) blc_normalize_hour_option($rest_start_hour_option, '08');
            $rest_end_hour   = (int) blc_normalize_hour_option($rest_end_hour_option, '20');
            $link_delay_ms   = max(0, (int) get_option('blc_link_delay', 200));
            $batch_delay_s   = max(0, (int) get_option('blc_batch_delay', 60));
            $default_lock_timeout = defined('MINUTE_IN_SECONDS') ? 15 * MINUTE_IN_SECONDS : 900;
            $lock_timeout = apply_filters('blc_link_scan_lock_timeout', $default_lock_timeout);
            if (!is_int($lock_timeout)) {
                $lock_timeout = (int) $lock_timeout;
            }
            if ($lock_timeout < 0) {
                $lock_timeout = 0;
            }

            $lock_token = blc_acquire_link_scan_lock($lock_timeout);
            if ($lock_token === '') {
                if ($current_hook === 'blc_check_links') {
                    if ($debug_mode) { error_log('Analyse de liens déjà en cours, reprogrammation du lot.'); }
                    $retry_delay = max(60, $batch_delay_s);
                    $scheduled = wp_schedule_single_event(time() + $retry_delay, 'blc_check_batch', array($batch, $is_full_scan, $bypass_rest_window));
                    if (false === $scheduled) {
                        error_log(sprintf('BLC: Failed to reschedule link batch #%d while waiting for lock.', $batch));
                        do_action('blc_check_batch_schedule_failed', $batch, $is_full_scan, $bypass_rest_window, 'lock_unavailable');
                    }
                    return;
                }

                \blc_update_link_scan_status([
                    'state'   => 'running',
                    'message' => \__('Une analyse des liens est déjà en cours. Veuillez réessayer plus tard.', 'liens-morts-detector-jlg'),
                ]);

                return new WP_Error(
                    'blc_link_scan_in_progress',
                    __('Une analyse des liens est déjà en cours. Veuillez réessayer plus tard.', 'liens-morts-detector-jlg')
                );
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

            $parallel_requests_dispatcher = apply_filters('blc_parallel_requests_dispatcher', null);
            if (!is_callable($parallel_requests_dispatcher)) {
                $parallel_requests_dispatcher = static function (array $requests) {
                    if (empty($requests)) {
                        return [];
                    }

                    if (!class_exists('Requests')) {
                        if (defined('ABSPATH') && defined('WPINC')) {
                            $requests_class_path = trailingslashit(ABSPATH) . WPINC . '/class-requests.php';
                            if (file_exists($requests_class_path)) {
                                require_once $requests_class_path;
                            }
                        }
                    }

                    return \Requests::request_multiple($requests);
                };
            }
            $last_remote_request_completed_at = 0.0;

            $wait_for_remote_slot = static function () use (&$last_remote_request_completed_at, $link_delay_ms) {
                if ($link_delay_ms <= 0) {
                    return;
                }

                $delay_seconds = $link_delay_ms / 1000;
                if ($last_remote_request_completed_at > 0) {
                    $elapsed = microtime(true) - $last_remote_request_completed_at;
                    $remaining = $delay_seconds - $elapsed;
                    if ($remaining > 0) {
                        usleep((int) round($remaining * 1000000));
                    }
                }
            };

            $mark_remote_request_complete = static function () use (&$last_remote_request_completed_at) {
                $last_remote_request_completed_at = microtime(true);
            };

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

            // --- 2. Contrôles pré-analyse ---
            $current_hour = (int) current_time('H');
            $is_in_rest_window = false;
            if ($rest_start_hour <= $rest_end_hour) {
                $is_in_rest_window = ($current_hour >= $rest_start_hour && $current_hour < $rest_end_hour);
            } else {
                $is_in_rest_window = ($current_hour >= $rest_start_hour || $current_hour < $rest_end_hour);
            }

            if ($is_in_rest_window && !$bypass_rest_window) {
                if ($debug_mode) { error_log("Scan arrêté : dans la plage horaire de repos."); }

                $current_gmt_timestamp = time();
                $timezone = null;

                if (function_exists('wp_timezone')) {
                    $maybe_timezone = wp_timezone();
                    if ($maybe_timezone instanceof \DateTimeZone) {
                        $timezone = $maybe_timezone;
                    }
                }

                if (!$timezone instanceof \DateTimeZone && function_exists('wp_timezone_string')) {
                    $timezone_string = wp_timezone_string();
                    if (is_string($timezone_string) && $timezone_string !== '') {
                        try {
                            $timezone = new \DateTimeZone($timezone_string);
                        } catch (\Exception $e) {
                            $timezone = null;
                        }
                    }
                }

                if (!$timezone instanceof \DateTimeZone) {
                    $offset = (float) get_option('gmt_offset', 0);
                    $offset_seconds = (int) round($offset * 3600);
                    $timezone_name = timezone_name_from_abbr('', $offset_seconds, 0);

                    if (is_string($timezone_name) && $timezone_name !== '') {
                        try {
                            $timezone = new \DateTimeZone($timezone_name);
                        } catch (\Exception $e) {
                            $timezone = null;
                        }
                    }

                    if (!$timezone instanceof \DateTimeZone) {
                        $sign = $offset >= 0 ? '+' : '-';
                        $abs_offset = abs($offset);
                        $hours = (int) floor($abs_offset);
                        $minutes = (int) round(($abs_offset - $hours) * 60);

                        if ($minutes === 60) {
                            $hours += 1;
                            $minutes = 0;
                        }

                        $formatted_offset = sprintf('%s%02d:%02d', $sign, $hours, $minutes);

                        try {
                            $timezone = new \DateTimeZone($formatted_offset);
                        } catch (\Exception $e) {
                            $timezone = new \DateTimeZone('UTC');
                        }
                    }
                }

                if (!$timezone instanceof \DateTimeZone) {
                    $timezone = new \DateTimeZone('UTC');
                }

                $current_datetime = (new \DateTimeImmutable('@' . $current_gmt_timestamp))->setTimezone($timezone);
                $next_run = $current_datetime->setTime($rest_end_hour, 0, 0);

                if ($rest_start_hour > $rest_end_hour) {
                    if ($current_hour >= $rest_start_hour) {
                        $next_run = $next_run->modify('+1 day');
                    } elseif ($next_run <= $current_datetime) {
                        $next_run = $next_run->modify('+1 day');
                    }
                } elseif ($next_run <= $current_datetime) {
                    $next_run = $next_run->modify('+1 day');
                }

                $next_timestamp = $next_run->getTimestamp();

                if ($next_timestamp <= $current_gmt_timestamp) {
                    $next_timestamp = $current_gmt_timestamp + 60;
                }

                $scheduled = wp_schedule_single_event($next_timestamp, 'blc_check_batch', array($batch, $is_full_scan, $bypass_rest_window));
                if (false === $scheduled) {
                    error_log(sprintf('BLC: Failed to schedule link batch #%d during rest window.', $batch));
                    do_action('blc_check_batch_schedule_failed', $batch, $is_full_scan, $bypass_rest_window, 'rest_window');
                }
                if ($lock_token !== '') {
                    blc_release_link_scan_lock($lock_token);
                    $lock_token = '';
                }
                return;
            }

            if (function_exists('sys_getloadavg')) {
                $load_values = sys_getloadavg();

                if (is_array($load_values) && !empty($load_values)) {
                    $current_load = reset($load_values);

                    if (is_numeric($current_load)) {
                        $current_load = (float) $current_load;
                        $max_load_threshold = (float) apply_filters('blc_max_load_threshold', 2.0);

                        if ($max_load_threshold > 0 && $current_load > $max_load_threshold) {
                            $retry_delay = (int) apply_filters('blc_load_retry_delay', 300);
                            if ($retry_delay < 0) { $retry_delay = 0; }

                            if ($debug_mode) { error_log("Scan reporté : charge serveur trop élevée (" . $current_load . ")."); }
                            $scheduled = wp_schedule_single_event(time() + $retry_delay, 'blc_check_batch', array($batch, $is_full_scan, $bypass_rest_window));
                            if (false === $scheduled) {
                                error_log(sprintf('BLC: Failed to schedule link batch #%d after high load.', $batch));
                                do_action('blc_check_batch_schedule_failed', $batch, $is_full_scan, $bypass_rest_window, 'server_load');
                            }
                            if ($lock_token !== '') {
                                blc_release_link_scan_lock($lock_token);
                                $lock_token = '';
                            }
                            return;
                        }
                    } elseif ($debug_mode) {
                        error_log('Contrôle de charge ignoré : la première valeur retournée par sys_getloadavg() n\'est pas numérique.');
                    }
                } elseif ($debug_mode) {
                    error_log('Contrôle de charge ignoré : sys_getloadavg() n\'a pas retourné de données valides.');
                }
            }

            $excluded_domains = [];
            if (!empty($excluded_domains_raw)) {
                $excluded_domains = array_filter(
                    array_map(
                        static function ($domain) {
                            return blc_normalize_remote_host($domain);
                        },
                        explode("\n", $excluded_domains_raw)
                    ),
                    'strlen'
                );
            }

            $scan_cache_context = blc_get_scan_cache_context('link', (int) $batch);
            $scan_cache_data = isset($scan_cache_context['data']) && is_array($scan_cache_context['data'])
                ? $scan_cache_context['data']
                : [];
            $scan_cache_dirty = false;
            $scan_cache_identifier = isset($scan_cache_context['key']) && is_string($scan_cache_context['key'])
                ? $scan_cache_context['key']
                : '';

            // --- 3. Récupération des données et préparation ---
            $batch_size_option = get_option('blc_batch_size', blc_get_link_batch_size_constraints()['default']);
            $batch_size = blc_normalize_link_batch_size($batch_size_option);
            $batch_size = apply_filters('blc_link_batch_size', $batch_size, $batch, $is_full_scan);
            $batch_size = blc_normalize_link_batch_size($batch_size);
            $last_check_time = (int) get_option('blc_last_check_time', 0);
            $table_name      = $wpdb->prefix . 'blc_broken_links';
            $link_dataset_row_types = \blc_get_dataset_row_types('link');
            if (!is_array($link_dataset_row_types) || $link_dataset_row_types === []) {
                $link_dataset_row_types = ['link'];
            } else {
                $link_dataset_row_types = array_values(array_filter(
                    array_map(
                        static function ($type) {
                            return is_string($type) ? trim($type) : '';
                        },
                        $link_dataset_row_types
                    ),
                    static function ($value) {
                        return $value !== '';
                    }
                ));

                if ($link_dataset_row_types === []) {
                    $link_dataset_row_types = ['link'];
                }
            }

            // Limiter la requête aux types de contenus publics (repli sur « post ») tout en conservant la pagination existante.
            $args = [
                'post_type'      => blc_get_scannable_post_types(),
                'post_status'    => blc_get_scannable_post_statuses(),
                'posts_per_page' => $batch_size,
                'paged'          => $batch + 1,
            ];
            if (!$is_full_scan && $last_check_time > 0) {
                $threshold = gmdate('Y-m-d H:i:s', $last_check_time);
                $args['date_query'] = [[
                    'column' => 'post_modified_gmt',
                    'after'  => $threshold,
                ]];
            }

            $wp_query = new WP_Query($args);
            $posts = $wp_query->posts;

            $total_items = isset($wp_query->found_posts) ? max(0, (int) $wp_query->found_posts) : 0;
            $max_pages = isset($wp_query->max_num_pages) ? (int) $wp_query->max_num_pages : 0;
            if ($max_pages <= 0) {
                if ($total_items > 0 && $batch_size > 0) {
                    $max_pages = (int) ceil($total_items / $batch_size);
                } elseif (!empty($posts) && $batch === 0) {
                    $max_pages = 1;
                } else {
                    $max_pages = max(1, $batch + 1);
                }
            }

            $total_batches = max(1, $max_pages);
            $processed_batches = min($total_batches, $batch + 1);
            $remaining_batches = max(0, $total_batches - $processed_batches);
            $posts_in_batch = is_array($posts) ? count($posts) : 0;
            $processed_items = max(0, ($batch * $batch_size) + $posts_in_batch);
            if ($total_items > 0) {
                $processed_items = min($processed_items, $total_items);
            }

            $status_message = sprintf(
                /* translators: 1: current batch number, 2: total batch count. */
                \__('Analyse du lot %1$d sur %2$d en cours…', 'liens-morts-detector-jlg'),
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
                'is_full_scan'      => (bool) $is_full_scan,
                'message'           => $status_message,
            ]);

            $cleanup_size = 0;
            $type_placeholders = implode(',', array_fill(0, count($link_dataset_row_types), '%s'));
            if (method_exists($wpdb, 'get_var')) {
                $cleanup_query = "SELECT SUM(COALESCE(LENGTH(blc.url), 0) + COALESCE(LENGTH(blc.anchor), 0) + COALESCE(LENGTH(blc.post_title), 0))
                         FROM $table_name AS blc
                         LEFT JOIN {$wpdb->posts} AS posts ON blc.post_id = posts.ID
                         WHERE blc.type IN ($type_placeholders) AND posts.ID IS NULL";
                $cleanup_size = (int) $wpdb->get_var($wpdb->prepare($cleanup_query, ...$link_dataset_row_types));
            }
            $cleanup_sql = "DELETE blc FROM $table_name AS blc LEFT JOIN {$wpdb->posts} AS posts ON blc.post_id = posts.ID WHERE blc.type IN ($type_placeholders) AND posts.ID IS NULL";
            $deleted_orphans = $wpdb->query($wpdb->prepare($cleanup_sql, ...$link_dataset_row_types));
            if ($deleted_orphans && $cleanup_size > 0) {
                blc_adjust_dataset_storage_footprint('link', -$cleanup_size);
            }

            $post_ids_in_batch = array_map('intval', wp_list_pluck($posts, 'ID'));
            $post_ids_in_batch = array_values(array_unique(array_filter($post_ids_in_batch, static function ($value) {
                return $value > 0;
            })));

            $scan_run_token = '';
            if (!empty($post_ids_in_batch)) {
                $scan_run_token = blc_generate_scan_run_token();
                $stage_result = blc_stage_dataset_refresh($table_name, $link_dataset_row_types, $scan_run_token, $post_ids_in_batch);
                if (is_wp_error($stage_result)) {
                    if ($lock_token !== '') {
                        blc_release_link_scan_lock($lock_token);
                        $lock_token = '';
                    }
                    return $stage_result;
                }
            }

            $global_link_sources = [];
            $register_global_source = static function (array $source) use (&$global_link_sources) {
                $html = isset($source['html']) ? (string) $source['html'] : '';
                if (trim($html) === '' || !\blc_html_contains_reference_candidates($html)) {
                    return;
                }

                $label = isset($source['debug_label']) ? (string) $source['debug_label'] : '';
                if ($label === '') {
                    $label = __('Source hors publication', 'liens-morts-detector-jlg');
                }

                $global_link_sources[] = [
                    'html'          => $html,
                    'post_id'       => isset($source['post_id']) ? (int) $source['post_id'] : 0,
                    'permalink'     => isset($source['permalink']) ? (string) $source['permalink'] : '',
                    'storage_title' => isset($source['storage_title']) && $source['storage_title'] !== ''
                        ? (string) $source['storage_title']
                        : blc_prepare_text_field_for_storage($label),
                    'debug_label'   => $label,
                ];
            };

            if (function_exists('get_option')) {
                $raw_widgets = get_option('widget_text', []);
                if (is_array($raw_widgets)) {
                    foreach ($raw_widgets as $widget_id => $widget_data) {
                        if (!is_array($widget_data)) {
                            continue;
                        }

                        $widget_text = isset($widget_data['text']) ? (string) $widget_data['text'] : '';
                        if ($widget_text === '') {
                            continue;
                        }

                        if (function_exists('do_shortcode')) {
                            $widget_text = do_shortcode($widget_text);
                        }

                        if (trim($widget_text) === '') {
                            continue;
                        }

                        $widget_title = '';
                        if (isset($widget_data['title'])) {
                            $widget_title = (string) $widget_data['title'];
                        }
                        if ($widget_title === '' && isset($widget_data['name'])) {
                            $widget_title = (string) $widget_data['name'];
                        }

                        if ($widget_title === '') {
                            $label = sprintf(__('Widget texte #%s', 'liens-morts-detector-jlg'), $widget_id);
                        } else {
                            $label = sprintf(__('Widget texte « %s »', 'liens-morts-detector-jlg'), $widget_title);
                        }

                        $register_global_source([
                            'html'          => $widget_text,
                            'storage_title' => blc_prepare_text_field_for_storage($label),
                            'debug_label'   => $label,
                        ]);
                    }
                }

                $custom_html_widgets = get_option('widget_custom_html', []);
                if (is_array($custom_html_widgets)) {
                    foreach ($custom_html_widgets as $widget_id => $widget_data) {
                        if (!is_array($widget_data)) {
                            continue;
                        }

                        $widget_content = isset($widget_data['content']) ? (string) $widget_data['content'] : '';
                        if ($widget_content === '') {
                            continue;
                        }

                        if (function_exists('do_shortcode')) {
                            $widget_content = do_shortcode($widget_content);
                        }

                        if (trim($widget_content) === '') {
                            continue;
                        }

                        $widget_title = '';
                        if (isset($widget_data['title'])) {
                            $widget_title = (string) $widget_data['title'];
                        }
                        if ($widget_title === '' && isset($widget_data['name'])) {
                            $widget_title = (string) $widget_data['name'];
                        }

                        if ($widget_title === '') {
                            $label = sprintf(__('Widget HTML personnalisé #%s', 'liens-morts-detector-jlg'), $widget_id);
                        } else {
                            $label = sprintf(__('Widget HTML personnalisé « %s »', 'liens-morts-detector-jlg'), $widget_title);
                        }

                        $register_global_source([
                            'html'          => $widget_content,
                            'storage_title' => blc_prepare_text_field_for_storage($label),
                            'debug_label'   => $label,
                        ]);
                    }
                }
            }

            if (function_exists('wp_get_nav_menus') && function_exists('wp_get_nav_menu_items')) {
                $nav_menus = wp_get_nav_menus();
                if (!is_array($nav_menus)) {
                    $nav_menus = [];
                }

                foreach ($nav_menus as $menu) {
                    $menu_name = '';
                    $menu_id   = 0;

                    if (is_object($menu)) {
                        $menu_name = isset($menu->name) ? (string) $menu->name : '';
                        if ($menu_name === '' && isset($menu->slug)) {
                            $menu_name = (string) $menu->slug;
                        }
                        $menu_id = isset($menu->term_id) ? (int) $menu->term_id : 0;
                    } elseif (is_array($menu)) {
                        $menu_name = isset($menu['name']) ? (string) $menu['name'] : '';
                        if ($menu_name === '' && isset($menu['slug'])) {
                            $menu_name = (string) $menu['slug'];
                        }
                        $menu_id = isset($menu['term_id']) ? (int) $menu['term_id'] : 0;
                    }

                    $menu_items = wp_get_nav_menu_items($menu_id !== 0 ? $menu_id : $menu);
                    if (!is_array($menu_items)) {
                        continue;
                    }

                    foreach ($menu_items as $menu_item) {
                        $item_url = '';
                        $item_title = '';

                        if (is_object($menu_item)) {
                            $item_url = isset($menu_item->url) ? (string) $menu_item->url : '';
                            if ($item_title === '' && isset($menu_item->title)) {
                                $item_title = (string) $menu_item->title;
                            }
                            if ($item_title === '' && isset($menu_item->post_title)) {
                                $item_title = (string) $menu_item->post_title;
                            }
                        } elseif (is_array($menu_item)) {
                            $item_url = isset($menu_item['url']) ? (string) $menu_item['url'] : '';
                            if ($item_title === '' && isset($menu_item['title'])) {
                                $item_title = (string) $menu_item['title'];
                            }
                            if ($item_title === '' && isset($menu_item['post_title'])) {
                                $item_title = (string) $menu_item['post_title'];
                            }
                        }

                        $item_url = trim($item_url);
                        if ($item_url === '' || $item_url === '#') {
                            continue;
                        }

                        if ($item_title === '') {
                            $item_title = $item_url;
                        }

                        $link_html = sprintf('<a href="%s">%s</a>', esc_url($item_url), esc_html($item_title));

                        if ($menu_name !== '') {
                            $label = sprintf(__('Menu « %1$s » — %2$s', 'liens-morts-detector-jlg'), $menu_name, $item_title);
                        } else {
                            $label = sprintf(__('Menu — %s', 'liens-morts-detector-jlg'), $item_title);
                        }

                        $register_global_source([
                            'html'          => $link_html,
                            'storage_title' => blc_prepare_text_field_for_storage($label),
                            'debug_label'   => $label,
                        ]);
                    }
                }
            }

            if (!empty($global_link_sources)) {
                if ($scan_run_token === '') {
                    $scan_run_token = blc_generate_scan_run_token();
                }

                $stage_result = blc_stage_dataset_refresh($table_name, $link_dataset_row_types, $scan_run_token, [0]);
                if (is_wp_error($stage_result)) {
                    if ($lock_token !== '') {
                        blc_release_link_scan_lock($lock_token);
                        $lock_token = '';
                    }
                    return $stage_result;
                }
            }

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

            $remote_request_queue = new class (
                $remote_request_client,
                $max_concurrent_requests,
                $wait_for_remote_slot,
                $mark_remote_request_complete,
                $parallel_requests_dispatcher
            ) {
                /** @var RemoteRequestClient */
                private $client;

                /** @var int */
                private $concurrency;

                /** @var callable */
                private $waitCallback;

                /** @var callable */
                private $completeCallback;

                /** @var callable */
                private $dispatcher;

                /** @var array<int, array<string, mixed>> */
                private $pending = [];

                public function __construct(
                    RemoteRequestClient $client,
                    int $concurrency,
                    callable $waitCallback,
                    callable $completeCallback,
                    callable $dispatcher
                ) {
                    $this->client = $client;
                    $this->concurrency = max(1, $concurrency);
                    $this->waitCallback = $waitCallback;
                    $this->completeCallback = $completeCallback;
                    $this->dispatcher = $dispatcher;
                }

                public function enqueue(
                    string $url,
                    array $headArgs,
                    array $getArgs,
                    string $scanMethod,
                    array $temporaryStatuses,
                    callable $callback
                ): void {
                    $this->pending[] = [
                        'url'                => $url,
                        'head_args'          => $headArgs,
                        'get_args'           => $getArgs,
                        'scan_method'        => $scanMethod,
                        'temporary_statuses' => $temporaryStatuses,
                        'callback'           => $callback,
                    ];

                    $this->dispatch(false);
                }

                public function drain(): void
                {
                    $this->dispatch(true);
                }

                private function dispatch(bool $force): void
                {
                    while (!empty($this->pending) && ($force || count($this->pending) >= $this->concurrency)) {
                        $batch = array_splice($this->pending, 0, min($this->concurrency, count($this->pending)));
                        $this->executeBatch($batch);
                    }
                }

                /**
                 * @param array<int, array<string, mixed>> $batch
                 */
                private function executeBatch(array $batch): void
                {
                    if (empty($batch)) {
                        return;
                    }

                    $headRequests = [];
                    foreach ($batch as $index => $job) {
                        $requestKey = 'head-' . $index;
                        $headRequests[$requestKey] = $this->buildRequest($job['url'], 'HEAD', $job['head_args']);
                    }

                    $headResponses = $this->sendRequests($headRequests);

                    $getJobs = [];

                    foreach ($batch as $index => $job) {
                        $requestKey = 'head-' . $index;
                        $headResponse = $headResponses[$requestKey] ?? new WP_Error('blc_missing_head_response', 'Missing HEAD response.');

                        $needsGetFallback = false;
                        $fallbackDueToTemporaryStatus = false;
                        $headRequestDisallowed = false;

                        if ($job['scan_method'] === 'precise') {
                            if (is_wp_error($headResponse)) {
                                $needsGetFallback = true;
                            } else {
                                $headStatus = (int) $this->client->responseCode($headResponse);
                                if (in_array($headStatus, $job['temporary_statuses'], true)) {
                                    $needsGetFallback = true;
                                    $fallbackDueToTemporaryStatus = true;
                                } elseif (in_array($headStatus, [403, 405, 501], true)) {
                                    $needsGetFallback = true;
                                }
                            }
                        } else {
                            if (!is_wp_error($headResponse)) {
                                $headStatus = (int) $this->client->responseCode($headResponse);
                                if (in_array($headStatus, [403, 405, 501], true)) {
                                    $needsGetFallback = true;
                                    $headRequestDisallowed = true;
                                }
                            }
                        }

                        if ($needsGetFallback) {
                            $getJobs[] = [
                                'index'                      => $index,
                                'job'                        => $job,
                                'fallback_due_to_temporary'  => $fallbackDueToTemporaryStatus,
                                'head_request_disallowed'    => $headRequestDisallowed,
                            ];
                        } else {
                            $this->triggerCallback($job, $headResponse, $headRequestDisallowed, $fallbackDueToTemporaryStatus);
                        }
                    }

                    if (empty($getJobs)) {
                        return;
                    }

                    $getRequests = [];
                    foreach ($getJobs as $entry) {
                        $requestKey = 'get-' . $entry['index'];
                        $getRequests[$requestKey] = $this->buildRequest($entry['job']['url'], 'GET', $entry['job']['get_args']);
                    }

                    $getResponses = $this->sendRequests($getRequests);

                    foreach ($getJobs as $entry) {
                        $requestKey = 'get-' . $entry['index'];
                        $response = $getResponses[$requestKey] ?? new WP_Error('blc_missing_get_response', 'Missing GET response.');
                        $this->triggerCallback(
                            $entry['job'],
                            $response,
                            $entry['head_request_disallowed'],
                            $entry['fallback_due_to_temporary']
                        );
                    }
                }

                private function triggerCallback(array $job, $response, bool $headRequestDisallowed, bool $fallbackDueToTemporaryStatus): void
                {
                    $callback = $job['callback'];
                    $callback($response, $headRequestDisallowed, $fallbackDueToTemporaryStatus);
                }

                private function buildRequest(string $url, string $method, array $args): array
                {
                    $headers = [];
                    if (isset($args['user-agent'])) {
                        $headers['user-agent'] = (string) $args['user-agent'];
                    }

                    $options = [];
                    if (isset($args['timeout'])) {
                        $options['timeout'] = (float) $args['timeout'];
                    }
                    if (isset($args['redirection'])) {
                        $options['redirects'] = (int) $args['redirection'];
                    }
                    if (isset($args['limit_response_size'])) {
                        $options['max_bytes'] = (int) $args['limit_response_size'];
                    }

                    return [
                        'url'     => $url,
                        'type'    => $method,
                        'headers' => $headers,
                        'data'    => $args['body'] ?? null,
                        'options' => $options,
                    ];
                }

                /**
                 * @param array<string, array<string, mixed>> $requests
                 * @return array<string, mixed>
                 */
                private function sendRequests(array $requests): array
                {
                    if (empty($requests)) {
                        return [];
                    }

                    $wait = $this->waitCallback;
                    foreach ($requests as $_) {
                        $wait();
                    }

                    $dispatcher = $this->dispatcher;
                    $responses = $dispatcher($requests);

                    $normalized = [];
                    foreach ($requests as $key => $_request) {
                        $raw = $responses[$key] ?? null;
                        $normalized[$key] = $this->normalizeResponse($raw);
                        $complete = $this->completeCallback;
                        $complete();
                    }

                    return $normalized;
                }

                private function normalizeResponse($raw)
                {
                    if ($raw instanceof WP_Error) {
                        return $raw;
                    }

                    if ($raw instanceof Requests_Response) {
                        return [
                            'headers'  => $raw->headers->getAll(),
                            'body'     => $raw->body,
                            'response' => [
                                'code'    => $raw->status_code,
                                'message' => $raw->status_text,
                            ],
                        ];
                    }

                    if ($raw instanceof Requests_Exception) {
                        $code = method_exists($raw, 'getCode') ? (int) $raw->getCode() : 0;
                        return new WP_Error('http_request_failed', $raw->getMessage(), ['status' => $code]);
                    }

                    if (is_array($raw)) {
                        return $raw;
                    }

                    if ($raw === null) {
                        return new WP_Error('http_request_failed', 'Empty HTTP response.');
                    }

                    return $raw;
                }
            };

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
                $remote_request_queue
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
                    $remote_request_queue
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
                        $should_use_cache
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

                                $scheduled = wp_schedule_single_event(
                                    time() + $retry_delay,
                                    'blc_check_batch',
                                    array($batch, $is_full_scan, $bypass_rest_window)
                                );
                                if (false === $scheduled) {
                                    error_log(sprintf('BLC: Failed to schedule temporary retry for link batch #%d.', $batch));
                                    do_action('blc_check_batch_schedule_failed', $batch, $is_full_scan, $bypass_rest_window, 'temporary_retry');
                                } else {
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
                            function ($response, bool $head_request_disallowed, bool $fallback_due_to_temporary_status) use (
                                &$should_retry_later,
                                &$should_insert_broken_link,
                                &$response_code,
                                &$detected_target_for_storage,
                                $remote_request_client,
                                $temporary_http_statuses,
                                $normalized_url,
                                $finalize_remote_outcome
                            ) {
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
                                    $response_code = (int) $remote_request_client->responseCode($response);
                                    if ($response_code >= 400) {
                                        if (in_array($response_code, $temporary_http_statuses, true)) {
                                            $should_retry_later = true;
                                        } else {
                                            $should_insert_broken_link = true;
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
                    $scheduled = wp_schedule_single_event(time() + $batch_delay_s, 'blc_check_batch', array($batch + 1, $is_full_scan, $bypass_rest_window));
                    if (false === $scheduled) {
                        error_log(sprintf('BLC: Failed to schedule next link batch #%d.', $batch + 1));
                        do_action('blc_check_batch_schedule_failed', $batch + 1, $is_full_scan, $bypass_rest_window, 'next_batch');
                        \blc_update_link_scan_status([
                            'state'           => 'failed',
                            'last_error'      => \__('Impossible de programmer le lot suivant.', 'liens-morts-detector-jlg'),
                            'message'         => \__('Impossible de programmer le lot suivant.', 'liens-morts-detector-jlg'),
                            'total_items'     => $total_items,
                            'processed_items' => $processed_items,
                        ]);
                    }
                    if (false !== $scheduled) {
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
                    \blc_update_link_scan_status([
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
                }

                if ($debug_mode) { error_log("--- Fin du scan LIENS (Lot #$batch) ---"); }
            } finally {
                if ($lock_token !== '') {
                    blc_release_link_scan_lock($lock_token);
                }
                wp_reset_postdata();
            }
        }

    public function run($batch = 0, $is_full_scan = false, $bypass_rest_window = false)
    {
        return $this->runBatch($batch, $is_full_scan, $bypass_rest_window);
    }
}



