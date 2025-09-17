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

// Ajoute nos planifications personnalisées (hebdomadaire, mensuelle) à WP-Cron
add_filter('cron_schedules', 'blc_add_cron_schedules');

// Lie nos fonctions de scan aux tâches planifiées
add_action('blc_check_links', 'blc_perform_check');
add_action('blc_check_batch', 'blc_perform_check', 10, 2);
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
            wp_send_json_error(['message' => sprintf('Le paramètre requis "%s" est manquant ou vide.', $param)]);
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
            wp_send_json_error(['message' => sprintf('Le paramètre requis "%s" est manquant ou vide.', $param)]);
        }

        $values[$param] = $value;
    }

    return $values;
}

// Gère la modification d'une URL
add_action('wp_ajax_blc_edit_link', 'blc_ajax_edit_link_callback');
function blc_ajax_edit_link_callback() {
    check_ajax_referer('blc_edit_link_nonce');

    $params = blc_require_post_params(['post_id', 'old_url', 'new_url']);

    $post_id = intval($params['post_id']);

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.']);
    }

    $raw_home_url = home_url();
    $site_url = trailingslashit($raw_home_url);
    $site_scheme = parse_url($raw_home_url, PHP_URL_SCHEME);
    if (!is_string($site_scheme) || $site_scheme === '') {
        $site_scheme = 'http';
    }

    $raw_old_url = $params['old_url'];
    $raw_new_url = $params['new_url'];

    $validated_old_url = wp_http_validate_url($raw_old_url);
    $normalized_old_url = $validated_old_url ?: blc_normalize_link_url($raw_old_url, $site_url, $site_scheme);
    $validated_new_url = wp_http_validate_url($raw_new_url);

    $normalized_parts = $normalized_old_url !== '' ? parse_url($normalized_old_url) : false;
    if (!$normalized_old_url || $normalized_parts === false || empty($normalized_parts['scheme']) || !in_array($normalized_parts['scheme'], ['http', 'https'], true) || !$validated_new_url) {
        wp_send_json_error(['message' => 'URL invalide.']);
    }

    $old_url = esc_url_raw($raw_old_url);
    $new_url = esc_url_raw($validated_new_url);

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(['message' => 'Article non trouvé.']);
    }

    $dom_data = blc_load_dom_from_post($post->post_content);
    if (isset($dom_data['error'])) {
        wp_send_json_error(['message' => $dom_data['error']]);
        return;
    }

    /** @var DOMDocument $dom */
    $dom = $dom_data['dom'];
    /** @var DOMXPath $xpath */
    $xpath = $dom_data['xpath'];

    // Recherche et modification de la balise <a> ciblée
    $anchors = $xpath->query(sprintf('//a[@href=%s]', blc_xpath_escape($old_url)));

    if ($anchors->length === 0) {
        wp_send_json_error(['message' => 'Le lien n\'a pas été trouvé dans le contenu de l\'article.']);
    }

    foreach ($anchors as $a) {
        $a->setAttribute('href', $new_url);
    }

    // Enregistrement du contenu mis à jour
    $new_content = $dom->saveHTML();
    $update_result = wp_update_post([
        'ID' => $post_id,
        'post_content' => wp_slash($new_content),
    ], true);

    if (!$update_result || is_wp_error($update_result)) {
        $error_message = 'La mise à jour de l\'article a échoué.';
        if (is_wp_error($update_result)) {
            $error_message .= ' ' . $update_result->get_error_message();
        }

        wp_send_json_error(['message' => $error_message]);
        return;
    }

    // Supprimer le lien de la table dédiée
    global $wpdb;
    $table_name = $wpdb->prefix . 'blc_broken_links';
    $delete_result = $wpdb->delete(
        $table_name,
        ['post_id' => $post_id, 'url' => $old_url, 'type' => 'link'],
        ['%d', '%s', '%s']
    );

    if (!$delete_result || is_wp_error($delete_result)) {
        $error_message = 'La suppression du lien dans la base de données a échoué.';
        if (is_wp_error($delete_result)) {
            $error_message .= ' ' . $delete_result->get_error_message();
        }

        wp_send_json_error(['message' => $error_message]);
        return;
    }

    wp_send_json_success();
}

// Gère la dissociation d'un lien
add_action('wp_ajax_blc_unlink', 'blc_ajax_unlink_callback');
function blc_ajax_unlink_callback() {
    check_ajax_referer('blc_unlink_nonce');

    $params = blc_require_post_params(['post_id', 'url_to_unlink']);

    $post_id = intval($params['post_id']);

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.']);
    }

    $raw_home_url = home_url();
    $site_url = trailingslashit($raw_home_url);
    $site_scheme = parse_url($raw_home_url, PHP_URL_SCHEME);
    if (!is_string($site_scheme) || $site_scheme === '') {
        $site_scheme = 'http';
    }

    $raw_url_to_unlink = $params['url_to_unlink'];
    $validated_url = wp_http_validate_url($raw_url_to_unlink);
    $normalized_url = $validated_url ?: blc_normalize_link_url($raw_url_to_unlink, $site_url, $site_scheme);

    $normalized_parts = $normalized_url !== '' ? parse_url($normalized_url) : false;
    if (!$normalized_url || $normalized_parts === false || empty($normalized_parts['scheme']) || !in_array($normalized_parts['scheme'], ['http', 'https'], true)) {
        wp_send_json_error(['message' => 'URL invalide.']);
    }

    $url_to_unlink = esc_url_raw($raw_url_to_unlink);
    if ($url_to_unlink === '') {
        wp_send_json_error(['message' => 'URL invalide.']);
    }

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(['message' => 'Article non trouvé.']);
    }

    $dom_data = blc_load_dom_from_post($post->post_content);
    if (isset($dom_data['error'])) {
        wp_send_json_error(['message' => $dom_data['error']]);
        return;
    }

    /** @var DOMDocument $dom */
    $dom = $dom_data['dom'];
    /** @var DOMXPath $xpath */
    $xpath = $dom_data['xpath'];

    // Recherche de la balise <a> à retirer
    $anchors = $xpath->query(sprintf('//a[@href=%s]', blc_xpath_escape($url_to_unlink)));

    if ($anchors->length === 0) {
        wp_send_json_error(['message' => 'Le lien n\'a pas été trouvé dans le contenu de l\'article.']);
    }

    foreach ($anchors as $a) {
        $fragment = $dom->createDocumentFragment();
        while ($a->childNodes->length > 0) {
            $fragment->appendChild($a->childNodes->item(0));
        }
        $a->parentNode->replaceChild($fragment, $a);
    }

    // Enregistrement du contenu mis à jour
    $new_content = $dom->saveHTML();
    $update_result = wp_update_post([
        'ID' => $post_id,
        'post_content' => wp_slash($new_content),
    ], true);

    if (!$update_result || is_wp_error($update_result)) {
        $error_message = 'La mise à jour de l\'article a échoué.';
        if (is_wp_error($update_result)) {
            $error_message .= ' ' . $update_result->get_error_message();
        }

        wp_send_json_error(['message' => $error_message]);
        return;
    }

    // Supprimer le lien de la table dédiée
    global $wpdb;
    $table_name = $wpdb->prefix . 'blc_broken_links';
    $delete_result = $wpdb->delete(
        $table_name,
        ['post_id' => $post_id, 'url' => $url_to_unlink, 'type' => 'link'],
        ['%d', '%s', '%s']
    );

    if (!$delete_result || is_wp_error($delete_result)) {
        $error_message = 'La suppression du lien dans la base de données a échoué.';
        if (is_wp_error($delete_result)) {
            $error_message .= ' ' . $delete_result->get_error_message();
        }

        wp_send_json_error(['message' => $error_message]);
        return;
    }

    wp_send_json_success();
}
