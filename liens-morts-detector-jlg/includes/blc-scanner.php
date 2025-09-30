<?php

if (!defined('ABSPATH')) exit;

if (!function_exists('blc_normalize_hour_option')) {
    require_once __DIR__ . '/blc-utils.php';
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

    if (!is_int($timeout)) {
        $timeout = (int) $timeout;
    }

    if ($timeout <= 0) {
        return false;
    }

    return ($locked_at + $timeout) > time();
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
function blc_stage_dataset_refresh($table_name, $type, $scan_run_id, ?array $post_ids = null) {
    if (is_array($post_ids) && count($post_ids) === 0) {
        return 0;
    }

    global $wpdb;

    $clauses = ['type = %s'];
    $args    = [$scan_run_id, $type];

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
function blc_commit_dataset_refresh($table_name, $type, $scan_run_id, $dataset_type, ?array $post_ids = null) {
    if (is_array($post_ids) && count($post_ids) === 0) {
        return 0;
    }

    global $wpdb;

    $clauses = ['scan_run_id = %s', 'type = %s'];
    $args    = [$scan_run_id, $type];

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
 * @param string         $table_name  Fully qualified table name.
 * @param string         $type        Dataset type stored in the table.
 * @param string         $scan_run_id Marker assigned during staging.
 * @param array<int>|null $post_ids   Optional subset of posts to restore.
 */
function blc_restore_dataset_refresh($table_name, $type, $scan_run_id, ?array $post_ids = null) {
    if (is_array($post_ids) && count($post_ids) === 0) {
        return;
    }

    global $wpdb;

    $clauses = ['scan_run_id = %s', 'type = %s'];
    $args    = [$scan_run_id, $type];

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
    global $wpdb;

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
    $batch_size      = 20;
    $last_check_time = (int) get_option('blc_last_check_time', 0);
    $table_name      = $wpdb->prefix . 'blc_broken_links';

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
        'post_status'    => 'publish',
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

    $cleanup_size = 0;
    if (method_exists($wpdb, 'get_var')) {
        $cleanup_size = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(COALESCE(LENGTH(blc.url), 0) + COALESCE(LENGTH(blc.anchor), 0) + COALESCE(LENGTH(blc.post_title), 0))
                 FROM $table_name AS blc
                 LEFT JOIN {$wpdb->posts} AS posts ON blc.post_id = posts.ID
                 WHERE blc.type = %s AND posts.ID IS NULL",
                'link'
            )
        );
    }
    $cleanup_sql = "DELETE blc FROM $table_name AS blc LEFT JOIN {$wpdb->posts} AS posts ON blc.post_id = posts.ID WHERE blc.type = %s AND posts.ID IS NULL";
    $deleted_orphans = $wpdb->query($wpdb->prepare($cleanup_sql, 'link'));
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
        $stage_result = blc_stage_dataset_refresh($table_name, 'link', $scan_run_token, $post_ids_in_batch);
        if (is_wp_error($stage_result)) {
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
    if (is_array($site_parts) && !empty($site_parts['host'])) {
        $site_host = strtolower((string) $site_parts['host']);
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

    $blog_charset = get_bloginfo('charset');
    if (empty($blog_charset)) { $blog_charset = 'UTF-8'; }

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
        if ($post_id <= 0) {
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

    try {
        foreach ($posts as $post) {
            try {
                if ($debug_mode) { error_log("Analyse LIENS pour : '" . $post->post_title . "'"); }

                $permalink = '';
                if (function_exists('get_permalink')) {
                    $maybe_permalink = get_permalink($post);
                    if (is_string($maybe_permalink)) {
                        $permalink = $maybe_permalink;
                    }
                }

                $post_title_for_storage = blc_prepare_text_field_for_storage($post->post_title);

                $dom = blc_create_dom_from_content($post->post_content, $blog_charset);
                if (!$dom instanceof DOMDocument) {
                    error_log(sprintf('BLC: DOM creation failed during link scan for post ID %d; restoring staged entries.', $post->ID));
                    if ($scan_run_token !== '') {
                        blc_restore_dataset_refresh($table_name, 'link', $scan_run_token, [$post->ID]);
                    }
                    continue;
                }

            $occurrence_counters = [];

            foreach ($dom->getElementsByTagName('a') as $link_node) {
                $original_url = trim(wp_kses_decode_entities($link_node->getAttribute('href')));
                if ($original_url === '') { continue; }

                $anchor_text = wp_strip_all_tags($link_node->textContent);
                $anchor_text = trim(preg_replace('/\s+/u', ' ', $anchor_text));
                if ($anchor_text === '') { $anchor_text = '[Lien sans texte]'; }

                $url_for_storage    = blc_prepare_url_for_storage($original_url);
                $anchor_for_storage = blc_prepare_text_field_for_storage($anchor_text);

                $normalized_url = blc_normalize_link_url($original_url, $site_url, $site_scheme, $permalink);
                if ($normalized_url === '') {
                    continue;
                }

                $metadata  = blc_get_url_metadata_for_storage($original_url, $normalized_url, $site_host);
                $row_bytes = blc_calculate_row_storage_footprint_bytes($url_for_storage, $anchor_for_storage, $post_title_for_storage);

                $parsed_url = parse_url($normalized_url);
                if ($parsed_url === false) {
                    continue;
                }

                if (empty($parsed_url['scheme']) || !in_array($parsed_url['scheme'], ['http', 'https'], true)) { continue; }

                $counter_key = $url_for_storage;
                if (!isset($occurrence_counters[$counter_key])) {
                    $occurrence_counters[$counter_key] = 0;
                }
                $occurrence_index = $occurrence_counters[$counter_key];
                $occurrence_counters[$counter_key]++;

                if ($upload_basedir && $upload_base_host && isset($parsed_url['host']) && isset($parsed_url['path'])) {
                    if (strcasecmp($upload_base_host, $parsed_url['host']) === 0 && $upload_base_path !== '' && strpos($parsed_url['path'], $upload_base_path) === 0) {
                        $relative_path = ltrim(substr($parsed_url['path'], strlen($upload_base_path)), '/');
                        if ($relative_path === '') {
                            continue;
                        }
                        $decoded_relative_path = rawurldecode($relative_path);
                        $decoded_relative_path = ltrim($decoded_relative_path, '/\\');
                        if ($decoded_relative_path === '') {
                            continue;
                        }

                        if (preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $decoded_relative_path)) {
                            continue;
                        }

                        $file_path = wp_normalize_path(trailingslashit($upload_basedir) . $decoded_relative_path);
                        if ($normalized_upload_basedir !== '' && strpos($file_path, $normalized_upload_basedir) !== 0) {
                            continue;
                        }

                        if (!file_exists($file_path)) {
                            if ($debug_mode) { error_log("  -> Ressource locale introuvable : " . $normalized_url); }
                            $inserted = $wpdb->insert(
                                $table_name,
                                [
                                    'url'         => $url_for_storage,
                                    'anchor'      => $anchor_for_storage,
                                    'post_id'     => $post->ID,
                                    'post_title'  => $post_title_for_storage,
                                    'type'        => 'link',
                                    'occurrence_index' => $occurrence_index,
                                    'url_host'    => $metadata['host'],
                                    'is_internal' => $metadata['is_internal'],
                                ],
                                ['%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d']
                            );
                            if ($inserted) {
                                $register_pending_link_insert($post->ID, $row_bytes);
                                blc_adjust_dataset_storage_footprint('link', $row_bytes);
                            }
                            continue;
                        }
                    }
                }

                $host = parse_url($normalized_url, PHP_URL_HOST);
                if (!is_string($host) || $host === '') {
                    continue;
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

                if ($is_excluded) { continue; }

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
                                'url'         => $url_for_storage,
                                'anchor'      => $anchor_for_storage,
                                'post_id'     => $post->ID,
                                'post_title'  => $post_title_for_storage,
                                'type'        => 'link',
                                'occurrence_index' => $occurrence_index,
                                'url_host'    => $metadata['host'],
                                'is_internal' => $metadata['is_internal'],
                            ],
                            ['%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d']
                        );
                        if ($inserted) {
                            $register_pending_link_insert($post->ID, $row_bytes);
                            blc_adjust_dataset_storage_footprint('link', $row_bytes);
                        }
                        $should_skip_remote_request = true;
                    }
                }

                if ($should_skip_remote_request) {
                    continue;
                }

                $should_insert_broken_link = false;
                $should_retry_later = false;
                $response_code = null;
                $cache_entry_key = '';
                $cache_entry = null;
                $should_use_cache = false;

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

                $fallback_due_to_temporary_status = false;
                if (!$should_use_cache) {
                    $head_request_args = [
                        'timeout'             => $head_request_timeout,
                        'limit_response_size' => 1024,
                        'redirection'         => 5,
                    ];

                    $get_request_args = [
                        'timeout'             => $get_request_timeout,
                        'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0',
                        'method'              => 'GET',
                        'limit_response_size' => 131072,
                    ];

                    if ($scan_method === 'precise') {
                        $response = null;
                        $wait_for_remote_slot();
                        $head_response = wp_safe_remote_head($normalized_url, $head_request_args);
                        $mark_remote_request_complete();
                        $needs_get_fallback = false;

                        if (is_wp_error($head_response)) {
                            $needs_get_fallback = true;
                        } else {
                            $head_status = (int) wp_remote_retrieve_response_code($head_response);
                            if (in_array($head_status, $temporary_http_statuses, true)) {
                                $needs_get_fallback = true;
                                $fallback_due_to_temporary_status = true;
                            } elseif ($head_status === 403) {
                                $needs_get_fallback = true;
                            } elseif ($head_status === 405 || $head_status === 501) {
                                $needs_get_fallback = true;
                            } else {
                                $response = $head_response;
                            }
                        }

                        if ($needs_get_fallback) {
                            $wait_for_remote_slot();
                            $response = wp_safe_remote_get($normalized_url, $get_request_args);
                            $mark_remote_request_complete();
                        }
                    } else {
                        $wait_for_remote_slot();
                        $response = wp_safe_remote_head($normalized_url, $head_request_args);
                        $mark_remote_request_complete();

                        if (!is_wp_error($response)) {
                            $head_status = (int) wp_remote_retrieve_response_code($response);
                            if (in_array($head_status, [403, 405, 501], true)) {
                                $wait_for_remote_slot();
                                $response = wp_safe_remote_get($normalized_url, $get_request_args);
                                $mark_remote_request_complete();
                            }
                        }
                    }

                    if (is_wp_error($response)) {
                        if ($fallback_due_to_temporary_status) {
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
                        $response_code = (int) wp_remote_retrieve_response_code($response);
                        if ($response_code >= 400) {
                            if (in_array($response_code, $temporary_http_statuses, true)) {
                                $should_retry_later = true;
                            } else {
                                $should_insert_broken_link = true;
                            }
                        }
                    }
                }

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
                            'url'         => $url_for_storage,
                            'anchor'      => $anchor_for_storage,
                            'post_id'     => $post->ID,
                            'post_title'  => $post_title_for_storage,
                            'type'        => 'link',
                            'occurrence_index' => $occurrence_index,
                            'url_host'    => $metadata['host'],
                            'is_internal' => $metadata['is_internal'],
                        ],
                        ['%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d']
                    );
                    if ($inserted) {
                        $register_pending_link_insert($post->ID, $row_bytes);
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
            }

            } catch (\Throwable $caught_exception) {
                $batch_exception = $caught_exception;
                break;
            }

            if ($scan_run_token !== '') {
                $commit_result = blc_commit_dataset_refresh($table_name, 'link', $scan_run_token, 'link', [$post->ID]);
                if (is_wp_error($commit_result)) {
                    $batch_wp_error = $commit_result;
                    break;
                }

                if (isset($pending_link_inserts[$post->ID])) {
                    unset($pending_link_inserts[$post->ID]);
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
            blc_restore_dataset_refresh($table_name, 'link', $scan_run_token);
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
            return;
        }

        if ($wp_query->max_num_pages > ($batch + 1)) {
            $scheduled = wp_schedule_single_event(time() + $batch_delay_s, 'blc_check_batch', array($batch + 1, $is_full_scan, $bypass_rest_window));
            if (false === $scheduled) {
                error_log(sprintf('BLC: Failed to schedule next link batch #%d.', $batch + 1));
                do_action('blc_check_batch_schedule_failed', $batch + 1, $is_full_scan, $bypass_rest_window, 'next_batch');
            }
        } else {
            update_option('blc_last_check_time', current_time('timestamp', true));
            blc_clear_scan_cache($scan_cache_context);
        }

        if ($debug_mode) { error_log("--- Fin du scan LIENS (Lot #$batch) ---"); }
    } finally {
        wp_reset_postdata();
    }
}


/**
 * NOUVEAU : Fonction de scan DÉDIÉE AUX IMAGES <img>.
 * Déclenchée uniquement par le bouton sur la page des images.
 */
function blc_perform_image_check($batch = 0, $is_full_scan = true) { // Une analyse d'images est toujours complète
    global $wpdb;
    $debug_mode = get_option('blc_debug_mode', false);
    if ($debug_mode) { error_log("--- Début du scan IMAGES (Lot #$batch) ---"); }

    $table_name = $wpdb->prefix . 'blc_broken_links';
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

    $batch_size = 20;
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
        'post_status'    => 'publish',
        'posts_per_page' => $batch_size,
        'paged'          => $batch + 1,
    ];
    $query = new WP_Query($args);
    $posts = $query->posts;
    $checked_local_paths = [];

    $post_ids_in_batch = array_map('intval', wp_list_pluck($posts, 'ID'));
    $post_ids_in_batch = array_values(array_unique(array_filter($post_ids_in_batch, static function ($value) {
        return $value > 0;
    })));

    $scan_run_token = '';
    if (!empty($post_ids_in_batch)) {
        $scan_run_token = blc_generate_scan_run_token();
        $stage_result = blc_stage_dataset_refresh($table_name, 'image', $scan_run_token, $post_ids_in_batch);
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
                        blc_restore_dataset_refresh($table_name, 'image', $scan_run_token, [$post->ID]);
                    }
                    continue;
                }

            $permalink = get_permalink($post);

            foreach ($dom->getElementsByTagName('img') as $image_node) {
                $image_url = trim(wp_kses_decode_entities($image_node->getAttribute('src')));
                if ($image_url === '') { continue; }

                $original_image_url = $image_url;

                $normalized_image_url = blc_normalize_link_url(
                    $image_url,
                    $home_url_with_trailing_slash,
                    $site_scheme,
                    $permalink
                );

                if (!is_string($normalized_image_url) || $normalized_image_url === '') {
                    continue;
                }

                $image_host_raw = parse_url($normalized_image_url, PHP_URL_HOST);
                $image_host = is_string($image_host_raw) ? blc_normalize_remote_host($image_host_raw) : '';
                if ($image_host === '') { continue; }

                $hosts_match_site = ($image_host !== '' && $normalized_site_host !== '' && $image_host === $normalized_site_host);
                $hosts_match_upload = ($image_host !== '' && $upload_baseurl_host !== '' && $image_host === $upload_baseurl_host);
                if (!$hosts_match_site && !$hosts_match_upload) {
                    continue;
                }
                if (!$hosts_match_site && $hosts_match_upload) {
                    $is_safe_remote_host = blc_is_safe_remote_host($image_host);
                    if (!$is_safe_remote_host) {
                        if ($debug_mode) { error_log("  -> Image ignorée (IP non autorisée) : " . $normalized_image_url); }
                        continue;
                    }
                }
                if (empty($upload_baseurl) || empty($upload_basedir) || empty($normalized_basedir)) {
                    continue;
                }

                $image_scheme = parse_url($normalized_image_url, PHP_URL_SCHEME);
                $normalized_upload_baseurl = $upload_baseurl;
                if ($image_scheme && $upload_baseurl !== '') {
                    $normalized_upload_baseurl = set_url_scheme($upload_baseurl, $image_scheme);
                }

                $normalized_upload_baseurl_length = strlen($normalized_upload_baseurl);
                if (
                    $normalized_upload_baseurl_length === 0 ||
                    strncasecmp($normalized_image_url, $normalized_upload_baseurl, $normalized_upload_baseurl_length) !== 0
                ) {
                    continue;
                }

                $image_path_from_url = function_exists('wp_parse_url')
                    ? wp_parse_url($normalized_image_url, PHP_URL_PATH)
                    : parse_url($normalized_image_url, PHP_URL_PATH);
                if (!is_string($image_path_from_url) || $image_path_from_url === '') {
                    continue;
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
                    continue;
                }

                $relative_path = ltrim(substr($image_path_trimmed, $upload_base_path_trimmed_length), '/');
                if ($relative_path === '') {
                    continue;
                }

                $decoded_relative_path = rawurldecode($relative_path);
                $decoded_relative_path = ltrim($decoded_relative_path, '/\\');
                if ($decoded_relative_path === '') {
                    continue;
                }

                if (preg_match('#(^|[\\/])\.\.([\\/]|$)#', $decoded_relative_path)) {
                    continue;
                }

                $file_path = wp_normalize_path(trailingslashit($upload_basedir) . $decoded_relative_path);
                if (strpos($file_path, $normalized_basedir) !== 0) {
                    continue;
                }

                if (!isset($checked_local_paths[$file_path])) {
                    $checked_local_paths[$file_path] = file_exists($file_path);
                }

                if ($checked_local_paths[$file_path]) {
                    continue;
                }

                if ($debug_mode) { error_log("  -> Image Cassée Trouvée : " . $image_url); }
                $url_for_storage    = blc_prepare_url_for_storage($original_image_url);
                $image_filename = wp_basename($decoded_relative_path);
                $anchor_for_storage = blc_prepare_text_field_for_storage($image_filename);
                $metadata  = blc_get_url_metadata_for_storage($original_image_url, $normalized_image_url, $site_host_for_metadata);
                $row_bytes = blc_calculate_row_storage_footprint_bytes($url_for_storage, $anchor_for_storage, $post_title_for_storage);
                $inserted = $wpdb->insert(
                    $table_name,
                    [
                        'url'         => $url_for_storage,
                        'anchor'      => $anchor_for_storage,
                        'post_id'     => $post->ID,
                        'post_title'  => $post_title_for_storage,
                        'type'        => 'image',
                        'url_host'    => $metadata['host'],
                        'is_internal' => $metadata['is_internal'],
                    ],
                    ['%s', '%s', '%d', '%s', '%s', '%s', '%d']
                );
                if ($inserted) {
                    $register_pending_image_insert($post->ID, $row_bytes);
                    blc_adjust_dataset_storage_footprint('image', $row_bytes);
                }
            }

            } catch (\Throwable $caught_exception) {
                $batch_exception = $caught_exception;
                break;
            }

            if ($scan_run_token !== '') {
                $commit_result = blc_commit_dataset_refresh($table_name, 'image', $scan_run_token, 'image', [$post->ID]);
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
            blc_restore_dataset_refresh($table_name, 'image', $scan_run_token);
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

                return new WP_Error(
                    'blc_image_schedule_failed',
                    sprintf('Failed to schedule next image batch #%d.', $batch + 1)
                );
            }
        } else {
            if ($debug_mode) { error_log("--- Scan IMAGES terminé ---"); }
            update_option('blc_last_image_check_time', current_time('timestamp', true));
            blc_release_image_scan_lock($lock_token);
        }
    } finally {
        wp_reset_postdata();
    }
}
