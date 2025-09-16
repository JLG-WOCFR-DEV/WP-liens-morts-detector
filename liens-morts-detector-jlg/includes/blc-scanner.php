<?php

if (!defined('ABSPATH')) exit;

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
    
    $rest_start_hour = get_option('blc_rest_start_hour', '08');
    $rest_end_hour   = get_option('blc_rest_end_hour', '20');
    $link_delay_ms   = max(0, (int) get_option('blc_link_delay', 200));
    $batch_delay_s   = max(0, (int) get_option('blc_batch_delay', 60));
    $scan_method     = get_option('blc_scan_method', 'precise');
    $excluded_domains_raw = get_option('blc_excluded_domains', '');

    // --- 2. Contrôles pré-analyse ---
    $current_hour = current_time('H');
    if ($current_hour >= $rest_start_hour && $current_hour < $rest_end_hour && !$is_full_scan) {
        if ($debug_mode) { error_log("Scan arrêté : dans la plage horaire de repos."); }
        return;
    }

    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $max_load_threshold = (float) apply_filters('blc_max_load_threshold', 2.0);

        if ($max_load_threshold > 0 && $load[0] > $max_load_threshold) {
            $retry_delay = (int) apply_filters('blc_load_retry_delay', 300);
            if ($retry_delay < 0) { $retry_delay = 0; }

            if ($debug_mode) { error_log("Scan reporté : charge serveur trop élevée (" . $load[0] . ")."); }
            wp_schedule_single_event(current_time('timestamp') + $retry_delay, 'blc_check_batch', array($batch, $is_full_scan));
            return;
        }
    }
    
    $excluded_domains = [];
    if (!empty($excluded_domains_raw)) {
        $excluded_domains = array_filter(array_map('trim', explode("\n", $excluded_domains_raw)));
    }

    // --- 3. Récupération des données et préparation ---
    $batch_size      = 20;
    $last_check_time = get_option('blc_last_check_time', 0);
    $table_name      = $wpdb->prefix . 'blc_broken_links';

    $args = ['post_type' => 'any', 'post_status' => 'publish', 'posts_per_page' => $batch_size, 'paged' => $batch + 1];
    if (!$is_full_scan && $last_check_time) {
        $args['date_query'] = [['column' => 'post_modified', 'after' => date('Y-m-d H:i:s', $last_check_time)]];
    }

    $wp_query = new WP_Query($args);
    $posts = $wp_query->posts;

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
    $site_url        = trailingslashit(home_url());

    $blog_charset = get_bloginfo('charset');
    if (empty($blog_charset)) { $blog_charset = 'UTF-8'; }

    foreach ($posts as $post) {
        if ($debug_mode) { error_log("Analyse LIENS pour : '" . $post->post_title . "'"); }

        $dom = blc_create_dom_from_content($post->post_content, $blog_charset);
        if (!$dom instanceof DOMDocument) {
            continue;
        }

        foreach ($dom->getElementsByTagName('a') as $link_node) {
            $url = trim(wp_kses_decode_entities($link_node->getAttribute('href')));
            if ($url === '') { continue; }

            $anchor_text = wp_strip_all_tags($link_node->textContent);
            $anchor_text = trim(preg_replace('/\s+/u', ' ', $anchor_text));
            if ($anchor_text === '') { $anchor_text = '[Lien sans texte]'; }

            $parsed_url = parse_url($url);
            if ($parsed_url === false) {
                continue;
            }

            if (empty($parsed_url['scheme'])) {
                $url = $site_url . ltrim($url, '/');
                $parsed_url = parse_url($url);
                if ($parsed_url === false) {
                    continue;
                }
            }
            elseif (!in_array($parsed_url['scheme'], ['http', 'https'])) { continue; }

            if ($upload_baseurl && $upload_basedir && strpos($url, $upload_baseurl) === 0) {
                $relative_path = ltrim(substr($url, strlen($upload_baseurl)), '/');
                $file_path = wp_normalize_path($upload_basedir . $relative_path);

                if (!file_exists($file_path)) {
                    if ($debug_mode) { error_log("  -> Ressource locale introuvable : " . $url); }
                    $wpdb->insert(
                        $table_name,
                        [
                            'url'        => $url,
                            'anchor'     => $anchor_text,
                            'post_id'    => $post->ID,
                            'post_title' => $post->post_title,
                            'type'       => 'link',
                        ],
                        ['%s', '%s', '%d', '%s', '%s']
                    );
                    continue;
                }
            }

            $host = parse_url($url, PHP_URL_HOST);
            if ($host === false) {
                continue;
            }
            $is_excluded = false;
            if (!empty($excluded_domains) && !empty($host)) {
                foreach ($excluded_domains as $domain_to_exclude) {
                    if (substr($host, -strlen($domain_to_exclude)) === $domain_to_exclude) { $is_excluded = true; break; }
                }
            }

            if ($is_excluded) { continue; }

            $response = ($scan_method === 'precise') ? wp_safe_remote_get($url, ['timeout' => 10, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0', 'method' => 'GET']) : wp_safe_remote_head($url, ['timeout' => 5]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
                $wpdb->insert(
                    $table_name,
                    [
                        'url'        => $url,
                        'anchor'     => $anchor_text,
                        'post_id'    => $post->ID,
                        'post_title' => $post->post_title,
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
        wp_schedule_single_event(current_time('timestamp') + $batch_delay_s, 'blc_check_batch', array($batch + 1, $is_full_scan));
    } else {
        update_option('blc_last_check_time', current_time('timestamp'));
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
    $site_url = home_url();

    $blog_charset = get_bloginfo('charset');
    if (empty($blog_charset)) { $blog_charset = 'UTF-8'; }

    foreach ($posts as $post) {
        if ($debug_mode) { error_log("Analyse IMAGES pour : '" . $post->post_title . "'"); }

        $dom = blc_create_dom_from_content($post->post_content, $blog_charset);
        if (!$dom instanceof DOMDocument) {
            continue;
        }

        foreach ($dom->getElementsByTagName('img') as $image_node) {
            $image_url = trim(wp_kses_decode_entities($image_node->getAttribute('src')));
            if ($image_url === '') { continue; }

            $image_host = parse_url($image_url, PHP_URL_HOST);
            if ($image_host === false) { continue; }

            $site_host  = parse_url($site_url, PHP_URL_HOST);
            if ($site_host === false) { continue; }

            if (!empty($image_host) && !empty($site_host) && strcasecmp($image_host, $site_host) !== 0) {
                continue;
            }
            $file_path = str_replace($upload_dir_info['baseurl'], $upload_dir_info['basedir'], $image_url);
            if (!file_exists($file_path)) {
                if ($debug_mode) { error_log("  -> Image Cassée Trouvée : " . $image_url); }
                $wpdb->insert(
                    $table_name,
                    [
                        'url'        => $image_url,
                        'anchor'     => basename($image_url),
                        'post_id'    => $post->ID,
                        'post_title' => $post->post_title,
                        'type'       => 'image',
                    ],
                    ['%s', '%s', '%d', '%s', '%s']
                );
            }
        }
    }

    if ($query->max_num_pages > ($batch + 1)) {
        // On utilise un hook de batch différent pour ne pas interférer
        wp_schedule_single_event(current_time('timestamp') + 60, 'blc_check_image_batch', array($batch + 1, true));
    } else {
        if ($debug_mode) { error_log("--- Scan IMAGES terminé ---"); }
    }
}
