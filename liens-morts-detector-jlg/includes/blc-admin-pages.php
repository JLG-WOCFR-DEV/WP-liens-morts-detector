<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
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
        $is_full = isset($_POST['blc_full_scan']);
        wp_clear_scheduled_hook('blc_check_batch');
        wp_schedule_single_event(current_time('timestamp'), 'blc_check_batch', array(0, $is_full));
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html__("La vérification des liens a été programmée et s'exécute en arrière-plan.", 'liens-morts-detector-jlg')
        );
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
    $option_size_bytes = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(COALESCE(LENGTH(url), 0) + COALESCE(LENGTH(anchor), 0) + COALESCE(LENGTH(post_title), 0))
             FROM $table_name
             WHERE type = %s",
            'link'
        )
    );
    $last_check_time    = get_option('blc_last_check_time', 0);
    $option_size_kb     = $option_size_bytes / 1024;
    $size_display       = ($option_size_kb < 1024)
        ? sprintf('%s %s', number_format_i18n($option_size_kb, 2), __('Ko', 'liens-morts-detector-jlg'))
        : sprintf('%s %s', number_format_i18n($option_size_kb / 1024, 2), __('Mo', 'liens-morts-detector-jlg'));
    $last_check_display = $last_check_time ? date_i18n('j M Y', $last_check_time) : __('Jamais', 'liens-morts-detector-jlg');

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
        wp_clear_scheduled_hook('blc_check_image_batch');
        wp_schedule_single_event(current_time('timestamp'), 'blc_check_image_batch', array(0, true));
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html__("La vérification des images a été programmée et s'exécute en arrière-plan.", 'liens-morts-detector-jlg')
        );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'blc_broken_links';
    $broken_images_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE type = %s",
            'image'
        )
    );
    $option_size_bytes = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(COALESCE(LENGTH(url), 0) + COALESCE(LENGTH(anchor), 0) + COALESCE(LENGTH(post_title), 0))
             FROM $table_name
             WHERE type = %s",
            'image'
        )
    );
    $last_check_time     = get_option('blc_last_check_time', 0);
    $option_size_kb      = $option_size_bytes / 1024;
    $size_display        = ($option_size_kb < 1024)
        ? sprintf('%s %s', number_format_i18n($option_size_kb, 2), __('Ko', 'liens-morts-detector-jlg'))
        : sprintf('%s %s', number_format_i18n($option_size_kb / 1024, 2), __('Mo', 'liens-morts-detector-jlg'));
    $last_check_display  = $last_check_time ? date_i18n('j M Y', $last_check_time) : __('Jamais', 'liens-morts-detector-jlg');

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
                 <span class="blc-stat-label"><?php esc_html_e('Dernière analyse de liens', 'liens-morts-detector-jlg'); ?></span>
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
    if (isset($_POST['blc_save_settings'])) {
        check_admin_referer('blc_settings_nonce');
        update_option('blc_frequency', sanitize_text_field($_POST['blc_frequency']));
        update_option('blc_rest_start_hour', sanitize_text_field($_POST['blc_rest_start_hour']));
        update_option('blc_rest_end_hour', sanitize_text_field($_POST['blc_rest_end_hour']));
        update_option('blc_link_delay', max(0, intval($_POST['blc_link_delay'])));
        update_option('blc_batch_delay', max(0, intval($_POST['blc_batch_delay'])));
        update_option('blc_scan_method', sanitize_text_field($_POST['blc_scan_method']));
        update_option('blc_excluded_domains', sanitize_textarea_field($_POST['blc_excluded_domains']));
        update_option('blc_debug_mode', isset($_POST['blc_debug_mode']));
        $frequency = sanitize_text_field($_POST['blc_frequency']);
        wp_clear_scheduled_hook('blc_check_links');
        wp_schedule_event(current_time('timestamp'), $frequency, 'blc_check_links');
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html__('Réglages enregistrés !', 'liens-morts-detector-jlg')
        );
    }

    $frequency = get_option('blc_frequency', 'daily');
    $rest_start_hour = get_option('blc_rest_start_hour', '08');
    $rest_end_hour = get_option('blc_rest_end_hour', '20');
    $link_delay = max(0, (int) get_option('blc_link_delay', 200));
    $batch_delay = max(0, (int) get_option('blc_batch_delay', 60));
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
                            <input type="time" name="blc_rest_start_hour" value="<?php echo esc_attr($rest_start_hour); ?>:00">
                            <?php esc_html_e('et', 'liens-morts-detector-jlg'); ?>
                            <input type="time" name="blc_rest_end_hour" value="<?php echo esc_attr($rest_end_hour); ?>:00">
                            <p class="description">
                                <?php
                                echo wp_kses(
                                    __('Le scan automatique des <strong>liens</strong> ne s\'exécutera pas durant cette période. (Fuseau horaire de Paris)', 'liens-morts-detector-jlg'),
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
