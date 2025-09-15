<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Crée le menu principal et les sous-menus pour les rapports et les réglages.
 */
function blc_add_admin_menu() {
    add_menu_page('Liens Morts', 'Liens Morts', 'manage_options', 'blc-dashboard', 'blc_dashboard_links_page', 'dashicons-editor-unlink');
    add_submenu_page('blc-dashboard', 'Liens Cassés', 'Liens Cassés', 'manage_options', 'blc-dashboard', 'blc_dashboard_links_page');
    add_submenu_page('blc-dashboard', 'Images Cassées', 'Images Cassées', 'manage_options', 'blc-images-dashboard', 'blc_dashboard_images_page');
    add_submenu_page('blc-dashboard', 'Réglages', 'Réglages', 'manage_options', 'blc-settings', 'blc_settings_page');
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
        wp_schedule_single_event(time(), 'blc_check_batch', array(0, $is_full));
        echo '<div class="notice notice-success is-dismissible"><p>La vérification des liens a été programmée et s\'exécute en arrière-plan.</p></div>';
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
    $size_display       = ($option_size_kb < 1024) ? number_format_i18n($option_size_kb, 2) . ' Ko' : number_format_i18n($option_size_kb / 1024, 2) . ' Mo';

    $list_table = new BLC_Links_List_Table();
    $list_table->prepare_items();
    ?>
    <div class="wrap">
        <h1>Rapport des Liens Cassés</h1>
        <div class="blc-stats-box">
            <div class="blc-stat"><span class="blc-stat-value"><?php echo $broken_links_count; ?></span><span class="blc-stat-label">Liens morts trouvés</span></div>
            <div class="blc-stat"><span class="blc-stat-value"><?php echo $size_display; ?></span><span class="blc-stat-label">Poids des données</span></div>
            <div class="blc-stat"><span class="blc-stat-value"><?php echo $last_check_time ? date_i18n('j M Y', $last_check_time) : 'Jamais'; ?></span><span class="blc-stat-label">Dernière analyse</span></div>
        </div>
        <form method="post" style="margin-bottom: 20px;">
            <?php wp_nonce_field('blc_manual_check_nonce'); ?>
            <input type="hidden" name="blc_manual_check" value="1">
            <p>
                <label><input type="checkbox" name="blc_full_scan"> Lancer une <strong>analyse complète</strong> de tous les articles (plus lent)</label><br>
                <small>Si non cochée, l'analyse ne portera que sur les articles modifiés depuis la dernière exécution.</small>
            </p>
            <input type="submit" class="button button-primary" value="Lancer la vérification des liens">
        </form>
        <?php if ($broken_links_count === 0): ?>
             <p>✅ Aucun lien mort trouvé. Bravo !</p>
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
        wp_schedule_single_event(time(), 'blc_check_image_batch', array(0, true));
        echo '<div class="notice notice-success is-dismissible"><p>La vérification des images a été programmée et s\'exécute en arrière-plan.</p></div>';
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
    $size_display        = ($option_size_kb < 1024) ? number_format_i18n($option_size_kb, 2) . ' Ko' : number_format_i18n($option_size_kb / 1024, 2) . ' Mo';

    $list_table = new BLC_Images_List_Table();
    $list_table->prepare_items();
    ?>
    <div class="wrap">
        <h1>Rapport des Images Cassées</h1>
        <div class="blc-stats-box">
             <div class="blc-stat"><span class="blc-stat-value"><?php echo $broken_images_count; ?></span><span class="blc-stat-label">Images cassées trouvées</span></div>
             <div class="blc-stat"><span class="blc-stat-value"><?php echo $size_display; ?></span><span class="blc-stat-label">Poids des données</span></div>
             <div class="blc-stat"><span class="blc-stat-value"><?php echo $last_check_time ? date_i18n('j M Y', $last_check_time) : 'Jamais'; ?></span><span class="blc-stat-label">Dernière analyse de liens</span></div>
        </div>
        <form method="post" style="margin-bottom: 20px;">
            <?php wp_nonce_field('blc_manual_image_check_nonce'); ?>
            <input type="hidden" name="blc_manual_image_check" value="1">
            <p>L'analyse des images peut être longue et consommer des ressources. Elle s'exécute en arrière-plan sur l'ensemble du site.</p>
            <input type="submit" class="button button-primary" value="Lancer l'analyse des images">
        </form>
        <?php if ($broken_images_count === 0): ?>
             <p>✅ Aucune image cassée trouvée. Bravo !</p>
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
        update_option('blc_link_delay', intval($_POST['blc_link_delay']));
        update_option('blc_batch_delay', intval($_POST['blc_batch_delay']));
        update_option('blc_scan_method', sanitize_text_field($_POST['blc_scan_method']));
        update_option('blc_excluded_domains', sanitize_textarea_field($_POST['blc_excluded_domains']));
        update_option('blc_debug_mode', isset($_POST['blc_debug_mode']));
        $frequency = sanitize_text_field($_POST['blc_frequency']);
        wp_clear_scheduled_hook('blc_check_links');
        wp_schedule_event(time(), $frequency, 'blc_check_links');
        echo '<div class="notice notice-success is-dismissible"><p>Réglages enregistrés !</p></div>';
    }

    $frequency = get_option('blc_frequency', 'daily');
    $rest_start_hour = get_option('blc_rest_start_hour', '08');
    $rest_end_hour = get_option('blc_rest_end_hour', '20');
    $link_delay = get_option('blc_link_delay', 200);
    $batch_delay = get_option('blc_batch_delay', 60);
    $scan_method = get_option('blc_scan_method', 'precise');
    $excluded_domains = get_option('blc_excluded_domains', "x.com\ntwitter.com\nlinkedin.com");
    $debug_mode = get_option('blc_debug_mode', false);
    ?>
    <div class="wrap">
        <h1>Réglages des liens morts</h1>
        <form method="post">
            <?php wp_nonce_field('blc_settings_nonce'); ?>
            <h2>Planification</h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="blc_frequency">Fréquence de vérification</label></th>
                        <td>
                            <select name="blc_frequency" id="blc_frequency">
                                <option value="daily" <?php selected($frequency, 'daily'); ?>>Quotidienne</option>
                                <option value="weekly" <?php selected($frequency, 'weekly'); ?>>Hebdomadaire</option>
                                <option value="monthly" <?php selected($frequency, 'monthly'); ?>>Mensuelle</option>
                            </select>
                            <p class="description">Fréquence de la vérification automatique des <strong>liens</strong>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="blc_rest_start_hour">😴 Plage horaire de repos</label></th>
                        <td>
                            Ne pas lancer de scan entre
                            <input type="time" name="blc_rest_start_hour" value="<?php echo esc_attr($rest_start_hour); ?>:00">
                            et
                            <input type="time" name="blc_rest_end_hour" value="<?php echo esc_attr($rest_end_hour); ?>:00">
                            <p class="description">Le scan automatique des <strong>liens</strong> ne s'exécutera pas durant cette période. (Fuseau horaire de Paris)</p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <h2>Performance</h2>
            <table class="form-table" role="presentation">
                 <tbody>
                    <tr>
                        <th scope="row"><label for="blc_link_delay">⚙️ Délai entre chaque lien</label></th>
                        <td>
                           <input type="number" name="blc_link_delay" id="blc_link_delay" value="<?php echo esc_attr($link_delay); ?>" min="0" step="50"> ms
                           <p class="description">Pause après la vérification de chaque URL. (Défaut : 200)</p>
                        </td>
                    </tr>
                     <tr>
                        <th scope="row"><label for="blc_batch_delay">⚙️ Délai entre chaque lot</label></th>
                        <td>
                           <input type="number" name="blc_batch_delay" id="blc_batch_delay" value="<?php echo esc_attr($batch_delay); ?>" min="10" step="10"> secondes
                           <p class="description">Pause entre chaque groupe de 20 articles analysés. (Défaut : 60)</p>
                        </td>
                    </tr>
                 </tbody>
            </table>
            <h2>Méthode d'Analyse</h2>
            <table class="form-table" role="presentation">
                 <tbody>
                    <tr>
                        <th scope="row">Stratégie de vérification</th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="blc_scan_method" value="precise" <?php checked($scan_method, 'precise'); ?>><strong>Précise (recommandé)</strong><p class="description">Simule un navigateur. Réduit les faux positifs, mais est un peu plus lent.</p></label><br>
                                <label><input type="radio" name="blc_scan_method" value="fast" <?php checked($scan_method, 'fast'); ?>><strong>Rapide</strong><p class="description">Vérification basique. Très léger, mais peut générer des faux positifs.</p></label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="blc_excluded_domains">Liste d'exclusion</label></th>
                        <td>
                           <textarea name="blc_excluded_domains" id="blc_excluded_domains" rows="5" class="large-text"><?php echo esc_textarea($excluded_domains); ?></textarea>
                           <p class="description">Domaines à ignorer pendant l'analyse. Un domaine par ligne (ex: amazon.fr).</p>
                        </td>
                    </tr>
                 </tbody>
            </table>
            <h2>Débogage</h2>
            <table class="form-table" role="presentation">
                 <tbody>
                    <tr>
                        <th scope="row">Mode Débogage</th>
                        <td>
                           <fieldset>
                                <label for="blc_debug_mode"><input type="checkbox" name="blc_debug_mode" id="blc_debug_mode" <?php checked($debug_mode, true); ?>> Activer le journal de débogage</label>
                                <p class="description">Écrit des informations dans <code>/wp-content/debug.log</code>. Nécessite que <code>WP_DEBUG_LOG</code> soit à <code>true</code> dans <code>wp-config.php</code>.</p>
                           </fieldset>
                        </td>
                    </tr>
                 </tbody>
            </table>
            <input type="hidden" name="blc_save_settings" value="1">
            <?php submit_button('Enregistrer les modifications'); ?>
        </form>
    </div>
    <?php
}
