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
            'singular' => 'Lien mort',
            'plural'   => 'Liens morts',
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
        $like = $wpdb->esc_like($this->site_url) . '%';

        $counts = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN url LIKE %s THEN 1 ELSE 0 END), 0) AS internal_count,
                    COALESCE(SUM(CASE WHEN url LIKE %s THEN 0 ELSE 1 END), 0) AS external_count
                 FROM $table_name
                 WHERE type = %s",
                $like,
                $like,
                'link'
            ),
            ARRAY_A
        );

        $total_count    = isset($counts['total']) ? (int) $counts['total'] : 0;
        $internal_count = isset($counts['internal_count']) ? (int) $counts['internal_count'] : 0;
        $external_count = isset($counts['external_count']) ? (int) $counts['external_count'] : max(0, $total_count - $internal_count);

        $all_class = ($current == 'all' ? 'current' : '');
        $views['all'] = "<a href='" . esc_url(remove_query_arg('link_type')) . "' class='" . esc_attr($all_class) . "'>Tous <span class='count'>($total_count)</span></a>";

        $internal_class = ($current == 'internal' ? 'current' : '');
        $views['internal'] = "<a href='" . esc_url(add_query_arg('link_type', 'internal')) . "' class='" . esc_attr($internal_class) . "'>Internes <span class='count'>($internal_count)</span></a>";

        $external_class = ($current == 'external' ? 'current' : '');
        $views['external'] = "<a href='" . esc_url(add_query_arg('link_type', 'external')) . "' class='" . esc_attr($external_class) . "'>Externes <span class='count'>($external_count)</span></a>";

        return $views;
    }

    /**
     * Définit les colonnes du tableau, avec une nouvelle colonne pour le texte du lien.
     */
    public function get_columns() {
        return [
            'url'          => 'URL Cassée',
            'anchor_text'  => 'Texte du lien',
            'post_title'   => 'Trouvé dans l\'article/page',
            'actions'      => 'Actions'
        ];
    }

    /**
     * Gère le rendu de la colonne "URL Cassée".
     */
    protected function column_url($item) {
        $output = sprintf(
            '<strong><a href="%s" target="_blank" rel="noopener noreferrer" title="Vérifier ce lien (nouvel onglet)">%s</a></strong>',
            esc_url($item['url']),
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
        return '—'; // Affiche un tiret si aucun texte de lien n'a été capturé
    }

    /**
     * Gère le rendu de la colonne "Trouvé dans...".
     */
    protected function column_post_title($item) {
        return sprintf('<a href="%s">%s</a>', get_edit_post_link($item['post_id']), esc_html($item['post_title']));
    }

    /**
     * Gère le rendu de la colonne "Actions".
     */
    protected function column_actions($item) {
        return sprintf('<a href="%s" class="button button-secondary">Modifier l\'article</a>', get_edit_post_link($item['post_id']));
    }

    /**
     * Définit les actions rapides pour la colonne principale.
     */
    protected function get_row_actions($item) {
        $actions = [];
        $actions['edit_link'] = sprintf(
            '<a href="#" class="blc-edit-link" data-postid="%d" data-url="%s" data-nonce="%s">Modifier</a>',
            $item['post_id'],
            esc_attr($item['url']),
            wp_create_nonce('blc_edit_link_nonce')
        );
        $actions['unlink'] = sprintf(
            '<a href="#" class="blc-unlink" data-postid="%d" data-url="%s" data-nonce="%s" style="color:#a00;">Dissocier</a>',
            $item['post_id'],
            esc_attr($item['url']),
            wp_create_nonce('blc_unlink_nonce')
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
        $like       = $wpdb->esc_like($this->site_url) . '%';

        $where   = ['type = %s'];
        $params  = ['link'];

        if ($current_view === 'internal') {
            $where[]  = 'url LIKE %s';
            $params[] = $like;
        } elseif ($current_view === 'external') {
            $where[]  = '(url NOT LIKE %s OR url IS NULL OR url = \'\')';
            $params[] = $like;
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
}
