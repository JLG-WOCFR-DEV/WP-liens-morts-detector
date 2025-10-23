<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_normalize_report_dataset_type')) {
    /**
     * Normalize the dataset type identifier supported by automated reports.
     *
     * @param string $dataset_type Raw dataset type.
     *
     * @return string|null Normalized dataset type or null when unsupported.
     */
    function blc_normalize_report_dataset_type($dataset_type)
    {
        $candidate = is_string($dataset_type) ? strtolower(trim($dataset_type)) : '';

        if ($candidate === '') {
            return null;
        }

        if ($candidate === 'link' || $candidate === 'links') {
            return 'link';
        }

        if ($candidate === 'image' || $candidate === 'images') {
            return 'image';
        }

        return null;
    }
}

if (!function_exists('blc_escape_report_csv_field')) {
    /**
     * Escape a CSV field to avoid formula injection when opened in spreadsheet tools.
     *
     * @param mixed $value Raw field value.
     *
     * @return string
     */
    function blc_escape_report_csv_field($value)
    {
        $value = (string) $value;

        if ($value === '') {
            return $value;
        }

        $trimmed = ltrim($value);
        if ($trimmed === '') {
            return $value;
        }

        if (preg_match('/^[=+\-@]/', $trimmed) === 1) {
            return "'" . $value;
        }

        return $value;
    }
}

if (!function_exists('blc_write_csv_row')) {
    /**
     * Wrapper around fputcsv to ease testing and error handling.
     *
     * @param resource $handle CSV resource handle.
     * @param array<int, string> $fields CSV fields to write.
     *
     * @return int|false
     */
    function blc_write_csv_row($handle, array $fields)
    {
        return fputcsv($handle, $fields, ',', '"', '\\');
    }
}

if (!function_exists('blc_normalize_report_context')) {
    /**
     * Sanitize and normalize the metadata associated with a report generation.
     *
     * @param string               $dataset_type Dataset type.
     * @param array<string, mixed> $context      Context payload.
     * @param array<string, mixed> $options      Normalization options.
     *
     * @return array<string, mixed>
     */
    function blc_normalize_report_context($dataset_type, array $context, array $options = [])
    {
        $options = array_merge([
            'stabilize_completed_at' => false,
        ], $options);

        $now = time();

        $defaults = [
            'job_id'          => '',
            'started_at'      => 0,
            'ended_at'        => 0,
            'completed_at'    => 0,
            'include_ignored' => false,
            'format'          => 'csv',
            'source'          => 'scan',
        ];

        $normalized = array_merge($defaults, $context);

        $job_id = isset($normalized['job_id']) ? (string) $normalized['job_id'] : '';
        $normalized['job_id'] = $job_id !== '' ? trim($job_id) : '';

        foreach (['started_at', 'ended_at', 'completed_at'] as $key) {
            $normalized[$key] = isset($normalized[$key]) ? max(0, (int) $normalized[$key]) : 0;
        }

        if ($normalized['completed_at'] === 0) {
            if (!empty($options['stabilize_completed_at'])) {
                $normalized['completed_at'] = $normalized['ended_at'] > 0 ? $normalized['ended_at'] : 0;
            } else {
                $normalized['completed_at'] = $normalized['ended_at'] > 0 ? $normalized['ended_at'] : $now;
            }
        }

        $normalized['include_ignored'] = !empty($normalized['include_ignored']);

        $format = isset($normalized['format']) ? strtolower((string) $normalized['format']) : 'csv';
        $normalized['format'] = $format !== '' ? $format : 'csv';

        $source = isset($normalized['source']) ? (string) $normalized['source'] : 'scan';
        $normalized['source'] = $source !== '' ? $source : 'scan';

        $normalized['dataset_type'] = $dataset_type;

        return $normalized;
    }
}

if (!function_exists('blc_schedule_automated_report_generation')) {
    /**
     * Schedule the asynchronous generation of an automated CSV report.
     *
     * @param string               $dataset_type Dataset type to export (link or image).
     * @param array<string, mixed> $context      Additional metadata (job_id, timestamps…).
     *
     * @return bool True on success, false otherwise.
     */
    function blc_schedule_automated_report_generation($dataset_type, array $context = [])
    {
        $normalized_type = blc_normalize_report_dataset_type($dataset_type);
        if ($normalized_type === null) {
            return false;
        }

        if (!function_exists('wp_schedule_single_event')) {
            return false;
        }

        $context = blc_normalize_report_context($normalized_type, $context, [
            'stabilize_completed_at' => true,
        ]);

        $delay = 30;
        if (function_exists('apply_filters')) {
            $maybe_delay = apply_filters('blc_automated_report_schedule_delay', $delay, $normalized_type, $context);
            if (is_numeric($maybe_delay)) {
                $delay = max(0, (int) $maybe_delay);
            }
        }

        $timestamp = time() + $delay;
        $args = [$normalized_type, $context];

        if (function_exists('wp_next_scheduled')) {
            $already = wp_next_scheduled('blc_generate_automated_report', $args);
            if ($already !== false) {
                return true;
            }
        }

        $scheduled = wp_schedule_single_event($timestamp, 'blc_generate_automated_report', $args);

        if (false === $scheduled) {
            if (function_exists('do_action')) {
                do_action('blc_automated_report_schedule_failed', $normalized_type, $context);
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('BLC: Failed to schedule automated report generation for dataset "%s".', $normalized_type));
            }

            return false;
        }

        if (function_exists('do_action')) {
            do_action('blc_automated_report_scheduled', $normalized_type, $timestamp, $context);
        }

        return true;
    }
}

if (!function_exists('blc_get_report_storage_directory')) {
    /**
     * Resolve the directory used to store generated reports.
     *
     * @param array<string, mixed>|null $upload_info Optional upload dir payload to reuse.
     *
     * @return array{path:string,url:string}|\WP_Error
     */
    function blc_get_report_storage_directory($upload_info = null)
    {
        if (!is_array($upload_info)) {
            $upload_info = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
        }

        if (!is_array($upload_info)) {
            return new \WP_Error('blc_report_missing_upload_dir', __('Unable to determine the upload directory for reports.', 'liens-morts-detector-jlg'));
        }

        if (!empty($upload_info['error'])) {
            return new \WP_Error('blc_report_upload_dir_error', (string) $upload_info['error']);
        }

        $base_dir = isset($upload_info['basedir']) ? (string) $upload_info['basedir'] : '';
        $base_url = isset($upload_info['baseurl']) ? (string) $upload_info['baseurl'] : '';

        if ($base_dir === '' || $base_url === '') {
            return new \WP_Error('blc_report_upload_dir_incomplete', __('Upload directory information is incomplete.', 'liens-morts-detector-jlg'));
        }

        $reports_dir = rtrim($base_dir, '/\\') . '/blc-reports';
        $reports_url = rtrim($base_url, '/\\') . '/blc-reports';

        if (!is_dir($reports_dir)) {
            if (function_exists('wp_mkdir_p')) {
                $created = wp_mkdir_p($reports_dir);
            } else {
                $created = @mkdir($reports_dir, 0755, true);
            }

            if (!$created && !is_dir($reports_dir)) {
                return new \WP_Error('blc_report_directory_creation_failed', __('Failed to create the report storage directory.', 'liens-morts-detector-jlg'));
            }
        }

        return [
            'path' => $reports_dir,
            'url'  => $reports_url,
        ];
    }
}

if (!function_exists('blc_get_report_columns')) {
    /**
     * Retrieve the column definitions for CSV exports.
     *
     * @param string               $dataset_type Dataset type being exported.
     * @param array<string, mixed> $context      Report context.
     *
     * @return array<int, array{key:string,label:string}>
     */
    function blc_get_report_columns($dataset_type, array $context)
    {
        $columns = [
            ['key' => 'dataset_type',       'label' => __('Jeu de données', 'liens-morts-detector-jlg')],
            ['key' => 'scan_job_id',        'label' => __('ID du job', 'liens-morts-detector-jlg')],
            ['key' => 'url',                'label' => __('URL', 'liens-morts-detector-jlg')],
            ['key' => 'http_status',        'label' => __('Statut HTTP', 'liens-morts-detector-jlg')],
            ['key' => 'is_internal',        'label' => __('Interne', 'liens-morts-detector-jlg')],
            ['key' => 'post_id',            'label' => __('ID contenu', 'liens-morts-detector-jlg')],
            ['key' => 'post_type',          'label' => __('Type de contenu', 'liens-morts-detector-jlg')],
            ['key' => 'post_title',         'label' => __('Titre du contenu', 'liens-morts-detector-jlg')],
            ['key' => 'anchor_text',        'label' => __('Texte du lien', 'liens-morts-detector-jlg')],
            ['key' => 'occurrence_index',   'label' => __('Occurrence', 'liens-morts-detector-jlg')],
            ['key' => 'last_checked_at_gmt','label' => __('Dernier contrôle (GMT)', 'liens-morts-detector-jlg')],
            ['key' => 'ignored_at_gmt',     'label' => __('Ignoré le (GMT)', 'liens-morts-detector-jlg')],
            ['key' => 'redirect_target',    'label' => __('Redirection suggérée', 'liens-morts-detector-jlg')],
            ['key' => 'context_excerpt',    'label' => __('Contexte', 'liens-morts-detector-jlg')],
        ];

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('blc_automated_report_columns', $columns, $dataset_type, $context);
            if (is_array($filtered) && $filtered !== []) {
                $columns = [];
                foreach ($filtered as $column) {
                    if (!is_array($column)) {
                        continue;
                    }
                    if (!isset($column['key']) || !isset($column['label'])) {
                        continue;
                    }
                    $key = (string) $column['key'];
                    $label = (string) $column['label'];
                    if ($key === '' || $label === '') {
                        continue;
                    }
                    $columns[] = ['key' => $key, 'label' => $label];
                }
                if ($columns === []) {
                    $columns = [
                        ['key' => 'dataset_type', 'label' => __('Jeu de données', 'liens-morts-detector-jlg')],
                    ];
                }
            }
        }

        return $columns;
    }
}

if (!function_exists('blc_format_report_row')) {
    /**
     * Convert a database row into a CSV row aligned with the column configuration.
     *
     * @param array<string, mixed>        $row      Database row.
     * @param array<int, array{key:string}> $columns Column configuration.
     * @param string                      $dataset_type Dataset type.
     * @param array<string, mixed>        $context  Report context.
     *
     * @return array<int, string>
     */
    function blc_format_report_row(array $row, array $columns, $dataset_type, array $context)
    {
        $job_id = isset($context['job_id']) ? (string) $context['job_id'] : '';

        $anchor = isset($row['anchor']) ? (string) $row['anchor'] : '';
        if ($anchor !== '') {
            if (function_exists('wp_strip_all_tags')) {
                $anchor = wp_strip_all_tags($anchor, true);
            } else {
                $anchor = strip_tags($anchor);
            }
            $anchor = trim(preg_replace('/\s+/u', ' ', $anchor));
        }

        $context_excerpt = isset($row['context_excerpt']) ? (string) $row['context_excerpt'] : '';
        if ($context_excerpt === '' && isset($row['context_html'])) {
            $context_excerpt = (string) $row['context_html'];
        }
        if ($context_excerpt !== '') {
            if (function_exists('wp_strip_all_tags')) {
                $context_excerpt = wp_strip_all_tags($context_excerpt, true);
            } else {
                $context_excerpt = strip_tags($context_excerpt);
            }
            $context_excerpt = trim(preg_replace('/\s+/u', ' ', $context_excerpt));
        }

        $last_checked_at = isset($row['last_checked_at']) ? (string) $row['last_checked_at'] : '';
        $ignored_at      = isset($row['ignored_at']) ? (string) $row['ignored_at'] : '';

        $normalized = [
            'dataset_type'       => $dataset_type,
            'scan_job_id'        => $job_id,
            'url'                => isset($row['url']) ? (string) $row['url'] : '',
            'http_status'        => isset($row['http_status']) && $row['http_status'] !== null ? (string) (int) $row['http_status'] : '',
            'is_internal'        => isset($row['is_internal']) ? ((int) $row['is_internal'] === 1 ? 'yes' : 'no') : 'no',
            'post_id'            => isset($row['post_id']) ? (string) (int) $row['post_id'] : '',
            'post_type'          => isset($row['post_type']) ? (string) $row['post_type'] : '',
            'post_title'         => isset($row['post_title']) ? (string) $row['post_title'] : '',
            'anchor_text'        => $anchor,
            'occurrence_index'   => isset($row['occurrence_index']) ? (string) (int) $row['occurrence_index'] : '0',
            'last_checked_at_gmt'=> $last_checked_at,
            'ignored_at_gmt'     => $ignored_at,
            'redirect_target'    => isset($row['redirect_target_url']) ? (string) $row['redirect_target_url'] : '',
            'context_excerpt'    => $context_excerpt,
        ];

        $result = [];
        foreach ($columns as $column) {
            $key = $column['key'];
            $value = array_key_exists($key, $normalized) ? $normalized[$key] : '';
            $result[] = blc_escape_report_csv_field($value);
        }

        return $result;
    }
}

if (!function_exists('blc_query_report_rows')) {
    /**
     * Retrieve dataset rows that should be part of the exported report.
     *
     * @param string               $dataset_type Dataset type.
     * @param array<string, mixed> $context      Report context.
     *
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    function blc_query_report_rows($dataset_type, array $context)
    {
        $row_types = blc_get_dataset_row_types($dataset_type);
        if ($row_types === []) {
            return [];
        }

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix)) {
            return new \WP_Error('blc_report_missing_wpdb', __('Database access is required to generate the report.', 'liens-morts-detector-jlg'));
        }

        $table_name = $wpdb->prefix . 'blc_broken_links';
        $clauses = [];
        $params = [];

        if (count($row_types) === 1) {
            $clauses[] = 'blc.type = %s';
            $params[]  = $row_types[0];
        } else {
            $placeholders = implode(',', array_fill(0, count($row_types), '%s'));
            $clauses[] = "blc.type IN ($placeholders)";
            $params = array_merge($params, $row_types);
        }

        if (empty($context['include_ignored'])) {
            $clauses[] = '(blc.ignored_at IS NULL)';
        }

        $start = isset($context['started_at']) ? (int) $context['started_at'] : 0;
        $end   = isset($context['ended_at']) ? (int) $context['ended_at'] : 0;

        if ($start > 0) {
            $clauses[] = 'blc.last_checked_at >= %s';
            $params[]  = gmdate('Y-m-d H:i:s', $start);
        }

        if ($end > 0) {
            $clauses[] = 'blc.last_checked_at <= %s';
            $params[]  = gmdate('Y-m-d H:i:s', $end);
        }

        $where = $clauses !== [] ? implode(' AND ', $clauses) : '1=1';

        $sql = "SELECT blc.id, blc.url, blc.anchor, blc.context_excerpt, blc.context_html, blc.post_id, blc.post_title, blc.type, blc.occurrence_index, blc.url_host, blc.is_internal, blc.http_status, blc.last_checked_at, blc.ignored_at, blc.redirect_target_url, posts.post_type
                FROM {$table_name} AS blc
                LEFT JOIN {$wpdb->posts} AS posts ON posts.ID = blc.post_id
                WHERE $where
                ORDER BY blc.last_checked_at DESC, blc.id DESC";

        $prepared = $sql;
        if (method_exists($wpdb, 'prepare')) {
            $prepared = $wpdb->prepare($sql, $params);
        }

        if (!is_string($prepared) || $prepared === '') {
            return new \WP_Error('blc_report_prepare_failed', __('Failed to prepare the report query.', 'liens-morts-detector-jlg'));
        }

        $results = [];
        if (method_exists($wpdb, 'get_results')) {
            $results = $wpdb->get_results($prepared, ARRAY_A);
        }

        if (!is_array($results)) {
            return [];
        }

        return $results;
    }
}

if (!function_exists('blc_store_generated_report_reference')) {
    /**
     * Persist metadata about the last generated reports and prune old entries.
     *
     * @param string               $dataset_type Dataset type.
     * @param array<string, mixed> $record       Metadata to store.
     *
     * @return void
     */
    function blc_store_generated_report_reference($dataset_type, array $record)
    {
        $option_name = 'blc_automated_report_index';
        $index = get_option($option_name, []);
        if (!is_array($index)) {
            $index = [];
        }

        if (!isset($index[$dataset_type]) || !is_array($index[$dataset_type])) {
            $index[$dataset_type] = [];
        }

        array_unshift($index[$dataset_type], $record);

        $max_entries = 10;
        if (function_exists('apply_filters')) {
            $maybe_max = apply_filters('blc_automated_report_history_size', $max_entries, $dataset_type);
            if (is_numeric($maybe_max)) {
                $max_entries = max(1, (int) $maybe_max);
            }
        }

        while (count($index[$dataset_type]) > $max_entries) {
            $removed = array_pop($index[$dataset_type]);
            if (is_array($removed) && isset($removed['file_path']) && is_string($removed['file_path'])) {
                $file_path = $removed['file_path'];
                if (is_file($file_path)) {
                    if (function_exists('wp_delete_file')) {
                        wp_delete_file($file_path);
                    } else {
                        @unlink($file_path);
                    }
                }
            }
        }

        update_option($option_name, $index, false);
    }
}

if (!function_exists('blc_generate_automated_report_csv')) {
    /**
     * Generate the CSV report for the provided dataset.
     *
     * @param string               $dataset_type Dataset type.
     * @param array<string, mixed> $context      Report context metadata.
     *
     * @return array<string, mixed>|\WP_Error
     */
    function blc_generate_automated_report_csv($dataset_type, array $context)
    {
        $normalized_type = blc_normalize_report_dataset_type($dataset_type);
        if ($normalized_type === null) {
            return new \WP_Error('blc_report_unknown_dataset', __('Unsupported dataset type for report generation.', 'liens-morts-detector-jlg'));
        }

        $context = blc_normalize_report_context($normalized_type, $context);

        $upload_info = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
        $storage = blc_get_report_storage_directory($upload_info);
        if (blc_is_wp_error($storage)) {
            return $storage;
        }

        $rows = blc_query_report_rows($normalized_type, $context);
        if (blc_is_wp_error($rows)) {
            return $rows;
        }

        $columns = blc_get_report_columns($normalized_type, $context);

        $timestamp = $context['completed_at'] ?? time();
        $timestamp = max(0, (int) $timestamp);
        if ($timestamp === 0) {
            $timestamp = time();
        }

        $site_fragment = '';
        if (is_array($upload_info) && isset($upload_info['baseurl'])) {
            $baseurl = (string) $upload_info['baseurl'];
            $host = '';
            if ($baseurl !== '') {
                if (function_exists('wp_parse_url')) {
                    $host = wp_parse_url($baseurl, PHP_URL_HOST);
                }
                if (!is_string($host) || $host === '') {
                    $host = parse_url($baseurl, PHP_URL_HOST);
                }
            }
            if (!is_string($host) || $host === '') {
                $host = 'site';
            }
            $site_fragment = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($host));
            $site_fragment = trim($site_fragment, '-');
        }
        if ($site_fragment === '') {
            $site_fragment = 'site';
        }

        $filename = sprintf(
            'blc-report-%s-%s-%s.csv',
            $normalized_type,
            $site_fragment,
            gmdate('Ymd-His', $timestamp)
        );

        $file_path = rtrim($storage['path'], '/\\') . '/' . $filename;
        $unique_counter = 1;
        while (file_exists($file_path)) {
            $file_path = rtrim($storage['path'], '/\\') . '/' . sprintf(
                'blc-report-%s-%s-%s-%d.csv',
                $normalized_type,
                $site_fragment,
                gmdate('Ymd-His', $timestamp),
                ++$unique_counter
            );
        }

        $handle = fopen($file_path, 'w');
        if (!$handle) {
            return new \WP_Error('blc_report_file_open_failed', __('Unable to open the report file for writing.', 'liens-morts-detector-jlg'));
        }

        $close_handle = static function () use (&$handle) {
            if (is_resource($handle)) {
                fclose($handle);
                $handle = null;
            }
        };

        $delete_file = static function ($path) {
            if (is_file($path)) {
                if (function_exists('wp_delete_file')) {
                    wp_delete_file($path);
                } else {
                    @unlink($path);
                }
            }
        };

        // Ensure Excel-friendly UTF-8 BOM.
        fwrite($handle, "\xEF\xBB\xBF");

        $header = [];
        foreach ($columns as $column) {
            $header[] = $column['label'];
        }

        if (false === blc_write_csv_row($handle, $header)) {
            $close_handle();
            $delete_file($file_path);

            return new \WP_Error('blc_report_file_write_failed', __('Unable to write the automated report file (failed to write headers).', 'liens-morts-detector-jlg'));
        }

        $row_count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $formatted = blc_format_report_row($row, $columns, $normalized_type, $context);

            if (false === blc_write_csv_row($handle, $formatted)) {
                $close_handle();
                $delete_file($file_path);

                return new \WP_Error('blc_report_file_write_failed', __('Unable to write the automated report file (failed to write a row).', 'liens-morts-detector-jlg'));
            }

            $row_count++;
        }

        $close_handle();

        $file_url = rtrim($storage['url'], '/\\') . '/' . basename($file_path);
        $bytes = filesize($file_path);

        $record = [
            'dataset_type' => $normalized_type,
            'file_path'    => $file_path,
            'file_url'     => $file_url,
            'file_name'    => basename($file_path),
            'generated_at' => time(),
            'row_count'    => $row_count,
            'bytes'        => is_int($bytes) ? $bytes : 0,
            'context'      => $context,
        ];

        blc_store_generated_report_reference($normalized_type, $record);

        if (function_exists('do_action')) {
            do_action('blc_automated_report_generated', $normalized_type, $record, $context);
        }

        return $record;
    }
}

if (!function_exists('blc_handle_generate_automated_report')) {
    /**
     * WP-Cron callback invoked to generate the automated report.
     *
     * @param string               $dataset_type Dataset type requested.
     * @param array<string, mixed> $context      Report context metadata.
     *
     * @return void
     */
    function blc_handle_generate_automated_report($dataset_type, $context = [])
    {
        $result = blc_generate_automated_report_csv($dataset_type, is_array($context) ? $context : []);
        if (blc_is_wp_error($result) && function_exists('do_action')) {
            do_action('blc_automated_report_generation_failed', $dataset_type, $context, $result);
        }
    }
}

add_action('blc_generate_automated_report', 'blc_handle_generate_automated_report', 10, 2);
