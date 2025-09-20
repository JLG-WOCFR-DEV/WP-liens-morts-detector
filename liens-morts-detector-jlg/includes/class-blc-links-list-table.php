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
     * Ajoute les liens de filtrage au-dessus du tableau (Tous | Internes | Externes).
     */
    protected function get_views() {
        $views = [];
        $current = (!empty($_GET['link_type'])) ? sanitize_text_field(wp_unslash($_GET['link_type'])) : 'all';
        global $wpdb;
        $table_name = $wpdb->prefix . 'blc_broken_links';
        $internal_condition = $this->build_internal_url_condition();
        $internal_case      = $internal_condition['case_template'];
        $internal_case_params = $internal_condition['case_params'];

        $counts = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM($internal_case), 0) AS internal_count,
                    (COUNT(*) - COALESCE(SUM($internal_case), 0)) AS external_count
                 FROM $table_name
                 WHERE type = %s",
                array_merge($internal_case_params, $internal_case_params, ['link'])
            ),
            ARRAY_A
        );

        $total_count    = isset($counts['total']) ? (int) $counts['total'] : 0;
        $internal_count = isset($counts['internal_count']) ? (int) $counts['internal_count'] : 0;
        $external_count = isset($counts['external_count']) ? (int) $counts['external_count'] : max(0, $total_count - $internal_count);

        $all_class = ($current == 'all' ? 'current' : '');
        $views['all'] = sprintf(
            "<a href='%s' class='%s'>%s <span class='count'>(%d)</span></a>",
            esc_url(remove_query_arg('link_type')),
            esc_attr($all_class),
            esc_html__('Tous', 'liens-morts-detector-jlg'),
            $total_count
        );

        $internal_class = ($current == 'internal' ? 'current' : '');
        $views['internal'] = sprintf(
            "<a href='%s' class='%s'>%s <span class='count'>(%d)</span></a>",
            esc_url(add_query_arg('link_type', 'internal')),
            esc_attr($internal_class),
            esc_html__('Internes', 'liens-morts-detector-jlg'),
            $internal_count
        );

        $external_class = ($current == 'external' ? 'current' : '');
        $views['external'] = sprintf(
            "<a href='%s' class='%s'>%s <span class='count'>(%d)</span></a>",
            esc_url(add_query_arg('link_type', 'external')),
            esc_attr($external_class),
            esc_html__('Externes', 'liens-morts-detector-jlg'),
            $external_count
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
            'post_title'   => __('Trouvé dans l\'article/page', 'liens-morts-detector-jlg'),
            'actions'      => __('Actions', 'liens-morts-detector-jlg')
        ];
    }

    /**
     * Gère le rendu de la colonne "URL Cassée".
     */
    protected function column_url($item) {
        $output = sprintf(
            '<strong><a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a></strong>',
            esc_url($item['url']),
            esc_attr__('Vérifier ce lien (nouvel onglet)', 'liens-morts-detector-jlg'),
            esc_html($item['url'])
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
        $actions['edit_link'] = sprintf(
            '<a href="#" class="blc-edit-link" data-postid="%d" data-url="%s" data-nonce="%s">%s</a>',
            $item['post_id'],
            esc_attr($item['url']),
            wp_create_nonce('blc_edit_link_nonce'),
            esc_html__('Modifier', 'liens-morts-detector-jlg')
        );
        $actions['unlink'] = sprintf(
            '<a href="#" class="blc-unlink" data-postid="%d" data-url="%s" data-nonce="%s" style="color:#a00;">%s</a>',
            $item['post_id'],
            esc_attr($item['url']),
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

        if ($current_view === 'internal') {
            $where[] = $internal_sql;
            $params  = array_merge($params, $internal_params);
        } elseif ($current_view === 'external') {
            $where[] = "({$external_sql} OR url IS NULL OR url = '')";
            $params  = array_merge($params, $external_params);
        }

        $where_clause = implode(' AND ', $where);

        $total_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE $where_clause",
            $params
        );
        $total_items = (int) $wpdb->get_var($total_query);

        $offset = ($current_page - 1) * $per_page;

        $data_query = $wpdb->prepare(
            "SELECT url, anchor, post_id, post_title
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

    private function build_internal_url_condition() {
        global $wpdb;

        $patterns = [];

        $add_pattern = function ($value) use (&$patterns, $wpdb) {
            if ($value === '') {
                return;
            }

            if (!isset($patterns[$value])) {
                $patterns[$value] = [
                    'sql'    => 'url LIKE %s',
                    'params' => [$wpdb->esc_like($value) . '%'],
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

        return [
            'sql'           => '(' . implode(' OR ', $or_conditions) . ')',
            'params'        => $case_params,
            'case_template' => 'CASE ' . implode(' ', $case_clauses) . ' ELSE 0 END',
            'case_params'   => $case_params,
            'not_sql'       => '(' . implode(' AND ', $not_clauses) . ')',
            'not_params'    => $not_params,
        ];
    }
}
