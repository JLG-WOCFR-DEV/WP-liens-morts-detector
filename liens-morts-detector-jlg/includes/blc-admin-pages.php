<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/Admin/DashboardCache.php';

if (!function_exists('blc_normalize_hour_option')) {
    require_once __DIR__ . '/blc-utils.php';
}

if (!function_exists('blc_reset_link_check_schedule')) {
    require_once __DIR__ . '/blc-cron.php';
}

/**
 * Crée le menu principal et les sous-menus pour les rapports et les réglages.
 */
function blc_add_admin_menu() {
    add_menu_page(
        __('Liens Morts', 'liens-morts-detector-jlg'),
        __('Liens Morts', 'liens-morts-detector-jlg'),
        'manage_options',
        'blc-dashboard',
        'blc_dashboard_links_page',
        'dashicons-editor-unlink'
    );
    add_submenu_page(
        'blc-dashboard',
        __('Liens Cassés', 'liens-morts-detector-jlg'),
        __('Liens Cassés', 'liens-morts-detector-jlg'),
        'manage_options',
        'blc-dashboard',
        'blc_dashboard_links_page'
    );
    add_submenu_page(
        'blc-dashboard',
        __('Images Cassées', 'liens-morts-detector-jlg'),
        __('Images Cassées', 'liens-morts-detector-jlg'),
        'manage_options',
        'blc-images-dashboard',
        'blc_dashboard_images_page'
    );
    add_submenu_page(
        'blc-dashboard',
        __('Historique', 'liens-morts-detector-jlg'),
        __('Historique', 'liens-morts-detector-jlg'),
        'manage_options',
        'blc-history',
        'blc_scan_history_page'
    );
    add_submenu_page(
        'blc-dashboard',
        __('Réglages', 'liens-morts-detector-jlg'),
        __('Réglages', 'liens-morts-detector-jlg'),
        'manage_options',
        'blc-settings',
        'blc_settings_page'
    );
}

/**
 * Rend la navigation principale des pages du tableau de bord du plugin.
 *
 * @param string $active_tab Identifiant de l'onglet actif.
 */
function blc_render_dashboard_tabs($active_tab) {
    $tabs = array(
        'links'    => array(
            'label' => __('Liens', 'liens-morts-detector-jlg'),
            'page'  => 'blc-dashboard',
        ),
        'images'   => array(
            'label' => __('Images', 'liens-morts-detector-jlg'),
            'page'  => 'blc-images-dashboard',
        ),
        'history'  => array(
            'label' => __('Historique', 'liens-morts-detector-jlg'),
            'page'  => 'blc-history',
        ),
        'settings' => array(
            'label' => __('Réglages', 'liens-morts-detector-jlg'),
            'page'  => 'blc-settings',
        ),
    );

    $navigation_label = __('Navigation du tableau de bord Liens Morts', 'liens-morts-detector-jlg');

    echo '<nav class="blc-admin-tabs" aria-label="' . esc_attr($navigation_label) . '"><ul class="blc-admin-tabs__list">';

    foreach ($tabs as $tab_key => $tab) {
        $is_active      = ($tab_key === $active_tab);
        $aria_current   = $is_active ? ' aria-current="page"' : '';
        $active_class   = $is_active ? ' is-active' : '';
        $tab_url        = function_exists('admin_url')
            ? admin_url('admin.php?page=' . $tab['page'])
            : 'admin.php?page=' . $tab['page'];
        $link_attributes = sprintf(
            ' class="blc-admin-tabs__link%s" href="%s"%s',
            esc_attr($active_class),
            esc_url($tab_url),
            $aria_current
        );

        echo '<li class="blc-admin-tabs__item"><a' . $link_attributes . '>' . esc_html($tab['label']) . '</a></li>';
    }

    echo '</ul></nav>';
}

/**
 * Retrieve the domains generating the highest volume of active broken links.
 *
 * @param int $limit Maximum number of domains to return.
 *
 * @return array<int,array{host:string,count:int,client_errors:int,server_errors:int,redirects:int,other:int}>
 */
function blc_get_top_broken_link_domains($limit = 5) {
    $limit = (int) $limit;
    if ($limit <= 0) {
        $limit = 5;
    }

    $cached_domains = blc_get_cached_top_domain_stats($limit);
    if (is_array($cached_domains)) {
        return $cached_domains;
    }

    $link_row_types = blc_get_dataset_row_types('link');
    if (empty($link_row_types)) {
        $link_row_types = array('link');
    }

    global $wpdb;

    $table_name    = $wpdb->prefix . 'blc_broken_links';
    $placeholders  = implode(',', array_fill(0, count($link_row_types), '%s'));
    $prepared_args = array_merge($link_row_types, array($limit));

    $sql = $wpdb->prepare(
        "
        SELECT url_host,
               COUNT(*) AS total_count,
               SUM(CASE WHEN http_status BETWEEN 400 AND 499 THEN 1 ELSE 0 END) AS client_error_count,
               SUM(CASE WHEN http_status BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS server_error_count,
               SUM(CASE WHEN http_status BETWEEN 300 AND 399 THEN 1 ELSE 0 END) AS redirect_count,
               SUM(CASE WHEN http_status IS NULL OR http_status = 0 OR http_status >= 600 THEN 1 ELSE 0 END) AS other_count
          FROM $table_name
         WHERE ignored_at IS NULL
           AND url_host IS NOT NULL
           AND url_host <> ''
           AND type IN ($placeholders)
         GROUP BY url_host
         ORDER BY total_count DESC
         LIMIT %d
        ",
        $prepared_args
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!is_array($rows)) {
        $rows = array();
    }

    $domains = array();

    foreach ($rows as $row) {
        $host = isset($row['url_host']) ? (string) $row['url_host'] : '';
        if ($host === '') {
            continue;
        }

        $total = isset($row['total_count']) ? (int) $row['total_count'] : 0;
        if ($total <= 0) {
            continue;
        }

        $domains[] = array(
            'host'          => $host,
            'count'         => $total,
            'client_errors' => isset($row['client_error_count']) ? (int) $row['client_error_count'] : 0,
            'server_errors' => isset($row['server_error_count']) ? (int) $row['server_error_count'] : 0,
            'redirects'     => isset($row['redirect_count']) ? (int) $row['redirect_count'] : 0,
            'other'         => isset($row['other_count']) ? (int) $row['other_count'] : 0,
        );
    }

    blc_store_top_domain_stats_cache($limit, $domains);

    return $domains;
}

/**
 * Build a chronological list of processed item counts for the most recent scans.
 *
 * @param int $limit Maximum number of points to expose.
 *
 * @return array<int,array{timestamp:int,value:int,label:string,formatted:string}>
 */
function blc_get_link_scan_trend_points($limit = 12) {
    if (!function_exists('blc_get_link_scan_history')) {
        return array();
    }

    $limit       = max(1, (int) $limit);
    $history     = blc_get_link_scan_history();
    $points      = array();
    $date_format = 'd M';

    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $metrics = array();
        if (isset($entry['metrics']) && is_array($entry['metrics'])) {
            $metrics = $entry['metrics'];
        }

        $timestamp = 0;
        foreach (array('ended_at', 'started_at', 'scheduled_at') as $time_key) {
            if (!empty($entry[$time_key])) {
                $timestamp = (int) $entry[$time_key];
                break;
            }
        }

        if ($timestamp <= 0) {
            continue;
        }

        $value = null;
        foreach (array('processed_items', 'total_items') as $metric_key) {
            if (isset($metrics[$metric_key])) {
                $value = max(0, (int) $metrics[$metric_key]);
                break;
            }

            if (isset($entry[$metric_key])) {
                $value = max(0, (int) $entry[$metric_key]);
                break;
            }
        }

        if (null === $value) {
            continue;
        }

        $points[] = array(
            'timestamp' => $timestamp,
            'value'     => $value,
            'label'     => function_exists('wp_date')
                ? wp_date($date_format, $timestamp)
                : date($date_format, $timestamp),
            'formatted' => number_format_i18n($value),
        );

        if (count($points) >= $limit) {
            break;
        }
    }

    return array_reverse($points);
}

/**
 * Derive textual highlights from a set of trend points.
 *
 * @param array<int,array{timestamp:int,value:int,label:string,formatted:string}> $points Trend points.
 *
 * @return array{latest_label:string,delta_label:string,direction:string}
 */
function blc_summarize_link_scan_trend(array $points) {
    if ($points === array()) {
        return array(
            'latest_label' => __('Pas encore de données pour la dernière analyse.', 'liens-morts-detector-jlg'),
            'delta_label'  => __('La tendance sera affichée après au moins deux analyses.', 'liens-morts-detector-jlg'),
            'direction'    => 'flat',
        );
    }

    $latest_point = end($points);
    $latest_value = isset($latest_point['value']) ? (int) $latest_point['value'] : 0;
    $latest_label = sprintf(
        /* translators: %s: number of URLs checked. */
        __('Dernière analyse : %s URL contrôlées.', 'liens-morts-detector-jlg'),
        number_format_i18n($latest_value)
    );

    $previous_point = null;
    if (count($points) > 1) {
        $previous_point = prev($points);
        // Reset internal pointer for subsequent consumers.
        end($points);
    }

    if (null === $previous_point) {
        return array(
            'latest_label' => $latest_label,
            'delta_label'  => __('En attente d’une nouvelle analyse pour calculer une tendance.', 'liens-morts-detector-jlg'),
            'direction'    => 'flat',
        );
    }

    $previous_value = isset($previous_point['value']) ? (int) $previous_point['value'] : 0;
    $delta          = $latest_value - $previous_value;

    if ($delta === 0) {
        return array(
            'latest_label' => $latest_label,
            'delta_label'  => __('Volume stable par rapport à l’analyse précédente.', 'liens-morts-detector-jlg'),
            'direction'    => 'flat',
        );
    }

    $direction = ($delta > 0) ? 'up' : 'down';
    $delta_abs = abs($delta);
    $percent   = 0.0;

    if ($previous_value > 0) {
        $percent = ($delta_abs / $previous_value) * 100;
    }

    if ($delta > 0) {
        $delta_label = sprintf(
            /* translators: 1: additional URLs, 2: percentage increase. */
            __('Progression de %1$s URL (+%2$s %) vs. l’analyse précédente.', 'liens-morts-detector-jlg'),
            number_format_i18n($delta_abs),
            number_format_i18n($percent, 1)
        );
    } else {
        $delta_label = sprintf(
            /* translators: 1: missing URLs, 2: percentage drop. */
            __('Baisse de %1$s URL (−%2$s %) vs. l’analyse précédente.', 'liens-morts-detector-jlg'),
            number_format_i18n($delta_abs),
            number_format_i18n($percent, 1)
        );
    }

    return array(
        'latest_label' => $latest_label,
        'delta_label'  => $delta_label,
        'direction'    => $direction,
    );
}

/**
 * Compute a severity overview for the current broken link distribution.
 *
 * @param array<string,int> $count_keys Status counters.
 *
 * @return array<int,array{
 *     key:string,
 *     label:string,
 *     count:int,
 *     formatted_count:string,
 *     percentage:float,
 *     formatted_percentage:string,
 *     class:string
 * }>
 */
function blc_get_link_severity_overview(array $count_keys) {
    $segments = array(
        array(
            'key'   => 'critical',
            'label' => __('Erreurs 5xx (serveur)', 'liens-morts-detector-jlg'),
            'count' => isset($count_keys['server_error_count']) ? (int) $count_keys['server_error_count'] : 0,
            'class' => 'is-critical',
        ),
        array(
            'key'   => 'high',
            'label' => __('Erreurs 4xx (client)', 'liens-morts-detector-jlg'),
            'count' => isset($count_keys['not_found_count']) ? (int) $count_keys['not_found_count'] : 0,
            'class' => 'is-high',
        ),
        array(
            'key'   => 'medium',
            'label' => __('Redirections à vérifier', 'liens-morts-detector-jlg'),
            'count' => isset($count_keys['redirect_count']) ? (int) $count_keys['redirect_count'] : 0,
            'class' => 'is-medium',
        ),
        array(
            'key'   => 'low',
            'label' => __('Liens à re-tester', 'liens-morts-detector-jlg'),
            'count' => isset($count_keys['needs_recheck_count']) ? (int) $count_keys['needs_recheck_count'] : 0,
            'class' => 'is-low',
        ),
    );

    $total = 0;
    foreach ($segments as $segment) {
        $total += max(0, (int) $segment['count']);
    }

    $total = max(1, $total);

    foreach ($segments as &$segment) {
        $count                       = max(0, (int) $segment['count']);
        $segment['count']            = $count;
        $segment['formatted_count']  = number_format_i18n($count);
        $segment['percentage']       = ($count > 0) ? (($count / $total) * 100) : 0.0;
        $segment['formatted_percentage'] = number_format_i18n($segment['percentage'], 1);
    }
    unset($segment);

    return $segments;
}

/**
 * Build a prioritized action queue based on the most impacted domains.
 *
 * @param array<int,array<string,int|string>> $top_domains Domain aggregates.
 * @param string                               $dashboard_base_url Base dashboard URL.
 * @param array<string,int>                    $count_keys Status counters for ratios.
 *
 * @return array<int,array{
 *     title:string,
 *     description:string,
 *     severity_label:string,
 *     severity_class:string,
 *     cta_url:string,
 *     cta_label:string,
 *     meta:string
 * }>
 */
function blc_build_priority_action_queue(array $top_domains, $dashboard_base_url, array $count_keys) {
    if ($top_domains === array()) {
        return array();
    }

    $actions      = array();
    $total_active = isset($count_keys['active_count']) ? max(0, (int) $count_keys['active_count']) : 0;
    $total_active = max(1, $total_active);

    $domain_slice = array_slice($top_domains, 0, 3);

    foreach ($domain_slice as $domain) {
        if (!is_array($domain) || empty($domain['host'])) {
            continue;
        }

        $host          = (string) $domain['host'];
        $total_count   = isset($domain['count']) ? max(0, (int) $domain['count']) : 0;
        $server_errors = isset($domain['server_errors']) ? max(0, (int) $domain['server_errors']) : 0;
        $client_errors = isset($domain['client_errors']) ? max(0, (int) $domain['client_errors']) : 0;
        $redirects     = isset($domain['redirects']) ? max(0, (int) $domain['redirects']) : 0;
        $others        = isset($domain['other']) ? max(0, (int) $domain['other']) : 0;

        if ($total_count === 0) {
            continue;
        }

        $severity_class = 'is-low';
        $severity_label = __('À surveiller', 'liens-morts-detector-jlg');
        $target_link_type = 'all';

        if ($server_errors > 0) {
            $severity_class  = 'is-critical';
            $severity_label  = __('Critique', 'liens-morts-detector-jlg');
            $target_link_type = 'status_5xx';
        } elseif ($client_errors > 0) {
            $severity_class  = 'is-high';
            $severity_label  = __('Prioritaire', 'liens-morts-detector-jlg');
            $target_link_type = 'status_404_410';
        } elseif ($redirects > 0) {
            $severity_class  = 'is-medium';
            $severity_label  = __('À optimiser', 'liens-morts-detector-jlg');
            $target_link_type = 'status_redirects';
        }

        $breakdown_parts = array();
        if ($server_errors > 0) {
            $breakdown_parts[] = sprintf(
                /* translators: %s: number of server errors. */
                __('%s erreur(s) serveur', 'liens-morts-detector-jlg'),
                number_format_i18n($server_errors)
            );
        }
        if ($client_errors > 0) {
            $breakdown_parts[] = sprintf(
                /* translators: %s: number of client errors. */
                __('%s erreur(s) client', 'liens-morts-detector-jlg'),
                number_format_i18n($client_errors)
            );
        }
        if ($redirects > 0) {
            $breakdown_parts[] = sprintf(
                /* translators: %s: number of redirects. */
                __('%s redirection(s)', 'liens-morts-detector-jlg'),
                number_format_i18n($redirects)
            );
        }
        if ($others > 0) {
            $breakdown_parts[] = sprintf(
                /* translators: %s: number of other issues. */
                __('%s statut(s) divers', 'liens-morts-detector-jlg'),
                number_format_i18n($others)
            );
        }

        $description = sprintf(
            /* translators: 1: number of broken links, 2: domain, 3: issue breakdown. */
            __('Traitez %1$s lien(s) sur %2$s — %3$s.', 'liens-morts-detector-jlg'),
            number_format_i18n($total_count),
            $host,
            implode(' · ', $breakdown_parts)
        );

        if ($breakdown_parts === array()) {
            $description = sprintf(
                /* translators: 1: number of broken links, 2: domain name. */
                __('Traitez %1$s lien(s) signalé(s) sur %2$s.', 'liens-morts-detector-jlg'),
                number_format_i18n($total_count),
                $host
            );
        }

        $percentage = min(100, max(0, ($total_count / $total_active) * 100));
        $meta       = sprintf(
            /* translators: %s: percentage of active broken links. */
            __('%s %% des liens cassés actifs.', 'liens-morts-detector-jlg'),
            number_format_i18n($percentage, 1)
        );

        $cta_url = $dashboard_base_url;
        if ($target_link_type !== 'all' && function_exists('add_query_arg')) {
            $cta_url = add_query_arg('link_type', $target_link_type, $cta_url);
        }

        if (function_exists('add_query_arg')) {
            $cta_url = add_query_arg('s', $host, $cta_url);
        } else {
            $separator = (false === strpos($cta_url, '?')) ? '?' : '&';
            $cta_url  .= $separator . 's=' . rawurlencode($host);
        }

        $actions[] = array(
            'title'          => sprintf(
                /* translators: %s: domain name. */
                __('Stabiliser %s', 'liens-morts-detector-jlg'),
                $host
            ),
            'description'    => $description,
            'severity_label' => $severity_label,
            'severity_class' => $severity_class,
            'cta_url'        => $cta_url,
            'cta_label'      => __('Ouvrir la liste filtrée', 'liens-morts-detector-jlg'),
            'meta'           => $meta,
        );
    }

    return $actions;
}

/**
 * Produce a contextual call-to-action label for a statistic card.
 *
 * @param string $slug  Card identifier.
 * @param int    $count Item count for the card.
 *
 * @return string
 */
function blc_get_stat_card_cta_label($slug, $count) {
    $count = max(0, (int) $count);

    switch ($slug) {
        case '404':
            return sprintf(
                /* translators: %s: number of 4xx errors. */
                _n('%s erreur critique à corriger', '%s erreurs critiques à corriger', $count, 'liens-morts-detector-jlg'),
                number_format_i18n($count)
            );
        case '5xx':
            return sprintf(
                /* translators: %s: number of server errors. */
                _n('%s incident serveur détecté', '%s incidents serveurs détectés', $count, 'liens-morts-detector-jlg'),
                number_format_i18n($count)
            );
        case 'redirects':
            return sprintf(
                /* translators: %s: number of redirects to review. */
                _n('%s redirection à valider', '%s redirections à valider', $count, 'liens-morts-detector-jlg'),
                number_format_i18n($count)
            );
        case 'recheck':
            return sprintf(
                /* translators: %s: number of links flagged for recheck. */
                _n('%s lien en attente de re-test', '%s liens en attente de re-test', $count, 'liens-morts-detector-jlg'),
                number_format_i18n($count)
            );
        default:
            return sprintf(
                /* translators: %s: number of broken links. */
                _n('%s lien cassé à traiter', '%s liens cassés à traiter', $count, 'liens-morts-detector-jlg'),
                number_format_i18n($count)
            );
    }
}

/**
 * Affiche la modale utilisée pour l'édition et la suppression rapides.
 *
 * Cette modale est rendue une seule fois puis réutilisée via JavaScript pour
 * remplacer les appels bloquants à prompt()/confirm().
 *
 * @return void
 */
function blc_render_action_modal() {
    static $rendered = false;

    if ($rendered) {
        return;
    }

    $rendered = true;
    ?>
    <div id="blc-modal" class="blc-modal" aria-hidden="true">
        <div class="blc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="blc-modal-title">
            <button type="button" class="blc-modal__close" aria-label="<?php echo esc_attr__('Fermer la fenêtre modale', 'liens-morts-detector-jlg'); ?>">
                <span aria-hidden="true">&times;</span>
            </button>
            <h2 id="blc-modal-title" class="blc-modal__title"></h2>
            <p class="blc-modal__message"></p>
            <div class="blc-modal__context" aria-live="polite"></div>
            <div class="blc-modal__error" role="alert" aria-live="assertive"></div>
            <div class="blc-modal__options blc-modal__section is-hidden"></div>
            <div class="blc-modal__field">
                <label for="blc-modal-url" class="blc-modal__label"></label>
                <input type="url" id="blc-modal-url" class="blc-modal__input" placeholder="<?php echo esc_attr__('https://', 'liens-morts-detector-jlg'); ?>">
            </div>
            <div class="blc-modal__preview blc-modal__section is-hidden" aria-live="polite"></div>
            <div class="blc-modal__actions">
                <button type="button" class="button button-secondary blc-modal__cancel"><?php esc_html_e('Annuler', 'liens-morts-detector-jlg'); ?></button>
                <button type="button" class="button button-primary blc-modal__confirm"><?php esc_html_e('Confirmer', 'liens-morts-detector-jlg'); ?></button>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Schedule a manual link scan and update the stored status.
 *
 * @param bool $is_full_scan Whether to launch a full scan.
 *
 * @return array{success:bool,message:string,manual_trigger_failed:bool}
 */
function blc_schedule_manual_link_scan($is_full_scan = false, $force_cancel = false) {
    $is_full_scan = (bool) $is_full_scan;
    $force_cancel = (bool) $force_cancel;
    $bypass_rest_window = $is_full_scan;
    $job_id = function_exists('blc_generate_link_scan_job_id') ? blc_generate_link_scan_job_id() : uniqid('blc_', true);
    $attempt = 1;
    $scheduled_at = time();

    $current_status = blc_get_link_scan_status();
    $current_state = isset($current_status['state']) ? (string) $current_status['state'] : 'idle';
    $active_states = array('running', 'queued');
    $has_remaining_batches = !empty($current_status['remaining_batches']);
    $scan_is_active = in_array($current_state, $active_states, true) || $has_remaining_batches;

    if ($scan_is_active && !$force_cancel) {
        $message = __("Une analyse est déjà en cours. Confirmez le remplacement ou attendez la fin pour éviter la perte de progression.", 'liens-morts-detector-jlg');

        return array(
            'success'               => false,
            'message'               => $message,
            'requires_confirmation' => true,
            'current_state'         => $current_state,
            'resolution_hint'       => __("Vous pouvez annuler l'analyse active ou confirmer le remplacement pour lancer un nouveau lot.", 'liens-morts-detector-jlg'),
        );
    }

    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('blc_manual_check_batch');
    }

    $schedule_args = array(0, $is_full_scan, $bypass_rest_window);
    $scheduled = wp_schedule_single_event($scheduled_at, 'blc_manual_check_batch', $schedule_args);

    if (false === $scheduled) {
        $retry_delay = function_exists('apply_filters') ? (int) apply_filters('blc_manual_scan_retry_delay', 60) : 60;
        if ($retry_delay < 5) {
            $retry_delay = 5;
        }

        $attempt++;
        $scheduled = wp_schedule_single_event($scheduled_at + $retry_delay, 'blc_manual_check_batch', $schedule_args);
    }

    if (false === $scheduled) {
        error_log(
            sprintf(
                'BLC: Failed to schedule manual link check (full scan: %s, bypass rest window: %s).',
                $is_full_scan ? 'true' : 'false',
                $bypass_rest_window ? 'true' : 'false'
            )
        );
        do_action('blc_manual_check_schedule_failed', $is_full_scan, $bypass_rest_window);

        $failure_message = __("La vérification des liens n'a pas pu être programmée. Veuillez réessayer.", 'liens-morts-detector-jlg');

        blc_update_link_scan_status([
            'state'             => 'failed',
            'message'           => $failure_message,
            'last_error'        => $failure_message,
            'current_batch'     => 0,
            'processed_batches' => 0,
            'total_batches'     => 0,
            'remaining_batches' => 0,
            'total_items'       => 0,
            'processed_items'   => 0,
            'is_full_scan'      => $is_full_scan,
            'started_at'        => 0,
            'job_id'            => $job_id,
            'attempt'           => $attempt,
        ]);

        blc_append_link_scan_history_entry([
            'job_id'      => $job_id,
            'state'       => 'failed',
            'message'     => $failure_message,
            'attempt'     => $attempt,
            'is_full_scan'=> $is_full_scan,
            'scheduled_at'=> $scheduled_at,
            'bypass_rest_window' => $bypass_rest_window,
        ]);

        return [
            'success'               => false,
            'message'               => $failure_message,
            'manual_trigger_failed' => false,
        ];
    }

    $manual_trigger_failed = false;
    $manual_trigger_error = '';

    if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
        $manual_trigger_result = null;

        if (function_exists('spawn_cron')) {
            $manual_trigger_result = spawn_cron();
        } elseif (function_exists('wp_cron')) {
            $manual_trigger_result = wp_cron();
        }

        if (null !== $manual_trigger_result) {
            $is_error = (false === $manual_trigger_result);

            if (function_exists('is_wp_error') && is_wp_error($manual_trigger_result)) {
                $is_error = true;
                $manual_trigger_error = $manual_trigger_result->get_error_message();
            }

            if ($is_error) {
                $manual_trigger_failed = true;
                error_log('BLC: Manual cron trigger failed for link check.');
            }
        }
    }

    $status_message = __('Analyse programmée. Le premier lot démarrera sous peu.', 'liens-morts-detector-jlg');

    if ($manual_trigger_failed) {
        $status_message .= ' ' . __("Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.", 'liens-morts-detector-jlg');
    }

    blc_append_link_scan_history_entry([
        'job_id'              => $job_id,
        'state'               => 'queued',
        'message'             => $status_message,
        'attempt'             => $attempt,
        'is_full_scan'        => $is_full_scan,
        'scheduled_at'        => $scheduled_at,
        'bypass_rest_window'  => $bypass_rest_window,
        'manual_trigger_failed' => $manual_trigger_failed,
        'manual_trigger_error'  => $manual_trigger_error,
    ]);

    blc_update_link_scan_status([
        'state'             => 'queued',
        'current_batch'     => 0,
        'processed_batches' => 0,
        'total_batches'     => 0,
        'remaining_batches' => 0,
        'total_items'       => 0,
        'processed_items'   => 0,
        'is_full_scan'      => $is_full_scan,
        'message'           => $status_message,
        'last_error'        => '',
        'started_at'        => 0,
        'ended_at'          => 0,
        'job_id'            => $job_id,
        'attempt'           => $attempt,
    ]);

    $return_message = sprintf(
        /* translators: %s: unique job identifier. */
        __("La vérification des liens a été programmée et s'exécute en arrière-plan. (Job : %s)", 'liens-morts-detector-jlg'),
        esc_html($job_id)
    );

    if ($scan_is_active && $force_cancel) {
        $return_message .= ' ' . __("Le scan en cours a été remplacé par cette nouvelle exécution.", 'liens-morts-detector-jlg');
    }

    return [
        'success'               => true,
        'message'               => $return_message,
        'manual_trigger_failed' => $manual_trigger_failed,
    ];
}

/**
 * Schedule a manual image scan and update the stored status.
 *
 * @return array{success:bool,message:string,manual_trigger_failed:bool}
 */
function blc_schedule_manual_image_scan() {
    $automatic_enabled = (bool) get_option('blc_image_scan_schedule_enabled', false);

    if (!$automatic_enabled && function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('blc_check_image_batch', array(0, true));
    }

    $scheduled = wp_schedule_single_event(time(), 'blc_check_image_batch', array(0, true));

    if (false === $scheduled) {
        error_log('BLC: Failed to schedule manual image check.');
        do_action('blc_manual_image_check_schedule_failed');

        $failure_message = __("La vérification des images n'a pas pu être programmée. Veuillez réessayer.", 'liens-morts-detector-jlg');

        blc_update_image_scan_status([
            'state'      => 'failed',
            'message'    => $failure_message,
            'last_error' => $failure_message,
            'started_at' => 0,
        ]);

        return [
            'success'               => false,
            'message'               => $failure_message,
            'manual_trigger_failed' => false,
        ];
    }

    $manual_trigger_failed = false;

    if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
        $manual_trigger_result = null;

        if (function_exists('spawn_cron')) {
            $manual_trigger_result = spawn_cron();
        } elseif (function_exists('wp_cron')) {
            $manual_trigger_result = wp_cron();
        }

        if (null !== $manual_trigger_result) {
            $is_error = (false === $manual_trigger_result);

            if (function_exists('is_wp_error') && is_wp_error($manual_trigger_result)) {
                $is_error = true;
            }

            if ($is_error) {
                $manual_trigger_failed = true;
                error_log('BLC: Manual cron trigger failed for image check.');
            }
        }
    }

    $status_message = __('Analyse programmée. Le premier lot démarrera sous peu.', 'liens-morts-detector-jlg');

    if ($manual_trigger_failed) {
        $status_message .= ' ' . __("Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.", 'liens-morts-detector-jlg');
    }

    blc_update_image_scan_status([
        'state'             => 'queued',
        'current_batch'     => 0,
        'processed_batches' => 0,
        'total_batches'     => 0,
        'remaining_batches' => 0,
        'total_items'       => 0,
        'processed_items'   => 0,
        'is_full_scan'      => true,
        'message'           => $status_message,
        'last_error'        => '',
        'started_at'        => 0,
        'ended_at'          => 0,
    ]);

    return [
        'success'               => true,
        'message'               => __("La vérification des images a été programmée et s'exécute en arrière-plan.", 'liens-morts-detector-jlg'),
        'manual_trigger_failed' => $manual_trigger_failed,
    ];
}

/**
 * Retrieve the localized label for a scan status.
 *
 * @param string $state Status identifier.
 *
 * @return string
 */
function blc_get_scan_state_label($state) {
    $labels = [
        'idle'      => __('Inactif', 'liens-morts-detector-jlg'),
        'queued'    => __('En file d\'attente', 'liens-morts-detector-jlg'),
        'running'   => __('Analyse en cours', 'liens-morts-detector-jlg'),
        'completed' => __('Terminée', 'liens-morts-detector-jlg'),
        'failed'    => __('Échec', 'liens-morts-detector-jlg'),
        'cancelled' => __('Annulée', 'liens-morts-detector-jlg'),
    ];

    $key = sanitize_key($state);

    if ($key === '' || !isset($labels[$key])) {
        return $labels['idle'];
    }

    return $labels[$key];
}

/**
 * Format a timestamp for display in the history dashboard.
 *
 * @param int $timestamp Unix timestamp.
 *
 * @return string
 */
function blc_format_scan_history_datetime($timestamp) {
    $timestamp = (int) $timestamp;
    if ($timestamp <= 0) {
        return __('—', 'liens-morts-detector-jlg');
    }

    $format = __('d/m/Y H:i', 'liens-morts-detector-jlg');

    if (function_exists('wp_date')) {
        return wp_date($format, $timestamp);
    }

    if (function_exists('date_i18n')) {
        return date_i18n($format, $timestamp); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
    }

    return gmdate($format, $timestamp);
}

/**
 * Format a duration in milliseconds to a human readable string.
 *
 * @param float|int $duration_ms
 *
 * @return string
 */
function blc_format_scan_duration($duration_ms) {
    $duration_ms = (float) $duration_ms;
    if ($duration_ms <= 0) {
        return __('—', 'liens-morts-detector-jlg');
    }

    $milliseconds = (int) round($duration_ms);
    if ($milliseconds < 1000) {
        return sprintf(
            /* translators: %s: duration in milliseconds. */
            __('%s ms', 'liens-morts-detector-jlg'),
            number_format_i18n($milliseconds)
        );
    }

    $total_seconds = (int) floor($milliseconds / 1000);
    $hours         = (int) floor($total_seconds / 3600);
    $minutes       = (int) floor(($total_seconds % 3600) / 60);
    $seconds       = (int) ($total_seconds % 60);

    $parts = [];

    if ($hours > 0) {
        if ($hours === 1) {
            $parts[] = __('1 heure', 'liens-morts-detector-jlg');
        } else {
            $parts[] = sprintf(
                /* translators: %s: number of hours. */
                __('%s heures', 'liens-morts-detector-jlg'),
                number_format_i18n($hours)
            );
        }
    }

    if ($minutes > 0) {
        if ($minutes === 1) {
            $parts[] = __('1 minute', 'liens-morts-detector-jlg');
        } else {
            $parts[] = sprintf(
                /* translators: %s: number of minutes. */
                __('%s minutes', 'liens-morts-detector-jlg'),
                number_format_i18n($minutes)
            );
        }
    }

    if ($seconds > 0 || $parts === []) {
        if ($seconds === 1) {
            $parts[] = __('1 seconde', 'liens-morts-detector-jlg');
        } else {
            $parts[] = sprintf(
                /* translators: %s: number of seconds. */
                __('%s secondes', 'liens-morts-detector-jlg'),
                number_format_i18n($seconds)
            );
        }
    }

    return implode(' ', $parts);
}

/**
 * Format a processed/total pair for display.
 *
 * @param int $processed Number of processed elements.
 * @param int $total     Total number of elements.
 *
 * @return string
 */
function blc_format_scan_progress_value($processed, $total) {
    $processed = max(0, (int) $processed);
    $total     = max(0, (int) $total);

    if ($processed === 0 && $total === 0) {
        return __('—', 'liens-morts-detector-jlg');
    }

    if ($total > 0) {
        return sprintf(
            /* translators: 1: processed count, 2: total count. */
            __('%1$s / %2$s', 'liens-morts-detector-jlg'),
            number_format_i18n($processed),
            number_format_i18n($total)
        );
    }

    return number_format_i18n($processed);
}

/**
 * Format an items-per-minute throughput value.
 *
 * @param float $throughput Items per minute.
 *
 * @return string
 */
function blc_format_scan_throughput($throughput) {
    $throughput = (float) $throughput;
    if ($throughput <= 0) {
        return __('—', 'liens-morts-detector-jlg');
    }

    return sprintf(
        /* translators: %s: items per minute. */
        __('%s éléments/min', 'liens-morts-detector-jlg'),
        number_format_i18n($throughput, 1)
    );
}

/**
 * Retrieve the CSS modifier used to render a state badge in the history dashboard.
 *
 * @param string $state State slug.
 *
 * @return string
 */
function blc_get_history_state_class($state) {
    $state = sanitize_key($state);

    switch ($state) {
        case 'completed':
            return 'is-success';
        case 'failed':
            return 'is-error';
        case 'running':
            return 'is-running';
        case 'queued':
            return 'is-queued';
        case 'cancelled':
            return 'is-cancelled';
        default:
            return 'is-idle';
    }
}

/**
 * Render the scan history dashboard and metrics explorer.
 */
function blc_scan_history_page() {
    $history_entries  = blc_get_link_scan_history();
    $metrics_history  = blc_get_link_scan_metrics_history();
    $insights         = blc_calculate_link_scan_history_insights($history_entries);

    $total_runs        = isset($insights['total_runs']) ? (int) $insights['total_runs'] : 0;
    $completed_runs    = isset($insights['completed_runs']) ? (int) $insights['completed_runs'] : 0;
    $failed_runs       = isset($insights['failed_runs']) ? (int) $insights['failed_runs'] : 0;
    $success_rate      = isset($insights['success_rate']) ? (float) $insights['success_rate'] : 0.0;
    $average_duration  = isset($insights['average_duration_ms']) ? (float) $insights['average_duration_ms'] : 0.0;
    $average_throughput = isset($insights['average_throughput']) ? (float) $insights['average_throughput'] : 0.0;
    $last_job_summary  = isset($insights['last_job_summary']) && is_array($insights['last_job_summary'])
        ? $insights['last_job_summary']
        : [
            'job_id'          => '',
            'state'           => '',
            'scheduled_at'    => 0,
            'started_at'      => 0,
            'ended_at'        => 0,
            'duration_ms'     => 0,
            'processed_items' => 0,
            'total_items'     => 0,
            'attempt'         => 0,
            'is_full_scan'    => false,
        ];

    $success_rate = max(0.0, min(1.0, $success_rate));
    $success_rate_percentage = $success_rate * 100.0;
    $success_rate_display = ($total_runs > 0)
        ? sprintf(
            /* translators: %s: success percentage. */
            __('%s %%', 'liens-morts-detector-jlg'),
            number_format_i18n($success_rate_percentage, 1)
        )
        : __('—', 'liens-morts-detector-jlg');

    $last_duration_display = blc_format_scan_duration($last_job_summary['duration_ms']);
    $last_progress_display = blc_format_scan_progress_value($last_job_summary['processed_items'], $last_job_summary['total_items']);

    $last_job_throughput = 0.0;
    if ($last_job_summary['duration_ms'] > 0 && $last_job_summary['processed_items'] > 0) {
        $minutes = $last_job_summary['duration_ms'] / 60000;
        if ($minutes > 0) {
            $last_job_throughput = $last_job_summary['processed_items'] / $minutes;
        }
    }

    $summary_cards = [
        [
            'label' => __('Analyses totales', 'liens-morts-detector-jlg'),
            'value' => number_format_i18n($total_runs),
            'note'  => ($total_runs > 0)
                ? sprintf(
                    /* translators: 1: completed scans count, 2: failed scans count. */
                    __('%1$s réussies · %2$s en échec', 'liens-morts-detector-jlg'),
                    number_format_i18n($completed_runs),
                    number_format_i18n($failed_runs)
                )
                : __('Aucune analyse enregistrée pour le moment.', 'liens-morts-detector-jlg'),
        ],
        [
            'label' => __('Taux de réussite', 'liens-morts-detector-jlg'),
            'value' => $success_rate_display,
            'note'  => ($total_runs > 0 && $last_job_summary['state'] !== '')
                ? sprintf(
                    /* translators: %s: status label. */
                    __('Dernière analyse : %s', 'liens-morts-detector-jlg'),
                    blc_get_scan_state_label($last_job_summary['state'])
                )
                : '',
        ],
        [
            'label' => __('Durée moyenne', 'liens-morts-detector-jlg'),
            'value' => blc_format_scan_duration($average_duration),
            'note'  => ($last_job_summary['duration_ms'] > 0)
                ? sprintf(
                    /* translators: %s: duration string. */
                    __('Dernière : %s', 'liens-morts-detector-jlg'),
                    $last_duration_display
                )
                : '',
        ],
        [
            'label' => __('Débit moyen', 'liens-morts-detector-jlg'),
            'value' => blc_format_scan_throughput($average_throughput),
            'note'  => ($last_job_throughput > 0)
                ? sprintf(
                    /* translators: %s: items per minute. */
                    __('Dernière : %s', 'liens-morts-detector-jlg'),
                    blc_format_scan_throughput($last_job_throughput)
                )
                : '',
        ],
    ];

    $job_rows = [];
    foreach (array_slice($history_entries, 0, 10) as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $job_id = isset($entry['job_id']) ? (string) $entry['job_id'] : '';
        $state_raw = isset($entry['state']) ? (string) $entry['state'] : '';
        $state = sanitize_key($state_raw);
        $state_label = blc_get_scan_state_label($state);
        $state_class = blc_get_history_state_class($state);
        $is_full_scan = !empty($entry['is_full_scan']);
        $mode_label = $is_full_scan
            ? __('Complet', 'liens-morts-detector-jlg')
            : __('Incrémental', 'liens-morts-detector-jlg');
        $attempt = isset($entry['attempt']) ? max(1, (int) $entry['attempt']) : 1;

        $metrics = isset($entry['metrics']) && is_array($entry['metrics']) ? $entry['metrics'] : [];

        $duration_ms = 0;
        if (isset($metrics['duration_ms'])) {
            $duration_ms = max(0, (int) $metrics['duration_ms']);
        } elseif (isset($entry['started_at'], $entry['ended_at'])) {
            $start = (int) $entry['started_at'];
            $end   = (int) $entry['ended_at'];
            if ($start > 0 && $end >= $start) {
                $duration_ms = ($end - $start) * 1000;
            }
        }

        $processed_items = 0;
        if (isset($metrics['processed_items'])) {
            $processed_items = max(0, (int) $metrics['processed_items']);
        } elseif (isset($entry['processed_items'])) {
            $processed_items = max(0, (int) $entry['processed_items']);
        }

        $total_items = 0;
        if (isset($metrics['total_items'])) {
            $total_items = max(0, (int) $metrics['total_items']);
        } elseif (isset($entry['total_items'])) {
            $total_items = max(0, (int) $entry['total_items']);
        }

        $processed_batches = 0;
        if (isset($metrics['processed_batches'])) {
            $processed_batches = max(0, (int) $metrics['processed_batches']);
        } elseif (isset($entry['processed_batches'])) {
            $processed_batches = max(0, (int) $entry['processed_batches']);
        }

        $total_batches = 0;
        if (isset($metrics['total_batches'])) {
            $total_batches = max(0, (int) $metrics['total_batches']);
        } elseif (isset($entry['total_batches'])) {
            $total_batches = max(0, (int) $entry['total_batches']);
        }

        $notes = [];
        if (!empty($entry['manual_trigger_failed'])) {
            $notes[] = __('Déclenchement manuel WP-Cron en échec.', 'liens-morts-detector-jlg');
        }
        if (!empty($entry['manual_trigger_error'])) {
            $notes[] = sprintf(
                /* translators: %s: error message. */
                __('Erreur : %s', 'liens-morts-detector-jlg'),
                (string) $entry['manual_trigger_error']
            );
        }
        if (!empty($entry['bypass_rest_window'])) {
            $notes[] = __('Fenêtre de repos contournée pour ce job.', 'liens-morts-detector-jlg');
        }

        $job_rows[] = [
            'job_id'            => $job_id,
            'state_label'      => $state_label,
            'state_class'      => $state_class,
            'mode_label'       => $mode_label,
            'attempt'          => $attempt,
            'scheduled_at'     => blc_format_scan_history_datetime($entry['scheduled_at'] ?? 0),
            'started_at'       => blc_format_scan_history_datetime($entry['started_at'] ?? 0),
            'ended_at'         => blc_format_scan_history_datetime($entry['ended_at'] ?? 0),
            'duration'         => blc_format_scan_duration($duration_ms),
            'items'            => blc_format_scan_progress_value($processed_items, $total_items),
            'batches'          => blc_format_scan_progress_value($processed_batches, $total_batches),
            'message'          => isset($entry['message']) ? (string) $entry['message'] : '',
            'last_error'       => isset($entry['last_error']) ? (string) $entry['last_error'] : '',
            'notes'            => $notes,
        ];
    }

    $metric_rows = [];
    foreach (array_slice($metrics_history, 0, 15) as $metric) {
        if (!is_array($metric)) {
            continue;
        }

        $job_id = isset($metric['job_id']) ? (string) $metric['job_id'] : '';
        $batch   = isset($metric['batch']) ? (int) $metric['batch'] : null;
        $attempt = isset($metric['attempt']) ? max(0, (int) $metric['attempt']) : 0;
        $duration_ms = isset($metric['duration_ms']) ? max(0, (int) $metric['duration_ms']) : 0;
        $processed_items = isset($metric['processed_items']) ? max(0, (int) $metric['processed_items']) : 0;
        $total_items     = isset($metric['total_items']) ? max(0, (int) $metric['total_items']) : 0;
        $processed_batches = isset($metric['processed_batches']) ? max(0, (int) $metric['processed_batches']) : 0;
        $total_batches     = isset($metric['total_batches']) ? max(0, (int) $metric['total_batches']) : 0;
        $state = isset($metric['state']) ? sanitize_key((string) $metric['state']) : '';
        $is_success = !empty($metric['success']);
        $is_full_scan = !empty($metric['is_full_scan']);

        $result_parts = [];
        $result_parts[] = $is_success
            ? __('Succès', 'liens-morts-detector-jlg')
            : __('Erreur', 'liens-morts-detector-jlg');
        if ($state !== '') {
            $result_parts[] = sprintf(
                /* translators: %s: state label. */
                __('État : %s', 'liens-morts-detector-jlg'),
                blc_get_scan_state_label($state)
            );
        }

        $metric_rows[] = [
            'timestamp'      => blc_format_scan_history_datetime($metric['timestamp'] ?? 0),
            'job_id'         => $job_id,
            'batch'          => $batch,
            'attempt'        => $attempt,
            'mode_label'     => $is_full_scan
                ? __('Complet', 'liens-morts-detector-jlg')
                : __('Incrémental', 'liens-morts-detector-jlg'),
            'duration'       => blc_format_scan_duration($duration_ms),
            'items'          => blc_format_scan_progress_value($processed_items, $total_items),
            'batches'        => blc_format_scan_progress_value($processed_batches, $total_batches),
            'result'         => implode(' · ', $result_parts),
            'result_class'   => $is_success ? 'is-success' : 'is-error',
        ];
    }

    $last_job_state_class = blc_get_history_state_class($last_job_summary['state']);
    $last_job_mode_label = $last_job_summary['is_full_scan']
        ? __('Complet', 'liens-morts-detector-jlg')
        : __('Incrémental', 'liens-morts-detector-jlg');
    $last_job_attempt = max(1, (int) $last_job_summary['attempt']);

    ?>
    <div class="wrap blc-history-page">
        <?php blc_render_dashboard_tabs('history'); ?>
        <h1><?php esc_html_e('Historique des Analyses', 'liens-morts-detector-jlg'); ?></h1>

        <div class="blc-stats-box blc-admin-card blc-admin-card--accent">
            <?php foreach ($summary_cards as $card) :
                $note = isset($card['note']) ? (string) $card['note'] : '';
                ?>
                <div class="blc-stat blc-stat--static">
                    <span class="blc-stat-value"><?php echo esc_html($card['value']); ?></span>
                    <span class="blc-stat-label"><?php echo esc_html($card['label']); ?></span>
                    <?php if ($note !== '') : ?>
                        <span class="blc-stat-note"><?php echo esc_html($note); ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="blc-history-last-run blc-admin-card blc-admin-card--accent">
            <h2><?php esc_html_e('Dernière analyse', 'liens-morts-detector-jlg'); ?></h2>
            <?php if ($total_runs === 0 || $last_job_summary['job_id'] === '') : ?>
                <p class="blc-history-empty-message"><?php esc_html_e('Aucune analyse n’a été enregistrée pour le moment.', 'liens-morts-detector-jlg'); ?></p>
            <?php else : ?>
                <div class="blc-history-last-run__header">
                    <span class="blc-history-state <?php echo esc_attr($last_job_state_class); ?>"><?php echo esc_html(blc_get_scan_state_label($last_job_summary['state'])); ?></span>
                    <span class="blc-history-last-run__job"><?php printf(
                        /* translators: %s: job identifier. */
                        esc_html__('Job : %s', 'liens-morts-detector-jlg'),
                        esc_html($last_job_summary['job_id'])
                    ); ?></span>
                    <span class="blc-history-last-run__mode"><?php printf(
                        /* translators: %1$s: scan mode label, %2$s: attempt number. */
                        esc_html__('%1$s · tentative %2$s', 'liens-morts-detector-jlg'),
                        esc_html($last_job_mode_label),
                        esc_html(number_format_i18n($last_job_attempt))
                    ); ?></span>
                </div>
                <ul class="blc-history-last-run__details">
                    <li><strong><?php esc_html_e('Planifié', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html(blc_format_scan_history_datetime($last_job_summary['scheduled_at'])); ?></li>
                    <li><strong><?php esc_html_e('Démarré', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html(blc_format_scan_history_datetime($last_job_summary['started_at'])); ?></li>
                    <li><strong><?php esc_html_e('Terminé', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html(blc_format_scan_history_datetime($last_job_summary['ended_at'])); ?></li>
                    <li><strong><?php esc_html_e('Durée', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($last_duration_display); ?></li>
                    <li><strong><?php esc_html_e('Liens traités', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($last_progress_display); ?></li>
                </ul>
            <?php endif; ?>
        </section>

        <section class="blc-history-table-section">
            <h2><?php esc_html_e('Historique des exécutions', 'liens-morts-detector-jlg'); ?></h2>
            <table class="widefat fixed striped blc-history-table">
                <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Job', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Statut', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Horodatages', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Performance', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Messages', 'liens-morts-detector-jlg'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($job_rows === []) : ?>
                    <tr>
                        <td colspan="5" class="blc-history-empty-message"><?php esc_html_e('Aucune exécution récente à afficher.', 'liens-morts-detector-jlg'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($job_rows as $row) :
                        $job_id_display = $row['job_id'] !== '' ? $row['job_id'] : __('(inconnu)', 'liens-morts-detector-jlg');
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($job_id_display); ?></strong>
                                <div class="blc-history-meta"><?php printf(
                                    /* translators: 1: scan mode, 2: attempt number. */
                                    esc_html__('%1$s · tentative %2$s', 'liens-morts-detector-jlg'),
                                    esc_html($row['mode_label']),
                                    esc_html(number_format_i18n($row['attempt']))
                                ); ?></div>
                            </td>
                            <td>
                                <span class="blc-history-state <?php echo esc_attr($row['state_class']); ?>"><?php echo esc_html($row['state_label']); ?></span>
                            </td>
                            <td>
                                <ul class="blc-history-list">
                                    <li><strong><?php esc_html_e('Planifié', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['scheduled_at']); ?></li>
                                    <li><strong><?php esc_html_e('Démarré', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['started_at']); ?></li>
                                    <li><strong><?php esc_html_e('Terminé', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['ended_at']); ?></li>
                                </ul>
                            </td>
                            <td>
                                <ul class="blc-history-list">
                                    <li><strong><?php esc_html_e('Durée', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['duration']); ?></li>
                                    <li><strong><?php esc_html_e('Liens', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['items']); ?></li>
                                    <li><strong><?php esc_html_e('Lots', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['batches']); ?></li>
                                </ul>
                            </td>
                            <td>
                                <?php if ($row['message'] !== '') : ?>
                                    <p class="blc-history-message"><?php echo esc_html($row['message']); ?></p>
                                <?php endif; ?>
                                <?php if ($row['last_error'] !== '') : ?>
                                    <p class="blc-history-error"><?php echo esc_html($row['last_error']); ?></p>
                                <?php endif; ?>
                                <?php if ($row['notes'] !== []) : ?>
                                    <ul class="blc-history-notes">
                                        <?php foreach ($row['notes'] as $note) : ?>
                                            <li><?php echo esc_html($note); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="blc-history-table-section">
            <h2><?php esc_html_e('Métriques par lot', 'liens-morts-detector-jlg'); ?></h2>
            <table class="widefat fixed striped blc-history-table">
                <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Horodatage', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Job & lot', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Durée', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Progression', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Résultat', 'liens-morts-detector-jlg'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($metric_rows === []) : ?>
                    <tr>
                        <td colspan="5" class="blc-history-empty-message"><?php esc_html_e('Aucune métrique enregistrée pour le moment.', 'liens-morts-detector-jlg'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($metric_rows as $metric_row) :
                        $batch = $metric_row['batch'];
                        $batch_display = '—';
                        if ($batch !== null) {
                            $batch_number = $batch + 1;
                            if ($batch_number < 1) {
                                $batch_number = $batch + 1;
                            }
                            $batch_display = sprintf(
                                /* translators: %s: batch number. */
                                __('Lot #%s', 'liens-morts-detector-jlg'),
                                number_format_i18n(max(1, $batch_number))
                            );
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($metric_row['timestamp']); ?></td>
                            <td>
                                <strong><?php echo esc_html($metric_row['job_id'] !== '' ? $metric_row['job_id'] : __('(inconnu)', 'liens-morts-detector-jlg')); ?></strong>
                                <div class="blc-history-meta"><?php echo esc_html($batch_display); ?></div>
                                <div class="blc-history-meta"><?php printf(
                                    /* translators: 1: scan mode, 2: attempt number. */
                                    esc_html__('%1$s · tentative %2$s', 'liens-morts-detector-jlg'),
                                    esc_html($metric_row['mode_label']),
                                    esc_html(number_format_i18n(max(1, $metric_row['attempt'])))
                                ); ?></div>
                            </td>
                            <td><?php echo esc_html($metric_row['duration']); ?></td>
                            <td>
                                <ul class="blc-history-list">
                                    <li><strong><?php esc_html_e('Liens', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($metric_row['items']); ?></li>
                                    <li><strong><?php esc_html_e('Lots', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($metric_row['batches']); ?></li>
                                </ul>
                            </td>
                            <td>
                                <span class="blc-history-state <?php echo esc_attr($metric_row['result_class']); ?>"><?php echo esc_html($metric_row['result']); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>
    <?php
}

/**
 * Affiche la page du rapport des LIENS cassés.
 */
function blc_dashboard_links_page() {
    // Gère le lancement d'une vérification manuelle des liens
    if (isset($_POST['blc_manual_check'])) {
        check_admin_referer('blc_manual_check_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permissions insuffisantes pour lancer une analyse manuelle.', 'liens-morts-detector-jlg'));
        }

        $is_full = isset($_POST['blc_full_scan']);
        $schedule_result = blc_schedule_manual_link_scan($is_full);

        if (!$schedule_result['success']) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($schedule_result['message'])
            );
        } else {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($schedule_result['message'])
            );

            if (!empty($schedule_result['manual_trigger_failed'])) {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    esc_html__(
                        "Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.",
                        'liens-morts-detector-jlg'
                    )
                );
            }
        }
    }

    if (isset($_POST['blc_reschedule_cron'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié via wp_nonce_field.
        check_admin_referer('blc_reschedule_cron_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permissions insuffisantes pour reprogrammer la vérification automatique.', 'liens-morts-detector-jlg'));
        }

        $reschedule_payload = blc_reschedule_link_scan_event('dashboard');

        if (!$reschedule_payload['success']) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($reschedule_payload['message'])
            );
        } else {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($reschedule_payload['message'])
            );
        }

        if (!empty($reschedule_payload['warnings'])) {
            foreach ($reschedule_payload['warnings'] as $warning_message) {
                printf(
                    '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                    esc_html($warning_message)
                );
            }
        }
    }

    $scan_status = blc_get_link_scan_status_payload();
    $scan_state_slug = isset($scan_status['state']) ? sanitize_key($scan_status['state']) : 'idle';
    if ($scan_state_slug === '') {
        $scan_state_slug = 'idle';
    }
    $scan_state_label = blc_get_scan_state_label($scan_state_slug);

    $total_batches_display = isset($scan_status['total_batches']) ? (int) $scan_status['total_batches'] : 0;
    if ($total_batches_display <= 0 && in_array($scan_state_slug, array('queued', 'running'), true)) {
        $total_batches_display = max(1, (int) $scan_status['processed_batches']);
    }
    $total_batches_display = max(0, $total_batches_display);
    $processed_batches_display = isset($scan_status['processed_batches']) ? (int) $scan_status['processed_batches'] : 0;
    if ($total_batches_display > 0) {
        $processed_batches_display = max(0, min($processed_batches_display, $total_batches_display));
    }

    $initial_progress = 0;
    if ($total_batches_display > 0) {
        $initial_progress = (int) round(($processed_batches_display / $total_batches_display) * 100);
    } elseif ('completed' === $scan_state_slug) {
        $initial_progress = 100;
    }
    $initial_progress = max(0, min(100, $initial_progress));

    $scan_details_parts = [];

    if (in_array($scan_state_slug, array('running', 'queued'), true)) {
        if ($total_batches_display > 0) {
            $scan_details_parts[] = sprintf(
                /* translators: 1: current batch number, 2: total batch count. */
                __('Lot %1$d sur %2$d.', 'liens-morts-detector-jlg'),
                max(1, $processed_batches_display),
                max(1, $total_batches_display)
            );
        } else {
            $scan_details_parts[] = __('Préparation du prochain lot…', 'liens-morts-detector-jlg');
        }
    } elseif ('completed' === $scan_state_slug) {
        $scan_details_parts[] = __('Analyse terminée.', 'liens-morts-detector-jlg');
    } elseif ('failed' === $scan_state_slug) {
        $scan_details_parts[] = __('Dernier scan en échec.', 'liens-morts-detector-jlg');
    } elseif ('cancelled' === $scan_state_slug) {
        $scan_details_parts[] = __('Scan annulé.', 'liens-morts-detector-jlg');
    } else {
        $scan_details_parts[] = __('Aucun scan manuel en cours.', 'liens-morts-detector-jlg');
    }

    if (!empty($scan_status['message']) && is_string($scan_status['message'])) {
        $scan_details_parts[] = $scan_status['message'];
    }

    $next_batch_timestamp = isset($scan_status['next_batch_timestamp'])
        ? (int) $scan_status['next_batch_timestamp']
        : 0;

    if ($next_batch_timestamp > 0 && in_array($scan_state_slug, array('queued', 'running'), true)) {
        $scan_details_parts[] = sprintf(
            /* translators: %s: next batch scheduled date. */
            __('Prochain lot prévu à %s.', 'liens-morts-detector-jlg'),
            wp_date('j M Y H:i', $next_batch_timestamp)
        );
    }

    $scan_details = trim(implode(' ', array_filter(array_map('strval', $scan_details_parts))));

    $scan_panel_classes = array_filter(
        array(
            'blc-scan-status',
            'blc-admin-card',
            'blc-scan-status--state-' . $scan_state_slug,
            in_array($scan_state_slug, array('running', 'queued'), true) ? 'is-active' : '',
            'completed' === $scan_state_slug ? 'is-completed' : '',
            'failed' === $scan_state_slug ? 'is-failed' : '',
            'cancelled' === $scan_state_slug ? 'is-cancelled' : '',
        )
    );

    $scan_panel_class_attr = implode(' ', array_map('sanitize_html_class', $scan_panel_classes));

    $next_scheduled_timestamp = wp_next_scheduled('blc_check_links');
    $next_schedule_slug       = wp_get_schedule('blc_check_links');
    $next_scheduled_display   = $next_scheduled_timestamp
        ? wp_date('j M Y H:i', $next_scheduled_timestamp)
        : __('Non planifiée', 'liens-morts-detector-jlg');

    $schedule_labels = array(
        'blc_hourly'       => __('Toutes les heures', 'liens-morts-detector-jlg'),
        'blc_six_hours'    => __('Toutes les 6 heures', 'liens-morts-detector-jlg'),
        'blc_twelve_hours' => __('Toutes les 12 heures', 'liens-morts-detector-jlg'),
        'daily'            => __('Quotidienne', 'liens-morts-detector-jlg'),
        'weekly'           => __('Hebdomadaire', 'liens-morts-detector-jlg'),
        'monthly'          => __('Mensuelle', 'liens-morts-detector-jlg'),
    );

    $schedule_note = '';

    if ('blc_custom_interval' === $next_schedule_slug) {
        $custom_hours = blc_get_custom_frequency_hours();
        $custom_time  = blc_get_custom_frequency_time();
        $schedule_note = sprintf(
            /* translators: 1: number of hours, 2: time of day. */
            __('Toutes les %1$d heures (démarrage à %2$s)', 'liens-morts-detector-jlg'),
            $custom_hours,
            $custom_time
        );
    } elseif ($next_schedule_slug && isset($schedule_labels[$next_schedule_slug])) {
        $schedule_note = $schedule_labels[$next_schedule_slug];
    }

    $timezone_label = blc_get_timezone_label();

    if ('' !== $schedule_note && '' !== $timezone_label) {
        $schedule_note = sprintf(
            /* translators: 1: recurrence label, 2: timezone label. */
            __('%1$s — Fuseau : %2$s', 'liens-morts-detector-jlg'),
            $schedule_note,
            $timezone_label
        );
    } elseif ('' === $schedule_note && '' !== $timezone_label) {
        $schedule_note = sprintf(
            /* translators: %s: timezone label. */
            __('Fuseau : %s', 'liens-morts-detector-jlg'),
            $timezone_label
        );
    }

    // Préparation des données et des statistiques pour les liens
    $list_table = new BLC_Links_List_Table();
    $status_counts = $list_table->get_status_counts();

    $current_link_type = 'all';
    if (isset($_GET['link_type'])) {
        $link_type_param = sanitize_key(wp_unslash($_GET['link_type']));
        if ($link_type_param !== '') {
            $current_link_type = $link_type_param;
        }
    }

    $status_counts = is_array($status_counts) ? $status_counts : [];
    $count_keys = [
        'active_count'        => 0,
        'not_found_count'     => 0,
        'server_error_count'  => 0,
        'redirect_count'      => 0,
        'needs_recheck_count' => 0,
    ];

    foreach ($count_keys as $key => $default_value) {
        $count_keys[$key] = isset($status_counts[$key]) ? (int) $status_counts[$key] : $default_value;
    }

    $broken_links_count  = $count_keys['active_count'];
    $not_found_count     = $count_keys['not_found_count'];
    $server_error_count  = $count_keys['server_error_count'];
    $redirect_count      = $count_keys['redirect_count'];
    $needs_recheck_count = $count_keys['needs_recheck_count'];

    $stats_card_blueprint = array(
        'all'        => array(
            'link_type' => 'all',
            'label'     => __('Liens morts trouvés', 'liens-morts-detector-jlg'),
            'count'     => $broken_links_count,
        ),
        '404'        => array(
            'link_type' => 'status_404_410',
            'label'     => __('Erreurs 404 / 410', 'liens-morts-detector-jlg'),
            'count'     => $not_found_count,
        ),
        '5xx'        => array(
            'link_type' => 'status_5xx',
            'label'     => __('Erreurs 5xx', 'liens-morts-detector-jlg'),
            'count'     => $server_error_count,
        ),
        'redirects'  => array(
            'link_type' => 'status_redirects',
            'label'     => __('Redirections détectées', 'liens-morts-detector-jlg'),
            'count'     => $redirect_count,
        ),
        'recheck'    => array(
            'link_type' => 'needs_recheck',
            'label'     => __('Liens à revérifier', 'liens-morts-detector-jlg'),
            'count'     => $needs_recheck_count,
        ),
    );

    $dashboard_base_url = function_exists('admin_url')
        ? admin_url('admin.php?page=blc-dashboard')
        : 'admin.php?page=blc-dashboard';

    $build_dashboard_url = static function($link_type) use ($dashboard_base_url) {
        if ($link_type === 'all') {
            return $dashboard_base_url;
        }

        if (function_exists('add_query_arg')) {
            return add_query_arg('link_type', $link_type, $dashboard_base_url);
        }

        $separator = (false === strpos($dashboard_base_url, '?')) ? '?' : '&';

        return $dashboard_base_url . $separator . 'link_type=' . rawurlencode($link_type);
    };

    $stats_cards = array();

    foreach ($stats_card_blueprint as $slug => $card_data) {
        $stats_cards[] = array(
            'slug'      => $slug,
            'link_type' => $card_data['link_type'],
            'count'     => (int) $card_data['count'],
            'value'     => number_format_i18n($card_data['count']),
            'label'     => $card_data['label'],
            'cta_label' => blc_get_stat_card_cta_label($slug, (int) $card_data['count']),
        );
    }
    $option_size_bytes  = blc_get_dataset_storage_footprint_bytes('link');
    $last_check_time    = get_option('blc_last_check_time', 0);
    $option_size_kb     = $option_size_bytes / 1024;
    $size_display       = ($option_size_kb < 1024)
        ? sprintf('%s %s', number_format_i18n($option_size_kb, 2), __('Ko', 'liens-morts-detector-jlg'))
        : sprintf('%s %s', number_format_i18n($option_size_kb / 1024, 2), __('Mo', 'liens-morts-detector-jlg'));
    $last_check_display = $last_check_time
        ? wp_date('j M Y', $last_check_time)
        : __('Jamais', 'liens-morts-detector-jlg');
    $top_domains        = blc_get_top_broken_link_domains(5);
    $trend_points       = blc_get_link_scan_trend_points(12);
    $trend_summary      = blc_summarize_link_scan_trend($trend_points);
    $severity_overview  = blc_get_link_severity_overview($count_keys);
    $priority_actions   = blc_build_priority_action_queue($top_domains, $dashboard_base_url, $count_keys);

    $list_table->prepare_items();
    blc_render_action_modal();

    ?>
    <div class="wrap blc-dashboard-links-page">
        <?php blc_render_dashboard_tabs('links'); ?>
        <h1><?php esc_html_e('Rapport des Liens Cassés', 'liens-morts-detector-jlg'); ?></h1>
        <div class="blc-stats-box blc-admin-card blc-admin-card--accent">
            <?php foreach ($stats_cards as $card) :
                $link_type = $card['link_type'];
                $card_url  = $build_dashboard_url($link_type);
                $aria_label = sprintf(
                    /* translators: 1: Stat label, 2: number of items. */
                    __('Afficher %1$s (%2$s)', 'liens-morts-detector-jlg'),
                    $card['label'],
                    $card['value']
                );
                $is_active_card = ($link_type === 'all' && $current_link_type === 'all')
                    || ($link_type !== 'all' && $current_link_type === $link_type);
                $card_classes = array(
                    'blc-stat',
                    'blc-stat--' . $card['slug'],
                    $is_active_card ? 'is-active' : '',
                );
                ?>
                <a
                    class="<?php echo esc_attr(implode(' ', array_filter(array_map('sanitize_html_class', $card_classes)))); ?>"
                    href="<?php echo esc_url($card_url); ?>"
                    data-link-type="<?php echo esc_attr($link_type); ?>"
                    aria-label="<?php echo esc_attr($aria_label); ?>"
                    <?php echo $is_active_card ? ' aria-current="page"' : ''; ?>
                >
                    <span class="blc-stat-value"><?php echo esc_html($card['value']); ?></span>
                    <span class="blc-stat-label"><?php echo esc_html($card['label']); ?></span>
                    <?php if (!empty($card['cta_label'])) : ?>
                        <span class="blc-stat-cta"><?php echo esc_html($card['cta_label']); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($trend_points)) : ?>
            <section class="blc-dashboard-insights blc-admin-card blc-admin-card--subtle" aria-labelledby="blc-dashboard-trends-heading">
                <div class="blc-dashboard-insights__column blc-dashboard-insights__column--trend">
                    <h2 id="blc-dashboard-trends-heading" class="blc-dashboard-insights__title"><?php esc_html_e('Tendance des analyses', 'liens-morts-detector-jlg'); ?></h2>
                    <p class="blc-dashboard-insights__summary"><?php echo esc_html($trend_summary['latest_label']); ?></p>
                    <p class="blc-dashboard-insights__delta blc-dashboard-insights__delta--<?php echo esc_attr($trend_summary['direction']); ?>"><?php echo esc_html($trend_summary['delta_label']); ?></p>
                    <figure class="blc-sparkline-card">
                        <div
                            class="blc-sparkline"
                            data-blc-points="<?php echo esc_attr(wp_json_encode($trend_points)); ?>"
                            data-empty-message="<?php echo esc_attr__('Pas assez de données pour afficher la tendance.', 'liens-morts-detector-jlg'); ?>"
                            role="img"
                            aria-label="<?php echo esc_attr__('Évolution du volume d’URL analysées', 'liens-morts-detector-jlg'); ?>"
                        ></div>
                        <figcaption class="screen-reader-text">
                            <?php
                            $trend_descriptions = array();
                            foreach ($trend_points as $point) {
                                $trend_descriptions[] = sprintf(
                                    /* translators: 1: scan date, 2: processed URLs. */
                                    __('%1$s : %2$s URL', 'liens-morts-detector-jlg'),
                                    isset($point['label']) ? (string) $point['label'] : '',
                                    isset($point['formatted']) ? (string) $point['formatted'] : ''
                                );
                            }
                            echo esc_html(implode(', ', $trend_descriptions));
                            ?>
                        </figcaption>
                    </figure>
                </div>
                <div class="blc-dashboard-insights__column blc-dashboard-insights__column--severity">
                    <h2 id="blc-dashboard-severity-heading" class="blc-dashboard-insights__title"><?php esc_html_e('Répartition par sévérité', 'liens-morts-detector-jlg'); ?></h2>
                    <ul class="blc-severity-list" role="list">
                        <?php foreach ($severity_overview as $segment) :
                            $progress_label = sprintf(
                                /* translators: 1: severity label, 2: number of links, 3: percentage. */
                                __('%1$s : %2$s lien(s), %3$s %% du total', 'liens-morts-detector-jlg'),
                                $segment['label'],
                                $segment['formatted_count'],
                                $segment['formatted_percentage']
                            );
                            ?>
                            <li class="blc-severity-list__item">
                                <div class="blc-severity-list__header">
                                    <span class="blc-severity-list__label"><?php echo esc_html($segment['label']); ?></span>
                                    <span class="blc-severity-list__value"><?php echo esc_html($segment['formatted_count']); ?></span>
                                </div>
                                <div
                                    class="blc-severity-list__bar"
                                    role="progressbar"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    aria-valuenow="<?php echo esc_attr(round($segment['percentage'], 1)); ?>"
                                    aria-valuetext="<?php echo esc_attr($progress_label); ?>"
                                >
                                    <span
                                        class="blc-severity-list__fill <?php echo esc_attr($segment['class']); ?>"
                                        style="width: <?php echo esc_attr(min(100, max(0, $segment['percentage']))); ?>%;"
                                        aria-hidden="true"
                                    ></span>
                                </div>
                                <span class="blc-severity-list__percentage"><?php echo esc_html($segment['formatted_percentage']); ?>%</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>
        <div class="blc-meta-box blc-admin-card blc-admin-card--subtle">
            <div class="blc-meta">
                <span class="blc-meta-value"><?php echo esc_html($size_display); ?></span>
                <span class="blc-meta-label"><?php esc_html_e('Poids des données', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-meta">
                <span class="blc-meta-value"><?php echo esc_html($last_check_display); ?></span>
                <span class="blc-meta-label"><?php esc_html_e('Dernière analyse', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-meta">
                <span class="blc-meta-value"><?php echo esc_html($next_scheduled_display); ?></span>
                <span class="blc-meta-label"><?php esc_html_e('Prochaine analyse automatique', 'liens-morts-detector-jlg'); ?></span>
                <?php if ('' !== $schedule_note) : ?>
                    <span class="blc-stat-note"><?php echo esc_html($schedule_note); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($top_domains)) : ?>
            <div class="blc-admin-card blc-admin-card--subtle blc-top-domains">
                <h2 class="blc-top-domains__title"><?php esc_html_e('Domaines les plus concernés', 'liens-morts-detector-jlg'); ?></h2>
                <p class="blc-top-domains__description">
                    <?php esc_html_e("Identifiez les sources externes qui concentrent le plus d'erreurs afin de prioriser vos corrections ou de contacter les partenaires concernés.", 'liens-morts-detector-jlg'); ?>
                </p>
                <ol class="blc-top-domains__list">
                    <?php foreach ($top_domains as $domain) :
                        $total_label = sprintf(
                            _n('%s lien cassé actif', '%s liens cassés actifs', $domain['count'], 'liens-morts-detector-jlg'),
                            number_format_i18n($domain['count'])
                        );
                        $breakdown_parts = array();
                        if ($domain['client_errors'] > 0) {
                            $breakdown_parts[] = sprintf(
                                __('4xx : %s', 'liens-morts-detector-jlg'),
                                number_format_i18n($domain['client_errors'])
                            );
                        }
                        if ($domain['server_errors'] > 0) {
                            $breakdown_parts[] = sprintf(
                                __('5xx : %s', 'liens-morts-detector-jlg'),
                                number_format_i18n($domain['server_errors'])
                            );
                        }
                        if ($domain['redirects'] > 0) {
                            $breakdown_parts[] = sprintf(
                                __('Redirections : %s', 'liens-morts-detector-jlg'),
                                number_format_i18n($domain['redirects'])
                            );
                        }
                        if ($domain['other'] > 0) {
                            $breakdown_parts[] = sprintf(
                                __('Autres : %s', 'liens-morts-detector-jlg'),
                                number_format_i18n($domain['other'])
                            );
                        }
                        $breakdown = implode(' • ', $breakdown_parts);
                        $search_url = add_query_arg(
                            array('s' => $domain['host']),
                            $build_dashboard_url('all')
                        );
                        ?>
                        <li class="blc-top-domains__item">
                            <div class="blc-top-domains__header">
                                <span class="blc-top-domains__host"><?php echo esc_html($domain['host']); ?></span>
                                <span class="blc-top-domains__count"><?php echo esc_html($total_label); ?></span>
                            </div>
                            <?php if ($breakdown !== '') : ?>
                                <span class="blc-top-domains__breakdown"><?php echo esc_html($breakdown); ?></span>
                            <?php endif; ?>
                            <a class="blc-top-domains__link" href="<?php echo esc_url($search_url); ?>">
                                <?php esc_html_e('Voir les liens', 'liens-morts-detector-jlg'); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>
        <?php if (!empty($priority_actions)) : ?>
            <section class="blc-priority-actions blc-admin-card blc-admin-card--accent" aria-labelledby="blc-priority-actions-heading">
                <div class="blc-priority-actions__header">
                    <h2 id="blc-priority-actions-heading" class="blc-priority-actions__title"><?php esc_html_e('Actions prioritaires', 'liens-morts-detector-jlg'); ?></h2>
                    <p class="blc-priority-actions__intro"><?php esc_html_e('Adoptez un plan d’action guidé pour résorber les erreurs critiques en quelques clics.', 'liens-morts-detector-jlg'); ?></p>
                </div>
                <ol class="blc-priority-actions__list">
                    <?php foreach ($priority_actions as $action) : ?>
                        <li class="blc-priority-actions__item">
                            <span class="blc-priority-actions__badge <?php echo esc_attr($action['severity_class']); ?>"><?php echo esc_html($action['severity_label']); ?></span>
                            <div class="blc-priority-actions__body">
                                <h3 class="blc-priority-actions__item-title"><?php echo esc_html($action['title']); ?></h3>
                                <p class="blc-priority-actions__description"><?php echo esc_html($action['description']); ?></p>
                            </div>
                            <div class="blc-priority-actions__actions">
                                <a class="button button-secondary blc-priority-actions__cta" href="<?php echo esc_url($action['cta_url']); ?>">
                                    <?php echo esc_html($action['cta_label']); ?>
                                </a>
                                <span class="blc-priority-actions__meta"><?php echo esc_html($action['meta']); ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>
        <div
            id="blc-scan-status-panel"
            class="<?php echo esc_attr($scan_panel_class_attr); ?>"
            aria-live="polite"
            data-scan-state="<?php echo esc_attr($scan_state_slug); ?>"
            data-is-full-scan="<?php echo esc_attr(!empty($scan_status['is_full_scan']) ? '1' : '0'); ?>"
        >
            <div class="blc-scan-status__header">
                <h2 class="blc-scan-status__title"><?php esc_html_e('Statut du scan manuel', 'liens-morts-detector-jlg'); ?></h2>
                <span class="blc-scan-status__state"><?php echo esc_html($scan_state_label); ?></span>
            </div>
            <div
                class="blc-scan-status__progress"
                role="progressbar"
                aria-label="<?php echo esc_attr__('Progression du scan manuel', 'liens-morts-detector-jlg'); ?>"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?php echo esc_attr($initial_progress); ?>"
            >
                <span class="blc-scan-status__progress-fill" style="width: <?php echo esc_attr($initial_progress); ?>%;"></span>
            </div>
            <p class="blc-scan-status__details"><?php echo esc_html($scan_details); ?></p>
            <div class="blc-scan-status__actions">
                <button type="button" class="button button-secondary blc-scan-status__cancel" id="blc-cancel-scan">
                    <?php esc_html_e('Annuler le scan', 'liens-morts-detector-jlg'); ?>
                </button>
                <button type="button" class="button blc-scan-status__restart" id="blc-restart-scan">
                    <?php esc_html_e('Replanifier un scan', 'liens-morts-detector-jlg'); ?>
                </button>
            </div>
            <p class="blc-scan-status__message" aria-live="polite"><?php echo esc_html($scan_status['message']); ?></p>
            <button type="button" class="button-link blc-scan-status__refresh" id="blc-refresh-scan-status">
                <?php esc_html_e('Actualiser le statut', 'liens-morts-detector-jlg'); ?>
            </button>
        </div>
        <section class="blc-manual-actions blc-admin-card blc-admin-card--subtle" aria-labelledby="blc-manual-actions-heading">
            <div class="blc-manual-actions__header">
                <h2 id="blc-manual-actions-heading" class="blc-manual-actions__title"><?php esc_html_e('Commandes rapides', 'liens-morts-detector-jlg'); ?></h2>
                <p class="blc-manual-actions__intro"><?php esc_html_e('Lancez, suspendez ou reprogrammez vos scans sans quitter cette page.', 'liens-morts-detector-jlg'); ?></p>
            </div>
            <div class="blc-manual-actions__grid">
                <form id="blc-manual-scan-form" method="post" class="blc-manual-actions__form" data-blc-action="start">
                    <?php wp_nonce_field('blc_manual_check_nonce'); ?>
                    <input type="hidden" name="blc_manual_check" value="1">
                    <div class="blc-manual-actions__primary">
                        <button type="submit" class="button button-primary blc-manual-actions__submit">
                            <?php esc_html_e('Lancer une analyse maintenant', 'liens-morts-detector-jlg'); ?>
                        </button>
                        <span class="blc-manual-actions__hint"><?php esc_html_e('Déclenche immédiatement une analyse manuelle.', 'liens-morts-detector-jlg'); ?></span>
                    </div>
                    <details class="blc-manual-actions__advanced">
                        <summary class="blc-manual-actions__advanced-toggle"><?php esc_html_e('Options avancées', 'liens-morts-detector-jlg'); ?></summary>
                        <div class="blc-manual-actions__advanced-content">
                            <label class="blc-manual-actions__checkbox">
                                <input type="checkbox" name="blc_full_scan">
                                <span><?php esc_html_e('Analyser l’ensemble du contenu (plus long)', 'liens-morts-detector-jlg'); ?></span>
                            </label>
                            <p class="description"><?php esc_html_e('Par défaut, seuls les contenus modifiés depuis la dernière exécution sont inspectés.', 'liens-morts-detector-jlg'); ?></p>
                        </div>
                    </details>
                </form>
                <form method="post" class="blc-manual-actions__form blc-manual-actions__form--secondary" data-blc-action="reschedule">
                    <?php wp_nonce_field('blc_reschedule_cron_nonce'); ?>
                    <input type="hidden" name="blc_reschedule_cron" value="1">
                    <div class="blc-manual-actions__primary">
                        <button type="submit" class="button button-secondary">
                            <?php esc_html_e('Reprogrammer l’analyse automatique', 'liens-morts-detector-jlg'); ?>
                        </button>
                        <span class="blc-manual-actions__hint"><?php esc_html_e('Recrée l’évènement WP-Cron selon la fréquence définie.', 'liens-morts-detector-jlg'); ?></span>
                    </div>
                </form>
            </div>
            <div class="blc-manual-actions__footer" role="note">
                <p class="blc-manual-actions__support">
                    <?php esc_html_e('Besoin d’aide ? Consultez la checklist WP-Cron dans la documentation ou exécutez la commande WP-CLI affichée ci-dessous.', 'liens-morts-detector-jlg'); ?>
                </p>
                <code class="blc-manual-actions__command">wp cron event run blc_manual_check_batch</code>
            </div>
        </section>
        <?php if ($broken_links_count === 0): ?>
             <p><?php esc_html_e('✅ Aucun lien mort trouvé. Bravo !', 'liens-morts-detector-jlg'); ?></p>
        <?php else: ?>
            <div class="blc-status-legend blc-admin-card blc-admin-card--subtle" role="note">
                <p class="blc-status-legend__title"><?php esc_html_e('Légende des statuts HTTP', 'liens-morts-detector-jlg'); ?></p>
                <ul class="blc-status-legend__list">
                    <li class="blc-status-legend__item">
                        <span class="blc-status blc-status--2xx">200</span>
                        <span><?php esc_html_e('Disponible (2xx)', 'liens-morts-detector-jlg'); ?></span>
                    </li>
                    <li class="blc-status-legend__item">
                        <span class="blc-status blc-status--3xx">302</span>
                        <span><?php esc_html_e('Redirection (3xx)', 'liens-morts-detector-jlg'); ?></span>
                    </li>
                    <li class="blc-status-legend__item">
                        <span class="blc-status blc-status--4xx">404</span>
                        <span><?php esc_html_e('Erreur client (4xx)', 'liens-morts-detector-jlg'); ?></span>
                    </li>
                    <li class="blc-status-legend__item">
                        <span class="blc-status blc-status--5xx">502</span>
                        <span><?php esc_html_e('Erreur serveur (5xx)', 'liens-morts-detector-jlg'); ?></span>
                    </li>
                    <li class="blc-status-legend__item">
                        <span class="blc-status blc-status--unknown">?</span>
                        <span><?php esc_html_e('Statut inconnu ou indisponible', 'liens-morts-detector-jlg'); ?></span>
                    </li>
                </ul>
            </div>
            <form method="get" class="blc-links-filter-form" aria-labelledby="blc-links-filter-heading">
                <h2 id="blc-links-filter-heading" class="screen-reader-text"><?php esc_html_e('Filtres de la liste des liens cassés', 'liens-morts-detector-jlg'); ?></h2>
                <?php
                $current_get_params = [];
                if (!empty($_GET) && is_array($_GET)) {
                    $current_get_params = $_GET;
                }

                foreach ($current_get_params as $key => $value) {
                    if (in_array($key, ['s', 'post_type', 'paged'], true)) {
                        continue;
                    }

                    if (is_scalar($value)) {
                        printf(
                            '<input type="hidden" name="%1$s" value="%2$s" />',
                            esc_attr($key),
                            esc_attr((string) $value)
                        );
                    }
                }

                if (
                    isset($_REQUEST['page'])
                    && is_scalar($_REQUEST['page'])
                    && (!isset($current_get_params['page']) || !is_scalar($current_get_params['page']))
                ) {
                    printf(
                        '<input type="hidden" name="page" value="%s" />',
                        esc_attr((string) $_REQUEST['page'])
                    );
                }

                $list_table->views();
                $list_table->display();
                ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Affiche la page du rapport des IMAGES cassées.
 */
function blc_dashboard_images_page() {
    blc_render_action_modal();

    if (isset($_POST['blc_reschedule_image_cron'])) {
        check_admin_referer('blc_reschedule_image_cron_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__("Permissions insuffisantes pour reprogrammer l'analyse automatique des images.", 'liens-morts-detector-jlg'));
        }

        $automatic_enabled = (bool) get_option('blc_image_scan_schedule_enabled', false);

        if (!$automatic_enabled) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html__("La planification automatique des images est désactivée. Activez-la dans les réglages pour reprogrammer le cron.", 'liens-morts-detector-jlg')
            );
        } else {
            $schedule_result = blc_reset_image_check_schedule(
                array(
                    'context' => 'dashboard_reschedule',
                )
            );

            if (!$schedule_result['success']) {
                if (!empty($schedule_result['error_message'])) {
                    error_log($schedule_result['error_message']);
                }

                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    esc_html__("La planification automatique des images n'a pas pu être reprogrammée. Vérifiez la configuration de WP-Cron.", 'liens-morts-detector-jlg')
                );

                if (!empty($schedule_result['restore_attempted'])) {
                    $restore_notice = !empty($schedule_result['restored'])
                        ? esc_html__("La planification précédente des images a été restaurée automatiquement.", 'liens-morts-detector-jlg')
                        : esc_html__("La planification précédente des images n'a pas pu être restaurée. Une intervention manuelle est nécessaire.", 'liens-morts-detector-jlg');

                    printf('<div class="notice notice-warning is-dismissible"><p>%s</p></div>', $restore_notice);
                } else {
                    printf(
                        '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                        esc_html__("Aucune planification précédente n'a été trouvée. Configurez manuellement la vérification automatique si nécessaire.", 'liens-morts-detector-jlg')
                    );
                }
            } else {
                $next_timestamp = 0;
                if (function_exists('wp_next_scheduled')) {
                    $next_event = wp_next_scheduled('blc_check_image_batch', array(0, true));
                    if ($next_event) {
                        $next_timestamp = (int) $next_event;
                    }
                }

                $success_message = esc_html__("Le scan automatique des images a été reprogrammé selon les réglages actuels.", 'liens-morts-detector-jlg');

                if ($next_timestamp > 0) {
                    $success_message .= ' ' . sprintf(
                        /* translators: %s: formatted date for next automatic scan. */
                        esc_html__('Prochaine exécution prévue : %s.', 'liens-morts-detector-jlg'),
                        esc_html(wp_date('j M Y H:i', $next_timestamp))
                    );
                }

                printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', $success_message);
            }
        }
    }

    if (isset($_POST['blc_manual_image_check'])) {
        check_admin_referer('blc_manual_image_check_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permissions insuffisantes pour lancer une analyse manuelle.', 'liens-morts-detector-jlg'));
        }

        $schedule_result = blc_schedule_manual_image_scan();

        if (!$schedule_result['success']) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($schedule_result['message'])
            );
        } else {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($schedule_result['message'])
            );

            if (!empty($schedule_result['manual_trigger_failed'])) {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    esc_html__(
                        "Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.",
                        'liens-morts-detector-jlg'
                    )
                );
            }
        }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'blc_broken_links';
    $image_row_types = blc_get_dataset_row_types('image');
    $broken_images_count = 0;
    $option_size_bytes = 0;
    $last_image_check_time = get_option('blc_last_image_check_time', 0);

    if ($image_row_types !== []) {
        if (count($image_row_types) === 1) {
            $broken_images_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE type = %s",
                    reset($image_row_types)
                )
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($image_row_types), '%s'));
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE type IN ($placeholders)",
                $image_row_types
            );
            $broken_images_count = (int) $wpdb->get_var($query);
        }

        $option_size_bytes = blc_get_dataset_storage_footprint_bytes('image');
    }

    $option_size_kb = $option_size_bytes / 1024;
    $size_display = ($option_size_kb < 1024)
        ? sprintf('%s %s', number_format_i18n($option_size_kb, 2), __('Ko', 'liens-morts-detector-jlg'))
        : sprintf('%s %s', number_format_i18n($option_size_kb / 1024, 2), __('Mo', 'liens-morts-detector-jlg'));

    $last_check_display = $last_image_check_time
        ? wp_date('j M Y', $last_image_check_time)
        : __('Jamais', 'liens-morts-detector-jlg');

    $image_scan_status = blc_get_image_scan_status_payload();
    $image_scan_state_slug = isset($image_scan_status['state']) ? sanitize_key($image_scan_status['state']) : 'idle';
    if ($image_scan_state_slug === '') {
        $image_scan_state_slug = 'idle';
    }

    $image_scan_state_label = blc_get_scan_state_label($image_scan_state_slug);

    $image_total_batches_display = isset($image_scan_status['total_batches']) ? (int) $image_scan_status['total_batches'] : 0;
    if ($image_total_batches_display <= 0 && in_array($image_scan_state_slug, array('queued', 'running'), true)) {
        $image_total_batches_display = max(1, (int) $image_scan_status['processed_batches']);
    }
    $image_total_batches_display = max(0, $image_total_batches_display);

    $image_processed_batches_display = isset($image_scan_status['processed_batches']) ? (int) $image_scan_status['processed_batches'] : 0;
    if ($image_total_batches_display > 0) {
        $image_processed_batches_display = max(0, min($image_processed_batches_display, $image_total_batches_display));
    }

    $image_initial_progress = 0;
    if ($image_total_batches_display > 0) {
        $image_initial_progress = (int) round(($image_processed_batches_display / max(1, $image_total_batches_display)) * 100);
    } elseif ('completed' === $image_scan_state_slug) {
        $image_initial_progress = 100;
    }
    $image_initial_progress = max(0, min(100, $image_initial_progress));

    $image_scan_details_parts = array();

    if (in_array($image_scan_state_slug, array('running', 'queued'), true)) {
        if ($image_total_batches_display > 0) {
            $image_scan_details_parts[] = sprintf(
                /* translators: 1: current batch number, 2: total batch count. */
                __('Lot %1$d sur %2$d.', 'liens-morts-detector-jlg'),
                max(1, $image_processed_batches_display),
                max(1, $image_total_batches_display)
            );
        } else {
            $image_scan_details_parts[] = __('Préparation du prochain lot…', 'liens-morts-detector-jlg');
        }
    } elseif ('completed' === $image_scan_state_slug) {
        $image_scan_details_parts[] = __('Analyse terminée.', 'liens-morts-detector-jlg');
    } elseif ('failed' === $image_scan_state_slug) {
        $image_scan_details_parts[] = __('Dernier scan en échec.', 'liens-morts-detector-jlg');
    } elseif ('cancelled' === $image_scan_state_slug) {
        $image_scan_details_parts[] = __('Scan annulé.', 'liens-morts-detector-jlg');
    }

    $image_next_batch_timestamp = isset($image_scan_status['next_batch_timestamp']) ? (int) $image_scan_status['next_batch_timestamp'] : 0;
    if ($image_next_batch_timestamp > 0 && in_array($image_scan_state_slug, array('running', 'queued'), true)) {
        $image_scan_details_parts[] = sprintf(
            /* translators: %s: next batch scheduled date. */
            __('Prochain lot prévu à %s.', 'liens-morts-detector-jlg'),
            wp_date('j M Y H:i', $image_next_batch_timestamp)
        );
    }

    $image_scan_details = trim(implode(' ', array_filter(array_map('strval', $image_scan_details_parts))));

    $image_scan_panel_classes = array_filter(
        array(
            'blc-scan-status',
            'blc-admin-card',
            'blc-scan-status--state-' . $image_scan_state_slug,
            in_array($image_scan_state_slug, array('running', 'queued'), true) ? 'is-active' : '',
            'completed' === $image_scan_state_slug ? 'is-completed' : '',
            'failed' === $image_scan_state_slug ? 'is-failed' : '',
            'cancelled' === $image_scan_state_slug ? 'is-cancelled' : '',
        )
    );

    $image_scan_panel_class_attr = implode(' ', array_map('sanitize_html_class', $image_scan_panel_classes));
    $image_status_message = isset($image_scan_status['message']) && is_string($image_scan_status['message'])
        ? $image_scan_status['message']
        : '';

    $automatic_image_scan_enabled = (bool) get_option('blc_image_scan_schedule_enabled', false);
    $next_automatic_image_scan    = 0;
    if ($automatic_image_scan_enabled && function_exists('wp_next_scheduled')) {
        $next_event = wp_next_scheduled('blc_check_image_batch', array(0, true));
        if ($next_event) {
            $next_automatic_image_scan = (int) $next_event;
        }
    }

    if ($automatic_image_scan_enabled) {
        $next_image_scan_display = ($next_automatic_image_scan > 0)
            ? wp_date('j M Y H:i', $next_automatic_image_scan)
            : esc_html__('Aucune exécution planifiée', 'liens-morts-detector-jlg');
    } else {
        $next_image_scan_display = esc_html__('Planification automatique désactivée', 'liens-morts-detector-jlg');
    }

    $list_table = new BLC_Images_List_Table();
    $list_table->prepare_items();
    ?>
    <div class="wrap">
        <?php blc_render_dashboard_tabs('images'); ?>
        <h1><?php esc_html_e('Rapport des Images Cassées', 'liens-morts-detector-jlg'); ?></h1>
        <div class="blc-stats-box blc-admin-card blc-admin-card--accent">
            <div class="blc-stat">
                <span class="blc-stat-value"><?php echo esc_html($broken_images_count); ?></span>
                <span class="blc-stat-label"><?php esc_html_e('Images cassées trouvées', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-stat">
                <span class="blc-stat-value"><?php echo esc_html($size_display); ?></span>
                <span class="blc-stat-label"><?php esc_html_e('Poids des données', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-stat">
                <span class="blc-stat-value"><?php echo esc_html($last_check_display); ?></span>
                <span class="blc-stat-label"><?php esc_html_e('Dernière analyse d\'images', 'liens-morts-detector-jlg'); ?></span>
            </div>
        </div>
        <div class="blc-automatic-scan-summary">
            <p>
                <strong><?php esc_html_e('Prochain scan automatique', 'liens-morts-detector-jlg'); ?> :</strong>
                <span><?php echo esc_html($next_image_scan_display); ?></span>
            </p>
            <?php if ($automatic_image_scan_enabled) : ?>
                <form method="post" class="blc-reschedule-image-cron-form">
                    <?php wp_nonce_field('blc_reschedule_image_cron_nonce'); ?>
                    <input type="hidden" name="blc_reschedule_image_cron" value="1">
                    <button type="submit" class="button button-secondary">
                        <?php esc_html_e('Reprogrammer le scan automatique', 'liens-morts-detector-jlg'); ?>
                    </button>
                </form>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Activez la planification automatique dans les réglages pour programmer des analyses récurrentes.', 'liens-morts-detector-jlg'); ?></p>
            <?php endif; ?>
        </div>
        <div
            id="blc-image-scan-status-panel"
            class="<?php echo esc_attr($image_scan_panel_class_attr); ?>"
            aria-live="polite"
            data-scan-state="<?php echo esc_attr($image_scan_state_slug); ?>"
            data-is-full-scan="1"
        >
            <div class="blc-scan-status__header">
                <h2 class="blc-scan-status__title"><?php esc_html_e('Statut du scan des images', 'liens-morts-detector-jlg'); ?></h2>
                <span class="blc-scan-status__state"><?php echo esc_html($image_scan_state_label); ?></span>
            </div>
            <div
                class="blc-scan-status__progress"
                role="progressbar"
                aria-label="<?php echo esc_attr__('Progression du scan manuel', 'liens-morts-detector-jlg'); ?>"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?php echo esc_attr($image_initial_progress); ?>"
            >
                <span class="blc-scan-status__progress-fill" style="width: <?php echo esc_attr($image_initial_progress); ?>%;"></span>
            </div>
            <p class="blc-scan-status__details"><?php echo esc_html($image_scan_details); ?></p>
            <div class="blc-scan-status__actions">
                <button type="button" class="button button-secondary blc-scan-status__cancel" id="blc-image-cancel-scan">
                    <?php esc_html_e('Annuler le scan', 'liens-morts-detector-jlg'); ?>
                </button>
                <button type="button" class="button blc-scan-status__restart" id="blc-image-restart-scan">
                    <?php esc_html_e('Replanifier un scan', 'liens-morts-detector-jlg'); ?>
                </button>
            </div>
            <p class="blc-scan-status__message" aria-live="polite"><?php echo esc_html($image_status_message); ?></p>
        </div>
        <form id="blc-image-manual-scan-form" method="post" class="blc-manual-scan-form blc-admin-card blc-admin-card--subtle">
            <?php wp_nonce_field('blc_manual_image_check_nonce'); ?>
            <input type="hidden" name="blc_manual_image_check" value="1">
            <p><?php esc_html_e("L'analyse des images peut être longue et consommer des ressources. Elle s'exécute en arrière-plan sur l'ensemble du site.", 'liens-morts-detector-jlg'); ?></p>
            <input type="submit" class="button button-primary" value="<?php echo esc_attr__("Lancer l'analyse des images", 'liens-morts-detector-jlg'); ?>">
        </form>
        <?php if ($broken_images_count === 0) : ?>
            <p><?php esc_html_e('✅ Aucune image cassée trouvée. Bravo !', 'liens-morts-detector-jlg'); ?></p>
        <?php else : ?>
            <form method="post">
                <?php $list_table->display(); ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Affiche la page des réglages du plugin.
 */
function blc_settings_page() {
    ?>
    <div class="wrap">
        <?php blc_render_dashboard_tabs('settings'); ?>
        <h1><?php esc_html_e('Réglages', 'liens-morts-detector-jlg'); ?></h1>
        <?php settings_errors(); ?>
        <form method="post" action="options.php" class="blc-settings-form">
            <?php
            settings_fields('blc_settings');
            blc_render_settings_sections_grouped('blc-settings');
            submit_button(__('Enregistrer les modifications', 'liens-morts-detector-jlg'));
            ?>
        </form>
    </div>
    <?php
}

/**
 * Render settings sections grouped by expertise level.
 *
 * @param string $page Settings page slug.
 *
 * @return void
 */
function blc_render_settings_sections_grouped($page) {
    global $wp_settings_sections;

    if (!isset($wp_settings_sections[$page]) || !is_array($wp_settings_sections[$page])) {
        do_settings_sections($page);

        return;
    }

    $sections = $wp_settings_sections[$page];

    $essential_section_ids = array(
        'blc_planification_section',
        'blc_notifications_section',
        'blc_post_types_section',
        'blc_post_statuses_section',
        'blc_ui_section',
    );

    $advanced_section_ids = array();
    foreach ($sections as $section_id => $section) {
        if (in_array($section_id, $essential_section_ids, true)) {
            continue;
        }

        $advanced_section_ids[] = $section_id;
    }

    echo '<div class="blc-settings-groups">';

    echo '<section class="blc-settings-group" aria-labelledby="blc-settings-essential-heading">';
    echo '<header class="blc-settings-group__header">';
    echo '<h2 id="blc-settings-essential-heading" class="blc-settings-group__title">' . esc_html__('Réglages essentiels', 'liens-morts-detector-jlg') . '</h2>';
    echo '<p class="blc-settings-group__description">' . esc_html__('Configurez les paramètres indispensables pour que la surveillance reste active.', 'liens-morts-detector-jlg') . '</p>';
    echo '</header>';
    foreach ($essential_section_ids as $section_id) {
        blc_render_settings_section($page, $section_id);
    }
    echo '</section>';

    if (!empty($advanced_section_ids)) {
        echo '<details class="blc-settings-group blc-settings-group--collapsible" open aria-labelledby="blc-settings-advanced-heading">';
        echo '<summary class="blc-settings-group__summary">';
        echo '<span id="blc-settings-advanced-heading" class="blc-settings-group__title">' . esc_html__('Réglages avancés', 'liens-morts-detector-jlg') . '</span>';
        echo '<span class="blc-settings-group__description">' . esc_html__('Optimisez les performances, heuristiques et intégrations externes.', 'liens-morts-detector-jlg') . '</span>';
        echo '</summary>';
        echo '<div class="blc-settings-group__content">';
        foreach ($advanced_section_ids as $section_id) {
            blc_render_settings_section($page, $section_id);
        }
        echo '</div>';
        echo '</details>';
    }

    echo '</div>';
}

/**
 * Output a single settings section with its fields.
 *
 * @param string $page       Settings page slug.
 * @param string $section_id Section identifier.
 *
 * @return void
 */
function blc_render_settings_section($page, $section_id) {
    global $wp_settings_sections, $wp_settings_fields;

    if (!isset($wp_settings_sections[$page][$section_id])) {
        return;
    }

    $section = $wp_settings_sections[$page][$section_id];

    echo '<section class="blc-settings-section" id="' . esc_attr($section_id) . '">';
    if (!empty($section['title'])) {
        echo '<h3 class="blc-settings-section__title">' . esc_html($section['title']) . '</h3>';
    }

    if (isset($section['callback']) && is_callable($section['callback'])) {
        call_user_func($section['callback'], $section);
    }

    if (!empty($wp_settings_fields[$page][$section_id])) {
        echo '<table class="form-table blc-settings-table" role="presentation">';
        do_settings_fields($page, $section_id);
        echo '</table>';
    }

    echo '</section>';
}

/**
 * Attempt to reschedule the automatic link scan event and return a structured payload.
 *
 * @param string $context Execution context label.
 *
 * @return array<string, mixed>
 */
function blc_reschedule_link_scan_event($context = 'dashboard') {
    $result = blc_reset_link_check_schedule(
        array(
            'context' => $context,
        )
    );

    $payload = array(
        'success'  => (bool) $result['success'],
        'warnings' => array(),
    );

    if (!$payload['success']) {
        $payload['message'] = __("La reprogrammation de l'analyse automatique a échoué. Vérifiez la configuration de WP-Cron.", 'liens-morts-detector-jlg');

        if (!empty($result['restore_attempted']) && empty($result['restored'])) {
            $payload['warnings'][] = __("La planification précédente n'a pas pu être restaurée. Une intervention manuelle est nécessaire.", 'liens-morts-detector-jlg');
        }

        $payload['resolution_hint'] = __("Assurez-vous que WP-Cron est actif (`DISABLE_WP_CRON` à false) ou exécutez `wp cron event run blc_check_batch`.", 'liens-morts-detector-jlg');

        return $payload;
    }

    $payload['message'] = __("La vérification automatique a été reprogrammée avec succès.", 'liens-morts-detector-jlg');

    if (!empty($result['restore_attempted']) && !empty($result['restored'])) {
        $payload['warnings'][] = __("La planification précédente a été restaurée automatiquement.", 'liens-morts-detector-jlg');
    }

    if (function_exists('wp_next_scheduled')) {
        $next_scheduled = wp_next_scheduled('blc_check_batch');
        if ($next_scheduled) {
            $payload['next_run'] = (int) $next_scheduled;
        }
    }

    return $payload;
}

add_action('wp_ajax_blc_start_manual_scan', 'blc_ajax_start_manual_scan');
add_action('wp_ajax_blc_cancel_manual_scan', 'blc_ajax_cancel_manual_scan');
add_action('wp_ajax_blc_get_scan_status', 'blc_ajax_get_scan_status');
add_action('wp_ajax_blc_reschedule_cron', 'blc_ajax_reschedule_cron');
add_action('wp_ajax_blc_start_manual_image_scan', 'blc_ajax_start_manual_image_scan');
add_action('wp_ajax_blc_cancel_manual_image_scan', 'blc_ajax_cancel_manual_image_scan');
add_action('wp_ajax_blc_get_image_scan_status', 'blc_ajax_get_image_scan_status');

/**
 * AJAX handler to start a manual scan via admin-ajax.
 *
 * @return void
 */
function blc_ajax_start_manual_scan() {
    check_ajax_referer('blc_start_manual_scan');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour lancer une analyse manuelle.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $is_full_scan = isset($_POST['full_scan']) && (int) $_POST['full_scan'] === 1;
    $force_cancel = isset($_POST['force_cancel']) && (int) $_POST['force_cancel'] === 1;
    $result = blc_schedule_manual_link_scan($is_full_scan, $force_cancel);

    if (!$result['success']) {
        $error_data = array(
            'message' => $result['message'],
            'status'  => blc_get_link_scan_status_payload(),
        );

        if (!empty($result['requires_confirmation'])) {
            $error_data['requires_confirmation'] = true;
            if (!empty($result['current_state'])) {
                $error_data['current_state'] = $result['current_state'];
            }
            if (!empty($result['resolution_hint'])) {
                $error_data['resolution_hint'] = $result['resolution_hint'];
            }
            wp_send_json_error($error_data, 409);
        }

        if (!empty($result['resolution_hint'])) {
            $error_data['resolution_hint'] = $result['resolution_hint'];
        }

        wp_send_json_error($error_data, 500);
    }

    $response = array(
        'message'               => $result['message'],
        'status'                => blc_get_link_scan_status_payload(),
        'manual_trigger_failed' => !empty($result['manual_trigger_failed']),
    );

    if (!empty($result['manual_trigger_failed'])) {
        $response['warning'] = __("Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.", 'liens-morts-detector-jlg');
    }

    wp_send_json_success($response);
}

/**
 * AJAX handler to reschedule the automatic link scan event.
 *
 * @return void
 */
function blc_ajax_reschedule_cron() {
    check_ajax_referer('blc_reschedule_cron_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour reprogrammer la vérification automatique.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $payload = blc_reschedule_link_scan_event('dashboard_ajax');

    if (!$payload['success']) {
        wp_send_json_error($payload, 500);
    }

    wp_send_json_success($payload);
}

/**
 * AJAX handler to cancel upcoming manual scan batches.
 *
 * @return void
 */
function blc_ajax_cancel_manual_scan() {
    check_ajax_referer('blc_cancel_manual_scan');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour annuler une analyse manuelle.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $manual_cleared = function_exists('wp_clear_scheduled_hook')
        ? wp_clear_scheduled_hook('blc_manual_check_batch')
        : 0;
    $batch_cleared = function_exists('wp_clear_scheduled_hook')
        ? wp_clear_scheduled_hook('blc_check_batch')
        : 0;

    $message = __('Les lots planifiés ont été annulés. Le lot en cours peut se terminer.', 'liens-morts-detector-jlg');

    blc_update_link_scan_status([
        'state'             => 'cancelled',
        'message'           => $message,
        'last_error'        => '',
        'remaining_batches' => 0,
        'total_items'       => 0,
        'processed_items'   => 0,
    ]);

    wp_send_json_success(
        array(
            'message'        => $message,
            'status'         => blc_get_link_scan_status_payload(),
            'cleared_manual' => (int) $manual_cleared,
            'cleared_batch'  => (int) $batch_cleared,
        )
    );
}

/**
 * AJAX handler returning the current scan status payload.
 *
 * @return void
 */
function blc_ajax_get_scan_status() {
    check_ajax_referer('blc_get_scan_status');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour consulter le statut du scan.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    wp_send_json_success(
        array(
            'status' => blc_get_link_scan_status_payload(),
        )
    );
}

/**
 * AJAX handler to start a manual image scan.
 *
 * @return void
 */
function blc_ajax_start_manual_image_scan() {
    check_ajax_referer('blc_start_manual_image_scan');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour lancer une analyse manuelle.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $result = blc_schedule_manual_image_scan();

    if (!$result['success']) {
        wp_send_json_error(
            array(
                'message' => $result['message'],
                'status'  => blc_get_image_scan_status_payload(),
            ),
            500
        );
    }

    $response = array(
        'message'               => $result['message'],
        'status'                => blc_get_image_scan_status_payload(),
        'manual_trigger_failed' => !empty($result['manual_trigger_failed']),
    );

    if (!empty($result['manual_trigger_failed'])) {
        $response['warning'] = __("Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.", 'liens-morts-detector-jlg');
    }

    wp_send_json_success($response);
}

/**
 * AJAX handler to cancel upcoming manual image scan batches.
 *
 * @return void
 */
function blc_ajax_cancel_manual_image_scan() {
    check_ajax_referer('blc_cancel_manual_image_scan');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour annuler une analyse manuelle.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $cleared_batches = function_exists('wp_clear_scheduled_hook')
        ? wp_clear_scheduled_hook('blc_check_image_batch', array(0, true))
        : 0;

    $message = __('Les lots planifiés ont été annulés. Le lot en cours peut se terminer.', 'liens-morts-detector-jlg');

    blc_update_image_scan_status([
        'state'             => 'cancelled',
        'message'           => $message,
        'last_error'        => '',
        'remaining_batches' => 0,
        'total_items'       => 0,
        'processed_items'   => 0,
    ]);

    wp_send_json_success(
        array(
            'message'        => $message,
            'status'         => blc_get_image_scan_status_payload(),
            'cleared_batches' => (int) $cleared_batches,
        )
    );
}

/**
 * AJAX handler returning the current image scan status payload.
 *
 * @return void
 */
function blc_ajax_get_image_scan_status() {
    check_ajax_referer('blc_get_image_scan_status');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour consulter le statut du scan.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    wp_send_json_success(
        array(
            'status' => blc_get_image_scan_status_payload(),
        )
    );
}

