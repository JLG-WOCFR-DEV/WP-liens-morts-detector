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

    private $bulk_notice = null;

    private static $bulk_query_args_registered = false;

    private $current_orderby = 'id';

    private $current_order = 'desc';

    /**
     * Cache local pour les compteurs agrégés des liens.
     *
     * @var array<string,int>|null
     */
    private $status_counts_cache = null;

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

        if (!self::$bulk_query_args_registered) {
            add_filter('removable_query_args', [__CLASS__, 'filter_removable_query_args']);
            self::$bulk_query_args_registered = true;
        }
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

        $available_post_types = $this->get_available_post_types();
        $selected_post_type  = $this->get_selected_post_type();

        if (!empty($available_post_types)) {
            echo '<div class="alignleft actions">';
            echo '<label for="blc-post-type-filter" class="blc-filter__label">' . esc_html__('Filtrer par type de contenu', 'liens-morts-detector-jlg') . '</label>';

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

        $counts = $this->get_status_counts();

        $active_count       = isset($counts['active_count']) ? (int) $counts['active_count'] : 0;
        $ignored_count      = isset($counts['ignored_count']) ? (int) $counts['ignored_count'] : 0;
        $internal_count     = isset($counts['internal_count']) ? (int) $counts['internal_count'] : 0;
        $external_count     = max(0, $active_count - $internal_count);
        $not_found_count    = isset($counts['not_found_count']) ? (int) $counts['not_found_count'] : 0;
        $server_error_count = isset($counts['server_error_count']) ? (int) $counts['server_error_count'] : 0;
        $redirect_count     = isset($counts['redirect_count']) ? (int) $counts['redirect_count'] : 0;
        $needs_recheck_count = isset($counts['needs_recheck_count']) ? (int) $counts['needs_recheck_count'] : 0;

        $total_count = $active_count;
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

        $ignored_class = ($current === 'ignored' ? 'current' : '');
        $views['ignored'] = sprintf(
            "<a href='%s' class='%s'>%s <span class='count'>(%d)</span></a>",
            esc_url(add_query_arg('link_type', 'ignored')),
            esc_attr($ignored_class),
            esc_html__('Ignorés', 'liens-morts-detector-jlg'),
            $ignored_count
        );

        return $views;
    }

    /**
     * Retourne et met en cache les compteurs agrégés utilisés par la vue et le tableau de bord.
     *
     * @return array<string,int>
     */
    public function get_status_counts() {
        if (is_array($this->status_counts_cache)) {
            return $this->status_counts_cache;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . 'blc_broken_links';
        $internal_condition = $this->build_internal_url_condition();
        $legacy_case        = $internal_condition['case_template'];
        $legacy_params      = $internal_condition['case_params'];
        $needs_recheck_clause = '(last_checked_at IS NULL OR last_checked_at = %s OR last_checked_at <= %s)';
        $needs_recheck_params = [$this->get_unchecked_sentinel_value(), $this->get_recheck_threshold_gmt()];

        $counts_query = $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN ignored_at IS NULL THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN ignored_at IS NOT NULL THEN 1 ELSE 0 END) AS ignored_count,
                SUM(
                    CASE
                        WHEN ignored_at IS NOT NULL THEN 0
                        WHEN is_internal IS NOT NULL THEN is_internal
                        ELSE $legacy_case
                    END
                ) AS internal_count,
                SUM(CASE WHEN ignored_at IS NULL AND http_status IN (404, 410) THEN 1 ELSE 0 END) AS not_found_count,
                SUM(CASE WHEN ignored_at IS NULL AND http_status BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS server_error_count,
                SUM(CASE WHEN ignored_at IS NULL AND http_status BETWEEN 300 AND 399 THEN 1 ELSE 0 END) AS redirect_count,
                SUM(CASE WHEN ignored_at IS NULL AND $needs_recheck_clause THEN 1 ELSE 0 END) AS needs_recheck_count
             FROM $table_name
             WHERE type = %s",
            array_merge($legacy_params, $needs_recheck_params, ['link'])
        );

        $counts = $wpdb->get_row($counts_query, ARRAY_A);

        if (!is_array($counts)) {
            $counts = [];
        }

        $defaults = array(
            'active_count'        => 0,
            'ignored_count'       => 0,
            'internal_count'      => 0,
            'not_found_count'     => 0,
            'server_error_count'  => 0,
            'redirect_count'      => 0,
            'needs_recheck_count' => 0,
        );

        $this->status_counts_cache = array_map('intval', wp_parse_args($counts, $defaults));

        return $this->status_counts_cache;
    }

    /**
     * Définit les colonnes du tableau, avec une nouvelle colonne pour le texte du lien.
     */
    public function get_columns() {
        $select_all_label = esc_attr__('Sélectionner tous les liens morts', 'liens-morts-detector-jlg');

        return [
            'cb'           => sprintf('<input type="checkbox" aria-label="%s" />', $select_all_label),
            'url'          => __('URL Cassée', 'liens-morts-detector-jlg'),
            'anchor_text'  => __('Texte du lien', 'liens-morts-detector-jlg'),
            'context_excerpt' => __('Contexte', 'liens-morts-detector-jlg'),
            'http_status'  => __('Statut HTTP', 'liens-morts-detector-jlg'),
            'redirect_target_url' => __('Cible détectée', 'liens-morts-detector-jlg'),
            'last_checked_at' => __('Dernier contrôle', 'liens-morts-detector-jlg'),
            'post_title'   => __('Trouvé dans l\'article/page', 'liens-morts-detector-jlg'),
            'actions'      => __('Actions', 'liens-morts-detector-jlg')
        ];
    }

    protected function get_sortable_columns() {
        return [
            'url'                 => ['url', false],
            'anchor_text'         => ['anchor_text', false],
            'http_status'         => ['http_status', false],
            'redirect_target_url' => ['redirect_target_url', false],
            'last_checked_at'     => ['last_checked_at', false],
            'post_title'          => ['post_title', false],
            'post_type'           => ['post_type', false],
        ];
    }

    public function get_current_orderby() {
        return $this->current_orderby;
    }

    public function get_current_order() {
        return $this->current_order;
    }

    /**
     * Rendu de la colonne de cases à cocher utilisée pour les actions groupées.
     *
     * @param array $item L'élément actuel du tableau.
     *
     * @return string
     */
    protected function column_cb($item) {
        $row_id = isset($item['id']) ? absint($item['id']) : 0;

        if ($row_id <= 0) {
            return '';
        }

        $aria_label = esc_attr__('Sélectionner ce lien mort', 'liens-morts-detector-jlg');

        return sprintf('<input type="checkbox" name="link_ids[]" value="%1$d" aria-label="%2$s" />', $row_id, $aria_label);
    }

    /**
     * Liste les actions disponibles pour les actions groupées.
     *
     * @return array
     */
    protected function get_bulk_actions() {
        $actions = [
            'ignore'         => __('Ignorer', 'liens-morts-detector-jlg'),
            'restore'        => __('Ne plus ignorer', 'liens-morts-detector-jlg'),
            'unlink'         => __('Dissocier', 'liens-morts-detector-jlg'),
            'apply_redirect' => __('Appliquer la redirection détectée', 'liens-morts-detector-jlg'),
        ];

        return apply_filters('blc_links_list_table_bulk_actions', $actions);
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

    protected function column_context_excerpt($item) {
        $excerpt = isset($item['context_excerpt']) ? (string) $item['context_excerpt'] : '';
        if ($excerpt === '') {
            return esc_html__('—', 'liens-morts-detector-jlg');
        }

        $tooltip_source = isset($item['context_html']) ? (string) $item['context_html'] : '';
        if ($tooltip_source === '') {
            $tooltip_source = $excerpt;
        }

        if (function_exists('wp_strip_all_tags')) {
            $tooltip_source = wp_strip_all_tags($tooltip_source);
        }

        $row_id = isset($item['id']) ? absint($item['id']) : 0;
        $occurrence_index = isset($item['occurrence_index']) ? absint($item['occurrence_index']) : 0;

        $identifier_parts = [];
        if ($row_id > 0) {
            $identifier_parts[] = 'r' . $row_id;
        }
        if ($occurrence_index > 0) {
            $identifier_parts[] = 'o' . $occurrence_index;
        }

        if (empty($identifier_parts)) {
            $identifier_parts[] = 'f' . substr(md5($excerpt), 0, 8);
        }

        $description_id = 'blc-context-excerpt-desc-' . implode('-', $identifier_parts);

        $description_id_attr = esc_attr($description_id);
        $full_excerpt_attr   = esc_attr($tooltip_source);
        $full_excerpt_text   = esc_html($tooltip_source);
        $excerpt_text        = esc_html($excerpt);

        return sprintf(
            '<span class="blc-context-excerpt-wrapper" aria-describedby="%1$s"><span class="blc-context-excerpt" title="%2$s" data-full-excerpt="%2$s">%3$s</span><span id="%1$s" class="screen-reader-text">%4$s</span></span>',
            $description_id_attr,
            $full_excerpt_attr,
            $excerpt_text,
            $full_excerpt_text
        );
    }

    protected function column_redirect_target_url($item) {
        $target = isset($item['redirect_target_url']) ? (string) $item['redirect_target_url'] : '';
        if ($target === '') {
            return esc_html__('—', 'liens-morts-detector-jlg');
        }

        $sanitized_url = esc_url($target);
        if ($sanitized_url === '') {
            return esc_html($target);
        }

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
            $sanitized_url,
            esc_attr__('Ouvrir la cible détectée dans un nouvel onglet', 'liens-morts-detector-jlg'),
            esc_html($target)
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

        $classes = ['blc-status'];
        $label = (string) $raw_status;
        $status_category_labels = [
            '1xx'     => __('Réponse informative (1xx)', 'liens-morts-detector-jlg'),
            '2xx'     => __('Disponible (2xx)', 'liens-morts-detector-jlg'),
            '3xx'     => __('Redirection (3xx)', 'liens-morts-detector-jlg'),
            '4xx'     => __('Erreur client (4xx)', 'liens-morts-detector-jlg'),
            '5xx'     => __('Erreur serveur (5xx)', 'liens-morts-detector-jlg'),
            'unknown' => __('Statut inconnu ou indisponible', 'liens-morts-detector-jlg'),
        ];
        $status_description = $status_category_labels['unknown'];

        if (is_numeric($raw_status)) {
            $status_code = (int) $raw_status;
            $label = (string) $status_code;

            $range_key = sprintf('%dxx', (int) floor($status_code / 100));
            if (isset($status_category_labels[$range_key])) {
                $classes[] = 'blc-status--' . $range_key;
                $status_description = $status_category_labels[$range_key];
            } else {
                $classes[] = 'blc-status--unknown';
            }

            $classes[] = 'blc-status--' . $status_code;
        } else {
            $classes[] = 'blc-status--unknown';
            $classes[] = 'blc-status--' . strtolower((string) $raw_status);
        }

        $sanitized_classes = array_map(
            static function ($class) {
                if (function_exists('sanitize_html_class')) {
                    return sanitize_html_class($class);
                }

                return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $class);
            },
            $classes
        );

        $class_attribute = implode(' ', array_filter(array_unique($sanitized_classes)));

        return sprintf(
            '<span class="%1$s" aria-label="%3$s" title="%3$s">%2$s</span>',
            esc_attr($class_attribute),
            esc_html($label),
            esc_attr($status_description)
        );
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

        $detected_target = isset($item['redirect_target_url']) ? (string) $item['redirect_target_url'] : '';
        if ($detected_target !== '') {
            $data_attributes[] = sprintf('data-detected-target="%s"', esc_attr($detected_target));
        }

        $context_excerpt = isset($item['context_excerpt']) ? (string) $item['context_excerpt'] : '';
        if ($context_excerpt !== '') {
            $data_attributes[] = sprintf('data-context-excerpt="%s"', esc_attr($context_excerpt));
        }

        $context_html = isset($item['context_html']) ? (string) $item['context_html'] : '';
        if ($context_html !== '') {
            $data_attributes[] = sprintf('data-context-html="%s"', esc_attr($context_html));
        }

        if (isset($item['http_status']) && $item['http_status'] !== null && $item['http_status'] !== '') {
            $data_attributes[] = sprintf('data-http-status="%s"', esc_attr((string) $item['http_status']));
        }

        $data_attributes = implode(' ', $data_attributes);

        $edit_nonce    = wp_create_nonce('blc_edit_link_nonce');
        $ignore_nonce  = wp_create_nonce('blc_ignore_link_nonce');
        $unlink_nonce  = wp_create_nonce('blc_unlink_nonce');
        $recheck_nonce = wp_create_nonce('blc_recheck_link_nonce');

        $actions['edit_link'] = sprintf(
            '<button type="button" class="button button-small button-link blc-edit-link" %s data-nonce="%s">%s</button>',
            $data_attributes,
            $edit_nonce,
            esc_html__('Modifier', 'liens-morts-detector-jlg')
        );

        $actions['suggest_redirect'] = sprintf(
            '<button type="button" class="button button-small button-link blc-suggest-redirect" %s data-nonce="%s">%s</button>',
            $data_attributes,
            $edit_nonce,
            esc_html__('Proposer une redirection', 'liens-morts-detector-jlg')
        );

        if ($detected_target !== '') {
            $apply_redirect_nonce = wp_create_nonce('blc_apply_detected_redirect_nonce');

            $actions['apply_redirect'] = sprintf(
                '<button type="button" class="button button-small button-link blc-apply-redirect" %s data-nonce="%s">%s</button>',
                $data_attributes,
                esc_attr($apply_redirect_nonce),
                esc_html__('Appliquer la redirection détectée', 'liens-morts-detector-jlg')
            );
        }

        $actions['view_context'] = sprintf(
            '<button type="button" class="button button-small button-link blc-view-context" %s>%s</button>',
            $data_attributes,
            esc_html__('Voir le contexte', 'liens-morts-detector-jlg')
        );

        $actions['recheck'] = sprintf(
            '<button type="button" class="button button-small button-link blc-recheck" %s data-nonce="%s">%s</button>',
            $data_attributes,
            $recheck_nonce,
            esc_html__('Re-vérifier', 'liens-morts-detector-jlg')
        );

        $ignored_raw = $item['ignored_at'] ?? null;
        $is_ignored = false;
        if (is_string($ignored_raw)) {
            $normalized_ignored = trim($ignored_raw);
            $is_ignored = ($normalized_ignored !== '' && $normalized_ignored !== '0000-00-00 00:00:00');
        } elseif ($ignored_raw !== null) {
            $is_ignored = true;
        }

        $ignore_mode = $is_ignored ? 'restore' : 'ignore';
        $ignore_label = $is_ignored
            ? esc_html__('Ne plus ignorer', 'liens-morts-detector-jlg')
            : esc_html__('Ignorer', 'liens-morts-detector-jlg');

        $actions['ignore'] = sprintf(
            '<button type="button" class="button button-small button-link blc-ignore" %s data-ignore-mode="%s" data-nonce="%s">%s</button>',
            $data_attributes,
            esc_attr($ignore_mode),
            $ignore_nonce,
            $ignore_label
        );
        $actions['unlink'] = sprintf(
            '<button type="button" class="button button-small button-link blc-unlink" %s data-nonce="%s" style="color:#a00;">%s</button>',
            $data_attributes,
            $unlink_nonce,
            esc_html__('Dissocier', 'liens-morts-detector-jlg')
        );
        return $actions;
    }

    /**
     * Prépare les données pour l'affichage : récupération, filtrage, et pagination.
     */
    public function prepare_items($data = null, $total_items_override = null) {
        $this->process_bulk_action();
        $this->maybe_prepare_bulk_notice_from_query();

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $current_view = (!empty($_GET['link_type'])) ? sanitize_text_field(wp_unslash($_GET['link_type'])) : 'all';
        $per_page     = 20;
        $current_page = max(1, (int) $this->get_pagenum());
        $search_term  = $this->get_search_term();

        $available_post_types = $this->get_available_post_types();
        $selected_post_type  = $this->get_selected_post_type();

        if (is_array($data)) {
            $total_items = ($total_items_override !== null) ? (int) $total_items_override : count($data);
            $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
            $this->items = array_slice($data, (($current_page - 1) * $per_page), $per_page);
            return;
        }

        global $wpdb;
        $table_name  = $wpdb->prefix . 'blc_broken_links';
        $posts_table = $wpdb->posts;
        $join_clause = "LEFT JOIN {$posts_table} AS posts ON {$table_name}.post_id = posts.ID";
        $internal_condition = $this->build_internal_url_condition();
        $internal_sql       = $internal_condition['sql'];
        $internal_params    = $internal_condition['params'];
        $external_sql       = $internal_condition['not_sql'];
        $external_params    = $internal_condition['not_params'];

        $is_ignored_view = ($current_view === 'ignored');

        $where  = ['type = %s'];
        $params = ['link'];

        if ($search_term !== '') {
            $like = '%' . $wpdb->esc_like($search_term) . '%';
            $where[] = '(url LIKE %s OR anchor LIKE %s OR post_title LIKE %s)';
            $params  = array_merge($params, [$like, $like, $like]);
        }

        if ($selected_post_type !== '' && in_array($selected_post_type, $available_post_types, true)) {
            $where[] = 'posts.post_type = %s';
            $params[] = $selected_post_type;
        }

        if ($is_ignored_view) {
            $where[] = 'ignored_at IS NOT NULL';
        } else {
            $where[] = 'ignored_at IS NULL';
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
            "SELECT COUNT(*) FROM {$table_name} {$join_clause} WHERE $where_clause",
            $params
        );
        $total_items = (int) $wpdb->get_var($total_query);

        $offset = ($current_page - 1) * $per_page;

        $requested_orderby = '';
        if (isset($_GET['orderby'])) {
            $requested_orderby = sanitize_key(wp_unslash($_GET['orderby']));
        }

        $requested_order = '';
        if (isset($_GET['order'])) {
            $requested_order = sanitize_key(wp_unslash($_GET['order']));
        }

        $allowed_orders = ['asc', 'desc'];
        if (!in_array($requested_order, $allowed_orders, true)) {
            $requested_order = 'desc';
        }

        $order_columns = [
            'id'                 => $table_name . '.id',
            'url'                => $table_name . '.url',
            'anchor_text'        => $table_name . '.anchor',
            'http_status'        => $table_name . '.http_status',
            'redirect_target_url'=> $table_name . '.redirect_target_url',
            'last_checked_at'    => $table_name . '.last_checked_at',
            'post_title'         => 'COALESCE(posts.post_title, ' . $table_name . '.post_title)',
            'post_type'          => 'posts.post_type',
            'ignored_at'         => $table_name . '.ignored_at',
        ];

        $default_orderby = $is_ignored_view ? 'ignored_at' : 'id';
        if (!isset($order_columns[$requested_orderby])) {
            $requested_orderby = $default_orderby;
        }

        $this->current_orderby = $requested_orderby;
        $this->current_order = $requested_order;

        $direction_sql = ($requested_order === 'asc') ? 'ASC' : 'DESC';

        $order_by_parts = [];
        $order_by_parts[] = $order_columns[$requested_orderby] . ' ' . $direction_sql;

        if ($is_ignored_view && $requested_orderby !== 'ignored_at') {
            $order_by_parts[] = $order_columns['ignored_at'] . ' DESC';
        }

        if ($requested_orderby !== 'id') {
            $order_by_parts[] = $order_columns['id'] . ' DESC';
        }

        $order_by = implode(', ', $order_by_parts);

        $data_query = $wpdb->prepare(
            "SELECT id, occurrence_index, url, anchor, redirect_target_url, context_html, context_excerpt, post_id, post_title, http_status, last_checked_at, ignored_at, posts.post_type AS post_type"
            . " FROM {$table_name}"
            . " {$join_clause}"
            . " WHERE $where_clause"
            . " ORDER BY $order_by"
            . " LIMIT %d OFFSET %d",
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

    private function get_available_post_types() {
        if (!function_exists('get_post_types')) {
            return [];
        }

        $post_types = get_post_types(['public' => true]);
        if (!is_array($post_types)) {
            return [];
        }

        $available_post_types = [];

        foreach ($post_types as $post_type) {
            if (is_string($post_type) && $post_type !== '') {
                $available_post_types[] = $post_type;
            }
        }

        return array_values(array_unique($available_post_types));
    }

    private function get_selected_post_type() {
        if (!isset($_GET['post_type'])) {
            return '';
        }

        $raw_value = $_GET['post_type'];
        if (!is_string($raw_value)) {
            return '';
        }

        if (function_exists('wp_unslash')) {
            $raw_value = wp_unslash($raw_value);
        }

        if (function_exists('sanitize_key')) {
            $raw_value = sanitize_key($raw_value);
        }

        return $raw_value === '' ? '' : $raw_value;
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

    protected function process_bulk_action() {
        $action = $this->current_action();

        if (!in_array($action, ['ignore', 'restore', 'unlink', 'apply_redirect'], true)) {
            return;
        }

        $ids = $this->get_requested_bulk_ids();

        check_admin_referer('bulk-' . $this->_args['plural']);

        if ($ids === []) {
            $notice = [
                'message'      => __('Veuillez sélectionner au moins un lien avant d\'appliquer une action groupée.', 'liens-morts-detector-jlg'),
                'type'         => 'warning',
                'announcement' => __('Aucune action groupée appliquée : aucun lien sélectionné.', 'liens-morts-detector-jlg'),
            ];

            $this->redirect_after_bulk($notice);
        }

        if ($action === 'unlink') {
            $notice = $this->handle_bulk_unlink($ids);
        } elseif ($action === 'apply_redirect') {
            $notice = $this->handle_bulk_apply_redirect($ids);
        } else {
            $notice = $this->handle_bulk_ignore($ids, $action);
        }

        $this->redirect_after_bulk($notice);
    }

    private function maybe_prepare_bulk_notice_from_query() {
        if ($this->bulk_notice !== null) {
            return;
        }

        if (!isset($_GET['blc-bulk-message'])) {
            return;
        }

        $raw_message = $_GET['blc-bulk-message'];
        if (!is_string($raw_message)) {
            return;
        }

        if (function_exists('wp_unslash')) {
            $raw_message = wp_unslash($raw_message);
        }

        $message = sanitize_text_field($raw_message);

        if ($message === '') {
            return;
        }

        $type = 'success';

        if (isset($_GET['blc-bulk-type'])) {
            $raw_type = $_GET['blc-bulk-type'];
            if (is_string($raw_type)) {
                if (function_exists('wp_unslash')) {
                    $raw_type = wp_unslash($raw_type);
                }
                $candidate = sanitize_key($raw_type);
                if (in_array($candidate, ['success', 'warning', 'error', 'info'], true)) {
                    $type = $candidate;
                }
            }
        }

        $announcement = '';

        if (isset($_GET['blc-bulk-announcement'])) {
            $raw_announcement = $_GET['blc-bulk-announcement'];
            if (is_string($raw_announcement)) {
                if (function_exists('wp_unslash')) {
                    $raw_announcement = wp_unslash($raw_announcement);
                }
                $announcement = sanitize_text_field($raw_announcement);
            }
        }

        if ($announcement === '') {
            $announcement = $message;
        }

        $this->bulk_notice = [
            'message'      => $message,
            'class'        => 'notice-' . $type,
            'announcement' => $announcement,
        ];

        add_action('admin_notices', [$this, 'render_bulk_notice']);
    }

    public function render_bulk_notice() {
        if ($this->bulk_notice === null) {
            return;
        }

        $type_class = isset($this->bulk_notice['class']) ? trim((string) $this->bulk_notice['class']) : 'notice-success';
        if ($type_class === '') {
            $type_class = 'notice-success';
        }

        $classes = sprintf('notice %s blc-bulk-notice is-dismissible', $type_class);
        $announcement = isset($this->bulk_notice['announcement']) ? (string) $this->bulk_notice['announcement'] : '';
        $announcement_attribute = $announcement !== ''
            ? sprintf(' data-blc-bulk-announcement="%s"', esc_attr($announcement))
            : '';

        printf(
            '<div class="%1$s" role="status" aria-live="polite"%3$s><p>%2$s</p></div>',
            esc_attr($classes),
            esc_html($this->bulk_notice['message']),
            $announcement_attribute
        );
    }

    public static function filter_removable_query_args($args) {
        $args[] = 'blc-bulk-message';
        $args[] = 'blc-bulk-type';
        $args[] = 'blc-bulk-announcement';

        return array_values(array_unique($args));
    }

    private function get_requested_bulk_ids() {
        if (!isset($_REQUEST['link_ids'])) {
            return [];
        }

        $raw_values = $_REQUEST['link_ids'];
        if (!is_array($raw_values)) {
            $raw_values = [$raw_values];
        }

        if (function_exists('wp_unslash')) {
            $raw_values = wp_unslash($raw_values);
        }

        $ids = [];

        foreach ($raw_values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $id = absint($value);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function redirect_after_bulk(array $notice) {
        $message = isset($notice['message']) ? sanitize_text_field($notice['message']) : '';
        $type = isset($notice['type']) ? sanitize_key($notice['type']) : 'success';

        if (!in_array($type, ['success', 'warning', 'error', 'info'], true)) {
            $type = 'success';
        }

        $announcement = isset($notice['announcement']) ? sanitize_text_field($notice['announcement']) : $message;

        $removals = [
            'action',
            'action2',
            'link_ids',
            'link_ids[]',
            'link_ids%5B%5D',
            '_wpnonce',
            '_wp_http_referer',
            'blc-bulk-message',
            'blc-bulk-type',
            'blc-bulk-announcement',
        ];

        $redirect = remove_query_arg($removals, add_query_arg([]));

        if ($message !== '') {
            $query_args = [
                'blc-bulk-message'      => $message,
                'blc-bulk-type'         => $type,
                'blc-bulk-announcement' => $announcement !== '' ? $announcement : $message,
            ];

            $redirect = add_query_arg($query_args, $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    private function handle_bulk_ignore(array $ids, $mode) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'blc_broken_links';

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $query_params = array_merge($ids, ['link']);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id, url, anchor, post_title, ignored_at FROM $table_name WHERE id IN ($placeholders) AND type = %s",
                $query_params
            ),
            ARRAY_A
        );

        $missing = max(0, count($ids) - count($rows));
        $permission_denied = 0;
        $already_count = 0;
        $ids_to_update = [];
        $bytes_delta = 0;

        foreach ($rows as $row) {
            $row_id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($row_id <= 0) {
                continue;
            }

            $post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
            if (!$this->user_can_manage_row($post_id)) {
                $permission_denied++;
                continue;
            }

            $is_ignored = $this->is_row_ignored($row);

            if ($mode === 'ignore') {
                if ($is_ignored) {
                    $already_count++;
                    continue;
                }

                $ids_to_update[] = $row_id;
                $bytes_delta -= $this->calculate_row_footprint($row);
            } else {
                if (!$is_ignored) {
                    $already_count++;
                    continue;
                }

                $ids_to_update[] = $row_id;
                $bytes_delta += $this->calculate_row_footprint($row);
            }
        }

        $success_count = 0;
        $db_error = '';

        if (!empty($ids_to_update)) {
            $update_placeholders = implode(', ', array_fill(0, count($ids_to_update), '%d'));

            if ($mode === 'ignore') {
                $timestamp = current_time('mysql', true);
                $sql = "UPDATE $table_name SET ignored_at = %s WHERE id IN ($update_placeholders) AND type = %s";
                $params = array_merge([$timestamp], $ids_to_update, ['link']);
            } else {
                $sql = "UPDATE $table_name SET ignored_at = NULL WHERE id IN ($update_placeholders) AND type = %s";
                $params = array_merge($ids_to_update, ['link']);
            }

            $updated = $wpdb->query($wpdb->prepare($sql, $params));

            if ($updated === false) {
                $db_error = $wpdb->last_error;
            } else {
                $success_count = (int) $updated;

                if ($success_count > 0) {
                    if ($bytes_delta !== 0) {
                        blc_adjust_dataset_storage_footprint('link', $bytes_delta);
                    }

                    blc_mark_link_view_counts_dirty();
                }
            }
        }

        $message_parts = [];

        if ($success_count > 0) {
            if ($mode === 'ignore') {
                $message_parts[] = sprintf(
                    _n('%d lien a été ignoré.', '%d liens ont été ignorés.', $success_count, 'liens-morts-detector-jlg'),
                    $success_count
                );
            } else {
                $message_parts[] = sprintf(
                    _n('%d lien n\'est plus ignoré.', '%d liens ne sont plus ignorés.', $success_count, 'liens-morts-detector-jlg'),
                    $success_count
                );
            }
        }

        if ($already_count > 0) {
            $message_parts[] = sprintf(
                _n('%d lien était déjà dans cet état.', '%d liens étaient déjà dans cet état.', $already_count, 'liens-morts-detector-jlg'),
                $already_count
            );
        }

        if ($permission_denied > 0) {
            $message_parts[] = sprintf(
                _n('Permissions insuffisantes pour %d lien.', 'Permissions insuffisantes pour %d liens.', $permission_denied, 'liens-morts-detector-jlg'),
                $permission_denied
            );
        }

        if ($missing > 0) {
            $message_parts[] = sprintf(
                _n('%d lien sélectionné est introuvable.', '%d liens sélectionnés sont introuvables.', $missing, 'liens-morts-detector-jlg'),
                $missing
            );
        }

        if ($db_error !== '') {
            $message_parts[] = __('Une erreur de base de données est survenue lors de la mise à jour des liens.', 'liens-morts-detector-jlg');
        }

        if (empty($message_parts)) {
            if ($mode === 'ignore') {
                $message_parts[] = __('Aucun lien n\'a été ignoré.', 'liens-morts-detector-jlg');
            } else {
                $message_parts[] = __('Aucun lien n\'a été réintégré.', 'liens-morts-detector-jlg');
            }
        }

        $type = 'success';

        if ($success_count === 0) {
            if ($db_error !== '' || $permission_denied > 0 || $missing > 0) {
                $type = 'error';
            } elseif ($already_count > 0) {
                $type = 'info';
            } else {
                $type = 'warning';
            }
        } elseif ($db_error !== '' || $permission_denied > 0 || $missing > 0) {
            $type = 'warning';
        }

        $announcement = $message_parts[0];

        return [
            'message'      => implode(' ', $message_parts),
            'type'         => $type,
            'announcement' => $announcement,
        ];
    }

    private function handle_bulk_unlink(array $ids) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'blc_broken_links';

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $query_params = array_merge($ids, ['link']);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id, url, anchor, post_title, occurrence_index FROM $table_name WHERE id IN ($placeholders) AND type = %s",
                $query_params
            ),
            ARRAY_A
        );

        $missing = max(0, count($ids) - count($rows));
        $permission_denied = 0;
        $processing_failures = 0;
        $success_count = 0;
        $bytes_delta = 0;

        foreach ($rows as $row) {
            $row_id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($row_id <= 0) {
                continue;
            }

            $post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
            $occurrence_index = $this->normalize_occurrence_index($row['occurrence_index'] ?? null);
            $stored_url = isset($row['url']) ? blc_prepare_posted_url($row['url']) : '';

            if ($stored_url === '') {
                $processing_failures++;
                continue;
            }

            $post = null;
            if ($post_id > 0) {
                $post = get_post($post_id);
            }

            if ($post instanceof WP_Post) {
                if (!current_user_can('edit_post', $post_id)) {
                    $permission_denied++;
                    continue;
                }

                $normalized_content = blc_normalize_post_content_encoding($post->post_content);
                $removal = blc_remove_link_wrappers_from_content($normalized_content, $stored_url, $occurrence_index);

                if (!is_array($removal) || empty($removal['removed']) || !array_key_exists('content', $removal)) {
                    $processing_failures++;
                    continue;
                }

                $new_content = blc_restore_post_content_encoding($removal['content']);
                $update_result = wp_update_post(
                    [
                        'ID'           => $post_id,
                        'post_content' => wp_slash($new_content),
                    ],
                    true
                );

                if (!$update_result || is_wp_error($update_result)) {
                    $processing_failures++;
                    continue;
                }
            } else {
                if (!current_user_can('manage_options')) {
                    $permission_denied++;
                    continue;
                }
            }

            $deleted = $wpdb->delete($table_name, ['id' => $row_id], ['%d']);

            if ($deleted === false) {
                $processing_failures++;
                continue;
            }

            $bytes_delta -= $this->calculate_row_footprint($row);
            $success_count++;
        }

        if ($success_count > 0) {
            blc_mark_link_view_counts_dirty();
            if ($bytes_delta !== 0) {
                blc_adjust_dataset_storage_footprint('link', $bytes_delta);
            }
        }

        $message_parts = [];

        if ($success_count > 0) {
            $message_parts[] = sprintf(
                _n('%d lien a été dissocié.', '%d liens ont été dissociés.', $success_count, 'liens-morts-detector-jlg'),
                $success_count
            );
        }

        if ($processing_failures > 0) {
            $message_parts[] = sprintf(
                _n('%d lien n\'a pas pu être dissocié en raison d\'une erreur.', '%d liens n\'ont pas pu être dissociés en raison d\'erreurs.', $processing_failures, 'liens-morts-detector-jlg'),
                $processing_failures
            );
        }

        if ($permission_denied > 0) {
            $message_parts[] = sprintf(
                _n('Permissions insuffisantes pour %d lien.', 'Permissions insuffisantes pour %d liens.', $permission_denied, 'liens-morts-detector-jlg'),
                $permission_denied
            );
        }

        if ($missing > 0) {
            $message_parts[] = sprintf(
                _n('%d lien sélectionné est introuvable.', '%d liens sélectionnés sont introuvables.', $missing, 'liens-morts-detector-jlg'),
                $missing
            );
        }

        if (empty($message_parts)) {
            $message_parts[] = __('Aucun lien n\'a été dissocié.', 'liens-morts-detector-jlg');
        }

        $type = 'success';

        if ($success_count === 0) {
            if ($processing_failures > 0 || $permission_denied > 0 || $missing > 0) {
                $type = 'error';
            } else {
                $type = 'warning';
            }
        } elseif ($processing_failures > 0 || $permission_denied > 0 || $missing > 0) {
            $type = 'warning';
        }

        $announcement = $message_parts[0];

        return [
            'message'      => implode(' ', $message_parts),
            'type'         => $type,
            'announcement' => $announcement,
        ];
    }

    private function handle_bulk_apply_redirect(array $ids) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'blc_broken_links';

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $query_params = array_merge($ids, ['link']);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id, url, anchor, post_title, occurrence_index, redirect_target_url, context_html, context_excerpt FROM $table_name WHERE id IN ($placeholders) AND type = %s",
                $query_params
            ),
            ARRAY_A
        );

        $missing = max(0, count($ids) - count($rows));
        $permission_denied = 0;
        $missing_target = 0;
        $processing_failures = 0;
        $success_count = 0;

        foreach ($rows as $row) {
            $row_id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($row_id <= 0) {
                continue;
            }

            $post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;

            if (!$this->user_can_manage_row($post_id)) {
                $permission_denied++;
                continue;
            }

            $redirect_target = isset($row['redirect_target_url']) ? trim((string) $row['redirect_target_url']) : '';
            if ($redirect_target === '') {
                $missing_target++;
                continue;
            }

            $occurrence_index = $this->normalize_occurrence_index($row['occurrence_index'] ?? null);

            $row_cache = [
                'url'             => isset($row['url']) ? (string) $row['url'] : '',
                'anchor'          => isset($row['anchor']) ? (string) $row['anchor'] : '',
                'post_title'      => isset($row['post_title']) ? (string) $row['post_title'] : '',
                'context_html'    => isset($row['context_html']) ? (string) $row['context_html'] : '',
                'context_excerpt' => isset($row['context_excerpt']) ? (string) $row['context_excerpt'] : '',
            ];

            $row_cache_footprint = $this->calculate_row_footprint($row);

            $result = blc_perform_link_update([
                'post_id'             => $post_id,
                'row_id'              => $row_id,
                'row'                 => $row,
                'occurrence_index'    => $occurrence_index,
                'table_name'          => $table_name,
                'row_cache'           => $row_cache,
                'row_cache_footprint' => $row_cache_footprint,
                'old_url'             => isset($row['url']) ? (string) $row['url'] : '',
                'new_url'             => $redirect_target,
            ]);

            if (is_wp_error($result)) {
                $processing_failures++;
                continue;
            }

            $success_count++;
        }

        $message_parts = [];

        if ($success_count > 0) {
            $message_parts[] = sprintf(
                _n('%d redirection détectée a été appliquée.', '%d redirections détectées ont été appliquées.', $success_count, 'liens-morts-detector-jlg'),
                $success_count
            );
        }

        if ($missing_target > 0) {
            $message_parts[] = sprintf(
                _n('%d lien sélectionné ne dispose pas de redirection détectée.', '%d liens sélectionnés ne disposent pas de redirection détectée.', $missing_target, 'liens-morts-detector-jlg'),
                $missing_target
            );
        }

        if ($processing_failures > 0) {
            $message_parts[] = sprintf(
                _n('%d lien n\'a pas pu être mis à jour en raison d\'une erreur.', '%d liens n\'ont pas pu être mis à jour en raison d\'erreurs.', $processing_failures, 'liens-morts-detector-jlg'),
                $processing_failures
            );
        }

        if ($permission_denied > 0) {
            $message_parts[] = sprintf(
                _n('Permissions insuffisantes pour %d lien.', 'Permissions insuffisantes pour %d liens.', $permission_denied, 'liens-morts-detector-jlg'),
                $permission_denied
            );
        }

        if ($missing > 0) {
            $message_parts[] = sprintf(
                _n('%d lien sélectionné est introuvable.', '%d liens sélectionnés sont introuvables.', $missing, 'liens-morts-detector-jlg'),
                $missing
            );
        }

        if (empty($message_parts)) {
            $message_parts[] = __('Aucune redirection détectée n\'a été appliquée.', 'liens-morts-detector-jlg');
        }

        $type = 'success';

        if ($success_count === 0) {
            if ($processing_failures > 0 || $permission_denied > 0 || $missing > 0 || $missing_target > 0) {
                $type = 'error';
            } else {
                $type = 'warning';
            }
        } elseif ($processing_failures > 0 || $permission_denied > 0 || $missing > 0 || $missing_target > 0) {
            $type = 'warning';
        }

        $announcement = $message_parts[0];

        return [
            'message'      => implode(' ', $message_parts),
            'type'         => $type,
            'announcement' => $announcement,
        ];
    }

    private function user_can_manage_row($post_id) {
        $post_id = absint($post_id);

        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post) {
                return current_user_can('edit_post', $post_id);
            }
        }

        return current_user_can('manage_options');
    }

    private function is_row_ignored($row) {
        $ignored_raw = $row['ignored_at'] ?? null;

        if (is_string($ignored_raw)) {
            $normalized = trim($ignored_raw);

            return ($normalized !== '' && $normalized !== '0000-00-00 00:00:00');
        }

        return $ignored_raw !== null;
    }

    private function calculate_row_footprint($row) {
        $url = $row['url'] ?? '';
        $anchor = $row['anchor'] ?? '';
        $post_title = $row['post_title'] ?? '';
        $context_html = $row['context_html'] ?? '';
        $context_excerpt = $row['context_excerpt'] ?? '';

        return blc_calculate_row_storage_footprint_bytes($url, $anchor, $post_title, $context_html, $context_excerpt);
    }

    private function normalize_occurrence_index($value) {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $candidate = (int) $value;
            if ($candidate >= 0) {
                return $candidate;
            }
        }

        return null;
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
        $default_days = 7;
        $min_days     = 1;
        $max_days     = null;

        if (function_exists('blc_get_recheck_interval_days_constraints')) {
            $constraints = blc_get_recheck_interval_days_constraints();

            if (isset($constraints['default']) && is_numeric($constraints['default'])) {
                $default_days = (int) $constraints['default'];
            }

            if (isset($constraints['min']) && is_numeric($constraints['min'])) {
                $min_days = (int) $constraints['min'];
            }

            if (isset($constraints['max']) && is_numeric($constraints['max'])) {
                $max_days = (int) $constraints['max'];
            }
        }

        $stored_days = get_option('blc_recheck_interval_days', $default_days);
        $days        = is_numeric($stored_days) ? (int) $stored_days : $default_days;

        if ($days < $min_days) {
            $days = $min_days;
        }

        if (null !== $max_days && $days > $max_days) {
            $days = $max_days;
        }

        $day_in_seconds = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;

        return max($day_in_seconds, $days * $day_in_seconds);
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

    /**
     * Render the HTML markup for a single row using the table's column definitions.
     *
     * @param array $item Row data to render.
     * @return string
     */
    public function render_row_html(array $item) {
        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items           = [$item];

        ob_start();
        $this->single_row($item);

        return (string) ob_get_clean();
    }
}
