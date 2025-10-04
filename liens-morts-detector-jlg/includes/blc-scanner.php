<?php

if (!defined('ABSPATH')) exit;


require_once __DIR__ . '/Scanner/RemoteRequestClient.php';

if (!function_exists('blc_get_default_link_scan_status')) {
    /**
     * Retrieve the default link scan status structure.
     *
     * @return array<string, mixed>
     */
    function blc_get_default_link_scan_status() {
        return [
            'state'              => 'idle',
            'current_batch'      => 0,
            'processed_batches'  => 0,
            'total_batches'      => 0,
            'remaining_batches'  => 0,
            'is_full_scan'       => false,
            'message'            => '',
            'last_error'         => '',
            'started_at'         => 0,
            'ended_at'           => 0,
            'updated_at'         => 0,
            'total_items'        => 0,
            'processed_items'    => 0,
        ];
    }
}

if (!function_exists('blc_get_link_scan_status')) {
    /**
     * Return the current link scan status from the database.
     *
     * @return array<string, mixed>
     */
    function blc_get_link_scan_status() {
        $status = get_option('blc_link_scan_status', []);
        if (!is_array($status)) {
            $status = [];
        }

        $defaults = blc_get_default_link_scan_status();
        $status = array_merge($defaults, array_intersect_key($status, $defaults));

        $allowed_states = ['idle', 'queued', 'running', 'completed', 'failed', 'cancelled'];
        $state = isset($status['state']) ? sanitize_key((string) $status['state']) : 'idle';
        if ($state === '' || !in_array($state, $allowed_states, true)) {
            $state = 'idle';
        }
        $status['state'] = $state;

        foreach (['current_batch', 'processed_batches', 'total_batches', 'remaining_batches', 'started_at', 'ended_at', 'updated_at', 'total_items', 'processed_items'] as $numeric_key) {
            $status[$numeric_key] = isset($status[$numeric_key]) ? max(0, (int) $status[$numeric_key]) : 0;
        }

        $status['is_full_scan'] = !empty($status['is_full_scan']);
        $status['message'] = isset($status['message']) && is_string($status['message']) ? $status['message'] : '';
        $status['last_error'] = isset($status['last_error']) && is_string($status['last_error']) ? $status['last_error'] : '';

        if ($status['state'] === 'completed' && $status['ended_at'] === 0 && $status['updated_at'] > 0) {
            $status['ended_at'] = $status['updated_at'];
        }

        return $status;
    }
}

if (!function_exists('blc_update_link_scan_status')) {
    /**
     * Persist link scan status updates.
     *
     * @param array<string, mixed> $changes Associative array of changes to merge into the status payload.
     *
     * @return array<string, mixed> Updated status payload.
     */
    function blc_update_link_scan_status(array $changes) {
        $status = blc_get_link_scan_status();
        $previous_state = $status['state'];

        foreach ($changes as $key => $value) {
            switch ($key) {
                case 'state':
                    $new_state = sanitize_key((string) $value);
                    if ($new_state === '' || !in_array($new_state, ['idle', 'queued', 'running', 'completed', 'failed', 'cancelled'], true)) {
                        $new_state = 'idle';
                    }
                    $status['state'] = $new_state;
                    break;
                case 'message':
                    $status['message'] = is_string($value) ? $value : '';
                    break;
                case 'last_error':
                    $status['last_error'] = is_string($value) ? $value : '';
                    break;
                case 'is_full_scan':
                    $status['is_full_scan'] = (bool) $value;
                    break;
                case 'current_batch':
                case 'processed_batches':
                case 'total_batches':
                case 'remaining_batches':
                case 'started_at':
                case 'ended_at':
                case 'updated_at':
                case 'total_items':
                case 'processed_items':
                    $status[$key] = max(0, (int) $value);
                    break;
                default:
                    $status[$key] = $value;
                    break;
            }
        }

        $now = time();
        if ($status['state'] === 'running' && ($status['started_at'] === 0 || $previous_state !== 'running')) {
            $status['started_at'] = $now;
        }

        if ($status['state'] === 'completed' || $status['state'] === 'failed' || $status['state'] === 'cancelled') {
            $status['ended_at'] = $now;
        } elseif ($status['state'] === 'idle' && !array_key_exists('ended_at', $changes)) {
            $status['ended_at'] = 0;
            if (!array_key_exists('started_at', $changes)) {
                $status['started_at'] = 0;
            }
        }

        $status['updated_at'] = $now;

        update_option('blc_link_scan_status', $status, false);

        return $status;
    }
}

if (!function_exists('blc_reset_link_scan_status')) {
    /**
     * Clear the stored link scan status.
     *
     * @return void
     */
    function blc_reset_link_scan_status() {
        delete_option('blc_link_scan_status');
    }
}

if (!function_exists('blc_get_next_link_batch_timestamp')) {
    /**
     * Retrieve the next scheduled timestamp for link scan batches.
     *
     * @return int
     */
    function blc_get_next_link_batch_timestamp() {
        if (!function_exists('wp_next_scheduled')) {
            return 0;
        }

        $candidates = [];

        $next_batch = wp_next_scheduled('blc_check_batch');
        if ($next_batch) {
            $candidates[] = (int) $next_batch;
        }

        $manual_batch = wp_next_scheduled('blc_manual_check_batch');
        if ($manual_batch) {
            $candidates[] = (int) $manual_batch;
        }

        if ($candidates === []) {
            return 0;
        }

        return (int) min($candidates);
    }
}

if (!function_exists('blc_get_link_scan_status_payload')) {
    /**
     * Build the enriched scan status payload shared by REST and AJAX endpoints.
     *
     * @return array<string, mixed>
     */
    function blc_get_link_scan_status_payload() {
        $status = blc_get_link_scan_status();

        $default_lock_timeout = defined('MINUTE_IN_SECONDS') ? 15 * MINUTE_IN_SECONDS : 900;
        $lock_timeout = apply_filters('blc_link_scan_lock_timeout', $default_lock_timeout);
        if (!is_int($lock_timeout)) {
            $lock_timeout = (int) $lock_timeout;
        }
        if ($lock_timeout < 0) {
            $lock_timeout = 0;
        }

        $lock_state = blc_get_link_scan_lock_state();
        $status['lock_active'] = blc_is_link_scan_lock_active($lock_state, $lock_timeout);
        $status['lock_timestamp'] = isset($lock_state['locked_at']) ? (int) $lock_state['locked_at'] : 0;
        $status['lock_timeout'] = $lock_timeout;
        $status['next_batch_timestamp'] = blc_get_next_link_batch_timestamp();

        if (!isset($status['lock_active'])) {
            $status['lock_active'] = false;
        }

        return $status;
    }
}

if (!function_exists('blc_get_default_image_scan_status')) {
    /**
     * Retrieve the default image scan status structure.
     *
     * @return array<string, mixed>
     */
    function blc_get_default_image_scan_status() {
        return [
            'state'             => 'idle',
            'current_batch'     => 0,
            'processed_batches' => 0,
            'total_batches'     => 0,
            'remaining_batches' => 0,
            'is_full_scan'      => true,
            'message'           => '',
            'last_error'        => '',
            'started_at'        => 0,
            'ended_at'          => 0,
            'updated_at'        => 0,
            'total_items'       => 0,
            'processed_items'   => 0,
        ];
    }
}

if (!function_exists('blc_get_image_scan_status')) {
    /**
     * Return the current image scan status from the database.
     *
     * @return array<string, mixed>
     */
    function blc_get_image_scan_status() {
        $status = get_option('blc_image_scan_status', []);
        if (!is_array($status)) {
            $status = [];
        }

        $defaults = blc_get_default_image_scan_status();
        $status = array_merge($defaults, array_intersect_key($status, $defaults));

        $allowed_states = ['idle', 'queued', 'running', 'completed', 'failed', 'cancelled'];
        $state = isset($status['state']) ? sanitize_key((string) $status['state']) : 'idle';
        if ($state === '' || !in_array($state, $allowed_states, true)) {
            $state = 'idle';
        }
        $status['state'] = $state;

        foreach (['current_batch', 'processed_batches', 'total_batches', 'remaining_batches', 'started_at', 'ended_at', 'updated_at', 'total_items', 'processed_items'] as $numeric_key) {
            $status[$numeric_key] = isset($status[$numeric_key]) ? max(0, (int) $status[$numeric_key]) : 0;
        }

        $status['is_full_scan'] = true;
        $status['message'] = isset($status['message']) && is_string($status['message']) ? $status['message'] : '';
        $status['last_error'] = isset($status['last_error']) && is_string($status['last_error']) ? $status['last_error'] : '';

        if ($status['state'] === 'completed' && $status['ended_at'] === 0 && $status['updated_at'] > 0) {
            $status['ended_at'] = $status['updated_at'];
        }

        return $status;
    }
}

if (!function_exists('blc_update_image_scan_status')) {
    /**
     * Persist image scan status updates.
     *
     * @param array<string, mixed> $changes Associative array of changes to merge into the status payload.
     *
     * @return array<string, mixed> Updated status payload.
     */
    function blc_update_image_scan_status(array $changes) {
        $status = blc_get_image_scan_status();
        $previous_state = $status['state'];

        foreach ($changes as $key => $value) {
            switch ($key) {
                case 'state':
                    $new_state = sanitize_key((string) $value);
                    if ($new_state === '' || !in_array($new_state, ['idle', 'queued', 'running', 'completed', 'failed', 'cancelled'], true)) {
                        $new_state = 'idle';
                    }
                    $status['state'] = $new_state;
                    break;
                case 'message':
                    $status['message'] = is_string($value) ? $value : '';
                    break;
                case 'last_error':
                    $status['last_error'] = is_string($value) ? $value : '';
                    break;
                case 'current_batch':
                case 'processed_batches':
                case 'total_batches':
                case 'remaining_batches':
                case 'started_at':
                case 'ended_at':
                case 'updated_at':
                case 'total_items':
                case 'processed_items':
                    $status[$key] = max(0, (int) $value);
                    break;
                case 'is_full_scan':
                    $status['is_full_scan'] = (bool) $value;
                    break;
                default:
                    $status[$key] = $value;
                    break;
            }
        }

        $now = time();
        if ($status['state'] === 'running' && ($status['started_at'] === 0 || $previous_state !== 'running')) {
            $status['started_at'] = $now;
        }

        if ($status['state'] === 'completed' || $status['state'] === 'failed' || $status['state'] === 'cancelled') {
            $status['ended_at'] = $now;
        } elseif ($status['state'] === 'idle' && !array_key_exists('ended_at', $changes)) {
            $status['ended_at'] = 0;
            if (!array_key_exists('started_at', $changes)) {
                $status['started_at'] = 0;
            }
        }

        $status['updated_at'] = $now;
        $status['is_full_scan'] = true;

        update_option('blc_image_scan_status', $status, false);

        return $status;
    }
}

if (!function_exists('blc_reset_image_scan_status')) {
    /**
     * Clear the stored image scan status.
     *
     * @return void
     */
    function blc_reset_image_scan_status() {
        delete_option('blc_image_scan_status');
    }
}

if (!function_exists('blc_get_next_image_batch_timestamp')) {
    /**
     * Retrieve the next scheduled timestamp for image scan batches.
     *
     * @return int
     */
    function blc_get_next_image_batch_timestamp() {
        if (!function_exists('wp_next_scheduled')) {
            return 0;
        }

        $next_batch = wp_next_scheduled('blc_check_image_batch');
        if ($next_batch) {
            return (int) $next_batch;
        }

        return 0;
    }
}

if (!function_exists('blc_get_image_scan_status_payload')) {
    /**
     * Build the enriched scan status payload for image scans.
     *
     * @return array<string, mixed>
     */
    function blc_get_image_scan_status_payload() {
        $status = blc_get_image_scan_status();

        $default_lock_timeout = defined('MINUTE_IN_SECONDS') ? 15 * MINUTE_IN_SECONDS : 900;
        $lock_timeout = apply_filters('blc_image_scan_lock_timeout', $default_lock_timeout);
        if (!is_int($lock_timeout)) {
            $lock_timeout = (int) $lock_timeout;
        }
        if ($lock_timeout < 0) {
            $lock_timeout = 0;
        }

        $lock_state = blc_get_image_scan_lock_state();
        $status['lock_active'] = blc_is_image_scan_lock_active($lock_state, $lock_timeout);
        $status['lock_timestamp'] = isset($lock_state['locked_at']) ? (int) $lock_state['locked_at'] : 0;
        $status['lock_timeout'] = $lock_timeout;
        $status['next_batch_timestamp'] = blc_get_next_image_batch_timestamp();

        if (!isset($status['lock_active'])) {
            $status['lock_active'] = false;
        }

        return $status;
    }
}

if (!function_exists('blc_register_scan_status_rest_route')) {
    /**
     * Register REST API routes for scan status retrieval.
     *
     * @return void
     */
    function blc_register_scan_status_rest_route() {
        if (!function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'blc/v1',
            '/scan-status',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => 'blc_rest_get_scan_status',
                'permission_callback' => 'blc_rest_scan_status_permissions',
                'args'                => [
                    'type' => [
                        'type'    => 'string',
                        'enum'    => ['link', 'image'],
                        'default' => 'link',
                    ],
                ],
            ]
        );
    }
}

if (!function_exists('blc_rest_scan_status_permissions')) {
    /**
     * Check permissions for scan status REST requests.
     *
     * @return bool
     */
    function blc_rest_scan_status_permissions() {
        return current_user_can('manage_options');
    }
}

if (!function_exists('blc_rest_get_scan_status')) {
    /**
     * REST callback returning the current scan status payload.
     *
     * @param \WP_REST_Request $request REST request instance.
     *
     * @return \WP_REST_Response|array<string, mixed>
     */
    function blc_rest_get_scan_status(\WP_REST_Request $request) {
        $type = $request->get_param('type');
        $type = is_string($type) ? strtolower($type) : 'link';

        if ($type === 'image') {
            return rest_ensure_response(blc_get_image_scan_status_payload());
        }

        return rest_ensure_response(blc_get_link_scan_status_payload());
    }
}

add_action('rest_api_init', 'blc_register_scan_status_rest_route');

require_once __DIR__ . '/Scanner/ScanQueue.php';
require_once __DIR__ . '/Scanner/LinkScanController.php';


if (!class_exists('ImageScanQueue')) {
    class ImageScanQueue {
        public function run($batch = 0, $is_full_scan = true) {
         // Une analyse d'images est toujours complète
            global $wpdb;
            $debug_mode = get_option('blc_debug_mode', false);
            if ($debug_mode) { error_log("--- Début du scan IMAGES (Lot #$batch) ---"); }

            $table_name = $wpdb->prefix . 'blc_broken_links';
            $remote_image_scan_enabled = (bool) get_option('blc_remote_image_scan_enabled', false);
            $image_dataset_row_types = blc_get_dataset_row_types('image');
            $batch_delay_s = max(0, (int) get_option('blc_batch_delay', 60));
            $default_lock_timeout = defined('MINUTE_IN_SECONDS') ? 15 * MINUTE_IN_SECONDS : 900;
            $lock_timeout = apply_filters('blc_image_scan_lock_timeout', $default_lock_timeout);
            if (!is_int($lock_timeout)) {
                $lock_timeout = (int) $lock_timeout;
            }
            if ($lock_timeout < 0) {
                $lock_timeout = 0;
            }

            $lock_token = '';
            if ($batch === 0) {
                $lock_token = blc_acquire_image_scan_lock($lock_timeout);
                if ($lock_token === '') {
                    if ($debug_mode) { error_log('Analyse d\'images déjà en cours, reprogrammation du lot initial.'); }
                    $retry_delay = max(60, $batch_delay_s);
                    $scheduled = wp_schedule_single_event(time() + $retry_delay, 'blc_check_image_batch', array(0, true));
                    if (false === $scheduled) {
                        error_log('BLC: Failed to schedule image batch #0 while waiting for lock.');
                        do_action('blc_check_image_batch_schedule_failed', 0, true, 'initial_lock_retry');
                    }
                    return;
                }
            } else {
                $stored_token = get_option('blc_image_scan_lock_token', '');
                if (!is_string($stored_token)) {
                    $stored_token = '';
                }
                $lock_state = blc_get_image_scan_lock_state();
                $active_token = isset($lock_state['token']) ? (string) $lock_state['token'] : '';
                $lock_active  = blc_is_image_scan_lock_active($lock_state, $lock_timeout);

                if ($stored_token === '' || $active_token === '') {
                    $lock_token = blc_acquire_image_scan_lock($lock_timeout);
                    if ($lock_token === '') {
                        if ($debug_mode) { error_log('Impossible de reprendre le scan d\'images (verrou indisponible).'); }
                        $retry_delay = max(60, $batch_delay_s);
                        $scheduled = wp_schedule_single_event(time() + $retry_delay, 'blc_check_image_batch', array($batch, $is_full_scan));
                        if (false === $scheduled) {
                            error_log(sprintf('BLC: Failed to reschedule image batch #%d due to missing lock token.', $batch));
                            do_action('blc_check_image_batch_schedule_failed', $batch, $is_full_scan, 'missing_lock_token');
                        }
                        return;
                    }
                } elseif (!$lock_active) {
                    $lock_token = blc_acquire_image_scan_lock($lock_timeout);
                    if ($lock_token === '') {
                        if ($debug_mode) { error_log('Impossible de reprendre le scan d\'images (verrou expiré).'); }
                        $retry_delay = max(60, $batch_delay_s);
                        $scheduled = wp_schedule_single_event(time() + $retry_delay, 'blc_check_image_batch', array($batch, $is_full_scan));
                        if (false === $scheduled) {
                            error_log(sprintf('BLC: Failed to reschedule image batch #%d after lock expiration.', $batch));
                            do_action('blc_check_image_batch_schedule_failed', $batch, $is_full_scan, 'expired_lock');
                        }
                        return;
                    }
                } elseif ($active_token !== $stored_token) {
                    if ($lock_active) {
                        if ($debug_mode) { error_log('Analyse d\'images ignorée : un autre processus détient le verrou.'); }
                        return;
                    }

                    $lock_token = blc_acquire_image_scan_lock($lock_timeout);
                    if ($lock_token === '') {
                        if ($debug_mode) { error_log('Impossible de rafraîchir le verrou du scan d\'images.'); }
                        $retry_delay = max(60, $batch_delay_s);
                        $scheduled = wp_schedule_single_event(time() + $retry_delay, 'blc_check_image_batch', array($batch, $is_full_scan));
                        if (false === $scheduled) {
                            error_log(sprintf('BLC: Failed to reschedule image batch #%d after failing to refresh lock.', $batch));
                            do_action('blc_check_image_batch_schedule_failed', $batch, $is_full_scan, 'lock_refresh');
                        }
                        return;
                    }
                } else {
                    $lock_token = $stored_token;
                    blc_refresh_image_scan_lock($lock_token, $lock_timeout);
                }
            }

            blc_update_image_scan_status([
                'state'         => 'running',
                'current_batch' => (int) $batch,
                'is_full_scan'  => true,
                'message'       => __('Analyse des images en cours…', 'liens-morts-detector-jlg'),
            ]);

            $batch_size_limits = function_exists('blc_get_batch_size_constraints')
                ? blc_get_batch_size_constraints()
                : array('min' => 5, 'max' => 100, 'default' => 20);

            $batch_min = isset($batch_size_limits['min']) ? (int) $batch_size_limits['min'] : 5;
            $batch_max = isset($batch_size_limits['max']) ? (int) $batch_size_limits['max'] : 100;
            $batch_default = isset($batch_size_limits['default']) ? (int) $batch_size_limits['default'] : 20;

            if ($batch_min <= 0) {
                $batch_min = 1;
            }

            if ($batch_max < $batch_min) {
                $batch_max = $batch_min;
            }

            $stored_batch_size = get_option('blc_batch_size', $batch_default);
            $batch_size = is_scalar($stored_batch_size) ? (int) $stored_batch_size : $batch_default;

            if ($batch_size < $batch_min) {
                $batch_size = $batch_min;
            } elseif ($batch_size > $batch_max) {
                $batch_size = $batch_max;
            }

            $batch_size = (int) apply_filters(
                'blc_batch_size',
                $batch_size,
                array(
                    'context'      => 'image',
                    'batch'        => (int) $batch,
                    'is_full_scan' => true,
                    'constraints'  => $batch_size_limits,
                )
            );

            if ($batch_size < 1) {
                $batch_size = $batch_min;
            }
            $public_post_types = get_post_types(['public' => true], 'names');
            if (!is_array($public_post_types)) {
                $public_post_types = [];
            }
            $public_post_types = array_values(array_filter(array_map('strval', $public_post_types), static function ($post_type) {
                return $post_type !== '';
            }));
            if ($public_post_types === []) {
                $public_post_types = ['post'];
            }

            // Limiter la requête aux types de contenus publics (repli sur « post ») tout en conservant la pagination existante.
            $args = [
                'post_type'      => $public_post_types,
                'post_status'    => blc_get_scannable_post_statuses(),
                'posts_per_page' => $batch_size,
                'paged'          => $batch + 1,
            ];
            $query = new WP_Query($args);
            $posts = $query->posts;
            $checked_local_paths = [];

            $total_items = isset($query->found_posts) ? max(0, (int) $query->found_posts) : 0;
            $max_pages = isset($query->max_num_pages) ? (int) $query->max_num_pages : 0;
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
                __('Analyse du lot %1$d sur %2$d en cours…', 'liens-morts-detector-jlg'),
                $processed_batches,
                $total_batches
            );

            blc_update_image_scan_status([
                'state'             => 'running',
                'current_batch'     => (int) $batch,
                'processed_batches' => $processed_batches,
                'total_batches'     => $total_batches,
                'remaining_batches' => $remaining_batches,
                'total_items'       => $total_items,
                'processed_items'   => $processed_items,
                'is_full_scan'      => true,
                'message'           => $status_message,
            ]);

            $post_ids_in_batch = array_map('intval', wp_list_pluck($posts, 'ID'));
            $post_ids_in_batch = array_values(array_unique(array_filter($post_ids_in_batch, static function ($value) {
                return $value > 0;
            })));

            $scan_run_token = '';
            if (!empty($post_ids_in_batch)) {
                $scan_run_token = blc_generate_scan_run_token();
                $stage_result = blc_stage_dataset_refresh($table_name, $image_dataset_row_types, $scan_run_token, $post_ids_in_batch);
                if (is_wp_error($stage_result)) {
                    if ($lock_token !== '') {
                        blc_release_image_scan_lock($lock_token);
                    }
                    return $stage_result;
                }
            }

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
                    error_log('wp_upload_dir() unavailable during image scan: ' . $upload_dir_error_message);
                }

                $upload_baseurl = '';
                $upload_basedir = '';
                $normalized_basedir = '';
            } else {
                $upload_baseurl = trailingslashit((string) $upload_dir_info['baseurl']);
                $upload_basedir = trailingslashit((string) $upload_dir_info['basedir']);
                $normalized_basedir = $upload_basedir !== '' ? wp_normalize_path($upload_basedir) : '';
            }
            $upload_baseurl_host = '';
            if ($upload_baseurl !== '') {
                $raw_upload_host = function_exists('wp_parse_url')
                    ? wp_parse_url($upload_baseurl, PHP_URL_HOST)
                    : parse_url($upload_baseurl, PHP_URL_HOST);
                if (is_string($raw_upload_host) && $raw_upload_host !== '') {
                    $upload_baseurl_host = blc_normalize_remote_host($raw_upload_host);
                }
            }
            $site_url = home_url();
            $home_url_with_trailing_slash = trailingslashit($site_url);
            $site_scheme = parse_url($site_url, PHP_URL_SCHEME);
            if (!is_string($site_scheme) || $site_scheme === '') {
                $site_scheme = 'https';
            }
            $site_host_for_metadata = '';
            $site_host_candidate = parse_url($site_url, PHP_URL_HOST);
            if (is_string($site_host_candidate) && $site_host_candidate !== '') {
                $site_host_for_metadata = blc_normalize_remote_host($site_host_candidate);
            }
            $normalized_site_host = $site_host_for_metadata;

            $blog_charset = get_bloginfo('charset');
            if (empty($blog_charset)) { $blog_charset = 'UTF-8'; }

            $batch_exception = null;
            $batch_wp_error = null;

            $pending_image_inserts = [];
            $register_pending_image_insert = static function ($post_id, $row_bytes) use (&$pending_image_inserts, $wpdb) {
                $post_id = (int) $post_id;
                if ($post_id <= 0) {
                    return;
                }

                $row_id = isset($wpdb->insert_id) ? (int) $wpdb->insert_id : 0;
                if ($row_id <= 0) {
                    return;
                }

                if (!isset($pending_image_inserts[$post_id])) {
                    $pending_image_inserts[$post_id] = [];
                }

                $pending_image_inserts[$post_id][] = [
                    'id'    => $row_id,
                    'bytes' => (int) $row_bytes,
                ];
            };

            try {
                foreach ($posts as $post) {
                    try {
                        if ($debug_mode) { error_log("Analyse IMAGES pour : '" . $post->post_title . "'"); }

                        $post_title_for_storage = blc_prepare_text_field_for_storage($post->post_title);

                        $dom = blc_create_dom_from_content($post->post_content, $blog_charset);
                        if (!$dom instanceof DOMDocument) {
                            error_log(sprintf('BLC: DOM creation failed during image scan for post ID %d; restoring staged entries.', $post->ID));
                            if ($scan_run_token !== '') {
                                blc_restore_dataset_refresh($table_name, $image_dataset_row_types, $scan_run_token, [$post->ID]);
                            }
                            continue;
                        }

                    $permalink = get_permalink($post);

                    foreach ($dom->getElementsByTagName('img') as $image_node) {
                        $process_image_candidate = static function ($candidate_url) use (
                            &$checked_local_paths,
                            $home_url_with_trailing_slash,
                            $site_scheme,
                            $permalink,
                            $normalized_site_host,
                            $upload_baseurl_host,
                            $upload_baseurl,
                            $upload_basedir,
                            $normalized_basedir,
                            $debug_mode,
                            $post,
                            $table_name,
                            $wpdb,
                            $post_title_for_storage,
                            $register_pending_image_insert,
                            $site_host_for_metadata,
                            $remote_image_scan_enabled
                        ) {
                            $candidate_url = trim((string) $candidate_url);
                            if ($candidate_url === '') {
                                return;
                            }

                            $normalized_image_url = blc_normalize_link_url(
                                $candidate_url,
                                $home_url_with_trailing_slash,
                                $site_scheme,
                                $permalink
                            );

                            if (!is_string($normalized_image_url) || $normalized_image_url === '') {
                                return;
                            }

                            $image_host_raw = parse_url($normalized_image_url, PHP_URL_HOST);
                            $image_host = is_string($image_host_raw) ? blc_normalize_remote_host($image_host_raw) : '';
                            if ($image_host === '') {
                                return;
                            }

                            $hosts_match_site = ($image_host !== '' && $normalized_site_host !== '' && $image_host === $normalized_site_host);
                            $hosts_match_upload = ($image_host !== '' && $upload_baseurl_host !== '' && $image_host === $upload_baseurl_host);
                            $is_remote_upload_candidate = false;

                            if (!$hosts_match_site && !$hosts_match_upload) {
                                if (!$remote_image_scan_enabled) {
                                    if ($debug_mode) {
                                        error_log("  -> Image distante ignorée (analyse désactivée) : " . $normalized_image_url);
                                    }
                                    return;
                                }

                                $is_safe_remote_host = blc_is_safe_remote_host($image_host);
                                if (!$is_safe_remote_host) {
                                    if ($debug_mode) {
                                        error_log("  -> Image ignorée (IP non autorisée) : " . $normalized_image_url);
                                    }
                                    return;
                                }

                                $is_remote_upload_candidate = true;
                            } elseif (!$hosts_match_site && $hosts_match_upload) {
                                $is_safe_remote_host = blc_is_safe_remote_host($image_host);
                                if (!$is_safe_remote_host) {
                                    if ($debug_mode) {
                                        error_log("  -> Image ignorée (IP non autorisée) : " . $normalized_image_url);
                                    }
                                    return;
                                }
                            }
                            if (empty($upload_baseurl) || empty($upload_basedir) || empty($normalized_basedir)) {
                                return;
                            }

                            $image_scheme = parse_url($normalized_image_url, PHP_URL_SCHEME);
                            $normalized_upload_baseurl = $upload_baseurl;
                            if ($image_scheme && $upload_baseurl !== '') {
                                $normalized_upload_baseurl = set_url_scheme($upload_baseurl, $image_scheme);
                            }

                            $normalized_upload_baseurl_length = strlen($normalized_upload_baseurl);
                            if ($normalized_upload_baseurl_length === 0) {
                                return;
                            }
                            if (
                                !$is_remote_upload_candidate &&
                                strncasecmp($normalized_image_url, $normalized_upload_baseurl, $normalized_upload_baseurl_length) !== 0
                            ) {
                                return;
                            }

                            $image_path_from_url = function_exists('wp_parse_url')
                                ? wp_parse_url($normalized_image_url, PHP_URL_PATH)
                                : parse_url($normalized_image_url, PHP_URL_PATH);
                            if (!is_string($image_path_from_url) || $image_path_from_url === '') {
                                return;
                            }

                            $image_path = wp_normalize_path($image_path_from_url);

                            $parsed_upload_baseurl = function_exists('wp_parse_url') ? wp_parse_url($normalized_upload_baseurl) : parse_url($normalized_upload_baseurl);
                            $upload_base_path = '';
                            if (is_array($parsed_upload_baseurl) && !empty($parsed_upload_baseurl['path'])) {
                                $upload_base_path = wp_normalize_path($parsed_upload_baseurl['path']);
                            }

                            $upload_base_path_trimmed = ltrim(trailingslashit($upload_base_path), '/');
                            $upload_base_path_trimmed_length = strlen($upload_base_path_trimmed);
                            $image_path_trimmed = ltrim($image_path, '/');

                            if (
                                $upload_base_path_trimmed_length === 0 ||
                                strncasecmp($image_path_trimmed, $upload_base_path_trimmed, $upload_base_path_trimmed_length) !== 0
                            ) {
                                return;
                            }

                            $relative_path = ltrim(substr($image_path_trimmed, $upload_base_path_trimmed_length), '/');
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
                            if (strpos($file_path, $normalized_basedir) !== 0) {
                                return;
                            }

                            if (!isset($checked_local_paths[$file_path]) || !is_array($checked_local_paths[$file_path])) {
                                $checked_local_paths[$file_path] = [
                                    'exists' => file_exists($file_path),
                                    'reported_posts' => [],
                                ];
                            }

                            if (!isset($checked_local_paths[$file_path]['reported_posts']) || !is_array($checked_local_paths[$file_path]['reported_posts'])) {
                                $checked_local_paths[$file_path]['reported_posts'] = [];
                            }

                            if (!empty($checked_local_paths[$file_path]['exists'])) {
                                return;
                            }

                            if (isset($checked_local_paths[$file_path]['reported_posts'][$post->ID])) {
                                return;
                            }

                            if ($debug_mode) {
                                error_log("  -> Image Cassée Trouvée : " . $candidate_url);
                            }
                            $url_for_storage    = blc_prepare_url_for_storage($candidate_url);
                            $image_filename = wp_basename($decoded_relative_path);
                            $anchor_for_storage = blc_prepare_text_field_for_storage($image_filename);
                            $metadata  = blc_get_url_metadata_for_storage($candidate_url, $normalized_image_url, $site_host_for_metadata);
                            $row_bytes = blc_calculate_row_storage_footprint_bytes($url_for_storage, $anchor_for_storage, $post_title_for_storage);
                            $checked_at_gmt = current_time('mysql', true);
                            $row_type = $is_remote_upload_candidate ? 'remote-image' : 'image';
                            $inserted = $wpdb->insert(
                                $table_name,
                                [
                                    'url'             => $url_for_storage,
                                    'anchor'          => $anchor_for_storage,
                                    'post_id'         => $post->ID,
                                    'post_title'      => $post_title_for_storage,
                                    'type'            => $row_type,
                                    'url_host'        => $metadata['host'],
                                    'is_internal'     => $metadata['is_internal'],
                                    'http_status'     => null,
                                    'last_checked_at' => $checked_at_gmt,
                                ],
                                ['%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s']
                            );
                            if ($inserted) {
                                $checked_local_paths[$file_path]['reported_posts'][$post->ID] = true;
                                $register_pending_image_insert($post->ID, $row_bytes);
                                blc_adjust_dataset_storage_footprint('image', $row_bytes);
                            }
                        };

                        $candidate_lookup = [];
                        $src_value = trim(wp_kses_decode_entities($image_node->getAttribute('src')));
                        if ($src_value !== '') {
                            $candidate_lookup[$src_value] = true;
                            $process_image_candidate($src_value);
                        }

                        $srcset_value_raw = wp_kses_decode_entities($image_node->getAttribute('srcset'));
                        $srcset_value = trim((string) $srcset_value_raw);
                        if ($srcset_value !== '') {
                            $srcset_entries = explode(',', $srcset_value);
                            foreach ($srcset_entries as $entry) {
                                $entry = trim($entry);
                                if ($entry === '') {
                                    continue;
                                }

                                $parts = preg_split('/\s+/', $entry, 2);
                                if (!is_array($parts) || $parts === []) {
                                    continue;
                                }

                                $candidate = trim((string) $parts[0]);
                                if ($candidate === '') {
                                    continue;
                                }

                                if (isset($candidate_lookup[$candidate])) {
                                    continue;
                                }

                                $candidate_lookup[$candidate] = true;
                                $process_image_candidate($candidate);
                            }
                        }
                    }
                    } catch (\Throwable $caught_exception) {
                        $batch_exception = $caught_exception;
                        break;
                    }

                    if ($scan_run_token !== '') {
                        $commit_result = blc_commit_dataset_refresh($table_name, $image_dataset_row_types, $scan_run_token, 'image', [$post->ID]);
                        if (is_wp_error($commit_result)) {
                            $batch_wp_error = $commit_result;
                            break;
                        }

                        if (isset($pending_image_inserts[$post->ID])) {
                            unset($pending_image_inserts[$post->ID]);
                        }
                    }
                }

                $should_cleanup_pending_images = ($batch_exception !== null || $batch_wp_error instanceof \WP_Error);
                if ($should_cleanup_pending_images && !empty($pending_image_inserts)) {
                    foreach ($pending_image_inserts as $post_pending_entries) {
                        foreach ($post_pending_entries as $entry) {
                            $row_id = isset($entry['id']) ? (int) $entry['id'] : 0;
                            $bytes  = isset($entry['bytes']) ? (int) $entry['bytes'] : 0;

                            if ($row_id > 0) {
                                $wpdb->delete($table_name, ['id' => $row_id], ['%d']);
                            }

                            if ($bytes !== 0) {
                                blc_adjust_dataset_storage_footprint('image', -$bytes);
                            }
                        }
                    }

                    $pending_image_inserts = [];
                }

                if ($scan_run_token !== '' && $should_cleanup_pending_images) {
                    blc_restore_dataset_refresh($table_name, $image_dataset_row_types, $scan_run_token);
                }

                if ($batch_exception instanceof \Throwable) {
                    if ($lock_token !== '') {
                        blc_release_image_scan_lock($lock_token);
                    }
                    throw $batch_exception;
                }

                if ($batch_wp_error instanceof \WP_Error) {
                    if ($lock_token !== '') {
                        blc_release_image_scan_lock($lock_token);
                    }
                    return $batch_wp_error;
                }

                if ($query->max_num_pages > ($batch + 1)) {
                    // On utilise un hook de batch différent pour ne pas interférer
                    blc_refresh_image_scan_lock($lock_token, $lock_timeout);
                    $scheduled = wp_schedule_single_event(time() + $batch_delay_s, 'blc_check_image_batch', array($batch + 1, true));
                    if (false === $scheduled) {
                        error_log(sprintf('BLC: Failed to schedule next image batch #%d.', $batch + 1));
                        do_action('blc_check_image_batch_schedule_failed', $batch + 1, true, 'next_batch');

                        if ($lock_token !== '') {
                            blc_release_image_scan_lock($lock_token);
                        }

                        delete_option('blc_image_scan_lock_token');

                        blc_update_image_scan_status([
                            'state'           => 'failed',
                            'last_error'      => __('Impossible de programmer le lot suivant.', 'liens-morts-detector-jlg'),
                            'message'         => __('Impossible de programmer le lot suivant.', 'liens-morts-detector-jlg'),
                            'total_items'     => $total_items,
                            'processed_items' => $processed_items,
                            'is_full_scan'    => true,
                        ]);

                        return new WP_Error(
                            'blc_image_schedule_failed',
                            sprintf('Failed to schedule next image batch #%d.', $batch + 1)
                        );
                    }

                    blc_update_image_scan_status([
                        'state'             => 'running',
                        'current_batch'     => (int) $batch,
                        'processed_batches' => $processed_batches,
                        'total_batches'     => $total_batches,
                        'remaining_batches' => max(0, $remaining_batches),
                        'total_items'       => $total_items,
                        'processed_items'   => $processed_items,
                        'is_full_scan'      => true,
                        'message'           => sprintf(
                            /* translators: 1: completed batch number, 2: next batch number. */
                            __('Lot %1$d terminé. Lot %2$d planifié.', 'liens-morts-detector-jlg'),
                            $processed_batches,
                            min($total_batches, $processed_batches + 1)
                        ),
                    ]);
                } else {
                    if ($debug_mode) { error_log("--- Scan IMAGES terminé ---"); }
                    update_option('blc_last_image_check_time', current_time('timestamp', true));
                    blc_maybe_send_scan_summary('image');
                    blc_update_image_scan_status([
                        'state'             => 'completed',
                        'current_batch'     => (int) $batch,
                        'processed_batches' => $processed_batches,
                        'total_batches'     => $total_batches,
                        'remaining_batches' => 0,
                        'total_items'       => $total_items,
                        'processed_items'   => max($total_items, $processed_items),
                        'is_full_scan'      => true,
                        'message'           => $total_items > 0
                            ? __('Analyse terminée. Tous les lots ont été traités.', 'liens-morts-detector-jlg')
                            : __('Analyse terminée. Aucun contenu à analyser.', 'liens-morts-detector-jlg'),
                    ]);
                    blc_release_image_scan_lock($lock_token);
                }
            } finally {
                wp_reset_postdata();
            }
        }
    }
}

if (!class_exists('ImageScanController')) {
    class ImageScanController {
        /** @var ImageScanQueue */
        private $queue;

        public function __construct(ImageScanQueue $queue) {
            $this->queue = $queue;
        }

        public function run($batch = 0, $is_full_scan = true) {
            return $this->queue->run($batch, $is_full_scan);
        }
    }
}

function blc_make_remote_request_client() {
    return new \JLG\BrokenLinks\Scanner\RemoteRequestClient();
}

function blc_make_scan_queue(\JLG\BrokenLinks\Scanner\RemoteRequestClient $client) {
    return new \JLG\BrokenLinks\Scanner\ScanQueue($client);
}

function blc_make_link_scan_controller(\JLG\BrokenLinks\Scanner\ScanQueue $queue) {
    return new \JLG\BrokenLinks\Scanner\LinkScanController($queue);
}

function blc_make_image_scan_queue() {
    return new ImageScanQueue();
}

function blc_make_image_scan_controller(ImageScanQueue $queue) {
    return new ImageScanController($queue);
}
if (!function_exists('blc_normalize_hour_option')) {
    require_once __DIR__ . '/blc-utils.php';
}

/**
 * Retrieve the configured notification recipients as a normalized list of emails.
 *
 * @return string[]
 */
function blc_get_notification_recipients_list() {
    $stored = get_option('blc_notification_recipients', '');

    return blc_parse_notification_recipients($stored);
}

/**
 * Send a summary email after a scan when recipients are configured.
 *
 * @param string $dataset_type Dataset type ('link' or 'image').
 *
 * @return void
 */
function blc_maybe_send_scan_summary($dataset_type) {
    if (!blc_is_notification_channel_enabled($dataset_type)) {
        return;
    }

    $summary = blc_generate_scan_summary_email($dataset_type);
    if ($summary === null) {
        return;
    }

    $recipients = blc_get_notification_recipients_list();
    $webhook_settings = blc_get_notification_webhook_settings();
    $has_recipients = $recipients !== [];
    $has_webhook = blc_is_webhook_notification_configured($webhook_settings);

    if (!$has_recipients && !$has_webhook) {
        return;
    }

    blc_dispatch_scan_summary_notifications(
        $dataset_type,
        $summary,
        $recipients,
        array(
            'context'           => 'scan',
            'webhook_settings'  => $webhook_settings,
        )
    );
}

/**
 * Check if a notification channel is enabled.
 *
 * @param string $dataset_type Dataset type ('link' or 'image').
 *
 * @return bool
 */
function blc_is_notification_channel_enabled($dataset_type) {
    $dataset_type = (string) $dataset_type;

    if ($dataset_type === 'link') {
        return (bool) get_option('blc_notification_links_enabled', true);
    }

    if ($dataset_type === 'image') {
        return (bool) get_option('blc_notification_images_enabled', true);
    }

    return false;
}

/**
 * Build the scan summary email payload for a dataset type.
 *
 * @param string $dataset_type Dataset type ('link' or 'image').
 *
 * @return array<string, mixed>|null
 */
function blc_generate_scan_summary_email($dataset_type) {
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix)) {
        return null;
    }

    $dataset_type = (string) $dataset_type;
    if ($dataset_type !== 'link' && $dataset_type !== 'image') {
        return null;
    }

    $table_name = $wpdb->prefix . 'blc_broken_links';
    $broken_count = 0;
    $row_types = blc_get_dataset_row_types($dataset_type);
    if ($row_types === []) {
        return null;
    }

    if (method_exists($wpdb, 'prepare') && method_exists($wpdb, 'get_var')) {
        if (count($row_types) === 1) {
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE type = %s",
                reset($row_types)
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($row_types), '%s'));
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE type IN ($placeholders)",
                $row_types
            );
        }

        if (is_string($query)) {
            $broken_count = (int) $wpdb->get_var($query);
        }
    }

    $site_name = get_bloginfo('name');
    if (!is_string($site_name) || $site_name === '') {
        $home_url = function_exists('home_url') ? home_url() : '';
        $parsed_host = '';
        if ($home_url !== '') {
            $parsed_host = function_exists('wp_parse_url')
                ? wp_parse_url($home_url, PHP_URL_HOST)
                : parse_url($home_url, PHP_URL_HOST);
        }
        $site_name = is_string($parsed_host) && $parsed_host !== '' ? $parsed_host : 'WordPress';
    }

    if ($dataset_type === 'link') {
        $dataset_label = __('analyse des liens', 'liens-morts-detector-jlg');
        $subject = sprintf(
            __('[%s] Résumé de la dernière analyse des liens', 'liens-morts-detector-jlg'),
            $site_name
        );
        $report_url = admin_url('admin.php?page=blc-dashboard');
        $message_lines = [
            __('Bonjour,', 'liens-morts-detector-jlg'),
            '',
            sprintf(
                __('Voici le résumé de la dernière analyse des liens sur %s :', 'liens-morts-detector-jlg'),
                $site_name
            ),
            sprintf(
                __('- Liens cassés détectés : %d', 'liens-morts-detector-jlg'),
                $broken_count
            ),
            '',
            sprintf(
                __('Consulter le rapport complet : %s', 'liens-morts-detector-jlg'),
                $report_url
            ),
            '',
            __('— Liens Morts Detector', 'liens-morts-detector-jlg'),
        ];
    } else {
        $dataset_label = __('analyse des images', 'liens-morts-detector-jlg');
        $subject = sprintf(
            __('[%s] Résumé de la dernière analyse des images', 'liens-morts-detector-jlg'),
            $site_name
        );
        $report_url = admin_url('admin.php?page=blc-images-dashboard');
        $message_lines = [
            __('Bonjour,', 'liens-morts-detector-jlg'),
            '',
            sprintf(
                __('Voici le résumé de la dernière analyse des images sur %s :', 'liens-morts-detector-jlg'),
                $site_name
            ),
            sprintf(
                __('- Images cassées détectées : %d', 'liens-morts-detector-jlg'),
                $broken_count
            ),
            '',
            sprintf(
                __('Consulter le rapport complet : %s', 'liens-morts-detector-jlg'),
                $report_url
            ),
            '',
            __('— Liens Morts Detector', 'liens-morts-detector-jlg'),
        ];
    }

    $message = implode(PHP_EOL, $message_lines);

    return [
        'subject'       => $subject,
        'message'       => $message,
        'dataset_type'  => $dataset_type,
        'dataset_label' => $dataset_label,
        'broken_count'  => $broken_count,
        'report_url'    => $report_url,
        'site_name'     => $site_name,
    ];
}

/**
 * Parse a raw recipient string into a list of valid email addresses.
 *
 * @param mixed $raw_recipients Raw input value.
 *
 * @return string[]
 */
function blc_parse_notification_recipients($raw_recipients) {
    if (!is_scalar($raw_recipients)) {
        return [];
    }

    $candidates = preg_split('/[\r\n,;]+/', (string) $raw_recipients);
    if (!is_array($candidates)) {
        return [];
    }

    $recipients = [];
    foreach ($candidates as $candidate) {
        $email = sanitize_email(trim((string) $candidate));
        if ($email === '') {
            continue;
        }

        if (is_email($email)) {
            $recipients[$email] = $email;
        }
    }

    return array_values($recipients);
}

/**
 * Retrieve the list of post statuses that should be included in scans.
 *
 * @return string[]
 */
function blc_get_scannable_post_statuses() {
    $stored_statuses = get_option('blc_post_statuses', ['publish']);
    if (!is_array($stored_statuses)) {
        $stored_statuses = [$stored_statuses];
    }

    $valid_statuses = get_post_stati([], 'names');
    if (!is_array($valid_statuses)) {
        $valid_statuses = [];
    }
    $valid_lookup = [];
    foreach ($valid_statuses as $status_name) {
        $normalized = sanitize_key((string) $status_name);
        if ($normalized === '') {
            continue;
        }
        $valid_lookup[$normalized] = true;
    }

    $selected = [];
    foreach ($stored_statuses as $status_value) {
        if (!is_scalar($status_value)) {
            continue;
        }

        $status_key = sanitize_key((string) $status_value);
        if ($status_key === '') {
            continue;
        }

        if ($valid_lookup !== [] && !isset($valid_lookup[$status_key])) {
            continue;
        }

        $selected[$status_key] = $status_key;
    }

    if ($selected === []) {
        if (isset($valid_lookup['publish'])) {
            $selected = ['publish'];
        } elseif ($valid_lookup !== []) {
            foreach ($valid_lookup as $status_key => $_unused) {
                $selected = [$status_key];
                break;
            }
        } else {
            $selected = ['publish'];
        }
    }

    return array_values($selected);
}

/**
 * Normalize a link URL while preserving the original value for storage/display.
 *
 * @param string      $url          Original URL extracted from the content.
 * @param string      $site_url     Site URL with trailing slash.
 * @param string|null $site_scheme  Current site scheme (http/https).
 * @param string|null $document_url Absolute permalink of the current document.
 *
 * @return string Normalized URL suitable for validation.
 */
function blc_normalize_link_url($url, $site_url, $site_scheme = null, $document_url = null) {
    $url = (string) $url;
    if ($url === '') {
        return '';
    }

    if (strpos($url, '//') === 0) {
        $scheme = ($site_scheme !== null && $site_scheme !== '') ? $site_scheme : 'http';
        return set_url_scheme($url, $scheme);
    }

    $parsed_url = parse_url($url);
    if ($parsed_url === false) {
        return '';
    }

    if (!empty($parsed_url['scheme'])) {
        return $url;
    }

    $ensure_trailing_slash = static function ($value) {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        return rtrim($value, '/') . '/';
    };

    $parse_url_with_wp = static function ($candidate) {
        $candidate = (string) $candidate;
        if ($candidate === '') {
            return null;
        }

        if (function_exists('wp_parse_url')) {
            $parts = wp_parse_url($candidate);
            if (is_array($parts)) {
                return $parts;
            }
        }

        $parts = parse_url($candidate);
        return is_array($parts) ? $parts : null;
    };

    $site_url = (string) $site_url;
    if ($site_url === '' && function_exists('home_url')) {
        $fallback_home = home_url();
        if (is_string($fallback_home) && $fallback_home !== '') {
            $site_url = $fallback_home;
        }
    }

    $site_url = $ensure_trailing_slash($site_url);
    $site_parts = $parse_url_with_wp($site_url);

    if ((!is_array($site_parts) || empty($site_parts['scheme']) || empty($site_parts['host'])) && function_exists('home_url')) {
        $fallback_home = home_url();
        if (is_string($fallback_home) && $fallback_home !== '') {
            $site_url = $ensure_trailing_slash($fallback_home);
            $site_parts = $parse_url_with_wp($site_url);
        }
    }

    $site_origin = '';
    $site_path = '/';
    if (is_array($site_parts)) {
        if (isset($site_parts['scheme'], $site_parts['host']) && $site_parts['scheme'] !== '' && $site_parts['host'] !== '') {
            $site_origin = $site_parts['scheme'] . '://' . $site_parts['host'];

            if (isset($site_parts['port']) && $site_parts['port'] !== '') {
                $site_origin .= ':' . $site_parts['port'];
            }
        }

        if (isset($site_parts['path']) && $site_parts['path'] !== '') {
            $site_path = $site_parts['path'];
        }
    }

    if (!is_string($site_path) || $site_path === '') {
        $site_path = '/';
    }

    if ($site_path[0] !== '/') {
        $site_path = '/' . ltrim($site_path, '/');
    }

    $document_origin = '';
    $document_path = '';

    if (is_string($document_url) && $document_url !== '') {
        $document_parts = $parse_url_with_wp($document_url);

        if (is_array($document_parts)) {
            if (isset($document_parts['scheme'], $document_parts['host']) && $document_parts['scheme'] !== '' && $document_parts['host'] !== '') {
                $document_origin = $document_parts['scheme'] . '://' . $document_parts['host'];
                if (isset($document_parts['port']) && $document_parts['port'] !== '') {
                    $document_origin .= ':' . $document_parts['port'];
                }
            }

            if (isset($document_parts['path']) && $document_parts['path'] !== '') {
                $document_path = $document_parts['path'];
            }
        }
    }

    if (!is_string($document_path) || $document_path === '') {
        $document_path = $site_path;
    }

    if (!is_string($document_path) || $document_path === '') {
        $document_path = '/';
    }

    if ($document_path[0] !== '/') {
        $document_path = '/' . ltrim($document_path, '/');
    }

    $document_directory = $document_path;
    if ($document_directory === '' || substr($document_directory, -1) !== '/') {
        $last_slash = strrpos($document_directory, '/');
        if ($last_slash === false) {
            $document_directory = '/';
        } else {
            $document_directory = substr($document_directory, 0, $last_slash + 1);
        }
    }

    if ($document_directory === '') {
        $document_directory = '/';
    }

    $document_default_path = $document_path !== '' ? $document_path : $site_path;
    if ($document_default_path === '') {
        $document_default_path = '/';
    }

    $origin_for_document = $document_origin !== '' ? $document_origin : $site_origin;

    $trimmed_url = ltrim($url);
    $parsed_without_scheme = parse_url('http://' . $trimmed_url);

    if (
        is_array($parsed_without_scheme) &&
        isset($parsed_without_scheme['host']) &&
        $parsed_without_scheme['host'] !== '' &&
        strpos($parsed_without_scheme['host'], '.') !== false &&
        preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/', $parsed_without_scheme['host']) === 1
    ) {
        $host = $parsed_without_scheme['host'];
        $last_dot = strrpos($host, '.');
        $tld = $last_dot !== false ? substr($host, $last_dot + 1) : '';

        if ($tld !== '' && preg_match('/^[A-Za-z]{2,}$/', $tld) === 1) {
            $scheme = ($site_scheme !== null && $site_scheme !== '') ? $site_scheme : 'http';
            $userinfo = '';
            if (isset($parsed_without_scheme['user']) && $parsed_without_scheme['user'] !== '') {
                $userinfo = $parsed_without_scheme['user'];
                if (isset($parsed_without_scheme['pass']) && $parsed_without_scheme['pass'] !== '') {
                    $userinfo .= ':' . $parsed_without_scheme['pass'];
                }
                $userinfo .= '@';
            }

            $port = isset($parsed_without_scheme['port']) ? ':' . $parsed_without_scheme['port'] : '';
            $path = $parsed_without_scheme['path'] ?? '';
            $query = isset($parsed_without_scheme['query']) ? '?' . $parsed_without_scheme['query'] : '';
            $fragment = isset($parsed_without_scheme['fragment']) ? '#' . $parsed_without_scheme['fragment'] : '';

            return $scheme . '://' . $userinfo . $host . $port . $path . $query . $fragment;
        }
    }

    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
    $query = isset($parsed_url['query']) && $parsed_url['query'] !== '' ? '?' . $parsed_url['query'] : '';
    $fragment = isset($parsed_url['fragment']) && $parsed_url['fragment'] !== '' ? '#' . $parsed_url['fragment'] : '';

    if ($path !== '' && strpos($path, '/') === 0) {
        $origin = $origin_for_document !== '' ? $origin_for_document : $site_origin;
        if ($origin === '') {
            $origin = rtrim($site_url, '/');
        }

        return $origin . $path . $query . $fragment;
    }

    $resolve_relative_path = static function ($base_directory, $relative_path) {
        $base_directory = (string) $base_directory;
        if ($base_directory === '') {
            $base_directory = '/';
        }

        if ($base_directory[0] !== '/') {
            $base_directory = '/' . ltrim($base_directory, '/');
        }

        if (substr($base_directory, -1) !== '/') {
            $base_directory .= '/';
        }

        $base_segments = $base_directory === '/' ? [] : explode('/', trim($base_directory, '/'));
        $result_segments = $base_segments;
        $relative_segments = explode('/', (string) $relative_path);
        $ends_with_slash = substr((string) $relative_path, -1) === '/';

        foreach ($relative_segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if (!empty($result_segments)) {
                    array_pop($result_segments);
                }
                continue;
            }

            $result_segments[] = $segment;
        }

        $resolved = '/' . implode('/', $result_segments);
        if ($resolved === '/') {
            return $ends_with_slash ? '/' : '/';
        }

        if ($ends_with_slash) {
            $resolved .= '/';
        }

        return $resolved;
    };

    $origin = $origin_for_document !== '' ? $origin_for_document : $site_origin;
    if ($origin === '') {
        $origin = rtrim($site_url, '/');
    }

    if ($path === '') {
        $base_path = $document_default_path !== '' ? $document_default_path : '/';
        if ($base_path[0] !== '/') {
            $base_path = '/' . ltrim($base_path, '/');
        }

        return $origin . $base_path . $query . $fragment;
    }

    $resolved_path = $resolve_relative_path($document_directory, $path);

    return $origin . $resolved_path . $query . $fragment;
}

/**
 * Generate a cache key identifier for the current scan session.
 *
 * @return string
 */
function blc_generate_scan_cache_key() {
    if (function_exists('wp_generate_uuid4')) {
        $uuid = wp_generate_uuid4();
        if (is_string($uuid) && $uuid !== '') {
            return $uuid;
        }
    }

    if (function_exists('wp_unique_id')) {
        $unique = wp_unique_id('blc_', true);
        if (is_string($unique) && $unique !== '') {
            return $unique;
        }
    }

    try {
        $fallback = bin2hex(random_bytes(16));
        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }
    } catch (\Exception $e) {
        // Fallback handled below.
    }

    return uniqid('blc_', true);
}

/**
 * Build the transient name used to persist the scan cache.
 *
 * @param string $scan_type Scan type identifier (e.g. "link" or "image").
 * @param string $cache_key Unique cache key for the ongoing scan.
 *
 * @return string
 */
function blc_build_scan_cache_transient_name($scan_type, $cache_key) {
    $scan_type = (string) $scan_type;
    if ($scan_type === '') {
        $scan_type = 'link';
    }

    $cache_key = (string) $cache_key;
    if ($cache_key === '') {
        return '';
    }

    return 'blc_scan_cache_' . strtolower($scan_type) . '_' . md5($cache_key);
}

/**
 * Retrieve the scan cache context for the current execution.
 *
 * @param string $scan_type Scan identifier.
 * @param int    $batch     Current batch number.
 *
 * @return array{type: string, option: string, key: string, transient: string, data: array}
 */
function blc_get_scan_cache_context($scan_type, $batch) {
    $scan_type = strtolower((string) $scan_type) === 'image' ? 'image' : 'link';
    $option_name = $scan_type === 'image' ? 'blc_active_image_scan_key' : 'blc_active_link_scan_key';

    $cache_key = get_option($option_name);
    $cache_key = is_string($cache_key) ? trim($cache_key) : '';

    if ($batch === 0 || $cache_key === '') {
        if ($batch === 0 && $cache_key !== '') {
            $previous_transient = blc_build_scan_cache_transient_name($scan_type, $cache_key);
            if ($previous_transient !== '' && function_exists('delete_transient')) {
                delete_transient($previous_transient);
            }
        }

        $cache_key = blc_generate_scan_cache_key();
        update_option($option_name, $cache_key, false);
        $cache_data = [];
    } else {
        $transient_name = blc_build_scan_cache_transient_name($scan_type, $cache_key);
        $cache_data = [];
        if ($transient_name !== '' && function_exists('get_transient')) {
            $stored_cache = get_transient($transient_name);
            if (is_array($stored_cache)) {
                $cache_data = $stored_cache;
            }
        }
    }

    return [
        'type'      => $scan_type,
        'option'    => $option_name,
        'key'       => $cache_key,
        'transient' => blc_build_scan_cache_transient_name($scan_type, $cache_key),
        'data'      => $cache_data,
    ];
}

/**
 * Persist the scan cache to the transient API.
 *
 * @param array $context Scan cache context as returned by blc_get_scan_cache_context().
 * @param array $data    Cache payload to persist.
 *
 * @return void
 */
function blc_save_scan_cache(array &$context, array $data) {
    $context['data'] = $data;

    $transient_name = isset($context['transient']) ? (string) $context['transient'] : '';
    if ($transient_name === '' || !function_exists('set_transient')) {
        return;
    }

    $default_expiration = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
    if (function_exists('apply_filters')) {
        $maybe_expiration = apply_filters(
            'blc_scan_cache_expiration',
            $default_expiration,
            $context['key'] ?? '',
            $context['type'] ?? ''
        );

        if (is_int($maybe_expiration) && $maybe_expiration > 0) {
            $default_expiration = $maybe_expiration;
        }
    }

    set_transient($transient_name, $data, $default_expiration);
}

/**
 * Clear the scan cache once a scan session is complete.
 *
 * @param array $context Scan cache context.
 *
 * @return void
 */
function blc_clear_scan_cache(array $context) {
    $transient_name = isset($context['transient']) ? (string) $context['transient'] : '';
    if ($transient_name !== '' && function_exists('delete_transient')) {
        delete_transient($transient_name);
    }

    $option_name = isset($context['option']) ? (string) $context['option'] : '';
    if ($option_name !== '') {
        delete_option($option_name);
    }
}

/**
 * Generate a token suitable for locking mechanisms.
 *
 * @return string
 */
function blc_generate_lock_token() {
    if (function_exists('wp_generate_uuid4')) {
        return wp_generate_uuid4();
    }

    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        // Fallback below.
    }

    return uniqid('', true);
}

/**
 * Retrieve the current link scan lock data.
 *
 * @return array
 */
function blc_get_link_scan_lock_state() {
    $state = get_option('blc_link_scan_lock', []);
    return is_array($state) ? $state : [];
}

/**
 * Determine if a link scan lock is still considered active.
 *
 * @param array $state   Lock state array.
 * @param int   $timeout Timeout in seconds.
 *
 * @return bool
 */
function blc_is_link_scan_lock_active(array $state, $timeout) {
    $token     = isset($state['token']) ? (string) $state['token'] : '';
    $locked_at = isset($state['locked_at']) ? (int) $state['locked_at'] : 0;

    if ($token === '') {
        return false;
    }

    $timeout = (int) $timeout;

    if ($timeout <= 0) {
        return false;
    }

    if ($locked_at <= 0) {
        return false;
    }

    $expires_at = $locked_at + $timeout;

    if ($expires_at <= 0) {
        return false;
    }

    return $expires_at > time();
}

/**
 * Acquire the link scan lock if possible.
 *
 * @param int $timeout Timeout in seconds.
 *
 * @return string Lock token on success, empty string otherwise.
 */
function blc_acquire_link_scan_lock($timeout) {
    $state = blc_get_link_scan_lock_state();
    if (blc_is_link_scan_lock_active($state, $timeout)) {
        return '';
    }

    $token = blc_generate_lock_token();
    update_option('blc_link_scan_lock', [
        'token'     => $token,
        'locked_at' => time(),
    ]);
    update_option('blc_link_scan_lock_token', $token);

    return $token;
}

/**
 * Refresh the lock timestamp for the current link scan token.
 *
 * @param string $token   Lock token.
 * @param int    $timeout Timeout in seconds.
 *
 * @return void
 */
function blc_refresh_link_scan_lock($token, $timeout) {
    if ($token === '') {
        return;
    }

    $state = blc_get_link_scan_lock_state();
    $current_token = isset($state['token']) ? (string) $state['token'] : '';
    if ($current_token !== $token) {
        return;
    }

    $timeout = (int) $timeout;
    if ($timeout <= 0) {
        return;
    }

    update_option('blc_link_scan_lock', [
        'token'     => $token,
        'locked_at' => time(),
    ]);

    $stored_token = get_option('blc_link_scan_lock_token', '');
    if (!is_string($stored_token) || $stored_token === '') {
        update_option('blc_link_scan_lock_token', $token);
    }
}

/**
 * Release the link scan lock if the provided token still matches.
 *
 * @param string $token Lock token.
 *
 * @return void
 */
function blc_release_link_scan_lock($token) {
    if ($token === '') {
        return;
    }

    $state = blc_get_link_scan_lock_state();
    $current_token = isset($state['token']) ? (string) $state['token'] : '';
    if ($current_token !== $token) {
        return;
    }

    delete_option('blc_link_scan_lock');
    delete_option('blc_link_scan_lock_token');
}

/**
 * Retrieve the current image scan lock data.
 *
 * @return array
 */
function blc_get_image_scan_lock_state() {
    $state = get_option('blc_image_scan_lock', []);
    return is_array($state) ? $state : [];
}

/**
 * Determine if an image scan lock is still considered active.
 *
 * @param array $state   Lock state array.
 * @param int   $timeout Timeout in seconds.
 *
 * @return bool
 */
function blc_is_image_scan_lock_active(array $state, $timeout) {
    $token     = isset($state['token']) ? (string) $state['token'] : '';
    $locked_at = isset($state['locked_at']) ? (int) $state['locked_at'] : 0;

    if ($token === '') {
        return false;
    }

    $timeout = (int) $timeout;

    if ($timeout <= 0) {
        // A non-positive timeout disables the lock and should be treated as expired.
        return false;
    }

    if ($locked_at <= 0) {
        return false;
    }

    $expires_at = $locked_at + $timeout;

    if ($expires_at <= 0) {
        return false;
    }

    return $expires_at > time();
}

/**
 * Acquire the image scan lock if possible.
 *
 * @param int $timeout Timeout in seconds.
 *
 * @return string Lock token on success, empty string otherwise.
 */
function blc_acquire_image_scan_lock($timeout) {
    $state = blc_get_image_scan_lock_state();
    if (blc_is_image_scan_lock_active($state, $timeout)) {
        return '';
    }

    $token = blc_generate_lock_token();
    update_option('blc_image_scan_lock', [
        'token'     => $token,
        'locked_at' => time(),
    ]);
    update_option('blc_image_scan_lock_token', $token);

    return $token;
}

/**
 * Refresh the lock timestamp for the current token.
 *
 * @param string $token   Lock token.
 * @param int    $timeout Timeout in seconds.
 *
 * @return void
 */
function blc_refresh_image_scan_lock($token, $timeout) {
    if ($token === '') {
        return;
    }

    $state = blc_get_image_scan_lock_state();
    $current_token = isset($state['token']) ? (string) $state['token'] : '';
    if ($current_token !== $token) {
        return;
    }

    update_option('blc_image_scan_lock', [
        'token'     => $token,
        'locked_at' => time(),
    ]);

    // Keep the helper option in sync in case it was deleted externally.
    $stored_token = get_option('blc_image_scan_lock_token', '');
    if ($stored_token !== $token) {
        update_option('blc_image_scan_lock_token', $token);
    }
}

/**
 * Release the image scan lock if the token matches.
 *
 * @param string $token Lock token.
 *
 * @return void
 */
function blc_release_image_scan_lock($token) {
    if ($token === '') {
        return;
    }

    $state = blc_get_image_scan_lock_state();
    $current_token = isset($state['token']) ? (string) $state['token'] : '';
    if ($current_token !== '' && $current_token !== $token) {
        return;
    }

    delete_option('blc_image_scan_lock');
    delete_option('blc_image_scan_lock_token');
}

/**
 * Helper to build a DOMDocument instance from raw post content.
 *
 * @param string $content Raw HTML content from the post.
 * @param string $charset Charset used by the blog.
 *
 * @return DOMDocument|null
 */
function blc_create_dom_from_content($content, $charset = 'UTF-8') {
    if (!class_exists('DOMDocument')) {
        return null;
    }

    $content = (string) $content;
    if (trim($content) === '') {
        return null;
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous_state = libxml_use_internal_errors(true);

    if (function_exists('mb_convert_encoding')) {
        $content_for_dom = mb_convert_encoding($content, 'HTML-ENTITIES', $charset);
        if ($content_for_dom === false) {
            $content_for_dom = $content;
        }
    } else {
        $content_for_dom = $content;
    }

    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content_for_dom);

    libxml_clear_errors();
    libxml_use_internal_errors($previous_state);

    return $loaded ? $dom : null;
}

/**
 * Iterate over every <a> element present in a raw HTML snippet.
 *
 * @param string   $html     Raw HTML content.
 * @param string   $charset  Charset used to decode the snippet before DOM parsing.
 * @param callable $callback Callback receiving the original href, anchor text and context information.
 *                           Signature: function (string $href, string $anchor_text, string $context_html, string $context_excerpt): void.
 *
 * @return bool Whether the DOM could be created successfully.
 */
function blc_process_link_nodes_from_html($html, $charset, callable $callback) {
    $dom = blc_create_dom_from_content($html, $charset);
    if (!$dom instanceof DOMDocument) {
        return false;
    }

    foreach ($dom->getElementsByTagName('a') as $link_node) {
        $original_url = trim(wp_kses_decode_entities($link_node->getAttribute('href')));
        if ($original_url === '') {
            continue;
        }

        $anchor_text = wp_strip_all_tags($link_node->textContent);
        $anchor_text = trim(preg_replace('/\s+/u', ' ', $anchor_text));
        if ($anchor_text === '') {
            $anchor_text = sprintf('[%s]', __('Lien sans texte', 'liens-morts-detector-jlg'));
        }

        $context_html = '';
        if ($link_node->ownerDocument instanceof DOMDocument) {
            $rendered = $link_node->ownerDocument->saveHTML($link_node);
            if (is_string($rendered)) {
                $context_html = trim($rendered);
            }
        }

        $context_excerpt = '';
        if ($link_node->parentNode instanceof \DOMNode) {
            $context_excerpt = trim($link_node->parentNode->textContent);
        }

        if ($context_excerpt === '') {
            $context_excerpt = $anchor_text;
        }

        $callback($original_url, $anchor_text, $context_html, $context_excerpt);
    }

    return true;
}

/**
 * Generate a unique token used to mark dataset rows while refreshing them.
 *
 * @return string
 */
function blc_generate_scan_run_token() {
    if (function_exists('wp_generate_uuid4')) {
        return (string) wp_generate_uuid4();
    }

    try {
        $bytes = random_bytes(16);
        return bin2hex($bytes);
    } catch (\Exception $e) {
        return uniqid('blc_', true);
    }
}

/**
 * Flag existing dataset rows so they can be purged after a successful refresh.
 *
 * @param string     $table_name  Fully qualified table name.
 * @param string     $type        Dataset type (e.g. 'link', 'image').
 * @param string     $scan_run_id Unique marker for the current refresh.
 * @param array<int>|null $post_ids Optional list of post IDs scoped to the refresh.
 *
 * @return int|\WP_Error Number of rows marked or WP_Error on failure.
 */
function blc_stage_dataset_refresh($table_name, $types, $scan_run_id, ?array $post_ids = null) {
    if (is_array($post_ids) && count($post_ids) === 0) {
        return 0;
    }

    if (!is_array($types)) {
        $types = [$types];
    }

    $types = array_values(array_filter(array_map('strval', $types), static function ($value) {
        return $value !== '';
    }));

    if ($types === []) {
        return 0;
    }

    global $wpdb;

    $clauses = [];
    $args    = [$scan_run_id];

    if (count($types) === 1) {
        $clauses[] = 'type = %s';
        $args[]    = $types[0];
    } else {
        $type_placeholders = implode(',', array_fill(0, count($types), '%s'));
        $clauses[] = "type IN ($type_placeholders)";
        $args      = array_merge($args, $types);
    }

    if (is_array($post_ids)) {
        $post_ids = array_values(array_unique(array_map('intval', $post_ids)));
        if (count($post_ids) === 0) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $clauses[]    = "post_id IN ($placeholders)";
        $args         = array_merge($args, $post_ids);
    }

    $where_sql = implode(' AND ', $clauses);
    $mark_sql  = $wpdb->prepare("UPDATE $table_name SET scan_run_id = %s WHERE $where_sql", $args);

    if (!is_string($mark_sql)) {
        return new \WP_Error('blc_stage_prepare_failed', __('Unable to prepare statement for staging dataset rows.', 'liens-morts-detector-jlg'));
    }

    $result = $wpdb->query($mark_sql);
    if ($result === false) {
        $message = isset($wpdb->last_error) && $wpdb->last_error !== ''
            ? $wpdb->last_error
            : __('Failed to mark dataset rows for refresh.', 'liens-morts-detector-jlg');

        return new \WP_Error('blc_stage_failed', $message);
    }

    return (int) $result;
}

/**
 * Remove staged rows after the new dataset has been stored successfully.
 *
 * @param string         $table_name   Fully qualified table name.
 * @param string         $type         Dataset type stored in the table.
 * @param string         $scan_run_id  Marker assigned during staging.
 * @param string         $dataset_type Logical dataset type for storage bookkeeping.
 * @param array<int>|null $post_ids    Optional subset of posts to purge.
 *
 * @return int|\WP_Error Number of rows deleted or WP_Error on failure.
 */
function blc_commit_dataset_refresh($table_name, $types, $scan_run_id, $dataset_type, ?array $post_ids = null) {
    if (is_array($post_ids) && count($post_ids) === 0) {
        return 0;
    }

    if (!is_array($types)) {
        $types = [$types];
    }

    $types = array_values(array_filter(array_map('strval', $types), static function ($value) {
        return $value !== '';
    }));

    if ($types === []) {
        return 0;
    }

    global $wpdb;

    $clauses = ['scan_run_id = %s'];
    $args    = [$scan_run_id];

    if (count($types) === 1) {
        $clauses[] = 'type = %s';
        $args[]    = $types[0];
    } else {
        $type_placeholders = implode(',', array_fill(0, count($types), '%s'));
        $clauses[] = "type IN ($type_placeholders)";
        $args      = array_merge($args, $types);
    }

    if (is_array($post_ids)) {
        $post_ids = array_values(array_unique(array_map('intval', $post_ids)));
        if (count($post_ids) === 0) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $clauses[]    = "post_id IN ($placeholders)";
        $args         = array_merge($args, $post_ids);
    }

    $where_sql = implode(' AND ', $clauses);

    $size = 0;
    if (method_exists($wpdb, 'get_var')) {
        $size_sql = $wpdb->prepare(
            "SELECT SUM(COALESCE(LENGTH(url), 0) + COALESCE(LENGTH(anchor), 0) + COALESCE(LENGTH(post_title), 0)) FROM $table_name WHERE $where_sql",
            $args
        );
        if (is_string($size_sql)) {
            $size = (int) $wpdb->get_var($size_sql);
        }
    }

    $delete_sql = $wpdb->prepare("DELETE FROM $table_name WHERE $where_sql", $args);
    if (!is_string($delete_sql)) {
        return new \WP_Error('blc_commit_prepare_failed', __('Unable to prepare cleanup query for dataset refresh.', 'liens-morts-detector-jlg'));
    }

    $deleted = $wpdb->query($delete_sql);
    if ($deleted === false) {
        $message = isset($wpdb->last_error) && $wpdb->last_error !== ''
            ? $wpdb->last_error
            : __('Failed to purge stale dataset entries.', 'liens-morts-detector-jlg');

        return new \WP_Error('blc_commit_failed', $message);
    }

    if (is_int($deleted) && $deleted > 0 && $size > 0) {
        blc_adjust_dataset_storage_footprint($dataset_type, -$size);
    }

    return (int) $deleted;
}

/**
 * Clear staging markers so the previous dataset remains available.
 *
 * @param string          $table_name  Fully qualified table name.
 * @param string|string[] $types       Dataset type(s) stored in the table.
 * @param string          $scan_run_id Marker assigned during staging.
 * @param array<int>|null $post_ids    Optional subset of posts to restore.
 */
function blc_restore_dataset_refresh($table_name, $types, $scan_run_id, ?array $post_ids = null) {
    if (is_array($post_ids) && count($post_ids) === 0) {
        return;
    }

    if (!is_array($types)) {
        $types = [$types];
    }

    $types = array_values(array_filter(array_map('strval', $types), static function ($value) {
        return $value !== '';
    }));

    if ($types === []) {
        return;
    }

    global $wpdb;

    $clauses = ['scan_run_id = %s'];
    $args    = [$scan_run_id];

    if (count($types) === 1) {
        $clauses[] = 'type = %s';
        $args[]    = $types[0];
    } else {
        $type_placeholders = implode(',', array_fill(0, count($types), '%s'));
        $clauses[] = "type IN ($type_placeholders)";
        $args      = array_merge($args, $types);
    }

    if (is_array($post_ids)) {
        $post_ids = array_values(array_unique(array_map('intval', $post_ids)));
        if (count($post_ids) === 0) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $clauses[]    = "post_id IN ($placeholders)";
        $args         = array_merge($args, $post_ids);
    }

    $where_sql   = implode(' AND ', $clauses);
    $restore_sql = $wpdb->prepare("UPDATE $table_name SET scan_run_id = NULL WHERE $where_sql", $args);

    if (is_string($restore_sql)) {
        $wpdb->query($restore_sql);
    }
}

/**
 * Fonction de scan DÉDIÉE AUX LIENS <a>.
 * Déclenchée par la planification et le bouton principal.
 */

function blc_perform_check($batch = 0, $is_full_scan = false, $bypass_rest_window = false) {
    $remote_client = blc_make_remote_request_client();
    $queue = blc_make_scan_queue($remote_client);
    $controller = blc_make_link_scan_controller($queue);

    $result = $controller->runBatch((int) $batch, (bool) $is_full_scan, (bool) $bypass_rest_window);

    if (function_exists('is_wp_error') && is_wp_error($result)) {
        $message = $result->get_error_message();
        blc_update_link_scan_status([
            'state'       => 'failed',
            'last_error'  => $message,
            'message'     => $message,
        ]);
    }

    return $result;
}




/**
 * NOUVEAU : Fonction de scan DÉDIÉE AUX IMAGES <img>.
 * Déclenchée uniquement par le bouton sur la page des images.
 */

function blc_perform_image_check($batch = 0, $is_full_scan = true) {
    $queue = blc_make_image_scan_queue();
    $controller = blc_make_image_scan_controller($queue);

    return $controller->run($batch, $is_full_scan);
}

