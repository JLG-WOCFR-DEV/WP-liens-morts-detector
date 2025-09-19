<?php

if (!defined('ABSPATH')) exit;

if (!function_exists('blc_normalize_hour_option')) {
    require_once __DIR__ . '/blc-utils.php';
}

/**
 * Normalize a link URL while preserving the original value for storage/display.
 *
 * @param string      $url        Original URL extracted from the content.
 * @param string      $site_url   Site URL with trailing slash.
 * @param string|null $site_scheme Current site scheme (http/https).
 *
 * @return string Normalized URL suitable for validation.
 */
function blc_normalize_link_url($url, $site_url, $site_scheme = null) {
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

    $site_url = rtrim((string) $site_url, '/') . '/';

    if (isset($parsed_url['path']) && strpos($parsed_url['path'], '/') === 0) {
        $base = rtrim($site_url, '/');
        return $base . $url;
    }

    return $site_url . ltrim($url, '/');
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
function blc_perform_check($batch = 0, $is_full_scan = false) {
    global $wpdb;

    // --- 1. Récupération des réglages ---
    $debug_mode = get_option('blc_debug_mode', false);
    if ($debug_mode) { error_log("--- Début du scan LIENS (Lot #$batch) ---"); }

    $current_hook = function_exists('current_filter') ? current_filter() : '';
    if (!$is_full_scan && $current_hook === 'blc_check_links') {
        $is_full_scan = true;
    }
    
    $rest_start_hour_option = get_option('blc_rest_start_hour', '08');
    $rest_end_hour_option   = get_option('blc_rest_end_hour', '20');
    $rest_start_hour = (int) blc_normalize_hour_option($rest_start_hour_option, '08');
    $rest_end_hour   = (int) blc_normalize_hour_option($rest_end_hour_option, '20');
    $link_delay_ms   = max(0, (int) get_option('blc_link_delay', 200));
    $batch_delay_s   = max(0, (int) get_option('blc_batch_delay', 60));
    $scan_method     = get_option('blc_scan_method', 'precise');
    $excluded_domains_raw = get_option('blc_excluded_domains', '');

    // --- 2. Contrôles pré-analyse ---
    $current_hour = (int) current_time('H');
    $is_in_rest_window = false;
    if ($rest_start_hour <= $rest_end_hour) {
        $is_in_rest_window = ($current_hour >= $rest_start_hour && $current_hour < $rest_end_hour);
    } else {
        $is_in_rest_window = ($current_hour >= $rest_start_hour || $current_hour < $rest_end_hour);
    }

    if ($is_in_rest_window && !$is_full_scan) {
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

        wp_schedule_single_event($next_timestamp, 'blc_check_batch', array($batch, $is_full_scan));
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
                    wp_schedule_single_event(time() + $retry_delay, 'blc_check_batch', array($batch, $is_full_scan));
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
    $raw_home_url    = home_url();
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

    foreach ($posts as $post) {
        if ($debug_mode) { error_log("Analyse LIENS pour : '" . $post->post_title . "'"); }

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

            $normalized_url = blc_normalize_link_url($original_url, $site_url, $site_scheme);
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

            if (!blc_is_safe_remote_host($host)) {
                if ($debug_mode) { error_log("  -> Lien ignoré (IP non autorisée) : " . $normalized_url); }
                continue;
            }

            $response = ($scan_method === 'precise') ? wp_safe_remote_get($normalized_url, ['timeout' => 10, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0', 'method' => 'GET']) : wp_safe_remote_head($normalized_url, ['timeout' => 5]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
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
    if ($wp_query->max_num_pages > ($batch + 1)) {
        wp_schedule_single_event(time() + $batch_delay_s, 'blc_check_batch', array($batch + 1, $is_full_scan));
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
    $site_url = home_url();

    $blog_charset = get_bloginfo('charset');
    if (empty($blog_charset)) { $blog_charset = 'UTF-8'; }

    foreach ($posts as $post) {
        if ($debug_mode) { error_log("Analyse IMAGES pour : '" . $post->post_title . "'"); }

        $post_title_for_storage = blc_prepare_text_field_for_storage($post->post_title);

        $dom = blc_create_dom_from_content($post->post_content, $blog_charset);
        if (!$dom instanceof DOMDocument) {
            continue;
        }

        foreach ($dom->getElementsByTagName('img') as $image_node) {
            $image_url = trim(wp_kses_decode_entities($image_node->getAttribute('src')));
            if ($image_url === '') { continue; }

            $original_image_url = $image_url;

            if (strpos($image_url, '//') === 0) {
                $site_scheme = parse_url($site_url, PHP_URL_SCHEME);
                if (!is_string($site_scheme) || $site_scheme === '') {
                    $site_scheme = 'https';
                }
                $image_url = set_url_scheme($image_url, $site_scheme);
            } elseif (strpos($image_url, '/') === 0) {
                $image_url = trailingslashit(home_url()) . ltrim($image_url, '/');
            }

            $image_host = parse_url($image_url, PHP_URL_HOST);
            if ($image_host === false) { continue; }

            $site_host  = parse_url($site_url, PHP_URL_HOST);
            if ($site_host === false) { continue; }

            if (!empty($image_host) && !empty($site_host) && strcasecmp($image_host, $site_host) !== 0) {
                continue;
            }
            if (empty($upload_baseurl) || empty($upload_basedir) || empty($normalized_basedir)) {
                continue;
            }

            $image_scheme = parse_url($image_url, PHP_URL_SCHEME);
            $normalized_upload_baseurl = $upload_baseurl;
            if ($image_scheme && $upload_baseurl !== '') {
                $normalized_upload_baseurl = set_url_scheme($upload_baseurl, $image_scheme);
            }

            if (strpos($image_url, $normalized_upload_baseurl) !== 0) {
                continue;
            }

            $image_path_from_url = function_exists('wp_parse_url')
                ? wp_parse_url($image_url, PHP_URL_PATH)
                : parse_url($image_url, PHP_URL_PATH);
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
            $image_path_trimmed = ltrim($image_path, '/');

            if ($upload_base_path_trimmed === '' || strpos($image_path_trimmed, $upload_base_path_trimmed) !== 0) {
                continue;
            }

            $relative_path = ltrim(substr($image_path_trimmed, strlen($upload_base_path_trimmed)), '/');
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
    }
}
