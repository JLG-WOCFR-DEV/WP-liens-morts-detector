<?php
/*
Plugin Name: Liens morts detector - JLG
Description: Détecte les liens et images morts sur votre site WordPress et les signale dans le menu d'administration. Prend en charge les vérifications planifiées et des outils de réparation rapide.
Version: 1.0
Author: Jérôme Le Gousse
Text Domain: liens-morts-detector-jlg
Domain Path: /languages
*/

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

// Définir les constantes utiles du plugin
define('BLC_PLUGIN_PATH', plugin_dir_path(__FILE__));

if (!defined('BLC_HTTP_BAD_REQUEST')) {
    define('BLC_HTTP_BAD_REQUEST', 400);
}

if (!defined('BLC_HTTP_FORBIDDEN')) {
    define('BLC_HTTP_FORBIDDEN', 403);
}

if (!defined('BLC_HTTP_NOT_FOUND')) {
    define('BLC_HTTP_NOT_FOUND', 404);
}

if (!defined('BLC_HTTP_CONFLICT')) {
    define('BLC_HTTP_CONFLICT', 409);
}

if (!defined('BLC_HTTP_SERVER_ERROR')) {
    define('BLC_HTTP_SERVER_ERROR', 500);
}

// Charge le domaine de traduction du plugin.
add_action('plugins_loaded', 'blc_load_textdomain');

/**
 * Initialise la traduction du plugin.
 *
 * @return void
 */
function blc_load_textdomain() {
    load_plugin_textdomain('liens-morts-detector-jlg', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// --- Chargement des Fichiers ---
// On inclut tous les fichiers nécessaires au fonctionnement.
require_once BLC_PLUGIN_PATH . 'includes/blc-activation.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-cron.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-scanner.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-utils.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-admin-pages.php';
require_once BLC_PLUGIN_PATH . 'includes/class-blc-links-list-table.php';
require_once BLC_PLUGIN_PATH . 'includes/class-blc-images-list-table.php';

// --- Hameçons (Hooks) d'Activation et de Désactivation ---
// Ces fonctions s'exécutent uniquement à l'activation ou la désactivation du plugin.
register_activation_hook(__FILE__, 'blc_activation');
register_deactivation_hook(__FILE__, 'blc_deactivation');

// Vérifie si la base de données doit être migrée après une mise à jour du plugin.
add_action('plugins_loaded', 'blc_maybe_upgrade_database');

// --- Initialisation des Actions et Filtres ---
// On connecte les fonctions du plugin au cœur de WordPress.

// Ajoute le menu et les pages dans l'administration
add_action('admin_menu', 'blc_add_admin_menu');

// Affiche la notification d'échec de planification lors de l'activation si nécessaire.
add_action('admin_notices', 'blc_maybe_show_activation_schedule_notice');
add_action('network_admin_notices', 'blc_maybe_show_activation_schedule_notice');

// Ajoute nos planifications personnalisées (hebdomadaire, mensuelle) à WP-Cron
add_filter('cron_schedules', 'blc_add_cron_schedules');

// Lie nos fonctions de scan aux tâches planifiées
add_action('blc_check_links', 'blc_perform_check');
add_action('blc_check_batch', 'blc_perform_check', 10, 3);
add_action('blc_manual_check_batch', 'blc_perform_check', 10, 3);
add_action('blc_check_image_batch', 'blc_perform_image_check', 10, 2);

// Ajoute nos fichiers CSS et JS dans l'administration
add_action('admin_enqueue_scripts', 'blc_enqueue_admin_assets');

/**
 * Charge les fichiers CSS et JavaScript sur les pages d'administration du plugin.
 *
 * @param string $hook Le nom de la page d'administration actuelle.
 */
function blc_enqueue_admin_assets($hook) {
    // On s'assure de ne charger les scripts que sur nos pages pour ne pas alourdir le reste de l'admin.
    if (strpos($hook, 'blc-dashboard') === false && strpos($hook, 'blc-images-dashboard') === false && strpos($hook, 'blc-settings') === false) {
        return;
    }
    
    // Chargement du fichier CSS
    $css_path    = __DIR__ . '/assets/css/blc-admin-styles.css';
    $css_version = file_exists($css_path) ? filemtime($css_path) : time();

    wp_enqueue_style(
        'blc-admin-css',
        plugin_dir_url(__FILE__) . 'assets/css/blc-admin-styles.css',
        array(),
        $css_version
    );

    // Chargement du fichier JavaScript
    $js_path    = __DIR__ . '/assets/js/blc-admin-scripts.js';
    $js_version = file_exists($js_path) ? filemtime($js_path) : time();

    wp_enqueue_script(
        'blc-admin-js',
        plugin_dir_url(__FILE__) . 'assets/js/blc-admin-scripts.js',
        array('jquery'),
        $js_version,
        true // Charger dans le pied de page pour de meilleures performances
    );

    wp_localize_script(
        'blc-admin-js',
        'blcAdminMessages',
        array(
            /* translators: %s: original URL displayed in the edit prompt. */
            'editPromptMessage'  => __("Entrez la nouvelle URL pour :\n%s", 'liens-morts-detector-jlg'),
            'editPromptDefault'  => __('https://', 'liens-morts-detector-jlg'),
            'unlinkConfirmation' => __('Êtes-vous sûr de vouloir supprimer ce lien ? Le texte sera conservé.', 'liens-morts-detector-jlg'),
            'errorPrefix'        => __('Erreur : ', 'liens-morts-detector-jlg'),
            'editModalTitle'     => __('Modifier le lien', 'liens-morts-detector-jlg'),
            'editModalLabel'     => __('Nouvelle URL', 'liens-morts-detector-jlg'),
            'editModalConfirm'   => __('Mettre à jour', 'liens-morts-detector-jlg'),
            'unlinkModalTitle'   => __('Supprimer le lien', 'liens-morts-detector-jlg'),
            'unlinkModalConfirm' => __('Supprimer', 'liens-morts-detector-jlg'),
            'ignoreModalTitle'   => __('Ignorer ce lien ?', 'liens-morts-detector-jlg'),
            'ignoreModalMessage' => __('Ce lien sera masqué des résultats et ne sera plus pris en compte lors des prochains contrôles.', 'liens-morts-detector-jlg'),
            'ignoreModalConfirm' => __('Ignorer', 'liens-morts-detector-jlg'),
            'restoreModalTitle'  => __('Réactiver le lien ignoré', 'liens-morts-detector-jlg'),
            'restoreModalMessage' => __('Le lien réapparaîtra dans la liste principale et pourra de nouveau être vérifié.', 'liens-morts-detector-jlg'),
            'restoreModalConfirm' => __('Réintégrer', 'liens-morts-detector-jlg'),
            'cancelButton'       => __('Annuler', 'liens-morts-detector-jlg'),
            'closeLabel'         => __('Fermer la fenêtre modale', 'liens-morts-detector-jlg'),
            'emptyUrlMessage'    => __('Veuillez saisir une URL.', 'liens-morts-detector-jlg'),
            'invalidUrlMessage'  => __('Veuillez saisir une URL valide.', 'liens-morts-detector-jlg'),
            'sameUrlMessage'     => __('La nouvelle URL doit être différente de l\'URL actuelle.', 'liens-morts-detector-jlg'),
            'genericError'        => __('Une erreur est survenue. Veuillez réessayer.', 'liens-morts-detector-jlg'),
            'successAnnouncement' => __('Action effectuée avec succès. La ligne a été retirée de la liste.', 'liens-morts-detector-jlg'),
        )
    );
}

/**
 * Construit un littéral XPath sécurisé pour la valeur fournie.
 *
 * Cette fonction s'assure que la chaîne est correctement encapsulée dans un
 * littéral XPath, même si elle contient des guillemets simples et doubles.
 *
 * @param string $value La valeur à échapper.
 * @return string Le littéral XPath sécurisé.
 */
function blc_xpath_escape($value) {
    $value = (string) $value;

    if ($value === '') {
        return "''";
    }

    if (strpos($value, "'") === false) {
        return "'" . $value . "'";
    }

    if (strpos($value, '"') === false) {
        return '"' . $value . '"';
    }

    $segments = [];
    $parts = explode("'", $value);

    foreach ($parts as $index => $part) {
        $segments[] = "'" . $part . "'";

        if ($index !== count($parts) - 1) {
            $segments[] = "\"'\"";
        }
    }

    if (count($segments) === 1) {
        return $segments[0];
    }

    return 'concat(' . implode(', ', $segments) . ')';
}

// --- Fonctions de rappel AJAX pour les actions rapides ---

/**
 * Récupère et valide la présence des paramètres requis pour une requête AJAX.
 *
 * Chaque paramètre doit être défini dans $_POST, et sa valeur (après suppression
 * des slashes et trim) ne doit pas être vide.
 *
 * @param string[] $required_params Liste des clés attendues dans $_POST.
 * @return array Tableau associatif des valeurs nettoyées.
 *               Cette fonction envoie une réponse JSON d'erreur et interrompt l'exécution
 *               via wp_send_json_error() si une validation échoue.
 */
function blc_require_post_params(array $required_params) {
    $values = [];

    foreach ($required_params as $param) {
        if (!isset($_POST[$param])) {
            wp_send_json_error([
                'message' => sprintf(
                    __('Le paramètre requis "%s" est manquant ou vide.', 'liens-morts-detector-jlg'),
                    $param
                ),
            ], BLC_HTTP_BAD_REQUEST);
        }

        $raw_value = wp_unslash($_POST[$param]);

        if (is_string($raw_value)) {
            $value = trim($raw_value);
        } elseif (is_scalar($raw_value)) {
            $value = trim((string) $raw_value);
        } else {
            $value = '';
        }

        if ($value === '') {
            wp_send_json_error([
                'message' => sprintf(
                    __('Le paramètre requis "%s" est manquant ou vide.', 'liens-morts-detector-jlg'),
                    $param
                ),
            ], BLC_HTTP_BAD_REQUEST);
        }

        $values[$param] = $value;
    }

    return $values;
}

// Gère la modification d'une URL
add_action('wp_ajax_blc_edit_link', 'blc_ajax_edit_link_callback');
function blc_ajax_edit_link_callback() {
    check_ajax_referer('blc_edit_link_nonce');

    $params = blc_require_post_params(['post_id', 'row_id', 'old_url', 'new_url']);

    $post_id = absint($params['post_id']);
    $row_id  = absint($params['row_id']);

    $occurrence_input = null;
    if (isset($_POST['occurrence_index'])) {
        $occurrence_input = wp_unslash($_POST['occurrence_index']);
    }

    $resolution = blc_resolve_link_row($post_id, $row_id, $occurrence_input);
    $row = $resolution['row'];
    $occurrence_index = $resolution['occurrence_index'];
    $table_name = $resolution['table'];
    $row_to_delete = $resolution['cache_row'];
    $row_cache_footprint = $resolution['cache_footprint'];

    global $wpdb;

    $prepared_old_url = blc_prepare_posted_url($params['old_url']);
    $prepared_new_url = blc_prepare_posted_url($params['new_url']);

    if ($prepared_old_url === '' || $prepared_new_url === '') {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $stored_old_url = blc_prepare_url_for_storage($prepared_old_url);
    if (!is_string($row['url']) || $row['url'] !== $stored_old_url) {
        wp_send_json_error([
            'message' => __('Le lien sélectionné est introuvable. Veuillez relancer une analyse.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_CONFLICT);
    }

    $raw_home_url = home_url();
    $site_url = trailingslashit($raw_home_url);
    $site_scheme = parse_url($raw_home_url, PHP_URL_SCHEME);
    if (!is_string($site_scheme) || $site_scheme === '') {
        $site_scheme = 'http';
    }

    $post = get_post($post_id);
    $permalink = '';
    if (function_exists('get_permalink')) {
        $permalink_candidate = null;
        if ($post) {
            $permalink_candidate = get_permalink($post);
        } else {
            $permalink_candidate = get_permalink($post_id);
        }

        if (is_string($permalink_candidate)) {
            $permalink = $permalink_candidate;
        }
    }

    $sanitized_new_url = wp_kses_bad_protocol($prepared_new_url, ['http', 'https']);
    if (!is_string($sanitized_new_url) || $sanitized_new_url === '') {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $normalized_sanitized_new_url = blc_normalize_user_input_url($sanitized_new_url);
    $normalized_prepared_new_url = blc_normalize_user_input_url($prepared_new_url);

    if ($normalized_sanitized_new_url !== $normalized_prepared_new_url) {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $clean_new_url = $sanitized_new_url;
    $looks_like_domain_input = blc_url_looks_like_bare_domain($clean_new_url);

    $validated_old_url = wp_http_validate_url($prepared_old_url);
    $normalized_old_url = $validated_old_url ?: blc_normalize_link_url($prepared_old_url, $site_url, $site_scheme, $permalink);

    $validated_new_url = wp_http_validate_url($clean_new_url);
    $normalized_new_url = '';
    if (!$validated_new_url) {
        $normalized_new_url = blc_normalize_link_url($clean_new_url, $site_url, $site_scheme, $permalink);
        if ($normalized_new_url !== '') {
            $validated_new_url = wp_http_validate_url($normalized_new_url);
        }
    } else {
        $normalized_new_url = $validated_new_url;
    }

    $normalized_parts = $normalized_old_url !== '' ? parse_url($normalized_old_url) : false;
    $validated_new_parts = $validated_new_url ? parse_url($validated_new_url) : false;
    if (
        !$normalized_old_url ||
        $normalized_parts === false ||
        empty($normalized_parts['scheme']) ||
        !in_array($normalized_parts['scheme'], ['http', 'https'], true) ||
        !$validated_new_url ||
        $validated_new_parts === false ||
        empty($validated_new_parts['scheme']) ||
        !in_array($validated_new_parts['scheme'], ['http', 'https'], true)
    ) {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $final_new_url = $clean_new_url;
    $is_explicit_new_url = (
        preg_match('#^https?://#i', $clean_new_url) === 1 ||
        strpos($clean_new_url, '//') === 0 ||
        strpos($clean_new_url, '/') === 0 ||
        strpos($clean_new_url, '#') === 0
    );

    if (!$is_explicit_new_url) {
        if (!$validated_new_url || $normalized_new_url === '') {
            wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
        }

        if ($looks_like_domain_input && $normalized_new_url !== '') {
            $final_new_url = $normalized_new_url;
        }
    }

    if (!$post) {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
        }

        $deleted = $wpdb->delete(
            $table_name,
            ['id' => $row_id],
            ['%d']
        );

        if ($deleted && is_array($row_to_delete) && $row_cache_footprint > 0) {
            blc_adjust_dataset_storage_footprint('link', -$row_cache_footprint);
        }

        wp_send_json_success(['purged' => true]);
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
    }

    $old_url = $prepared_old_url;
    if (strpos($final_new_url, '//') === 0) {
        $scheme_for_scheme_relative = (is_string($site_scheme) && $site_scheme !== '') ? $site_scheme : 'http';
        $final_new_url = set_url_scheme($final_new_url, $scheme_for_scheme_relative);
    }
    $new_url = esc_url_raw($final_new_url);
    if ($new_url === '') {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $normalized_content = blc_normalize_post_content_encoding($post->post_content);
    $replacement = blc_replace_link_href_in_content($normalized_content, $old_url, $new_url, $occurrence_index);

    if (!$replacement['updated']) {
        wp_send_json_error([
            'message' => __('Impossible de localiser cette occurrence du lien. Le contenu a peut-être été modifié.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_CONFLICT);
    }

    $new_content = $replacement['content'];
    $new_content = blc_restore_post_content_encoding($new_content);
    $update_result = wp_update_post([
        'ID' => $post_id,
        'post_content' => wp_slash($new_content),
    ], true);

    if (!$update_result || is_wp_error($update_result)) {
        $error_message = __('La mise à jour de l\'article a échoué.', 'liens-morts-detector-jlg');
        if (is_wp_error($update_result)) {
            $error_message .= ' ' . $update_result->get_error_message();
        }

        wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
        return;
    }

    $delete_result = $wpdb->delete(
        $table_name,
        ['id' => $row_id],
        ['%d']
    );

    if ($delete_result === false || is_wp_error($delete_result)) {
        $error_message = __('La suppression du lien dans la base de données a échoué.', 'liens-morts-detector-jlg');
        if (is_wp_error($delete_result)) {
            $error_message .= ' ' . $delete_result->get_error_message();
        }

        wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
        return;
    }

    if ($delete_result > 0 && is_array($row_to_delete) && $row_cache_footprint > 0) {
        blc_adjust_dataset_storage_footprint('link', -$row_cache_footprint);
    }

    wp_send_json_success();
}

// Gère la dissociation d'un lien
add_action('wp_ajax_blc_unlink', 'blc_ajax_unlink_callback');
function blc_ajax_unlink_callback() {
    check_ajax_referer('blc_unlink_nonce');

    $params = blc_require_post_params(['post_id', 'row_id', 'url_to_unlink']);

    $post_id = absint($params['post_id']);
    $row_id  = absint($params['row_id']);

    $occurrence_input = null;
    if (isset($_POST['occurrence_index'])) {
        $occurrence_input = wp_unslash($_POST['occurrence_index']);
    }

    $resolution = blc_resolve_link_row($post_id, $row_id, $occurrence_input);
    $row = $resolution['row'];
    $occurrence_index = $resolution['occurrence_index'];
    $table_name = $resolution['table'];
    $row_to_delete = $resolution['cache_row'];
    $row_cache_footprint = $resolution['cache_footprint'];

    global $wpdb;

    $raw_home_url = home_url();
    $site_url = trailingslashit($raw_home_url);
    $site_scheme = parse_url($raw_home_url, PHP_URL_SCHEME);
    if (!is_string($site_scheme) || $site_scheme === '') {
        $site_scheme = 'http';
    }

    $prepared_url_to_unlink = blc_prepare_posted_url($params['url_to_unlink']);
    if ($prepared_url_to_unlink === '') {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $sanitized_url_to_unlink = esc_url_raw($prepared_url_to_unlink);
    if ($sanitized_url_to_unlink === '') {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $stored_url_to_unlink = blc_prepare_url_for_storage($prepared_url_to_unlink);
    if (!is_string($row['url']) || $row['url'] !== $stored_url_to_unlink) {
        wp_send_json_error([
            'message' => __('Le lien sélectionné est introuvable. Veuillez relancer une analyse.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_CONFLICT);
    }

    $validated_url = wp_http_validate_url($sanitized_url_to_unlink);
    $normalized_url = $validated_url ?: blc_normalize_link_url($prepared_url_to_unlink, $site_url, $site_scheme);

    $normalized_parts = $normalized_url !== '' ? parse_url($normalized_url) : false;
    if (!$normalized_url || $normalized_parts === false || empty($normalized_parts['scheme']) || !in_array($normalized_parts['scheme'], ['http', 'https'], true)) {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $post = get_post($post_id);
    if (!$post) {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
        }

        $deleted = $wpdb->delete(
            $table_name,
            ['id' => $row_id],
            ['%d']
        );

        if ($deleted && is_array($row_to_delete) && $row_cache_footprint > 0) {
            blc_adjust_dataset_storage_footprint('link', -$row_cache_footprint);
        }

        wp_send_json_success(['purged' => true]);
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
    }

    $normalized_content = blc_normalize_post_content_encoding($post->post_content);
    $removal = blc_remove_link_wrappers_from_content($normalized_content, $prepared_url_to_unlink, $occurrence_index);

    if (!$removal['removed']) {
        wp_send_json_error([
            'message' => __('Impossible de localiser cette occurrence du lien. Le contenu a peut-être été modifié.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_CONFLICT);
    }

    $new_content = blc_restore_post_content_encoding($removal['content']);
    $update_result = wp_update_post([
        'ID' => $post_id,
        'post_content' => wp_slash($new_content),
    ], true);

    if (!$update_result || is_wp_error($update_result)) {
        $error_message = __('La mise à jour de l\'article a échoué.', 'liens-morts-detector-jlg');
        if (is_wp_error($update_result)) {
            $error_message .= ' ' . $update_result->get_error_message();
        }

        wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
        return;
    }

    $delete_result = $wpdb->delete(
        $table_name,
        ['id' => $row_id],
        ['%d']
    );

    if ($delete_result === false || is_wp_error($delete_result)) {
        $error_message = __('La suppression du lien dans la base de données a échoué.', 'liens-morts-detector-jlg');
        if (is_wp_error($delete_result)) {
            $error_message .= ' ' . $delete_result->get_error_message();
        }

        wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
        return;
    }

    if ($delete_result > 0 && is_array($row_to_delete) && $row_cache_footprint > 0) {
        blc_adjust_dataset_storage_footprint('link', -$row_cache_footprint);
    }

    wp_send_json_success();
}

add_action('wp_ajax_blc_ignore_link', 'blc_ajax_ignore_link_callback');
function blc_ajax_ignore_link_callback() {
    check_ajax_referer('blc_ignore_link_nonce');

    $params = blc_require_post_params(['post_id', 'row_id', 'mode']);

    $post_id = absint($params['post_id']);
    $row_id  = absint($params['row_id']);
    $mode_raw = strtolower($params['mode']);
    $mode = ($mode_raw === 'restore') ? 'restore' : ($mode_raw === 'ignore' ? 'ignore' : '');

    if ($mode === '') {
        wp_send_json_error([
            'message' => __('Mode d\'action invalide.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_BAD_REQUEST);
    }

    $occurrence_input = null;
    if (isset($_POST['occurrence_index'])) {
        $occurrence_input = wp_unslash($_POST['occurrence_index']);
    }

    $resolution = blc_resolve_link_row($post_id, $row_id, $occurrence_input);
    $row = $resolution['row'];
    $table_name = $resolution['table'];
    $row_to_delete = $resolution['cache_row'];
    $row_cache_footprint = $resolution['cache_footprint'];

    global $wpdb;

    $post = get_post($post_id);

    if (!$post) {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
        }

        $deleted = $wpdb->delete(
            $table_name,
            ['id' => $row_id],
            ['%d']
        );

        if ($deleted && is_array($row_to_delete) && $row_cache_footprint > 0) {
            blc_adjust_dataset_storage_footprint('link', -$row_cache_footprint);
        }

        wp_send_json_success([
            'purged' => true,
            'announcement' => __('Le lien a été retiré de la liste.', 'liens-morts-detector-jlg'),
        ]);
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
    }

    $ignored_raw = $row['ignored_at'] ?? null;
    $is_currently_ignored = false;
    if (is_string($ignored_raw)) {
        $ignored_raw = trim($ignored_raw);
    }
    if ($ignored_raw !== null && $ignored_raw !== '' && $ignored_raw !== '0000-00-00 00:00:00') {
        $is_currently_ignored = true;
    }

    if ($mode === 'ignore') {
        if ($is_currently_ignored) {
            wp_send_json_success([
                'announcement' => __('Ce lien était déjà ignoré.', 'liens-morts-detector-jlg'),
                'ignored' => true,
            ]);
        }

        $ignored_at = current_time('mysql', true);
        if (!is_string($ignored_at) || $ignored_at === '') {
            $ignored_at = gmdate('Y-m-d H:i:s');
        }

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table_name SET ignored_at = %s WHERE id = %d",
                $ignored_at,
                $row_id
            )
        );

        if ($updated === false || is_wp_error($updated)) {
            $error_message = __('La mise à jour du statut du lien a échoué.', 'liens-morts-detector-jlg');
            if (is_wp_error($updated)) {
                $error_message .= ' ' . $updated->get_error_message();
            }

            wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
        }

        if ($updated > 0 && is_array($row_to_delete) && $row_cache_footprint > 0) {
            blc_adjust_dataset_storage_footprint('link', -$row_cache_footprint);
        }

        wp_send_json_success([
            'announcement' => __('Le lien sera ignoré et masqué de la liste principale.', 'liens-morts-detector-jlg'),
            'ignored' => true,
        ]);
    }

    if (!$is_currently_ignored) {
        wp_send_json_success([
            'announcement' => __('Ce lien est déjà actif.', 'liens-morts-detector-jlg'),
            'restored' => true,
        ]);
    }

    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE $table_name SET ignored_at = NULL WHERE id = %d",
            $row_id
        )
    );

    if ($updated === false || is_wp_error($updated)) {
        $error_message = __('La mise à jour du statut du lien a échoué.', 'liens-morts-detector-jlg');
        if (is_wp_error($updated)) {
            $error_message .= ' ' . $updated->get_error_message();
        }

        wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
    }

    if ($updated > 0 && is_array($row_to_delete) && $row_cache_footprint > 0) {
        blc_adjust_dataset_storage_footprint('link', $row_cache_footprint);
    }

    wp_send_json_success([
        'announcement' => __('Le lien a été réintégré dans la liste principale.', 'liens-morts-detector-jlg'),
        'restored' => true,
    ]);
}
