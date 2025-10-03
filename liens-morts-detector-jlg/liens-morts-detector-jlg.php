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
require_once BLC_PLUGIN_PATH . 'includes/blc-activation.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-cron.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-scanner.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-utils.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-settings-fields.php';
require_once BLC_PLUGIN_PATH . 'includes/blc-admin-pages.php';
require_once BLC_PLUGIN_PATH . 'includes/class-blc-links-list-table.php';
require_once BLC_PLUGIN_PATH . 'includes/class-blc-images-list-table.php';

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

// Ajoute nos planifications personnalisées (hebdomadaire, mensuelle) à WP-Cron
add_filter('cron_schedules', 'blc_add_cron_schedules');

// Lie nos fonctions de scan aux tâches planifiées
add_action('blc_check_links', 'blc_perform_check');
add_action('blc_check_batch', 'blc_perform_check', 10, 3);
add_action('blc_manual_check_batch', 'blc_perform_check', 10, 3);
add_action('blc_check_image_batch', 'blc_perform_image_check', 10, 2);

// Ajoute nos fichiers CSS et JS dans l'administration
add_action('admin_enqueue_scripts', 'blc_enqueue_admin_assets');

/**
 * Charge les fichiers CSS et JavaScript sur les pages d'administration du plugin.
 *
 * @param string $hook Le nom de la page d'administration actuelle.
 */
function blc_enqueue_admin_assets($hook) {
    // On s'assure de ne charger les scripts que sur nos pages pour ne pas alourdir le reste de l'admin.
    if (strpos($hook, 'blc-dashboard') === false && strpos($hook, 'blc-images-dashboard') === false && strpos($hook, 'blc-settings') === false) {
        return;
    }
    
    // Chargement du fichier CSS
    $css_path    = __DIR__ . '/assets/css/blc-admin-styles.css';
    $css_version = file_exists($css_path) ? filemtime($css_path) : time();

    wp_enqueue_style(
        'blc-admin-css',
        plugin_dir_url(__FILE__) . 'assets/css/blc-admin-styles.css',
        array(),
        $css_version
    );

    // Chargement du fichier JavaScript
    $js_path    = __DIR__ . '/assets/js/blc-admin-scripts.js';
    $js_version = file_exists($js_path) ? filemtime($js_path) : time();

    wp_enqueue_script(
        'blc-admin-js',
        plugin_dir_url(__FILE__) . 'assets/js/blc-admin-scripts.js',
        array('jquery', 'wp-util'),
        $js_version,
        true // Charger dans le pied de page pour de meilleures performances
    );

    wp_localize_script(
        'blc-admin-js',
        'blcAdminMessages',
        array(
            /* translators: %s: original URL displayed in the edit prompt. */
            'editPromptMessage'  => __("Entrez la nouvelle URL pour :\n%s", 'liens-morts-detector-jlg'),
            'editPromptDefault'  => __('https://', 'liens-morts-detector-jlg'),
            'unlinkConfirmation' => __('Êtes-vous sûr de vouloir supprimer ce lien ? Le texte sera conservé.', 'liens-morts-detector-jlg'),
            'errorPrefix'        => __('Erreur : ', 'liens-morts-detector-jlg'),
            'editModalTitle'     => __('Modifier le lien', 'liens-morts-detector-jlg'),
            'editModalLabel'     => __('Nouvelle URL', 'liens-morts-detector-jlg'),
            'editModalConfirm'   => __('Mettre à jour', 'liens-morts-detector-jlg'),
            'unlinkModalTitle'   => __('Supprimer le lien', 'liens-morts-detector-jlg'),
            'unlinkModalConfirm' => __('Supprimer', 'liens-morts-detector-jlg'),
            'cancelButton'       => __('Annuler', 'liens-morts-detector-jlg'),
            'closeLabel'         => __('Fermer la fenêtre modale', 'liens-morts-detector-jlg'),
            'emptyUrlMessage'    => __('Veuillez saisir une URL.', 'liens-morts-detector-jlg'),
            'invalidUrlMessage'  => __('Veuillez saisir une URL valide.', 'liens-morts-detector-jlg'),
            'sameUrlMessage'     => __('La nouvelle URL doit être différente de l\'URL actuelle.', 'liens-morts-detector-jlg'),
            'genericError'        => __('Une erreur est survenue. Veuillez réessayer.', 'liens-morts-detector-jlg'),
            'successAnnouncement' => __('Action effectuée avec succès. La ligne a été retirée de la liste.', 'liens-morts-detector-jlg'),
            'noItemsMessage'      => __('Aucun lien cassé à afficher.', 'liens-morts-detector-jlg'),
            'ignoreModalTitle'    => __('Ignorer le lien', 'liens-morts-detector-jlg'),
            /* translators: %s: URL that will be ignored. */
            'ignoreModalMessage'  => __('Voulez-vous ignorer ce lien ? Il ne sera plus signalé.\n%s', 'liens-morts-detector-jlg'),
            'ignoreModalConfirm'  => __('Ignorer', 'liens-morts-detector-jlg'),
            'restoreModalTitle'   => __('Ne plus ignorer', 'liens-morts-detector-jlg'),
            /* translators: %s: URL that will be restored. */
            'restoreModalMessage' => __('Voulez-vous réintégrer ce lien dans la liste ?\n%s', 'liens-morts-detector-jlg'),
            'restoreModalConfirm' => __('Réintégrer', 'liens-morts-detector-jlg'),
            'ignoredAnnouncement' => __('Le lien est désormais ignoré.', 'liens-morts-detector-jlg'),
            'restoredAnnouncement' => __('Le lien n\'est plus ignoré.', 'liens-morts-detector-jlg'),
            /* translators: %s: number of selected links. */
            'bulkIgnoreModalMessage'   => __('Voulez-vous ignorer les %s liens sélectionnés ?', 'liens-morts-detector-jlg'),
            /* translators: %s: number of selected links. */
            'bulkRestoreModalMessage'  => __('Voulez-vous réintégrer les %s liens sélectionnés ?', 'liens-morts-detector-jlg'),
            /* translators: %s: number of selected links. */
            'bulkUnlinkModalMessage'   => __('Voulez-vous dissocier les %s liens sélectionnés ?', 'liens-morts-detector-jlg'),
            /* translators: %s: number of selected items. */
            'bulkGenericModalMessage'  => __('Voulez-vous appliquer cette action aux %s éléments sélectionnés ?', 'liens-morts-detector-jlg'),
            'bulkNoSelectionMessage'   => __('Veuillez sélectionner au moins un lien avant d\'appliquer une action groupée.', 'liens-morts-detector-jlg'),
            'bulkSuccessAnnouncement'  => __('Les actions groupées ont été appliquées avec succès.', 'liens-morts-detector-jlg'),
        )
    );

    $rest_url = function_exists('rest_url') ? rest_url('blc/v1/scan-status') : '';
    $scan_status = blc_get_link_scan_status_payload();
    $poll_interval = apply_filters('blc_scan_status_poll_interval', 10000);
    if (!is_int($poll_interval)) {
        $poll_interval = 10000;
    }

    wp_localize_script(
        'blc-admin-js',
        'blcAdminScanConfig',
        array(
            'restUrl'         => $rest_url ? esc_url_raw($rest_url) : '',
            'restNonce'       => wp_create_nonce('wp_rest'),
            'startScanNonce'  => wp_create_nonce('blc_start_manual_scan'),
            'cancelScanNonce' => wp_create_nonce('blc_cancel_manual_scan'),
            'getStatusNonce'  => wp_create_nonce('blc_get_scan_status'),
            'pollInterval'    => max(2000, (int) $poll_interval),
            'status'          => $scan_status,
            'i18n'            => array(
                'panelTitle'        => __('Statut du scan manuel', 'liens-morts-detector-jlg'),
                'states'            => array(
                    'idle'      => __('Inactif', 'liens-morts-detector-jlg'),
                    'queued'    => __('En file d\'attente', 'liens-morts-detector-jlg'),
                    'running'   => __('Analyse en cours', 'liens-morts-detector-jlg'),
                    'completed' => __('Terminée', 'liens-morts-detector-jlg'),
                    'failed'    => __('Échec', 'liens-morts-detector-jlg'),
                    'cancelled' => __('Annulée', 'liens-morts-detector-jlg'),
                ),
                'batchSummary'     => __('Lot %1$d sur %2$d', 'liens-morts-detector-jlg'),
                'remainingBatches' => __('Lots restants : %d', 'liens-morts-detector-jlg'),
                'nextBatch'        => __('Prochain lot prévu à %s', 'liens-morts-detector-jlg'),
                'queueMessage'     => __('Analyse programmée. Le premier lot démarrera sous peu.', 'liens-morts-detector-jlg'),
                'startError'       => __('Impossible de lancer l\'analyse. Veuillez réessayer.', 'liens-morts-detector-jlg'),
                'cancelSuccess'    => __('Les lots planifiés ont été annulés.', 'liens-morts-detector-jlg'),
                'cancelError'      => __('Impossible d\'annuler l\'analyse. Veuillez réessayer.', 'liens-morts-detector-jlg'),
                'cancelConfirm'    => __('Voulez-vous annuler les lots planifiés ?', 'liens-morts-detector-jlg'),
                'restartConfirm'   => __('Voulez-vous reprogrammer immédiatement un nouveau scan ?', 'liens-morts-detector-jlg'),
                'unknownState'     => __('Statut inconnu', 'liens-morts-detector-jlg'),
            ),
        )
    );

    wp_localize_script(
        'blc-admin-js',
        'blcAdminNotifications',
        array(
            'action'                 => 'blc_send_test_email',
            'nonce'                  => wp_create_nonce('blc_send_test_email'),
            'ajaxUrl'                => admin_url('admin-ajax.php'),
            'sendingText'            => __('Envoi du message de test…', 'liens-morts-detector-jlg'),
            'successText'            => __('Notifications de test envoyées avec succès.', 'liens-morts-detector-jlg'),
            'partialSuccessText'     => __('Notifications de test envoyées avec des avertissements.', 'liens-morts-detector-jlg'),
            'errorText'              => __('Échec de l’envoi de la notification de test. Veuillez vérifier vos réglages.', 'liens-morts-detector-jlg'),
            'missingRecipientsText'  => __('Ajoutez un destinataire ou configurez un webhook avant d’envoyer un test.', 'liens-morts-detector-jlg'),
            'missingChannelText'     => __('Sélectionnez au moins un type de résumé à tester.', 'liens-morts-detector-jlg'),
        )
    );
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
 * Prépare et envoie la notification webhook.
 *
 * @param string               $dataset_type Type d'analyse.
 * @param array<string, mixed> $summary      Résumé d'analyse.
 * @param array<string, mixed> $settings     Configuration du webhook.
 *
 * @return array<string, mixed>|WP_Error
 */
function blc_send_scan_summary_webhook($dataset_type, array $summary, array $settings) {
    if (!blc_is_webhook_notification_configured($settings)) {
        return new WP_Error('blc_webhook_not_configured', __('Aucun webhook configuré.', 'liens-morts-detector-jlg'));
    }

    $channel = isset($settings['channel']) ? (string) $settings['channel'] : 'generic';
    $message = blc_render_notification_message_template(isset($settings['message_template']) ? $settings['message_template'] : '', $summary);
    if ($message === '') {
        $message = isset($summary['subject']) ? (string) $summary['subject'] : '';
    }

    $payload = array();
    switch ($channel) {
        case 'slack':
        case 'teams':
            $payload = array('text' => $message);
            break;
        case 'generic':
        default:
            $payload = array(
                'message'      => $message,
                'subject'      => isset($summary['subject']) ? (string) $summary['subject'] : '',
                'dataset_type' => isset($summary['dataset_type']) ? (string) $summary['dataset_type'] : '',
                'broken_count' => isset($summary['broken_count']) ? (int) $summary['broken_count'] : 0,
                'report_url'   => isset($summary['report_url']) ? (string) $summary['report_url'] : '',
                'site_name'    => isset($summary['site_name']) ? (string) $summary['site_name'] : '',
            );
            break;
    }

    /**
     * Permet de personnaliser le contenu envoyé au webhook.
     *
     * @param array<string, mixed> $payload       Corps JSON.
     * @param string               $dataset_type  Type d'analyse.
     * @param array<string, mixed> $summary       Résumé d'analyse.
     * @param array<string, mixed> $settings      Configuration du webhook.
     * @param string               $channel       Canal sélectionné.
     */
    $payload = apply_filters('blc_notification_webhook_payload', $payload, $dataset_type, $summary, $settings, $channel, $message);

    if (is_wp_error($payload)) {
        return $payload;
    }

    $encoded_payload = wp_json_encode($payload);
    if (!is_string($encoded_payload)) {
        return new WP_Error('blc_webhook_json_encoding_failed', __('Impossible d’encoder le message de webhook en JSON.', 'liens-morts-detector-jlg'));
    }

    $request_args = array(
        'timeout'     => apply_filters('blc_notification_webhook_timeout', 15, $dataset_type, $settings, $payload),
        'redirection' => 3,
        'blocking'    => true,
        'headers'     => array('Content-Type' => 'application/json; charset=utf-8'),
        'body'        => $encoded_payload,
    );

    /**
     * Permet de filtrer les arguments passés à wp_remote_post pour le webhook.
     *
     * @param array<string, mixed> $request_args Arguments de la requête HTTP.
     * @param string               $dataset_type Type d'analyse.
     * @param array<string, mixed> $summary      Résumé d'analyse.
     * @param array<string, mixed> $settings     Configuration du webhook.
     * @param array<string, mixed> $payload      Corps JSON.
     */
    $request_args = apply_filters('blc_notification_webhook_request_args', $request_args, $dataset_type, $summary, $settings, $payload);

    $response = wp_remote_post($settings['url'], $request_args);
    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return new WP_Error(
            'blc_webhook_unexpected_status',
            sprintf(__('Le webhook a répondu avec le code HTTP %d.', 'liens-morts-detector-jlg'), $code),
            array('response' => $response, 'code' => $code)
        );
    }

    return array(
        'response'     => $response,
        'request_args' => $request_args,
        'payload'      => $payload,
        'code'         => $code,
        'message'      => $message,
    );
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
    $results = array(
        'email'   => array('status' => 'skipped'),
        'webhook' => array('status' => 'skipped'),
    );

    $context = isset($args['context']) ? (string) $args['context'] : 'scan';
    $webhook_settings = isset($args['webhook_settings']) && is_array($args['webhook_settings'])
        ? $args['webhook_settings']
        : blc_get_notification_webhook_settings();

    $dataset_label = isset($summary['dataset_label']) ? (string) $summary['dataset_label'] : $dataset_type;
    $context_label = ($context === 'test') ? __('de test', 'liens-morts-detector-jlg') : __('planifiée', 'liens-morts-detector-jlg');

    if ($recipients !== array()) {
        $sent = wp_mail($recipients, (string) $summary['subject'], (string) $summary['message']);
        if ($sent) {
            $results['email']['status'] = 'sent';
        } else {
            $results['email'] = array(
                'status' => 'failed',
                'error'  => sprintf(__('Échec de l’envoi de l’e-mail pour l’analyse %s.', 'liens-morts-detector-jlg'), $dataset_label),
            );
            error_log(sprintf('BLC: Failed to send %s summary email (%s).', $dataset_type, $context_label));
        }
    }

    if (blc_is_webhook_notification_configured($webhook_settings)) {
        $webhook_response = blc_send_scan_summary_webhook($dataset_type, $summary, $webhook_settings);
        if (is_wp_error($webhook_response)) {
            $results['webhook'] = array(
                'status' => 'failed',
                'error'  => $webhook_response->get_error_message(),
            );
            error_log(sprintf('BLC: Webhook notification failed for %s summary (%s): %s', $dataset_type, $context_label, $webhook_response->get_error_message()));
        } else {
            $results['webhook'] = array(
                'status' => 'sent',
                'code'   => $webhook_response['code'],
            );
        }
    }

    return $results;
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

// Gère la modification d'une URL
add_action('wp_ajax_blc_edit_link', 'blc_ajax_edit_link_callback');
function blc_ajax_edit_link_callback() {
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

    global $wpdb;

    $prepared_old_url = blc_prepare_posted_url($params['old_url']);
    $prepared_new_url = blc_prepare_posted_url($params['new_url']);

    if ($prepared_old_url === '' || $prepared_new_url === '') {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $stored_old_url = blc_prepare_url_for_storage($prepared_old_url);
    if (!is_string($row['url']) || $row['url'] !== $stored_old_url) {
        wp_send_json_error([
            'message' => __('Le lien sélectionné est introuvable. Veuillez relancer une analyse.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_CONFLICT);
    }

    $raw_home_url = home_url();
    $site_url = trailingslashit($raw_home_url);
    $site_scheme = parse_url($raw_home_url, PHP_URL_SCHEME);
    if (!is_string($site_scheme) || $site_scheme === '') {
        $site_scheme = 'http';
    }

    $post = get_post($post_id);
    $permalink = '';
    if (function_exists('get_permalink')) {
        $permalink_candidate = null;
        if ($post) {
            $permalink_candidate = get_permalink($post);
        } else {
            $permalink_candidate = get_permalink($post_id);
        }

        if (is_string($permalink_candidate)) {
            $permalink = $permalink_candidate;
        }
    }

    $sanitized_new_url = wp_kses_bad_protocol($prepared_new_url, ['http', 'https']);
    if (!is_string($sanitized_new_url) || $sanitized_new_url === '') {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $normalized_sanitized_new_url = blc_normalize_user_input_url($sanitized_new_url);
    $normalized_prepared_new_url = blc_normalize_user_input_url($prepared_new_url);

    if ($normalized_sanitized_new_url !== $normalized_prepared_new_url) {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
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
        $normalized_new_url = $validated_new_url;
    }

    $normalized_parts = $normalized_old_url !== '' ? parse_url($normalized_old_url) : false;
    $validated_new_parts = $validated_new_url ? parse_url($validated_new_url) : false;
    if (
        !$normalized_old_url ||
        $normalized_parts === false ||
        empty($normalized_parts['scheme']) ||
        !in_array($normalized_parts['scheme'], ['http', 'https'], true) ||
        !$validated_new_url ||
        $validated_new_parts === false ||
        empty($validated_new_parts['scheme']) ||
        !in_array($validated_new_parts['scheme'], ['http', 'https'], true)
    ) {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
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
            wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
        }

        if ($looks_like_domain_input && $normalized_new_url !== '') {
            $final_new_url = $normalized_new_url;
        }
    }

    if (!$post) {
        if (!current_user_can('manage_options')) {
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

    $old_url = $prepared_old_url;
    if (strpos($final_new_url, '//') === 0) {
        $scheme_for_scheme_relative = (is_string($site_scheme) && $site_scheme !== '') ? $site_scheme : 'http';
        $final_new_url = set_url_scheme($final_new_url, $scheme_for_scheme_relative);
    }
    $new_url = esc_url_raw($final_new_url);
    if ($new_url === '') {
        wp_send_json_error(['message' => __('URL invalide.', 'liens-morts-detector-jlg')], BLC_HTTP_BAD_REQUEST);
    }

    $normalized_content = blc_normalize_post_content_encoding($post->post_content);
    $replacement = blc_replace_link_href_in_content($normalized_content, $old_url, $new_url, $occurrence_index);

    if (!$replacement['updated']) {
        wp_send_json_error([
            'message' => __('Impossible de localiser cette occurrence du lien. Le contenu a peut-être été modifié.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_CONFLICT);
    }

    $new_content = $replacement['content'];
    $new_content = blc_restore_post_content_encoding($new_content);
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

// Gère la dissociation d'un lien
add_action('wp_ajax_blc_unlink', 'blc_ajax_unlink_callback');
function blc_ajax_unlink_callback() {
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
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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

    $remote_request_client = new \JLG\BrokenLinks\Scanner\RemoteRequestClient();

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
    if (!current_user_can('manage_options')) {
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
        $summary = blc_generate_scan_summary_email($dataset_type);
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
