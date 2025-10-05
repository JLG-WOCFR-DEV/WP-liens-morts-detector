<?php
// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_get_scan_history_store')) {
    /**
     * Retrieve the raw scan history option as an associative array.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    function blc_get_scan_history_store() {
        $history = get_option('blc_scan_history', array());

        if (!is_array($history)) {
            return array();
        }

        foreach ($history as $dataset => $entries) {
            if (!is_array($entries)) {
                unset($history[$dataset]);
                continue;
            }

            $history[$dataset] = array_values(array_filter(
                array_map('blc_normalize_scan_history_entry', $entries),
                static function ($entry) {
                    return is_array($entry) && $entry !== array();
                }
            ));
        }

        return $history;
    }
}

if (!function_exists('blc_normalize_scan_history_entry')) {
    /**
     * Normalize a raw history entry.
     *
     * @param mixed $entry Raw entry value.
     *
     * @return array<string,mixed>
     */
    function blc_normalize_scan_history_entry($entry) {
        if (!is_array($entry)) {
            return array();
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        if ($timestamp <= 0) {
            return array();
        }

        $totals = array();
        if (isset($entry['totals']) && is_array($entry['totals'])) {
            foreach ($entry['totals'] as $key => $value) {
                $sanitized_key = sanitize_key((string) $key);
                if ($sanitized_key === '') {
                    continue;
                }
                $totals[$sanitized_key] = max(0, (int) $value);
            }
        }

        return array(
            'timestamp' => $timestamp,
            'totals'    => $totals,
        );
    }
}

if (!function_exists('blc_add_scan_history_entry')) {
    /**
     * Append a new entry to the scan history option for a dataset.
     *
     * @param string               $dataset   Dataset identifier (e.g. "link" or "image").
     * @param array<string, int>   $totals    Aggregated totals keyed by error type.
     * @param int|null             $timestamp Unix timestamp of the entry. Defaults to current time.
     *
     * @return void
     */
    function blc_add_scan_history_entry($dataset, array $totals, $timestamp = null) {
        $dataset_key = sanitize_key((string) $dataset);
        if ($dataset_key === '') {
            return;
        }

        $timestamp = (null === $timestamp) ? time() : (int) $timestamp;
        if ($timestamp <= 0) {
            $timestamp = time();
        }

        $normalized_totals = array();
        foreach ($totals as $key => $value) {
            $metric_key = sanitize_key((string) $key);
            if ($metric_key === '') {
                continue;
            }

            $normalized_totals[$metric_key] = max(0, (int) $value);
        }

        $history = blc_get_scan_history_store();
        if (!isset($history[$dataset_key])) {
            $history[$dataset_key] = array();
        }

        $history[$dataset_key][] = array(
            'timestamp' => $timestamp,
            'totals'    => $normalized_totals,
        );

        $max_entries = 30;
        if (function_exists('apply_filters')) {
            $max_entries = (int) apply_filters('blc_scan_history_max_entries', $max_entries, $dataset_key);
        }

        if ($max_entries > 0 && count($history[$dataset_key]) > $max_entries) {
            $history[$dataset_key] = array_slice($history[$dataset_key], -$max_entries);
        }

        update_option('blc_scan_history', $history, false);
    }
}

if (!function_exists('blc_get_recent_scan_history')) {
    /**
     * Retrieve the most recent scan history entries for a dataset.
     *
     * @param string $dataset Dataset identifier.
     * @param int    $limit   Maximum number of entries to return.
     *
     * @return array<int, array<string,mixed>>
     */
    function blc_get_recent_scan_history($dataset, $limit = 10) {
        $dataset_key = sanitize_key((string) $dataset);
        if ($dataset_key === '') {
            return array();
        }

        $limit = max(1, (int) $limit);
        $history = blc_get_scan_history_store();

        if (!isset($history[$dataset_key]) || !is_array($history[$dataset_key])) {
            return array();
        }

        $entries = $history[$dataset_key];
        $entries = array_values(array_filter(
            array_map('blc_normalize_scan_history_entry', $entries),
            static function ($entry) {
                return is_array($entry) && $entry !== array();
            }
        ));

        if ($entries === array()) {
            return array();
        }

        if (count($entries) > $limit) {
            $entries = array_slice($entries, -$limit);
        }

        return $entries;
    }
}

if (!function_exists('blc_record_link_scan_history_snapshot')) {
    /**
     * Persist the aggregated counters for the latest link scan.
     *
     * @return void
     */
    function blc_record_link_scan_history_snapshot() {
        if (!function_exists('blc_get_link_status_counts')) {
            return;
        }

        $counts = blc_get_link_status_counts();
        if (!is_array($counts)) {
            return;
        }

        $totals = array(
            'broken'         => isset($counts['active_count']) ? (int) $counts['active_count'] : 0,
            'not_found'      => isset($counts['not_found_count']) ? (int) $counts['not_found_count'] : 0,
            'server_error'   => isset($counts['server_error_count']) ? (int) $counts['server_error_count'] : 0,
            'redirect'       => isset($counts['redirect_count']) ? (int) $counts['redirect_count'] : 0,
            'needs_recheck'  => isset($counts['needs_recheck_count']) ? (int) $counts['needs_recheck_count'] : 0,
        );

        blc_add_scan_history_entry('link', $totals);
    }
}

if (!function_exists('blc_record_image_scan_history_snapshot')) {
    /**
     * Persist the aggregated counters for the latest image scan.
     *
     * @return void
     */
    function blc_record_image_scan_history_snapshot() {
        if (!function_exists('blc_get_image_status_counts')) {
            return;
        }

        $counts = blc_get_image_status_counts();
        if (!is_array($counts)) {
            return;
        }

        $totals = array(
            'broken'       => isset($counts['broken_count']) ? (int) $counts['broken_count'] : 0,
            'not_found'    => isset($counts['not_found_count']) ? (int) $counts['not_found_count'] : 0,
            'server_error' => isset($counts['server_error_count']) ? (int) $counts['server_error_count'] : 0,
            'redirect'     => isset($counts['redirect_count']) ? (int) $counts['redirect_count'] : 0,
        );

        blc_add_scan_history_entry('image', $totals);
    }
}
