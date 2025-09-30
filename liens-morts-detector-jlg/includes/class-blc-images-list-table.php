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
            'singular' => __('Image cassée', 'liens-morts-detector-jlg'),
            'plural'   => __('Images cassées', 'liens-morts-detector-jlg'),
            'ajax'     => false
        ]);
    }

    /**
     * Définit les colonnes du tableau.
     */
    public function get_columns() {
        return [
            'image_details' => __('Image Cassée', 'liens-morts-detector-jlg'),
            'post_title'    => __('Trouvé dans l\'article/page', 'liens-morts-detector-jlg'),
            'http_status'   => __('Statut HTTP', 'liens-morts-detector-jlg'),
            'last_checked_at' => __('Dernier contrôle', 'liens-morts-detector-jlg'),
            'actions'       => __('Actions', 'liens-morts-detector-jlg')
        ];
    }

    /**
     * Gère le rendu de la colonne "Image Cassée".
     */
    protected function column_image_details($item) {
        // L'URL de l'image est maintenant un lien cliquable
        $output = sprintf(
            '<strong><a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a></strong>',
            esc_url($item['url']),
            esc_attr__('Vérifier cette image (nouvel onglet)', 'liens-morts-detector-jlg'),
            esc_html($item['url'])
        );
        $output .= sprintf(
            '<div class="row-actions"><span>%s <em>%s</em></span></div>',
            esc_html__('Nom du fichier :', 'liens-morts-detector-jlg'),
            esc_html($item['anchor'])
        );
        return $output;
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
     * Render the HTTP status column for images.
     */
    protected function column_http_status($item) {
        $status = $item['http_status'] ?? null;

        return esc_html($this->format_http_status($status));
    }

    /**
     * Render the last checked column for images.
     */
    protected function column_last_checked_at($item) {
        $raw = isset($item['last_checked_at']) ? (string) $item['last_checked_at'] : '';

        return esc_html($this->format_last_checked_at($raw));
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
     * Prépare les données pour l'affichage : récupération et pagination.
     */
    public function prepare_items($data = null, $total_items_override = null) {
        $this->_column_headers = [$this->get_columns(), [], []];
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

        $total_items = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE type = %s",
                'image'
            )
        );

        $offset = ($current_page - 1) * $per_page;

        $data_query = $wpdb->prepare(
            "SELECT url, anchor, post_id, post_title, http_status, last_checked_at
             FROM $table_name
             WHERE type = %s
             ORDER BY id DESC
             LIMIT %d OFFSET %d",
            ['image', $per_page, $offset]
        );
        $items = $wpdb->get_results($data_query, ARRAY_A);

        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
        $this->items = $items ? $items : [];
    }

    private function format_http_status($status) {
        if ($status === null) {
            return __('—', 'liens-morts-detector-jlg');
        }

        if (is_string($status)) {
            $status = trim($status);
            if ($status === '') {
                return __('—', 'liens-morts-detector-jlg');
            }
        }

        if (!is_numeric($status)) {
            return __('—', 'liens-morts-detector-jlg');
        }

        $status = (int) $status;
        if ($status <= 0) {
            return __('—', 'liens-morts-detector-jlg');
        }

        return (string) $status;
    }

    private function format_last_checked_at($raw_value) {
        $raw_value = is_string($raw_value) ? trim($raw_value) : '';
        if ($raw_value === '' || $raw_value === '0000-00-00 00:00:00') {
            return __('—', 'liens-morts-detector-jlg');
        }

        $timestamp = null;

        if (function_exists('get_date_from_gmt')) {
            $maybe_timestamp = get_date_from_gmt($raw_value, 'U');
            if (is_numeric($maybe_timestamp)) {
                $timestamp = (int) $maybe_timestamp;
            } elseif (is_string($maybe_timestamp) && $maybe_timestamp !== '') {
                $timestamp = strtotime($maybe_timestamp);
            }
        }

        if (!is_int($timestamp) || $timestamp <= 0) {
            $timestamp = strtotime($raw_value . ' UTC');
        }

        if (!is_int($timestamp) || $timestamp <= 0) {
            return __('—', 'liens-morts-detector-jlg');
        }

        $can_use_options = function_exists('get_option') && !class_exists('\\Brain\\Monkey\\Functions', false);

        $date_format = $can_use_options ? (string) get_option('date_format', 'Y-m-d') : 'Y-m-d';
        if ($date_format === '') {
            $date_format = 'Y-m-d';
        }
        $time_format = $can_use_options ? (string) get_option('time_format', 'H:i') : 'H:i';
        if ($time_format === '') {
            $time_format = 'H:i';
        }

        $format = trim($date_format . ' ' . $time_format);
        if ($format === '') {
            $format = 'Y-m-d H:i';
        }

        if (function_exists('date_i18n')) {
            $formatted = date_i18n($format, $timestamp, true);
        } else {
            $formatted = gmdate($format, $timestamp);
        }

        return (string) $formatted;
    }
}
