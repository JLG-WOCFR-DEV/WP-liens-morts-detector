<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('BLC_DB_VERSION')) {
    define('BLC_DB_VERSION', '1.7.1');
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

    $table_name   = $wpdb->prefix . 'blc_broken_links';
    $table_pattern = $wpdb->esc_like($table_name);
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_pattern));

    if (empty($table_exists)) {
        return;
    }

    if (!$installed_version || version_compare($installed_version, '1.2.0', '<')) {
        blc_migrate_column_to_varchar($table_name, 'anchor', BLC_TEXT_FIELD_LENGTH, true);
        blc_migrate_column_to_varchar($table_name, 'post_title', BLC_TEXT_FIELD_LENGTH, true);
    }

    if (!$installed_version || version_compare($installed_version, '1.3.0', '<')) {
        blc_migrate_column_to_text($table_name, 'url', 'longtext', false);
    }

    if (!$installed_version || version_compare($installed_version, '1.4.0', '<')) {
        blc_maybe_add_index($table_name, 'url_prefix', 'url', 191);
    }

    if (!$installed_version || version_compare($installed_version, '1.5.0', '<')) {
        blc_maybe_add_column($table_name, 'url_host', 'varchar(191) NULL');
        blc_maybe_add_column($table_name, 'is_internal', 'tinyint(1) NOT NULL DEFAULT 0');
        blc_maybe_add_index($table_name, 'url_host', 'url_host');
        blc_maybe_add_index($table_name, 'is_internal', 'is_internal');
    }

    if (!$installed_version || version_compare($installed_version, '1.6.0', '<')) {
        blc_maybe_add_column($table_name, 'occurrence_index', 'int(10) unsigned NULL DEFAULT NULL');
    }

    if (!$installed_version || version_compare($installed_version, '1.7.0', '<')) {
        blc_maybe_add_column($table_name, 'scan_run_id', 'varchar(64) NULL');
        blc_maybe_add_index($table_name, 'scan_run_id', 'scan_run_id');
    }

    if (!$installed_version || version_compare($installed_version, '1.7.1', '<')) {
        blc_maybe_make_occurrence_index_nullable($table_name);
    }

    update_option('blc_plugin_db_version', BLC_DB_VERSION);
}

/**
 * Convertit ou ajuste une colonne en VARCHAR avec la longueur souhaitée.
 *
 * @param string $table_name    Nom de la table.
 * @param string $column_name   Nom de la colonne à mettre à jour.
 * @param int    $target_length Longueur maximale souhaitée pour la colonne.
 * @param bool   $is_nullable   Indique si la colonne peut être NULL.
 */
function blc_migrate_column_to_varchar($table_name, $column_name, $target_length, $is_nullable) {
    global $wpdb;

    $table         = esc_sql($table_name);
    $column        = esc_sql($column_name);
    $column_pattern = $wpdb->esc_like($column_name);

    $column_definition = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $column_pattern)
    );

    if (!$column_definition) {
        return;
    }

    $current_type = strtolower($column_definition->Type);
    $target_length = (int) $target_length;
    $target_type   = sprintf('varchar(%d)', $target_length);

    $needs_update = false;

    if (stripos($current_type, 'text') !== false) {
        $needs_update = true;
    } elseif (preg_match('/varchar\\((\\d+)\\)/', $current_type, $matches)) {
        $current_length = (int) $matches[1];
        if ($current_length !== $target_length) {
            $needs_update = true;
        }
    } else {
        $needs_update = true;
    }

    if (!$needs_update) {
        return;
    }

    if ($target_length > 0) {
        $wpdb->query(
            sprintf(
                "UPDATE `%s` SET `%s` = LEFT(`%s`, %d) WHERE CHAR_LENGTH(`%s`) > %d",
                $table,
                $column,
                $column,
                $target_length,
                $column,
                $target_length
            )
        );
    }

    $null_sql    = $is_nullable ? 'NULL' : 'NOT NULL';
    $default_sql = $is_nullable ? ' DEFAULT NULL' : '';

    $wpdb->query(
        sprintf(
            "ALTER TABLE `%s` MODIFY `%s` %s %s%s",
            $table,
            $column,
            $target_type,
            $null_sql,
            $default_sql
        )
    );
}

/**
 * Convertit une colonne en type TEXT si elle ne l'est pas déjà.
 *
 * @param string $table_name  Nom de la table.
 * @param string $column_name Nom de la colonne à mettre à jour.
 * @param string $target_type Type TEXT cible (TEXT, LONGTEXT, etc.).
 * @param bool   $is_nullable Indique si la colonne peut être NULL.
 */
function blc_migrate_column_to_text($table_name, $column_name, $target_type = 'longtext', $is_nullable = false) {
    global $wpdb;

    $table          = esc_sql($table_name);
    $column         = esc_sql($column_name);
    $column_pattern = $wpdb->esc_like($column_name);

    $column_definition = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $column_pattern)
    );

    if (!$column_definition) {
        return;
    }

    $current_type = strtolower($column_definition->Type);
    $target_type  = strtolower(trim((string) $target_type));

    if ($target_type === '') {
        $target_type = 'text';
    }

    if (strpos($current_type, $target_type) !== false) {
        return;
    }

    if (strpos($current_type, 'text') !== false && $target_type === 'text') {
        return;
    }

    $null_sql    = $is_nullable ? 'NULL' : 'NOT NULL';
    $default_sql = $is_nullable ? ' DEFAULT NULL' : '';

    $wpdb->query(
        sprintf(
            "ALTER TABLE `%s` MODIFY `%s` %s %s%s",
            $table,
            $column,
            strtoupper($target_type),
            $null_sql,
            $default_sql
        )
    );
}

/**
 * Add a column to the specified table when it does not already exist.
 *
 * @param string $table_name Database table name.
 * @param string $column_name Column name to add.
 * @param string $definition Column definition (type, nullability, default, etc.).
 */
function blc_maybe_make_occurrence_index_nullable($table_name) {
    global $wpdb;

    $table          = esc_sql($table_name);
    $column_pattern = $wpdb->esc_like('occurrence_index');

    $column_definition = $wpdb->get_row(
        $wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $column_pattern)
    );

    if (!$column_definition) {
        return;
    }

    $current_type  = strtolower((string) ($column_definition->Type ?? ''));
    $is_nullable   = strtolower((string) ($column_definition->Null ?? '')) === 'yes';
    $default_value = $column_definition->Default;

    $needs_alter = !$is_nullable || $default_value !== null;

    if (strpos($current_type, 'int(10) unsigned') === false) {
        $needs_alter = true;
    }

    if ($needs_alter) {
        $wpdb->query(
            "ALTER TABLE `$table` MODIFY `occurrence_index` int(10) unsigned NULL DEFAULT NULL"
        );
    }

    $wpdb->query(
        "UPDATE `$table` SET `occurrence_index` = NULL WHERE `occurrence_index` = 0"
    );
}

function blc_maybe_add_column($table_name, $column_name, $definition) {
    global $wpdb;

    $table   = esc_sql($table_name);
    $column  = esc_sql($column_name);
    $pattern = $wpdb->esc_like($column_name);

    $existing = $wpdb->get_var(
        $wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $pattern)
    );

    if ($existing) {
        return;
    }

    $wpdb->query(
        sprintf(
            "ALTER TABLE `%s` ADD COLUMN `%s` %s",
            $table,
            $column,
            $definition
        )
    );
}

/**
 * Add an index to the specified table when it does not already exist.
 *
 * @param string   $table_name    Database table name.
 * @param string   $index_name    Desired index name.
 * @param string   $column_name   Column to index.
 * @param int|null $prefix_length Optional prefix length for text columns.
 */
function blc_maybe_add_index($table_name, $index_name, $column_name, $prefix_length = null) {
    global $wpdb;

    $table = esc_sql($table_name);
    $index = esc_sql($index_name);

    $existing_index = $wpdb->get_var(
        $wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name = %s", $index)
    );

    if ($existing_index) {
        return;
    }

    $column = '`' . esc_sql($column_name) . '`';
    if ($prefix_length !== null) {
        $prefix_length = (int) $prefix_length;
        if ($prefix_length > 0) {
            $column .= '(' . $prefix_length . ')';
        }
    }

    $wpdb->query(
        sprintf(
            "ALTER TABLE `%s` ADD INDEX `%s` (%s)",
            $table,
            $index,
            $column
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
        url longtext NOT NULL,
        anchor varchar(" . BLC_TEXT_FIELD_LENGTH . ") NULL,
        post_id bigint(20) unsigned NOT NULL,
        post_title varchar(" . BLC_TEXT_FIELD_LENGTH . ") NULL,
        type varchar(20) NOT NULL,
        occurrence_index int(10) unsigned NULL DEFAULT NULL,
        url_host varchar(191) NULL,
        is_internal tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY type (type),
        KEY post_id (post_id),
        KEY url_prefix (url(191)),
        KEY url_host (url_host),
        KEY is_internal (is_internal)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    blc_maybe_upgrade_database();

    // On récupère la fréquence de scan enregistrée, ou 'daily' par défaut
    $frequency = get_option('blc_frequency', 'daily');
    $frequency = is_string($frequency) ? trim($frequency) : '';
    if ($frequency === '') {
        $frequency = 'daily';
    }

    $schedules = wp_get_schedules();
    if (!isset($schedules[$frequency])) {
        $frequency = 'daily';
    }

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
    wp_clear_scheduled_hook('blc_manual_check_batch');
    wp_clear_scheduled_hook('blc_check_image_batch');
}
