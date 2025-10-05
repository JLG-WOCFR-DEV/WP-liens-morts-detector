<?php
// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_get_link_status_counts')) {
    /**
     * Retrieve the aggregated status counters for broken links.
     *
     * @return array<string,int>
     */
    function blc_get_link_status_counts() {
        $defaults = array(
            'active_count'        => 0,
            'ignored_count'       => 0,
            'internal_count'      => 0,
            'not_found_count'     => 0,
            'server_error_count'  => 0,
            'redirect_count'      => 0,
            'needs_recheck_count' => 0,
        );

        if (!class_exists('BLC_Links_List_Table')) {
            return $defaults;
        }

        try {
            $table = new BLC_Links_List_Table();
        } catch (\Throwable $e) {
            return $defaults;
        }

        $counts = $table->get_status_counts();
        if (!is_array($counts)) {
            return $defaults;
        }

        $counts = wp_parse_args($counts, $defaults);

        return array_map('intval', $counts);
    }
}

if (!function_exists('blc_get_image_status_counts')) {
    /**
     * Retrieve the aggregated status counters for broken images.
     *
     * @return array<string,int>
     */
    function blc_get_image_status_counts() {
        $defaults = array(
            'broken_count'       => 0,
            'not_found_count'    => 0,
            'server_error_count' => 0,
            'redirect_count'     => 0,
        );

        if (!function_exists('blc_get_dataset_row_types')) {
            return $defaults;
        }

        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || empty($wpdb->prefix)) {
            return $defaults;
        }

        $row_types = blc_get_dataset_row_types('image');
        if ($row_types === []) {
            return $defaults;
        }

        $table_name = $wpdb->prefix . 'blc_broken_links';
        $placeholders = array_fill(0, count($row_types), '%s');
        $where_clause = 'type IN (' . implode(',', $placeholders) . ')';

        $query = "SELECT
                SUM(CASE WHEN ignored_at IS NULL THEN 1 ELSE 0 END) AS broken_count,
                SUM(CASE WHEN ignored_at IS NULL AND http_status IN (404, 410) THEN 1 ELSE 0 END) AS not_found_count,
                SUM(CASE WHEN ignored_at IS NULL AND http_status BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS server_error_count,
                SUM(CASE WHEN ignored_at IS NULL AND http_status BETWEEN 300 AND 399 THEN 1 ELSE 0 END) AS redirect_count
            FROM $table_name
            WHERE $where_clause";

        if (!method_exists($wpdb, 'prepare')) {
            return $defaults;
        }

        $prepared = $wpdb->prepare($query, $row_types);
        if (!is_string($prepared)) {
            return $defaults;
        }

        $row = $wpdb->get_row($prepared, ARRAY_A);
        if (!is_array($row)) {
            return $defaults;
        }

        $row = wp_parse_args($row, $defaults);

        return array_map('intval', $row);
    }
}
