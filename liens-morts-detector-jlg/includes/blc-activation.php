<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('BLC_DB_VERSION')) {
    define('BLC_DB_VERSION', '1.1.0');
}

if (!defined('BLC_URL_MAX_LENGTH')) {
    define('BLC_URL_MAX_LENGTH', 2083);
}

if (!defined('BLC_TEXT_FIELD_LENGTH')) {
    define('BLC_TEXT_FIELD_LENGTH', 255);
}

/**
 * Met à jour le schéma de la base de données si nécessaire.
 */
function blc_maybe_upgrade_database() {
    $installed_version = get_option('blc_plugin_db_version');

    if ($installed_version && version_compare($installed_version, BLC_DB_VERSION, '>=')) {
        return;
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'blc_broken_links';
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));

    if (empty($table_exists)) {
        return;
    }

    blc_upgrade_column_if_text($table_name, 'url', sprintf('varchar(%d)', BLC_URL_MAX_LENGTH), false);
    blc_upgrade_column_if_text($table_name, 'anchor', sprintf('varchar(%d)', BLC_TEXT_FIELD_LENGTH), true);
    blc_upgrade_column_if_text($table_name, 'post_title', sprintf('varchar(%d)', BLC_TEXT_FIELD_LENGTH), true);

    update_option('blc_plugin_db_version', BLC_DB_VERSION);
}

/**
 * Convertit une colonne TEXT en VARCHAR si nécessaire.
 *
 * @param string $table_name  Nom de la table.
 * @param string $column_name Nom de la colonne à mettre à jour.
 * @param string $new_type    Nouveau type de colonne (ex. varchar(255)).
 * @param bool   $is_nullable Indique si la colonne peut être NULL.
 */
function blc_upgrade_column_if_text($table_name, $column_name, $new_type, $is_nullable) {
    global $wpdb;

    $table = esc_sql($table_name);
    $column = esc_sql($column_name);

    $column_definition = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $column)
    );

    if (!$column_definition || stripos($column_definition->Type, 'text') === false) {
        return;
    }

    if (preg_match('/\\((\\d+)\\)/', $new_type, $matches)) {
        $max_length = (int) $matches[1];
        if ($max_length > 0) {
            $wpdb->query(
                sprintf(
                    "UPDATE `%s` SET `%s` = LEFT(`%s`, %d)",
                    $table,
                    $column,
                    $column,
                    $max_length
                )
            );
        }
    }

    $null_sql    = $is_nullable ? 'NULL' : 'NOT NULL';
    $default_sql = $is_nullable ? ' DEFAULT NULL' : '';

    $wpdb->query(
        sprintf(
            "ALTER TABLE `%s` MODIFY `%s` %s %s%s",
            $table,
            $column,
            $new_type,
            $null_sql,
            $default_sql
        )
    );
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
        url varchar(" . BLC_URL_MAX_LENGTH . ") NOT NULL,
        anchor varchar(" . BLC_TEXT_FIELD_LENGTH . ") NULL,
        post_id bigint(20) unsigned NOT NULL,
        post_title varchar(" . BLC_TEXT_FIELD_LENGTH . ") NULL,
        type varchar(20) NOT NULL,
        PRIMARY KEY  (id),
        KEY type (type),
        KEY post_id (post_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    blc_maybe_upgrade_database();

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
