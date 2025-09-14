<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

// On s'assure que la classe WP_List_Table est bien disponible
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Classe pour afficher la liste des images cassées dans une table d'administration WordPress
 * avec pagination et colonnes personnalisées.
 */
// CORRIGÉ : Le nom de la classe est maintenant BLC_Images_List_Table
class BLC_Images_List_Table extends WP_List_Table {

    /**
     * Constructeur de la classe.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'Image cassée',
            'plural'   => 'Images cassées',
            'ajax'     => false
        ]);
    }

    /**
     * Définit les colonnes du tableau.
     */
    public function get_columns() {
        return [
            'image_details' => 'Image Cassée',
            'post_title'    => 'Trouvé dans l\'article/page',
            'actions'       => 'Actions'
        ];
    }

    /**
     * Gère le rendu de la colonne "Image Cassée".
     */
    protected function column_image_details($item) {
        // L'URL de l'image est maintenant un lien cliquable
        $output = sprintf(
            '<strong><a href="%s" target="_blank" rel="noopener noreferrer" title="Vérifier cette image (nouvel onglet)">%s</a></strong>',
            esc_url($item['url']),
            esc_html($item['url'])
        );
        $output .= '<div class="row-actions"><span>Nom du fichier : <em>' . esc_html($item['anchor']) . '</em></span></div>';
        return $output;
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
     * Prépare les données pour l'affichage : récupération et pagination.
     */
    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        global $wpdb;
        $table_name = $wpdb->prefix . 'blc_broken_links';

        // On récupère uniquement les données des images depuis la table dédiée
        $data = $wpdb->get_results("SELECT url, anchor, post_id, post_title FROM $table_name WHERE type = 'image'", ARRAY_A);
        
        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $total_items  = count($data);

        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
        $this->items = array_slice($data, (($current_page - 1) * $per_page), $per_page);
    }
}
