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

    private $column_labels_cache = null;

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
            'http_status'   => __('Statut HTTP', 'liens-morts-detector-jlg'),
            'last_checked_at' => __('Dernier contrôle', 'liens-morts-detector-jlg'),
            'post_title'    => __('Trouvé dans l\'article/page', 'liens-morts-detector-jlg'),
            'actions'       => __('Actions', 'liens-morts-detector-jlg')
        ];
    }

    /**
     * Gère le rendu de la colonne "Image Cassée".
     */
    protected function column_image_details($item) {
        // L'URL de l'image est maintenant un lien cliquable
        $output = $this->get_column_label_html('image_details');
        $output .= sprintf(
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
            return $this->get_column_label_html('post_title') . esc_html__('—', 'liens-morts-detector-jlg');
        }

        return $this->get_column_label_html('post_title') . sprintf('<a href="%s">%s</a>', esc_url($edit_link), esc_html($item['post_title']));
    }

    protected function column_http_status($item) {
        $raw_status = $item['http_status'] ?? null;

        if ($raw_status === null || $raw_status === '') {
            return $this->get_column_label_html('http_status') . esc_html__('—', 'liens-morts-detector-jlg');
        }

        if (is_numeric($raw_status)) {
            $raw_status = (int) $raw_status;
        }

        return $this->get_column_label_html('http_status') . esc_html((string) $raw_status);
    }

    protected function column_last_checked_at($item) {
        $raw_value = $item['last_checked_at'] ?? '';

        return $this->get_column_label_html('last_checked_at') . $this->format_last_checked_at_for_display($raw_value);
    }

    /**
     * Gère le rendu de la colonne "Actions".
     */
    protected function column_actions($item) {
        $edit_link = get_edit_post_link($item['post_id']);

        if ($edit_link === false) {
            return $this->get_column_label_html('actions') . esc_html__('—', 'liens-morts-detector-jlg');
        }

        return $this->get_column_label_html('actions') . sprintf(
            '<a href="%s" class="button button-secondary">%s</a>',
            esc_url($edit_link),
            esc_html__('Modifier l\'article', 'liens-morts-detector-jlg')
        );
    }

    protected function get_column_label_html($column_name) {
        if ($this->column_labels_cache === null) {
            $this->column_labels_cache = $this->get_columns();
        }

        $label = '';
        if (is_array($this->column_labels_cache) && array_key_exists($column_name, $this->column_labels_cache)) {
            $label = (string) $this->column_labels_cache[$column_name];
        }

        if ($label === '') {
            return '';
        }

        return sprintf('<span class="blc-column-label">%s</span>', esc_html($label));
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
}
