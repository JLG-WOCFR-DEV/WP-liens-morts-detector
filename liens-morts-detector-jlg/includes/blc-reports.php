<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_run_automated_report_exports')) {
    /**
     * Generate CSV exports for the latest completed scans.
     *
     * @param bool $force When true, bypass freshness checks and generate exports.
     *
     * @return void
     */
    function blc_run_automated_report_exports($force = false) {
        if (!function_exists('blc_is_report_export_enabled') || !blc_is_report_export_enabled()) {
            return;
        }

        $datasets = blc_get_report_export_datasets();
        if ($datasets === []) {
            return;
        }

        $directory = blc_prepare_report_export_directory();
        if (function_exists('is_wp_error') && is_wp_error($directory)) {
            blc_log_report_export_error($directory);

            return;
        }

        foreach ($datasets as $dataset_type) {
            $status = blc_get_scan_status_for_report_dataset($dataset_type);
            if ($status === null) {
                continue;
            }

            $previous = blc_get_previous_report_export($dataset_type);
            if (!blc_should_generate_report_export($dataset_type, $status, $previous, $force)) {
                blc_record_report_export_result($dataset_type, $status, null, true);

                continue;
            }

            $rows = blc_collect_report_rows($dataset_type);
            if (function_exists('is_wp_error') && is_wp_error($rows)) {
                blc_log_report_export_error($rows);
                blc_record_report_export_result($dataset_type, $status, null, true);

                continue;
            }

            $metadata = blc_write_report_export($dataset_type, $rows, $directory, $status);
            if (function_exists('is_wp_error') && is_wp_error($metadata)) {
                blc_log_report_export_error($metadata);
                blc_record_report_export_result($dataset_type, $status, null, true);

                continue;
            }

            blc_record_report_export_result($dataset_type, $status, $metadata, false);
        }
    }
}

if (!function_exists('blc_get_report_export_datasets')) {
    /**
     * Retrieve the dataset identifiers that should produce CSV exports.
     *
     * @return string[]
     */
    function blc_get_report_export_datasets() {
        $datasets = ['link', 'image'];

        if (function_exists('apply_filters')) {
            $datasets = apply_filters('blc_report_export_datasets', $datasets);
        }

        $datasets = array_filter(
            array_map(
                static function ($dataset) {
                    return is_string($dataset) ? trim($dataset) : '';
                },
                (array) $datasets
            ),
            static function ($value) {
                return $value !== '';
            }
        );

        return array_values(array_unique($datasets));
    }
}

if (!function_exists('blc_prepare_report_export_directory')) {
    /**
     * Ensure the export directory exists inside the uploads folder.
     *
     * @return array<string,string>|\WP_Error
     */
    function blc_prepare_report_export_directory() {
        if (!function_exists('wp_upload_dir')) {
            return new \WP_Error(
                'blc_report_upload_dir_unavailable',
                __('Unable to determine the uploads directory for report exports.', 'liens-morts-detector-jlg')
            );
        }

        $uploads = wp_upload_dir();
        if (is_array($uploads) && isset($uploads['error']) && $uploads['error']) {
            $error_message = is_string($uploads['error'])
                ? $uploads['error']
                : __('Unknown uploads directory error.', 'liens-morts-detector-jlg');

            return new \WP_Error('blc_report_upload_dir_error', $error_message);
        }

        $base_dir = is_array($uploads) && isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        $base_url = is_array($uploads) && isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';

        if ($base_dir === '') {
            return new \WP_Error(
                'blc_report_upload_dir_empty',
                __('The uploads directory path is empty.', 'liens-morts-detector-jlg')
            );
        }

        $base_dir = rtrim($base_dir, '/\\');
        $directory = $base_dir . '/blc-report-exports';

        if (!is_dir($directory)) {
            $created = false;
            if (function_exists('wp_mkdir_p')) {
                $created = wp_mkdir_p($directory);
            } else {
                $created = mkdir($directory, 0777, true);
            }

            if (!$created) {
                return new \WP_Error(
                    'blc_report_export_mkdir_failed',
                    sprintf(
                        /* translators: %s: absolute path to the export directory. */
                        __('Unable to create the report export directory (%s).', 'liens-morts-detector-jlg'),
                        $directory
                    )
                );
            }
        }

        $directory_url = $base_url !== '' ? rtrim($base_url, '/\\') . '/blc-report-exports' : '';

        return [
            'path' => $directory,
            'url'  => $directory_url,
        ];
    }
}

if (!function_exists('blc_collect_report_rows')) {
    /**
     * Fetch rows that should be exported for a dataset type.
     *
     * @param string $dataset_type Dataset identifier.
     *
     * @return array<int, array<string,mixed>>|\WP_Error
     */
    function blc_collect_report_rows($dataset_type) {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix)) {
            return new \WP_Error(
                'blc_report_database_unavailable',
                __('The WordPress database layer is unavailable for report exports.', 'liens-morts-detector-jlg')
            );
        }

        $row_types = [];
        if (function_exists('blc_get_dataset_row_types')) {
            $row_types = blc_get_dataset_row_types($dataset_type);
        } elseif ($dataset_type === 'image') {
            $row_types = ['image', 'remote-image'];
        } else {
            $row_types = ['link'];
        }

        if (!is_array($row_types) || $row_types === []) {
            return [];
        }

        if (!method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return new \WP_Error(
                'blc_report_database_methods_missing',
                __('The database connection does not support prepared statements required for exports.', 'liens-morts-detector-jlg')
            );
        }

        $placeholders = implode(',', array_fill(0, count($row_types), '%s'));
        $table_name   = $wpdb->prefix . 'blc_broken_links';

        $query = $wpdb->prepare(
            "SELECT url, http_status, post_id, post_title, type, occurrence_index, is_internal, ignored_at, last_checked_at, redirect_target_url, context_excerpt " .
            "FROM $table_name WHERE type IN ($placeholders) ORDER BY ignored_at IS NULL DESC, http_status DESC, url ASC",
            $row_types
        );

        if (!is_string($query)) {
            return new \WP_Error(
                'blc_report_query_prepare_failed',
                __('Failed to prepare the export query.', 'liens-morts-detector-jlg')
            );
        }

        $raw_rows = $wpdb->get_results($query, ARRAY_A);
        if (!is_array($raw_rows)) {
            $raw_rows = [];
        }

        $rows = [];
        foreach ($raw_rows as $row) {
            $rows[] = [
                'url'                => isset($row['url']) ? (string) $row['url'] : '',
                'http_status'        => isset($row['http_status']) && $row['http_status'] !== null ? (int) $row['http_status'] : null,
                'post_id'            => isset($row['post_id']) ? (int) $row['post_id'] : 0,
                'post_title'         => isset($row['post_title']) ? (string) $row['post_title'] : '',
                'row_type'           => isset($row['type']) ? (string) $row['type'] : '',
                'occurrence_index'   => isset($row['occurrence_index']) && $row['occurrence_index'] !== null ? (int) $row['occurrence_index'] : null,
                'is_internal'        => isset($row['is_internal']) ? ((int) $row['is_internal'] === 1) : false,
                'ignored_at'         => isset($row['ignored_at']) && $row['ignored_at'] !== null ? (string) $row['ignored_at'] : '',
                'last_checked_at'    => isset($row['last_checked_at']) && $row['last_checked_at'] !== null ? (string) $row['last_checked_at'] : '',
                'redirect_target_url'=> isset($row['redirect_target_url']) ? (string) $row['redirect_target_url'] : '',
                'context_excerpt'    => isset($row['context_excerpt']) ? (string) $row['context_excerpt'] : '',
            ];
        }

        return $rows;
    }
}

if (!function_exists('blc_write_report_export')) {
    /**
     * Serialize dataset rows to a CSV file.
     *
     * @param string               $dataset_type Dataset identifier.
     * @param array<int,array>     $rows         Rows returned by {@see blc_collect_report_rows()}.
     * @param array<string,string> $directory    Export directory information.
     * @param array<string,mixed>  $status       Latest scan status for the dataset.
     *
     * @return array<string,mixed>|\WP_Error
     */
    function blc_write_report_export($dataset_type, array $rows, array $directory, array $status) {
        $timestamp = time();
        $sanitized_dataset_type = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $dataset_type);
        if ($sanitized_dataset_type === '') {
            $sanitized_dataset_type = 'dataset';
        }
        $filename  = sprintf('blc-report-%s-%s.csv', $sanitized_dataset_type, gmdate('Ymd-His', $timestamp));
        $file_path = rtrim($directory['path'], '/\\') . '/' . $filename;

        $resource = fopen($file_path, 'wb');
        if ($resource === false) {
            return new \WP_Error(
                'blc_report_export_write_failed',
                sprintf(
                    /* translators: %s: file path. */
                    __('Unable to write the report export file (%s).', 'liens-morts-detector-jlg'),
                    $file_path
                )
            );
        }

        $headers = [
            'dataset',
            'url',
            'http_status',
            'post_id',
            'post_title',
            'row_type',
            'occurrence_index',
            'is_internal',
            'ignored_at',
            'last_checked_at',
            'redirect_target_url',
            'context_excerpt',
        ];

        $delimiter = ',';
        $enclosure = '"';
        $escape    = '\\';

        $write_csv_line = static function ($handle, array $fields) use ($delimiter, $enclosure, $escape) {
            return fputcsv($handle, $fields, $delimiter, $enclosure, $escape);
        };

        $escape_csv_field = static function ($value) {
            $value = (string) $value;

            if ($value === '') {
                return $value;
            }

            if (preg_match('/^[=+\-@]/', ltrim($value))) {
                return "'" . $value;
            }

            return $value;
        };

        if ($write_csv_line($resource, $headers) === false) {
            fclose($resource);
            @unlink($file_path);

            return new \WP_Error(
                'blc_report_export_write_failed',
                __('Unable to write the report export file (failed to write headers).', 'liens-morts-detector-jlg')
            );
        }

        foreach ($rows as $row) {
            $line = [
                $escape_csv_field($dataset_type),
                $escape_csv_field(isset($row['url']) ? (string) $row['url'] : ''),
                $escape_csv_field(isset($row['http_status']) && $row['http_status'] !== null ? (string) $row['http_status'] : ''),
                $escape_csv_field(isset($row['post_id']) ? (string) (int) $row['post_id'] : '0'),
                $escape_csv_field(isset($row['post_title']) ? (string) $row['post_title'] : ''),
                $escape_csv_field(isset($row['row_type']) ? (string) $row['row_type'] : ''),
                $escape_csv_field(isset($row['occurrence_index']) && $row['occurrence_index'] !== null ? (string) (int) $row['occurrence_index'] : ''),
                $escape_csv_field(!empty($row['is_internal']) ? '1' : '0'),
                $escape_csv_field(isset($row['ignored_at']) ? (string) $row['ignored_at'] : ''),
                $escape_csv_field(isset($row['last_checked_at']) ? (string) $row['last_checked_at'] : ''),
                $escape_csv_field(isset($row['redirect_target_url']) ? (string) $row['redirect_target_url'] : ''),
                $escape_csv_field(isset($row['context_excerpt']) ? (string) $row['context_excerpt'] : ''),
            ];

            if ($write_csv_line($resource, $line) === false) {
                fclose($resource);
                @unlink($file_path);

                return new \WP_Error(
                    'blc_report_export_write_failed',
                    __('Unable to write the report export file (failed to write a row).', 'liens-morts-detector-jlg')
                );
            }
        }

        fclose($resource);

        $relative_path = basename($file_path);
        $file_url = isset($directory['url']) && $directory['url'] !== ''
            ? rtrim($directory['url'], '/\\') . '/' . $relative_path
            : '';

        $metadata = [
            'dataset_type'        => $dataset_type,
            'file_path'           => $file_path,
            'relative_path'       => $relative_path,
            'file_url'            => $file_url,
            'row_count'           => count($rows),
            'generated_at'        => $timestamp,
            'source_state'        => isset($status['state']) ? (string) $status['state'] : '',
            'source_updated_at'   => isset($status['updated_at']) ? (int) $status['updated_at'] : 0,
            'source_ended_at'     => isset($status['ended_at']) ? (int) $status['ended_at'] : 0,
        ];

        if (function_exists('md5_file')) {
            $checksum = @md5_file($file_path);
            if ($checksum !== false) {
                $metadata['checksum_md5'] = $checksum;
            }
        }

        if (function_exists('filesize')) {
            $size = @filesize($file_path);
            if ($size !== false) {
                $metadata['filesize'] = (int) $size;
            }
        }

        return $metadata;
    }
}

if (!function_exists('blc_get_scan_status_for_report_dataset')) {
    /**
     * Retrieve the latest scan status for a dataset.
     *
     * @param string $dataset_type Dataset identifier.
     *
     * @return array<string,mixed>|null
     */
    function blc_get_scan_status_for_report_dataset($dataset_type) {
        if ($dataset_type === 'image') {
            if (function_exists('blc_get_image_scan_status')) {
                return blc_get_image_scan_status();
            }

            return null;
        }

        if (function_exists('blc_get_link_scan_status')) {
            return blc_get_link_scan_status();
        }

        return null;
    }
}

if (!function_exists('blc_get_previous_report_export')) {
    /**
     * Retrieve metadata of the last generated report for a dataset.
     *
     * @param string $dataset_type Dataset identifier.
     *
     * @return array<string,mixed>|null
     */
    function blc_get_previous_report_export($dataset_type) {
        $stored = get_option('blc_report_export_status', []);
        if (!is_array($stored)) {
            return null;
        }

        $dataset_type = (string) $dataset_type;

        return isset($stored[$dataset_type]) && is_array($stored[$dataset_type])
            ? $stored[$dataset_type]
            : null;
    }
}

if (!function_exists('blc_should_generate_report_export')) {
    /**
     * Determine whether a new export should be produced for the dataset.
     *
     * @param string                     $dataset_type Dataset identifier.
     * @param array<string,mixed>        $status       Latest scan status.
     * @param array<string,mixed>|null   $previous     Previously stored metadata.
     * @param bool                       $force        Whether to bypass freshness checks.
     *
     * @return bool
     */
    function blc_should_generate_report_export($dataset_type, array $status, ?array $previous, $force) {
        if ($force) {
            return true;
        }

        $state = isset($status['state']) ? (string) $status['state'] : '';
        if ($state === 'running' || $state === 'queued') {
            return false;
        }

        $ended_at   = isset($status['ended_at']) ? (int) $status['ended_at'] : 0;
        $updated_at = isset($status['updated_at']) ? (int) $status['updated_at'] : 0;
        $reference  = $ended_at > 0 ? $ended_at : $updated_at;

        if ($previous === null) {
            return true;
        }

        $previous_reference = 0;
        if (isset($previous['source_ended_at'])) {
            $previous_reference = (int) $previous['source_ended_at'];
        }
        if (isset($previous['source_updated_at'])) {
            $previous_reference = max($previous_reference, (int) $previous['source_updated_at']);
        }

        if ($reference === 0) {
            return $previous_reference === 0;
        }

        return $reference > $previous_reference;
    }
}

if (!function_exists('blc_record_report_export_result')) {
    /**
     * Persist metadata about the latest export attempt.
     *
     * @param string                    $dataset_type Dataset identifier.
     * @param array<string,mixed>       $status       Latest scan status.
     * @param array<string,mixed>|null  $metadata     Export metadata on success.
     * @param bool                      $skipped      Whether the export was skipped.
     *
     * @return void
     */
    function blc_record_report_export_result($dataset_type, array $status, ?array $metadata, $skipped) {
        $dataset_type = (string) $dataset_type;
        $stored = get_option('blc_report_export_status', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $entry = isset($stored[$dataset_type]) && is_array($stored[$dataset_type])
            ? $stored[$dataset_type]
            : [];

        $entry['last_attempted_at'] = time();
        $entry['source_state']      = isset($status['state']) ? (string) $status['state'] : '';
        $entry['source_updated_at'] = isset($status['updated_at']) ? (int) $status['updated_at'] : 0;
        $entry['source_ended_at']   = isset($status['ended_at']) ? (int) $status['ended_at'] : 0;
        $entry['skipped']           = (bool) $skipped;

        if (!$skipped && is_array($metadata)) {
            $entry = array_merge($entry, $metadata);
            $entry['skipped'] = false;
        }

        $stored[$dataset_type] = $entry;

        update_option('blc_report_export_status', $stored, false);
    }
}

if (!function_exists('blc_log_report_export_error')) {
    /**
     * Log an export error using WordPress' error_log when available.
     *
     * @param \WP_Error $error Error instance.
     *
     * @return void
     */
    function blc_log_report_export_error($error) {
        if (!($error instanceof \WP_Error)) {
            return;
        }

        $message = $error->get_error_message();
        if ($message === '') {
            $message = sprintf('BLC report export error: %s', $error->get_error_code());
        }

        if (function_exists('error_log')) {
            error_log('BLC: ' . $message);
        }
    }
}
