<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_normalize_hour_option')) {
    require_once __DIR__ . '/blc-utils.php';
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
        __('Réglages', 'liens-morts-detector-jlg'),
        __('Réglages', 'liens-morts-detector-jlg'),
        'manage_options',
        'blc-settings',
        'blc_settings_page'
    );
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
    <div id="blc-modal" class="blc-modal" role="presentation" aria-hidden="true">
        <div class="blc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="blc-modal-title">
            <button type="button" class="blc-modal__close" aria-label="<?php echo esc_attr__('Fermer la fenêtre modale', 'liens-morts-detector-jlg'); ?>">
                <span aria-hidden="true">&times;</span>
            </button>
            <h2 id="blc-modal-title" class="blc-modal__title"></h2>
            <p class="blc-modal__message"></p>
            <div class="blc-modal__context" aria-live="polite"></div>
            <div class="blc-modal__error" role="alert" aria-live="assertive"></div>
            <div class="blc-modal__field">
                <label for="blc-modal-url" class="blc-modal__label"></label>
                <input type="url" id="blc-modal-url" class="blc-modal__input" placeholder="<?php echo esc_attr__('https://', 'liens-morts-detector-jlg'); ?>">
            </div>
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
function blc_schedule_manual_link_scan($is_full_scan = false) {
    $is_full_scan = (bool) $is_full_scan;
    $bypass_rest_window = $is_full_scan;

    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('blc_manual_check_batch');
    }

    $scheduled = wp_schedule_single_event(time(), 'blc_manual_check_batch', array(0, $is_full_scan, $bypass_rest_window));

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
                error_log('BLC: Manual cron trigger failed for link check.');
            }
        }
    }

    $status_message = __('Analyse programmée. Le premier lot démarrera sous peu.', 'liens-morts-detector-jlg');

    if ($manual_trigger_failed) {
        $status_message .= ' ' . __("Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.", 'liens-morts-detector-jlg');
    }

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
        'started_at'        => time(),
        'ended_at'          => 0,
    ]);

    return [
        'success'               => true,
        'message'               => __("La vérification des liens a été programmée et s'exécute en arrière-plan.", 'liens-morts-detector-jlg'),
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

        $reschedule_result = blc_reset_link_check_schedule(
            array(
                'context' => 'dashboard',
            )
        );

        if (!$reschedule_result['success']) {
            $error_message = esc_html__(
                "La reprogrammation de l'analyse automatique a échoué. Vérifiez la configuration de WP-Cron.",
                'liens-morts-detector-jlg'
            );

            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                $error_message
            );

            if ($reschedule_result['restore_attempted'] && !$reschedule_result['restored']) {
                printf(
                    '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                    esc_html__(
                        "La planification précédente n'a pas pu être restaurée. Une intervention manuelle est nécessaire.",
                        'liens-morts-detector-jlg'
                    )
                );
            }
        } else {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__("La vérification automatique a été reprogrammée avec succès.", 'liens-morts-detector-jlg')
            );
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
    global $wpdb;
    $table_name = $wpdb->prefix . 'blc_broken_links';
    $broken_links_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE type = %s AND ignored_at IS NULL",
            'link'
        )
    );
    $option_size_bytes = blc_get_dataset_storage_footprint_bytes('link');
    $last_check_time    = get_option('blc_last_check_time', 0);
    $option_size_kb     = $option_size_bytes / 1024;
    $size_display       = ($option_size_kb < 1024)
        ? sprintf('%s %s', number_format_i18n($option_size_kb, 2), __('Ko', 'liens-morts-detector-jlg'))
        : sprintf('%s %s', number_format_i18n($option_size_kb / 1024, 2), __('Mo', 'liens-morts-detector-jlg'));
    $last_check_display = $last_check_time
        ? wp_date('j M Y', $last_check_time)
        : __('Jamais', 'liens-morts-detector-jlg');

    $list_table = new BLC_Links_List_Table();
    $list_table->prepare_items();
    blc_render_action_modal();

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Rapport des Liens Cassés', 'liens-morts-detector-jlg'); ?></h1>
        <div class="blc-stats-box">
            <div class="blc-stat">
                <span class="blc-stat-value"><?php echo esc_html($broken_links_count); ?></span>
                <span class="blc-stat-label"><?php esc_html_e('Liens morts trouvés', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-stat">
                <span class="blc-stat-value"><?php echo esc_html($size_display); ?></span>
                <span class="blc-stat-label"><?php esc_html_e('Poids des données', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-stat">
                <span class="blc-stat-value"><?php echo esc_html($last_check_display); ?></span>
                <span class="blc-stat-label"><?php esc_html_e('Dernière analyse', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-stat">
                <span class="blc-stat-value"><?php echo esc_html($next_scheduled_display); ?></span>
                <span class="blc-stat-label"><?php esc_html_e('Prochaine analyse automatique', 'liens-morts-detector-jlg'); ?></span>
                <?php if ('' !== $schedule_note) : ?>
                    <span class="blc-stat-note"><?php echo esc_html($schedule_note); ?></span>
                <?php endif; ?>
            </div>
        </div>
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
        </div>
        <form id="blc-manual-scan-form" method="post" class="blc-manual-scan-form" style="margin-bottom: 20px;">
            <?php wp_nonce_field('blc_manual_check_nonce'); ?>
            <input type="hidden" name="blc_manual_check" value="1">
            <p>
                <label>
                    <input type="checkbox" name="blc_full_scan">
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: 1: opening strong tag, 2: closing strong tag. */
                            __('Lancer une %1$sanalyse complète%2$s de tous les articles (plus lent)', 'liens-morts-detector-jlg'),
                            '<strong>',
                            '</strong>'
                        ),
                        array(
                            'strong' => array(),
                        )
                    );
                    ?>
                </label><br>
                <small><?php esc_html_e('Si non cochée, l\'analyse ne portera que sur les articles modifiés depuis la dernière exécution.', 'liens-morts-detector-jlg'); ?></small>
            </p>
            <input type="submit" class="button button-primary" value="<?php echo esc_attr__('Lancer la vérification des liens', 'liens-morts-detector-jlg'); ?>">
        </form>
        <form method="post" class="blc-reschedule-cron-form" style="margin-bottom: 20px;">
            <?php wp_nonce_field('blc_reschedule_cron_nonce'); ?>
            <input type="hidden" name="blc_reschedule_cron" value="1">
            <p>
                <button type="submit" class="button button-secondary">
                    <?php esc_html_e('Reprogrammer la vérification automatique', 'liens-morts-detector-jlg'); ?>
                </button>
                <span class="description"><?php esc_html_e('Force la création d\'un nouvel événement selon la cadence configurée.', 'liens-morts-detector-jlg'); ?></span>
            </p>
        </form>
        <?php if ($broken_links_count === 0): ?>
             <p><?php esc_html_e('✅ Aucun lien mort trouvé. Bravo !', 'liens-morts-detector-jlg'); ?></p>
        <?php else: ?>
            <div class="blc-status-legend" role="note">
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

    // Gère le lancement du scan d'images
    if (isset($_POST['blc_manual_image_check'])) {
        check_admin_referer('blc_manual_image_check_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permissions insuffisantes pour lancer une analyse manuelle.', 'liens-morts-detector-jlg'));
        }

        wp_clear_scheduled_hook('blc_check_image_batch');
        $scheduled = wp_schedule_single_event(time(), 'blc_check_image_batch', array(0, true));

        if (false === $scheduled) {
            error_log('BLC: Failed to schedule manual image check.');
            do_action('blc_manual_image_check_schedule_failed');
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html__("La vérification des images n'a pas pu être programmée. Veuillez réessayer.", 'liens-morts-detector-jlg')
            );
        } else {
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

            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__("La vérification des images a été programmée et s'exécute en arrière-plan.", 'liens-morts-detector-jlg')
            );

            if ($manual_trigger_failed) {
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
    $option_size_kb      = $option_size_bytes / 1024;
    $size_display        = ($option_size_kb < 1024)
        ? sprintf('%s %s', number_format_i18n($option_size_kb, 2), __('Ko', 'liens-morts-detector-jlg'))
        : sprintf('%s %s', number_format_i18n($option_size_kb / 1024, 2), __('Mo', 'liens-morts-detector-jlg'));
    $last_check_display  = $last_image_check_time
        ? wp_date('j M Y', $last_image_check_time)
        : __('Jamais', 'liens-morts-detector-jlg');

    $list_table = new BLC_Images_List_Table();
    $list_table->prepare_items();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Rapport des Images Cassées', 'liens-morts-detector-jlg'); ?></h1>
        <div class="blc-stats-box">
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
        <form method="post" style="margin-bottom: 20px;">
            <?php wp_nonce_field('blc_manual_image_check_nonce'); ?>
            <input type="hidden" name="blc_manual_image_check" value="1">
            <p><?php esc_html_e("L'analyse des images peut être longue et consommer des ressources. Elle s'exécute en arrière-plan sur l'ensemble du site.", 'liens-morts-detector-jlg'); ?></p>
            <input type="submit" class="button button-primary" value="<?php echo esc_attr__("Lancer l'analyse des images", 'liens-morts-detector-jlg'); ?>">
        </form>
        <?php if ($broken_images_count === 0): ?>
             <p><?php esc_html_e('✅ Aucune image cassée trouvée. Bravo !', 'liens-morts-detector-jlg'); ?></p>
        <?php else: ?>
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
        <h1><?php esc_html_e('Réglages', 'liens-morts-detector-jlg'); ?></h1>
        <?php settings_errors(); ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('blc_settings');
            do_settings_sections('blc-settings');
            submit_button(__('Enregistrer les modifications', 'liens-morts-detector-jlg'));
            ?>
        </form>
    </div>
    <?php
}

add_action('wp_ajax_blc_start_manual_scan', 'blc_ajax_start_manual_scan');
add_action('wp_ajax_blc_cancel_manual_scan', 'blc_ajax_cancel_manual_scan');
add_action('wp_ajax_blc_get_scan_status', 'blc_ajax_get_scan_status');

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
    $result = blc_schedule_manual_link_scan($is_full_scan);

    if (!$result['success']) {
        wp_send_json_error(
            array(
                'message' => $result['message'],
                'status'  => blc_get_link_scan_status_payload(),
            ),
            500
        );
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

