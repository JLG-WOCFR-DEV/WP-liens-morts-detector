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
        $bypass_rest_window = $is_full;
        wp_clear_scheduled_hook('blc_manual_check_batch');
        $scheduled = wp_schedule_single_event(time(), 'blc_manual_check_batch', array(0, $is_full, $bypass_rest_window));

        if (false === $scheduled) {
            error_log(
                sprintf(
                    'BLC: Failed to schedule manual link check (full scan: %s, bypass rest window: %s).',
                    $is_full ? 'true' : 'false',
                    $bypass_rest_window ? 'true' : 'false'
                )
            );
            do_action('blc_manual_check_schedule_failed', $is_full, $bypass_rest_window);
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html__("La vérification des liens n'a pas pu être programmée. Veuillez réessayer.", 'liens-morts-detector-jlg')
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
                        error_log('BLC: Manual cron trigger failed for link check.');
                    }
                }
            }

            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__("La vérification des liens a été programmée et s'exécute en arrière-plan.", 'liens-morts-detector-jlg')
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

    $requested_page = 'blc-dashboard';
    if (isset($_REQUEST['page']) && is_scalar($_REQUEST['page'])) {
        $requested_page = sanitize_key((string) wp_unslash($_REQUEST['page']));
    }

    $nav_items = array(
        'blc-dashboard'        => array(
            'label' => __('Liens cassés', 'liens-morts-detector-jlg'),
        ),
        'blc-images-dashboard' => array(
            'label' => __('Images cassées', 'liens-morts-detector-jlg'),
        ),
        'blc-settings'         => array(
            'label' => __('Réglages', 'liens-morts-detector-jlg'),
        ),
    );

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Rapport des Liens Cassés', 'liens-morts-detector-jlg'); ?></h1>
        <nav class="nav-tab-wrapper blc-dashboard-nav blc-mobile-only" aria-label="<?php echo esc_attr__('Navigation Liens morts', 'liens-morts-detector-jlg'); ?>">
            <?php foreach ($nav_items as $slug => $item) :
                $is_current = ($requested_page === $slug);
                $classes    = 'nav-tab' . ($is_current ? ' nav-tab-active' : '');
                $url        = admin_url('admin.php?page=' . $slug);
                ?>
                <a
                    href="<?php echo esc_url($url); ?>"
                    class="<?php echo esc_attr($classes); ?>"
                    <?php echo $is_current ? 'aria-current="page"' : ''; ?>
                >
                    <?php echo esc_html($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
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
        <form method="post" style="margin-bottom: 20px;">
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

                $current_view = isset($_GET['link_type']) && is_scalar($_GET['link_type'])
                    ? sanitize_text_field((string) wp_unslash($_GET['link_type']))
                    : 'all';

                $views_for_select = array();
                if (method_exists($list_table, 'get_views')) {
                    try {
                        $reflection_method = new \ReflectionMethod($list_table, 'get_views');
                        if (!$reflection_method->isPublic()) {
                            $reflection_method->setAccessible(true);
                        }

                        $maybe_views = $reflection_method->invoke($list_table);
                        if (is_array($maybe_views)) {
                            $views_for_select = $maybe_views;
                        }
                    } catch (\ReflectionException $exception) {
                        $views_for_select = array();
                    }
                }

                if (!empty($views_for_select)) {
                    ?>
                    <div class="blc-link-type-select-wrapper blc-mobile-only">
                        <label class="screen-reader-text" for="blc-link-type-select"><?php esc_html_e('Filtrer les liens cassés', 'liens-morts-detector-jlg'); ?></label>
                        <select name="link_type" id="blc-link-type-select" class="blc-link-type-select" data-current-view="<?php echo esc_attr($current_view); ?>">
                            <?php
                            foreach ($views_for_select as $slug => $view_html) {
                                $label = trim(wp_strip_all_tags($view_html));
                                printf(
                                    '<option value="%1$s" %3$s>%2$s</option>',
                                    esc_attr((string) $slug),
                                    esc_html($label),
                                    selected($current_view, (string) $slug, false)
                                );
                            }
                            ?>
                        </select>
                    </div>
                    <?php
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

