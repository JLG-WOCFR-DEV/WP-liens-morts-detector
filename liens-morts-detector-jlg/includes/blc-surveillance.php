<?php
/**
 * Surveillance thresholds and proactive alert helpers.
 *
 * @package LiensMortsDetector
 */

// Prevent direct access when loaded outside of WordPress.
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_get_surveillance_threshold_defaults')) {
    /**
     * Return the default set of surveillance thresholds.
     *
     * @return array{global:array<int,array<string,mixed>>,taxonomy:array<int,array<string,mixed>>}
     */
    function blc_get_surveillance_threshold_defaults() {
        $defaults = array(
            'global'   => array(
                array(
                    'id'          => 'global_ratio_default',
                    'label'       => __('Ratio de liens cassés critique', 'liens-morts-detector-jlg'),
                    'metric'      => 'broken_ratio',
                    'comparison'  => 'gte',
                    'threshold'   => 5.0,
                    'severity'    => 'warning',
                ),
                array(
                    'id'          => 'global_volume_default',
                    'label'       => __('Volume total de liens cassés', 'liens-morts-detector-jlg'),
                    'metric'      => 'broken_count',
                    'comparison'  => 'gte',
                    'threshold'   => 50.0,
                    'severity'    => 'critical',
                ),
            ),
            'taxonomy' => array(),
        );

        return blc_normalize_surveillance_thresholds($defaults);
    }
}

if (!function_exists('blc_save_surveillance_thresholds')) {
    /**
     * Persist the provided surveillance thresholds configuration.
     *
     * @param array<string,mixed> $thresholds Raw threshold payload.
     *
     * @return void
     */
    function blc_save_surveillance_thresholds(array $thresholds) {
        if (!function_exists('update_option')) {
            return;
        }

        $normalized = blc_normalize_surveillance_thresholds($thresholds);
        update_option('blc_surveillance_thresholds', $normalized, false);
    }
}

if (!function_exists('blc_get_surveillance_threshold_definitions')) {
    /**
     * Retrieve the active set of surveillance thresholds.
     *
     * @return array{global:array<int,array<string,mixed>>,taxonomy:array<int,array<string,mixed>>}
     */
    function blc_get_surveillance_threshold_definitions() {
        $defaults = blc_get_surveillance_threshold_defaults();
        $stored   = array();

        if (function_exists('get_option')) {
            $option_value = get_option('blc_surveillance_thresholds', array());
            if (is_array($option_value)) {
                $stored = $option_value;
            }
        }

        $stored = blc_normalize_surveillance_thresholds($stored);

        $definitions = array(
            'global'   => blc_merge_surveillance_threshold_groups($defaults['global'], $stored['global']),
            'taxonomy' => blc_merge_surveillance_threshold_groups($defaults['taxonomy'], $stored['taxonomy']),
        );

        if (function_exists('apply_filters')) {
            /**
             * Allow plugins to customize the surveillance thresholds before evaluation.
             *
             * @param array{global:array<int,array<string,mixed>>,taxonomy:array<int,array<string,mixed>>} $definitions Threshold definitions.
             */
            $definitions = apply_filters('blc_surveillance_threshold_definitions', $definitions);
        }

        return blc_normalize_surveillance_thresholds($definitions);
    }
}

if (!function_exists('blc_merge_surveillance_threshold_groups')) {
    /**
     * Merge default and override threshold groups using their identifiers.
     *
     * @param array<int,array<string,mixed>> $defaults
     * @param array<int,array<string,mixed>> $overrides
     *
     * @return array<int,array<string,mixed>>
     */
    function blc_merge_surveillance_threshold_groups(array $defaults, array $overrides) {
        $merged = array();

        foreach ($defaults as $definition) {
            if (!isset($definition['id'])) {
                continue;
            }

            $merged[$definition['id']] = $definition;
        }

        foreach ($overrides as $definition) {
            if (!isset($definition['id'])) {
                continue;
            }

            if (isset($merged[$definition['id']])) {
                $merged[$definition['id']] = array_merge($merged[$definition['id']], $definition);
            } else {
                $merged[$definition['id']] = $definition;
            }
        }

        return array_values($merged);
    }
}

if (!function_exists('blc_normalize_surveillance_thresholds')) {
    /**
     * Normalize a raw threshold payload into a predictable structure.
     *
     * @param mixed $raw Threshold definitions.
     *
     * @return array{global:array<int,array<string,mixed>>,taxonomy:array<int,array<string,mixed>>}
     */
    function blc_normalize_surveillance_thresholds($raw) {
        $normalized = array(
            'global'   => array(),
            'taxonomy' => array(),
        );

        if (!is_array($raw)) {
            return $normalized;
        }

        if (isset($raw['global']) && is_array($raw['global'])) {
            $normalized['global'] = blc_normalize_surveillance_threshold_group($raw['global'], 'global');
        }

        if (isset($raw['taxonomy']) && is_array($raw['taxonomy'])) {
            $normalized['taxonomy'] = blc_normalize_surveillance_threshold_group($raw['taxonomy'], 'taxonomy');
        }

        return $normalized;
    }
}

if (!function_exists('blc_normalize_surveillance_threshold_group')) {
    /**
     * Normalize a group of thresholds for the provided scope.
     *
     * @param array<int,mixed> $definitions Raw group definitions.
     * @param string           $scope       Either "global" or "taxonomy".
     *
     * @return array<int,array<string,mixed>>
     */
    function blc_normalize_surveillance_threshold_group(array $definitions, $scope) {
        $scope = ($scope === 'taxonomy') ? 'taxonomy' : 'global';
        $normalized = array();

        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if ($scope === 'global') {
                $metric = isset($definition['metric']) ? (string) $definition['metric'] : 'broken_ratio';
                if (!in_array($metric, array('broken_ratio', 'broken_count'), true)) {
                    $metric = 'broken_ratio';
                }

                $id = isset($definition['id']) ? (string) $definition['id'] : '';
                if ($id === '') {
                    $id = 'global_' . $metric;
                }

                $comparison = isset($definition['comparison']) ? (string) $definition['comparison'] : 'gte';
                $comparison = blc_normalize_surveillance_comparison($comparison);

                $threshold = isset($definition['threshold']) ? (float) $definition['threshold'] : 0.0;
                $label     = isset($definition['label']) ? (string) $definition['label'] : '';
                $severity  = isset($definition['severity']) ? (string) $definition['severity'] : 'warning';
                if ($severity === '') {
                    $severity = 'warning';
                }

                $normalized[] = array(
                    'id'         => blc_surveillance_sanitize_id($id, 'global_' . $metric),
                    'scope'      => 'global',
                    'metric'     => $metric,
                    'comparison' => $comparison,
                    'threshold'  => $threshold,
                    'label'      => $label,
                    'severity'   => $severity,
                );

                continue;
            }

            $taxonomy = isset($definition['taxonomy']) ? (string) $definition['taxonomy'] : '';
            $taxonomy = blc_surveillance_sanitize_key($taxonomy);
            if ($taxonomy === '') {
                continue;
            }

            $threshold = isset($definition['threshold']) ? (float) $definition['threshold'] : 0.0;
            $comparison = isset($definition['comparison']) ? (string) $definition['comparison'] : 'gte';
            $comparison = blc_normalize_surveillance_comparison($comparison);
            $label = isset($definition['label']) ? (string) $definition['label'] : '';
            $severity = isset($definition['severity']) ? (string) $definition['severity'] : 'warning';
            if ($severity === '') {
                $severity = 'warning';
            }

            $term_ids = array();
            if (isset($definition['term_ids'])) {
                $raw_term_ids = $definition['term_ids'];

                if (!is_array($raw_term_ids)) {
                    $raw_term_ids = preg_split('/[\s,]+/', (string) $raw_term_ids);
                }

                foreach ($raw_term_ids as $term_id) {
                    if ($term_id === null || $term_id === '') {
                        continue;
                    }

                    $term_ids[] = (int) $term_id;
                }
            }

            $term_id = isset($definition['term_id']) ? (int) $definition['term_id'] : 0;
            if ($term_id > 0 && $term_ids === array()) {
                $term_ids[] = $term_id;
            }

            $term_ids = array_values(array_unique(array_filter($term_ids, static function ($value) {
                return (int) $value > 0;
            })));

            $apply_to_all = ($term_ids === array());

            $id = isset($definition['id']) ? (string) $definition['id'] : '';
            if ($id === '') {
                $id_suffix = $apply_to_all ? 'all' : implode('_', $term_ids);
                $id        = sprintf('taxonomy_%s_%s', $taxonomy, $id_suffix);
            }

            $normalized[] = array(
                'id'                => blc_surveillance_sanitize_id($id, 'taxonomy_' . $taxonomy),
                'scope'             => 'taxonomy',
                'taxonomy'          => $taxonomy,
                'metric'            => 'count',
                'comparison'        => $comparison,
                'threshold'         => $threshold,
                'label'             => $label,
                'severity'          => $severity,
                'term_ids'          => $term_ids,
                'apply_to_all_terms'=> $apply_to_all,
            );
        }

        return $normalized;
    }
}

if (!function_exists('blc_normalize_surveillance_comparison')) {
    /**
     * Normalize the comparison operator used for a threshold.
     *
     * @param string $comparison Raw comparison operator.
     *
     * @return string
     */
    function blc_normalize_surveillance_comparison($comparison) {
        $comparison = strtolower(trim((string) $comparison));
        $allowed    = array('gte', 'gt', 'lte', 'lt', 'eq', 'neq');

        if (!in_array($comparison, $allowed, true)) {
            return 'gte';
        }

        return $comparison;
    }
}

if (!function_exists('blc_surveillance_sanitize_id')) {
    /**
     * Sanitize a threshold identifier.
     *
     * @param string $id       Proposed identifier.
     * @param string $fallback Fallback identifier when the provided one is empty.
     *
     * @return string
     */
    function blc_surveillance_sanitize_id($id, $fallback) {
        $id = is_scalar($id) ? (string) $id : '';
        $id = trim($id);

        if ($id === '') {
            $id = (string) $fallback;
        }

        $sanitized = preg_replace('/[^A-Za-z0-9:_-]/', '', $id);
        if ($sanitized === null || $sanitized === '') {
            $sanitized = preg_replace('/[^A-Za-z0-9:_-]/', '', (string) $fallback);
        }

        if ($sanitized === null || $sanitized === '') {
            $random = function_exists('wp_rand') ? wp_rand() : mt_rand();

            return 'threshold_' . $random;
        }

        return strtolower($sanitized);
    }
}

if (!function_exists('blc_surveillance_sanitize_key')) {
    /**
     * Sanitize a taxonomy slug or similar identifier.
     *
     * @param string $value Raw value.
     *
     * @return string
     */
    function blc_surveillance_sanitize_key($value) {
        $value = is_scalar($value) ? (string) $value : '';
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (function_exists('sanitize_key')) {
            return sanitize_key($value);
        }

        return strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $value));
    }
}

if (!function_exists('blc_evaluate_surveillance_thresholds')) {
    /**
     * Evaluate the configured thresholds for a dataset and return triggered alerts.
     *
     * @param string               $dataset_type Dataset identifier (currently only "link").
     * @param array<string,mixed>  $context      Optional context overrides (definitions, metrics).
     *
     * @return array{alerts:array<int,array<string,mixed>>,metrics:array<string,mixed>,definitions:array<string,mixed>}
     */
    function blc_evaluate_surveillance_thresholds($dataset_type, array $context = array()) {
        $dataset_type = (string) $dataset_type;

        $empty_response = array(
            'alerts'      => array(),
            'metrics'     => array(
                'global'   => array(
                    'broken_ratio' => 0.0,
                    'broken_count' => 0,
                    'total_tracked'=> 0,
                ),
                'taxonomy' => array(),
            ),
            'definitions' => array(
                'global'   => array(),
                'taxonomy' => array(),
            ),
        );

        if ($dataset_type !== 'link') {
            return $empty_response;
        }

        $definitions = isset($context['definitions']) && is_array($context['definitions'])
            ? blc_normalize_surveillance_thresholds($context['definitions'])
            : blc_get_surveillance_threshold_definitions();

        $metrics = blc_collect_link_surveillance_metrics($definitions, $context);

        $alerts = array();

        $taxonomy_labels = array();
        $watched_taxonomies = blc_surveillance_collect_taxonomies($definitions);
        if ($watched_taxonomies !== array()) {
            $taxonomy_labels = blc_get_taxonomy_labels($watched_taxonomies);
        }

        foreach ($definitions['global'] as $definition) {
            if (!isset($definition['metric'])) {
                continue;
            }

            $metric = (string) $definition['metric'];
            $value  = isset($metrics['global'][$metric]) ? $metrics['global'][$metric] : null;

            if ($metric === 'broken_ratio') {
                $value = ($value !== null) ? (float) $value : null;
            } else {
                $value = ($value !== null) ? (int) $value : null;
            }

            if ($value === null) {
                continue;
            }

            $threshold  = isset($definition['threshold']) ? (float) $definition['threshold'] : 0.0;
            $comparison = isset($definition['comparison']) ? (string) $definition['comparison'] : 'gte';
            $comparison = blc_normalize_surveillance_comparison($comparison);

            if (!blc_surveillance_threshold_compare($value, $comparison, $threshold)) {
                continue;
            }

            $alert = array(
                'id'         => isset($definition['id']) ? (string) $definition['id'] : 'global_' . $metric,
                'scope'      => 'global',
                'metric'     => $metric,
                'value'      => $value,
                'threshold'  => $threshold,
                'comparison' => $comparison,
                'severity'   => isset($definition['severity']) ? (string) $definition['severity'] : 'warning',
                'label'      => isset($definition['label']) ? (string) $definition['label'] : '',
                'value_type' => ($metric === 'broken_ratio') ? 'ratio' : 'count',
            );

            $alerts[] = $alert;
        }

        if (!empty($definitions['taxonomy'])) {
            $taxonomy_metrics = isset($metrics['taxonomy']) && is_array($metrics['taxonomy'])
                ? $metrics['taxonomy']
                : array();

            foreach ($definitions['taxonomy'] as $definition) {
                if (!isset($definition['taxonomy'])) {
                    continue;
                }

                $taxonomy = (string) $definition['taxonomy'];
                if ($taxonomy === '' || !isset($taxonomy_metrics[$taxonomy])) {
                    continue;
                }

                $terms = $taxonomy_metrics[$taxonomy];
                if (!is_array($terms) || $terms === array()) {
                    continue;
                }

                $term_ids = array();
                if (!empty($definition['term_ids']) && is_array($definition['term_ids'])) {
                    foreach ($definition['term_ids'] as $term_id) {
                        $term_ids[] = (int) $term_id;
                    }
                }

                if ($definition['apply_to_all_terms']) {
                    $term_ids = array_keys($terms);
                }

                $term_ids = array_values(array_unique(array_filter($term_ids, static function ($value) {
                    return (int) $value > 0;
                })));

                if ($term_ids === array()) {
                    continue;
                }

                $threshold  = isset($definition['threshold']) ? (float) $definition['threshold'] : 0.0;
                $comparison = isset($definition['comparison']) ? (string) $definition['comparison'] : 'gte';
                $comparison = blc_normalize_surveillance_comparison($comparison);

                foreach ($term_ids as $term_id) {
                    if (!isset($terms[$term_id])) {
                        continue;
                    }

                    $term_payload = $terms[$term_id];
                    $count        = isset($term_payload['count']) ? (int) $term_payload['count'] : 0;

                    if (!blc_surveillance_threshold_compare($count, $comparison, $threshold)) {
                        continue;
                    }

                    $alerts[] = array(
                        'id'             => isset($definition['id']) ? (string) $definition['id'] : sprintf('taxonomy_%s_%d', $taxonomy, $term_id),
                        'scope'          => 'taxonomy',
                        'metric'         => 'count',
                        'taxonomy'       => $taxonomy,
                        'taxonomy_label' => isset($taxonomy_labels[$taxonomy]) ? $taxonomy_labels[$taxonomy] : $taxonomy,
                        'term_id'        => $term_id,
                        'term_name'      => isset($term_payload['name']) ? (string) $term_payload['name'] : '',
                        'term_slug'      => isset($term_payload['slug']) ? (string) $term_payload['slug'] : '',
                        'value'          => $count,
                        'threshold'      => $threshold,
                        'comparison'     => $comparison,
                        'severity'       => isset($definition['severity']) ? (string) $definition['severity'] : 'warning',
                        'label'          => isset($definition['label']) ? (string) $definition['label'] : '',
                        'value_type'     => 'count',
                    );
                }
            }
        }

        if (function_exists('apply_filters')) {
            /**
             * Allow customization of the generated alerts before they are returned.
             *
             * @param array<int,array<string,mixed>> $alerts      Triggered alerts.
             * @param array<string,mixed>            $metrics     Evaluated metrics.
             * @param array<string,mixed>            $definitions Active threshold definitions.
             * @param string                         $dataset_type Dataset identifier.
             * @param array<string,mixed>            $context     Evaluation context.
             */
            $filtered_alerts = apply_filters('blc_surveillance_threshold_alerts', $alerts, $metrics, $definitions, $dataset_type, $context);
            if (is_array($filtered_alerts)) {
                $alerts = $filtered_alerts;
            }
        }

        if ($alerts !== array() && function_exists('do_action')) {
            /**
             * Fires when one or more surveillance thresholds are triggered.
             *
             * @param array<int,array<string,mixed>> $alerts      Triggered alerts.
             * @param array<string,mixed>            $metrics     Evaluated metrics.
             * @param array<string,mixed>            $definitions Active threshold definitions.
             * @param string                         $dataset_type Dataset identifier.
             * @param array<string,mixed>            $context     Evaluation context.
             */
            do_action('blc_surveillance_thresholds_triggered', $alerts, $metrics, $definitions, $dataset_type, $context);
        }

        return array(
            'alerts'      => $alerts,
            'metrics'     => $metrics,
            'definitions' => $definitions,
        );
    }
}

if (!function_exists('blc_collect_link_surveillance_metrics')) {
    /**
     * Gather the metrics required to evaluate link surveillance thresholds.
     *
     * @param array<string,mixed> $definitions Threshold definitions.
     * @param array<string,mixed> $context     Evaluation context.
     *
     * @return array<string,mixed>
     */
    function blc_collect_link_surveillance_metrics(array $definitions, array $context = array()) {
        if (isset($context['metrics']) && is_array($context['metrics'])) {
            $metrics = $context['metrics'];

            if (!isset($metrics['global']) || !is_array($metrics['global'])) {
                $metrics['global'] = array();
            }

            $metrics['global'] = array_merge(
                array(
                    'broken_ratio' => 0.0,
                    'broken_count' => 0,
                    'total_tracked'=> 0,
                ),
                array(
                    'broken_ratio' => isset($metrics['global']['broken_ratio']) ? (float) $metrics['global']['broken_ratio'] : 0.0,
                    'broken_count' => isset($metrics['global']['broken_count']) ? (int) $metrics['global']['broken_count'] : 0,
                    'total_tracked'=> isset($metrics['global']['total_tracked']) ? (int) $metrics['global']['total_tracked'] : 0,
                )
            );

            if (!isset($metrics['taxonomy']) || !is_array($metrics['taxonomy'])) {
                $metrics['taxonomy'] = array();
            }

            return $metrics;
        }

        $metrics = array(
            'global' => array(
                'broken_ratio' => 0.0,
                'broken_count' => 0,
                'total_tracked'=> 0,
            ),
            'taxonomy' => array(),
        );

        if (!function_exists('blc_get_dataset_row_types')) {
            return $metrics;
        }

        $row_types = blc_get_dataset_row_types('link');
        if (!is_array($row_types) || $row_types === array()) {
            return $metrics;
        }

        $counters = blc_get_link_surveillance_counters($row_types);
        $broken_count = isset($counters['active_count']) ? max(0, (int) $counters['active_count']) : 0;
        $total_tracked = isset($counters['total_count']) ? max(0, (int) $counters['total_count']) : 0;

        $metrics['global']['broken_count']  = $broken_count;
        $metrics['global']['total_tracked'] = $total_tracked;
        $metrics['global']['broken_ratio']  = ($total_tracked > 0)
            ? ($broken_count / $total_tracked) * 100
            : 0.0;

        $taxonomies = blc_surveillance_collect_taxonomies($definitions);
        if ($taxonomies !== array()) {
            $metrics['taxonomy'] = blc_get_broken_link_taxonomy_counts($taxonomies, $row_types);
        }

        if (function_exists('apply_filters')) {
            /**
             * Allow customization of the metrics used for surveillance threshold evaluation.
             *
             * @param array<string,mixed>            $metrics     Base metrics.
             * @param array<string,mixed>            $definitions Active definitions.
             * @param array<string,mixed>            $context     Evaluation context.
             */
            $filtered = apply_filters('blc_surveillance_metrics', $metrics, $definitions, $context);
            if (is_array($filtered)) {
                $metrics = $filtered;
            }
        }

        if (!isset($metrics['global']) || !is_array($metrics['global'])) {
            $metrics['global'] = array();
        }

        $metrics['global'] = array_merge(
            array(
                'broken_ratio' => 0.0,
                'broken_count' => 0,
                'total_tracked'=> 0,
            ),
            array(
                'broken_ratio' => isset($metrics['global']['broken_ratio']) ? (float) $metrics['global']['broken_ratio'] : 0.0,
                'broken_count' => isset($metrics['global']['broken_count']) ? (int) $metrics['global']['broken_count'] : 0,
                'total_tracked'=> isset($metrics['global']['total_tracked']) ? (int) $metrics['global']['total_tracked'] : 0,
            )
        );

        if (!isset($metrics['taxonomy']) || !is_array($metrics['taxonomy'])) {
            $metrics['taxonomy'] = array();
        }

        return $metrics;
    }
}

if (!function_exists('blc_get_link_surveillance_counters')) {
    /**
     * Retrieve the total and active broken link counts for the provided row types.
     *
     * @param array<int,string> $row_types Dataset row types.
     *
     * @return array{active_count:int,total_count:int}
     */
    function blc_get_link_surveillance_counters(array $row_types) {
        $row_types = array_values(array_filter($row_types, static function ($value) {
            return is_scalar($value) && (string) $value !== '';
        }));

        if ($row_types === array()) {
            return array(
                'active_count' => 0,
                'total_count'  => 0,
            );
        }

        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix)) {
            return array(
                'active_count' => 0,
                'total_count'  => 0,
            );
        }

        if (!method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return array(
                'active_count' => 0,
                'total_count'  => 0,
            );
        }

        $table_name   = $wpdb->prefix . 'blc_broken_links';
        $placeholders = implode(',', array_fill(0, count($row_types), '%s'));
        $params       = $row_types;

        $active_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE ignored_at IS NULL AND type IN ($placeholders)",
            $params
        );

        $total_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE type IN ($placeholders)",
            $params
        );

        $active_count = (is_string($active_query) && $active_query !== '') ? (int) $wpdb->get_var($active_query) : 0;
        $total_count  = (is_string($total_query) && $total_query !== '') ? (int) $wpdb->get_var($total_query) : 0;

        return array(
            'active_count' => max(0, $active_count),
            'total_count'  => max(0, $total_count),
        );
    }
}

if (!function_exists('blc_get_broken_link_taxonomy_counts')) {
    /**
     * Aggregate the number of active broken links per taxonomy term.
     *
     * @param array<int,string> $taxonomies List of taxonomy slugs.
     * @param array<int,string> $row_types  Dataset row types.
     *
     * @return array<string,array<int,array{term_id:int,name:string,slug:string,count:int}>>
     */
    function blc_get_broken_link_taxonomy_counts(array $taxonomies, array $row_types) {
        $taxonomies = array_values(array_filter($taxonomies, static function ($taxonomy) {
            $taxonomy = blc_surveillance_sanitize_key($taxonomy);

            return $taxonomy !== '';
        }));

        if ($taxonomies === array()) {
            return array();
        }

        $row_types = array_values(array_filter($row_types, static function ($value) {
            return is_scalar($value) && (string) $value !== '';
        }));

        if ($row_types === array()) {
            return array();
        }

        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->prefix)) {
            return array();
        }

        if (!method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return array();
        }

        $links_table      = $wpdb->prefix . 'blc_broken_links';
        $relationships    = isset($wpdb->term_relationships) ? $wpdb->term_relationships : $wpdb->prefix . 'term_relationships';
        $term_taxonomy    = isset($wpdb->term_taxonomy) ? $wpdb->term_taxonomy : $wpdb->prefix . 'term_taxonomy';
        $terms_table      = isset($wpdb->terms) ? $wpdb->terms : $wpdb->prefix . 'terms';

        $type_placeholders = implode(',', array_fill(0, count($row_types), '%s'));
        $tax_placeholders  = implode(',', array_fill(0, count($taxonomies), '%s'));
        $params            = array_merge($row_types, $taxonomies);

        $query = $wpdb->prepare(
            "SELECT tt.taxonomy AS taxonomy,
                    t.term_id AS term_id,
                    t.slug AS term_slug,
                    t.name AS term_name,
                    COUNT(*) AS link_count
             FROM $links_table AS bl
             INNER JOIN $relationships AS tr ON tr.object_id = bl.post_id
             INNER JOIN $term_taxonomy AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN $terms_table AS t ON t.term_id = tt.term_id
             WHERE bl.ignored_at IS NULL
               AND bl.type IN ($type_placeholders)
               AND tt.taxonomy IN ($tax_placeholders)
             GROUP BY tt.taxonomy, t.term_id, t.slug, t.name",
            $params
        );

        if (!is_string($query) || $query === '') {
            return array();
        }

        $output_mode = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $rows        = $wpdb->get_results($query, $output_mode);
        if (!is_array($rows)) {
            return array();
        }

        $aggregates = array();

        foreach ($rows as $row) {
            $taxonomy = isset($row['taxonomy']) ? blc_surveillance_sanitize_key($row['taxonomy']) : '';
            if ($taxonomy === '') {
                continue;
            }

            $term_id = isset($row['term_id']) ? (int) $row['term_id'] : 0;
            if ($term_id <= 0) {
                continue;
            }

            $count = isset($row['link_count']) ? (int) $row['link_count'] : 0;
            if (!isset($aggregates[$taxonomy])) {
                $aggregates[$taxonomy] = array();
            }

            $aggregates[$taxonomy][$term_id] = array(
                'term_id' => $term_id,
                'name'    => isset($row['term_name']) ? (string) $row['term_name'] : '',
                'slug'    => isset($row['term_slug']) ? (string) $row['term_slug'] : '',
                'count'   => max(0, $count),
            );
        }

        return $aggregates;
    }
}

if (!function_exists('blc_surveillance_collect_taxonomies')) {
    /**
     * Extract the list of taxonomies referenced by the threshold definitions.
     *
     * @param array<string,mixed> $definitions Active definitions.
     *
     * @return array<int,string>
     */
    function blc_surveillance_collect_taxonomies(array $definitions) {
        if (!isset($definitions['taxonomy']) || !is_array($definitions['taxonomy'])) {
            return array();
        }

        $taxonomies = array();

        foreach ($definitions['taxonomy'] as $definition) {
            if (!isset($definition['taxonomy'])) {
                continue;
            }

            $taxonomy = blc_surveillance_sanitize_key($definition['taxonomy']);
            if ($taxonomy === '') {
                continue;
            }

            $taxonomies[] = $taxonomy;
        }

        return array_values(array_unique($taxonomies));
    }
}

if (!function_exists('blc_surveillance_threshold_compare')) {
    /**
     * Compare a metric value with a threshold using the provided operator.
     *
     * @param float|int $value      Metric value.
     * @param string    $comparison Comparison operator.
     * @param float|int $threshold  Threshold value.
     *
     * @return bool
     */
    function blc_surveillance_threshold_compare($value, $comparison, $threshold) {
        $value     = (float) $value;
        $threshold = (float) $threshold;

        switch (blc_normalize_surveillance_comparison($comparison)) {
            case 'gt':
                return $value > $threshold;
            case 'lte':
                return $value <= $threshold;
            case 'lt':
                return $value < $threshold;
            case 'eq':
                return abs($value - $threshold) < 0.000001;
            case 'neq':
                return abs($value - $threshold) >= 0.000001;
            case 'gte':
            default:
                return $value >= $threshold;
        }
    }
}

if (!function_exists('blc_get_taxonomy_labels')) {
    /**
     * Resolve the display labels for a list of taxonomy slugs.
     *
     * @param array<int,string> $taxonomies Taxonomy slugs.
     *
     * @return array<string,string>
     */
    function blc_get_taxonomy_labels(array $taxonomies) {
        $labels = array();

        foreach ($taxonomies as $taxonomy) {
            $taxonomy = blc_surveillance_sanitize_key($taxonomy);
            if ($taxonomy === '') {
                continue;
            }

            $labels[$taxonomy] = $taxonomy;

            if (function_exists('get_taxonomy')) {
                $taxonomy_object = get_taxonomy($taxonomy);
                if ($taxonomy_object && isset($taxonomy_object->labels) && isset($taxonomy_object->labels->name)) {
                    $name = (string) $taxonomy_object->labels->name;
                    if ($name !== '') {
                        $labels[$taxonomy] = $name;
                    }
                }
            }
        }

        return $labels;
    }
}
