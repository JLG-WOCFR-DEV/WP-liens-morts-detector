<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

/**
 * S'exécute à l'activation de l'extension.
 * Met en place la tâche planifiée (cron) pour les liens si elle n'existe pas déjà.
 */
function blc_activation() {
    global $wpdb;

    // Création de la table dédiée aux liens et images cassés
    $table_name      = $wpdb->prefix . 'blc_broken_links';
    $charset_collate = $wpdb->get_charset_collate();
    $sql             = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        url text NOT NULL,
        anchor text NULL,
        post_id bigint(20) unsigned NOT NULL,
        post_title text NULL,
        type varchar(20) NOT NULL,
        PRIMARY KEY  (id),
        KEY type (type),
        KEY post_id (post_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // On récupère la fréquence de scan enregistrée, ou 'daily' par défaut
    $frequency = get_option('blc_frequency', 'daily');

    // On vérifie si une tâche est déjà planifiée pour éviter les doublons
    if (!wp_next_scheduled('blc_check_links')) {
        // Planifie l'événement : quand commencer (maintenant), à quelle fréquence, et quelle action exécuter
        wp_schedule_event(time(), $frequency, 'blc_check_links');
    }
}

/**
 * S'exécute à la désactivation de l'extension.
 * Nettoie toutes les tâches planifiées pour ne pas laisser de résidus dans le système.
 */
function blc_deactivation() {
    // Supprime la tâche planifiée principale pour les liens
    wp_clear_scheduled_hook('blc_check_links');
    
    // Supprime également toute tâche de lot qui aurait pu rester en attente
    wp_clear_scheduled_hook('blc_check_batch');
    wp_clear_scheduled_hook('blc_check_image_batch');
}
