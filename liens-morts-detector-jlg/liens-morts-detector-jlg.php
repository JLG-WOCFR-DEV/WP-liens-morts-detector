<?php
/*
Plugin Name: Liens morts detector - JLG
Description: Détecte les liens et images morts sur votre site WordPress et les signale dans le menu d'administration. Prend en charge les vérifications planifiées et des outils de réparation rapide.
Version: 1.0
Author: Jérôme Le Gousse
Text Domain: liens-morts-detector-jlg
Domain Path: /languages
*/

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

// Définir les constantes utiles du plugin
define('BLC_PLUGIN_PATH', plugin_dir_path(__FILE__));

if (!defined('BLC_HTTP_BAD_REQUEST')) {
    define('BLC_HTTP_BAD_REQUEST', 400);
}

if (!defined('BLC_HTTP_FORBIDDEN')) {
    define('BLC_HTTP_FORBIDDEN', 403);
}

if (!defined('BLC_HTTP_NOT_FOUND')) {
    define('BLC_HTTP_NOT_FOUND', 404);
}

if (!defined('BLC_HTTP_CONFLICT')) {
    define('BLC_HTTP_CONFLICT', 409);
}

if (!defined('BLC_HTTP_SERVER_ERROR')) {
    define('BLC_HTTP_SERVER_ERROR', 500);
}

// Charge le domaine de traduction du plugin.
add_action('plugins_loaded', 'blc_load_textdomain');

/**
 * Initialise la traduction du plugin.
 *
 * @return void
 */
function blc_load_textdomain() {
    load_plugin_textdomain('liens-morts-detector-jlg', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// --- Chargement des Fichiers ---
// On inclut tous les fichiers nécessaires au fonctionnement.
require_once BLC_PLUGIN_PATH . 'includes/Admin/AdminAssets.php';
require_once BLC_PLUGIN_PATH . 'includes/Admin/AdminScriptLocalizations.php';
require_once BLC_PLUGIN_PATH . 'includes/Admin/DashboardCache.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-capabilities.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-activation.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-cron.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-surveillance.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-surveillance-escalation.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-scanner.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-reporting.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-google-sheets.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-s3-exports.php';
require_once BLC_PLUGIN_PATH . 'includes/Notifications/NotificationManager.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-notification-payloads.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-utils.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-settings-fields.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-admin-pages.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-reports.php';
require_once BLC_PLUGIN_PATH . 'includes/class-blc-links-list-table.php';
require_once BLC_PLUGIN_PATH . 'includes/class-blc-images-list-table.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-cli.php';

/**
 * Mark the cached view counters for broken links as stale so they can be recalculated.
 *
 * @return void
 */
function blc_mark_link_view_counts_dirty() {
    if (!function_exists('update_option')) {
        return;
    }

    static $already_marked = false;

    if ($already_marked) {
        return;
    }

    $already_marked = true;
    $timestamp = time();

    update_option('blc_links_view_counts_dirty', $timestamp, false);

    /**
     * Fires after the broken link view counters have been flagged for refresh.
     *
     * @since 1.0.0
     *
     * @param int $timestamp Unix timestamp saved alongside the dirty flag.
     */
    do_action('blc_links_view_counts_marked_dirty', $timestamp);
}

// --- Hameçons (Hooks) d'Activation et de Désactivation ---
// Ces fonctions s'exécutent uniquement à l'activation ou la désactivation du plugin.
register_activation_hook(__FILE__, 'blc_activation');
register_deactivation_hook(__FILE__, 'blc_deactivation');

// Vérifie si la base de données doit être migrée après une mise à jour du plugin.
add_action('plugins_loaded', 'blc_maybe_upgrade_database');

// --- Initialisation des Actions et Filtres ---
// On connecte les fonctions du plugin au cœur de WordPress.

// Ajoute le menu et les pages dans l'administration
add_action('admin_menu', 'blc_add_admin_menu');

// Affiche la notification d'échec de planification lors de l'activation si nécessaire.
add_action('admin_notices', 'blc_maybe_show_activation_schedule_notice');
add_action('network_admin_notices', 'blc_maybe_show_activation_schedule_notice');

add_action('blc_links_view_counts_marked_dirty', 'blc_invalidate_top_domain_stats_cache');

// Ajoute nos planifications personnalisées (hebdomadaire, mensuelle) à WP-Cron
add_filter('cron_schedules', 'blc_add_cron_schedules');

// Lie nos fonctions de scan aux tâches planifiées
add_action('blc_check_links', 'blc_perform_check');
add_action('blc_check_batch', 'blc_perform_check', 10, 4);
add_action('blc_manual_check_batch', 'blc_perform_check', 10, 4);
add_action('blc_check_image_batch', 'blc_perform_image_check', 10, 2);
add_action('blc_generate_report_exports', 'blc_run_automated_report_exports');

// Ajoute nos fichiers CSS et JS dans l'administration
add_action('admin_enqueue_scripts', 'blc_enqueue_admin_assets');
add_filter('admin_body_class', 'blc_add_admin_body_class');

function blc_admin_assets_manager() {
    static $manager = null;

    if ($manager === null) {
        $manager = new \JLG\BrokenLinks\Admin\AdminAssets(__FILE__);
    }

    return $manager;
}

function blc_enqueue_admin_assets($hook) {
    blc_admin_assets_manager()->enqueue($hook);
}

function blc_should_enqueue_admin_assets($hook) {
    return blc_admin_assets_manager()->shouldEnqueue($hook);
}

function blc_get_admin_page_slugs() {
    return blc_admin_assets_manager()->getAdminPageSlugs();
}

function blc_is_plugin_admin_request() {
    return blc_admin_assets_manager()->isPluginAdminRequest();
}

function blc_add_admin_body_class($classes) {
    if (!blc_is_plugin_admin_request()) {
        return $classes;
    }

    $classes       = is_string($classes) ? $classes : '';
    $ui_preset     = blc_get_active_ui_preset();
    $ui_preset_key = sanitize_key($ui_preset);
    $preset_class  = 'blc-preset--' . (function_exists('sanitize_html_class') ? sanitize_html_class($ui_preset_key) : $ui_preset_key);

    if (strpos($classes, 'blc-ui-enhanced') === false) {
        $classes .= ' blc-ui-enhanced';
    }

    if (strpos($classes, $preset_class) === false) {
        $classes .= ' ' . $preset_class;
    }

    if (function_exists('blc_get_accessibility_preferences')) {
        $accessibility = blc_get_accessibility_preferences();

        if (!empty($accessibility['high_contrast']) && strpos($classes, 'blc-accessibility--high-contrast') === false) {
            $classes .= ' blc-accessibility--high-contrast';
        }

        if (!empty($accessibility['reduce_motion']) && strpos($classes, 'blc-accessibility--reduce-motion') === false) {
            $classes .= ' blc-accessibility--reduce-motion';
        }

        if (!empty($accessibility['large_font']) && strpos($classes, 'blc-accessibility--large-font') === false) {
            $classes .= ' blc-accessibility--large-font';
        }
    }

    return trim($classes);
}

function blc_notification_manager() {
    static $manager = null;

    if ($manager === null) {
        $manager = new \JLG\BrokenLinks\Notifications\NotificationManager();
    }

    return $manager;
}

/**
 * Retrieve the stored notification delivery history.
 *
 * @param int|null $limit Optional number of entries to return.
 *
 * @return array<int, array<string, mixed>>
 */
function blc_get_notification_delivery_history($limit = null) {
    return blc_notification_manager()->getHistoryEntries($limit);
}

/**
 * Retourne la configuration actuelle du webhook avec la possibilité de surcharger certaines valeurs.
 *
 * @param array<string, mixed> $overrides Valeurs de remplacement éventuelles.
 *
 * @return array<string, string>
 */
function blc_get_notification_webhook_settings($overrides = array()) {
    $settings = array(
        'url'              => blc_normalize_notification_webhook_url(get_option('blc_notification_webhook_url', '')),
        'channel'          => blc_normalize_notification_webhook_channel(get_option('blc_notification_webhook_channel', 'disabled')),
        'message_template' => blc_normalize_notification_message_template(get_option('blc_notification_message_template', "{{subject}}\n\n{{message}}")),
        'slack_channel_override' => blc_normalize_notification_slack_channel_override(get_option('blc_notification_slack_channel_override', '')),
        'slack_username'         => blc_normalize_notification_slack_username(get_option('blc_notification_slack_username', '')),
        'slack_icon'             => blc_normalize_notification_slack_icon(get_option('blc_notification_slack_icon', '')),
        'slack_title_template'   => blc_normalize_notification_slack_title_template(get_option('blc_notification_slack_title_template', '{{subject}}')),
        'slack_show_filters'     => blc_normalize_notification_slack_toggle(get_option('blc_notification_slack_show_filters', true)),
        'slack_show_top_issues'  => blc_normalize_notification_slack_toggle(get_option('blc_notification_slack_show_top_issues', true)),
    );

    if (is_array($overrides)) {
        if (array_key_exists('url', $overrides)) {
            $settings['url'] = blc_normalize_notification_webhook_url($overrides['url']);
        }

        if (array_key_exists('channel', $overrides)) {
            $settings['channel'] = blc_normalize_notification_webhook_channel($overrides['channel']);
        }

        if (array_key_exists('message_template', $overrides)) {
            $settings['message_template'] = blc_normalize_notification_message_template($overrides['message_template']);
        }

        if (array_key_exists('slack_channel_override', $overrides)) {
            $settings['slack_channel_override'] = blc_normalize_notification_slack_channel_override($overrides['slack_channel_override']);
        }

        if (array_key_exists('slack_username', $overrides)) {
            $settings['slack_username'] = blc_normalize_notification_slack_username($overrides['slack_username']);
        }

        if (array_key_exists('slack_icon', $overrides)) {
            $settings['slack_icon'] = blc_normalize_notification_slack_icon($overrides['slack_icon']);
        }

        if (array_key_exists('slack_title_template', $overrides)) {
            $settings['slack_title_template'] = blc_normalize_notification_slack_title_template($overrides['slack_title_template']);
        }

        if (array_key_exists('slack_show_filters', $overrides)) {
            $settings['slack_show_filters'] = blc_normalize_notification_slack_toggle($overrides['slack_show_filters']);
        }

        if (array_key_exists('slack_show_top_issues', $overrides)) {
            $settings['slack_show_top_issues'] = blc_normalize_notification_slack_toggle($overrides['slack_show_top_issues']);
        }
    }

    return $settings;
}

/**
 * Indique si un webhook est correctement configuré.
 *
 * @param array<string, string>|null $settings Configuration existante.
 *
 * @return bool
 */
function blc_is_webhook_notification_configured($settings = null) {
    if ($settings === null) {
        $settings = blc_get_notification_webhook_settings();
    }

    if (!is_array($settings)) {
        return false;
    }

    $channel = isset($settings['channel']) ? (string) $settings['channel'] : 'disabled';
    $url     = isset($settings['url']) ? (string) $settings['url'] : '';

    if ($channel === 'disabled') {
        return false;
    }

    return $url !== '';
}

/**
 * Remplace les placeholders du modèle de notification par les valeurs calculées.
 *
 * @param string               $template Modèle configuré.
 * @param array<string, mixed> $summary  Résumé d'analyse.
 *
 * @return string
 */
function blc_render_notification_message_template($template, array $summary) {
    $template = (string) $template;
    if ($template === '') {
        $template = "{{subject}}\n\n{{message}}";
    }

    $replacements = array(
        '{{subject}}'       => isset($summary['subject']) ? (string) $summary['subject'] : '',
        '{{message}}'       => isset($summary['message']) ? (string) $summary['message'] : '',
        '{{dataset_type}}'  => isset($summary['dataset_type']) ? (string) $summary['dataset_type'] : '',
        '{{dataset_label}}' => isset($summary['dataset_label']) ? (string) $summary['dataset_label'] : '',
        '{{broken_count}}'  => isset($summary['broken_count']) ? (string) (int) $summary['broken_count'] : '0',
        '{{report_url}}'    => isset($summary['report_url']) ? (string) $summary['report_url'] : '',
        '{{site_name}}'     => isset($summary['site_name']) ? (string) $summary['site_name'] : '',
    );

    $rendered = strtr($template, $replacements);

    return trim($rendered);
}

/**
 * Prépare et envoie la notification webhook via le gestionnaire mutualisé.
 *
 * @param string               $dataset_type Type d'analyse.
 * @param array<string, mixed> $summary      Résumé d'analyse.
 * @param array<string, mixed> $settings     Configuration du webhook.
 *
 * @return array<string, mixed>|WP_Error
 */
function blc_send_scan_summary_webhook($dataset_type, array $summary, array $settings) {
    return blc_notification_manager()->sendWebhookOnly($dataset_type, $summary, $settings);
}

/**
 * Envoie les notifications configurées (e-mail + webhook) et retourne le statut de chaque canal.
 *
 * @param string               $dataset_type Type d'analyse.
 * @param array<string, mixed> $summary      Résumé d'analyse.
 * @param string[]             $recipients   Destinataires e-mail.
 * @param array<string, mixed> $args         Options complémentaires.
 *
 * @return array<string, array<string, mixed>>
 */
function blc_dispatch_scan_summary_notifications($dataset_type, array $summary, array $recipients, array $args = array()) {
    return blc_notification_manager()->sendSummaryNotifications($dataset_type, $summary, $recipients, $args);
}

/**
 * Construit un littéral XPath sécurisé pour la valeur fournie.
 *
 * Cette fonction s'assure que la chaîne est correctement encapsulée dans un
 * littéral XPath, même si elle contient des guillemets simples et doubles.
 *
 * @param string $value La valeur à échapper.
 * @return string Le littéral XPath sécurisé.
 */
function blc_xpath_escape($value) {
    $value = (string) $value;

    if ($value === '') {
        return "''";
    }

    if (strpos($value, "'") === false) {
        return "'" . $value . "'";
    }

    if (strpos($value, '"') === false) {
        return '"' . $value . '"';
    }

    $segments = [];
    $parts = explode("'", $value);

    foreach ($parts as $index => $part) {
        $segments[] = "'" . $part . "'";

        if ($index !== count($parts) - 1) {
            $segments[] = "\"'\"";
        }
    }

    if (count($segments) === 1) {
        return $segments[0];
    }

    return 'concat(' . implode(', ', $segments) . ')';
}

// --- Fonctions de rappel AJAX pour les actions rapides ---

/**
 * Récupère et valide la présence des paramètres requis pour une requête AJAX.
 *
 * Chaque paramètre doit être défini dans $_POST, et sa valeur (après suppression
 * des slashes et trim) ne doit pas être vide.
 *
 * @param string[] $required_params Liste des clés attendues dans $_POST.
 * @return array Tableau associatif des valeurs nettoyées.
 *               Cette fonction envoie une réponse JSON d'erreur et interrompt l'exécution
 *               via wp_send_json_error() si une validation échoue.
 */
function blc_require_post_params(array $required_params) {
    $values = [];

    foreach ($required_params as $param) {
        if (!isset($_POST[$param])) {
            wp_send_json_error([
                'message' => sprintf(
                    __('Le paramètre requis "%s" est manquant ou vide.', 'liens-morts-detector-jlg'),
                    $param
                ),
            ], BLC_HTTP_BAD_REQUEST);
        }

        $raw_value = wp_unslash($_POST[$param]);

        if (is_string($raw_value)) {
            $value = trim($raw_value);
        } elseif (is_scalar($raw_value)) {
            $value = trim((string) $raw_value);
        } else {
            $value = '';
        }

        if ($value === '') {
            wp_send_json_error([
                'message' => sprintf(
                    __('Le paramètre requis "%s" est manquant ou vide.', 'liens-morts-detector-jlg'),
                    $param
                ),
            ], BLC_HTTP_BAD_REQUEST);
        }

        $values[$param] = $value;
    }

    return $values;
}

/**
 * Applique une nouvelle URL à un lien détecté et purge son entrée.
 *
 * @param array $args Arguments décrivant le lien à mettre à jour.
 * @return array|WP_Error
 */
function blc_perform_link_update(array $args) {
    if (!function_exists('blc_current_user_can_fix_links') || !blc_current_user_can_fix_links()) {
        return new WP_Error('blc_forbidden', __('Permissions insuffisantes.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_FORBIDDEN]);
    }

    global $wpdb;

    $defaults = [
        'post_id'             => 0,
        'row_id'              => 0,
        'row'                 => [],
        'occurrence_index'    => null,
        'table_name'          => '',
        'row_cache'           => [],
        'row_cache_footprint' => 0,
        'old_url'             => '',
        'new_url'             => '',
        'success_message'     => '',
        'apply_globally'      => false,
        'preview_only'        => false,
    ];

    $args = array_merge($defaults, $args);

    $apply_globally = !empty($args['apply_globally']);
    $preview_only   = !empty($args['preview_only']);

    $post_id = absint($args['post_id']);
    $row_id  = absint($args['row_id']);
    $row     = is_array($args['row']) ? $args['row'] : [];

    if ($row_id <= 0) {
        return new WP_Error('blc_invalid_row', __('Le lien sélectionné est introuvable. Veuillez relancer une analyse.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_BAD_REQUEST]);
    }

    $table_name = $args['table_name'];
    if (!is_string($table_name) || $table_name === '') {
        $table_name = $wpdb->prefix . 'blc_broken_links';
    }

    $occurrence_index = $args['occurrence_index'];
    if ($occurrence_index !== null) {
        if (is_numeric($occurrence_index)) {
            $candidate = (int) $occurrence_index;
            $occurrence_index = $candidate >= 0 ? $candidate : null;
        } else {
            $occurrence_index = null;
        }
    }

    $row_cache = is_array($args['row_cache']) ? $args['row_cache'] : [];
    $row_cache_footprint = isset($args['row_cache_footprint']) ? (int) $args['row_cache_footprint'] : 0;

    if ($row_cache_footprint === 0 && !empty($row_cache)) {
        $row_cache_footprint = blc_calculate_row_storage_footprint_bytes(
            isset($row_cache['url']) ? (string) $row_cache['url'] : '',
            isset($row_cache['anchor']) ? (string) $row_cache['anchor'] : '',
            isset($row_cache['post_title']) ? (string) $row_cache['post_title'] : '',
            isset($row_cache['context_html']) ? (string) $row_cache['context_html'] : '',
            isset($row_cache['context_excerpt']) ? (string) $row_cache['context_excerpt'] : ''
        );
    }

    $stored_row_url = isset($row['url']) ? (string) $row['url'] : '';

    $prepared_old_url = blc_prepare_posted_url($args['old_url']);
    $prepared_new_url = blc_prepare_posted_url($args['new_url']);

    if ($prepared_old_url === '' || $prepared_new_url === '') {
        return new WP_Error('blc_invalid_url', __('URL invalide.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_BAD_REQUEST]);
    }

    $normalized_old_input = blc_normalize_user_input_url($prepared_old_url);
    $normalized_new_input = blc_normalize_user_input_url($prepared_new_url);
    if ($normalized_old_input !== '' && $normalized_new_input !== '' && $normalized_old_input === $normalized_new_input) {
        return new WP_Error('blc_same_url', __('La nouvelle URL doit être différente de l\'URL actuelle.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_BAD_REQUEST]);
    }

    $stored_old_url = blc_prepare_url_for_storage($prepared_old_url);
    if ($stored_old_url === '' || $stored_old_url !== $stored_row_url) {
        return new WP_Error('blc_row_mismatch', __('Le lien sélectionné est introuvable. Veuillez relancer une analyse.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_CONFLICT]);
    }

    $raw_home_url = home_url();
    $site_url = trailingslashit($raw_home_url);
    $site_scheme = parse_url($raw_home_url, PHP_URL_SCHEME);
    if (!is_string($site_scheme) || $site_scheme === '') {
        $site_scheme = 'http';
    }

    $post = $post_id > 0 ? get_post($post_id) : null;
    $permalink = '';
    if (function_exists('get_permalink')) {
        if ($post instanceof WP_Post) {
            $permalink_candidate = get_permalink($post);
        } else {
            $permalink_candidate = get_permalink($post_id);
        }

        if (is_string($permalink_candidate)) {
            $permalink = $permalink_candidate;
        }
    }

    $allowed_protocols = ['http', 'https'];
    $sanitized_new_url = wp_kses_bad_protocol($prepared_new_url, $allowed_protocols);
    if (!is_string($sanitized_new_url) || $sanitized_new_url === '') {
        return new WP_Error('blc_invalid_url', __('URL invalide.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_BAD_REQUEST]);
    }

    $normalized_sanitized_new_url = blc_normalize_user_input_url($sanitized_new_url);
    if ($normalized_sanitized_new_url !== $normalized_new_input) {
        return new WP_Error('blc_invalid_url', __('URL invalide.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_BAD_REQUEST]);
    }

    $clean_new_url = $sanitized_new_url;
    $looks_like_domain_input = blc_url_looks_like_bare_domain($clean_new_url);

    $validated_old_url = wp_http_validate_url($prepared_old_url);
    $normalized_old_url = $validated_old_url ?: blc_normalize_link_url($prepared_old_url, $site_url, $site_scheme, $permalink);

    $validated_new_url = wp_http_validate_url($clean_new_url);
    $normalized_new_url = '';
    if (!$validated_new_url) {
        $normalized_new_url = blc_normalize_link_url($clean_new_url, $site_url, $site_scheme, $permalink);
        if ($normalized_new_url !== '') {
            $validated_new_url = wp_http_validate_url($normalized_new_url);
        }
    } else {
        $normalized_new_url = $clean_new_url;
    }

    $normalized_parts = $normalized_old_url !== '' ? parse_url($normalized_old_url) : false;
    $validated_new_parts = $validated_new_url ? parse_url($validated_new_url) : false;
    if (
        !$normalized_old_url ||
        $normalized_parts === false ||
        empty($normalized_parts['scheme']) ||
        !in_array($normalized_parts['scheme'], $allowed_protocols, true) ||
        !$validated_new_url ||
        $validated_new_parts === false ||
        empty($validated_new_parts['scheme']) ||
        !in_array($validated_new_parts['scheme'], $allowed_protocols, true)
    ) {
        return new WP_Error('blc_invalid_url', __('URL invalide.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_BAD_REQUEST]);
    }

    $final_new_url = $clean_new_url;
    $is_explicit_new_url = (
        preg_match('#^https?://#i', $clean_new_url) === 1 ||
        strpos($clean_new_url, '//') === 0 ||
        strpos($clean_new_url, '/') === 0 ||
        strpos($clean_new_url, '#') === 0
    );

    if (!$is_explicit_new_url) {
        if (!$validated_new_url || $normalized_new_url === '') {
            return new WP_Error('blc_invalid_url', __('URL invalide.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_BAD_REQUEST]);
        }

        if ($looks_like_domain_input && $normalized_new_url !== '') {
            $final_new_url = $normalized_new_url;
        }
    }

    $old_url = $prepared_old_url;
    if (strpos($final_new_url, '//') === 0) {
        $scheme_for_scheme_relative = (is_string($site_scheme) && $site_scheme !== '') ? $site_scheme : 'http';
        $final_new_url = set_url_scheme($final_new_url, $scheme_for_scheme_relative);
    }

    $new_url = esc_url_raw($final_new_url);
    if ($new_url === '') {
        return new WP_Error('blc_invalid_url', __('URL invalide.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_BAD_REQUEST]);
    }

    if ($apply_globally) {
        $candidate_rows = blc_get_mass_update_candidate_rows($table_name, $stored_old_url, $row_id);

        $unique_rows = [];
        foreach ($candidate_rows as $candidate_row) {
            $candidate_id = isset($candidate_row['id']) ? (int) $candidate_row['id'] : 0;
            if ($candidate_id <= 0 || isset($unique_rows[$candidate_id])) {
                continue;
            }

            $unique_rows[$candidate_id] = $candidate_row;
        }

        $ordered_rows = [];
        if (isset($unique_rows[$row_id])) {
            $ordered_rows[] = $unique_rows[$row_id];
            unset($unique_rows[$row_id]);
        }

        foreach ($unique_rows as $unique_row) {
            $ordered_rows[] = $unique_row;
        }

        $mass_update_candidates = [];
        $preview_items = [];
        $editable_count = 0;
        $non_editable_count = 0;

        foreach ($ordered_rows as $candidate_row) {
            $target_row_id = isset($candidate_row['id']) ? (int) $candidate_row['id'] : 0;
            if ($target_row_id <= 0) {
                continue;
            }

            $target_post_id = isset($candidate_row['post_id']) ? (int) $candidate_row['post_id'] : 0;

            $candidate_post_title = '';
            if (!empty($candidate_row['current_post_title'])) {
                $candidate_post_title = (string) $candidate_row['current_post_title'];
            } elseif (!empty($candidate_row['post_title'])) {
                $candidate_post_title = (string) $candidate_row['post_title'];
            }

            $candidate_post_type = isset($candidate_row['post_type']) ? (string) $candidate_row['post_type'] : '';

            $can_edit = ($target_post_id > 0 && current_user_can('edit_post', $target_post_id));

            $permalink = '';
            if ($target_post_id > 0 && function_exists('get_permalink')) {
                $permalink_candidate = get_permalink($target_post_id);
                if (is_string($permalink_candidate)) {
                    $permalink = $permalink_candidate;
                }
            }

            $occurrence_value = $candidate_row['occurrence_index'] ?? null;
            $candidate_occurrence_index = null;
            if (is_numeric($occurrence_value)) {
                $candidate_occurrence_index = max(0, (int) $occurrence_value);
            }
            if ($target_row_id === $row_id && $occurrence_index !== null) {
                $candidate_occurrence_index = $occurrence_index;
            }

            $candidate_footprint = 0;
            if ($target_row_id === $row_id && $row_cache_footprint > 0) {
                $candidate_footprint = $row_cache_footprint;
            } else {
                $candidate_footprint = blc_calculate_row_storage_footprint_bytes(
                    isset($candidate_row['url']) ? (string) $candidate_row['url'] : '',
                    isset($candidate_row['anchor']) ? (string) $candidate_row['anchor'] : '',
                    $candidate_post_title,
                    isset($candidate_row['context_html']) ? (string) $candidate_row['context_html'] : '',
                    isset($candidate_row['context_excerpt']) ? (string) $candidate_row['context_excerpt'] : ''
                );
            }

            if ($can_edit) {
                $editable_count++;
            } else {
                $non_editable_count++;
            }

            $mass_update_candidates[] = [
                'rowId'           => $target_row_id,
                'postId'          => $target_post_id,
                'postTitle'       => $candidate_post_title,
                'postType'        => $candidate_post_type,
                'canEdit'         => $can_edit,
                'permalink'       => $permalink,
                'occurrenceIndex' => $candidate_occurrence_index,
                'footprint'       => $candidate_footprint,
            ];

            $preview_items[] = [
                'rowId'     => $target_row_id,
                'postId'    => $target_post_id,
                'postTitle' => $candidate_post_title,
                'postType'  => $candidate_post_type,
                'permalink' => $permalink,
                'canEdit'   => $can_edit,
            ];
        }

        $preview_payload = [
            'applyGlobally'    => true,
            'totalCount'       => count($mass_update_candidates),
            'editableCount'    => $editable_count,
            'nonEditableCount' => $non_editable_count,
            'items'            => $preview_items,
            'initiatorRowId'   => $row_id,
        ];

        if ($preview_only) {
            return [
                'previewOnly' => true,
                'massUpdate'  => $preview_payload,
                'rowRemoved'  => false,
                'row_removed' => false,
            ];
        }

        $successes = [];
        $failures = [];
        $row_removed = false;
        $footprint_adjustment = 0;

        foreach ($mass_update_candidates as $candidate) {
            if (!$candidate['canEdit']) {
                $failures[] = [
                    'rowId'     => $candidate['rowId'],
                    'postId'    => $candidate['postId'],
                    'postTitle' => $candidate['postTitle'],
                    'reason'    => __('Permissions insuffisantes.', 'liens-morts-detector-jlg'),
                ];
                continue;
            }

            $target_post_id = $candidate['postId'];
            if ($target_post_id <= 0) {
                $failures[] = [
                    'rowId'     => $candidate['rowId'],
                    'postId'    => $candidate['postId'],
                    'postTitle' => $candidate['postTitle'],
                    'reason'    => __('Le contenu est introuvable.', 'liens-morts-detector-jlg'),
                ];
                continue;
            }

            $target_post = get_post($target_post_id);
            if (!$target_post instanceof WP_Post) {
                $failures[] = [
                    'rowId'     => $candidate['rowId'],
                    'postId'    => $candidate['postId'],
                    'postTitle' => $candidate['postTitle'],
                    'reason'    => __('Le contenu est introuvable.', 'liens-morts-detector-jlg'),
                ];
                continue;
            }

            $normalized_content = blc_normalize_post_content_encoding($target_post->post_content);
            $replacement = blc_replace_link_href_in_content($normalized_content, $old_url, $new_url, $candidate['occurrenceIndex']);

            if (!is_array($replacement) || empty($replacement['updated'])) {
                $failures[] = [
                    'rowId'     => $candidate['rowId'],
                    'postId'    => $candidate['postId'],
                    'postTitle' => $candidate['postTitle'] !== '' ? $candidate['postTitle'] : get_the_title($target_post),
                    'reason'    => __('Impossible de localiser cette occurrence du lien. Le contenu a peut-être été modifié.', 'liens-morts-detector-jlg'),
                ];
                continue;
            }

            $new_content = blc_restore_post_content_encoding($replacement['content']);
            $update_result = wp_update_post(
                [
                    'ID'           => $target_post_id,
                    'post_content' => wp_slash($new_content),
                ],
                true
            );

            if (!$update_result || is_wp_error($update_result)) {
                $error_message = __('La mise à jour de l\'article a échoué.', 'liens-morts-detector-jlg');
                if (is_wp_error($update_result)) {
                    $error_message .= ' ' . $update_result->get_error_message();
                }

                $failures[] = [
                    'rowId'     => $candidate['rowId'],
                    'postId'    => $candidate['postId'],
                    'postTitle' => $candidate['postTitle'] !== '' ? $candidate['postTitle'] : get_the_title($target_post),
                    'reason'    => $error_message,
                ];
                continue;
            }

            $delete_result = $wpdb->delete(
                $table_name,
                ['id' => $candidate['rowId']],
                ['%d']
            );

            if ($delete_result === false) {
                $failures[] = [
                    'rowId'     => $candidate['rowId'],
                    'postId'    => $candidate['postId'],
                    'postTitle' => $candidate['postTitle'] !== '' ? $candidate['postTitle'] : get_the_title($target_post),
                    'reason'    => __('La suppression du lien dans la base de données a échoué.', 'liens-morts-detector-jlg'),
                ];
                continue;
            }

            if ($delete_result > 0 && $candidate['footprint'] > 0) {
                $footprint_adjustment += (int) $candidate['footprint'];
            }

            $row_removed = $row_removed || ($candidate['rowId'] === $row_id);

            $success_title = get_the_title($target_post_id);
            if (!is_string($success_title) || $success_title === '') {
                $success_title = $candidate['postTitle'];
            }

            $success_permalink = $candidate['permalink'];
            if ($success_permalink === '' && function_exists('get_permalink')) {
                $permalink_candidate = get_permalink($target_post_id);
                if (is_string($permalink_candidate)) {
                    $success_permalink = $permalink_candidate;
                }
            }

            $successes[] = [
                'rowId'     => $candidate['rowId'],
                'postId'    => $candidate['postId'],
                'postTitle' => $success_title,
                'permalink' => $success_permalink,
            ];
        }

        if ($footprint_adjustment > 0) {
            blc_adjust_dataset_storage_footprint('link', -$footprint_adjustment);
        }

        if (!empty($successes)) {
            blc_mark_link_view_counts_dirty();
        }

        $updated_count = count($successes);
        $failure_count = count($failures);

        if ($updated_count === 0 && $failure_count === 0) {
            $message = __('Aucune occurrence n’a été trouvée pour cette URL.', 'liens-morts-detector-jlg');
        } elseif ($updated_count === 0) {
            $message = __('Aucune occurrence n’a pu être mise à jour.', 'liens-morts-detector-jlg');
        } elseif ($failure_count > 0) {
            $message = sprintf(
                _n('%1$d contenu mis à jour, %2$d échec.', '%1$d contenus mis à jour, %2$d échecs.', $updated_count, 'liens-morts-detector-jlg'),
                $updated_count,
                $failure_count
            );
        } else {
            $message = sprintf(
                _n('%d contenu mis à jour.', '%d contenus mis à jour.', $updated_count, 'liens-morts-detector-jlg'),
                $updated_count
            );
        }

        $mass_update_summary = [
            'applyGlobally'    => true,
            'totalCount'       => count($mass_update_candidates),
            'updatedCount'     => $updated_count,
            'failureCount'     => $failure_count,
            'editableCount'    => $editable_count,
            'nonEditableCount' => $non_editable_count,
            'updatedPosts'     => $successes,
            'failures'         => $failures,
        ];

        $log_payload = [
            'old_url'         => $prepared_old_url,
            'new_url'         => $new_url,
            'initiator_row_id'=> $row_id,
            'initiator_post_id' => $post_id,
            'updated_posts'   => $successes,
            'failures'        => $failures,
            'total'           => count($mass_update_candidates),
            'updated_count'   => $updated_count,
            'failure_count'   => $failure_count,
            'user_id'         => get_current_user_id(),
            'timestamp'       => current_time('mysql'),
        ];

        $filtered_payload = apply_filters('blc_link_mass_update_performed', $log_payload, $mass_update_summary);
        if (is_array($filtered_payload)) {
            $log_payload = $filtered_payload;
        }

        return [
            'purged'      => false,
            'row_removed' => $row_removed,
            'rowRemoved'  => $row_removed,
            'previewOnly' => false,
            'message'     => $message,
            'announcement'=> $message,
            'massUpdate'  => $mass_update_summary,
        ];
    }

    $success_payload = [
        'purged'      => false,
        'row_removed' => true,
        'rowRemoved'  => true,
        'previewOnly' => false,
    ];

    if ($args['success_message'] !== '') {
        $success_payload['message'] = $args['success_message'];
        $success_payload['announcement'] = $args['success_message'];
    }

    if (!$post instanceof WP_Post) {
        if (!function_exists('blc_current_user_can_fix_links') || !blc_current_user_can_fix_links()) {
            return new WP_Error('blc_forbidden', __('Permissions insuffisantes.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_FORBIDDEN]);
        }

        $deleted = $wpdb->delete(
            $table_name,
            ['id' => $row_id],
            ['%d']
        );

        if ($deleted === false) {
            return new WP_Error('blc_db_error', __('La suppression du lien dans la base de données a échoué.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_SERVER_ERROR]);
        }

        if ($deleted > 0 && $row_cache_footprint > 0) {
            blc_adjust_dataset_storage_footprint('link', -$row_cache_footprint);
        }

        blc_mark_link_view_counts_dirty();

        $success_payload['purged'] = true;
        $success_payload['row_removed'] = true;
        $success_payload['rowRemoved'] = true;

        return $success_payload;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('blc_forbidden', __('Permissions insuffisantes.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_FORBIDDEN]);
    }

    $normalized_content = blc_normalize_post_content_encoding($post->post_content);
    $replacement = blc_replace_link_href_in_content($normalized_content, $old_url, $new_url, $occurrence_index);

    if (!is_array($replacement) || empty($replacement['updated'])) {
        return new WP_Error('blc_link_not_found', __('Impossible de localiser cette occurrence du lien. Le contenu a peut-être été modifié.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_CONFLICT]);
    }

    $new_content = blc_restore_post_content_encoding($replacement['content']);
    $update_result = wp_update_post(
        [
            'ID'           => $post_id,
            'post_content' => wp_slash($new_content),
        ],
        true
    );

    if (!$update_result || is_wp_error($update_result)) {
        $error_message = __('La mise à jour de l\'article a échoué.', 'liens-morts-detector-jlg');
        if (is_wp_error($update_result)) {
            $error_message .= ' ' . $update_result->get_error_message();
        }

        return new WP_Error('blc_post_update_failed', $error_message, ['status' => BLC_HTTP_SERVER_ERROR]);
    }

    $delete_result = $wpdb->delete(
        $table_name,
        ['id' => $row_id],
        ['%d']
    );

    if ($delete_result === false) {
        return new WP_Error('blc_db_error', __('La suppression du lien dans la base de données a échoué.', 'liens-morts-detector-jlg'), ['status' => BLC_HTTP_SERVER_ERROR]);
    }

    if ($delete_result > 0 && $row_cache_footprint > 0) {
        blc_adjust_dataset_storage_footprint('link', -$row_cache_footprint);
    }

    blc_mark_link_view_counts_dirty();

    $success_payload['row_removed'] = true;
    $success_payload['rowRemoved'] = true;

    return $success_payload;
}

/**
 * Retrieve candidate broken link rows for a mass update.
 *
 * @param string $table_name     Table storing broken link records.
 * @param string $stored_old_url Stored URL used as matching key.
 * @param int    $row_id         Initiator row identifier.
 *
 * @return array<int,array<string,mixed>>
 */
function blc_get_mass_update_candidate_rows($table_name, $stored_old_url, $row_id) {
    global $wpdb;

    $table_name = (string) $table_name;
    if ($table_name === '') {
        $table_name = $wpdb->prefix . 'blc_broken_links';
    }

    $row_id = absint($row_id);

    $posts_table = $wpdb->posts;

    $conditions = [];
    $params = ['link'];

    if ($stored_old_url !== '') {
        $conditions[] = 'links.url = %s';
        $params[] = $stored_old_url;
        $conditions[] = 'links.redirect_target_url = %s';
        $params[] = $stored_old_url;
    }

    $conditions[] = 'links.id = %d';
    $params[] = $row_id;

    $where_parts = array_map(
        static function ($clause) {
            return '(' . $clause . ')';
        },
        $conditions
    );

    $where_sql = implode(' OR ', $where_parts);

    $query = "
        SELECT
            links.id,
            links.post_id,
            links.url,
            links.anchor,
            links.redirect_target_url,
            links.context_html,
            links.context_excerpt,
            links.post_title,
            links.occurrence_index,
            posts.post_type,
            posts.post_title AS current_post_title
        FROM {$table_name} AS links
        LEFT JOIN {$posts_table} AS posts ON posts.ID = links.post_id
        WHERE links.type = %s
    ";

    if ($where_sql !== '') {
        $query .= " AND ({$where_sql})";
    }

    $query .= ' ORDER BY links.post_id ASC, links.id ASC';

    $prepared = $wpdb->prepare($query, $params);
    $results = $wpdb->get_results($prepared, ARRAY_A);

    if (!is_array($results)) {
        return [];
    }

    return $results;
}

/**
 * Retrieve a single broken link row with contextual data.
 *
 * @param int $row_id Identifier of the broken link row.
 * @return array|null
 */
function blc_get_broken_link_row($row_id) {
    global $wpdb;

    $row_id = absint($row_id);
    if ($row_id <= 0) {
        return null;
    }

    $table_name  = $wpdb->prefix . 'blc_broken_links';
    $posts_table = $wpdb->posts;

    $query = $wpdb->prepare(
        "SELECT links.id, links.occurrence_index, links.url, links.anchor, links.redirect_target_url, links.context_html, links.context_excerpt, links.post_id, links.post_title, links.http_status, links.last_checked_at, links.ignored_at, posts.post_type AS post_type FROM {$table_name} AS links LEFT JOIN {$posts_table} AS posts ON links.post_id = posts.ID WHERE links.id = %d AND links.type = %s",
        $row_id,
        'link'
    );

    $row = $wpdb->get_row($query, ARRAY_A);

    return is_array($row) ? $row : null;
}

/**
 * Render the HTML markup for a single broken link row.
 *
 * @param array $row Row data as returned by blc_get_broken_link_row().
 * @return string
 */
function blc_render_broken_link_row_html(array $row) {
    if (!class_exists('BLC_Links_List_Table')) {
        require_once BLC_PLUGIN_PATH . 'includes/class-blc-links-list-table.php';
    }

    $list_table = new BLC_Links_List_Table();

    return $list_table->render_row_html($row);
}

// Gère la modification d'une URL
add_action('wp_ajax_blc_edit_link', 'blc_ajax_edit_link_callback');
function blc_ajax_edit_link_callback() {
    if (!function_exists('blc_current_user_can_fix_links') || !blc_current_user_can_fix_links()) {
        wp_send_json_error([
            'message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_FORBIDDEN);
    }

    check_ajax_referer('blc_edit_link_nonce');

    $params = blc_require_post_params(['post_id', 'row_id', 'old_url', 'new_url']);

    $post_id = absint($params['post_id']);
    $row_id  = absint($params['row_id']);

    $occurrence_input = null;
    if (isset($_POST['occurrence_index'])) {
        $occurrence_input = wp_unslash($_POST['occurrence_index']);
    }

    $resolution = blc_resolve_link_row($post_id, $row_id, $occurrence_input);
    $row = $resolution['row'];
    $occurrence_index = $resolution['occurrence_index'];
    $table_name = $resolution['table'];
    $row_to_delete = $resolution['cache_row'];
    $row_cache_footprint = $resolution['cache_footprint'];

    $apply_globally = !empty($_POST['apply_globally']);
    $preview_only   = !empty($_POST['preview_only']);

    $result = blc_perform_link_update([
        'post_id'             => $post_id,
        'row_id'              => $row_id,
        'row'                 => $row,
        'occurrence_index'    => $occurrence_index,
        'table_name'          => $table_name,
        'row_cache'           => $row_to_delete,
        'row_cache_footprint' => $row_cache_footprint,
        'old_url'             => $params['old_url'],
        'new_url'             => $params['new_url'],
        'apply_globally'      => $apply_globally,
        'preview_only'        => $preview_only,
    ]);

    if (is_wp_error($result)) {
        $status = (int) ($result->get_error_data()['status'] ?? BLC_HTTP_BAD_REQUEST);
        wp_send_json_error(['message' => $result->get_error_message()], $status);
    }

    wp_send_json_success($result);
}

add_action('wp_ajax_blc_apply_detected_redirect', 'blc_ajax_apply_detected_redirect_callback');
function blc_ajax_apply_detected_redirect_callback() {
    if (!function_exists('blc_current_user_can_fix_links') || !blc_current_user_can_fix_links()) {
        wp_send_json_error([
            'message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_FORBIDDEN);
    }

    check_ajax_referer('blc_apply_detected_redirect_nonce');

    $params = blc_require_post_params(['post_id', 'row_id']);

    $post_id = absint($params['post_id']);
    $row_id  = absint($params['row_id']);

    $occurrence_input = null;
    if (isset($_POST['occurrence_index'])) {
        $occurrence_input = wp_unslash($_POST['occurrence_index']);
    }

    $resolution = blc_resolve_link_row($post_id, $row_id, $occurrence_input);
    $row = $resolution['row'];
    $occurrence_index = $resolution['occurrence_index'];
    $table_name = $resolution['table'];
    $row_to_delete = $resolution['cache_row'];
    $row_cache_footprint = $resolution['cache_footprint'];

    $stored_old_url = isset($row['url']) ? (string) $row['url'] : '';
    $detected_target = isset($row['redirect_target_url']) ? trim((string) $row['redirect_target_url']) : '';

    if ($detected_target === '') {
        wp_send_json_error([
            'message' => __('Aucune redirection détectée n\'est disponible pour ce lien.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_BAD_REQUEST);
    }

    $result = blc_perform_link_update([
        'post_id'             => $post_id,
        'row_id'              => $row_id,
        'row'                 => $row,
        'occurrence_index'    => $occurrence_index,
        'table_name'          => $table_name,
        'row_cache'           => $row_to_delete,
        'row_cache_footprint' => $row_cache_footprint,
        'old_url'             => $stored_old_url,
        'new_url'             => $detected_target,
        'success_message'     => __('La redirection détectée a été appliquée.', 'liens-morts-detector-jlg'),
    ]);

    if (is_wp_error($result)) {
        $status = (int) ($result->get_error_data()['status'] ?? BLC_HTTP_BAD_REQUEST);
        wp_send_json_error(['message' => $result->get_error_message()], $status);
    }

    $response = [
        'message'      => $result['message'] ?? __('La redirection détectée a été appliquée.', 'liens-morts-detector-jlg'),
        'announcement' => $result['announcement'] ?? ($result['message'] ?? ''),
        'rowRemoved'   => !empty($result['row_removed']),
    ];

    if (empty($result['purged']) && empty($result['row_removed'])) {
        $refreshed_row = blc_get_broken_link_row($row_id);
        if (is_array($refreshed_row)) {
            $response['rowHtml'] = blc_render_broken_link_row_html($refreshed_row);
        }
    }

    if (!empty($result['purged'])) {
        $response['purged'] = true;
    }

    wp_send_json_success($response);
}

// Gère la dissociation d'un lien
add_action('wp_ajax_blc_unlink', 'blc_ajax_unlink_callback');
function blc_ajax_unlink_callback() {
    if (!function_exists('blc_current_user_can_fix_links') || !blc_current_user_can_fix_links()) {
        wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
    }

    check_ajax_referer('blc_unlink_nonce');

    $params = blc_require_post_params(['post_id', 'row_id', 'url_to_unlink']);

    $post_id = absint($params['post_id']);
    $row_id  = absint($params['row_id']);

    $occurrence_input = null;
    if (isset($_POST['occurrence_index'])) {
        $occurrence_input = wp_unslash($_POST['occurrence_index']);
    }

    $resolution = blc_resolve_link_row($post_id, $row_id, $occurrence_input);
    $row = $resolution['row'];
    $occurrence_index = $resolution['occurrence_index'];
    $table_name = $resolution['table'];
    $row_to_delete = $resolution['cache_row'];
    $row_cache_footprint = $resolution['cache_footprint'];

    global $wpdb;

    $raw_home_url = home_url();
    $site_url = trailingslashit($raw_home_url);
    $site_scheme = parse_url($raw_home_url, PHP_URL_SCHEME);
    if (!is_string($site_scheme) || $site_scheme === '') {
        $site_scheme = 'http';
    }

    $prepared_url_to_unlink = blc_prepare_posted_url($params['url_to_unlink']);
    if ($prepared_url_to_unlink === '') {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $sanitized_url_to_unlink = esc_url_raw($prepared_url_to_unlink);
    if ($sanitized_url_to_unlink === '') {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $stored_url_to_unlink = blc_prepare_url_for_storage($prepared_url_to_unlink);
    if (!is_string($row['url']) || $row['url'] !== $stored_url_to_unlink) {
        wp_send_json_error([
            'message' => __('Le lien sélectionné est introuvable. Veuillez relancer une analyse.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_CONFLICT);
    }

    $validated_url = wp_http_validate_url($sanitized_url_to_unlink);
    $normalized_url = $validated_url ?: blc_normalize_link_url($prepared_url_to_unlink, $site_url, $site_scheme);

    $normalized_parts = $normalized_url !== '' ? parse_url($normalized_url) : false;
    if (!$normalized_url || $normalized_parts === false || empty($normalized_parts['scheme']) || !in_array($normalized_parts['scheme'], ['http', 'https'], true)) {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $post = get_post($post_id);
    if (!$post) {
        if (!function_exists('blc_current_user_can_fix_links') || !blc_current_user_can_fix_links()) {
            wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
        }

        $deleted = $wpdb->delete(
            $table_name,
            ['id' => $row_id],
            ['%d']
        );

        if ($deleted && is_array($row_to_delete) && $row_cache_footprint > 0) {
            blc_adjust_dataset_storage_footprint('link', -$row_cache_footprint);
        }

        blc_mark_link_view_counts_dirty();
        wp_send_json_success(['purged' => true]);
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
    }

    $normalized_content = blc_normalize_post_content_encoding($post->post_content);
    $removal = blc_remove_link_wrappers_from_content($normalized_content, $prepared_url_to_unlink, $occurrence_index);

    if (!$removal['removed']) {
        wp_send_json_error([
            'message' => __('Impossible de localiser cette occurrence du lien. Le contenu a peut-être été modifié.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_CONFLICT);
    }

    $new_content = blc_restore_post_content_encoding($removal['content']);
    $update_result = wp_update_post([
        'ID' => $post_id,
        'post_content' => wp_slash($new_content),
    ], true);

    if (!$update_result || is_wp_error($update_result)) {
        $error_message = __('La mise à jour de l\'article a échoué.', 'liens-morts-detector-jlg');
        if (is_wp_error($update_result)) {
            $error_message .= ' ' . $update_result->get_error_message();
        }

        wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
        return;
    }

    $delete_result = $wpdb->delete(
        $table_name,
        ['id' => $row_id],
        ['%d']
    );

    if ($delete_result === false || is_wp_error($delete_result)) {
        $error_message = __('La suppression du lien dans la base de données a échoué.', 'liens-morts-detector-jlg');
        if (is_wp_error($delete_result)) {
            $error_message .= ' ' . $delete_result->get_error_message();
        }

        wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
        return;
    }

    if ($delete_result > 0 && is_array($row_to_delete) && $row_cache_footprint > 0) {
        blc_adjust_dataset_storage_footprint('link', -$row_cache_footprint);
    }

    blc_mark_link_view_counts_dirty();
    wp_send_json_success();
}

add_action('wp_ajax_blc_ignore_link', 'blc_ajax_ignore_link_callback');
function blc_ajax_ignore_link_callback() {
    if (!function_exists('blc_current_user_can_fix_links') || !blc_current_user_can_fix_links()) {
        wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
    }

    check_ajax_referer('blc_ignore_link_nonce');

    $params = blc_require_post_params(['post_id', 'row_id', 'mode']);

    $post_id = absint($params['post_id']);
    $row_id  = absint($params['row_id']);

    $mode_raw = strtolower($params['mode']);
    if ($mode_raw === 'unignore') {
        $mode_raw = 'restore';
    }

    if (!in_array($mode_raw, ['ignore', 'restore'], true)) {
        wp_send_json_error([
            'message' => __('Action ignorée invalide.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_BAD_REQUEST);
    }

    $occurrence_input = null;
    if (isset($_POST['occurrence_index'])) {
        $occurrence_input = wp_unslash($_POST['occurrence_index']);
    }

    $resolution = blc_resolve_link_row($post_id, $row_id, $occurrence_input);
    $row = $resolution['row'];
    $table_name = $resolution['table'];
    $row_cache_footprint = isset($resolution['cache_footprint']) ? (int) $resolution['cache_footprint'] : 0;

    $ignored_raw = $row['ignored_at'] ?? null;
    $is_currently_ignored = false;
    if (is_string($ignored_raw)) {
        $normalized_ignored = trim($ignored_raw);
        $is_currently_ignored = ($normalized_ignored !== '' && $normalized_ignored !== '0000-00-00 00:00:00');
    } elseif ($ignored_raw !== null) {
        $is_currently_ignored = true;
    }

    global $wpdb;

    $post = get_post($post_id);
    if ($post) {
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error([
                'message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg'),
            ], BLC_HTTP_FORBIDDEN);
        }
    } else {
        if (!function_exists('blc_current_user_can_fix_links') || !blc_current_user_can_fix_links()) {
            wp_send_json_error([
                'message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg'),
            ], BLC_HTTP_FORBIDDEN);
        }
    }

    $mode = $mode_raw;
    $announcement = '';
    $changed = false;

    if ($mode === 'ignore') {
        if ($is_currently_ignored) {
            $announcement = __('Le lien est déjà ignoré.', 'liens-morts-detector-jlg');
        } else {
            $timestamp = current_time('mysql', true);
            $updated = $wpdb->query(
                $wpdb->prepare("UPDATE $table_name SET ignored_at = %s WHERE id = %d", $timestamp, $row_id)
            );

            if ($updated === false) {
                $error_message = __('La mise à jour du statut ignoré a échoué.', 'liens-morts-detector-jlg');
                if (!empty($wpdb->last_error)) {
                    $error_message .= ' ' . $wpdb->last_error;
                }

                wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
            }

            if ((int) $updated > 0) {
                $changed = true;
                if ($row_cache_footprint > 0) {
                    blc_adjust_dataset_storage_footprint('link', -$row_cache_footprint);
                }
            }

            $announcement = __('Le lien est désormais ignoré.', 'liens-morts-detector-jlg');
            $is_currently_ignored = true;
        }
    } else { // restore
        if (!$is_currently_ignored) {
            $announcement = __('Le lien n\'était pas ignoré.', 'liens-morts-detector-jlg');
        } else {
            $updated = $wpdb->query(
                $wpdb->prepare("UPDATE $table_name SET ignored_at = NULL WHERE id = %d", $row_id)
            );

            if ($updated === false) {
                $error_message = __('La réactivation du lien a échoué.', 'liens-morts-detector-jlg');
                if (!empty($wpdb->last_error)) {
                    $error_message .= ' ' . $wpdb->last_error;
                }

                wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
            }

            if ((int) $updated > 0) {
                $changed = true;
                if ($row_cache_footprint > 0) {
                    blc_adjust_dataset_storage_footprint('link', $row_cache_footprint);
                }
            }

            $announcement = __('Le lien n\'est plus ignoré.', 'liens-morts-detector-jlg');
            $is_currently_ignored = false;
        }
    }

    if ($changed) {
        blc_mark_link_view_counts_dirty();
    }

    wp_send_json_success([
        'purged'       => true,
        'announcement' => $announcement,
        'ignored'      => $is_currently_ignored,
    ]);
}

add_action('wp_ajax_blc_recheck_link', 'blc_ajax_recheck_link_callback');
function blc_ajax_recheck_link_callback() {
    if (!function_exists('blc_current_user_can_fix_links') || !blc_current_user_can_fix_links()) {
        wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
    }

    check_ajax_referer('blc_recheck_link_nonce');

    $params = blc_require_post_params(['post_id', 'row_id']);

    $post_id = absint($params['post_id']);
    $row_id  = absint($params['row_id']);

    $occurrence_input = null;
    if (isset($_POST['occurrence_index'])) {
        $occurrence_input = wp_unslash($_POST['occurrence_index']);
    }

    $resolution = blc_resolve_link_row($post_id, $row_id, $occurrence_input);
    $row        = $resolution['row'];
    $table_name = $resolution['table'];

    global $wpdb;

    $post = get_post($post_id);
    if ($post) {
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
        }
    } else {
        if (!function_exists('blc_current_user_can_fix_links') || !blc_current_user_can_fix_links()) {
            wp_send_json_error(['message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')], BLC_HTTP_FORBIDDEN);
        }
    }

    $stored_url = isset($row['url']) ? (string) $row['url'] : '';
    if ($stored_url === '') {
        wp_send_json_error([
            'message' => __('Le lien sélectionné est introuvable. Veuillez relancer une analyse.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_CONFLICT);
    }

    $normalized_url = $stored_url;

    $timeout_constraints = blc_get_request_timeout_constraints();
    $head_limits         = $timeout_constraints['head'];
    $get_limits          = $timeout_constraints['get'];

    $head_request_timeout = blc_normalize_timeout_option(
        get_option('blc_head_request_timeout', $head_limits['default']),
        $head_limits['default'],
        $head_limits['min'],
        $head_limits['max']
    );

    $get_request_timeout = blc_normalize_timeout_option(
        get_option('blc_get_request_timeout', $get_limits['default']),
        $get_limits['default'],
        $get_limits['min'],
        $get_limits['max']
    );

    $proxy_pool = blc_get_proxy_pool_instance();
    $remote_request_client = new \JLG\BrokenLinks\Scanner\RemoteRequestClient([], [], [], $proxy_pool instanceof \JLG\BrokenLinks\Scanner\ProxyPool ? $proxy_pool : null);

    if ($proxy_pool instanceof \JLG\BrokenLinks\Scanner\ProxyPool) {
        $remote_request_client->setProxyPool($proxy_pool);
    }

    $head_args = [
        'user-agent'          => blc_get_http_user_agent(),
        'timeout'             => $head_request_timeout,
        'limit_response_size' => 1024,
        'redirection'         => 5,
    ];

    $get_args = [
        'timeout'             => $get_request_timeout,
        'user-agent'          => blc_get_http_user_agent(),
        'method'              => 'GET',
        'limit_response_size' => 131072,
    ];

    $response = $remote_request_client->head($normalized_url, $head_args);
    $response_code = null;
    $needs_get     = false;

    if (is_wp_error($response)) {
        $needs_get = true;
    } else {
        $response_code = (int) $remote_request_client->responseCode($response);
        if (in_array($response_code, [403, 405, 501], true)) {
            $needs_get = true;
        }
    }

    if ($needs_get) {
        $response = $remote_request_client->get($normalized_url, $get_args);
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            if ($error_message === '') {
                $error_message = __('La re-vérification du lien a échoué.', 'liens-morts-detector-jlg');
            }

            wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
        }

        $response_code = (int) $remote_request_client->responseCode($response);
    }

    $detected_target         = blc_determine_response_target_url($response, $normalized_url);
    $detected_target_storage = blc_prepare_url_for_storage($detected_target);
    if ($detected_target_storage === '') {
        $detected_target_storage = null;
    }

    $timestamp = current_time('mysql', true);

    $set_clauses = ['last_checked_at = %s'];
    $values      = [$timestamp];

    if ($detected_target_storage === null) {
        $set_clauses[] = 'redirect_target_url = NULL';
    } else {
        $set_clauses[] = 'redirect_target_url = %s';
        $values[]      = $detected_target_storage;
    }

    if ($response_code === null) {
        $set_clauses[] = 'http_status = NULL';
    } else {
        $set_clauses[] = 'http_status = %d';
        $values[]      = (int) $response_code;
    }

    $values[] = $row_id;

    $update_sql = $wpdb->prepare(
        "UPDATE $table_name SET " . implode(', ', $set_clauses) . " WHERE id = %d",
        $values
    );

    if ($update_sql === false) {
        wp_send_json_error([
            'message' => __('La mise à jour du contrôle a échoué.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_SERVER_ERROR);
    }

    $updated = $wpdb->query($update_sql);
    if ($updated === false) {
        $error_message = __('La mise à jour du contrôle a échoué.', 'liens-morts-detector-jlg');
        if (!empty($wpdb->last_error)) {
            $error_message .= ' ' . $wpdb->last_error;
        }

        wp_send_json_error(['message' => $error_message], BLC_HTTP_SERVER_ERROR);
    }

    blc_mark_link_view_counts_dirty();

    $success_message = __('Le lien a été re-vérifié.', 'liens-morts-detector-jlg');

    wp_send_json_success([
        'message' => $success_message,
        'http_status' => $response_code,
        'redirect_target_url' => $detected_target_storage,
    ]);
}

add_action('wp_ajax_blc_send_test_email', 'blc_ajax_send_test_email');
function blc_ajax_send_test_email() {
    $can_manage_settings = function_exists('blc_current_user_can_manage_settings')
        ? blc_current_user_can_manage_settings()
        : current_user_can('manage_options');

    if (!$can_manage_settings) {
        wp_send_json_error(
            array('message' => __('Permissions insuffisantes.', 'liens-morts-detector-jlg')),
            BLC_HTTP_FORBIDDEN
        );
    }

    check_ajax_referer('blc_send_test_email');

    $raw_recipients = isset($_POST['recipients']) ? wp_unslash($_POST['recipients']) : '';
    $recipients     = blc_parse_notification_recipients($raw_recipients);

    $dataset_input = array();
    if (isset($_POST['dataset_types'])) {
        $dataset_input = wp_unslash($_POST['dataset_types']);
    }

    if (!is_array($dataset_input)) {
        $dataset_input = $dataset_input === '' ? array() : array($dataset_input);
    }

    $allowed_types = array('link', 'links', 'image', 'images');
    $dataset_types = array();
    foreach ($dataset_input as $candidate) {
        if (!is_scalar($candidate)) {
            continue;
        }

        $normalized = strtolower(trim((string) $candidate));
        if ($normalized === '') {
            continue;
        }

        if (!in_array($normalized, $allowed_types, true)) {
            continue;
        }

        if ($normalized === 'links') {
            $normalized = 'link';
        } elseif ($normalized === 'images') {
            $normalized = 'image';
        }

        $dataset_types[$normalized] = $normalized;
    }

    if ($dataset_types === array()) {
        wp_send_json_error(
            array('message' => __('Sélectionnez au moins un type de résumé à tester.', 'liens-morts-detector-jlg')),
            BLC_HTTP_BAD_REQUEST
        );
    }

    $webhook_overrides = array();
    if (isset($_POST['webhook_url'])) {
        $webhook_overrides['url'] = wp_unslash($_POST['webhook_url']);
    }
    if (isset($_POST['webhook_channel'])) {
        $webhook_overrides['channel'] = wp_unslash($_POST['webhook_channel']);
    }
    if (isset($_POST['message_template'])) {
        $webhook_overrides['message_template'] = wp_unslash($_POST['message_template']);
    }

    $status_filters_override = null;
    if (isset($_POST['status_filters'])) {
        $raw_filters = wp_unslash($_POST['status_filters']);
        if (!is_array($raw_filters)) {
            $raw_filters = ($raw_filters === '') ? array() : array($raw_filters);
        }
        $status_filters_override = blc_normalize_notification_status_filters($raw_filters);
    }

    $webhook_settings = blc_get_notification_webhook_settings($webhook_overrides);
    $has_webhook = blc_is_webhook_notification_configured($webhook_settings);

    if ($recipients === array() && !$has_webhook) {
        wp_send_json_error(
            array('message' => __('Ajoutez un destinataire ou configurez un webhook avant d’envoyer un test.', 'liens-morts-detector-jlg')),
            BLC_HTTP_BAD_REQUEST
        );
    }

    $results = array();
    $sent_types = array();
    $failed_types = array();

    $dataset_labels = array(
        'link'  => __('Analyse des liens', 'liens-morts-detector-jlg'),
        'image' => __('Analyse des images', 'liens-morts-detector-jlg'),
    );

    $channel_labels = array(
        'email'   => __('e-mail', 'liens-morts-detector-jlg'),
        'webhook' => __('webhook', 'liens-morts-detector-jlg'),
    );

    $status_labels = array(
        'sent'    => __('envoyé', 'liens-morts-detector-jlg'),
        'failed'  => __('échec', 'liens-morts-detector-jlg'),
        'skipped' => __('ignoré', 'liens-morts-detector-jlg'),
        'throttled' => __('en attente (anti-doublon)', 'liens-morts-detector-jlg'),
    );

    $format_channel_statuses = static function (array $channel_results) use ($channel_labels, $status_labels) {
        $parts = array();
        foreach ($channel_results as $channel => $details) {
            $label = isset($channel_labels[$channel]) ? $channel_labels[$channel] : $channel;
            $status_key = isset($details['status']) ? (string) $details['status'] : 'skipped';
            $status = isset($status_labels[$status_key]) ? $status_labels[$status_key] : $status_key;
            $parts[] = sprintf('%s (%s)', $label, $status);
        }

        return implode(', ', $parts);
    };

    foreach ($dataset_types as $dataset_type) {
        $summary_args = array();
        if ($status_filters_override !== null) {
            $summary_args['status_filters'] = $status_filters_override;
        }

        $summary = blc_generate_scan_summary_email($dataset_type, $summary_args);
        if ($summary === null) {
            $results[$dataset_type] = array(
                'email'   => array('status' => $recipients === array() ? 'skipped' : 'failed'),
                'webhook' => array('status' => $has_webhook ? 'failed' : 'skipped'),
            );
            $failed_types[] = $dataset_type;
            continue;
        }

        $dispatch_results = blc_dispatch_scan_summary_notifications(
            $dataset_type,
            $summary,
            $recipients,
            array(
                'context'          => 'test',
                'webhook_settings' => $webhook_settings,
            )
        );

        $results[$dataset_type] = $dispatch_results;

        $has_success = false;
        foreach ($dispatch_results as $channel_result) {
            if (isset($channel_result['status']) && $channel_result['status'] === 'sent') {
                $has_success = true;
                break;
            }
        }

        if ($has_success) {
            $sent_types[] = $dataset_type;
        } else {
            $failed_types[] = $dataset_type;
        }
    }

    if ($sent_types === array()) {
        wp_send_json_error(
            array('message' => __('Échec de l’envoi de la notification de test. Veuillez vérifier vos réglages.', 'liens-morts-detector-jlg')),
            BLC_HTTP_SERVER_ERROR
        );
    }

    $message_sections = array();
    foreach ($results as $dataset_type => $channel_results) {
        $dataset_label = isset($dataset_labels[$dataset_type]) ? $dataset_labels[$dataset_type] : ucfirst($dataset_type);
        $message_sections[] = sprintf('%s : %s', $dataset_label, $format_channel_statuses($channel_results));
    }

    $partial = ($failed_types !== array());
    if ($partial) {
        $message = sprintf(
            __('Notifications de test envoyées avec des avertissements : %s', 'liens-morts-detector-jlg'),
            implode(' — ', $message_sections)
        );
    } else {
        $message = sprintf(
            __('Notifications de test envoyées avec succès : %s', 'liens-morts-detector-jlg'),
            implode(' — ', $message_sections)
        );
    }

    wp_send_json_success(
        array(
            'message' => $message,
            'sent'    => $sent_types,
            'failed'  => $failed_types,
            'partial' => $partial,
            'results' => $results,
        )
    );
}
