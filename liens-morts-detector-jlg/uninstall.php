<?php

// Sécurité : ne rien faire si ce fichier est appelé directement
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Liste de toutes les options que notre plugin a créées dans la base de données
// (les liens et images brisés sont stockés dans une table dédiée)
$options_to_delete = [
    'blc_last_check_time',
    'blc_last_image_check_time',
    'blc_frequency',
    'blc_rest_start_hour',
    'blc_rest_end_hour',
    'blc_link_delay',
    'blc_batch_delay',
    'blc_scan_method',
    'blc_active_link_scan_key',
    'blc_active_image_scan_key',
    'blc_excluded_domains',
    'blc_debug_mode',
    'blc_image_scan_lock',
    'blc_image_scan_lock_token',
    'blc_link_scan_lock',
    'blc_link_scan_lock_token',
    'blc_link_scan_status',
    'blc_plugin_db_version',
    'blc_dataset_size_link',
    'blc_dataset_size_image',
    'blc_activation_schedule_failure',
];

$cleanup_site = static function () use ($options_to_delete) {
    global $wpdb;
    foreach ($options_to_delete as $option_name) {
        delete_option($option_name);
        delete_site_option($option_name);
    }

    $table_name = $wpdb->prefix . 'blc_broken_links';
    $escaped_table_name = str_replace('`', '``', $table_name);
    $wpdb->query(sprintf('DROP TABLE IF EXISTS `%s`', $escaped_table_name));

    wp_clear_scheduled_hook('blc_check_links');
    wp_clear_scheduled_hook('blc_check_batch');
    wp_clear_scheduled_hook('blc_manual_check_batch');
    wp_clear_scheduled_hook('blc_check_image_batch');
};

if (is_multisite()) {
    $sites = function_exists('get_sites') ? get_sites(['fields' => 'ids']) : [];
    foreach ($sites as $site_id) {
        switch_to_blog((int) $site_id);
        $cleanup_site();
        restore_current_blog();
    }
} else {
    $cleanup_site();
}
