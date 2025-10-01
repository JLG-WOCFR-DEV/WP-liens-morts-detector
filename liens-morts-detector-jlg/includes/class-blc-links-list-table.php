<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

// On s'assure que la classe WP_List_Table, nécessaire pour notre tableau, est bien disponible
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Classe pour afficher la liste des liens morts dans une table d'administration WordPress
 * avec pagination, filtrage et actions rapides.
 */
class BLC_Links_List_Table extends WP_List_Table {

    private $site_url;

    private $internal_url_condition_cache = null;
    private $search_term = null;

    /**
     * Constructeur de la classe.
     */
    public function __construct() {
        parent::__construct([
            'singular' => __('Lien mort', 'liens-morts-detector-jlg'),
            'plural'   => __('Liens morts', 'liens-morts-detector-jlg'),
            'ajax'     => false
        ]);
        $this->site_url = home_url();
    }

    /**
     * Retourne le champ de recherche à afficher au-dessus du tableau.
     */
    protected function get_search_box() {
        $input_id    = 'blc-links-search-input';
        $search_term = $this->get_search_term();

        return sprintf(
            '<p class="search-box"><label class="screen-reader-text" for="%1$s">%2$s</label><input type="search" id="%1$s" name="s" value="%3$s" /><input type="submit" class="button" value="%4$s" /></p>',
            esc_attr($input_id),
            esc_html__('Rechercher des liens morts :', 'liens-morts-detector-jlg'),
            esc_attr($search_term),
            esc_attr__('Rechercher', 'liens-morts-detector-jlg')
        );
    }

    /**
     * Ajoute le champ de recherche avant le tableau.
     */
    protected function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }

        $available_post_types = [];
        $post_types = function_exists('get_post_types') ? get_post_types(['public' => true]) : [];
        if (is_array($post_types)) {
            foreach ($post_types as $post_type) {
                if (is_string($post_type) && $post_type !== '') {
                    $available_post_types[] = $post_type;
                }
            }
        }

        $selected_post_type = '';
        if (isset($_GET['post_type'])) {
            $candidate = sanitize_key(wp_unslash($_GET['post_type']));
            if ($candidate !== '') {
                $selected_post_type = $candidate;
            }
        }

        if (!empty($available_post_types)) {
            echo '<div class="alignleft actions">';
            echo '<label class="screen-reader-text" for="blc-post-type-filter">' . esc_html__('Filtrer par type de contenu', 'liens-morts-detector-jlg') . '</label>';

            $select  = '<select name="post_type" id="blc-post-type-filter" aria-label="' . esc_attr__('Filtrer par type de contenu', 'liens-morts-detector-jlg') . '">';
            $select .= '<option value="">' . esc_html__('Tous les types de contenu', 'liens-morts-detector-jlg') . '</option>';

            foreach ($available_post_types as $post_type) {
                $label = $post_type;
                if (function_exists('get_post_type_object')) {
                    $post_type_object = get_post_type_object($post_type);
                    if (is_object($post_type_object)) {
                        if (isset($post_type_object->labels) && is_object($post_type_object->labels) && !empty($post_type_object->labels->singular_name)) {
                            $label = (string) $post_type_object->labels->singular_name;
                        } elseif (!empty($post_type_object->label)) {
                            $label = (string) $post_type_object->label;
                        }
                    }
                }

                $is_selected = ($selected_post_type === $post_type) ? ' selected="selected"' : '';
                $select     .= sprintf(
                    '<option value="%1$s"%3$s>%2$s</option>',
                    esc_attr($post_type),
                    esc_html($label),
                    $is_selected
                );
            }

            $select .= '</select>';
            echo $select; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

            printf(
                '<input type="submit" class="button" value="%s" />',
                esc_attr__('Filtrer', 'liens-morts-detector-jlg')
            );

            echo '</div>';
        }

        echo $this->get_search_box(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Ajoute les liens de filtrage au-dessus du tableau (Tous | Internes | Externes).
     */
    protected function get_views() {
        $views = [];
        $current = (!empty($_GET['link_type'])) ? sanitize_text_field(wp_unslash($_GET['link_type'])) : 'all';
        global $wpdb;
        $table_name = $wpdb->prefix . 'blc_broken_links';
        $internal_condition = $this->build_internal_url_condition();
        $legacy_case        = $internal_condition['case_template'];
        $legacy_params      = $internal_condition['case_params'];
        $needs_recheck_clause = '(last_checked_at IS NULL OR last_checked_at = %s OR last_checked_at <= %s)';
        $needs_recheck_params = [$this->get_unchecked_sentinel_value(), $this->get_recheck_threshold_gmt()];

        $counts_query = $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(
                    CASE
                        WHEN is_internal IS NOT NULL THEN is_internal
                        ELSE $legacy_case
                    END
                ) AS internal_count,
                SUM(CASE WHEN http_status IN (404, 410) THEN 1 ELSE 0 END) AS not_found_count,
                SUM(CASE WHEN http_status BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS server_error_count,
                SUM(CASE WHEN http_status BETWEEN 300 AND 399 THEN 1 ELSE 0 END) AS redirect_count,
                SUM(CASE WHEN $needs_recheck_clause THEN 1 ELSE 0 END) AS needs_recheck_count
             FROM $table_name
             WHERE type = %s",
            array_merge($legacy_params, $needs_recheck_params, ['link'])
        );

        $counts = $wpdb->get_row($counts_query, ARRAY_A);

        $total_count        = isset($counts['total']) ? (int) $counts['total'] : 0;
        $internal_count     = isset($counts['internal_count']) ? (int) $counts['internal_count'] : 0;
        $external_count     = max(0, $total_count - $internal_count);
        $not_found_count    = isset($counts['not_found_count']) ? (int) $counts['not_found_count'] : 0;
        $server_error_count = isset($counts['server_error_count']) ? (int) $counts['server_error_count'] : 0;
        $redirect_count     = isset($counts['redirect_count']) ? (int) $counts['redirect_count'] : 0;
        $needs_recheck_count = isset($counts['needs_recheck_count']) ? (int) $counts['needs_recheck_count'] : 0;

        $all_class = ($current === 'all' ? 'current' : '');
        $views['all'] = sprintf(
            "<a href='%s' class='%s'>%s <span class='count'>(%d)</span></a>",
            esc_url(remove_query_arg('link_type')),
            esc_attr($all_class),
            esc_html__('Tous', 'liens-morts-detector-jlg'),
            $total_count
        );

        $internal_class = ($current === 'internal' ? 'current' : '');
        $views['internal'] = sprintf(
            "<a href='%s' class='%s'>%s <span class='count'>(%d)</span></a>",
            esc_url(add_query_arg('link_type', 'internal')),
            esc_attr($internal_class),
            esc_html__('Internes', 'liens-morts-detector-jlg'),
            $internal_count
        );

        $external_class = ($current === 'external' ? 'current' : '');
        $views['external'] = sprintf(
            "<a href='%s' class='%s'>%s <span class='count'>(%d)</span></a>",
            esc_url(add_query_arg('link_type', 'external')),
            esc_attr($external_class),
            esc_html__('Externes', 'liens-morts-detector-jlg'),
            $external_count
        );

        $not_found_class = ($current === 'status_404_410' ? 'current' : '');
        $views['status_404_410'] = sprintf(
            "<a href='%s' class='%s'>%s <span class='count'>(%d)</span></a>",
            esc_url(add_query_arg('link_type', 'status_404_410')),
            esc_attr($not_found_class),
            esc_html__('404 / 410', 'liens-morts-detector-jlg'),
            $not_found_count
        );

        $server_error_class = ($current === 'status_5xx' ? 'current' : '');
        $views['status_5xx'] = sprintf(
            "<a href='%s' class='%s'>%s <span class='count'>(%d)</span></a>",
            esc_url(add_query_arg('link_type', 'status_5xx')),
            esc_attr($server_error_class),
            esc_html__('5xx', 'liens-morts-detector-jlg'),
            $server_error_count
        );

        $redirect_class = ($current === 'status_redirects' ? 'current' : '');
        $views['status_redirects'] = sprintf(
            "<a href='%s' class='%s'>%s <span class='count'>(%d)</span></a>",
            esc_url(add_query_arg('link_type', 'status_redirects')),
            esc_attr($redirect_class),
            esc_html__('Redirections', 'liens-morts-detector-jlg'),
            $redirect_count
        );

        $needs_recheck_class = ($current === 'needs_recheck' ? 'current' : '');
        $views['needs_recheck'] = sprintf(
            "<a href='%s' class='%s'>%s <span class='count'>(%d)</span></a>",
            esc_url(add_query_arg('link_type', 'needs_recheck')),
            esc_attr($needs_recheck_class),
            esc_html__('À revérifier', 'liens-morts-detector-jlg'),
            $needs_recheck_count
        );

        return $views;
    }

    /**
     * Définit les colonnes du tableau, avec une nouvelle colonne pour le texte du lien.
     */
    public function get_columns() {
        return [
            'url'          => __('URL Cassée', 'liens-morts-detector-jlg'),
            'anchor_text'  => __('Texte du lien', 'liens-morts-detector-jlg'),
            'http_status'  => __('Statut HTTP', 'liens-morts-detector-jlg'),
            'last_checked_at' => __('Dernier contrôle', 'liens-morts-detector-jlg'),
            'post_title'   => __('Trouvé dans l\'article/page', 'liens-morts-detector-jlg'),
            'actions'      => __('Actions', 'liens-morts-detector-jlg')
        ];
    }

    /**
     * Gère le rendu de la colonne "URL Cassée".
     */
    protected function column_url($item) {
        $original_url = isset($item['url']) ? (string) $item['url'] : '';
        $href = $original_url;

        $permalink = '';
        if (!empty($item['post_id'])) {
            $permalink_candidate = get_permalink($item['post_id']);
            if (is_string($permalink_candidate)) {
                $permalink = $permalink_candidate;
            }
        }

        $home_url = home_url();
        $site_url = is_string($home_url) ? $home_url : '';
        if (function_exists('trailingslashit')) {
            $site_url = trailingslashit($site_url);
        } else {
            $site_url = rtrim($site_url, '/') . '/';
        }

        if (function_exists('blc_normalize_link_url')) {
            $normalized = blc_normalize_link_url($original_url, $site_url, null, $permalink);
            if (is_string($normalized) && $normalized !== '') {
                $parsed = null;
                if (function_exists('wp_parse_url')) {
                    $parsed = wp_parse_url($normalized);
                }
                if (!is_array($parsed)) {
                    $parsed = parse_url($normalized);
                }

                if (
                    is_array($parsed) &&
                    isset($parsed['scheme']) &&
                    in_array(strtolower($parsed['scheme']), ['http', 'https'], true)
                ) {
                    $href = $normalized;
                }
            }
        }

        $output = sprintf(
            '<strong><a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a></strong>',
            esc_url($href),
            esc_attr__('Vérifier ce lien (nouvel onglet)', 'liens-morts-detector-jlg'),
            esc_html($original_url)
        );
        
        // Les actions rapides (Modifier, Dissocier) sont ajoutées sous la colonne principale
        $output .= $this->row_actions($this->get_row_actions($item), true);

        return $output;
    }

    /**
     * Gère le rendu de la nouvelle colonne "Texte du lien".
     */
    protected function column_anchor_text($item) {
        if (!empty($item['anchor'])) {
            return '<em>' . esc_html($item['anchor']) . '</em>';
        }
        return esc_html__('—', 'liens-morts-detector-jlg'); // Affiche un tiret si aucun texte de lien n'a été capturé
    }

    /**
     * Gère le rendu de la colonne "Trouvé dans...".
     */
    protected function column_post_title($item) {
        $edit_link = get_edit_post_link($item['post_id']);

        if ($edit_link === false) {
            return esc_html__('—', 'liens-morts-detector-jlg');
        }

        return sprintf('<a href="%s">%s</a>', esc_url($edit_link), esc_html($item['post_title']));
    }

    protected function column_http_status($item) {
        $raw_status = $item['http_status'] ?? null;

        if ($raw_status === null || $raw_status === '') {
            return esc_html__('—', 'liens-morts-detector-jlg');
        }

        if (is_numeric($raw_status)) {
            $raw_status = (int) $raw_status;
        }

        return esc_html((string) $raw_status);
    }

    protected function column_last_checked_at($item) {
        $raw_value = $item['last_checked_at'] ?? '';

        return $this->format_last_checked_at_for_display($raw_value);
    }

    /**
     * Gère le rendu de la colonne "Actions".
     */
    protected function column_actions($item) {
        $edit_link = get_edit_post_link($item['post_id']);

        if ($edit_link === false) {
            return esc_html__('—', 'liens-morts-detector-jlg');
        }

        return sprintf(
            '<a href="%s" class="button button-secondary">%s</a>',
            esc_url($edit_link),
            esc_html__('Modifier l\'article', 'liens-morts-detector-jlg')
        );
    }

    /**
     * Définit les actions rapides pour la colonne principale.
     */
    protected function get_row_actions($item) {
        $actions = [];
        $row_id            = isset($item['id']) ? absint($item['id']) : 0;
        $post_id           = isset($item['post_id']) ? absint($item['post_id']) : 0;
        $occurrence_raw     = $item['occurrence_index'] ?? null;
        $occurrence_index   = null;
        if (is_numeric($occurrence_raw)) {
            $occurrence_index = (int) $occurrence_raw;
        }
        $has_occurrence = ($occurrence_index !== null && $occurrence_index >= 0);
        $data_attributes   = [
            sprintf('data-postid="%d"', $post_id),
            sprintf('data-url="%s"', esc_attr($item['url'])),
            sprintf('data-row-id="%d"', $row_id),
        ];

        if ($has_occurrence) {
            $data_attributes[] = sprintf('data-occurrence-index="%d"', $occurrence_index);
        }

        $data_attributes = implode(' ', $data_attributes);

        $actions['edit_link'] = sprintf(
            '<a href="#" class="blc-edit-link" %s data-nonce="%s">%s</a>',
            $data_attributes,
            wp_create_nonce('blc_edit_link_nonce'),
            esc_html__('Modifier', 'liens-morts-detector-jlg')
        );
        $actions['unlink'] = sprintf(
            '<a href="#" class="blc-unlink" %s data-nonce="%s" style="color:#a00;">%s</a>',
            $data_attributes,
            wp_create_nonce('blc_unlink_nonce'),
            esc_html__('Dissocier', 'liens-morts-detector-jlg')
        );
        return $actions;
    }

    /**
     * Prépare les données pour l'affichage : récupération, filtrage, et pagination.
     */
    public function prepare_items($data = null, $total_items_override = null) {
        $this->_column_headers = [$this->get_columns(), [], []];
        $current_view = (!empty($_GET['link_type'])) ? sanitize_text_field(wp_unslash($_GET['link_type'])) : 'all';
        $per_page     = 20;
        $current_page = max(1, (int) $this->get_pagenum());
        $search_term  = $this->get_search_term();

        $available_post_types = [];
        $post_types = function_exists('get_post_types') ? get_post_types(['public' => true]) : [];
        if (is_array($post_types)) {
            foreach ($post_types as $post_type) {
                if (is_string($post_type) && $post_type !== '') {
                    $available_post_types[] = $post_type;
                }
            }
        }

        $selected_post_type = '';
        if (isset($_GET['post_type'])) {
            $candidate = sanitize_key(wp_unslash($_GET['post_type']));
            if ($candidate !== '') {
                $selected_post_type = $candidate;
            }
        }

        if (is_array($data)) {
            $total_items = ($total_items_override !== null) ? (int) $total_items_override : count($data);
            $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
            $this->items = array_slice($data, (($current_page - 1) * $per_page), $per_page);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'blc_broken_links';
        $internal_condition = $this->build_internal_url_condition();
        $internal_sql       = $internal_condition['sql'];
        $internal_params    = $internal_condition['params'];
        $external_sql       = $internal_condition['not_sql'];
        $external_params    = $internal_condition['not_params'];

        $where  = ['type = %s'];
        $params = ['link'];

        if ($search_term !== '') {
            $like = '%' . $wpdb->esc_like($search_term) . '%';
            $where[] = '(url LIKE %s OR anchor LIKE %s OR post_title LIKE %s)';
            $params  = array_merge($params, [$like, $like, $like]);
        }

        if ($selected_post_type !== '' && in_array($selected_post_type, $available_post_types, true)) {
            $where[] = 'post_type = %s';
            $params[] = $selected_post_type;
        }

        if ($current_view === 'internal') {
            $where[] = '(is_internal = 1 OR (is_internal IS NULL AND ' . $internal_sql . '))';
            $params  = array_merge($params, $internal_params);
        } elseif ($current_view === 'external') {
            $external_clause = '(is_internal = 0 OR (is_internal IS NULL AND (' . $external_sql . " OR url IS NULL OR url = ''" . ')))';
            $where[] = $external_clause;
            $params  = array_merge($params, $external_params);
        }

        $needs_recheck_clause = '(last_checked_at IS NULL OR last_checked_at = %s OR last_checked_at <= %s)';

        if ($current_view === 'status_404_410') {
            $where[] = 'http_status IN (404, 410)';
        } elseif ($current_view === 'status_5xx') {
            $where[] = '(http_status BETWEEN 500 AND 599)';
        } elseif ($current_view === 'status_redirects') {
            $where[] = '(http_status BETWEEN 300 AND 399)';
        } elseif ($current_view === 'needs_recheck') {
            $where[] = $needs_recheck_clause;
            $params = array_merge($params, [$this->get_unchecked_sentinel_value(), $this->get_recheck_threshold_gmt()]);
        }

        $where_clause = implode(' AND ', $where);

        $total_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE $where_clause",
            $params
        );
        $total_items = (int) $wpdb->get_var($total_query);

        $offset = ($current_page - 1) * $per_page;

        $data_query = $wpdb->prepare(
            "SELECT id, occurrence_index, url, anchor, post_id, post_title, http_status, last_checked_at
             FROM $table_name
             WHERE $where_clause
             ORDER BY id DESC
             LIMIT %d OFFSET %d",
            array_merge($params, [$per_page, $offset])
        );
        $items = $wpdb->get_results($data_query, ARRAY_A);

        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
        $this->items = $items ? $items : [];
    }

    private function format_last_checked_at_for_display($value) {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return esc_html__('—', 'liens-morts-detector-jlg');
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return esc_html__('—', 'liens-morts-detector-jlg');
        }

        $timezone = $this->get_site_timezone();
        if ($timezone instanceof \DateTimeZone) {
            $date = $date->setTimezone($timezone);
        }

        $format = $this->get_date_time_format();

        return esc_html($date->format($format));
    }

    private function get_site_timezone() {
        if (function_exists('wp_timezone')) {
            $timezone = wp_timezone();
            if ($timezone instanceof \DateTimeZone) {
                return $timezone;
            }
        }

        $timezone_string = function_exists('wp_timezone_string') ? wp_timezone_string() : '';
        if (is_string($timezone_string) && $timezone_string !== '') {
            try {
                return new \DateTimeZone($timezone_string);
            } catch (\Exception $e) {
                // Ignore and fallback below.
            }
        }

        $offset = 0.0;
        if (function_exists('get_option')) {
            $raw_offset = get_option('gmt_offset', 0);
            if (is_numeric($raw_offset)) {
                $offset = (float) $raw_offset;
            }
        }

        $seconds_per_hour = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
        $seconds = (int) round($offset * $seconds_per_hour);

        if ($seconds === 0) {
            return new \DateTimeZone('UTC');
        }

        $sign = $seconds >= 0 ? '+' : '-';
        $seconds = abs($seconds);
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $timezone_name = sprintf('%s%02d:%02d', $sign, $hours, $minutes);

        try {
            return new \DateTimeZone($timezone_name);
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }

    private function get_date_time_format() {
        $date_format = 'Y-m-d';
        $time_format = 'H:i';

        if (function_exists('get_option')) {
            $stored_date = get_option('date_format');
            if (is_string($stored_date) && $stored_date !== '') {
                $date_format = $stored_date;
            }

            $stored_time = get_option('time_format');
            if (is_string($stored_time) && $stored_time !== '') {
                $time_format = $stored_time;
            }
        }

        return trim($date_format . ' ' . $time_format);
    }

    private function get_search_term() {
        if (is_string($this->search_term)) {
            return $this->search_term;
        }

        if (!isset($_REQUEST['s'])) {
            $this->search_term = '';
            return $this->search_term;
        }

        $raw = $_REQUEST['s'];
        if (!is_string($raw)) {
            $this->search_term = '';
            return $this->search_term;
        }

        if (function_exists('wp_unslash')) {
            $raw = wp_unslash($raw);
        }

        if (function_exists('sanitize_text_field')) {
            $raw = sanitize_text_field($raw);
        }

        $this->search_term = $raw;

        return $this->search_term;
    }

    private function get_unchecked_sentinel_value() {
        return '0000-00-00 00:00:00';
    }

    private function get_recheck_threshold_gmt() {
        $interval = $this->get_recheck_interval_seconds();
        $current_time = $this->get_current_time_gmt();
        $threshold_timestamp = max(0, $current_time - $interval);

        return gmdate('Y-m-d H:i:s', $threshold_timestamp);
    }

    private function get_recheck_interval_seconds() {
        if (defined('WEEK_IN_SECONDS')) {
            return (int) WEEK_IN_SECONDS;
        }

        $day_in_seconds = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;

        return 7 * $day_in_seconds;
    }

    private function get_current_time_gmt() {
        if (function_exists('current_time')) {
            $timestamp = current_time('timestamp', true);
            if (is_numeric($timestamp)) {
                return (int) $timestamp;
            }
        }

        return time();
    }

    private function build_internal_url_condition() {
        if (is_array($this->internal_url_condition_cache)) {
            return $this->internal_url_condition_cache;
        }

        global $wpdb;

        $patterns = [];

        $add_pattern = function ($value) use (&$patterns, $wpdb) {
            if ($value === '') {
                return;
            }

            if (!isset($patterns[$value])) {
                $regex_value = preg_quote($value, '/');
                $regex_value = str_replace('\/', '/', $regex_value);
                $regex_value = str_replace('\:', ':', $regex_value);

                $patterns[$value] = [
                    'sql'    => '(url LIKE %s AND url REGEXP %s)',
                    'params' => [
                        $wpdb->esc_like($value) . '%',
                        '^' . $regex_value . '(?:[/?#]|$)',
                    ],
                ];
            }
        };

        $add_pattern($this->site_url);

        $parsed_url = function_exists('wp_parse_url') ? wp_parse_url($this->site_url) : parse_url($this->site_url);
        $host       = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port       = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $path       = isset($parsed_url['path']) ? trim($parsed_url['path'], '/') : '';
        $path       = $path === '' ? '' : '/' . $path;

        if ($host !== '') {
            $host_with_port = $host . $port;
            $segments       = [$host_with_port];

            if ($path !== '') {
                $segments[] = $host_with_port . $path;
            }

            $schemes = ['https', 'http'];
            if (!empty($parsed_url['scheme']) && !in_array($parsed_url['scheme'], $schemes, true)) {
                array_unshift($schemes, $parsed_url['scheme']);
                $schemes = array_values(array_unique($schemes));
            }

            foreach ($segments as $segment) {
                foreach ($schemes as $scheme) {
                    $add_pattern($scheme . '://' . $segment);
                }

                $add_pattern('//' . $segment);
            }
        }

        $patterns['relative'] = [
            'sql'    => '(url LIKE %s AND url NOT LIKE %s)',
            'params' => ['/%', '//%'],
        ];

        $patterns['path_without_scheme'] = [
            'sql'    => "(url NOT LIKE %s AND url NOT LIKE %s AND url NOT LIKE %s AND url <> '' AND url IS NOT NULL)",
            'params' => ['%://%', '//%', '%:%'],
        ];

        $or_conditions = [];
        $case_clauses  = [];
        $case_params   = [];
        $not_clauses   = [];
        $not_params    = [];

        foreach ($patterns as $pattern) {
            $or_conditions[] = '(' . $pattern['sql'] . ')';
            $case_clauses[]  = 'WHEN ' . $pattern['sql'] . ' THEN 1';
            $case_params     = array_merge($case_params, $pattern['params']);
            $not_clauses[]   = 'NOT (' . $pattern['sql'] . ')';
            $not_params      = array_merge($not_params, $pattern['params']);
        }

        $this->internal_url_condition_cache = [
            'sql'           => '(' . implode(' OR ', $or_conditions) . ')',
            'params'        => $case_params,
            'case_template' => 'CASE ' . implode(' ', $case_clauses) . ' ELSE 0 END',
            'case_params'   => $case_params,
            'not_sql'       => '(' . implode(' AND ', $not_clauses) . ')',
            'not_params'    => $not_params,
        ];

        return $this->internal_url_condition_cache;
    }
}
