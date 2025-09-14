<?php
/*
Plugin Name: Liens morts detector - JLG
Description: Détecte les liens et images morts sur votre site WordPress et les signale dans le menu d'administration. Prend en charge les vérifications planifiées et des outils de réparation rapide.
Version: V 1.0
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
require_once BLC_PLUGIN_PATH . 'includes/blc-admin-pages.php';
require_once BLC_PLUGIN_PATH . 'includes/class-blc-links-list-table.php';
require_once BLC_PLUGIN_PATH . 'includes/class-blc-images-list-table.php';

// --- Hameçons (Hooks) d'Activation et de Désactivation ---
// Ces fonctions s'exécutent uniquement à l'activation ou la désactivation du plugin.
register_activation_hook(__FILE__, 'blc_activation');
register_deactivation_hook(__FILE__, 'blc_deactivation');

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
    wp_enqueue_style(
        'blc-admin-css',
        plugin_dir_url(__FILE__) . 'assets/css/blc-admin-styles.css',
        array(),
        '1.0'
    );
    
    // Chargement du fichier JavaScript
    wp_enqueue_script(
        'blc-admin-js',
        plugin_dir_url(__FILE__) . 'assets/js/blc-admin-scripts.js',
        array('jquery'),
        '1.0',
        true // Charger dans le pied de page pour de meilleures performances
    );
}

// --- Fonctions de rappel AJAX pour les actions rapides ---

// Gère la modification d'une URL
add_action('wp_ajax_blc_edit_link', 'blc_ajax_edit_link_callback');
function blc_ajax_edit_link_callback() {
    check_ajax_referer('blc_edit_link_nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.']);
    }

    $post_id = intval($_POST['post_id']);
    $old_url = esc_url_raw($_POST['old_url']);
    $new_url = esc_url_raw($_POST['new_url']);

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(['message' => 'Article non trouvé.']);
    }

    // Remplacement de l'URL dans le contenu
    $new_content = str_replace('href="' . $old_url . '"', 'href="' . $new_url . '"', $post->post_content);
    
    if ($new_content === $post->post_content) {
        wp_send_json_error(['message' => 'Le lien n\'a pas été trouvé dans le contenu de l\'article.']);
    }

    wp_update_post(['ID' => $post_id, 'post_content' => $new_content]);

    // Supprimer le lien de la liste des liens morts
    $broken_links = get_option('blc_broken_links', []);
    $updated_links = array_filter($broken_links, function($link) use ($post_id, $old_url) {
        return !($link['post_id'] == $post_id && $link['url'] == $old_url);
    });
    update_option('blc_broken_links', array_values($updated_links));

    wp_send_json_success();
}

// Gère la dissociation d'un lien
add_action('wp_ajax_blc_unlink', 'blc_ajax_unlink_callback');
function blc_ajax_unlink_callback() {
    check_ajax_referer('blc_unlink_nonce');
     if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.']);
    }

    $post_id = intval($_POST['post_id']);
    $url_to_unlink = esc_url_raw($_POST['url_to_unlink']);

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(['message' => 'Article non trouvé.']);
    }

    // Remplacement du lien complet par son texte
    $pattern = '/<a\s[^>]*href\s*=\s*["\']' . preg_quote($url_to_unlink, '/') . '["\'][^>]*>(.*?)<\/a>/i';
    $new_content = preg_replace($pattern, '$1', $post->post_content);

    if ($new_content === $post->post_content) {
        wp_send_json_error(['message' => 'Le lien n\'a pas été trouvé dans le contenu de l\'article.']);
    }

    wp_update_post(['ID' => $post_id, 'post_content' => $new_content]);

    // Supprimer le lien de la liste des liens morts
    $broken_links = get_option('blc_broken_links', []);
    $updated_links = array_filter($broken_links, function($link) use ($post_id, $url_to_unlink) {
        return !($link['post_id'] == $post_id && $link['url'] == $url_to_unlink);
    });
    update_option('blc_broken_links', array_values($updated_links));

    wp_send_json_success();
}
