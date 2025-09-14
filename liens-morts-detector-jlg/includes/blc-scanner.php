<?php

if (!defined('ABSPATH')) exit;

/**
 * Fonction de scan DÉDIÉE AUX LIENS <a>.
 * Déclenchée par la planification et le bouton principal.
 */
function blc_perform_check($batch = 0, $is_full_scan = false) {
    // --- 1. Récupération des réglages ---
    $debug_mode = get_option('blc_debug_mode', false);
    if ($debug_mode) { error_log("--- Début du scan LIENS (Lot #$batch) ---"); }
    
    $rest_start_hour = get_option('blc_rest_start_hour', '08');
    $rest_end_hour   = get_option('blc_rest_end_hour', '20');
    $link_delay_ms   = get_option('blc_link_delay', 200);
    $batch_delay_s   = get_option('blc_batch_delay', 60);
    $scan_method     = get_option('blc_scan_method', 'precise');
    $excluded_domains_raw = get_option('blc_excluded_domains', '');

    // --- 2. Contrôles pré-analyse ---
    $current_hour = (new DateTime("now", new DateTimeZone('Europe/Paris')))->format('H');
    if ($current_hour >= $rest_start_hour && $current_hour < $rest_end_hour && !$is_full_scan) {
        if ($debug_mode) { error_log("Scan arrêté : dans la plage horaire de repos."); }
        return;
    }

    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if ($load[0] > 2.0) {
            if ($debug_mode) { error_log("Scan reporté : charge serveur trop élevée (" . $load[0] . ")."); }
            wp_schedule_single_event(time() + 300, 'blc_check_batch', array($batch, $is_full_scan));
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
    $broken_links    = get_option('blc_broken_links', array());

    $args = ['post_type' => 'any', 'post_status' => 'publish', 'posts_per_page' => $batch_size, 'paged' => $batch + 1];
    if (!$is_full_scan && $last_check_time) {
        $args['date_query'] = [['column' => 'post_modified', 'after' => date('Y-m-d H:i:s', $last_check_time)]];
    }

    $query = new WP_Query($args);
    $posts = $query->posts;

    $post_ids_in_batch = wp_list_pluck($posts, 'ID');
    if (!empty($post_ids_in_batch)) {
        $broken_links = array_filter($broken_links, function($link) use ($post_ids_in_batch) { return !in_array($link['post_id'], $post_ids_in_batch); });
    }

    // --- 4. Boucle d'analyse des LIENS <a> ---
    foreach ($posts as $post) {
        if ($debug_mode) { error_log("Analyse LIENS pour : '" . $post->post_title . "'"); }

        preg_match_all('/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', $post->post_content, $matches_a, PREG_SET_ORDER);
        if (!empty($matches_a)) {
            foreach ($matches_a as $match) {
                $url = $match[1];
                $anchor_text = wp_strip_all_tags($match[2]);
                if (empty(trim($anchor_text))) { $anchor_text = '[Lien sans texte]'; }
                
                $parsed_url = parse_url($url);
                if (empty($parsed_url['scheme'])) { $url = home_url($url); } 
                elseif (!in_array($parsed_url['scheme'], ['http', 'https'])) { continue; }

                $host = parse_url($url, PHP_URL_HOST);
                $is_excluded = false;
                if (!empty($excluded_domains) && !empty($host)) {
                    foreach ($excluded_domains as $domain_to_exclude) {
                        if (substr($host, -strlen($domain_to_exclude)) === $domain_to_exclude) { $is_excluded = true; break; }
                    }
                }

                if ($is_excluded) { continue; }

                $response = ($scan_method === 'precise') ? wp_remote_get($url, ['timeout' => 10, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0', 'method' => 'GET']) : wp_remote_head($url, ['timeout' => 5]);
                
                if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
                    $broken_links[] = ['url' => $url, 'anchor' => $anchor_text, 'post_id' => $post->ID, 'post_title' => $post->post_title];
                }
                usleep($link_delay_ms * 1000);
            }
        }
    }

    // --- 5. Sauvegarde et planification ---
    update_option('blc_broken_links', array_values($broken_links));

    if ($query->max_num_pages > ($batch + 1)) {
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
    $debug_mode = get_option('blc_debug_mode', false);
    if ($debug_mode) { error_log("--- Début du scan IMAGES (Lot #$batch) ---"); }

    // Si c'est le premier lot, on nettoie les anciens résultats
    if ($batch === 0) {
        delete_option('blc_broken_images');
    }
    $broken_images = get_option('blc_broken_images', array());

    $batch_size = 20;
    $args = ['post_type' => 'any', 'post_status' => 'publish', 'posts_per_page' => $batch_size, 'paged' => $batch + 1];
    $query = new WP_Query($args);
    $posts = $query->posts;

    foreach ($posts as $post) {
        if ($debug_mode) { error_log("Analyse IMAGES pour : '" . $post->post_title . "'"); }

        preg_match_all('/<img\s[^>]*src\s*=\s*["\']([^"\']+)["\']/i', $post->post_content, $matches_img);
        if (!empty($matches_img[1])) {
            $upload_dir_info = wp_upload_dir();
            $site_url = home_url();
            foreach ($matches_img[1] as $image_url) {
                if (strpos($image_url, $site_url) === false) { continue; }
                $file_path = str_replace($upload_dir_info['baseurl'], $upload_dir_info['basedir'], $image_url);
                if (!file_exists($file_path)) {
                    if ($debug_mode) { error_log("  -> Image Cassée Trouvée : " . $image_url); }
                    $broken_images[] = ['url' => $image_url, 'anchor' => basename($image_url), 'post_id' => $post->ID, 'post_title' => $post->post_title];
                }
            }
        }
    }

    update_option('blc_broken_images', array_values($broken_images));

    if ($query->max_num_pages > ($batch + 1)) {
        // On utilise un hook de batch différent pour ne pas interférer
        wp_schedule_single_event(time() + 60, 'blc_check_image_batch', array($batch + 1, true));
    } else {
        if ($debug_mode) { error_log("--- Scan IMAGES terminé ---"); }
    }
}
