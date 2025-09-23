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
    } else {
        $content_for_dom = $content;
    }

    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content_for_dom);

    libxml_clear_errors();
    libxml_use_internal_errors($previous_state);

    return $loaded ? $dom : null;
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
    $scan_method     = get_option('blc_scan_method', 'precise');
    $excluded_domains_raw = get_option('blc_excluded_domains', '');

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

        $safe_internal_hosts[strtolower($host)] = true;
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

        wp_schedule_single_event($next_timestamp, 'blc_check_batch', array($batch, $is_full_scan, $bypass_rest_window));
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
                    wp_schedule_single_event(time() + $retry_delay, 'blc_check_batch', array($batch, $is_full_scan, $bypass_rest_window));
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
                    return strtolower(trim((string) $domain));
                },
                explode("\n", $excluded_domains_raw)
            ),
            'strlen'
        );
    }

    // --- 3. Récupération des données et préparation ---
    $batch_size      = 20;
    $last_check_time = (int) get_option('blc_last_check_time', 0);
    $table_name      = $wpdb->prefix . 'blc_broken_links';

    $args = ['post_type' => 'any', 'post_status' => 'publish', 'posts_per_page' => $batch_size, 'paged' => $batch + 1];
    if (!$is_full_scan && $last_check_time > 0) {
        $threshold = gmdate('Y-m-d H:i:s', $last_check_time);
        $args['date_query'] = [[
            'column' => 'post_modified_gmt',
            'after'  => $threshold,
        ]];
    }

    $wp_query = new WP_Query($args);
    $posts = $wp_query->posts;

    $cleanup_sql = "DELETE blc FROM $table_name AS blc LEFT JOIN {$wpdb->posts} AS posts ON blc.post_id = posts.ID WHERE blc.type = %s AND posts.ID IS NULL";
    $wpdb->query($wpdb->prepare($cleanup_sql, 'link'));

    $post_ids_in_batch = wp_list_pluck($posts, 'ID');
    if (!empty($post_ids_in_batch)) {
        $post_ids_in_batch = array_map('intval', $post_ids_in_batch);
        $placeholders = implode(',', array_fill(0, count($post_ids_in_batch), '%d'));
        $delete_sql = "DELETE FROM $table_name WHERE post_id IN ($placeholders) AND type = %s";
        $wpdb->query($wpdb->prepare($delete_sql, array_merge($post_ids_in_batch, ['link'])));
    }

    // --- 4. Boucle d'analyse des LIENS <a> ---
    $upload_dir_info = wp_upload_dir();
    $upload_baseurl  = isset($upload_dir_info['baseurl']) ? trailingslashit($upload_dir_info['baseurl']) : '';
    $upload_basedir  = isset($upload_dir_info['basedir']) ? trailingslashit($upload_dir_info['basedir']) : '';
    if ($raw_home_url === '' && function_exists('home_url')) {
        $raw_home_url = home_url();
    }
    $site_url        = trailingslashit($raw_home_url);
    $site_scheme     = parse_url($raw_home_url, PHP_URL_SCHEME);
    if (!is_string($site_scheme) || $site_scheme === '') {
        $site_scheme = 'http';
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
        [403, 429, 503]
    );
    if (!is_array($temporary_http_statuses)) {
        $temporary_http_statuses = [];
    }
    $temporary_http_statuses = array_values(array_unique(array_map('intval', $temporary_http_statuses)));

    $temporary_retry_scheduled = false;

    foreach ($posts as $post) {
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
            continue;
        }

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

            $parsed_url = parse_url($normalized_url);
            if ($parsed_url === false) {
                continue;
            }

            if (empty($parsed_url['scheme']) || !in_array($parsed_url['scheme'], ['http', 'https'], true)) { continue; }

            if ($upload_basedir && $upload_base_host && isset($parsed_url['host']) && isset($parsed_url['path'])) {
                if (strcasecmp($upload_base_host, $parsed_url['host']) === 0 && $upload_base_path !== '' && strpos($parsed_url['path'], $upload_base_path) === 0) {
                    $relative_path = ltrim(substr($parsed_url['path'], strlen($upload_base_path)), '/');
                    if ($relative_path === '') {
                        continue;
                    }
                    $file_path = wp_normalize_path($upload_basedir . $relative_path);

                    if (!file_exists($file_path)) {
                        if ($debug_mode) { error_log("  -> Ressource locale introuvable : " . $normalized_url); }
                        $wpdb->insert(
                            $table_name,
                            [
                                'url'        => $url_for_storage,
                                'anchor'     => $anchor_for_storage,
                                'post_id'    => $post->ID,
                                'post_title' => $post_title_for_storage,
                                'type'       => 'link',
                            ],
                            ['%s', '%s', '%d', '%s', '%s']
                        );
                        continue;
                    }
                }
            }

            $host = parse_url($normalized_url, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                continue;
            }
            $normalized_host = strtolower($host);
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

            $is_internal_safe_host = isset($safe_internal_hosts[$normalized_host]);

            if (!$is_internal_safe_host && !blc_is_safe_remote_host($host)) {
                if ($debug_mode) { error_log("  -> Lien ignoré (IP non autorisée) : " . $normalized_url); }
                continue;
            }

            $head_request_args = [
                'timeout'             => 5,
                'limit_response_size' => 1024,
                'redirection'         => 5,
            ];

            $get_request_args = [
                'timeout'             => 10,
                'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0',
                'method'              => 'GET',
                'limit_response_size' => 131072,
            ];

            if ($scan_method === 'precise') {
                $response = null;
                $head_response = wp_safe_remote_head($normalized_url, $head_request_args);
                $needs_get_fallback = false;
                $fallback_due_to_temporary_status = false;

                if (is_wp_error($head_response)) {
                    $needs_get_fallback = true;
                } else {
                    $head_status = (int) wp_remote_retrieve_response_code($head_response);
                    if (in_array($head_status, $temporary_http_statuses, true)) {
                        $needs_get_fallback = true;
                        $fallback_due_to_temporary_status = true;
                    } elseif ($head_status === 405 || $head_status === 501) {
                        $needs_get_fallback = true;
                    } else {
                        $response = $head_response;
                    }
                }

                if ($needs_get_fallback) {
                    $response = wp_safe_remote_get($normalized_url, $get_request_args);
                }
            } else {
                $response = wp_safe_remote_head($normalized_url, $head_request_args);
                $fallback_due_to_temporary_status = false;
            }

            $should_insert_broken_link = false;
            $should_retry_later = false;
            $response_code = null;

            if (is_wp_error($response)) {
                if ($fallback_due_to_temporary_status) {
                    $should_retry_later = true;
                } else {
                    $should_insert_broken_link = true;
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

                    wp_schedule_single_event(
                        time() + $retry_delay,
                        'blc_check_batch',
                        array($batch, $is_full_scan, $bypass_rest_window)
                    );
                    $temporary_retry_scheduled = true;
                }
            } elseif ($should_insert_broken_link) {
                $wpdb->insert(
                    $table_name,
                    [
                        'url'        => $url_for_storage,
                        'anchor'     => $anchor_for_storage,
                        'post_id'    => $post->ID,
                        'post_title' => $post_title_for_storage,
                        'type'       => 'link',
                    ],
                    ['%s', '%s', '%d', '%s', '%s']
                );
            }
            usleep($link_delay_ms * 1000);
        }
    }

    // --- 5. Sauvegarde et planification ---
    if ($temporary_retry_scheduled) {
        if ($debug_mode) { error_log('Scan reporté : statut HTTP temporaire détecté, nouveau passage planifié.'); }
        return;
    }

    if ($wp_query->max_num_pages > ($batch + 1)) {
        wp_schedule_single_event(time() + $batch_delay_s, 'blc_check_batch', array($batch + 1, $is_full_scan, $bypass_rest_window));
    } else {
        update_option('blc_last_check_time', time());
    }

    if ($debug_mode) { error_log("--- Fin du scan LIENS (Lot #$batch) ---"); }
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

    // Si c'est le premier lot, on nettoie les anciens résultats
    if ($batch === 0) {
        $wpdb->query("DELETE FROM $table_name WHERE type = 'image'");
    }

    $batch_size = 20;
    $args = ['post_type' => 'any', 'post_status' => 'publish', 'posts_per_page' => $batch_size, 'paged' => $batch + 1];
    $query = new WP_Query($args);
    $posts = $query->posts;

    $upload_dir_info = wp_upload_dir();
    $upload_baseurl = isset($upload_dir_info['baseurl']) ? trailingslashit($upload_dir_info['baseurl']) : '';
    $upload_basedir = isset($upload_dir_info['basedir']) ? trailingslashit($upload_dir_info['basedir']) : '';
    $normalized_basedir = $upload_basedir !== '' ? wp_normalize_path($upload_basedir) : '';
    $upload_baseurl_host = '';
    if ($upload_baseurl !== '') {
        $upload_baseurl_host = function_exists('wp_parse_url')
            ? wp_parse_url($upload_baseurl, PHP_URL_HOST)
            : parse_url($upload_baseurl, PHP_URL_HOST);
        if (!is_string($upload_baseurl_host)) {
            $upload_baseurl_host = '';
        }
    }
    $site_url = home_url();
    $home_url_with_trailing_slash = trailingslashit($site_url);
    $site_scheme = parse_url($site_url, PHP_URL_SCHEME);
    if (!is_string($site_scheme) || $site_scheme === '') {
        $site_scheme = 'https';
    }

    $blog_charset = get_bloginfo('charset');
    if (empty($blog_charset)) { $blog_charset = 'UTF-8'; }

    foreach ($posts as $post) {
        if ($debug_mode) { error_log("Analyse IMAGES pour : '" . $post->post_title . "'"); }

        $post_title_for_storage = blc_prepare_text_field_for_storage($post->post_title);

        $dom = blc_create_dom_from_content($post->post_content, $blog_charset);
        if (!$dom instanceof DOMDocument) {
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

            $image_host = parse_url($normalized_image_url, PHP_URL_HOST);
            if ($image_host === false) { continue; }

            $site_host  = parse_url($site_url, PHP_URL_HOST);
            if ($site_host === false) { continue; }

            $hosts_match_site = !empty($image_host) && !empty($site_host) && strcasecmp($image_host, $site_host) === 0;
            $hosts_match_upload = !empty($image_host) && $upload_baseurl_host !== '' && strcasecmp($image_host, $upload_baseurl_host) === 0;
            if (!$hosts_match_site && !$hosts_match_upload) {
                continue;
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

            if (preg_match('#(^|[\\/])\.\.([\\/]|$)#', $relative_path)) {
                continue;
            }

            $file_path = wp_normalize_path(trailingslashit($upload_basedir) . $relative_path);
            if (strpos($file_path, $normalized_basedir) !== 0) {
                continue;
            }

            if (!file_exists($file_path)) {
                if ($debug_mode) { error_log("  -> Image Cassée Trouvée : " . $image_url); }
                $url_for_storage    = blc_prepare_url_for_storage($original_image_url);
                $image_filename = wp_basename($image_path);
                $anchor_for_storage = blc_prepare_text_field_for_storage($image_filename);
                $wpdb->insert(
                    $table_name,
                    [
                        'url'        => $url_for_storage,
                        'anchor'     => $anchor_for_storage,
                        'post_id'    => $post->ID,
                        'post_title' => $post_title_for_storage,
                        'type'       => 'image',
                    ],
                    ['%s', '%s', '%d', '%s', '%s']
                );
            }
        }
    }

    if ($query->max_num_pages > ($batch + 1)) {
        // On utilise un hook de batch différent pour ne pas interférer
        wp_schedule_single_event(time() + 60, 'blc_check_image_batch', array($batch + 1, true));
    } else {
        if ($debug_mode) { error_log("--- Scan IMAGES terminé ---"); }
        update_option('blc_last_image_check_time', current_time('timestamp'));
    }
}
