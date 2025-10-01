<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

// On s'assure que la classe WP_List_Table est bien disponible
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

if (!function_exists('blc_get_dataset_row_types')) {
    require_once __DIR__ . '/blc-utils.php';
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
        $image_url = isset($item['url']) && is_string($item['url']) ? trim($item['url']) : '';
        $anchor_text = isset($item['anchor']) && is_string($item['anchor']) ? trim($item['anchor']) : '';
        $alt_text = $anchor_text !== ''
            ? $anchor_text
            : esc_html__("Aperçu de l'image cassée", 'liens-morts-detector-jlg');

        $attachment_id = 0;
        if (isset($item['attachment_id']) && is_numeric($item['attachment_id'])) {
            $attachment_id = (int) $item['attachment_id'];
        }

        if ($attachment_id === 0 && $image_url !== '' && function_exists('attachment_url_to_postid')) {
            $maybe_id = attachment_url_to_postid($image_url);
            if (is_numeric($maybe_id)) {
                $attachment_id = (int) $maybe_id;
            }
        }

        $thumbnail_html = '';
        if ($attachment_id > 0 && function_exists('wp_get_attachment_image')) {
            $thumbnail_html = wp_get_attachment_image(
                $attachment_id,
                'thumbnail',
                false,
                [
                    'class'        => 'blc-image-preview__img',
                    'alt'          => $alt_text,
                    'aria-hidden'  => 'false',
                    'loading'      => 'lazy',
                    'decoding'     => 'async',
                ]
            );
            if (!is_string($thumbnail_html)) {
                $thumbnail_html = '';
            }
        }

        if ($thumbnail_html === '' && $image_url !== '') {
            $thumbnail_html = sprintf(
                '<img src="%s" alt="%s" aria-hidden="false" loading="lazy" decoding="async" class="blc-image-preview__img" />',
                esc_url($image_url),
                esc_attr($alt_text)
            );
        }

        $preview_markup = '';
        if ($thumbnail_html !== '') {
            $preview_markup = sprintf('<div class="blc-image-preview">%s</div>', $thumbnail_html);
        }

        $link_markup = '';
        if ($image_url !== '') {
            $link_markup = sprintf(
                '<strong><a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a></strong>',
                esc_url($image_url),
                esc_attr__('Vérifier cette image (nouvel onglet)', 'liens-morts-detector-jlg'),
                esc_html($image_url)
            );
        }

        $details_markup = $link_markup;
        $details_markup .= sprintf(
            '<div class="row-actions"><span>%s <em>%s</em></span></div>',
            esc_html__('Nom du fichier :', 'liens-morts-detector-jlg'),
            esc_html($anchor_text)
        );

        if ($preview_markup === '') {
            return $details_markup;
        }

        return sprintf(
            '<div class="blc-image-details">%s<div class="blc-image-details__content">%s</div></div>',
            $preview_markup,
            $details_markup
        );
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

        $image_row_types = blc_get_dataset_row_types('image');
        if ($image_row_types === []) {
            $this->set_pagination_args(['total_items' => 0, 'per_page' => $per_page]);
            $this->items = [];

            return;
        }

        if (count($image_row_types) === 1) {
            $total_items = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE type = %s",
                    reset($image_row_types)
                )
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($image_row_types), '%s'));
            $total_items = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE type IN ($placeholders)",
                    $image_row_types
                )
            );
        }

        $offset = ($current_page - 1) * $per_page;

        if (count($image_row_types) === 1) {
            $data_query = $wpdb->prepare(
                "SELECT url, anchor, post_id, post_title, http_status, last_checked_at
                 FROM $table_name
                 WHERE type = %s
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d",
                [reset($image_row_types), $per_page, $offset]
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($image_row_types), '%s'));
            $args = array_merge($image_row_types, [$per_page, $offset]);
            $data_query = $wpdb->prepare(
                "SELECT url, anchor, post_id, post_title, http_status, last_checked_at
                 FROM $table_name
                 WHERE type IN ($placeholders)
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d",
                $args
            );
        }
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
