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
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__("La vérification des liens a été programmée et s'exécute en arrière-plan.", 'liens-morts-detector-jlg')
            );
        }
    }

    // Préparation des données et des statistiques pour les liens
    global $wpdb;
    $table_name = $wpdb->prefix . 'blc_broken_links';
    $broken_links_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE type = %s",
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
        <?php if ($broken_links_count === 0): ?>
             <p><?php esc_html_e('✅ Aucun lien mort trouvé. Bravo !', 'liens-morts-detector-jlg'); ?></p>
        <?php else: ?>
            <form method="post">
                <?php $list_table->views(); $list_table->display(); ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Affiche la page du rapport des IMAGES cassées.
 */
function blc_dashboard_images_page() {
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
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__("La vérification des images a été programmée et s'exécute en arrière-plan.", 'liens-morts-detector-jlg')
            );
        }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'blc_broken_links';
    $broken_images_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE type = %s",
            'image'
        )
    );
    $option_size_bytes = blc_get_dataset_storage_footprint_bytes('image');
    $last_image_check_time = get_option('blc_last_image_check_time', 0);
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
    $timeout_constraints = blc_get_request_timeout_constraints();
    $head_timeout_limits = $timeout_constraints['head'];
    $get_timeout_limits  = $timeout_constraints['get'];

    if (isset($_POST['blc_save_settings'])) {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__(
                    "Vous n'avez pas l'autorisation de modifier ces réglages.",
                    'liens-morts-detector-jlg'
                )
            );

            return;
        }

        check_admin_referer('blc_settings_nonce');

        $allowed_frequencies = array('daily', 'weekly', 'monthly');
        $previous_frequency_option = get_option('blc_frequency', 'daily');
        $previous_frequency_sanitized = sanitize_text_field($previous_frequency_option);
        $fallback_frequency = in_array($previous_frequency_sanitized, $allowed_frequencies, true)
            ? $previous_frequency_sanitized
            : 'daily';

        $frequency_raw = isset($_POST['blc_frequency']) ? wp_unslash($_POST['blc_frequency']) : '';
        $submitted_frequency = sanitize_text_field($frequency_raw);
        $frequency_warning = '';

        if (in_array($submitted_frequency, $allowed_frequencies, true)) {
            $frequency = $submitted_frequency;
        } else {
            $frequency = $fallback_frequency;
            $frequency_labels = array(
                'daily'   => __('quotidienne', 'liens-morts-detector-jlg'),
                'weekly'  => __('hebdomadaire', 'liens-morts-detector-jlg'),
                'monthly' => __('mensuelle', 'liens-morts-detector-jlg'),
            );
            $frequency_label = isset($frequency_labels[$frequency]) ? $frequency_labels[$frequency] : $frequency;
            $frequency_warning = sprintf(
                /* translators: %s: fallback frequency label. */
                esc_html__('La fréquence choisie est invalide. La valeur "%s" a été conservée.', 'liens-morts-detector-jlg'),
                esc_html($frequency_label)
            );
        }

        update_option('blc_frequency', $frequency);

        $previous_rest_start = get_option('blc_rest_start_hour', '08');
        $rest_start_hour_raw = isset($_POST['blc_rest_start_hour']) ? wp_unslash($_POST['blc_rest_start_hour']) : '';
        $rest_start_hour_clean = sanitize_text_field($rest_start_hour_raw);
        $rest_start_hour = blc_normalize_hour_option($rest_start_hour_clean, $previous_rest_start);
        update_option('blc_rest_start_hour', $rest_start_hour);

        $previous_rest_end = get_option('blc_rest_end_hour', '20');
        $rest_end_hour_raw = isset($_POST['blc_rest_end_hour']) ? wp_unslash($_POST['blc_rest_end_hour']) : '';
        $rest_end_hour_clean = sanitize_text_field($rest_end_hour_raw);
        $rest_end_hour = blc_normalize_hour_option($rest_end_hour_clean, $previous_rest_end);
        update_option('blc_rest_end_hour', $rest_end_hour);

        $link_delay_raw = isset($_POST['blc_link_delay']) ? wp_unslash($_POST['blc_link_delay']) : '';
        $link_delay = max(0, intval($link_delay_raw));
        update_option('blc_link_delay', $link_delay);

        $batch_delay_raw = isset($_POST['blc_batch_delay']) ? wp_unslash($_POST['blc_batch_delay']) : '';
        $batch_delay = max(0, intval($batch_delay_raw));
        update_option('blc_batch_delay', $batch_delay);

        $previous_head_timeout = blc_normalize_timeout_option(
            get_option('blc_head_request_timeout', $head_timeout_limits['default']),
            $head_timeout_limits['default'],
            $head_timeout_limits['min'],
            $head_timeout_limits['max']
        );
        $head_timeout_raw = isset($_POST['blc_head_request_timeout'])
            ? wp_unslash($_POST['blc_head_request_timeout'])
            : $previous_head_timeout;
        $head_timeout = blc_normalize_timeout_option(
            $head_timeout_raw,
            $previous_head_timeout,
            $head_timeout_limits['min'],
            $head_timeout_limits['max']
        );
        update_option('blc_head_request_timeout', $head_timeout);

        $previous_get_timeout = blc_normalize_timeout_option(
            get_option('blc_get_request_timeout', $get_timeout_limits['default']),
            $get_timeout_limits['default'],
            $get_timeout_limits['min'],
            $get_timeout_limits['max']
        );
        $get_timeout_raw = isset($_POST['blc_get_request_timeout'])
            ? wp_unslash($_POST['blc_get_request_timeout'])
            : $previous_get_timeout;
        $get_timeout = blc_normalize_timeout_option(
            $get_timeout_raw,
            $previous_get_timeout,
            $get_timeout_limits['min'],
            $get_timeout_limits['max']
        );
        update_option('blc_get_request_timeout', $get_timeout);

        $scan_method_raw = isset($_POST['blc_scan_method']) ? wp_unslash($_POST['blc_scan_method']) : '';
        $scan_method = sanitize_text_field($scan_method_raw);
        update_option('blc_scan_method', $scan_method);

        $excluded_domains_raw = isset($_POST['blc_excluded_domains']) ? wp_unslash($_POST['blc_excluded_domains']) : '';
        $excluded_domains = sanitize_textarea_field($excluded_domains_raw);
        update_option('blc_excluded_domains', $excluded_domains);

        $debug_mode = isset($_POST['blc_debug_mode']);
        update_option('blc_debug_mode', $debug_mode);

        $notices = array(
            'success' => array(),
            'warning' => array(),
            'error'   => array(),
        );

        if ($frequency_warning !== '') {
            $notices['warning'][] = $frequency_warning;
        }

        $previous_event_timestamp = wp_next_scheduled('blc_check_links');
        wp_clear_scheduled_hook('blc_check_links');
        $scheduled = wp_schedule_event(time(), $frequency, 'blc_check_links');

        if (false === $scheduled) {
            error_log(
                sprintf(
                    'BLC: Failed to schedule automatic link check (frequency: %s).',
                    $frequency
                )
            );
            do_action('blc_check_links_schedule_failed', $frequency);

            $restore_message_for_error = '';
            if (false !== $previous_event_timestamp && null !== $previous_event_timestamp) {
                $restore_timestamp = (int) $previous_event_timestamp;
                if ($restore_timestamp <= time()) {
                    $restore_timestamp = time() + HOUR_IN_SECONDS;
                }

                $restored = wp_schedule_event($restore_timestamp, $previous_frequency_sanitized, 'blc_check_links');

                if (false === $restored) {
                    error_log(
                        sprintf(
                            'BLC: Failed to restore previous automatic link check schedule (frequency: %s).',
                            $previous_frequency_sanitized
                        )
                    );
                    $restore_message_for_error = esc_html__(
                        "L'ancienne planification n'a pas pu être restaurée. Une intervention manuelle est nécessaire.",
                        'liens-morts-detector-jlg'
                    );
                } else {
                    error_log('BLC: Previous automatic link check schedule restored after failure.');
                    $restore_notice = esc_html__(
                        'La planification précédente a été restaurée automatiquement. Vérifiez que les prochaines analyses se lanceront correctement.',
                        'liens-morts-detector-jlg'
                    );
                    $notices['warning'][] = $restore_notice;
                }
            } else {
                $restore_message_for_error = esc_html__(
                    "Aucune ancienne planification n'a été trouvée. Veuillez configurer manuellement la vérification automatique.",
                    'liens-morts-detector-jlg'
                );
            }

            $error_message = esc_html__(
                "La nouvelle planification n'a pas pu être programmée. Vérifiez la configuration de WP-Cron.",
                'liens-morts-detector-jlg'
            );

            if ($restore_message_for_error !== '') {
                $error_message = sprintf('%s %s', $error_message, $restore_message_for_error);
            }

            $notices['error'][] = $error_message;
        } else {
            $notices['success'][] = esc_html__('Réglages enregistrés !', 'liens-morts-detector-jlg');
        }

        foreach ($notices as $type => $messages) {
            foreach ($messages as $message) {
                printf(
                    '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                    esc_attr($type),
                    $message
                );
            }
        }
    }

    $frequency = get_option('blc_frequency', 'daily');
    $timezone_label = '';

    if (function_exists('wp_timezone_string')) {
        $timezone_label = (string) wp_timezone_string();
    }

    if ('' === $timezone_label && function_exists('wp_timezone')) {
        $timezone_object = wp_timezone();
        if ($timezone_object instanceof DateTimeZone) {
            $timezone_label = $timezone_object->getName();
        }
    }

    if ('' === $timezone_label) {
        $timezone_label = (string) get_option('timezone_string', '');
    }

    if ('' === $timezone_label) {
        $gmt_offset = get_option('gmt_offset');
        if (is_numeric($gmt_offset) && (float) $gmt_offset !== 0.0) {
            $timezone_label = sprintf('UTC%+g', (float) $gmt_offset);
        } else {
            $timezone_label = 'UTC';
        }
    }
    $rest_start_hour_option = get_option('blc_rest_start_hour', '08');
    $rest_end_hour_option = get_option('blc_rest_end_hour', '20');
    $rest_start_hour = blc_prepare_time_input_value($rest_start_hour_option, '08');
    $rest_end_hour = blc_prepare_time_input_value($rest_end_hour_option, '20');
    $link_delay = max(0, (int) get_option('blc_link_delay', 200));
    $batch_delay = max(0, (int) get_option('blc_batch_delay', 60));

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
    $scan_method = get_option('blc_scan_method', 'precise');
    $excluded_domains = get_option('blc_excluded_domains', "x.com\ntwitter.com\nlinkedin.com");
    $debug_mode = get_option('blc_debug_mode', false);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Réglages des liens morts', 'liens-morts-detector-jlg'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('blc_settings_nonce'); ?>
            <h2><?php esc_html_e('Planification', 'liens-morts-detector-jlg'); ?></h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="blc_frequency"><?php esc_html_e('Fréquence de vérification', 'liens-morts-detector-jlg'); ?></label></th>
                        <td>
                            <select name="blc_frequency" id="blc_frequency">
                                <option value="daily" <?php selected($frequency, 'daily'); ?>><?php esc_html_e('Quotidienne', 'liens-morts-detector-jlg'); ?></option>
                                <option value="weekly" <?php selected($frequency, 'weekly'); ?>><?php esc_html_e('Hebdomadaire', 'liens-morts-detector-jlg'); ?></option>
                                <option value="monthly" <?php selected($frequency, 'monthly'); ?>><?php esc_html_e('Mensuelle', 'liens-morts-detector-jlg'); ?></option>
                            </select>
                            <p class="description">
                                <?php
                                echo wp_kses(
                                    __('Fréquence de la vérification automatique des <strong>liens</strong>.', 'liens-morts-detector-jlg'),
                                    array(
                                        'strong' => array(),
                                    )
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="blc_rest_start_hour"><?php esc_html_e('😴 Plage horaire de repos', 'liens-morts-detector-jlg'); ?></label></th>
                        <td>
                            <?php esc_html_e('Ne pas lancer de scan entre', 'liens-morts-detector-jlg'); ?>
                            <input type="time" name="blc_rest_start_hour" value="<?php echo esc_attr($rest_start_hour); ?>">
                            <?php esc_html_e('et', 'liens-morts-detector-jlg'); ?>
                            <input type="time" name="blc_rest_end_hour" value="<?php echo esc_attr($rest_end_hour); ?>">
                            <p class="description">
                                <?php
                                $timezone_information = sprintf(
                                    /* translators: %s: timezone label. */
                                    __('Fuseau horaire : %s', 'liens-morts-detector-jlg'),
                                    esc_html($timezone_label)
                                );

                                $timezone_description = sprintf(
                                    /* translators: %s: formatted timezone information. */
                                    __('Le scan automatique des <strong>liens</strong> ne s\'exécutera pas durant cette période. %s', 'liens-morts-detector-jlg'),
                                    $timezone_information
                                );
                                echo wp_kses(
                                    $timezone_description,
                                    array(
                                        'strong' => array(),
                                    )
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <h2><?php esc_html_e('Performance', 'liens-morts-detector-jlg'); ?></h2>
            <table class="form-table" role="presentation">
                 <tbody>
                    <tr>
                        <th scope="row"><label for="blc_link_delay"><?php esc_html_e('⚙️ Délai entre chaque lien', 'liens-morts-detector-jlg'); ?></label></th>
                        <td>
                           <input type="number" name="blc_link_delay" id="blc_link_delay" value="<?php echo esc_attr($link_delay); ?>" min="0" step="50"> <?php esc_html_e('ms', 'liens-morts-detector-jlg'); ?>
                           <p class="description"><?php esc_html_e('Pause après la vérification de chaque URL. (Défaut : 200)', 'liens-morts-detector-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="blc_batch_delay"><?php esc_html_e('⚙️ Délai entre chaque lot', 'liens-morts-detector-jlg'); ?></label></th>
                        <td>
                           <input type="number" name="blc_batch_delay" id="blc_batch_delay" value="<?php echo esc_attr($batch_delay); ?>" min="10" step="10"> <?php esc_html_e('secondes', 'liens-morts-detector-jlg'); ?>
                           <p class="description"><?php esc_html_e('Pause entre chaque groupe de 20 articles analysés. (Défaut : 60)', 'liens-morts-detector-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="blc_head_request_timeout"><?php esc_html_e('⏱️ Timeout requêtes HEAD', 'liens-morts-detector-jlg'); ?></label></th>
                        <td>
                           <input type="number" name="blc_head_request_timeout" id="blc_head_request_timeout" value="<?php echo esc_attr($head_request_timeout); ?>" min="<?php echo esc_attr($head_timeout_limits['min']); ?>" max="<?php echo esc_attr($head_timeout_limits['max']); ?>" step="0.5"> <?php esc_html_e('secondes', 'liens-morts-detector-jlg'); ?>
                           <p class="description"><?php esc_html_e('Durée maximale accordée à chaque requête HEAD. (Défaut : 5)', 'liens-morts-detector-jlg'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="blc_get_request_timeout"><?php esc_html_e('⏱️ Timeout requêtes GET', 'liens-morts-detector-jlg'); ?></label></th>
                        <td>
                           <input type="number" name="blc_get_request_timeout" id="blc_get_request_timeout" value="<?php echo esc_attr($get_request_timeout); ?>" min="<?php echo esc_attr($get_timeout_limits['min']); ?>" max="<?php echo esc_attr($get_timeout_limits['max']); ?>" step="0.5"> <?php esc_html_e('secondes', 'liens-morts-detector-jlg'); ?>
                           <p class="description"><?php esc_html_e('Durée maximale accordée à chaque requête GET lors du fallback. (Défaut : 10)', 'liens-morts-detector-jlg'); ?></p>
                        </td>
                    </tr>
                 </tbody>
            </table>
            <h2><?php esc_html_e('Méthode d\'Analyse', 'liens-morts-detector-jlg'); ?></h2>
            <table class="form-table" role="presentation">
                 <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Stratégie de vérification', 'liens-morts-detector-jlg'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="blc_scan_method" value="precise" <?php checked($scan_method, 'precise'); ?>>
                                    <strong><?php esc_html_e('Précise (recommandé)', 'liens-morts-detector-jlg'); ?></strong>
                                    <p class="description"><?php esc_html_e('Simule un navigateur. Réduit les faux positifs, mais est un peu plus lent.', 'liens-morts-detector-jlg'); ?></p>
                                </label><br>
                                <label>
                                    <input type="radio" name="blc_scan_method" value="fast" <?php checked($scan_method, 'fast'); ?>>
                                    <strong><?php esc_html_e('Rapide', 'liens-morts-detector-jlg'); ?></strong>
                                    <p class="description"><?php esc_html_e('Vérification basique. Très léger, mais peut générer des faux positifs.', 'liens-morts-detector-jlg'); ?></p>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="blc_excluded_domains"><?php esc_html_e('Liste d\'exclusion', 'liens-morts-detector-jlg'); ?></label></th>
                        <td>
                           <textarea name="blc_excluded_domains" id="blc_excluded_domains" rows="5" class="large-text"><?php echo esc_textarea($excluded_domains); ?></textarea>
                           <p class="description"><?php esc_html_e('Domaines à ignorer pendant l\'analyse. Un domaine par ligne (ex: amazon.fr).', 'liens-morts-detector-jlg'); ?></p>
                        </td>
                    </tr>
                 </tbody>
            </table>
            <h2><?php esc_html_e('Débogage', 'liens-morts-detector-jlg'); ?></h2>
            <table class="form-table" role="presentation">
                 <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Mode Débogage', 'liens-morts-detector-jlg'); ?></th>
                        <td>
                           <fieldset>
                                <label for="blc_debug_mode"><input type="checkbox" name="blc_debug_mode" id="blc_debug_mode" <?php checked($debug_mode, true); ?>> <?php esc_html_e('Activer le journal de débogage', 'liens-morts-detector-jlg'); ?></label>
                                <p class="description">
                                    <?php
                                    echo wp_kses_post(
                                        __('Écrit des informations dans <code>/wp-content/debug.log</code>. Nécessite que <code>WP_DEBUG_LOG</code> soit à <code>true</code> dans <code>wp-config.php</code>.', 'liens-morts-detector-jlg')
                                    );
                                    ?>
                                </p>
                           </fieldset>
                        </td>
                    </tr>
                 </tbody>
            </table>
            <input type="hidden" name="blc_save_settings" value="1">
            <?php submit_button(__('Enregistrer les modifications', 'liens-morts-detector-jlg')); ?>
        </form>
    </div>
    <?php
}
