<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/Admin/DashboardCache.php';

if (!function_exists('blc_normalize_hour_option')) {
    require_once __DIR__ . '/blc-utils.php';
}

if (!function_exists('blc_reset_link_check_schedule')) {
    require_once __DIR__ . '/blc-cron.php';
}

if (!function_exists('blc_get_required_capability')) {
    require_once __DIR__ . '/blc-capabilities.php';
}

if (!defined('BLC_SAVED_LINK_VIEWS_META_KEY')) {
    define('BLC_SAVED_LINK_VIEWS_META_KEY', 'blc_saved_link_views');
}

if (!defined('BLC_SETTINGS_MODE_META_KEY')) {
    define('BLC_SETTINGS_MODE_META_KEY', 'blc_settings_mode');
}

/**
 * Returns the maximum number of saved views allowed per user.
 *
 * @return int
 */
function blc_get_saved_link_views_limit() {
    $limit = (int) apply_filters('blc_saved_link_views_limit', 12);

    return ($limit < 0) ? 0 : $limit;
}

/**
 * Normalize filters persisted within a saved view.
 *
 * @param array<string, mixed> $filters
 *
 * @return array{link_type:string,post_type:string,s:string,orderby:string,order:string}
 */
function blc_normalize_link_view_filters($filters) {
    $normalized = array(
        'link_type' => 'all',
        'post_type' => '',
        's'         => '',
        'orderby'   => '',
        'order'     => 'desc',
    );

    if (!is_array($filters)) {
        return $normalized;
    }

    $link_type = '';
    if (isset($filters['link_type'])) {
        $link_type = sanitize_key((string) $filters['link_type']);
    } elseif (isset($filters['view'])) {
        $link_type = sanitize_key((string) $filters['view']);
    }
    if ($link_type === '') {
        $link_type = 'all';
    }
    $normalized['link_type'] = $link_type;

    if (isset($filters['post_type'])) {
        $post_type = sanitize_key((string) $filters['post_type']);
        if ($post_type !== '') {
            $normalized['post_type'] = $post_type;
        }
    }

    if (isset($filters['s'])) {
        $normalized['s'] = sanitize_text_field((string) $filters['s']);
    } elseif (isset($filters['search'])) {
        $normalized['s'] = sanitize_text_field((string) $filters['search']);
    }

    if (isset($filters['orderby'])) {
        $allowed_orderby = array('url', 'anchor_text', 'http_status', 'redirect_target_url', 'last_checked_at', 'post_title');
        $orderby         = sanitize_key((string) $filters['orderby']);

        if ($orderby !== '' && in_array($orderby, $allowed_orderby, true)) {
            $normalized['orderby'] = $orderby;
        }
    }

    if (isset($filters['order'])) {
        $order = strtolower((string) $filters['order']);
        if ($order === 'asc' || $order === 'desc') {
            $normalized['order'] = $order;
        }
    }

    return $normalized;
}

/**
 * Sort saved views by the most recent update.
 *
 * @param array<int, array<string, mixed>> $views
 *
 * @return array<int, array<string, mixed>>
 */
function blc_sort_link_views_by_recency(array $views) {
    usort(
        $views,
        static function ($a, $b) {
            $a_default = !empty($a['is_default']);
            $b_default = !empty($b['is_default']);

            if ($a_default !== $b_default) {
                return $a_default ? -1 : 1;
            }

            $a_time = isset($a['updated_at']) ? (int) $a['updated_at'] : 0;
            $b_time = isset($b['updated_at']) ? (int) $b['updated_at'] : 0;

            if ($a_time === $b_time) {
                $a_name = isset($a['name']) ? (string) $a['name'] : '';
                $b_name = isset($b['name']) ? (string) $b['name'] : '';

                return strcasecmp($a_name, $b_name);
            }

            return ($a_time > $b_time) ? -1 : 1;
        }
    );

    return array_values($views);
}

/**
 * Store the provided set of saved views for a user.
 *
 * @param int                                        $user_id
 * @param array<int, array<string, mixed>>           $views
 *
 * @return array<int, array<string, mixed>>
 */
function blc_store_link_views($user_id, array $views) {
    if ($user_id <= 0) {
        return array();
    }

    $ordered = blc_sort_link_views_by_recency($views);
    update_user_meta($user_id, BLC_SAVED_LINK_VIEWS_META_KEY, $ordered);

    return $ordered;
}

/**
 * Retrieve the saved views for the current user.
 *
 * @param int $user_id
 *
 * @return array<int, array<string, mixed>>
 */
function blc_get_saved_link_views($user_id = 0) {
    if (!function_exists('get_current_user_id')) {
        return array();
    }

    if ($user_id <= 0) {
        $user_id = (int) get_current_user_id();
    }

    if ($user_id <= 0) {
        return array();
    }

    $raw_views = get_user_meta($user_id, BLC_SAVED_LINK_VIEWS_META_KEY, true);
    if (!is_array($raw_views)) {
        return array();
    }

    $normalized = array();

    foreach ($raw_views as $view) {
        if (!is_array($view)) {
            continue;
        }

        $id   = isset($view['id']) ? (string) $view['id'] : '';
        $name = isset($view['name']) ? (string) $view['name'] : '';

        if ($id === '' || $name === '') {
            continue;
        }

        $filters = isset($view['filters']) ? $view['filters'] : array();
        $normalized[] = array(
            'id'         => $id,
            'name'       => $name,
            'filters'    => blc_normalize_link_view_filters($filters),
            'created_at' => isset($view['created_at']) ? (int) $view['created_at'] : 0,
            'updated_at' => isset($view['updated_at']) ? (int) $view['updated_at'] : 0,
            'is_default' => !empty($view['is_default']),
        );
    }

    return blc_sort_link_views_by_recency($normalized);
}

/**
 * Retrieve the identifier of the default saved view within a list.
 *
 * @param array<int, array<string, mixed>> $views
 *
 * @return string
 */
function blc_get_default_link_view_id(array $views) {
    foreach ($views as $view) {
        if (!empty($view['is_default']) && isset($view['id'])) {
            return (string) $view['id'];
        }
    }

    return '';
}

/**
 * Resolve a localized label for the provided link type.
 *
 * @param string $link_type
 *
 * @return string
 */
function blc_get_link_view_link_type_label($link_type) {
    $map = array(
        'all'             => __('Tous les statuts', 'liens-morts-detector-jlg'),
        'internal'        => __('Internes', 'liens-morts-detector-jlg'),
        'external'        => __('Externes', 'liens-morts-detector-jlg'),
        'ignored'         => __('Ignorés', 'liens-morts-detector-jlg'),
        'status_404_410'  => __('404 / 410', 'liens-morts-detector-jlg'),
        'status_5xx'      => __('5xx', 'liens-morts-detector-jlg'),
        'status_redirects'=> __('Redirections', 'liens-morts-detector-jlg'),
        'needs_recheck'   => __('À revérifier', 'liens-morts-detector-jlg'),
        'link'            => __('Liens HTML', 'liens-morts-detector-jlg'),
        'iframe'          => __('Iframes', 'liens-morts-detector-jlg'),
        'script'          => __('Scripts externes', 'liens-morts-detector-jlg'),
        'stylesheet'      => __('Balises <link>', 'liens-morts-detector-jlg'),
        'form'            => __('Formulaires', 'liens-morts-detector-jlg'),
        'css-background'  => __('Arrière-plans CSS', 'liens-morts-detector-jlg'),
    );

    if (isset($map[$link_type])) {
        return $map[$link_type];
    }

    $fallback = str_replace(array('-', '_'), ' ', $link_type);
    $fallback = trim($fallback);

    if ($fallback === '') {
        return __('Tous les statuts', 'liens-morts-detector-jlg');
    }

    return ucfirst($fallback);
}

/**
 * Return a display label for a post type within saved views.
 *
 * @param string $post_type
 *
 * @return string
 */
function blc_get_link_view_post_type_label($post_type) {
    if (function_exists('get_post_type_object')) {
        $post_type_object = get_post_type_object($post_type);
        if ($post_type_object && isset($post_type_object->labels) && isset($post_type_object->labels->name)) {
            return (string) $post_type_object->labels->name;
        }
    }

    $fallback = str_replace(array('-', '_'), ' ', $post_type);
    $fallback = trim($fallback);

    if ($fallback === '') {
        return __('Tous les contenus', 'liens-morts-detector-jlg');
    }

    return ucwords($fallback);
}

/**
 * Resolve the label used for the selected sorting field.
 *
 * @param string $orderby
 *
 * @return string
 */
function blc_get_link_view_sort_label($orderby) {
    $map = array(
        'url'                => __('URL Cassée', 'liens-morts-detector-jlg'),
        'anchor_text'        => __('Texte du lien', 'liens-morts-detector-jlg'),
        'http_status'        => __('Statut HTTP', 'liens-morts-detector-jlg'),
        'redirect_target_url'=> __('Cible détectée', 'liens-morts-detector-jlg'),
        'last_checked_at'    => __('Dernier contrôle', 'liens-morts-detector-jlg'),
        'post_title'         => __('Trouvé dans l\'article/page', 'liens-morts-detector-jlg'),
    );

    if (isset($map[$orderby])) {
        return $map[$orderby];
    }

    return __('Ordre par défaut', 'liens-morts-detector-jlg');
}

/**
 * Build a short textual summary for a saved view.
 *
 * @param array{link_type:string,post_type:string,s:string,orderby:string,order:string} $filters
 *
 * @return string
 */
function blc_build_link_view_summary(array $filters) {
    $parts = array();

    $link_type = isset($filters['link_type']) ? (string) $filters['link_type'] : 'all';
    $link_label = blc_get_link_view_link_type_label($link_type);

    if ($link_type === 'all') {
        $parts[] = $link_label;
    } else {
        $parts[] = sprintf(
            /* translators: %s: label of the link status filter. */
            __('Statut : %s', 'liens-morts-detector-jlg'),
            $link_label
        );
    }

    $post_type = isset($filters['post_type']) ? (string) $filters['post_type'] : '';
    if ($post_type !== '') {
        $parts[] = sprintf(
            /* translators: %s: label of the post type filter. */
            __('Type : %s', 'liens-morts-detector-jlg'),
            blc_get_link_view_post_type_label($post_type)
        );
    }

    $search = isset($filters['s']) ? (string) $filters['s'] : '';
    if ($search !== '') {
        $parts[] = sprintf(
            /* translators: %s: search term. */
            __('Recherche : “%s”', 'liens-morts-detector-jlg'),
            $search
        );
    }

    $orderby = isset($filters['orderby']) ? (string) $filters['orderby'] : '';
    if ($orderby !== '') {
        $order = isset($filters['order']) ? strtolower((string) $filters['order']) : 'desc';
        $arrow = ($order === 'asc') ? '↑' : '↓';
        $parts[] = sprintf(
            /* translators: 1: sort label. 2: arrow indicating direction. */
            __('Tri : %1$s %2$s', 'liens-morts-detector-jlg'),
            blc_get_link_view_sort_label($orderby),
            $arrow
        );
    }

    if ($parts === array()) {
        $parts[] = __('Tous les statuts', 'liens-morts-detector-jlg');
    }

    return implode(' · ', $parts);
}

/**
 * Prepare a saved view payload for front-end consumption.
 *
 * @param array<string, mixed> $view
 *
 * @return array<string, mixed>
 */
function blc_prepare_link_view_for_client(array $view) {
    $id      = isset($view['id']) ? (string) $view['id'] : '';
    $name    = isset($view['name']) ? (string) $view['name'] : '';
    $filters = isset($view['filters']) ? blc_normalize_link_view_filters($view['filters']) : blc_normalize_link_view_filters(array());
    $updated = isset($view['updated_at']) ? (int) $view['updated_at'] : 0;

    $updated_human = '';
    if ($updated > 0) {
        $now = function_exists('current_time') ? (int) current_time('timestamp') : time();
        $diff = human_time_diff($updated, $now);
        if (!empty($diff)) {
            $updated_human = sprintf(
                /* translators: %s: human readable time difference. */
                __('Mis à jour il y a %s', 'liens-morts-detector-jlg'),
                $diff
            );
        } else {
            $updated_human = __('Mis à jour à l’instant', 'liens-morts-detector-jlg');
        }
    }

    return array(
        'id'            => $id,
        'name'          => $name,
        'filters'       => $filters,
        'summary'       => blc_build_link_view_summary($filters),
        'is_default'    => !empty($view['is_default']),
        'updated_at'    => $updated,
        'updated_human' => $updated_human,
    );
}

/**
 * Prepare an array of saved views for front-end usage.
 *
 * @param array<int, array<string, mixed>> $views
 *
 * @return array<int, array<string, mixed>>
 */
function blc_prepare_link_views_for_client(array $views) {
    $prepared = array();

    foreach ($views as $view) {
        $prepared[] = blc_prepare_link_view_for_client($view);
    }

    return $prepared;
}

/**
 * Persist a saved view for a user.
 *
 * @param string                         $name
 * @param array<string, mixed>           $filters
 * @param int                            $user_id
 *
 * @return array<string, mixed>|\WP_Error
 */
function blc_save_link_view($name, array $filters, $user_id = 0, $set_default = null) {
    if (!function_exists('get_current_user_id')) {
        return new \WP_Error('invalid_context', __('Impossible d’enregistrer cette vue.', 'liens-morts-detector-jlg'));
    }

    if ($user_id <= 0) {
        $user_id = (int) get_current_user_id();
    }

    if ($user_id <= 0) {
        return new \WP_Error('invalid_user', __('Impossible d’enregistrer cette vue.', 'liens-morts-detector-jlg'));
    }

    $name = trim((string) $name);
    $name = preg_replace('/\s+/u', ' ', $name);
    $name = sanitize_text_field($name);

    if ($name === '') {
        return new \WP_Error('invalid_name', __('Veuillez saisir un nom pour enregistrer cette vue.', 'liens-morts-detector-jlg'));
    }

    if (function_exists('mb_strlen') && mb_strlen($name) > 80) {
        $name = mb_substr($name, 0, 80);
    } elseif (strlen($name) > 80) {
        $name = substr($name, 0, 80);
    }

    $normalized_filters = blc_normalize_link_view_filters($filters);
    $existing_views      = blc_get_saved_link_views($user_id);
    $limit               = blc_get_saved_link_views_limit();
    $requested_default   = null;

    if ($set_default !== null) {
        $requested_default = (bool) $set_default;
    }

    $previous_default_id = blc_get_default_link_view_id($existing_views);

    $slug           = sanitize_title($name);
    $existing_index = null;

    foreach ($existing_views as $index => $existing_view) {
        if (sanitize_title($existing_view['name']) === $slug) {
            $existing_index = $index;
            break;
        }
    }

    if ($existing_index === null && $limit > 0 && count($existing_views) >= $limit) {
        return new \WP_Error(
            'limit_reached',
            sprintf(
                /* translators: %d: limit of saved views. */
                __('Vous pouvez enregistrer jusqu’à %d vues personnalisées.', 'liens-morts-detector-jlg'),
                $limit
            ),
            array('limit' => $limit)
        );
    }

    $timestamp = function_exists('current_time') ? (int) current_time('timestamp') : time();

    if ($existing_index !== null) {
        $existing_views[$existing_index]['name']       = $name;
        $existing_views[$existing_index]['filters']    = $normalized_filters;
        $existing_views[$existing_index]['updated_at'] = $timestamp;

        $is_default = $requested_default;
        if ($is_default === null) {
            $is_default = !empty($existing_views[$existing_index]['is_default']);
        }

        $existing_views[$existing_index]['is_default'] = (bool) $is_default;

        $view   = $existing_views[$existing_index];
        $status = 'updated';
    } else {
        $view_id = function_exists('wp_generate_uuid4')
            ? 'sv_' . wp_generate_uuid4()
            : 'sv_' . uniqid('', true);

        $is_default = ($requested_default !== null) ? (bool) $requested_default : false;

        $view = array(
            'id'         => $view_id,
            'name'       => $name,
            'filters'    => $normalized_filters,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'is_default' => $is_default,
        );

        $existing_views[] = $view;
        $status           = 'created';
    }

    if (!empty($view['is_default'])) {
        foreach ($existing_views as $index => $candidate) {
            if (!isset($candidate['id']) || $candidate['id'] === $view['id']) {
                continue;
            }

            $existing_views[$index]['is_default'] = false;
        }
    }

    $stored_views = blc_store_link_views($user_id, $existing_views);

    foreach ($stored_views as $stored_view) {
        if ($stored_view['id'] === $view['id']) {
            $view = $stored_view;
            break;
        }
    }

    $new_default_id = blc_get_default_link_view_id($stored_views);
    $view_id        = isset($view['id']) ? (string) $view['id'] : '';
    $default_status = 'unchanged';

    if ($view_id !== '') {
        if ($new_default_id === $view_id && $previous_default_id !== $view_id) {
            $default_status = 'assigned';
        } elseif ($previous_default_id === $view_id && $new_default_id !== $view_id) {
            $default_status = 'removed';
        }
    }

    return array(
        'status' => $status,
        'view'   => $view,
        'views'  => $stored_views,
        'limit'  => $limit,
        'default_status' => $default_status,
        'default_view_id' => $new_default_id,
    );
}

/**
 * Remove a saved view for a user.
 *
 * @param string $view_id
 * @param int    $user_id
 *
 * @return array<string, mixed>|\WP_Error
 */
function blc_delete_link_view($view_id, $user_id = 0) {
    if (!function_exists('get_current_user_id')) {
        return new \WP_Error('invalid_context', __('Impossible de supprimer cette vue.', 'liens-morts-detector-jlg'));
    }

    $view_id = sanitize_text_field((string) $view_id);

    if ($view_id === '') {
        return new \WP_Error('invalid_id', __('Impossible de supprimer cette vue.', 'liens-morts-detector-jlg'));
    }

    if ($user_id <= 0) {
        $user_id = (int) get_current_user_id();
    }

    if ($user_id <= 0) {
        return new \WP_Error('invalid_user', __('Impossible de supprimer cette vue.', 'liens-morts-detector-jlg'));
    }

    $existing_views = blc_get_saved_link_views($user_id);
    $deleted_view   = null;

    foreach ($existing_views as $index => $view) {
        if (isset($view['id']) && $view['id'] === $view_id) {
            $deleted_view = $view;
            unset($existing_views[$index]);
            break;
        }
    }

    if ($deleted_view === null) {
        return new \WP_Error('not_found', __('Cette vue enregistrée est introuvable.', 'liens-morts-detector-jlg'));
    }

    $stored_views = blc_store_link_views($user_id, array_values($existing_views));

    return array(
        'view'  => $deleted_view,
        'views' => $stored_views,
    );
}

/**
 * Crée le menu principal et les sous-menus pour les rapports et les réglages.
 */
function blc_add_admin_menu() {
    $view_capability     = blc_get_required_capability('view_reports');
    $settings_capability = blc_get_required_capability('manage_settings');

    add_menu_page(
        __('Liens Morts', 'liens-morts-detector-jlg'),
        __('Liens Morts', 'liens-morts-detector-jlg'),
        $view_capability,
        'blc-dashboard',
        'blc_dashboard_links_page',
        'dashicons-editor-unlink'
    );
    add_submenu_page(
        'blc-dashboard',
        __('Liens Cassés', 'liens-morts-detector-jlg'),
        __('Liens Cassés', 'liens-morts-detector-jlg'),
        $view_capability,
        'blc-dashboard',
        'blc_dashboard_links_page'
    );
    add_submenu_page(
        'blc-dashboard',
        __('Images Cassées', 'liens-morts-detector-jlg'),
        __('Images Cassées', 'liens-morts-detector-jlg'),
        $view_capability,
        'blc-images-dashboard',
        'blc_dashboard_images_page'
    );
    add_submenu_page(
        'blc-dashboard',
        __('Historique', 'liens-morts-detector-jlg'),
        __('Historique', 'liens-morts-detector-jlg'),
        $view_capability,
        'blc-history',
        'blc_scan_history_page'
    );
    add_submenu_page(
        'blc-dashboard',
        __('Réglages', 'liens-morts-detector-jlg'),
        __('Réglages', 'liens-morts-detector-jlg'),
        $settings_capability,
        'blc-settings',
        'blc_settings_page'
    );
}

/**
 * Rend la navigation principale des pages du tableau de bord du plugin.
 *
 * @param string $active_tab Identifiant de l'onglet actif.
 */
function blc_render_dashboard_tabs($active_tab) {
    $tabs = array(
        'links'    => array(
            'label' => __('Liens', 'liens-morts-detector-jlg'),
            'page'  => 'blc-dashboard',
            'capability' => blc_get_required_capability('view_reports'),
        ),
        'images'   => array(
            'label' => __('Images', 'liens-morts-detector-jlg'),
            'page'  => 'blc-images-dashboard',
            'capability' => blc_get_required_capability('view_reports'),
        ),
        'history'  => array(
            'label' => __('Historique', 'liens-morts-detector-jlg'),
            'page'  => 'blc-history',
            'capability' => blc_get_required_capability('view_reports'),
        ),
        'settings' => array(
            'label' => __('Réglages', 'liens-morts-detector-jlg'),
            'page'  => 'blc-settings',
            'capability' => blc_get_required_capability('manage_settings'),
        ),
    );

    $navigation_label = __('Navigation du tableau de bord Liens Morts', 'liens-morts-detector-jlg');

    echo '<nav class="blc-admin-tabs" aria-label="' . esc_attr($navigation_label) . '"><ul class="blc-admin-tabs__list">';

    foreach ($tabs as $tab_key => $tab) {
        $required_capability = isset($tab['capability']) ? $tab['capability'] : '';

        if ($required_capability !== '' && !blc_user_can($required_capability)) {
            continue;
        }

        $is_active      = ($tab_key === $active_tab);
        $aria_current   = $is_active ? ' aria-current="page"' : '';
        $active_class   = $is_active ? ' is-active' : '';
        $tab_url        = function_exists('admin_url')
            ? admin_url('admin.php?page=' . $tab['page'])
            : 'admin.php?page=' . $tab['page'];
        $link_attributes = sprintf(
            ' class="blc-admin-tabs__link%s" href="%s"%s',
            esc_attr($active_class),
            esc_url($tab_url),
            $aria_current
        );
        $active_badge = '';
        $active_sr_hint = '';

        if ($is_active) {
            $active_badge = '<span class="blc-admin-tabs__state" aria-hidden="true">' . esc_html__('Actif', 'liens-morts-detector-jlg') . '</span>';
            $active_sr_hint = '<span class="screen-reader-text">' . esc_html__('(onglet actif)', 'liens-morts-detector-jlg') . '</span>';
        }

        echo '<li class="blc-admin-tabs__item"><a' . $link_attributes . '>' . esc_html($tab['label']) . $active_sr_hint . $active_badge . '</a></li>';
    }

    echo '</ul></nav>';
}

/**
 * Retrieve the domains generating the highest volume of active broken links.
 *
 * @param int $limit Maximum number of domains to return.
 *
 * @return array<int,array{host:string,count:int,client_errors:int,server_errors:int,redirects:int,other:int}>
 */
function blc_get_top_broken_link_domains($limit = 5) {
    $limit = (int) $limit;
    if ($limit <= 0) {
        $limit = 5;
    }

    $cached_domains = blc_get_cached_top_domain_stats($limit);
    if (is_array($cached_domains)) {
        return $cached_domains;
    }

    $link_row_types = blc_get_dataset_row_types('link');
    if (empty($link_row_types)) {
        $link_row_types = array('link');
    }

    global $wpdb;

    $table_name    = $wpdb->prefix . 'blc_broken_links';
    $placeholders  = implode(',', array_fill(0, count($link_row_types), '%s'));
    $prepared_args = array_merge($link_row_types, array($limit));

    $sql = $wpdb->prepare(
        "
        SELECT url_host,
               COUNT(*) AS total_count,
               SUM(CASE WHEN http_status BETWEEN 400 AND 499 THEN 1 ELSE 0 END) AS client_error_count,
               SUM(CASE WHEN http_status BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS server_error_count,
               SUM(CASE WHEN http_status BETWEEN 300 AND 399 THEN 1 ELSE 0 END) AS redirect_count,
               SUM(CASE WHEN http_status IS NULL OR http_status = 0 OR http_status >= 600 THEN 1 ELSE 0 END) AS other_count
          FROM $table_name
         WHERE ignored_at IS NULL
           AND url_host IS NOT NULL
           AND url_host <> ''
           AND type IN ($placeholders)
         GROUP BY url_host
         ORDER BY total_count DESC
         LIMIT %d
        ",
        $prepared_args
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!is_array($rows)) {
        $rows = array();
    }

    $domains = array();

    foreach ($rows as $row) {
        $host = isset($row['url_host']) ? (string) $row['url_host'] : '';
        if ($host === '') {
            continue;
        }

        $total = isset($row['total_count']) ? (int) $row['total_count'] : 0;
        if ($total <= 0) {
            continue;
        }

        $domains[] = array(
            'host'          => $host,
            'count'         => $total,
            'client_errors' => isset($row['client_error_count']) ? (int) $row['client_error_count'] : 0,
            'server_errors' => isset($row['server_error_count']) ? (int) $row['server_error_count'] : 0,
            'redirects'     => isset($row['redirect_count']) ? (int) $row['redirect_count'] : 0,
            'other'         => isset($row['other_count']) ? (int) $row['other_count'] : 0,
        );
    }

    blc_store_top_domain_stats_cache($limit, $domains);

    return $domains;
}

/**
 * Build a chronological list of processed item counts for the most recent scans.
 *
 * @param int $limit Maximum number of points to expose.
 *
 * @return array<int,array{timestamp:int,value:int,label:string,formatted:string}>
 */
function blc_get_link_scan_trend_points($limit = 12) {
    if (!function_exists('blc_get_link_scan_history')) {
        return array();
    }

    $limit       = max(1, (int) $limit);
    $history     = blc_get_link_scan_history();
    $points      = array();
    $date_format = 'd M';

    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $metrics = array();
        if (isset($entry['metrics']) && is_array($entry['metrics'])) {
            $metrics = $entry['metrics'];
        }

        $timestamp = 0;
        foreach (array('ended_at', 'started_at', 'scheduled_at') as $time_key) {
            if (!empty($entry[$time_key])) {
                $timestamp = (int) $entry[$time_key];
                break;
            }
        }

        if ($timestamp <= 0) {
            continue;
        }

        $value = null;
        foreach (array('processed_items', 'total_items') as $metric_key) {
            if (isset($metrics[$metric_key])) {
                $value = max(0, (int) $metrics[$metric_key]);
                break;
            }

            if (isset($entry[$metric_key])) {
                $value = max(0, (int) $entry[$metric_key]);
                break;
            }
        }

        if (null === $value) {
            continue;
        }

        $points[] = array(
            'timestamp' => $timestamp,
            'value'     => $value,
            'label'     => function_exists('wp_date')
                ? wp_date($date_format, $timestamp)
                : date($date_format, $timestamp),
            'formatted' => number_format_i18n($value),
        );

        if (count($points) >= $limit) {
            break;
        }
    }

    return array_reverse($points);
}

/**
 * Derive textual highlights from a set of trend points.
 *
 * @param array<int,array{timestamp:int,value:int,label:string,formatted:string}> $points Trend points.
 *
 * @return array{latest_label:string,delta_label:string,direction:string}
 */
function blc_summarize_link_scan_trend(array $points) {
    if ($points === array()) {
        return array(
            'latest_label' => __('Pas encore de données pour la dernière analyse.', 'liens-morts-detector-jlg'),
            'delta_label'  => __('La tendance sera affichée après au moins deux analyses.', 'liens-morts-detector-jlg'),
            'direction'    => 'flat',
        );
    }

    $latest_point = end($points);
    $latest_value = isset($latest_point['value']) ? (int) $latest_point['value'] : 0;
    $latest_label = sprintf(
        /* translators: %s: number of URLs checked. */
        __('Dernière analyse : %s URL contrôlées.', 'liens-morts-detector-jlg'),
        number_format_i18n($latest_value)
    );

    $previous_point = null;
    if (count($points) > 1) {
        $previous_point = prev($points);
        // Reset internal pointer for subsequent consumers.
        end($points);
    }

    if (null === $previous_point) {
        return array(
            'latest_label' => $latest_label,
            'delta_label'  => __('En attente d’une nouvelle analyse pour calculer une tendance.', 'liens-morts-detector-jlg'),
            'direction'    => 'flat',
        );
    }

    $previous_value = isset($previous_point['value']) ? (int) $previous_point['value'] : 0;
    $delta          = $latest_value - $previous_value;

    if ($delta === 0) {
        return array(
            'latest_label' => $latest_label,
            'delta_label'  => __('Volume stable par rapport à l’analyse précédente.', 'liens-morts-detector-jlg'),
            'direction'    => 'flat',
        );
    }

    $direction = ($delta > 0) ? 'up' : 'down';
    $delta_abs = abs($delta);
    $percent   = 0.0;

    if ($previous_value > 0) {
        $percent = ($delta_abs / $previous_value) * 100;
    }

    if ($delta > 0) {
        $delta_label = sprintf(
            /* translators: 1: additional URLs, 2: percentage increase. */
            __('Progression de %1$s URL (+%2$s %) vs. l’analyse précédente.', 'liens-morts-detector-jlg'),
            number_format_i18n($delta_abs),
            number_format_i18n($percent, 1)
        );
    } else {
        $delta_label = sprintf(
            /* translators: 1: missing URLs, 2: percentage drop. */
            __('Baisse de %1$s URL (−%2$s %) vs. l’analyse précédente.', 'liens-morts-detector-jlg'),
            number_format_i18n($delta_abs),
            number_format_i18n($percent, 1)
        );
    }

    return array(
        'latest_label' => $latest_label,
        'delta_label'  => $delta_label,
        'direction'    => $direction,
    );
}

/**
 * Compute a severity overview for the current broken link distribution.
 *
 * @param array<string,int> $count_keys Status counters.
 *
 * @return array<int,array{
 *     key:string,
 *     label:string,
 *     count:int,
 *     formatted_count:string,
 *     percentage:float,
 *     formatted_percentage:string,
 *     class:string
 * }>
 */
function blc_get_link_severity_overview(array $count_keys) {
    $segments = array(
        array(
            'key'   => 'critical',
            'label' => __('Erreurs 5xx (serveur)', 'liens-morts-detector-jlg'),
            'count' => isset($count_keys['server_error_count']) ? (int) $count_keys['server_error_count'] : 0,
            'class' => 'is-critical',
        ),
        array(
            'key'   => 'high',
            'label' => __('Erreurs 4xx (client)', 'liens-morts-detector-jlg'),
            'count' => isset($count_keys['not_found_count']) ? (int) $count_keys['not_found_count'] : 0,
            'class' => 'is-high',
        ),
        array(
            'key'   => 'medium',
            'label' => __('Redirections à vérifier', 'liens-morts-detector-jlg'),
            'count' => isset($count_keys['redirect_count']) ? (int) $count_keys['redirect_count'] : 0,
            'class' => 'is-medium',
        ),
        array(
            'key'   => 'low',
            'label' => __('Liens à re-tester', 'liens-morts-detector-jlg'),
            'count' => isset($count_keys['needs_recheck_count']) ? (int) $count_keys['needs_recheck_count'] : 0,
            'class' => 'is-low',
        ),
    );

    $total = 0;
    foreach ($segments as $segment) {
        $total += max(0, (int) $segment['count']);
    }

    $total = max(1, $total);

    foreach ($segments as &$segment) {
        $count                       = max(0, (int) $segment['count']);
        $segment['count']            = $count;
        $segment['formatted_count']  = number_format_i18n($count);
        $segment['percentage']       = ($count > 0) ? (($count / $total) * 100) : 0.0;
        $segment['formatted_percentage'] = number_format_i18n($segment['percentage'], 1);
    }
    unset($segment);

    return $segments;
}

if (!function_exists('blc_get_summary_placeholder')) {
    /**
     * Placeholder string for unavailable dashboard metrics.
     *
     * @return string
     */
    function blc_get_summary_placeholder() {
        return __('—', 'liens-morts-detector-jlg');
    }
}

/**
 * Build a dashboard URL with the provided query arguments, handling fallbacks when WordPress helpers are unavailable.
 *
 * @param string              $base_url Base dashboard URL.
 * @param array<string,mixed> $args     Query arguments.
 *
 * @return string
 */
function blc_build_dashboard_filtered_url($base_url, array $args) {
    $base_url = (string) $base_url;

    if ($args === array()) {
        return $base_url;
    }

    if (function_exists('add_query_arg')) {
        return add_query_arg($args, $base_url);
    }

    $separator = (false === strpos($base_url, '?')) ? '?' : '&';
    $query     = http_build_query($args, '', '&', PHP_QUERY_RFC3986);

    return $base_url . $separator . $query;
}

/**
 * Identify the most impacted domain for each severity class to guide priority recommendations.
 *
 * @param array<int,array<string,int|string>> $domains Domain aggregates.
 *
 * @return array{
 *     server:array{host:string,count:int}|null,
 *     client:array{host:string,count:int}|null,
 *     redirect:array{host:string,count:int}|null
 * }
 */
function blc_identify_priority_focus_domains(array $domains) {
    $focus = array(
        'server'   => null,
        'client'   => null,
        'redirect' => null,
    );

    foreach ($domains as $domain) {
        if (!is_array($domain) || empty($domain['host'])) {
            continue;
        }

        $host = (string) $domain['host'];

        $server_errors = isset($domain['server_errors']) ? max(0, (int) $domain['server_errors']) : 0;
        if ($server_errors > 0 && ($focus['server'] === null || $server_errors > $focus['server']['count'])) {
            $focus['server'] = array('host' => $host, 'count' => $server_errors);
        }

        $client_errors = isset($domain['client_errors']) ? max(0, (int) $domain['client_errors']) : 0;
        if ($client_errors > 0 && ($focus['client'] === null || $client_errors > $focus['client']['count'])) {
            $focus['client'] = array('host' => $host, 'count' => $client_errors);
        }

        $redirects = isset($domain['redirects']) ? max(0, (int) $domain['redirects']) : 0;
        if ($redirects > 0 && ($focus['redirect'] === null || $redirects > $focus['redirect']['count'])) {
            $focus['redirect'] = array('host' => $host, 'count' => $redirects);
        }
    }

    return $focus;
}

if (!function_exists('blc_format_summary_interval_label')) {
    /**
     * Format a time interval into a human readable unit (seconds, minutes, hours, days, weeks).
     *
     * @param int $seconds Interval in seconds.
     *
     * @return string
     */
    function blc_format_summary_interval_label($seconds) {
        $seconds = (int) abs($seconds);

        if ($seconds < 1) {
            return '';
        }

        $minute = 60;
        $hour   = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
        $day    = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
        $week   = $day * 7;

        if ($seconds < $minute) {
            $value = max(1, $seconds);

            return sprintf(
                /* translators: %s: number of seconds. */
                _n('%s seconde', '%s secondes', $value, 'liens-morts-detector-jlg'),
                number_format_i18n($value)
            );
        }

        if ($seconds < $hour) {
            $value = (int) round($seconds / $minute);
            $value = max(1, $value);

            return sprintf(
                /* translators: %s: number of minutes. */
                _n('%s minute', '%s minutes', $value, 'liens-morts-detector-jlg'),
                number_format_i18n($value)
            );
        }

        if ($seconds < $day) {
            $value = (int) round($seconds / $hour);
            $value = max(1, $value);

            return sprintf(
                /* translators: %s: number of hours. */
                _n('%s heure', '%s heures', $value, 'liens-morts-detector-jlg'),
                number_format_i18n($value)
            );
        }

        if ($seconds < $week * 4) {
            $value = (int) round($seconds / $day);
            $value = max(1, $value);

            return sprintf(
                /* translators: %s: number of days. */
                _n('%s jour', '%s jours', $value, 'liens-morts-detector-jlg'),
                number_format_i18n($value)
            );
        }

        $value = (int) round($seconds / $week);
        $value = max(1, $value);

        return sprintf(
            /* translators: %s: number of weeks. */
            _n('%s semaine', '%s semaines', $value, 'liens-morts-detector-jlg'),
            number_format_i18n($value)
        );
    }
}

if (!function_exists('blc_format_summary_relative_phrase')) {
    /**
     * Produce a localized relative time phrase.
     *
     * @param int    $seconds   Interval in seconds.
     * @param string $direction Either 'past' or 'future'.
     *
     * @return string
     */
    function blc_format_summary_relative_phrase($seconds, $direction = 'past') {
        $seconds   = (int) $seconds;
        $direction = ('future' === $direction) ? 'future' : 'past';

        if ($seconds <= 0) {
            return ('future' === $direction)
                ? __('dans un instant', 'liens-morts-detector-jlg')
                : __('à l’instant', 'liens-morts-detector-jlg');
        }

        $label = blc_format_summary_interval_label($seconds);
        if ($label === '') {
            return '';
        }

        if ('future' === $direction) {
            return sprintf(
                /* translators: %s: human readable interval. */
                __('dans %s', 'liens-morts-detector-jlg'),
                $label
            );
        }

        return sprintf(
            /* translators: %s: human readable interval. */
            __('il y a %s', 'liens-morts-detector-jlg'),
            $label
        );
    }
}

if (!function_exists('blc_format_summary_last_activity_label')) {
    /**
     * Create the last activity label for the dashboard summary.
     *
     * @param int $delta_seconds Seconds since the last activity as reported by the scanner.
     * @param int $updated_at    Timestamp of the last update, used as a fallback.
     *
     * @return string
     */
    function blc_format_summary_last_activity_label($delta_seconds, $updated_at) {
        $delta_seconds = (int) $delta_seconds;
        $updated_at    = (int) $updated_at;

        if ($delta_seconds > 0) {
            return sprintf(
                /* translators: %s: relative time phrase. */
                __('Actualisé : %s', 'liens-morts-detector-jlg'),
                blc_format_summary_relative_phrase($delta_seconds, 'past')
            );
        }

        if ($updated_at > 0) {
            $now       = time();
            $direction = ($updated_at > $now) ? 'future' : 'past';
            $delta     = abs($now - $updated_at);

            if ($delta < 5) {
                return __('Actualisé à l’instant', 'liens-morts-detector-jlg');
            }

            return sprintf(
                /* translators: %s: relative time phrase. */
                __('Actualisé : %s', 'liens-morts-detector-jlg'),
                blc_format_summary_relative_phrase($delta, $direction)
            );
        }

        return __('Dernière actualisation inconnue', 'liens-morts-detector-jlg');
    }
}

if (!function_exists('blc_format_summary_queue_label')) {
    /**
     * Build the queue length label for the summary state card.
     *
     * @param int $queue_length Number of manual scans queued.
     *
     * @return string
     */
    function blc_format_summary_queue_label($queue_length) {
        $queue_length = (int) $queue_length;
        if ($queue_length <= 0) {
            return '';
        }

        return sprintf(
            _n('%s analyse en file', '%s analyses en file', $queue_length, 'liens-morts-detector-jlg'),
            number_format_i18n($queue_length)
        );
    }
}

if (!function_exists('blc_map_scan_state_variant')) {
    /**
     * Associate a visual variant with the current scan state.
     *
     * @param string $state Scan state slug.
     *
     * @return string
     */
    function blc_map_scan_state_variant($state) {
        switch ($state) {
            case 'running':
                return 'info';
            case 'queued':
                return 'warning';
            case 'completed':
                return 'success';
            case 'failed':
                return 'danger';
            case 'cancelled':
                return 'warning';
            default:
                return 'neutral';
        }
    }
}

if (!function_exists('blc_map_progress_variant')) {
    /**
     * Determine the visual variant for the progress summary card.
     *
     * @param string $state Scan state slug.
     *
     * @return string
     */
    function blc_map_progress_variant($state) {
        switch ($state) {
            case 'completed':
                return 'success';
            case 'failed':
                return 'danger';
            case 'queued':
            case 'running':
                return 'info';
            case 'cancelled':
                return 'warning';
            default:
                return 'neutral';
        }
    }
}

if (!function_exists('blc_build_dashboard_summary_items')) {
    /**
     * Build the DashboardSummary cards displayed above the main statistics.
     *
     * @param array<string, mixed> $scan_status        Current scan status payload.
     * @param string               $scan_details_line  Narrative details describing the current scan.
     *
     * @return array<int,array{slug:string,label:string,value:string,description:string,variant:string}>
     */
    function blc_build_dashboard_summary_items(array $scan_status, $scan_details_line) {
        $scan_details_line = is_string($scan_details_line) ? trim($scan_details_line) : '';

        $state_slug  = isset($scan_status['state']) ? (string) $scan_status['state'] : 'idle';
        $state_label = blc_get_scan_state_label($state_slug);

        $manual_queue_length = isset($scan_status['manual_queue_length'])
            ? max(0, (int) $scan_status['manual_queue_length'])
            : 0;
        $last_activity_delta = isset($scan_status['last_activity_delta'])
            ? (int) $scan_status['last_activity_delta']
            : 0;
        $updated_at = isset($scan_status['updated_at']) ? (int) $scan_status['updated_at'] : 0;

        $state_description_parts = array();
        if ($scan_details_line !== '') {
            $state_description_parts[] = $scan_details_line;
        }

        $last_activity_label = blc_format_summary_last_activity_label($last_activity_delta, $updated_at);
        if ($last_activity_label !== '') {
            $state_description_parts[] = $last_activity_label;
        }

        $queue_label = blc_format_summary_queue_label($manual_queue_length);
        if ($queue_label !== '') {
            $state_description_parts[] = $queue_label;
        }

        if ($state_description_parts === array()) {
            $state_description_parts[] = __('Suivi en attente de données.', 'liens-morts-detector-jlg');
        }

        $state_item = array(
            'slug'        => 'state',
            'label'       => __('Statut opérationnel', 'liens-morts-detector-jlg'),
            'value'       => $state_label,
            'description' => implode(' ', $state_description_parts),
            'variant'     => blc_map_scan_state_variant($state_slug),
        );

        $processed_items = isset($scan_status['processed_items'])
            ? max(0, (int) $scan_status['processed_items'])
            : 0;
        $total_items = isset($scan_status['total_items'])
            ? max(0, (int) $scan_status['total_items'])
            : 0;

        $progress_percentage = null;
        if (isset($scan_status['progress_percentage']) && is_numeric($scan_status['progress_percentage'])) {
            $progress_percentage = (float) $scan_status['progress_percentage'];
        }

        if ($progress_percentage === null) {
            if ($total_items > 0) {
                $progress_percentage = ($processed_items / $total_items) * 100;
            } elseif ($processed_items > 0) {
                $progress_percentage = 100.0;
            } else {
                $progress_percentage = 0.0;
            }
        }

        $progress_percentage = max(0.0, min(100.0, (float) $progress_percentage));
        $progress_decimals   = ($progress_percentage >= 10.0) ? 0 : 1;

        $progress_value = sprintf(
            /* translators: %s: formatted percentage. */
            __('%s %%', 'liens-morts-detector-jlg'),
            number_format_i18n($progress_percentage, $progress_decimals)
        );

        if ($total_items > 0) {
            $progress_description = sprintf(
                /* translators: 1: processed URLs, 2: total URLs. */
                __('%1$s sur %2$s URL analysées', 'liens-morts-detector-jlg'),
                number_format_i18n($processed_items),
                number_format_i18n($total_items)
            );
        } elseif ($processed_items > 0) {
            $progress_description = sprintf(
                _n('%s URL analysée', '%s URL analysées', $processed_items, 'liens-morts-detector-jlg'),
                number_format_i18n($processed_items)
            );
        } else {
            $progress_description = __('Analyse en attente de démarrage.', 'liens-morts-detector-jlg');
        }

        $progress_item = array(
            'slug'        => 'progress',
            'label'       => __('Progression', 'liens-morts-detector-jlg'),
            'value'       => $progress_value,
            'description' => $progress_description,
            'variant'     => blc_map_progress_variant($state_slug),
        );

        $items_per_minute = isset($scan_status['items_per_minute'])
            ? (float) $scan_status['items_per_minute']
            : 0.0;
        $duration_seconds = isset($scan_status['duration_seconds'])
            ? max(0, (int) $scan_status['duration_seconds'])
            : 0;

        if ($items_per_minute > 0) {
            $throughput_decimals = ($items_per_minute >= 10.0) ? 0 : 1;
            $throughput_value    = sprintf(
                /* translators: %s: throughput in URLs per minute. */
                __('%s URL/min', 'liens-morts-detector-jlg'),
                number_format_i18n($items_per_minute, $throughput_decimals)
            );
        } else {
            $throughput_value = blc_get_summary_placeholder();
        }

        if ($duration_seconds > 0) {
            $throughput_description = sprintf(
                /* translators: %s: formatted duration. */
                __('Durée écoulée : %s', 'liens-morts-detector-jlg'),
                blc_format_scan_duration($duration_seconds * 1000)
            );
        } else {
            $throughput_description = __('Durée écoulée : en attente de calcul.', 'liens-morts-detector-jlg');
        }

        $throughput_item = array(
            'slug'        => 'throughput',
            'label'       => __('Rythme d’analyse', 'liens-morts-detector-jlg'),
            'value'       => $throughput_value,
            'description' => $throughput_description,
            'variant'     => ($items_per_minute > 0) ? 'info' : 'neutral',
        );

        return array($state_item, $progress_item, $throughput_item);
    }
}

/**
 * Build a prioritized action queue based on the most impacted domains.
 *
 * @param array<int,array<string,int|string>> $top_domains Domain aggregates.
 * @param string                               $dashboard_base_url Base dashboard URL.
 * @param array<string,int>                    $count_keys Status counters for ratios.
 *
 * @return array<int,array{
 *     title:string,
 *     description:string,
 *     severity_label:string,
 *     severity_class:string,
 *     cta_url:string,
 *     cta_label:string,
 *     meta:string
 * }>
 */
function blc_build_priority_action_queue(array $top_domains, $dashboard_base_url, array $count_keys) {
    $actions      = array();
    $total_active = isset($count_keys['active_count']) ? max(0, (int) $count_keys['active_count']) : 0;
    $total_active = max(1, $total_active);

    $server_error_total  = isset($count_keys['server_error_count']) ? max(0, (int) $count_keys['server_error_count']) : 0;
    $client_error_total  = isset($count_keys['not_found_count']) ? max(0, (int) $count_keys['not_found_count']) : 0;
    $redirect_total      = isset($count_keys['redirect_count']) ? max(0, (int) $count_keys['redirect_count']) : 0;
    $needs_recheck_total = isset($count_keys['needs_recheck_count']) ? max(0, (int) $count_keys['needs_recheck_count']) : 0;

    $max_actions = 5;
    if (function_exists('apply_filters')) {
        $max_actions = (int) apply_filters('blc_priority_actions_max_count', $max_actions);
    }
    if ($max_actions <= 0) {
        $max_actions = 5;
    }

    $focus_domains = blc_identify_priority_focus_domains($top_domains);

    $global_specs = array(
        'server'  => array(
            'total'        => $server_error_total,
            'min_ratio'    => 0,
            'min_count'    => 1,
            'link_type'    => 'status_5xx',
            'severity'     => array('class' => 'is-critical', 'label' => __('Critique', 'liens-morts-detector-jlg')),
            'title'        => __('Stabiliser les erreurs serveur', 'liens-morts-detector-jlg'),
            'description'  => static function($total, $focus = null) {
                if ($focus !== null) {
                    return sprintf(
                        /* translators: 1: number of server errors, 2: number for the main domain, 3: domain name. */
                        __('Résolvez %1$s erreur(s) 5xx restantes — dont %2$s sur %3$s — pour rétablir les pages critiques.', 'liens-morts-detector-jlg'),
                        number_format_i18n($total),
                        number_format_i18n($focus['count']),
                        $focus['host']
                    );
                }

                return sprintf(
                    /* translators: %s: number of server errors. */
                    __('Résolvez %s erreur(s) 5xx restantes pour rétablir les pages critiques.', 'liens-morts-detector-jlg'),
                    number_format_i18n($total)
                );
            },
        ),
        'client'  => array(
            'total'        => $client_error_total,
            'min_ratio'    => 10,
            'min_count'    => 5,
            'link_type'    => 'status_404_410',
            'severity'     => array('class' => 'is-high', 'label' => __('Prioritaire', 'liens-morts-detector-jlg')),
            'title'        => __('Résorber les erreurs 4xx récurrentes', 'liens-morts-detector-jlg'),
            'description'  => static function($total, $focus = null) {
                if ($focus !== null) {
                    return sprintf(
                        /* translators: 1: number of client errors, 2: number for the main domain, 3: domain name. */
                        __('Réparez %1$s lien(s) en erreur 4xx — dont %2$s sur %3$s — pour restaurer les contenus attendus ou configurer une redirection.', 'liens-morts-detector-jlg'),
                        number_format_i18n($total),
                        number_format_i18n($focus['count']),
                        $focus['host']
                    );
                }

                return sprintf(
                    /* translators: %s: number of client errors. */
                    __('Réparez %s lien(s) en erreur 4xx pour restaurer les contenus attendus ou configurer une redirection.', 'liens-morts-detector-jlg'),
                    number_format_i18n($total)
                );
            },
        ),
        'redirect' => array(
            'total'        => $redirect_total,
            'min_ratio'    => 15,
            'min_count'    => 5,
            'link_type'    => 'status_redirects',
            'severity'     => array('class' => 'is-medium', 'label' => __('À optimiser', 'liens-morts-detector-jlg')),
            'title'        => __('Optimiser les redirections détectées', 'liens-morts-detector-jlg'),
            'description'  => static function($total, $focus = null) {
                if ($focus !== null) {
                    return sprintf(
                        /* translators: 1: number of redirects, 2: number for the main domain, 3: domain name. */
                        __('Passez en revue %1$s redirection(s) — dont %2$s sur %3$s — afin de réduire les détours et préserver le référencement.', 'liens-morts-detector-jlg'),
                        number_format_i18n($total),
                        number_format_i18n($focus['count']),
                        $focus['host']
                    );
                }

                return sprintf(
                    /* translators: %s: number of redirects. */
                    __('Passez en revue %s redirection(s) pour réduire les détours et préserver le référencement.', 'liens-morts-detector-jlg'),
                    number_format_i18n($total)
                );
            },
        ),
        'recheck' => array(
            'total'        => $needs_recheck_total,
            'min_ratio'    => 20,
            'min_count'    => 5,
            'link_type'    => 'needs_recheck',
            'severity'     => array('class' => 'is-low', 'label' => __('À surveiller', 'liens-morts-detector-jlg')),
            'title'        => __('Relancer les liens à re-tester', 'liens-morts-detector-jlg'),
            'description'  => static function($total, $focus = null) {
                return sprintf(
                    /* translators: %s: number of links to recheck. */
                    __('Planifiez une nouvelle vérification pour %s lien(s) en attente afin de confirmer la résolution ou de détecter les rechutes.', 'liens-morts-detector-jlg'),
                    number_format_i18n($total)
                );
            },
        ),
    );

    if (function_exists('apply_filters')) {
        $global_specs = apply_filters('blc_priority_actions_global_specs', $global_specs, $count_keys, $top_domains);
    }

    foreach ($global_specs as $key => $spec) {
        $total = isset($spec['total']) ? (int) $spec['total'] : 0;
        if ($total <= 0) {
            continue;
        }

        $percentage = ($total / $total_active) * 100;
        $min_ratio  = isset($spec['min_ratio']) ? (float) $spec['min_ratio'] : 0.0;
        $min_count  = isset($spec['min_count']) ? (int) $spec['min_count'] : 0;

        if ($percentage < $min_ratio && $total < $min_count) {
            continue;
        }

        $focus_key = ($key === 'client') ? 'client' : $key;
        $focus     = isset($focus_domains[$focus_key]) ? $focus_domains[$focus_key] : null;

        $description_callback = isset($spec['description']) && is_callable($spec['description'])
            ? $spec['description']
            : static function() {
                return '';
            };

        if ($focus !== null) {
            $description = $description_callback($total, $focus);
        } else {
            $description = $description_callback($total);
        }

        $meta_parts = array(
            sprintf(
                /* translators: %s: percentage of active broken links. */
                __('Impact : %s %% des liens cassés actifs.', 'liens-morts-detector-jlg'),
                number_format_i18n(min(100, max(0, $percentage)), 1)
            ),
        );

        if ($focus !== null) {
            $meta_parts[] = sprintf(
                /* translators: %s: domain name. */
                __('Focus : %s', 'liens-morts-detector-jlg'),
                $focus['host']
            );
        }

        $cta_args = array();
        if (!empty($spec['link_type']) && 'all' !== $spec['link_type']) {
            $cta_args['link_type'] = (string) $spec['link_type'];
        }

        $actions[] = array(
            'title'          => isset($spec['title']) ? (string) $spec['title'] : '',
            'description'    => $description,
            'severity_label' => isset($spec['severity']['label']) ? (string) $spec['severity']['label'] : '',
            'severity_class' => isset($spec['severity']['class']) ? (string) $spec['severity']['class'] : 'is-low',
            'cta_url'        => blc_build_dashboard_filtered_url($dashboard_base_url, $cta_args),
            'cta_label'      => __('Ouvrir la liste filtrée', 'liens-morts-detector-jlg'),
            'meta'           => implode(' · ', array_filter($meta_parts)),
        );

        if (count($actions) >= $max_actions) {
            return array_slice($actions, 0, $max_actions);
        }
    }

    $domain_slice = array_slice($top_domains, 0, 3);

    foreach ($domain_slice as $domain) {
        if (count($actions) >= $max_actions) {
            break;
        }
        if (!is_array($domain) || empty($domain['host'])) {
            continue;
        }

        $host          = (string) $domain['host'];
        $total_count   = isset($domain['count']) ? max(0, (int) $domain['count']) : 0;
        $server_errors = isset($domain['server_errors']) ? max(0, (int) $domain['server_errors']) : 0;
        $client_errors = isset($domain['client_errors']) ? max(0, (int) $domain['client_errors']) : 0;
        $redirects     = isset($domain['redirects']) ? max(0, (int) $domain['redirects']) : 0;
        $others        = isset($domain['other']) ? max(0, (int) $domain['other']) : 0;

        if ($total_count === 0) {
            continue;
        }

        $severity_class = 'is-low';
        $severity_label = __('À surveiller', 'liens-morts-detector-jlg');
        $target_link_type = 'all';

        if ($server_errors > 0) {
            $severity_class  = 'is-critical';
            $severity_label  = __('Critique', 'liens-morts-detector-jlg');
            $target_link_type = 'status_5xx';
        } elseif ($client_errors > 0) {
            $severity_class  = 'is-high';
            $severity_label  = __('Prioritaire', 'liens-morts-detector-jlg');
            $target_link_type = 'status_404_410';
        } elseif ($redirects > 0) {
            $severity_class  = 'is-medium';
            $severity_label  = __('À optimiser', 'liens-morts-detector-jlg');
            $target_link_type = 'status_redirects';
        }

        $breakdown_parts = array();
        if ($server_errors > 0) {
            $breakdown_parts[] = sprintf(
                /* translators: %s: number of server errors. */
                __('%s erreur(s) serveur', 'liens-morts-detector-jlg'),
                number_format_i18n($server_errors)
            );
        }
        if ($client_errors > 0) {
            $breakdown_parts[] = sprintf(
                /* translators: %s: number of client errors. */
                __('%s erreur(s) client', 'liens-morts-detector-jlg'),
                number_format_i18n($client_errors)
            );
        }
        if ($redirects > 0) {
            $breakdown_parts[] = sprintf(
                /* translators: %s: number of redirects. */
                __('%s redirection(s)', 'liens-morts-detector-jlg'),
                number_format_i18n($redirects)
            );
        }
        if ($others > 0) {
            $breakdown_parts[] = sprintf(
                /* translators: %s: number of other issues. */
                __('%s statut(s) divers', 'liens-morts-detector-jlg'),
                number_format_i18n($others)
            );
        }

        $description = sprintf(
            /* translators: 1: number of broken links, 2: domain, 3: issue breakdown. */
            __('Traitez %1$s lien(s) sur %2$s — %3$s.', 'liens-morts-detector-jlg'),
            number_format_i18n($total_count),
            $host,
            implode(' · ', $breakdown_parts)
        );

        if ($breakdown_parts === array()) {
            $description = sprintf(
                /* translators: 1: number of broken links, 2: domain name. */
                __('Traitez %1$s lien(s) signalé(s) sur %2$s.', 'liens-morts-detector-jlg'),
                number_format_i18n($total_count),
                $host
            );
        }

        $percentage = min(100, max(0, ($total_count / $total_active) * 100));
        $meta       = sprintf(
            /* translators: %s: percentage of active broken links. */
            __('%s %% des liens cassés actifs.', 'liens-morts-detector-jlg'),
            number_format_i18n($percentage, 1)
        );

        $cta_args = array('s' => $host);
        if ($target_link_type !== 'all') {
            $cta_args['link_type'] = $target_link_type;
        }

        $cta_url = blc_build_dashboard_filtered_url($dashboard_base_url, $cta_args);

        $actions[] = array(
            'title'          => sprintf(
                /* translators: %s: domain name. */
                __('Stabiliser %s', 'liens-morts-detector-jlg'),
                $host
            ),
            'description'    => $description,
            'severity_label' => $severity_label,
            'severity_class' => $severity_class,
            'cta_url'        => $cta_url,
            'cta_label'      => __('Ouvrir la liste filtrée', 'liens-morts-detector-jlg'),
            'meta'           => $meta,
        );
    }

    return array_slice($actions, 0, $max_actions);
}

/**
 * Produce a contextual call-to-action label for a statistic card.
 *
 * @param string $slug  Card identifier.
 * @param int    $count Item count for the card.
 *
 * @return string
 */
function blc_get_stat_card_cta_label($slug, $count) {
    $count = max(0, (int) $count);

    switch ($slug) {
        case '404':
            return sprintf(
                /* translators: %s: number of 4xx errors. */
                _n('%s erreur critique à corriger', '%s erreurs critiques à corriger', $count, 'liens-morts-detector-jlg'),
                number_format_i18n($count)
            );
        case '5xx':
            return sprintf(
                /* translators: %s: number of server errors. */
                _n('%s incident serveur détecté', '%s incidents serveurs détectés', $count, 'liens-morts-detector-jlg'),
                number_format_i18n($count)
            );
        case 'redirects':
            return sprintf(
                /* translators: %s: number of redirects to review. */
                _n('%s redirection à valider', '%s redirections à valider', $count, 'liens-morts-detector-jlg'),
                number_format_i18n($count)
            );
        case 'recheck':
            return sprintf(
                /* translators: %s: number of links flagged for recheck. */
                _n('%s lien en attente de re-test', '%s liens en attente de re-test', $count, 'liens-morts-detector-jlg'),
                number_format_i18n($count)
            );
        default:
            return sprintf(
                /* translators: %s: number of broken links. */
                _n('%s lien cassé à traiter', '%s liens cassés à traiter', $count, 'liens-morts-detector-jlg'),
                number_format_i18n($count)
            );
    }
}

/**
 * Affiche la modale utilisée pour l'édition et la suppression rapides.
 *
 * Cette modale est rendue une seule fois puis réutilisée via JavaScript pour
 * remplacer les appels bloquants à prompt()/confirm().
 *
 * @return void
 */
function blc_render_action_modal() {
    static $rendered = false;

    if ($rendered) {
        return;
    }

    $rendered = true;
    ?>
    <div id="blc-modal" class="blc-modal" aria-hidden="true">
        <div class="blc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="blc-modal-title">
            <button type="button" class="blc-modal__close" aria-label="<?php echo esc_attr__('Fermer la fenêtre modale', 'liens-morts-detector-jlg'); ?>">
                <span aria-hidden="true">&times;</span>
            </button>
            <h2 id="blc-modal-title" class="blc-modal__title"></h2>
            <p class="blc-modal__message"></p>
            <div class="blc-modal__context" aria-live="polite"></div>
            <div class="blc-modal__error" role="alert" aria-live="assertive"></div>
            <div class="blc-modal__options blc-modal__section is-hidden"></div>
            <div class="blc-modal__field">
                <label for="blc-modal-url" class="blc-modal__label"></label>
                <input type="url" id="blc-modal-url" class="blc-modal__input" placeholder="<?php echo esc_attr__('https://', 'liens-morts-detector-jlg'); ?>">
            </div>
            <div class="blc-modal__preview blc-modal__section is-hidden" aria-live="polite"></div>
            <div class="blc-modal__actions">
                <button type="button" class="button button-secondary blc-modal__cancel"><?php esc_html_e('Annuler', 'liens-morts-detector-jlg'); ?></button>
                <button type="button" class="button button-primary blc-modal__confirm"><?php esc_html_e('Confirmer', 'liens-morts-detector-jlg'); ?></button>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Schedule a manual link scan and update the stored status.
 *
 * @param bool $is_full_scan Whether to launch a full scan.
 *
 * @return array<string, mixed>
 */
function blc_schedule_manual_link_scan($is_full_scan = false, $force_cancel = false, $queue_on_busy = false, array $options = array()) {
    $is_full_scan = (bool) $is_full_scan;
    $force_cancel = (bool) $force_cancel;
    $queue_on_busy = (bool) $queue_on_busy;
    if (!is_array($options)) {
        $options = array();
    }

    $bypass_rest_window = $is_full_scan;
    $job_id = function_exists('blc_generate_link_scan_job_id') ? blc_generate_link_scan_job_id() : uniqid('blc_', true);
    $attempt = 1;
    $scheduled_at = time();

    $current_status = blc_get_link_scan_status(true);
    $current_state = isset($current_status['state']) ? (string) $current_status['state'] : 'idle';
    $active_states = array('running', 'queued');
    $has_remaining_batches = !empty($current_status['remaining_batches']);
    $scan_is_active = in_array($current_state, $active_states, true) || $has_remaining_batches;

    $from_queue = !empty($options['from_queue']);
    $queue_entry = (isset($options['queue_entry']) && is_array($options['queue_entry'])) ? $options['queue_entry'] : null;
    $requested_by = isset($options['requested_by']) ? (int) $options['requested_by'] : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
    $queue_context = isset($options['enqueue_context']) && is_string($options['enqueue_context']) ? $options['enqueue_context'] : 'manual_dashboard';

    if ($scan_is_active && !$force_cancel) {
        if ($queue_on_busy) {
            $queued_entry = blc_enqueue_manual_scan_request(
                array(
                    'is_full_scan' => $is_full_scan,
                    'requested_by' => $requested_by,
                    'context'      => $queue_context,
                )
            );

            $queue_message = __("Un scan est déjà en cours. La nouvelle demande a été ajoutée à la file d’attente.", 'liens-morts-detector-jlg');

            return array(
                'success'               => true,
                'message'               => $queue_message,
                'manual_trigger_failed' => false,
                'queued'                => true,
                'queue_length'          => blc_manual_scan_queue_length(),
                'queue_entry'           => blc_format_manual_queue_entry_for_response($queued_entry),
            );
        }

        if ($from_queue && $queue_entry) {
            blc_prepend_manual_scan_queue($queue_entry);
        }

        $message = __("Une analyse est déjà en cours. Ajoutez la demande à la file d’attente ou remplacez l’exécution en cours.", 'liens-morts-detector-jlg');

        return array(
            'success'               => false,
            'message'               => $message,
            'manual_trigger_failed' => false,
            'requires_confirmation' => true,
            'current_state'         => $current_state,
            'resolution_hint'       => __("Vous pouvez laisser la file d’attente démarrer automatiquement après l’analyse en cours ou confirmer le remplacement immédiat.", 'liens-morts-detector-jlg'),
            'queue_available'       => true,
            'queue_length'          => blc_manual_scan_queue_length(),
        );
    }

    $queue_was_cleared = false;

    if ($force_cancel) {
        $queue_was_cleared = (blc_manual_scan_queue_length() > 0);
        blc_clear_manual_scan_queue();
    }

    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('blc_manual_check_batch');
    }

    $schedule_args = array(0, $is_full_scan, $bypass_rest_window, array());
    $scheduled = wp_schedule_single_event($scheduled_at, 'blc_manual_check_batch', $schedule_args);

    if (false === $scheduled) {
        $retry_delay = function_exists('apply_filters') ? (int) apply_filters('blc_manual_scan_retry_delay', 60) : 60;
        if ($retry_delay < 5) {
            $retry_delay = 5;
        }

        $attempt++;
        $scheduled = wp_schedule_single_event($scheduled_at + $retry_delay, 'blc_manual_check_batch', $schedule_args);
    }

    if (false === $scheduled) {
        error_log(
            sprintf(
                'BLC: Failed to schedule manual link check (full scan: %s, bypass rest window: %s).',
                $is_full_scan ? 'true' : 'false',
                $bypass_rest_window ? 'true' : 'false'
            )
        );
        do_action('blc_manual_check_schedule_failed', $is_full_scan, $bypass_rest_window);

        $failure_message = __("La vérification des liens n'a pas pu être programmée. Veuillez réessayer.", 'liens-morts-detector-jlg');

        blc_update_link_scan_status([
            'state'             => 'failed',
            'message'           => $failure_message,
            'last_error'        => $failure_message,
            'current_batch'     => 0,
            'processed_batches' => 0,
            'total_batches'     => 0,
            'remaining_batches' => 0,
            'total_items'       => 0,
            'processed_items'   => 0,
            'is_full_scan'      => $is_full_scan,
            'started_at'        => 0,
            'job_id'            => $job_id,
            'attempt'           => $attempt,
        ]);

        blc_append_link_scan_history_entry([
            'job_id'      => $job_id,
            'state'       => 'failed',
            'message'     => $failure_message,
            'attempt'     => $attempt,
            'is_full_scan'=> $is_full_scan,
            'scheduled_at'=> $scheduled_at,
            'bypass_rest_window' => $bypass_rest_window,
        ]);

        blc_record_manual_scan_error($failure_message, array('context' => 'schedule_failed'));

        return array(
            'success'               => false,
            'message'               => $failure_message,
            'manual_trigger_failed' => false,
            'queue_length'          => blc_manual_scan_queue_length(),
        );
    }

    $manual_trigger_failed = false;
    $manual_trigger_error = '';

    if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
        $manual_trigger_result = null;

        if (function_exists('spawn_cron')) {
            $manual_trigger_result = spawn_cron();
        } elseif (function_exists('wp_cron')) {
            $manual_trigger_result = wp_cron();
        }

        if (null !== $manual_trigger_result) {
            $is_error = (false === $manual_trigger_result);

            if (function_exists('is_wp_error') && is_wp_error($manual_trigger_result)) {
                $is_error = true;
                $manual_trigger_error = $manual_trigger_result->get_error_message();
            }

            if ($is_error) {
                $manual_trigger_failed = true;
                error_log('BLC: Manual cron trigger failed for link check.');
                blc_record_manual_scan_error(
                    __('Le déclenchement immédiat de WP-Cron a échoué. WordPress réessaiera automatiquement.', 'liens-morts-detector-jlg'),
                    array('context' => 'manual_trigger_failed')
                );
            }
        }
    }

    $status_message = __('Analyse programmée. Le premier lot démarrera sous peu.', 'liens-morts-detector-jlg');

    if ($manual_trigger_failed) {
        $status_message .= ' ' . __("Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.", 'liens-morts-detector-jlg');
    }

    blc_append_link_scan_history_entry([
        'job_id'              => $job_id,
        'state'               => 'queued',
        'message'             => $status_message,
        'attempt'             => $attempt,
        'is_full_scan'        => $is_full_scan,
        'scheduled_at'        => $scheduled_at,
        'bypass_rest_window'  => $bypass_rest_window,
        'manual_trigger_failed' => $manual_trigger_failed,
        'manual_trigger_error'  => $manual_trigger_error,
        'queued_via'            => $from_queue ? 'queue' : 'manual',
    ]);

    blc_update_link_scan_status([
        'state'             => 'queued',
        'current_batch'     => 0,
        'processed_batches' => 0,
        'total_batches'     => 0,
        'remaining_batches' => 0,
        'total_items'       => 0,
        'processed_items'   => 0,
        'is_full_scan'      => $is_full_scan,
        'message'           => $status_message,
        'last_error'        => '',
        'started_at'        => 0,
        'ended_at'          => 0,
        'job_id'            => $job_id,
        'attempt'           => $attempt,
    ]);

    $return_message = sprintf(
        /* translators: %s: unique job identifier. */
        __("La vérification des liens a été programmée et s'exécute en arrière-plan. (Job : %s)", 'liens-morts-detector-jlg'),
        esc_html($job_id)
    );

    if ($queue_was_cleared) {
        $return_message .= ' ' . __("La file d’attente précédente a été vidée avant de relancer l’analyse.", 'liens-morts-detector-jlg');
    } elseif ($scan_is_active && $force_cancel) {
        $return_message .= ' ' . __("Le scan en cours a été remplacé par cette nouvelle exécution.", 'liens-morts-detector-jlg');
    }

    return array(
        'success'               => true,
        'message'               => $return_message,
        'manual_trigger_failed' => $manual_trigger_failed,
        'queue_length'          => blc_manual_scan_queue_length(),
        'queue_cleared'         => $queue_was_cleared,
        'dispatched_from_queue' => $from_queue,
    );
}

/**
 * Schedule a manual image scan and update the stored status.
 *
 * @return array{success:bool,message:string,manual_trigger_failed:bool,job_id:string,attempt:int,scheduled_at:int}
 */
function blc_schedule_manual_image_scan() {
    $automatic_enabled = (bool) get_option('blc_image_scan_schedule_enabled', false);

    $job_id = function_exists('blc_generate_image_scan_job_id')
        ? blc_generate_image_scan_job_id()
        : uniqid('blc_img_', true);
    $attempt = 1;
    $scheduled_at = time();

    if (!$automatic_enabled && function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('blc_check_image_batch', array(0, true));
    }

    $schedule_args = array(0, true);
    $scheduled = wp_schedule_single_event($scheduled_at, 'blc_check_image_batch', $schedule_args);

    if (false === $scheduled) {
        $retry_delay = function_exists('apply_filters') ? (int) apply_filters('blc_manual_image_scan_retry_delay', 60) : 60;
        if ($retry_delay < 5) {
            $retry_delay = 5;
        }

        $attempt++;
        $scheduled = wp_schedule_single_event($scheduled_at + $retry_delay, 'blc_check_image_batch', $schedule_args);
    }

    if (false === $scheduled) {
        error_log('BLC: Failed to schedule manual image check.');
        do_action('blc_manual_image_check_schedule_failed');

        $failure_message = __("La vérification des images n'a pas pu être programmée. Veuillez réessayer.", 'liens-morts-detector-jlg');

        blc_update_image_scan_status([
            'state'      => 'failed',
            'message'    => $failure_message,
            'last_error' => $failure_message,
            'started_at' => 0,
            'job_id'     => $job_id,
            'attempt'    => $attempt,
            'scheduled_at' => $scheduled_at,
        ]);

        blc_append_image_scan_history_entry([
            'job_id'                => $job_id,
            'state'                 => 'failed',
            'message'               => $failure_message,
            'attempt'               => $attempt,
            'is_full_scan'          => true,
            'scheduled_at'          => $scheduled_at,
            'manual_trigger_failed' => false,
            'automatic_schedule_enabled' => $automatic_enabled,
        ]);

        return [
            'success'               => false,
            'message'               => $failure_message,
            'manual_trigger_failed' => false,
            'job_id'                => $job_id,
            'attempt'               => $attempt,
            'scheduled_at'          => $scheduled_at,
        ];
    }

    $manual_trigger_failed = false;

    if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
        $manual_trigger_result = null;

        if (function_exists('spawn_cron')) {
            $manual_trigger_result = spawn_cron();
        } elseif (function_exists('wp_cron')) {
            $manual_trigger_result = wp_cron();
        }

        if (null !== $manual_trigger_result) {
            $is_error = (false === $manual_trigger_result);

            if (function_exists('is_wp_error') && is_wp_error($manual_trigger_result)) {
                $is_error = true;
            }

            if ($is_error) {
                $manual_trigger_failed = true;
                error_log('BLC: Manual cron trigger failed for image check.');
            }
        }
    }

    $status_message = __('Analyse programmée. Le premier lot démarrera sous peu.', 'liens-morts-detector-jlg');

    if ($manual_trigger_failed) {
        $status_message .= ' ' . __("Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.", 'liens-morts-detector-jlg');
    }

    blc_append_image_scan_history_entry([
        'job_id'                => $job_id,
        'state'                 => 'queued',
        'message'               => $status_message,
        'attempt'               => $attempt,
        'is_full_scan'          => true,
        'scheduled_at'          => $scheduled_at,
        'manual_trigger_failed' => $manual_trigger_failed,
        'automatic_schedule_enabled' => $automatic_enabled,
    ]);

    blc_update_image_scan_status([
        'state'             => 'queued',
        'current_batch'     => 0,
        'processed_batches' => 0,
        'total_batches'     => 0,
        'remaining_batches' => 0,
        'total_items'       => 0,
        'processed_items'   => 0,
        'is_full_scan'      => true,
        'message'           => $status_message,
        'last_error'        => '',
        'started_at'        => 0,
        'ended_at'          => 0,
        'job_id'            => $job_id,
        'attempt'           => $attempt,
        'scheduled_at'      => $scheduled_at,
    ]);

    $return_message = sprintf(
        /* translators: %s: unique job identifier. */
        __("La vérification des images a été programmée et s'exécute en arrière-plan. (Job : %s)", 'liens-morts-detector-jlg'),
        esc_html($job_id)
    );

    if ($manual_trigger_failed) {
        $return_message .= ' ' . __("Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.", 'liens-morts-detector-jlg');
    }

    return [
        'success'               => true,
        'message'               => $return_message,
        'manual_trigger_failed' => $manual_trigger_failed,
        'job_id'                => $job_id,
        'attempt'               => $attempt,
        'scheduled_at'          => $scheduled_at,
    ];
}

/**
 * Retrieve the localized label for a scan status.
 *
 * @param string $state Status identifier.
 *
 * @return string
 */
function blc_get_scan_state_label($state) {
    $labels = [
        'idle'      => __('Inactif', 'liens-morts-detector-jlg'),
        'queued'    => __('En file d\'attente', 'liens-morts-detector-jlg'),
        'running'   => __('Analyse en cours', 'liens-morts-detector-jlg'),
        'completed' => __('Terminée', 'liens-morts-detector-jlg'),
        'failed'    => __('Échec', 'liens-morts-detector-jlg'),
        'cancelled' => __('Annulée', 'liens-morts-detector-jlg'),
    ];

    $key = sanitize_key($state);

    if ($key === '' || !isset($labels[$key])) {
        return $labels['idle'];
    }

    return $labels[$key];
}

/**
 * Format a timestamp for display in the history dashboard.
 *
 * @param int $timestamp Unix timestamp.
 *
 * @return string
 */
function blc_format_scan_history_datetime($timestamp) {
    $timestamp = (int) $timestamp;
    if ($timestamp <= 0) {
        return __('—', 'liens-morts-detector-jlg');
    }

    $format = __('d/m/Y H:i', 'liens-morts-detector-jlg');

    if (function_exists('wp_date')) {
        return wp_date($format, $timestamp);
    }

    if (function_exists('date_i18n')) {
        return date_i18n($format, $timestamp); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
    }

    return gmdate($format, $timestamp);
}

/**
 * Format a duration in milliseconds to a human readable string.
 *
 * @param float|int $duration_ms
 *
 * @return string
 */
function blc_format_scan_duration($duration_ms) {
    $duration_ms = (float) $duration_ms;
    if ($duration_ms <= 0) {
        return __('—', 'liens-morts-detector-jlg');
    }

    $milliseconds = (int) round($duration_ms);
    if ($milliseconds < 1000) {
        return sprintf(
            /* translators: %s: duration in milliseconds. */
            __('%s ms', 'liens-morts-detector-jlg'),
            number_format_i18n($milliseconds)
        );
    }

    $total_seconds = (int) floor($milliseconds / 1000);
    $hours         = (int) floor($total_seconds / 3600);
    $minutes       = (int) floor(($total_seconds % 3600) / 60);
    $seconds       = (int) ($total_seconds % 60);

    $parts = [];

    if ($hours > 0) {
        if ($hours === 1) {
            $parts[] = __('1 heure', 'liens-morts-detector-jlg');
        } else {
            $parts[] = sprintf(
                /* translators: %s: number of hours. */
                __('%s heures', 'liens-morts-detector-jlg'),
                number_format_i18n($hours)
            );
        }
    }

    if ($minutes > 0) {
        if ($minutes === 1) {
            $parts[] = __('1 minute', 'liens-morts-detector-jlg');
        } else {
            $parts[] = sprintf(
                /* translators: %s: number of minutes. */
                __('%s minutes', 'liens-morts-detector-jlg'),
                number_format_i18n($minutes)
            );
        }
    }

    if ($seconds > 0 || $parts === []) {
        if ($seconds === 1) {
            $parts[] = __('1 seconde', 'liens-morts-detector-jlg');
        } else {
            $parts[] = sprintf(
                /* translators: %s: number of seconds. */
                __('%s secondes', 'liens-morts-detector-jlg'),
                number_format_i18n($seconds)
            );
        }
    }

    return implode(' ', $parts);
}

/**
 * Format a processed/total pair for display.
 *
 * @param int $processed Number of processed elements.
 * @param int $total     Total number of elements.
 *
 * @return string
 */
function blc_format_scan_progress_value($processed, $total) {
    $processed = max(0, (int) $processed);
    $total     = max(0, (int) $total);

    if ($processed === 0 && $total === 0) {
        return __('—', 'liens-morts-detector-jlg');
    }

    if ($total > 0) {
        return sprintf(
            /* translators: 1: processed count, 2: total count. */
            __('%1$s / %2$s', 'liens-morts-detector-jlg'),
            number_format_i18n($processed),
            number_format_i18n($total)
        );
    }

    return number_format_i18n($processed);
}

/**
 * Format an items-per-minute throughput value.
 *
 * @param float $throughput Items per minute.
 *
 * @return string
 */
function blc_format_scan_throughput($throughput) {
    $throughput = (float) $throughput;
    if ($throughput <= 0) {
        return __('—', 'liens-morts-detector-jlg');
    }

    return sprintf(
        /* translators: %s: items per minute. */
        __('%s éléments/min', 'liens-morts-detector-jlg'),
        number_format_i18n($throughput, 1)
    );
}

/**
 * Retrieve the CSS modifier used to render a state badge in the history dashboard.
 *
 * @param string $state State slug.
 *
 * @return string
 */
function blc_get_history_state_class($state) {
    $state = sanitize_key($state);

    switch ($state) {
        case 'completed':
            return 'is-success';
        case 'failed':
            return 'is-error';
        case 'running':
            return 'is-running';
        case 'queued':
            return 'is-queued';
        case 'cancelled':
            return 'is-cancelled';
        default:
            return 'is-idle';
    }
}

/**
 * Retrieve a localized label for a dataset displayed in the history page.
 *
 * @param string $dataset_type Dataset slug.
 *
 * @return string
 */
function blc_get_history_dataset_label($dataset_type) {
    $dataset_type = is_string($dataset_type) ? $dataset_type : '';

    if ($dataset_type !== '' && function_exists('sanitize_key')) {
        $dataset_type = sanitize_key($dataset_type);
    }

    switch ($dataset_type) {
        case 'link':
            return __('Liens', 'liens-morts-detector-jlg');
        case 'image':
            return __('Images', 'liens-morts-detector-jlg');
        default:
            return '';
    }
}

/**
 * Render the scan history dashboard and metrics explorer.
 */
function blc_scan_history_page() {
    if (!blc_current_user_can_view_reports()) {
        wp_die(esc_html__('Permissions insuffisantes pour consulter les rapports.', 'liens-morts-detector-jlg'));
    }

    $history_entries  = blc_get_link_scan_history();
    $metrics_history  = blc_get_link_scan_metrics_history();
    $insights         = blc_calculate_link_scan_history_insights($history_entries);

    $total_runs        = isset($insights['total_runs']) ? (int) $insights['total_runs'] : 0;
    $completed_runs    = isset($insights['completed_runs']) ? (int) $insights['completed_runs'] : 0;
    $failed_runs       = isset($insights['failed_runs']) ? (int) $insights['failed_runs'] : 0;
    $success_rate      = isset($insights['success_rate']) ? (float) $insights['success_rate'] : 0.0;
    $average_duration  = isset($insights['average_duration_ms']) ? (float) $insights['average_duration_ms'] : 0.0;
    $average_throughput = isset($insights['average_throughput']) ? (float) $insights['average_throughput'] : 0.0;
    $last_job_summary  = isset($insights['last_job_summary']) && is_array($insights['last_job_summary'])
        ? $insights['last_job_summary']
        : [
            'job_id'          => '',
            'state'           => '',
            'scheduled_at'    => 0,
            'started_at'      => 0,
            'ended_at'        => 0,
            'duration_ms'     => 0,
            'processed_items' => 0,
            'total_items'     => 0,
            'attempt'         => 0,
            'is_full_scan'    => false,
        ];

    $success_rate = max(0.0, min(1.0, $success_rate));
    $success_rate_percentage = $success_rate * 100.0;
    $success_rate_display = ($total_runs > 0)
        ? sprintf(
            /* translators: %s: success percentage. */
            __('%s %%', 'liens-morts-detector-jlg'),
            number_format_i18n($success_rate_percentage, 1)
        )
        : __('—', 'liens-morts-detector-jlg');

    $last_duration_display = blc_format_scan_duration($last_job_summary['duration_ms']);
    $last_progress_display = blc_format_scan_progress_value($last_job_summary['processed_items'], $last_job_summary['total_items']);

    $last_job_throughput = 0.0;
    if ($last_job_summary['duration_ms'] > 0 && $last_job_summary['processed_items'] > 0) {
        $minutes = $last_job_summary['duration_ms'] / 60000;
        if ($minutes > 0) {
            $last_job_throughput = $last_job_summary['processed_items'] / $minutes;
        }
    }

    $summary_cards = [
        [
            'label' => __('Analyses totales', 'liens-morts-detector-jlg'),
            'value' => number_format_i18n($total_runs),
            'note'  => ($total_runs > 0)
                ? sprintf(
                    /* translators: 1: completed scans count, 2: failed scans count. */
                    __('%1$s réussies · %2$s en échec', 'liens-morts-detector-jlg'),
                    number_format_i18n($completed_runs),
                    number_format_i18n($failed_runs)
                )
                : __('Aucune analyse enregistrée pour le moment.', 'liens-morts-detector-jlg'),
        ],
        [
            'label' => __('Taux de réussite', 'liens-morts-detector-jlg'),
            'value' => $success_rate_display,
            'note'  => ($total_runs > 0 && $last_job_summary['state'] !== '')
                ? sprintf(
                    /* translators: %s: status label. */
                    __('Dernière analyse : %s', 'liens-morts-detector-jlg'),
                    blc_get_scan_state_label($last_job_summary['state'])
                )
                : '',
        ],
        [
            'label' => __('Durée moyenne', 'liens-morts-detector-jlg'),
            'value' => blc_format_scan_duration($average_duration),
            'note'  => ($last_job_summary['duration_ms'] > 0)
                ? sprintf(
                    /* translators: %s: duration string. */
                    __('Dernière : %s', 'liens-morts-detector-jlg'),
                    $last_duration_display
                )
                : '',
        ],
        [
            'label' => __('Débit moyen', 'liens-morts-detector-jlg'),
            'value' => blc_format_scan_throughput($average_throughput),
            'note'  => ($last_job_throughput > 0)
                ? sprintf(
                    /* translators: %s: items per minute. */
                    __('Dernière : %s', 'liens-morts-detector-jlg'),
                    blc_format_scan_throughput($last_job_throughput)
                )
                : '',
        ],
    ];

    $job_rows = [];
    foreach (array_slice($history_entries, 0, 10) as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $event = isset($entry['event']) ? (string) $entry['event'] : '';
        if ($event === 'reset') {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $timestamp_display = blc_format_scan_history_datetime($timestamp);

            $user_data = isset($entry['user']) && is_array($entry['user']) ? $entry['user'] : [];
            $user_name = '';
            if (isset($user_data['display_name'])) {
                $user_name = trim((string) $user_data['display_name']);
            }
            if ($user_name === '' && isset($user_data['login'])) {
                $user_name = trim((string) $user_data['login']);
            }
            if ($user_name === '') {
                $user_name = __('Utilisateur inconnu', 'liens-morts-detector-jlg');
            }

            $dataset_label = blc_get_history_dataset_label($entry['dataset_type'] ?? '');

            $description = sprintf(
                /* translators: 1: user display name, 2: formatted date. */
                __('Déclenchée par %1$s le %2$s', 'liens-morts-detector-jlg'),
                $user_name,
                $timestamp_display
            );

            $announcement = ($dataset_label !== '')
                ? sprintf(
                    /* translators: 1: user display name, 2: formatted date, 3: dataset label. */
                    __('Remise à zéro manuelle déclenchée par %1$s le %2$s pour le jeu de données %3$s.', 'liens-morts-detector-jlg'),
                    $user_name,
                    $timestamp_display,
                    $dataset_label
                )
                : sprintf(
                    /* translators: 1: user display name, 2: formatted date. */
                    __('Remise à zéro manuelle déclenchée par %1$s le %2$s.', 'liens-morts-detector-jlg'),
                    $user_name,
                    $timestamp_display
                );

            $dataset_note = ($dataset_label !== '')
                ? sprintf(
                    /* translators: %s: dataset label. */
                    __('Jeu de données : %s', 'liens-morts-detector-jlg'),
                    $dataset_label
                )
                : '';

            $job_rows[] = [
                'type'         => 'reset',
                'timestamp'    => $timestamp_display,
                'description'  => $description,
                'dataset_note' => $dataset_note,
                'announcement' => $announcement,
            ];

            continue;
        }

        $job_id = isset($entry['job_id']) ? (string) $entry['job_id'] : '';
        $state_raw = isset($entry['state']) ? (string) $entry['state'] : '';
        $state = sanitize_key($state_raw);
        $state_label = blc_get_scan_state_label($state);
        $state_class = blc_get_history_state_class($state);
        $is_full_scan = !empty($entry['is_full_scan']);
        $mode_label = $is_full_scan
            ? __('Complet', 'liens-morts-detector-jlg')
            : __('Incrémental', 'liens-morts-detector-jlg');
        $attempt = isset($entry['attempt']) ? max(1, (int) $entry['attempt']) : 1;

        $metrics = isset($entry['metrics']) && is_array($entry['metrics']) ? $entry['metrics'] : [];

        $duration_ms = 0;
        if (isset($metrics['duration_ms'])) {
            $duration_ms = max(0, (int) $metrics['duration_ms']);
        } elseif (isset($entry['started_at'], $entry['ended_at'])) {
            $start = (int) $entry['started_at'];
            $end   = (int) $entry['ended_at'];
            if ($start > 0 && $end >= $start) {
                $duration_ms = ($end - $start) * 1000;
            }
        }

        $processed_items = 0;
        if (isset($metrics['processed_items'])) {
            $processed_items = max(0, (int) $metrics['processed_items']);
        } elseif (isset($entry['processed_items'])) {
            $processed_items = max(0, (int) $entry['processed_items']);
        }

        $total_items = 0;
        if (isset($metrics['total_items'])) {
            $total_items = max(0, (int) $metrics['total_items']);
        } elseif (isset($entry['total_items'])) {
            $total_items = max(0, (int) $entry['total_items']);
        }

        $processed_batches = 0;
        if (isset($metrics['processed_batches'])) {
            $processed_batches = max(0, (int) $metrics['processed_batches']);
        } elseif (isset($entry['processed_batches'])) {
            $processed_batches = max(0, (int) $entry['processed_batches']);
        }

        $total_batches = 0;
        if (isset($metrics['total_batches'])) {
            $total_batches = max(0, (int) $metrics['total_batches']);
        } elseif (isset($entry['total_batches'])) {
            $total_batches = max(0, (int) $entry['total_batches']);
        }

        $notes = [];
        if (!empty($entry['manual_trigger_failed'])) {
            $notes[] = __('Déclenchement manuel WP-Cron en échec.', 'liens-morts-detector-jlg');
        }
        if (!empty($entry['manual_trigger_error'])) {
            $notes[] = sprintf(
                /* translators: %s: error message. */
                __('Erreur : %s', 'liens-morts-detector-jlg'),
                (string) $entry['manual_trigger_error']
            );
        }
        if (!empty($entry['bypass_rest_window'])) {
            $notes[] = __('Fenêtre de repos contournée pour ce job.', 'liens-morts-detector-jlg');
        }

        $job_rows[] = [
            'job_id'            => $job_id,
            'state_label'      => $state_label,
            'state_class'      => $state_class,
            'mode_label'       => $mode_label,
            'attempt'          => $attempt,
            'scheduled_at'     => blc_format_scan_history_datetime($entry['scheduled_at'] ?? 0),
            'started_at'       => blc_format_scan_history_datetime($entry['started_at'] ?? 0),
            'ended_at'         => blc_format_scan_history_datetime($entry['ended_at'] ?? 0),
            'duration'         => blc_format_scan_duration($duration_ms),
            'items'            => blc_format_scan_progress_value($processed_items, $total_items),
            'batches'          => blc_format_scan_progress_value($processed_batches, $total_batches),
            'message'          => isset($entry['message']) ? (string) $entry['message'] : '',
            'last_error'       => isset($entry['last_error']) ? (string) $entry['last_error'] : '',
            'notes'            => $notes,
        ];
    }

    $metric_rows = [];
    foreach (array_slice($metrics_history, 0, 15) as $metric) {
        if (!is_array($metric)) {
            continue;
        }

        $job_id = isset($metric['job_id']) ? (string) $metric['job_id'] : '';
        $batch   = isset($metric['batch']) ? (int) $metric['batch'] : null;
        $attempt = isset($metric['attempt']) ? max(0, (int) $metric['attempt']) : 0;
        $duration_ms = isset($metric['duration_ms']) ? max(0, (int) $metric['duration_ms']) : 0;
        $processed_items = isset($metric['processed_items']) ? max(0, (int) $metric['processed_items']) : 0;
        $total_items     = isset($metric['total_items']) ? max(0, (int) $metric['total_items']) : 0;
        $processed_batches = isset($metric['processed_batches']) ? max(0, (int) $metric['processed_batches']) : 0;
        $total_batches     = isset($metric['total_batches']) ? max(0, (int) $metric['total_batches']) : 0;
        $state = isset($metric['state']) ? sanitize_key((string) $metric['state']) : '';
        $is_success = !empty($metric['success']);
        $is_full_scan = !empty($metric['is_full_scan']);

        $result_parts = [];
        $result_parts[] = $is_success
            ? __('Succès', 'liens-morts-detector-jlg')
            : __('Erreur', 'liens-morts-detector-jlg');
        if ($state !== '') {
            $result_parts[] = sprintf(
                /* translators: %s: state label. */
                __('État : %s', 'liens-morts-detector-jlg'),
                blc_get_scan_state_label($state)
            );
        }

        $metric_rows[] = [
            'timestamp'      => blc_format_scan_history_datetime($metric['timestamp'] ?? 0),
            'job_id'         => $job_id,
            'batch'          => $batch,
            'attempt'        => $attempt,
            'mode_label'     => $is_full_scan
                ? __('Complet', 'liens-morts-detector-jlg')
                : __('Incrémental', 'liens-morts-detector-jlg'),
            'duration'       => blc_format_scan_duration($duration_ms),
            'items'          => blc_format_scan_progress_value($processed_items, $total_items),
            'batches'        => blc_format_scan_progress_value($processed_batches, $total_batches),
            'result'         => implode(' · ', $result_parts),
            'result_class'   => $is_success ? 'is-success' : 'is-error',
        ];
    }

    $last_job_state_class = blc_get_history_state_class($last_job_summary['state']);
    $last_job_mode_label = $last_job_summary['is_full_scan']
        ? __('Complet', 'liens-morts-detector-jlg')
        : __('Incrémental', 'liens-morts-detector-jlg');
    $last_job_attempt = max(1, (int) $last_job_summary['attempt']);

    ?>
    <div class="wrap blc-history-page">
        <?php blc_render_dashboard_tabs('history'); ?>
        <h1><?php esc_html_e('Historique des Analyses', 'liens-morts-detector-jlg'); ?></h1>

        <div class="blc-stats-box blc-admin-card blc-admin-card--accent">
            <?php foreach ($summary_cards as $card) :
                $note = isset($card['note']) ? (string) $card['note'] : '';
                ?>
                <div class="blc-stat blc-stat--static">
                    <span class="blc-stat-value"><?php echo esc_html($card['value']); ?></span>
                    <span class="blc-stat-label"><?php echo esc_html($card['label']); ?></span>
                    <?php if ($note !== '') : ?>
                        <span class="blc-stat-note"><?php echo esc_html($note); ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="blc-history-last-run blc-admin-card blc-admin-card--accent">
            <h2><?php esc_html_e('Dernière analyse', 'liens-morts-detector-jlg'); ?></h2>
            <?php if ($total_runs === 0 || $last_job_summary['job_id'] === '') : ?>
                <p class="blc-history-empty-message"><?php esc_html_e('Aucune analyse n’a été enregistrée pour le moment.', 'liens-morts-detector-jlg'); ?></p>
            <?php else : ?>
                <div class="blc-history-last-run__header">
                    <span class="blc-history-state <?php echo esc_attr($last_job_state_class); ?>"><?php echo esc_html(blc_get_scan_state_label($last_job_summary['state'])); ?></span>
                    <span class="blc-history-last-run__job"><?php printf(
                        /* translators: %s: job identifier. */
                        esc_html__('Job : %s', 'liens-morts-detector-jlg'),
                        esc_html($last_job_summary['job_id'])
                    ); ?></span>
                    <span class="blc-history-last-run__mode"><?php printf(
                        /* translators: %1$s: scan mode label, %2$s: attempt number. */
                        esc_html__('%1$s · tentative %2$s', 'liens-morts-detector-jlg'),
                        esc_html($last_job_mode_label),
                        esc_html(number_format_i18n($last_job_attempt))
                    ); ?></span>
                </div>
                <ul class="blc-history-last-run__details">
                    <li><strong><?php esc_html_e('Planifié', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html(blc_format_scan_history_datetime($last_job_summary['scheduled_at'])); ?></li>
                    <li><strong><?php esc_html_e('Démarré', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html(blc_format_scan_history_datetime($last_job_summary['started_at'])); ?></li>
                    <li><strong><?php esc_html_e('Terminé', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html(blc_format_scan_history_datetime($last_job_summary['ended_at'])); ?></li>
                    <li><strong><?php esc_html_e('Durée', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($last_duration_display); ?></li>
                    <li><strong><?php esc_html_e('Liens traités', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($last_progress_display); ?></li>
                </ul>
            <?php endif; ?>
        </section>

        <section class="blc-history-table-section">
            <h2><?php esc_html_e('Historique des exécutions', 'liens-morts-detector-jlg'); ?></h2>
            <table class="widefat fixed striped blc-history-table">
                <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Job', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Statut', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Horodatages', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Performance', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Messages', 'liens-morts-detector-jlg'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($job_rows === []) : ?>
                    <tr>
                        <td colspan="5" class="blc-history-empty-message"><?php esc_html_e('Aucune exécution récente à afficher.', 'liens-morts-detector-jlg'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($job_rows as $row) :
                        if (($row['type'] ?? '') === 'reset') :
                            ?>
                            <tr class="blc-history-reset-row">
                                <td colspan="5">
                                    <div class="blc-history-reset" role="status" aria-live="polite" aria-label="<?php echo esc_attr($row['announcement']); ?>">
                                        <span class="blc-history-reset__title"><?php esc_html_e('Remise à zéro manuelle', 'liens-morts-detector-jlg'); ?></span>
                                        <span class="blc-history-reset__meta"><?php echo esc_html($row['description']); ?></span>
                                        <?php if (!empty($row['dataset_note'])) : ?>
                                            <span class="blc-history-reset__note"><?php echo esc_html($row['dataset_note']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php
                            continue;
                        endif;
                        $job_id_display = $row['job_id'] !== '' ? $row['job_id'] : __('(inconnu)', 'liens-morts-detector-jlg');
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($job_id_display); ?></strong>
                                <div class="blc-history-meta"><?php printf(
                                    /* translators: 1: scan mode, 2: attempt number. */
                                    esc_html__('%1$s · tentative %2$s', 'liens-morts-detector-jlg'),
                                    esc_html($row['mode_label']),
                                    esc_html(number_format_i18n($row['attempt']))
                                ); ?></div>
                            </td>
                            <td>
                                <span class="blc-history-state <?php echo esc_attr($row['state_class']); ?>"><?php echo esc_html($row['state_label']); ?></span>
                            </td>
                            <td>
                                <ul class="blc-history-list">
                                    <li><strong><?php esc_html_e('Planifié', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['scheduled_at']); ?></li>
                                    <li><strong><?php esc_html_e('Démarré', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['started_at']); ?></li>
                                    <li><strong><?php esc_html_e('Terminé', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['ended_at']); ?></li>
                                </ul>
                            </td>
                            <td>
                                <ul class="blc-history-list">
                                    <li><strong><?php esc_html_e('Durée', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['duration']); ?></li>
                                    <li><strong><?php esc_html_e('Liens', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['items']); ?></li>
                                    <li><strong><?php esc_html_e('Lots', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($row['batches']); ?></li>
                                </ul>
                            </td>
                            <td>
                                <?php if ($row['message'] !== '') : ?>
                                    <p class="blc-history-message"><?php echo esc_html($row['message']); ?></p>
                                <?php endif; ?>
                                <?php if ($row['last_error'] !== '') : ?>
                                    <p class="blc-history-error"><?php echo esc_html($row['last_error']); ?></p>
                                <?php endif; ?>
                                <?php if ($row['notes'] !== []) : ?>
                                    <ul class="blc-history-notes">
                                        <?php foreach ($row['notes'] as $note) : ?>
                                            <li><?php echo esc_html($note); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="blc-history-table-section">
            <h2><?php esc_html_e('Métriques par lot', 'liens-morts-detector-jlg'); ?></h2>
            <table class="widefat fixed striped blc-history-table">
                <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Horodatage', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Job & lot', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Durée', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Progression', 'liens-morts-detector-jlg'); ?></th>
                    <th scope="col"><?php esc_html_e('Résultat', 'liens-morts-detector-jlg'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($metric_rows === []) : ?>
                    <tr>
                        <td colspan="5" class="blc-history-empty-message"><?php esc_html_e('Aucune métrique enregistrée pour le moment.', 'liens-morts-detector-jlg'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($metric_rows as $metric_row) :
                        $batch = $metric_row['batch'];
                        $batch_display = '—';
                        if ($batch !== null) {
                            $batch_number = $batch + 1;
                            if ($batch_number < 1) {
                                $batch_number = $batch + 1;
                            }
                            $batch_display = sprintf(
                                /* translators: %s: batch number. */
                                __('Lot #%s', 'liens-morts-detector-jlg'),
                                number_format_i18n(max(1, $batch_number))
                            );
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($metric_row['timestamp']); ?></td>
                            <td>
                                <strong><?php echo esc_html($metric_row['job_id'] !== '' ? $metric_row['job_id'] : __('(inconnu)', 'liens-morts-detector-jlg')); ?></strong>
                                <div class="blc-history-meta"><?php echo esc_html($batch_display); ?></div>
                                <div class="blc-history-meta"><?php printf(
                                    /* translators: 1: scan mode, 2: attempt number. */
                                    esc_html__('%1$s · tentative %2$s', 'liens-morts-detector-jlg'),
                                    esc_html($metric_row['mode_label']),
                                    esc_html(number_format_i18n(max(1, $metric_row['attempt'])))
                                ); ?></div>
                            </td>
                            <td><?php echo esc_html($metric_row['duration']); ?></td>
                            <td>
                                <ul class="blc-history-list">
                                    <li><strong><?php esc_html_e('Liens', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($metric_row['items']); ?></li>
                                    <li><strong><?php esc_html_e('Lots', 'liens-morts-detector-jlg'); ?> :</strong> <?php echo esc_html($metric_row['batches']); ?></li>
                                </ul>
                            </td>
                            <td>
                                <span class="blc-history-state <?php echo esc_attr($metric_row['result_class']); ?>"><?php echo esc_html($metric_row['result']); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>
    <?php
}

/**
 * Affiche la page du rapport des LIENS cassés.
 */
function blc_dashboard_links_page() {
    if (!blc_current_user_can_view_reports()) {
        wp_die(esc_html__('Permissions insuffisantes pour consulter les rapports.', 'liens-morts-detector-jlg'));
    }

    // Gère le lancement d'une vérification manuelle des liens
    if (isset($_POST['blc_manual_check'])) {
        check_admin_referer('blc_manual_check_nonce');

        if (!blc_current_user_can_fix_links()) {
            wp_die(esc_html__('Permissions insuffisantes pour lancer une analyse manuelle.', 'liens-morts-detector-jlg'));
        }

        $is_full = isset($_POST['blc_full_scan']);
        $schedule_result = blc_schedule_manual_link_scan($is_full);

        if (!$schedule_result['success']) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($schedule_result['message'])
            );
        } else {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($schedule_result['message'])
            );

            if (!empty($schedule_result['manual_trigger_failed'])) {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    esc_html__(
                        "Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.",
                        'liens-morts-detector-jlg'
                    )
                );
            }
        }
    }

    if (isset($_POST['blc_reschedule_cron'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié via wp_nonce_field.
        check_admin_referer('blc_reschedule_cron_nonce');

        if (!blc_current_user_can_fix_links()) {
            wp_die(esc_html__('Permissions insuffisantes pour reprogrammer la vérification automatique.', 'liens-morts-detector-jlg'));
        }

        $reschedule_payload = blc_reschedule_link_scan_event('dashboard');

        if (!$reschedule_payload['success']) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($reschedule_payload['message'])
            );
        } else {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($reschedule_payload['message'])
            );
        }

        if (!empty($reschedule_payload['warnings'])) {
            foreach ($reschedule_payload['warnings'] as $warning_message) {
                printf(
                    '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                    esc_html($warning_message)
                );
            }
        }
    }

    $scan_status = blc_get_link_scan_status_payload();
    $scan_state_slug = isset($scan_status['state']) ? sanitize_key($scan_status['state']) : 'idle';
    if ($scan_state_slug === '') {
        $scan_state_slug = 'idle';
    }
    $scan_state_label = blc_get_scan_state_label($scan_state_slug);

    $total_batches_display = isset($scan_status['total_batches']) ? (int) $scan_status['total_batches'] : 0;
    if ($total_batches_display <= 0 && in_array($scan_state_slug, array('queued', 'running'), true)) {
        $total_batches_display = max(1, (int) $scan_status['processed_batches']);
    }
    $total_batches_display = max(0, $total_batches_display);
    $processed_batches_display = isset($scan_status['processed_batches']) ? (int) $scan_status['processed_batches'] : 0;
    if ($total_batches_display > 0) {
        $processed_batches_display = max(0, min($processed_batches_display, $total_batches_display));
    }

    $initial_progress = 0;
    if ($total_batches_display > 0) {
        $initial_progress = (int) round(($processed_batches_display / $total_batches_display) * 100);
    } elseif ('completed' === $scan_state_slug) {
        $initial_progress = 100;
    }
    $initial_progress = max(0, min(100, $initial_progress));

    $scan_details_parts = [];

    if (in_array($scan_state_slug, array('running', 'queued'), true)) {
        if ($total_batches_display > 0) {
            $scan_details_parts[] = sprintf(
                /* translators: 1: current batch number, 2: total batch count. */
                __('Lot %1$d sur %2$d.', 'liens-morts-detector-jlg'),
                max(1, $processed_batches_display),
                max(1, $total_batches_display)
            );
        } else {
            $scan_details_parts[] = __('Préparation du prochain lot…', 'liens-morts-detector-jlg');
        }
    } elseif ('completed' === $scan_state_slug) {
        $scan_details_parts[] = __('Analyse terminée.', 'liens-morts-detector-jlg');
    } elseif ('failed' === $scan_state_slug) {
        $scan_details_parts[] = __('Dernier scan en échec.', 'liens-morts-detector-jlg');
    } elseif ('cancelled' === $scan_state_slug) {
        $scan_details_parts[] = __('Scan annulé.', 'liens-morts-detector-jlg');
    } else {
        $scan_details_parts[] = __('Aucun scan manuel en cours.', 'liens-morts-detector-jlg');
    }

    if (!empty($scan_status['message']) && is_string($scan_status['message'])) {
        $scan_details_parts[] = $scan_status['message'];
    }

    $next_batch_timestamp = isset($scan_status['next_batch_timestamp'])
        ? (int) $scan_status['next_batch_timestamp']
        : 0;

    if ($next_batch_timestamp > 0 && in_array($scan_state_slug, array('queued', 'running'), true)) {
        $scan_details_parts[] = sprintf(
            /* translators: %s: next batch scheduled date. */
            __('Prochain lot prévu à %s.', 'liens-morts-detector-jlg'),
            wp_date('j M Y H:i', $next_batch_timestamp)
        );
    }

    $scan_details = trim(implode(' ', array_filter(array_map('strval', $scan_details_parts))));

    $summary_items = blc_build_dashboard_summary_items($scan_status, $scan_details);

    $scan_panel_classes = array_filter(
        array(
            'blc-scan-status',
            'blc-admin-card',
            'blc-scan-status--state-' . $scan_state_slug,
            in_array($scan_state_slug, array('running', 'queued'), true) ? 'is-active' : '',
            'completed' === $scan_state_slug ? 'is-completed' : '',
            'failed' === $scan_state_slug ? 'is-failed' : '',
            'cancelled' === $scan_state_slug ? 'is-cancelled' : '',
        )
    );

    $scan_panel_class_attr = implode(' ', array_map('sanitize_html_class', $scan_panel_classes));

    $next_scheduled_timestamp = wp_next_scheduled('blc_check_links');
    $next_schedule_slug       = wp_get_schedule('blc_check_links');
    $next_scheduled_display   = $next_scheduled_timestamp
        ? wp_date('j M Y H:i', $next_scheduled_timestamp)
        : __('Non planifiée', 'liens-morts-detector-jlg');

    $schedule_labels = array(
        'blc_hourly'       => __('Toutes les heures', 'liens-morts-detector-jlg'),
        'blc_six_hours'    => __('Toutes les 6 heures', 'liens-morts-detector-jlg'),
        'blc_twelve_hours' => __('Toutes les 12 heures', 'liens-morts-detector-jlg'),
        'daily'            => __('Quotidienne', 'liens-morts-detector-jlg'),
        'weekly'           => __('Hebdomadaire', 'liens-morts-detector-jlg'),
        'monthly'          => __('Mensuelle', 'liens-morts-detector-jlg'),
    );

    $schedule_note = '';

    if ('blc_custom_interval' === $next_schedule_slug) {
        $custom_hours = blc_get_custom_frequency_hours();
        $custom_time  = blc_get_custom_frequency_time();
        $schedule_note = sprintf(
            /* translators: 1: number of hours, 2: time of day. */
            __('Toutes les %1$d heures (démarrage à %2$s)', 'liens-morts-detector-jlg'),
            $custom_hours,
            $custom_time
        );
    } elseif ($next_schedule_slug && isset($schedule_labels[$next_schedule_slug])) {
        $schedule_note = $schedule_labels[$next_schedule_slug];
    }

    $timezone_label = blc_get_timezone_label();

    if ('' !== $schedule_note && '' !== $timezone_label) {
        $schedule_note = sprintf(
            /* translators: 1: recurrence label, 2: timezone label. */
            __('%1$s — Fuseau : %2$s', 'liens-morts-detector-jlg'),
            $schedule_note,
            $timezone_label
        );
    } elseif ('' === $schedule_note && '' !== $timezone_label) {
        $schedule_note = sprintf(
            /* translators: %s: timezone label. */
            __('Fuseau : %s', 'liens-morts-detector-jlg'),
            $timezone_label
        );
    }

    // Préparation des données et des statistiques pour les liens
    $list_table = new BLC_Links_List_Table();
    $list_table->set_request_args(blc_normalize_links_table_request($_GET));
    $status_counts = $list_table->get_status_counts();

    $status_counts = is_array($status_counts) ? $status_counts : [];
    $count_keys = [
        'active_count'        => 0,
        'not_found_count'     => 0,
        'server_error_count'  => 0,
        'redirect_count'      => 0,
        'needs_recheck_count' => 0,
    ];

    foreach ($count_keys as $key => $default_value) {
        $count_keys[$key] = isset($status_counts[$key]) ? (int) $status_counts[$key] : $default_value;
    }

    $broken_links_count  = $count_keys['active_count'];
    $not_found_count     = $count_keys['not_found_count'];
    $server_error_count  = $count_keys['server_error_count'];
    $redirect_count      = $count_keys['redirect_count'];
    $needs_recheck_count = $count_keys['needs_recheck_count'];

    $stats_card_blueprint = array(
        'all'        => array(
            'link_type' => 'all',
            'label'     => __('Liens morts trouvés', 'liens-morts-detector-jlg'),
            'count'     => $broken_links_count,
        ),
        '404'        => array(
            'link_type' => 'status_404_410',
            'label'     => __('Erreurs 404 / 410', 'liens-morts-detector-jlg'),
            'count'     => $not_found_count,
        ),
        '5xx'        => array(
            'link_type' => 'status_5xx',
            'label'     => __('Erreurs 5xx', 'liens-morts-detector-jlg'),
            'count'     => $server_error_count,
        ),
        'redirects'  => array(
            'link_type' => 'status_redirects',
            'label'     => __('Redirections détectées', 'liens-morts-detector-jlg'),
            'count'     => $redirect_count,
        ),
        'recheck'    => array(
            'link_type' => 'needs_recheck',
            'label'     => __('Liens à revérifier', 'liens-morts-detector-jlg'),
            'count'     => $needs_recheck_count,
        ),
    );

    $dashboard_base_url = function_exists('admin_url')
        ? admin_url('admin.php?page=blc-dashboard')
        : 'admin.php?page=blc-dashboard';

    $build_dashboard_url = static function($link_type) use ($dashboard_base_url) {
        if ($link_type === 'all') {
            return $dashboard_base_url;
        }

        if (function_exists('add_query_arg')) {
            return add_query_arg('link_type', $link_type, $dashboard_base_url);
        }

        $separator = (false === strpos($dashboard_base_url, '?')) ? '?' : '&';

        return $dashboard_base_url . $separator . 'link_type=' . rawurlencode($link_type);
    };

    $stats_cards = array();

    foreach ($stats_card_blueprint as $slug => $card_data) {
        $stats_cards[] = array(
            'slug'      => $slug,
            'link_type' => $card_data['link_type'],
            'count'     => (int) $card_data['count'],
            'value'     => number_format_i18n($card_data['count']),
            'label'     => $card_data['label'],
            'cta_label' => blc_get_stat_card_cta_label($slug, (int) $card_data['count']),
        );
    }
    $option_size_bytes  = blc_get_dataset_storage_footprint_bytes('link');
    $last_check_time    = get_option('blc_last_check_time', 0);
    $option_size_kb     = $option_size_bytes / 1024;
    $size_display       = ($option_size_kb < 1024)
        ? sprintf('%s %s', number_format_i18n($option_size_kb, 2), __('Ko', 'liens-morts-detector-jlg'))
        : sprintf('%s %s', number_format_i18n($option_size_kb / 1024, 2), __('Mo', 'liens-morts-detector-jlg'));
    $last_check_display = $last_check_time
        ? wp_date('j M Y', $last_check_time)
        : __('Jamais', 'liens-morts-detector-jlg');
    $top_domains        = blc_get_top_broken_link_domains(5);
    $trend_points       = blc_get_link_scan_trend_points(12);
    $trend_summary      = blc_summarize_link_scan_trend($trend_points);
    $severity_overview  = blc_get_link_severity_overview($count_keys);
    $priority_actions   = blc_build_priority_action_queue($top_domains, $dashboard_base_url, $count_keys);

    $list_table->prepare_items();
    $table_state = $list_table->export_state();
    $current_link_type = isset($table_state['view']) && $table_state['view'] !== ''
        ? $table_state['view']
        : 'all';
    $current_sorting = isset($table_state['sorting']) && is_array($table_state['sorting'])
        ? $table_state['sorting']
        : array('orderby' => '', 'order' => 'desc');
    $current_search = isset($table_state['search']) ? (string) $table_state['search'] : '';
    $current_post_type_filter = isset($table_state['post_type']) ? (string) $table_state['post_type'] : '';
    $pagination_state = isset($table_state['pagination']) && is_array($table_state['pagination'])
        ? $table_state['pagination']
        : array('current' => 1, 'per_page' => 20, 'total_items' => 0, 'total_pages' => 1);
    $initial_state_json = wp_json_encode($table_state);
    if (!is_string($initial_state_json)) {
        $initial_state_json = '{}';
    }
    $ajax_endpoint = function_exists('admin_url') ? admin_url('admin-ajax.php') : 'admin-ajax.php';
    $ajax_nonce    = wp_create_nonce('blc_links_table');
    $table_markup  = blc_render_links_table_markup($list_table);
    $cache_ttl     = (int) apply_filters('blc_links_table_cache_ttl', 60);
    if ($cache_ttl < 5) {
        $cache_ttl = 60;
    }
    $saved_views_raw     = blc_get_saved_link_views();
    $saved_views_payload = blc_prepare_link_views_for_client($saved_views_raw);
    $saved_views_json    = wp_json_encode($saved_views_payload);
    if (!is_string($saved_views_json)) {
        $saved_views_json = '[]';
    }
    $saved_views_nonce = wp_create_nonce('blc_links_views');
    $saved_views_limit = blc_get_saved_link_views_limit();
    blc_render_action_modal();

    ?>
    <div class="wrap blc-dashboard-links-page">
        <?php blc_render_dashboard_tabs('links'); ?>
        <h1><?php esc_html_e('Rapport des Liens Cassés', 'liens-morts-detector-jlg'); ?></h1>
        <?php if (!empty($summary_items)) : ?>
            <section class="blc-dashboard-summary blc-admin-card blc-admin-card--subtle" aria-labelledby="blc-dashboard-summary-heading">
                <div class="blc-dashboard-summary__header">
                    <h2 id="blc-dashboard-summary-heading" class="blc-dashboard-summary__title"><?php esc_html_e('Synthèse opérationnelle', 'liens-morts-detector-jlg'); ?></h2>
                    <p class="blc-dashboard-summary__subtitle"><?php esc_html_e('Mesures clés actualisées selon le dernier scan manuel.', 'liens-morts-detector-jlg'); ?></p>
                </div>
                <ul class="blc-dashboard-summary__grid" role="list">
                    <?php foreach ($summary_items as $item) :
                        $variant = isset($item['variant']) ? (string) $item['variant'] : 'neutral';
                        ?>
                        <li
                            class="blc-dashboard-summary__item blc-dashboard-summary__item--<?php echo esc_attr($variant); ?>"
                            data-summary-metric="<?php echo esc_attr($item['slug']); ?>"
                        >
                            <span class="blc-dashboard-summary__label"><?php echo esc_html($item['label']); ?></span>
                            <span class="blc-dashboard-summary__value" data-summary-field="<?php echo esc_attr($item['slug'] . '-value'); ?>"><?php echo esc_html($item['value']); ?></span>
                            <p class="blc-dashboard-summary__description" data-summary-field="<?php echo esc_attr($item['slug'] . '-description'); ?>"><?php echo esc_html($item['description']); ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <div class="blc-stats-box blc-admin-card blc-admin-card--accent">
            <?php foreach ($stats_cards as $card) :
                $link_type = $card['link_type'];
                $card_url  = $build_dashboard_url($link_type);
                $aria_label = sprintf(
                    /* translators: 1: Stat label, 2: number of items. */
                    __('Afficher %1$s (%2$s)', 'liens-morts-detector-jlg'),
                    $card['label'],
                    $card['value']
                );
                $is_active_card = ($link_type === 'all' && $current_link_type === 'all')
                    || ($link_type !== 'all' && $current_link_type === $link_type);
                $card_classes = array(
                    'blc-stat',
                    'blc-stat--' . $card['slug'],
                );

                if ($is_active_card) {
                    $card_classes[] = 'is-active';
                    $card_classes[] = 'blc-stat--has-status';
                    $aria_label .= ' ' . __('(filtre actif)', 'liens-morts-detector-jlg');
                }

                $active_badge = $is_active_card
                    ? '<span class="blc-stat__status" aria-hidden="true">' . esc_html__('Actif', 'liens-morts-detector-jlg') . '</span>'
                    : '';
                $active_sr_hint = $is_active_card
                    ? '<span class="screen-reader-text">' . esc_html__('(filtre actif)', 'liens-morts-detector-jlg') . '</span>'
                    : '';
                ?>
                <a
                    class="<?php echo esc_attr(implode(' ', array_filter(array_map('sanitize_html_class', $card_classes)))); ?>"
                    href="<?php echo esc_url($card_url); ?>"
                    data-link-type="<?php echo esc_attr($link_type); ?>"
                    aria-label="<?php echo esc_attr($aria_label); ?>"
                    <?php echo $is_active_card ? ' aria-current="page"' : ''; ?>
                >
                    <?php echo $active_badge; ?>
                    <span class="blc-stat-value"><?php echo esc_html($card['value']); ?></span>
                    <span class="blc-stat-label"><?php echo esc_html($card['label']); ?></span>
                    <?php if (!empty($card['cta_label'])) : ?>
                        <span class="blc-stat-cta"><?php echo esc_html($card['cta_label']); ?></span>
                    <?php endif; ?>
                    <?php echo $active_sr_hint; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($trend_points)) : ?>
            <section class="blc-dashboard-insights blc-admin-card blc-admin-card--subtle" aria-labelledby="blc-dashboard-trends-heading">
                <div class="blc-dashboard-insights__column blc-dashboard-insights__column--trend">
                    <h2 id="blc-dashboard-trends-heading" class="blc-dashboard-insights__title"><?php esc_html_e('Tendance des analyses', 'liens-morts-detector-jlg'); ?></h2>
                    <p class="blc-dashboard-insights__summary"><?php echo esc_html($trend_summary['latest_label']); ?></p>
                    <p class="blc-dashboard-insights__delta blc-dashboard-insights__delta--<?php echo esc_attr($trend_summary['direction']); ?>"><?php echo esc_html($trend_summary['delta_label']); ?></p>
                    <figure class="blc-sparkline-card">
                        <div
                            class="blc-sparkline"
                            data-blc-points="<?php echo esc_attr(wp_json_encode($trend_points)); ?>"
                            data-empty-message="<?php echo esc_attr__('Pas assez de données pour afficher la tendance.', 'liens-morts-detector-jlg'); ?>"
                            role="img"
                            aria-label="<?php echo esc_attr__('Évolution du volume d’URL analysées', 'liens-morts-detector-jlg'); ?>"
                        ></div>
                        <figcaption class="screen-reader-text">
                            <?php
                            $trend_descriptions = array();
                            foreach ($trend_points as $point) {
                                $trend_descriptions[] = sprintf(
                                    /* translators: 1: scan date, 2: processed URLs. */
                                    __('%1$s : %2$s URL', 'liens-morts-detector-jlg'),
                                    isset($point['label']) ? (string) $point['label'] : '',
                                    isset($point['formatted']) ? (string) $point['formatted'] : ''
                                );
                            }
                            echo esc_html(implode(', ', $trend_descriptions));
                            ?>
                        </figcaption>
                    </figure>
                </div>
                <div class="blc-dashboard-insights__column blc-dashboard-insights__column--severity">
                    <h2 id="blc-dashboard-severity-heading" class="blc-dashboard-insights__title"><?php esc_html_e('Répartition par sévérité', 'liens-morts-detector-jlg'); ?></h2>
                    <ul class="blc-severity-list" role="list">
                        <?php foreach ($severity_overview as $segment) :
                            $progress_label = sprintf(
                                /* translators: 1: severity label, 2: number of links, 3: percentage. */
                                __('%1$s : %2$s lien(s), %3$s %% du total', 'liens-morts-detector-jlg'),
                                $segment['label'],
                                $segment['formatted_count'],
                                $segment['formatted_percentage']
                            );
                            ?>
                            <li class="blc-severity-list__item">
                                <div class="blc-severity-list__header">
                                    <span class="blc-severity-list__label"><?php echo esc_html($segment['label']); ?></span>
                                    <span class="blc-severity-list__value"><?php echo esc_html($segment['formatted_count']); ?></span>
                                </div>
                                <div
                                    class="blc-severity-list__bar"
                                    role="progressbar"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    aria-valuenow="<?php echo esc_attr(round($segment['percentage'], 1)); ?>"
                                    aria-valuetext="<?php echo esc_attr($progress_label); ?>"
                                >
                                    <span
                                        class="blc-severity-list__fill <?php echo esc_attr($segment['class']); ?>"
                                        style="width: <?php echo esc_attr(min(100, max(0, $segment['percentage']))); ?>%;"
                                        aria-hidden="true"
                                    ></span>
                                </div>
                                <span class="blc-severity-list__percentage"><?php echo esc_html($segment['formatted_percentage']); ?>%</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>
        <div class="blc-meta-box blc-admin-card blc-admin-card--subtle">
            <div class="blc-meta">
                <span class="blc-meta-value"><?php echo esc_html($size_display); ?></span>
                <span class="blc-meta-label"><?php esc_html_e('Poids des données', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-meta">
                <span class="blc-meta-value"><?php echo esc_html($last_check_display); ?></span>
                <span class="blc-meta-label"><?php esc_html_e('Dernière analyse', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-meta">
                <span class="blc-meta-value"><?php echo esc_html($next_scheduled_display); ?></span>
                <span class="blc-meta-label"><?php esc_html_e('Prochaine analyse automatique', 'liens-morts-detector-jlg'); ?></span>
                <?php if ('' !== $schedule_note) : ?>
                    <span class="blc-stat-note"><?php echo esc_html($schedule_note); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($top_domains)) : ?>
            <div class="blc-admin-card blc-admin-card--subtle blc-top-domains">
                <h2 class="blc-top-domains__title"><?php esc_html_e('Domaines les plus concernés', 'liens-morts-detector-jlg'); ?></h2>
                <p class="blc-top-domains__description">
                    <?php esc_html_e("Identifiez les sources externes qui concentrent le plus d'erreurs afin de prioriser vos corrections ou de contacter les partenaires concernés.", 'liens-morts-detector-jlg'); ?>
                </p>
                <ol class="blc-top-domains__list">
                    <?php foreach ($top_domains as $domain) :
                        $total_label = sprintf(
                            _n('%s lien cassé actif', '%s liens cassés actifs', $domain['count'], 'liens-morts-detector-jlg'),
                            number_format_i18n($domain['count'])
                        );
                        $breakdown_parts = array();
                        if ($domain['client_errors'] > 0) {
                            $breakdown_parts[] = sprintf(
                                __('4xx : %s', 'liens-morts-detector-jlg'),
                                number_format_i18n($domain['client_errors'])
                            );
                        }
                        if ($domain['server_errors'] > 0) {
                            $breakdown_parts[] = sprintf(
                                __('5xx : %s', 'liens-morts-detector-jlg'),
                                number_format_i18n($domain['server_errors'])
                            );
                        }
                        if ($domain['redirects'] > 0) {
                            $breakdown_parts[] = sprintf(
                                __('Redirections : %s', 'liens-morts-detector-jlg'),
                                number_format_i18n($domain['redirects'])
                            );
                        }
                        if ($domain['other'] > 0) {
                            $breakdown_parts[] = sprintf(
                                __('Autres : %s', 'liens-morts-detector-jlg'),
                                number_format_i18n($domain['other'])
                            );
                        }
                        $breakdown = implode(' • ', $breakdown_parts);
                        $search_url = add_query_arg(
                            array('s' => $domain['host']),
                            $build_dashboard_url('all')
                        );
                        ?>
                        <li class="blc-top-domains__item">
                            <div class="blc-top-domains__header">
                                <span class="blc-top-domains__host"><?php echo esc_html($domain['host']); ?></span>
                                <span class="blc-top-domains__count"><?php echo esc_html($total_label); ?></span>
                            </div>
                            <?php if ($breakdown !== '') : ?>
                                <span class="blc-top-domains__breakdown"><?php echo esc_html($breakdown); ?></span>
                            <?php endif; ?>
                            <a class="blc-top-domains__link" href="<?php echo esc_url($search_url); ?>">
                                <?php esc_html_e('Voir les liens', 'liens-morts-detector-jlg'); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>
        <?php if (!empty($priority_actions)) : ?>
            <section class="blc-priority-actions blc-admin-card blc-admin-card--accent" aria-labelledby="blc-priority-actions-heading">
                <div class="blc-priority-actions__header">
                    <h2 id="blc-priority-actions-heading" class="blc-priority-actions__title"><?php esc_html_e('Actions prioritaires', 'liens-morts-detector-jlg'); ?></h2>
                    <p class="blc-priority-actions__intro"><?php esc_html_e('Adoptez un plan d’action guidé pour résorber les erreurs critiques en quelques clics.', 'liens-morts-detector-jlg'); ?></p>
                </div>
                <ol class="blc-priority-actions__list">
                    <?php foreach ($priority_actions as $action) : ?>
                        <li class="blc-priority-actions__item">
                            <span class="blc-priority-actions__badge <?php echo esc_attr($action['severity_class']); ?>"><?php echo esc_html($action['severity_label']); ?></span>
                            <div class="blc-priority-actions__body">
                                <h3 class="blc-priority-actions__item-title"><?php echo esc_html($action['title']); ?></h3>
                                <p class="blc-priority-actions__description"><?php echo esc_html($action['description']); ?></p>
                            </div>
                            <div class="blc-priority-actions__actions">
                                <a class="button button-secondary blc-priority-actions__cta" href="<?php echo esc_url($action['cta_url']); ?>">
                                    <?php echo esc_html($action['cta_label']); ?>
                                </a>
                                <span class="blc-priority-actions__meta"><?php echo esc_html($action['meta']); ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>
        <div
            id="blc-scan-status-panel"
            class="<?php echo esc_attr($scan_panel_class_attr); ?>"
            aria-live="polite"
            data-scan-state="<?php echo esc_attr($scan_state_slug); ?>"
            data-is-full-scan="<?php echo esc_attr(!empty($scan_status['is_full_scan']) ? '1' : '0'); ?>"
        >
            <div class="blc-scan-status__header">
                <h2 class="blc-scan-status__title"><?php esc_html_e('Statut du scan manuel', 'liens-morts-detector-jlg'); ?></h2>
                <span class="blc-scan-status__state"><?php echo esc_html($scan_state_label); ?></span>
            </div>
            <div
                class="blc-scan-status__progress"
                role="progressbar"
                aria-label="<?php echo esc_attr__('Progression du scan manuel', 'liens-morts-detector-jlg'); ?>"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?php echo esc_attr($initial_progress); ?>"
            >
                <span class="blc-scan-status__progress-fill" style="width: <?php echo esc_attr($initial_progress); ?>%;"></span>
            </div>
            <p class="blc-scan-status__details"><?php echo esc_html($scan_details); ?></p>
            <div class="blc-scan-status__actions">
                <button type="button" class="button button-secondary blc-scan-status__cancel" id="blc-cancel-scan">
                    <?php esc_html_e('Annuler le scan', 'liens-morts-detector-jlg'); ?>
                </button>
                <button type="button" class="button blc-scan-status__restart" id="blc-restart-scan">
                    <?php esc_html_e('Replanifier un scan', 'liens-morts-detector-jlg'); ?>
                </button>
            </div>
            <p class="blc-scan-status__message" aria-live="polite"><?php echo esc_html($scan_status['message']); ?></p>
            <div
                class="blc-scan-status__queue"
                id="blc-manual-queue-indicator"
                role="status"
                aria-live="polite"
                hidden
            >
                <h3 class="screen-reader-text"><?php esc_html_e('File d’attente des analyses manuelles', 'liens-morts-detector-jlg'); ?></h3>
                <ul class="blc-scan-status__queue-list"></ul>
            </div>
            <p class="blc-scan-status__queue-warning" id="blc-queue-warning" hidden>
                <?php esc_html_e('Remplacer ou reprogrammer un scan effacera la file d’attente actuelle.', 'liens-morts-detector-jlg'); ?>
            </p>
            <div class="blc-scan-status__assist" id="blc-scan-support" hidden>
                <p class="blc-scan-status__assist-message"></p>
                <div class="blc-scan-status__assist-actions">
                    <a
                        class="button button-link"
                        target="_blank"
                        rel="noreferrer noopener"
                        href="<?php echo esc_url(apply_filters('blc_wp_cron_checklist_url', 'https://wordpress.org/documentation/article/cron/')); ?>"
                    >
                        <?php esc_html_e('Consulter la checklist WP-Cron', 'liens-morts-detector-jlg'); ?>
                    </a>
                    <button
                        type="button"
                        class="button button-secondary blc-scan-status__assist-copy"
                        data-command="wp cron event run blc_manual_check_batch"
                    >
                        <?php esc_html_e('Copier la commande WP-CLI', 'liens-morts-detector-jlg'); ?>
                    </button>
                </div>
            </div>
            <details class="blc-scan-status__log" id="blc-scan-error-log">
                <summary class="blc-scan-status__log-summary">
                    <?php esc_html_e('Journal des erreurs récentes', 'liens-morts-detector-jlg'); ?>
                </summary>
                <p class="blc-scan-status__log-empty"><?php esc_html_e('Aucun incident récent à signaler.', 'liens-morts-detector-jlg'); ?></p>
                <ul class="blc-scan-status__log-list" aria-live="polite"></ul>
            </details>
            <button type="button" class="button-link blc-scan-status__refresh" id="blc-refresh-scan-status">
                <?php esc_html_e('Actualiser le statut', 'liens-morts-detector-jlg'); ?>
            </button>
        </div>
        <section class="blc-manual-actions blc-admin-card blc-admin-card--subtle" aria-labelledby="blc-manual-actions-heading">
            <div class="blc-manual-actions__header">
                <h2 id="blc-manual-actions-heading" class="blc-manual-actions__title"><?php esc_html_e('Commandes rapides', 'liens-morts-detector-jlg'); ?></h2>
                <p class="blc-manual-actions__intro"><?php esc_html_e('Lancez, suspendez ou reprogrammez vos scans sans quitter cette page.', 'liens-morts-detector-jlg'); ?></p>
            </div>
            <div class="blc-manual-actions__grid">
                <form id="blc-manual-scan-form" method="post" class="blc-manual-actions__form" data-blc-action="start">
                    <?php wp_nonce_field('blc_manual_check_nonce'); ?>
                    <input type="hidden" name="blc_manual_check" value="1">
                    <div class="blc-manual-actions__primary">
                        <button type="submit" class="button button-primary blc-manual-actions__submit">
                            <?php esc_html_e('Lancer une analyse maintenant', 'liens-morts-detector-jlg'); ?>
                        </button>
                        <span class="blc-manual-actions__hint"><?php esc_html_e('Déclenche immédiatement une analyse manuelle.', 'liens-morts-detector-jlg'); ?></span>
                    </div>
                    <details class="blc-manual-actions__advanced">
                        <summary class="blc-manual-actions__advanced-toggle"><?php esc_html_e('Options avancées', 'liens-morts-detector-jlg'); ?></summary>
                        <div class="blc-manual-actions__advanced-content">
                            <label class="blc-manual-actions__checkbox">
                                <input type="checkbox" name="blc_full_scan">
                                <span><?php esc_html_e('Analyser l’ensemble du contenu (plus long)', 'liens-morts-detector-jlg'); ?></span>
                            </label>
                            <p class="description"><?php esc_html_e('Par défaut, seuls les contenus modifiés depuis la dernière exécution sont inspectés.', 'liens-morts-detector-jlg'); ?></p>
                        </div>
                    </details>
                </form>
                <form method="post" class="blc-manual-actions__form blc-manual-actions__form--secondary" data-blc-action="reschedule">
                    <?php wp_nonce_field('blc_reschedule_cron_nonce'); ?>
                    <input type="hidden" name="blc_reschedule_cron" value="1">
                    <div class="blc-manual-actions__primary">
                        <button type="submit" class="button button-secondary">
                            <?php esc_html_e('Reprogrammer l’analyse automatique', 'liens-morts-detector-jlg'); ?>
                        </button>
                        <span class="blc-manual-actions__hint"><?php esc_html_e('Recrée l’évènement WP-Cron selon la fréquence définie.', 'liens-morts-detector-jlg'); ?></span>
                    </div>
                </form>
            </div>
            <div class="blc-manual-actions__footer" role="note">
                <p class="blc-manual-actions__support">
                    <?php esc_html_e('Besoin d’aide ? Consultez la checklist WP-Cron dans la documentation ou exécutez la commande WP-CLI affichée ci-dessous.', 'liens-morts-detector-jlg'); ?>
                </p>
                <code class="blc-manual-actions__command">wp cron event run blc_manual_check_batch</code>
            </div>
        </section>
        <?php if ($broken_links_count === 0): ?>
             <p><?php esc_html_e('✅ Aucun lien mort trouvé. Bravo !', 'liens-morts-detector-jlg'); ?></p>
        <?php else: ?>
            <div class="blc-status-legend blc-admin-card blc-admin-card--subtle" role="note">
                <p class="blc-status-legend__title"><?php esc_html_e('Légende des statuts HTTP', 'liens-morts-detector-jlg'); ?></p>
                <ul class="blc-status-legend__list">
                    <li class="blc-status-legend__item">
                        <span class="blc-status blc-status--2xx">200</span>
                        <span><?php esc_html_e('Disponible (2xx)', 'liens-morts-detector-jlg'); ?></span>
                    </li>
                    <li class="blc-status-legend__item">
                        <span class="blc-status blc-status--3xx">302</span>
                        <span><?php esc_html_e('Redirection (3xx)', 'liens-morts-detector-jlg'); ?></span>
                    </li>
                    <li class="blc-status-legend__item">
                        <span class="blc-status blc-status--4xx">404</span>
                        <span><?php esc_html_e('Erreur client (4xx)', 'liens-morts-detector-jlg'); ?></span>
                    </li>
                    <li class="blc-status-legend__item">
                        <span class="blc-status blc-status--5xx">502</span>
                        <span><?php esc_html_e('Erreur serveur (5xx)', 'liens-morts-detector-jlg'); ?></span>
                    </li>
                    <li class="blc-status-legend__item">
                        <span class="blc-status blc-status--unknown">?</span>
                        <span><?php esc_html_e('Statut inconnu ou indisponible', 'liens-morts-detector-jlg'); ?></span>
                    </li>
                </ul>
            </div>
            <form
                method="get"
                class="blc-links-filter-form"
                aria-labelledby="blc-links-filter-heading"
                data-blc-ajax-table="true"
                data-blc-ajax-endpoint="<?php echo esc_url($ajax_endpoint); ?>"
                data-blc-ajax-nonce="<?php echo esc_attr($ajax_nonce); ?>"
                data-blc-cache-ttl="<?php echo esc_attr($cache_ttl); ?>"
                data-blc-initial-state="<?php echo esc_attr($initial_state_json); ?>"
                data-blc-saved-views="<?php echo esc_attr($saved_views_json); ?>"
                data-blc-views-nonce="<?php echo esc_attr($saved_views_nonce); ?>"
                data-blc-views-limit="<?php echo esc_attr($saved_views_limit); ?>"
            >
                <h2 id="blc-links-filter-heading" class="screen-reader-text"><?php esc_html_e('Filtres de la liste des liens cassés', 'liens-morts-detector-jlg'); ?></h2>
                <?php
                $preserved_query_args = [];
                if (!empty($_GET) && is_array($_GET)) {
                    $preserved_query_args = $_GET;
                }

                foreach ($preserved_query_args as $key => $value) {
                    if (in_array($key, ['s', 'post_type', 'paged', 'link_type', 'orderby', 'order'], true)) {
                        continue;
                    }

                    if (is_scalar($value)) {
                        printf(
                            '<input type="hidden" name="%1$s" value="%2$s" />',
                            esc_attr($key),
                            esc_attr((string) $value)
                        );
                    }
                }

                $page_slug = '';
                if (isset($preserved_query_args['page']) && is_scalar($preserved_query_args['page'])) {
                    $page_slug = (string) $preserved_query_args['page'];
                } elseif (isset($_REQUEST['page']) && is_scalar($_REQUEST['page'])) {
                    $page_slug = (string) $_REQUEST['page'];
                }
                if ($page_slug === '') {
                    $page_slug = 'blc-dashboard';
                }
                ?>
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>" />
                <input type="hidden" name="link_type" value="<?php echo esc_attr($current_link_type); ?>" data-blc-state-field="link_type" />
                <input type="hidden" name="orderby" value="<?php echo esc_attr(isset($current_sorting['orderby']) ? (string) $current_sorting['orderby'] : ''); ?>" data-blc-state-field="orderby" />
                <input type="hidden" name="order" value="<?php echo esc_attr(isset($current_sorting['order']) ? (string) $current_sorting['order'] : ''); ?>" data-blc-state-field="order" />
                <input type="hidden" name="paged" value="<?php echo esc_attr(isset($pagination_state['current']) ? (int) $pagination_state['current'] : 1); ?>" data-blc-state-field="paged" />
                <div
                    class="blc-saved-views"
                    data-blc-saved-views-panel
                >
                    <div class="blc-saved-views__row">
                        <label for="blc-saved-views-select" class="blc-saved-views__label"><?php esc_html_e('Segments enregistrés', 'liens-morts-detector-jlg'); ?></label>
                        <div class="blc-saved-views__controls">
                            <select
                                id="blc-saved-views-select"
                                class="blc-saved-views__select"
                                data-blc-saved-views-select
                            >
                                <option value=""><?php esc_html_e('Sélectionnez une vue…', 'liens-morts-detector-jlg'); ?></option>
                                <?php foreach ($saved_views_payload as $saved_view) : ?>
                                    <option value="<?php echo esc_attr($saved_view['id']); ?>">
                                        <?php echo esc_html($saved_view['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="button button-secondary blc-saved-views__apply" data-blc-saved-views-apply disabled>
                                <?php esc_html_e('Appliquer', 'liens-morts-detector-jlg'); ?>
                            </button>
                            <button type="button" class="button-link blc-saved-views__delete" data-blc-saved-views-delete disabled>
                                <?php esc_html_e('Supprimer', 'liens-morts-detector-jlg'); ?>
                            </button>
                        </div>
                    </div>
                    <p class="blc-saved-views__meta" data-blc-saved-views-meta aria-live="polite" hidden></p>
                    <div class="blc-saved-views__row blc-saved-views__row--save">
                        <label for="blc-saved-views-name" class="blc-saved-views__label"><?php esc_html_e('Enregistrer la vue actuelle', 'liens-morts-detector-jlg'); ?></label>
                        <div class="blc-saved-views__controls">
                            <input
                                type="text"
                                id="blc-saved-views-name"
                                class="blc-saved-views__input"
                                placeholder="<?php echo esc_attr__('Nom du segment (ex. 404 critiques)', 'liens-morts-detector-jlg'); ?>"
                                data-blc-saved-views-name
                            />
                            <button type="button" class="button button-primary blc-saved-views__save" data-blc-saved-views-save>
                                <?php esc_html_e('Enregistrer', 'liens-morts-detector-jlg'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="blc-saved-views__row blc-saved-views__row--default">
                        <span class="blc-saved-views__label"><?php esc_html_e('Vue par défaut', 'liens-morts-detector-jlg'); ?></span>
                        <div class="blc-saved-views__controls">
                            <label class="blc-saved-views__toggle">
                                <input type="checkbox" class="blc-saved-views__toggle-input" data-blc-saved-views-default />
                                <span><?php esc_html_e('Définir comme vue par défaut', 'liens-morts-detector-jlg'); ?></span>
                            </label>
                            <p class="blc-saved-views__note"><?php esc_html_e('Appliquera automatiquement cette vue lors de vos prochaines visites du rapport.', 'liens-morts-detector-jlg'); ?></p>
                        </div>
                    </div>
                    <p class="blc-saved-views__hint">
                        <?php esc_html_e('Vos vues enregistrées sont privées et synchronisées avec votre compte.', 'liens-morts-detector-jlg'); ?>
                    </p>
                </div>
                <div class="blc-links-table" data-blc-table-region>
                    <?php echo $table_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Affiche la page du rapport des IMAGES cassées.
 */
function blc_dashboard_images_page() {
    if (!blc_current_user_can_view_reports()) {
        wp_die(esc_html__('Permissions insuffisantes pour consulter les rapports.', 'liens-morts-detector-jlg'));
    }

    blc_render_action_modal();

    if (isset($_POST['blc_reschedule_image_cron'])) {
        check_admin_referer('blc_reschedule_image_cron_nonce');

        if (!blc_current_user_can_fix_links()) {
            wp_die(esc_html__("Permissions insuffisantes pour reprogrammer l'analyse automatique des images.", 'liens-morts-detector-jlg'));
        }

        $automatic_enabled = (bool) get_option('blc_image_scan_schedule_enabled', false);

        if (!$automatic_enabled) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html__("La planification automatique des images est désactivée. Activez-la dans les réglages pour reprogrammer le cron.", 'liens-morts-detector-jlg')
            );
        } else {
            $schedule_result = blc_reset_image_check_schedule(
                array(
                    'context' => 'dashboard_reschedule',
                )
            );

            if (!$schedule_result['success']) {
                if (!empty($schedule_result['error_message'])) {
                    error_log($schedule_result['error_message']);
                }

                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    esc_html__("La planification automatique des images n'a pas pu être reprogrammée. Vérifiez la configuration de WP-Cron.", 'liens-morts-detector-jlg')
                );

                if (!empty($schedule_result['restore_attempted'])) {
                    $restore_notice = !empty($schedule_result['restored'])
                        ? esc_html__("La planification précédente des images a été restaurée automatiquement.", 'liens-morts-detector-jlg')
                        : esc_html__("La planification précédente des images n'a pas pu être restaurée. Une intervention manuelle est nécessaire.", 'liens-morts-detector-jlg');

                    printf('<div class="notice notice-warning is-dismissible"><p>%s</p></div>', $restore_notice);
                } else {
                    printf(
                        '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                        esc_html__("Aucune planification précédente n'a été trouvée. Configurez manuellement la vérification automatique si nécessaire.", 'liens-morts-detector-jlg')
                    );
                }
            } else {
                $next_timestamp = 0;
                if (function_exists('wp_next_scheduled')) {
                    $next_event = wp_next_scheduled('blc_check_image_batch', array(0, true));
                    if ($next_event) {
                        $next_timestamp = (int) $next_event;
                    }
                }

                $success_message = esc_html__("Le scan automatique des images a été reprogrammé selon les réglages actuels.", 'liens-morts-detector-jlg');

                if ($next_timestamp > 0) {
                    $success_message .= ' ' . sprintf(
                        /* translators: %s: formatted date for next automatic scan. */
                        esc_html__('Prochaine exécution prévue : %s.', 'liens-morts-detector-jlg'),
                        esc_html(wp_date('j M Y H:i', $next_timestamp))
                    );
                }

                printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', $success_message);
            }
        }
    }

    if (isset($_POST['blc_manual_image_check'])) {
        check_admin_referer('blc_manual_image_check_nonce');

        if (!blc_current_user_can_fix_links()) {
            wp_die(esc_html__('Permissions insuffisantes pour lancer une analyse manuelle.', 'liens-morts-detector-jlg'));
        }

        $schedule_result = blc_schedule_manual_image_scan();

        if (!$schedule_result['success']) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($schedule_result['message'])
            );
        } else {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($schedule_result['message'])
            );

            if (!empty($schedule_result['manual_trigger_failed'])) {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    esc_html__(
                        "Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.",
                        'liens-morts-detector-jlg'
                    )
                );
            }
        }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'blc_broken_links';
    $image_row_types = blc_get_dataset_row_types('image');
    $broken_images_count = 0;
    $option_size_bytes = 0;
    $last_image_check_time = get_option('blc_last_image_check_time', 0);

    if ($image_row_types !== []) {
        if (count($image_row_types) === 1) {
            $broken_images_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE type = %s",
                    reset($image_row_types)
                )
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($image_row_types), '%s'));
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE type IN ($placeholders)",
                $image_row_types
            );
            $broken_images_count = (int) $wpdb->get_var($query);
        }

        $option_size_bytes = blc_get_dataset_storage_footprint_bytes('image');
    }

    $option_size_kb = $option_size_bytes / 1024;
    $size_display = ($option_size_kb < 1024)
        ? sprintf('%s %s', number_format_i18n($option_size_kb, 2), __('Ko', 'liens-morts-detector-jlg'))
        : sprintf('%s %s', number_format_i18n($option_size_kb / 1024, 2), __('Mo', 'liens-morts-detector-jlg'));

    $last_check_display = $last_image_check_time
        ? wp_date('j M Y', $last_image_check_time)
        : __('Jamais', 'liens-morts-detector-jlg');

    $image_scan_status = blc_get_image_scan_status_payload();
    $image_scan_state_slug = isset($image_scan_status['state']) ? sanitize_key($image_scan_status['state']) : 'idle';
    if ($image_scan_state_slug === '') {
        $image_scan_state_slug = 'idle';
    }

    $image_scan_state_label = blc_get_scan_state_label($image_scan_state_slug);

    $image_total_batches_display = isset($image_scan_status['total_batches']) ? (int) $image_scan_status['total_batches'] : 0;
    if ($image_total_batches_display <= 0 && in_array($image_scan_state_slug, array('queued', 'running'), true)) {
        $image_total_batches_display = max(1, (int) $image_scan_status['processed_batches']);
    }
    $image_total_batches_display = max(0, $image_total_batches_display);

    $image_processed_batches_display = isset($image_scan_status['processed_batches']) ? (int) $image_scan_status['processed_batches'] : 0;
    if ($image_total_batches_display > 0) {
        $image_processed_batches_display = max(0, min($image_processed_batches_display, $image_total_batches_display));
    }

    $image_initial_progress = 0;
    if ($image_total_batches_display > 0) {
        $image_initial_progress = (int) round(($image_processed_batches_display / max(1, $image_total_batches_display)) * 100);
    } elseif ('completed' === $image_scan_state_slug) {
        $image_initial_progress = 100;
    }
    $image_initial_progress = max(0, min(100, $image_initial_progress));

    $image_scan_details_parts = array();

    if (in_array($image_scan_state_slug, array('running', 'queued'), true)) {
        if ($image_total_batches_display > 0) {
            $image_scan_details_parts[] = sprintf(
                /* translators: 1: current batch number, 2: total batch count. */
                __('Lot %1$d sur %2$d.', 'liens-morts-detector-jlg'),
                max(1, $image_processed_batches_display),
                max(1, $image_total_batches_display)
            );
        } else {
            $image_scan_details_parts[] = __('Préparation du prochain lot…', 'liens-morts-detector-jlg');
        }
    } elseif ('completed' === $image_scan_state_slug) {
        $image_scan_details_parts[] = __('Analyse terminée.', 'liens-morts-detector-jlg');
    } elseif ('failed' === $image_scan_state_slug) {
        $image_scan_details_parts[] = __('Dernier scan en échec.', 'liens-morts-detector-jlg');
    } elseif ('cancelled' === $image_scan_state_slug) {
        $image_scan_details_parts[] = __('Scan annulé.', 'liens-morts-detector-jlg');
    }

    $image_next_batch_timestamp = isset($image_scan_status['next_batch_timestamp']) ? (int) $image_scan_status['next_batch_timestamp'] : 0;
    if ($image_next_batch_timestamp > 0 && in_array($image_scan_state_slug, array('running', 'queued'), true)) {
        $image_scan_details_parts[] = sprintf(
            /* translators: %s: next batch scheduled date. */
            __('Prochain lot prévu à %s.', 'liens-morts-detector-jlg'),
            wp_date('j M Y H:i', $image_next_batch_timestamp)
        );
    }

    $image_scan_details = trim(implode(' ', array_filter(array_map('strval', $image_scan_details_parts))));

    $image_scan_panel_classes = array_filter(
        array(
            'blc-scan-status',
            'blc-admin-card',
            'blc-scan-status--state-' . $image_scan_state_slug,
            in_array($image_scan_state_slug, array('running', 'queued'), true) ? 'is-active' : '',
            'completed' === $image_scan_state_slug ? 'is-completed' : '',
            'failed' === $image_scan_state_slug ? 'is-failed' : '',
            'cancelled' === $image_scan_state_slug ? 'is-cancelled' : '',
        )
    );

    $image_scan_panel_class_attr = implode(' ', array_map('sanitize_html_class', $image_scan_panel_classes));
    $image_status_message = isset($image_scan_status['message']) && is_string($image_scan_status['message'])
        ? $image_scan_status['message']
        : '';

    $automatic_image_scan_enabled = (bool) get_option('blc_image_scan_schedule_enabled', false);
    $next_automatic_image_scan    = 0;
    if ($automatic_image_scan_enabled && function_exists('wp_next_scheduled')) {
        $next_event = wp_next_scheduled('blc_check_image_batch', array(0, true));
        if ($next_event) {
            $next_automatic_image_scan = (int) $next_event;
        }
    }

    if ($automatic_image_scan_enabled) {
        $next_image_scan_display = ($next_automatic_image_scan > 0)
            ? wp_date('j M Y H:i', $next_automatic_image_scan)
            : esc_html__('Aucune exécution planifiée', 'liens-morts-detector-jlg');
    } else {
        $next_image_scan_display = esc_html__('Planification automatique désactivée', 'liens-morts-detector-jlg');
    }

    $list_table = new BLC_Images_List_Table();
    $list_table->prepare_items();
    ?>
    <div class="wrap">
        <?php blc_render_dashboard_tabs('images'); ?>
        <h1><?php esc_html_e('Rapport des Images Cassées', 'liens-morts-detector-jlg'); ?></h1>
        <div class="blc-stats-box blc-admin-card blc-admin-card--accent">
            <div class="blc-stat">
                <span class="blc-stat-value"><?php echo esc_html($broken_images_count); ?></span>
                <span class="blc-stat-label"><?php esc_html_e('Images cassées trouvées', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-stat">
                <span class="blc-stat-value"><?php echo esc_html($size_display); ?></span>
                <span class="blc-stat-label"><?php esc_html_e('Poids des données', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-stat">
                <span class="blc-stat-value"><?php echo esc_html($last_check_display); ?></span>
                <span class="blc-stat-label"><?php esc_html_e('Dernière analyse d\'images', 'liens-morts-detector-jlg'); ?></span>
            </div>
        </div>
        <div class="blc-automatic-scan-summary">
            <p>
                <strong><?php esc_html_e('Prochain scan automatique', 'liens-morts-detector-jlg'); ?> :</strong>
                <span><?php echo esc_html($next_image_scan_display); ?></span>
            </p>
            <?php if ($automatic_image_scan_enabled) : ?>
                <form method="post" class="blc-reschedule-image-cron-form">
                    <?php wp_nonce_field('blc_reschedule_image_cron_nonce'); ?>
                    <input type="hidden" name="blc_reschedule_image_cron" value="1">
                    <button type="submit" class="button button-secondary">
                        <?php esc_html_e('Reprogrammer le scan automatique', 'liens-morts-detector-jlg'); ?>
                    </button>
                </form>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Activez la planification automatique dans les réglages pour programmer des analyses récurrentes.', 'liens-morts-detector-jlg'); ?></p>
            <?php endif; ?>
        </div>
        <div
            id="blc-image-scan-status-panel"
            class="<?php echo esc_attr($image_scan_panel_class_attr); ?>"
            aria-live="polite"
            data-scan-state="<?php echo esc_attr($image_scan_state_slug); ?>"
            data-is-full-scan="1"
        >
            <div class="blc-scan-status__header">
                <h2 class="blc-scan-status__title"><?php esc_html_e('Statut du scan des images', 'liens-morts-detector-jlg'); ?></h2>
                <span class="blc-scan-status__state"><?php echo esc_html($image_scan_state_label); ?></span>
            </div>
            <div
                class="blc-scan-status__progress"
                role="progressbar"
                aria-label="<?php echo esc_attr__('Progression du scan manuel', 'liens-morts-detector-jlg'); ?>"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?php echo esc_attr($image_initial_progress); ?>"
            >
                <span class="blc-scan-status__progress-fill" style="width: <?php echo esc_attr($image_initial_progress); ?>%;"></span>
            </div>
            <p class="blc-scan-status__details"><?php echo esc_html($image_scan_details); ?></p>
            <div class="blc-scan-status__actions">
                <button type="button" class="button button-secondary blc-scan-status__cancel" id="blc-image-cancel-scan">
                    <?php esc_html_e('Annuler le scan', 'liens-morts-detector-jlg'); ?>
                </button>
                <button type="button" class="button blc-scan-status__restart" id="blc-image-restart-scan">
                    <?php esc_html_e('Replanifier un scan', 'liens-morts-detector-jlg'); ?>
                </button>
            </div>
            <p class="blc-scan-status__message" aria-live="polite"><?php echo esc_html($image_status_message); ?></p>
        </div>
        <form id="blc-image-manual-scan-form" method="post" class="blc-manual-scan-form blc-admin-card blc-admin-card--subtle">
            <?php wp_nonce_field('blc_manual_image_check_nonce'); ?>
            <input type="hidden" name="blc_manual_image_check" value="1">
            <p><?php esc_html_e("L'analyse des images peut être longue et consommer des ressources. Elle s'exécute en arrière-plan sur l'ensemble du site.", 'liens-morts-detector-jlg'); ?></p>
            <input type="submit" class="button button-primary" value="<?php echo esc_attr__("Lancer l'analyse des images", 'liens-morts-detector-jlg'); ?>">
        </form>
        <?php if ($broken_images_count === 0) : ?>
            <p><?php esc_html_e('✅ Aucune image cassée trouvée. Bravo !', 'liens-morts-detector-jlg'); ?></p>
        <?php else : ?>
            <form method="post">
                <?php $list_table->display(); ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Affiche la page des réglages du plugin.
 */
function blc_settings_page() {
    if (!blc_current_user_can_manage_settings()) {
        wp_die(esc_html__('Permissions insuffisantes pour modifier les réglages.', 'liens-morts-detector-jlg'));
    }

    $settings_mode    = blc_get_settings_mode();
    $is_advanced_mode = ($settings_mode === 'advanced');

    ?>
    <div class="wrap">
        <?php blc_render_dashboard_tabs('settings'); ?>
        <h1><?php esc_html_e('Réglages', 'liens-morts-detector-jlg'); ?></h1>
        <?php settings_errors(); ?>
        <div
            class="blc-settings-mode"
            data-blc-settings-mode-toggle
            data-current-mode="<?php echo esc_attr($settings_mode); ?>"
        >
            <div class="blc-settings-mode__intro">
                <h2 id="blc-settings-mode-title" class="blc-settings-mode__title"><?php esc_html_e('Niveau de configuration', 'liens-morts-detector-jlg'); ?></h2>
                <p id="blc-settings-mode-description" class="blc-settings-mode__description">
                    <?php esc_html_e('Choisissez la quantité de réglages à afficher en fonction de votre aisance technique.', 'liens-morts-detector-jlg'); ?>
                </p>
            </div>
            <div class="blc-settings-mode__control">
                <span id="blc-settings-mode-state" class="blc-settings-mode__state" data-blc-settings-mode-state>
                    <?php
                    if ($is_advanced_mode) {
                        esc_html_e('Mode avancé activé — toutes les sections sont affichées.', 'liens-morts-detector-jlg');
                    } else {
                        esc_html_e('Mode simple activé — seuls les réglages essentiels sont visibles.', 'liens-morts-detector-jlg');
                    }
                    ?>
                </span>
                <button
                    type="button"
                    class="button blc-settings-mode__switch"
                    role="switch"
                    aria-checked="<?php echo $is_advanced_mode ? 'true' : 'false'; ?>"
                    aria-labelledby="blc-settings-mode-title blc-settings-mode-state"
                    aria-describedby="blc-settings-mode-description"
                    data-blc-settings-mode-control
                >
                    <span data-blc-settings-mode-action>
                        <?php
                        if ($is_advanced_mode) {
                            esc_html_e('Revenir au mode simple', 'liens-morts-detector-jlg');
                        } else {
                            esc_html_e('Passer en mode avancé', 'liens-morts-detector-jlg');
                        }
                        ?>
                    </span>
                </button>
            </div>
        </div>
        <form method="post" action="options.php" class="blc-settings-form">
            <?php
            settings_fields('blc_settings');
            blc_render_settings_sections_grouped('blc-settings');
            submit_button(__('Enregistrer les modifications', 'liens-morts-detector-jlg'));
            ?>
        </form>
    </div>
    <?php
}

/**
 * Render settings sections grouped by expertise level.
 *
 * @param string $page Settings page slug.
 *
 * @return void
 */
function blc_get_advanced_settings_groups() {
    return array(
        'performance' => array(
            'label'       => __('Performance & débit', 'liens-morts-detector-jlg'),
            'tab_hint'    => __('Cadence des scans', 'liens-morts-detector-jlg'),
            'description' => __('Ajustez le rythme des requêtes pour équilibrer vitesse d’analyse et charge serveur.', 'liens-morts-detector-jlg'),
            'sections'    => array('blc_performance_section'),
        ),
        'heuristics'  => array(
            'label'       => __('Heuristiques & fiabilité', 'liens-morts-detector-jlg'),
            'tab_hint'    => __('Qualité des détections', 'liens-morts-detector-jlg'),
            'description' => __('Paramétrez la sensibilité du détecteur et les exclusions pour limiter les faux positifs.', 'liens-morts-detector-jlg'),
            'sections'    => array('blc_scan_section', 'blc_soft_404_section'),
        ),
        'media'       => array(
            'label'       => __('Images & CDN', 'liens-morts-detector-jlg'),
            'tab_hint'    => __('Surveillance des médias', 'liens-morts-detector-jlg'),
            'description' => __('Orchestrez les scans distants pour les images et les bibliothèques externes.', 'liens-morts-detector-jlg'),
            'sections'    => array('blc_images_section'),
        ),
        'diagnostics' => array(
            'label'       => __('Diagnostics & journalisation', 'liens-morts-detector-jlg'),
            'tab_hint'    => __('Support & logs', 'liens-morts-detector-jlg'),
            'description' => __('Activez des options avancées pour investiguer des incidents ponctuels.', 'liens-morts-detector-jlg'),
            'sections'    => array('blc_debug_section'),
        ),
    );
}

function blc_get_settings_persona_presets() {
    return array(
        'balanced' => array(
            'label'       => __('Mode équilibré', 'liens-morts-detector-jlg'),
            'description' => __('Compromis recommandé pour la majorité des sites WordPress.', 'liens-morts-detector-jlg'),
            'settings'    => array(
                'blc_link_delay'            => 200,
                'blc_batch_delay'           => 45,
                'blc_batch_size'            => 25,
                'blc_scan_method'           => 'precise',
                'blc_head_request_timeout'  => 5,
                'blc_get_request_timeout'   => 10,
            ),
        ),
        'velocity' => array(
            'label'       => __('Mode express', 'liens-morts-detector-jlg'),
            'description' => __('Priorise la vitesse pour les grandes bases éditoriales (charge serveur accrue).', 'liens-morts-detector-jlg'),
            'settings'    => array(
                'blc_link_delay'            => 80,
                'blc_batch_delay'           => 20,
                'blc_batch_size'            => 40,
                'blc_scan_method'           => 'fast',
                'blc_head_request_timeout'  => 4,
                'blc_get_request_timeout'   => 8,
            ),
        ),
        'quality'  => array(
            'label'       => __('Mode qualité', 'liens-morts-detector-jlg'),
            'description' => __('Renforce les contrôles pour les équipes conformité ou SEO critiques.', 'liens-morts-detector-jlg'),
            'settings'    => array(
                'blc_link_delay'               => 300,
                'blc_batch_delay'              => 75,
                'blc_batch_size'               => 15,
                'blc_scan_method'              => 'precise',
                'blc_head_request_timeout'     => 7.5,
                'blc_get_request_timeout'      => 12,
                'blc_soft_404_min_length'      => 640,
                'blc_soft_404_title_weight'    => 1.4,
            ),
        ),
    );
}

function blc_normalize_links_table_request($source = null) {
    if ($source === null) {
        $source = $_REQUEST;
    }

    $allowed_keys = array('s', 'post_type', 'link_type', 'orderby', 'order', 'paged', 'page');
    $normalized   = array();

    if (!is_array($source)) {
        return $normalized;
    }

    foreach ($allowed_keys as $key) {
        if (!isset($source[$key])) {
            continue;
        }

        $value = $source[$key];

        if (is_array($value)) {
            $normalized[$key] = array_map(static function ($item) {
                return is_scalar($item) ? (string) $item : '';
            }, $value);
        } elseif (is_scalar($value) || $value === null) {
            $normalized[$key] = (string) $value;
        }
    }

    if (!isset($normalized['page']) && isset($_REQUEST['page']) && is_scalar($_REQUEST['page'])) {
        $normalized['page'] = (string) $_REQUEST['page'];
    }

    return $normalized;
}

/**
 * Normalize the requested settings mode.
 *
 * @param string $mode
 *
 * @return string
 */
function blc_normalize_settings_mode($mode) {
    $allowed_modes = array('simple', 'advanced');
    $normalized    = is_string($mode) ? $mode : '';

    if (function_exists('sanitize_key')) {
        $normalized = sanitize_key($normalized);
    } else {
        $normalized = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $normalized));
    }

    if (!in_array($normalized, $allowed_modes, true)) {
        return 'simple';
    }

    return $normalized;
}

/**
 * Retrieve the preferred settings display mode for a user.
 *
 * @param int $user_id Optional user identifier.
 *
 * @return string Either "simple" or "advanced".
 */
function blc_get_settings_mode($user_id = 0) {
    $default = 'simple';

    if (!function_exists('get_current_user_id') || !function_exists('get_user_meta')) {
        return $default;
    }

    if ($user_id <= 0) {
        $user_id = (int) get_current_user_id();
    }

    if ($user_id <= 0) {
        return $default;
    }

    $stored = get_user_meta($user_id, BLC_SETTINGS_MODE_META_KEY, true);
    if (!is_string($stored)) {
        $stored = (string) $stored;
    }

    return blc_normalize_settings_mode($stored);
}

/**
 * Persist the preferred settings display mode for a user.
 *
 * @param string $mode    Requested mode.
 * @param int    $user_id Optional user identifier.
 *
 * @return string Saved mode.
 */
function blc_update_settings_mode($mode, $user_id = 0) {
    $normalized = blc_normalize_settings_mode($mode);

    if (!function_exists('get_current_user_id') || !function_exists('update_user_meta')) {
        return $normalized;
    }

    if ($user_id <= 0) {
        $user_id = (int) get_current_user_id();
    }

    if ($user_id <= 0) {
        return $normalized;
    }

    update_user_meta($user_id, BLC_SETTINGS_MODE_META_KEY, $normalized);

    return $normalized;
}

function blc_render_links_table_markup(BLC_Links_List_Table $list_table) {
    ob_start();
    ?>
    <div class="blc-links-table__views">
        <?php $list_table->views(); ?>
    </div>
    <div class="blc-links-table__wrapper">
        <?php $list_table->display(); ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function blc_render_settings_sections_grouped($page) {
    global $wp_settings_sections;

    if (!isset($wp_settings_sections[$page]) || !is_array($wp_settings_sections[$page])) {
        do_settings_sections($page);

        return;
    }

    $sections = $wp_settings_sections[$page];
    $current_mode = blc_get_settings_mode();
    $show_advanced = ($current_mode === 'advanced');

    $essential_section_ids = array(
        'blc_planification_section',
        'blc_notifications_section',
        'blc_post_types_section',
        'blc_post_statuses_section',
        'blc_ui_section',
        'blc_accessibility_section',
    );

    $advanced_section_ids = array();
    foreach ($sections as $section_id => $section) {
        if (in_array($section_id, $essential_section_ids, true)) {
            continue;
        }

        $advanced_section_ids[] = $section_id;
    }

    echo '<div class="blc-settings-groups" data-blc-settings-groups>';

    echo '<section class="blc-settings-group" aria-labelledby="blc-settings-essential-heading">';
    echo '<header class="blc-settings-group__header">';
    echo '<h2 id="blc-settings-essential-heading" class="blc-settings-group__title">' . esc_html__('Réglages essentiels', 'liens-morts-detector-jlg') . '</h2>';
    echo '<p class="blc-settings-group__description">' . esc_html__('Configurez les paramètres indispensables pour que la surveillance reste active.', 'liens-morts-detector-jlg') . '</p>';
    echo '</header>';
    foreach ($essential_section_ids as $section_id) {
        blc_render_settings_section($page, $section_id);
    }
    echo '</section>';

    $advanced_markup = '';
    if (!empty($advanced_section_ids)) {
        $advanced_groups = blc_get_advanced_settings_groups();
        $grouped_sections = array();
        $assigned_sections = array();

        foreach ($advanced_groups as $slug => $definition) {
            if (empty($definition['sections']) || !is_array($definition['sections'])) {
                continue;
            }

            $matched = array_values(array_intersect($definition['sections'], $advanced_section_ids));
            if (empty($matched)) {
                continue;
            }

            $grouped_sections[$slug] = array(
                'label'       => isset($definition['label']) ? (string) $definition['label'] : $slug,
                'tab_hint'    => isset($definition['tab_hint']) ? (string) $definition['tab_hint'] : '',
                'description' => isset($definition['description']) ? (string) $definition['description'] : '',
                'sections'    => $matched,
            );

            $assigned_sections = array_merge($assigned_sections, $matched);
        }

        $unassigned_sections = array_values(array_diff($advanced_section_ids, $assigned_sections));
        if (!empty($unassigned_sections)) {
            $grouped_sections['other'] = array(
                'label'       => __('Autres optimisations', 'liens-morts-detector-jlg'),
                'tab_hint'    => __('Réglages divers', 'liens-morts-detector-jlg'),
                'description' => __('Affinez des options complémentaires rarement modifiées.', 'liens-morts-detector-jlg'),
                'sections'    => $unassigned_sections,
            );
        }

        if (!empty($grouped_sections)) {
            $default_group = key($grouped_sections);
            $personas      = blc_get_settings_persona_presets();

            ob_start();
            echo '<details class="blc-settings-group blc-settings-group--collapsible" aria-labelledby="blc-settings-advanced-heading" open>';
            echo '<summary class="blc-settings-group__summary">';
            echo '<span id="blc-settings-advanced-heading" class="blc-settings-group__title">' . esc_html__('Réglages avancés', 'liens-morts-detector-jlg') . '</span>';
            echo '<span class="blc-settings-group__description">' . esc_html__('Optimisez les performances, heuristiques et intégrations externes.', 'liens-morts-detector-jlg') . '</span>';
            echo '</summary>';
            echo '<div class="blc-settings-group__content">';
            echo '<div class="blc-settings-advanced" data-blc-settings-advanced>'; // container

            if (!empty($personas)) {
                echo '<div class="blc-settings-advanced__personas" role="group" aria-labelledby="blc-settings-personas-heading">';
                echo '<div class="blc-settings-advanced__personas-header">';
                echo '<p id="blc-settings-personas-heading" class="blc-settings-advanced__personas-title">' . esc_html__('Préréglages rapides', 'liens-morts-detector-jlg') . '</p>';
                echo '<p class="blc-settings-advanced__personas-help">' . esc_html__('Appliquez une configuration type puis ajustez les champs si nécessaire.', 'liens-morts-detector-jlg') . '</p>';
                echo '</div>';
                echo '<div class="blc-settings-advanced__persona-grid">';
                foreach ($personas as $persona_slug => $persona) {
                    if (empty($persona['settings']) || !is_array($persona['settings'])) {
                        continue;
                    }

                    $settings_json = wp_json_encode($persona['settings']);
                    if (!is_string($settings_json)) {
                        continue;
                    }

                    $button_classes = 'blc-persona';
                    $label = isset($persona['label']) ? (string) $persona['label'] : $persona_slug;
                    $description = isset($persona['description']) ? (string) $persona['description'] : '';

                    echo '<button type="button" class="' . esc_attr($button_classes) . '" data-blc-persona="' . esc_attr($persona_slug) . '" data-blc-persona-settings="' . esc_attr($settings_json) . '" aria-pressed="false">';
                    echo '<span class="blc-persona__label">' . esc_html($label) . '</span>';
                    if ($description !== '') {
                        echo '<span class="blc-persona__description">' . esc_html($description) . '</span>';
                    }
                    echo '</button>';
                }
                echo '</div>';
                echo '</div>';
            }

            echo '<div class="blc-settings-advanced__tabs" role="tablist" aria-label="' . esc_attr__('Catégories des réglages avancés', 'liens-morts-detector-jlg') . '">';
            foreach ($grouped_sections as $slug => $group) {
                $is_active = ($slug === $default_group);
                $tab_id    = 'blc-advanced-tab-' . sanitize_title($slug);
                $panel_id  = 'blc-advanced-panel-' . sanitize_title($slug);
                $tab_classes = array('blc-settings-advanced__tab', $is_active ? 'is-active' : '');
                $tab_hint = isset($group['tab_hint']) ? (string) $group['tab_hint'] : '';

                echo '<button type="button" role="tab" class="' . esc_attr(implode(' ', array_filter(array_map('sanitize_html_class', $tab_classes)))) . '" id="' . esc_attr($tab_id) . '" aria-controls="' . esc_attr($panel_id) . '" aria-selected="' . ($is_active ? 'true' : 'false') . '" tabindex="' . ($is_active ? '0' : '-1') . '" data-blc-target="' . esc_attr($slug) . '">';
                echo '<span class="blc-settings-advanced__tab-label">' . esc_html($group['label']) . '</span>';
                if ($tab_hint !== '') {
                    echo '<span class="blc-settings-advanced__tab-hint">' . esc_html($tab_hint) . '</span>';
                }
                echo '</button>';
            }
            echo '</div>';

            echo '<div class="blc-settings-advanced__panels">';
            foreach ($grouped_sections as $slug => $group) {
                $is_active = ($slug === $default_group);
                $panel_id  = 'blc-advanced-panel-' . sanitize_title($slug);
                $tab_id    = 'blc-advanced-tab-' . sanitize_title($slug);
                $panel_classes = array('blc-settings-advanced__panel', $is_active ? 'is-active' : '');
                $panel_attributes = $is_active ? '' : ' hidden';

                echo '<section class="' . esc_attr(implode(' ', array_filter(array_map('sanitize_html_class', $panel_classes)))) . '" id="' . esc_attr($panel_id) . '" role="tabpanel" aria-labelledby="' . esc_attr($tab_id) . '" data-blc-panel="' . esc_attr($slug) . '"' . $panel_attributes . '>';
                echo '<header class="blc-settings-advanced__panel-header">';
                echo '<h3 class="blc-settings-advanced__panel-title">' . esc_html($group['label']) . '</h3>';
                if (!empty($group['description'])) {
                    echo '<p class="blc-settings-advanced__panel-description">' . esc_html($group['description']) . '</p>';
                }
                echo '</header>';

                if (!empty($group['sections'])) {
                    foreach ($group['sections'] as $section_id) {
                        blc_render_settings_section($page, $section_id);
                    }
                }

                echo '</section>';
            }
            echo '</div>';

            echo '</div>'; // .blc-settings-advanced
            echo '</div>';
            echo '</details>';
            $advanced_markup = ob_get_clean();
        }
    }

    if ($advanced_markup !== '') {
        echo '<div class="blc-settings-groups__advanced" data-blc-settings-advanced-placeholder>';
        if ($show_advanced) {
            echo $advanced_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped in template.
        }
        echo '</div>';

        echo '<template id="blc-settings-advanced-template">' . $advanced_markup . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    echo '</div>';
}

/**
 * Output a single settings section with its fields.
 *
 * @param string $page       Settings page slug.
 * @param string $section_id Section identifier.
 *
 * @return void
 */
function blc_render_settings_section($page, $section_id) {
    global $wp_settings_sections, $wp_settings_fields;

    if (!isset($wp_settings_sections[$page][$section_id])) {
        return;
    }

    $section = $wp_settings_sections[$page][$section_id];

    echo '<section class="blc-settings-section" id="' . esc_attr($section_id) . '">';
    if (!empty($section['title'])) {
        echo '<h3 class="blc-settings-section__title">' . esc_html($section['title']) . '</h3>';
    }

    if (isset($section['callback']) && is_callable($section['callback'])) {
        call_user_func($section['callback'], $section);
    }

    if (!empty($wp_settings_fields[$page][$section_id])) {
        echo '<table class="form-table blc-settings-table" role="presentation">';
        do_settings_fields($page, $section_id);
        echo '</table>';
    }

    echo '</section>';
}

/**
 * Persist the preferred settings mode via Ajax.
 *
 * @return void
 */
function blc_ajax_update_settings_mode() {
    if (!blc_current_user_can_manage_settings()) {
        wp_send_json_error(
            array('message' => __('Permissions insuffisantes pour modifier ce réglage.', 'liens-morts-detector-jlg')),
            403
        );
    }

    check_ajax_referer('blc_settings_mode');

    $mode = isset($_POST['mode']) ? (string) wp_unslash($_POST['mode']) : 'simple'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via nonce above.
    $saved_mode = blc_update_settings_mode($mode);

    $announcement = ($saved_mode === 'advanced')
        ? __('Mode avancé activé. Les réglages supplémentaires sont visibles.', 'liens-morts-detector-jlg')
        : __('Mode simple activé. Les réglages avancés sont masqués.', 'liens-morts-detector-jlg');

    wp_send_json_success(
        array(
            'mode'        => $saved_mode,
            'announcement' => $announcement,
        )
    );
}

/**
 * Attempt to reschedule the automatic link scan event and return a structured payload.
 *
 * @param string $context Execution context label.
 *
 * @return array<string, mixed>
 */
function blc_reschedule_link_scan_event($context = 'dashboard') {
    $result = blc_reset_link_check_schedule(
        array(
            'context' => $context,
        )
    );

    $payload = array(
        'success'  => (bool) $result['success'],
        'warnings' => array(),
    );

    if (!$payload['success']) {
        $payload['message'] = __("La reprogrammation de l'analyse automatique a échoué. Vérifiez la configuration de WP-Cron.", 'liens-morts-detector-jlg');

        if (!empty($result['restore_attempted']) && empty($result['restored'])) {
            $payload['warnings'][] = __("La planification précédente n'a pas pu être restaurée. Une intervention manuelle est nécessaire.", 'liens-morts-detector-jlg');
        }

        $payload['resolution_hint'] = __("Assurez-vous que WP-Cron est actif (`DISABLE_WP_CRON` à false) ou exécutez `wp cron event run blc_check_batch`.", 'liens-morts-detector-jlg');

        $payload['status'] = blc_get_link_scan_status_payload();

        return $payload;
    }

    $payload['message'] = __("La vérification automatique a été reprogrammée avec succès.", 'liens-morts-detector-jlg');

    if (!empty($result['restore_attempted']) && !empty($result['restored'])) {
        $payload['warnings'][] = __("La planification précédente a été restaurée automatiquement.", 'liens-morts-detector-jlg');
    }

    if (function_exists('wp_next_scheduled')) {
        $next_scheduled = wp_next_scheduled('blc_check_batch');
        if ($next_scheduled) {
            $payload['next_run'] = (int) $next_scheduled;
        }
    }

    $payload['status'] = blc_get_link_scan_status_payload();

    return $payload;
}

add_action('wp_ajax_blc_start_manual_scan', 'blc_ajax_start_manual_scan');
add_action('wp_ajax_blc_cancel_manual_scan', 'blc_ajax_cancel_manual_scan');
add_action('wp_ajax_blc_get_scan_status', 'blc_ajax_get_scan_status');
add_action('wp_ajax_blc_reschedule_cron', 'blc_ajax_reschedule_cron');
add_action('wp_ajax_blc_start_manual_image_scan', 'blc_ajax_start_manual_image_scan');
add_action('wp_ajax_blc_cancel_manual_image_scan', 'blc_ajax_cancel_manual_image_scan');
add_action('wp_ajax_blc_get_image_scan_status', 'blc_ajax_get_image_scan_status');
add_action('wp_ajax_blc_fetch_links_table', 'blc_ajax_fetch_links_table');
add_action('wp_ajax_blc_save_links_view', 'blc_ajax_save_links_view');
add_action('wp_ajax_blc_delete_links_view', 'blc_ajax_delete_links_view');
add_action('wp_ajax_blc_update_settings_mode', 'blc_ajax_update_settings_mode');

/**
 * AJAX handler to start a manual scan via admin-ajax.
 *
 * @return void
 */
function blc_ajax_start_manual_scan() {
    check_ajax_referer('blc_start_manual_scan');

    if (!blc_current_user_can_fix_links()) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour lancer une analyse manuelle.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $is_full_scan = isset($_POST['full_scan']) && (int) $_POST['full_scan'] === 1;
    $force_cancel = isset($_POST['force_cancel']) && (int) $_POST['force_cancel'] === 1;
    $queue_on_busy = isset($_POST['queue_on_busy']) && (int) $_POST['queue_on_busy'] === 1;

    $options = array(
        'requested_by'    => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        'enqueue_context' => 'manual_dashboard_ajax',
    );

    $result = blc_schedule_manual_link_scan($is_full_scan, $force_cancel, $queue_on_busy, $options);

    if (!$result['success']) {
        $error_data = array(
            'message' => $result['message'],
            'status'  => blc_get_link_scan_status_payload(),
        );

        if (isset($result['queue_length'])) {
            $error_data['queue_length'] = (int) $result['queue_length'];
        }

        if (!empty($result['requires_confirmation'])) {
            $error_data['requires_confirmation'] = true;
            if (!empty($result['current_state'])) {
                $error_data['current_state'] = $result['current_state'];
            }
            if (!empty($result['resolution_hint'])) {
                $error_data['resolution_hint'] = $result['resolution_hint'];
            }
            if (!empty($result['queue_available'])) {
                $error_data['queue_available'] = true;
            }
            wp_send_json_error($error_data, 409);
        }

        if (!empty($result['resolution_hint'])) {
            $error_data['resolution_hint'] = $result['resolution_hint'];
        }

        wp_send_json_error($error_data, 500);
    }

    $status_payload = blc_get_link_scan_status_payload();

    $response = array(
        'message'               => $result['message'],
        'status'                => $status_payload,
        'manual_trigger_failed' => !empty($result['manual_trigger_failed']),
    );

    if (!empty($result['manual_trigger_failed'])) {
        $response['warning'] = __("Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.", 'liens-morts-detector-jlg');
    }

    if (!empty($result['queued'])) {
        $response['queued'] = true;
    }

    if (isset($result['queue_length'])) {
        $response['queue_length'] = (int) $result['queue_length'];
    }

    if (!empty($result['queue_entry'])) {
        $response['queue_entry'] = $result['queue_entry'];
    }

    if (!empty($result['queue_cleared'])) {
        $response['queue_cleared'] = true;
    }

    wp_send_json_success($response);
}

/**
 * AJAX handler to reschedule the automatic link scan event.
 *
 * @return void
 */
function blc_ajax_reschedule_cron() {
    check_ajax_referer('blc_reschedule_cron_nonce');

    if (!blc_current_user_can_fix_links()) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour reprogrammer la vérification automatique.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $payload = blc_reschedule_link_scan_event('dashboard_ajax');

    if (!$payload['success']) {
        wp_send_json_error($payload, 500);
    }

    wp_send_json_success($payload);
}

/**
 * AJAX handler to cancel upcoming manual scan batches.
 *
 * @return void
 */
function blc_ajax_cancel_manual_scan() {
    check_ajax_referer('blc_cancel_manual_scan');

    if (!blc_current_user_can_fix_links()) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour annuler une analyse manuelle.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $manual_cleared = function_exists('wp_clear_scheduled_hook')
        ? wp_clear_scheduled_hook('blc_manual_check_batch')
        : 0;
    $batch_cleared = function_exists('wp_clear_scheduled_hook')
        ? wp_clear_scheduled_hook('blc_check_batch')
        : 0;

    $message = __('Les lots planifiés ont été annulés. Le lot en cours peut se terminer.', 'liens-morts-detector-jlg');

    blc_update_link_scan_status([
        'state'             => 'cancelled',
        'message'           => $message,
        'last_error'        => '',
        'remaining_batches' => 0,
        'total_items'       => 0,
        'processed_items'   => 0,
    ]);

    wp_send_json_success(
        array(
            'message'        => $message,
            'status'         => blc_get_link_scan_status_payload(),
            'cleared_manual' => (int) $manual_cleared,
            'cleared_batch'  => (int) $batch_cleared,
        )
    );
}

/**
 * AJAX handler returning the current scan status payload.
 *
 * @return void
 */
function blc_ajax_get_scan_status() {
    check_ajax_referer('blc_get_scan_status');

    if (!blc_current_user_can_view_reports()) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour consulter le statut du scan.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    wp_send_json_success(
        array(
            'status' => blc_get_link_scan_status_payload(),
        )
    );
}

/**
 * AJAX handler to start a manual image scan.
 *
 * @return void
 */
function blc_ajax_start_manual_image_scan() {
    check_ajax_referer('blc_start_manual_image_scan');

    if (!blc_current_user_can_fix_links()) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour lancer une analyse manuelle.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $result = blc_schedule_manual_image_scan();

    if (!$result['success']) {
        wp_send_json_error(
            array(
                'message' => $result['message'],
                'status'  => blc_get_image_scan_status_payload(),
                'job_id'  => isset($result['job_id']) ? (string) $result['job_id'] : '',
                'attempt' => isset($result['attempt']) ? (int) $result['attempt'] : 0,
                'scheduled_at' => isset($result['scheduled_at']) ? (int) $result['scheduled_at'] : 0,
            ),
            500
        );
    }

    $response = array(
        'message'               => $result['message'],
        'status'                => blc_get_image_scan_status_payload(),
        'manual_trigger_failed' => !empty($result['manual_trigger_failed']),
        'job_id'                => isset($result['job_id']) ? (string) $result['job_id'] : '',
        'attempt'               => isset($result['attempt']) ? (int) $result['attempt'] : 0,
        'scheduled_at'          => isset($result['scheduled_at']) ? (int) $result['scheduled_at'] : 0,
    );

    if (!empty($result['manual_trigger_failed'])) {
        $response['warning'] = __("Le déclenchement immédiat du cron a échoué. Le système WordPress essaiera de l'exécuter automatiquement.", 'liens-morts-detector-jlg');
    }

    wp_send_json_success($response);
}

/**
 * AJAX handler to cancel upcoming manual image scan batches.
 *
 * @return void
 */
function blc_ajax_cancel_manual_image_scan() {
    check_ajax_referer('blc_cancel_manual_image_scan');

    if (!blc_current_user_can_fix_links()) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour annuler une analyse manuelle.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $cleared_batches = function_exists('wp_clear_scheduled_hook')
        ? wp_clear_scheduled_hook('blc_check_image_batch', array(0, true))
        : 0;

    $message = __('Les lots planifiés ont été annulés. Le lot en cours peut se terminer.', 'liens-morts-detector-jlg');

    blc_update_image_scan_status([
        'state'             => 'cancelled',
        'message'           => $message,
        'last_error'        => '',
        'remaining_batches' => 0,
        'total_items'       => 0,
        'processed_items'   => 0,
    ]);

    wp_send_json_success(
        array(
            'message'        => $message,
            'status'         => blc_get_image_scan_status_payload(),
            'cleared_batches' => (int) $cleared_batches,
        )
    );
}

/**
 * AJAX handler returning the current image scan status payload.
 *
 * @return void
 */
function blc_ajax_get_image_scan_status() {
    check_ajax_referer('blc_get_image_scan_status');

    if (!blc_current_user_can_view_reports()) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour consulter le statut du scan.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    wp_send_json_success(
        array(
            'status' => blc_get_image_scan_status_payload(),
        )
    );
}

/**
 * AJAX handler to retrieve the links list table markup via admin-ajax.
 *
 * @return void
 */
function blc_ajax_fetch_links_table() {
    check_ajax_referer('blc_links_table');

    if (!blc_current_user_can_view_reports()) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour consulter ce rapport.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $request_args = blc_normalize_links_table_request($_REQUEST);

    $list_table = new BLC_Links_List_Table();
    $list_table->set_request_args($request_args);
    $list_table->prepare_items();

    wp_send_json_success(
        array(
            'markup' => blc_render_links_table_markup($list_table),
            'state'  => $list_table->export_state(),
        )
    );
}

/**
 * Handle the creation or update of a saved links view via AJAX.
 *
 * @return void
 */
function blc_ajax_save_links_view() {
    check_ajax_referer('blc_links_views');

    if (!blc_current_user_can_view_reports()) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour gérer les vues enregistrées.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $name = isset($_POST['name']) ? (string) wp_unslash($_POST['name']) : '';
    $filters = array();
    if (isset($_POST['filters']) && is_array($_POST['filters'])) {
        $filters = wp_unslash($_POST['filters']);
    }

    $default_flag = null;
    if (isset($_POST['is_default'])) {
        $raw_default = wp_unslash($_POST['is_default']);
        $default_flag = in_array($raw_default, array('1', 'true', 'on', 'yes'), true);
    }

    $result = blc_save_link_view($name, is_array($filters) ? $filters : array(), 0, $default_flag);

    if (is_wp_error($result)) {
        $error_data = array(
            'message' => $result->get_error_message(),
            'code'    => $result->get_error_code(),
        );

        $error_meta = $result->get_error_data();
        if (is_array($error_meta)) {
            $error_data = array_merge($error_data, $error_meta);
        }

        wp_send_json_error($error_data, 400);
    }

    $response = array(
        'views'  => blc_prepare_link_views_for_client(isset($result['views']) && is_array($result['views']) ? $result['views'] : array()),
        'view'   => blc_prepare_link_view_for_client(isset($result['view']) && is_array($result['view']) ? $result['view'] : array()),
        'status' => isset($result['status']) ? (string) $result['status'] : '',
        'limit'  => isset($result['limit']) ? (int) $result['limit'] : blc_get_saved_link_views_limit(),
        'default_status' => isset($result['default_status']) ? (string) $result['default_status'] : 'unchanged',
        'default_view_id' => isset($result['default_view_id']) ? (string) $result['default_view_id'] : '',
    );

    wp_send_json_success($response);
}

/**
 * Handle the deletion of a saved links view via AJAX.
 *
 * @return void
 */
function blc_ajax_delete_links_view() {
    check_ajax_referer('blc_links_views');

    if (!blc_current_user_can_view_reports()) {
        wp_send_json_error(
            array(
                'message' => __('Permissions insuffisantes pour gérer les vues enregistrées.', 'liens-morts-detector-jlg'),
            ),
            defined('BLC_HTTP_FORBIDDEN') ? (int) BLC_HTTP_FORBIDDEN : 403
        );
    }

    $view_id = isset($_POST['id']) ? (string) wp_unslash($_POST['id']) : '';

    $result = blc_delete_link_view($view_id);

    if (is_wp_error($result)) {
        $error_data = array(
            'message' => $result->get_error_message(),
            'code'    => $result->get_error_code(),
        );

        $error_meta = $result->get_error_data();
        if (is_array($error_meta)) {
            $error_data = array_merge($error_data, $error_meta);
        }

        wp_send_json_error($error_data, 400);
    }

    $response = array(
        'views' => blc_prepare_link_views_for_client(isset($result['views']) && is_array($result['views']) ? $result['views'] : array()),
        'view'  => blc_prepare_link_view_for_client(isset($result['view']) && is_array($result['view']) ? $result['view'] : array()),
    );

    wp_send_json_success($response);
}

