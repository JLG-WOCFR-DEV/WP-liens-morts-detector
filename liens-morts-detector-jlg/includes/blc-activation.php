<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('BLC_DB_VERSION')) {
    define('BLC_DB_VERSION', '1.9.0');
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
        blc_maybe_add_column($table_name, 'occurrence_index', 'int(10) NULL DEFAULT NULL');
        blc_mark_occurrence_indexes_as_unknown($table_name);
    }

    if (!$installed_version || version_compare($installed_version, '1.7.0', '<')) {
        blc_maybe_add_column($table_name, 'scan_run_id', 'varchar(64) NULL');
        blc_maybe_add_index($table_name, 'scan_run_id', 'scan_run_id');
    }

    if (!$installed_version || version_compare($installed_version, '1.7.2', '<')) {
        blc_maybe_make_occurrence_index_nullable($table_name);
    }

    if (!$installed_version || version_compare($installed_version, '1.8.0', '<')) {
        blc_maybe_add_column($table_name, 'http_status', 'smallint(6) NULL');
        blc_maybe_add_column($table_name, 'last_checked_at', 'datetime NULL DEFAULT NULL');
    }

    if (!$installed_version || version_compare($installed_version, '1.9.0', '<')) {
        blc_maybe_add_column($table_name, 'ignored_at', 'datetime NULL DEFAULT NULL');
        blc_maybe_add_index($table_name, 'ignored_at', 'ignored_at');
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

    $is_int_type = strpos($current_type, 'int') !== false;
    $is_unsigned = strpos($current_type, 'unsigned') !== false;

    $needs_alter = !$is_int_type || $is_unsigned || !$is_nullable || $default_value !== null;

    if ($needs_alter) {
        $wpdb->query(
            "ALTER TABLE `$table` MODIFY `occurrence_index` int(10) NULL DEFAULT NULL"
        );
    }

    blc_mark_occurrence_indexes_as_unknown($table_name);
}

/**
 * Replace placeholder occurrence indexes with a sentinel value that indicates an unknown position.
 *
 * @param string $table_name Database table name.
 */
function blc_mark_occurrence_indexes_as_unknown($table_name) {
    global $wpdb;

    $table = esc_sql($table_name);

    $wpdb->query(
        "UPDATE `$table` SET `occurrence_index` = NULL WHERE `occurrence_index` IS NOT NULL AND `occurrence_index` <= 0"
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
function blc_activate_site() {
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
        occurrence_index int(10) NULL DEFAULT NULL,
        url_host varchar(191) NULL,
        is_internal tinyint(1) NOT NULL DEFAULT 0,
        http_status smallint(6) NULL,
        last_checked_at datetime NULL DEFAULT NULL,
        ignored_at datetime NULL DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY type (type),
        KEY post_id (post_id),
        KEY url_prefix (url(191)),
        KEY url_host (url_host),
        KEY is_internal (is_internal),
        KEY ignored_at (ignored_at)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    blc_maybe_upgrade_database();

    // On récupère la fréquence de scan enregistrée, ou 'daily' par défaut
    $frequency_option = get_option('blc_frequency', 'daily');
    $frequency_option = is_string($frequency_option) ? trim($frequency_option) : '';
    if ($frequency_option === '') {
        $frequency_option = 'daily';
    }

    // On vérifie si une tâche est déjà planifiée pour éviter les doublons
    if (!wp_next_scheduled('blc_check_links')) {
        $schedule_result = blc_reset_link_check_schedule(
            array(
                'frequency' => $frequency_option,
                'context'   => 'activation',
            )
        );

        if (!$schedule_result['success']) {
            $log_message = $schedule_result['error_message'] !== ''
                ? $schedule_result['error_message']
                : sprintf(
                    'BLC: Failed to schedule automatic link check during activation (frequency: %s).',
                    $schedule_result['schedule']
                );

            if ('missing_schedule' === $schedule_result['error_code']) {
                do_action('blc_check_links_schedule_failed', $schedule_result['schedule'], 'activation');
            }

            if ($log_message !== '') {
                error_log($log_message);
            }

            $admin_message = esc_html__(
                "La planification automatique des liens n'a pas pu être créée lors de l'activation. Vérifiez la configuration de WP-Cron.",
                'liens-morts-detector-jlg'
            );

            $notice_payload = array(
                'type'      => 'error',
                'message'   => $admin_message,
                'context'   => 'activation',
                'frequency' => $schedule_result['schedule'],
                'logged'    => $log_message,
            );

            if (function_exists('set_transient')) {
                set_transient(
                    'blc_activation_schedule_failure',
                    $notice_payload,
                    defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400
                );
            } else {
                update_option('blc_activation_schedule_failure', $notice_payload);
            }

            $fallback_frequency = 'daily';
            if ($frequency !== $fallback_frequency && isset($schedules[$fallback_frequency])) {
                $fallback_timestamp = time() + (defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600);
                $fallback = wp_schedule_event($fallback_timestamp, $fallback_frequency, 'blc_check_links');

                if (false === $fallback) {
                    error_log(
                        sprintf(
                            'BLC: Failed to schedule fallback automatic link check during activation (frequency: %s, timestamp: %d).',
                            $fallback_frequency,
                            $fallback_timestamp
                        )
                    );
                } else {
                    error_log('BLC: Fallback automatic link check schedule created after activation failure.');
                }
            }
        }
    }
}

/**
 * S'exécute à la désactivation de l'extension.
 * Nettoie toutes les tâches planifiées pour ne pas laisser de résidus dans le système.
 */
function blc_activation($network_wide = false) {
    blc_activate_site();

    $is_network_admin = function_exists('is_network_admin') ? is_network_admin() : false;
    $is_network_activation = is_multisite() && ($is_network_admin || $network_wide);

    if (!$is_network_activation) {
        return;
    }

    $current_blog_id = get_current_blog_id();
    $sites           = get_sites();

    foreach ($sites as $site) {
        $blog_id = (int) $site->blog_id;

        if ($blog_id === $current_blog_id) {
            continue;
        }

        switch_to_blog($blog_id);

        try {
            blc_activate_site();
        } finally {
            restore_current_blog();
        }
    }
}

function blc_deactivate_site() {
    // Supprime la tâche planifiée principale pour les liens
    wp_clear_scheduled_hook('blc_check_links');

    // Supprime également toute tâche de lot qui aurait pu rester en attente
    wp_clear_scheduled_hook('blc_check_batch');
    wp_clear_scheduled_hook('blc_manual_check_batch');
    wp_clear_scheduled_hook('blc_check_image_batch');
}

function blc_deactivation($network_wide = false) {
    blc_deactivate_site();

    $is_network_admin = function_exists('is_network_admin') ? is_network_admin() : false;
    $is_network_deactivation = is_multisite() && ($is_network_admin || $network_wide);

    if (!$is_network_deactivation) {
        return;
    }

    $current_blog_id = get_current_blog_id();
    $sites           = get_sites();

    foreach ($sites as $site) {
        $blog_id = (int) $site->blog_id;

        if ($blog_id === $current_blog_id) {
            continue;
        }

        switch_to_blog($blog_id);

        try {
            blc_deactivate_site();
        } finally {
            restore_current_blog();
        }
    }
}

/**
 * Affiche une éventuelle notification d'échec de planification enregistrée lors de l'activation.
 *
 * Cette fonction récupère la notification stockée dans un transient ou une option et la supprime
 * après l'affichage pour éviter les doublons.
 */
function blc_maybe_show_activation_schedule_notice() {
    $notice = false;

    if (function_exists('get_transient')) {
        $notice = get_transient('blc_activation_schedule_failure');

        if (false !== $notice) {
            delete_transient('blc_activation_schedule_failure');
        }
    }

    if (false === $notice || empty($notice)) {
        $notice = get_option('blc_activation_schedule_failure');

        if (!empty($notice)) {
            delete_option('blc_activation_schedule_failure');
        }
    }

    if (empty($notice) || !is_array($notice)) {
        return;
    }

    $message = isset($notice['message']) ? (string) $notice['message'] : '';
    if ($message === '') {
        return;
    }

    $type = isset($notice['type']) ? strtolower((string) $notice['type']) : 'warning';
    $allowed_types = array('error', 'warning', 'success', 'info');
    if (!in_array($type, $allowed_types, true)) {
        $type = 'warning';
    }

    printf(
        '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
        esc_attr($type),
        wp_kses_post($message)
    );
}
