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
        $current = (!empty($_GET['link_type'])) ? $_GET['link_type'] : 'all';

        $all_links = get_option('blc_broken_links', []);
        $total_count = count($all_links);
        $internal_count = 0;
        $external_count = 0;

        if ($total_count > 0) {
            foreach ($all_links as $link) {
                if (strpos($link['url'], $this->site_url) === 0) {
                    $internal_count++;
                } else {
                    $external_count++;
                }
            }
        }

        $all_class = ($current == 'all' ? 'current' : '');
        $views['all'] = "<a href='" . remove_query_arg('link_type') . "' class='$all_class'>Tous <span class='count'>($total_count)</span></a>";

        $internal_class = ($current == 'internal' ? 'current' : '');
        $views['internal'] = "<a href='" . add_query_arg('link_type', 'internal') . "' class='$internal_class'>Internes <span class='count'>($internal_count)</span></a>";

        $external_class = ($current == 'external' ? 'current' : '');
        $views['external'] = "<a href='" . add_query_arg('link_type', 'external') . "' class='$external_class'>Externes <span class='count'>($external_count)</span></a>";

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
    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        $current_view = (!empty($_GET['link_type'])) ? $_GET['link_type'] : 'all';
        $all_data = get_option('blc_broken_links', []);
        $filtered_data = [];

        if ($current_view === 'all' || empty($all_data)) {
            $filtered_data = $all_data;
        } else {
            foreach ($all_data as $link) {
                $is_internal = (strpos($link['url'], $this->site_url) === 0);
                if ($current_view === 'internal' && $is_internal) {
                    $filtered_data[] = $link;
                } elseif ($current_view === 'external' && !$is_internal) {
                    $filtered_data[] = $link;
                }
            }
        }
        
        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $total_items  = count($filtered_data);

        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
        $this->items = array_slice($filtered_data, (($current_page - 1) * $per_page), $per_page);
    }
}
