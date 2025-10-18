<?php
/**
 * Surveillance escalation helpers.
 *
 * @package LiensMortsDetector
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_get_surveillance_threshold_defaults')) {
    require_once __DIR__ . '/blc-surveillance.php';
}

if (!function_exists('blc_get_notification_recipients_list')) {
    require_once __DIR__ . '/blc-scanner.php';
}

if (!function_exists('blc_get_surveillance_severity_options')) {
    /**
     * Provide default severity labels when settings helpers are unavailable.
     *
     * @return array<string,string>
     */
    function blc_get_surveillance_severity_options() {
        return array(
            'warning'  => __('Avertissement', 'liens-morts-detector-jlg'),
            'critical' => __('Critique', 'liens-morts-detector-jlg'),
        );
    }
}

/**
 * Return the normalized escalation configuration.
 *
 * @return array<string,mixed>
 */
function blc_get_surveillance_escalation_settings() {
    $defaults = array(
        'email_levels'   => array('warning', 'critical'),
        'webhook_levels' => array('critical'),
        'cooldowns'      => array(
            'warning'  => 1800,
            'critical' => 600,
        ),
    );

    $email_levels   = get_option('blc_surveillance_escalation_email_levels', $defaults['email_levels']);
    $webhook_levels = get_option('blc_surveillance_escalation_webhook_levels', $defaults['webhook_levels']);
    $warning_cd     = get_option('blc_surveillance_escalation_cooldown_warning', $defaults['cooldowns']['warning']);
    $critical_cd    = get_option('blc_surveillance_escalation_cooldown_critical', $defaults['cooldowns']['critical']);

    $normalizeLevels = static function ($value) {
        if (function_exists('blc_sanitize_surveillance_escalation_levels_option')) {
            return blc_sanitize_surveillance_escalation_levels_option($value);
        }

        return blc_surveillance_normalize_levels($value);
    };

    $normalizeCooldown = static function ($value) {
        if (function_exists('blc_sanitize_surveillance_cooldown_option')) {
            return blc_sanitize_surveillance_cooldown_option($value);
        }

        return blc_surveillance_normalize_cooldown($value);
    };

    return array(
        'email_levels'   => $normalizeLevels($email_levels),
        'webhook_levels' => $normalizeLevels($webhook_levels),
        'cooldowns'      => array(
            'warning'  => $normalizeCooldown($warning_cd),
            'critical' => $normalizeCooldown($critical_cd),
        ),
    );
}

/**
 * Determine the active channels for a severity.
 *
 * @param string               $severity Severity slug.
 * @param array<string,mixed>  $settings Escalation settings.
 *
 * @return array{email:bool,webhook:bool}
 */
function blc_get_surveillance_channels_for_severity($severity, array $settings) {
    $severity = sanitize_key((string) $severity);

    $email_levels   = isset($settings['email_levels']) ? (array) $settings['email_levels'] : array();
    $webhook_levels = isset($settings['webhook_levels']) ? (array) $settings['webhook_levels'] : array();

    return array(
        'email'   => in_array($severity, $email_levels, true),
        'webhook' => in_array($severity, $webhook_levels, true),
    );
}

/**
 * Format the summary payload dispatched when alerts are triggered.
 *
 * @param string              $severity Alert severity.
 * @param array<int,array>    $alerts   Triggered alerts.
 * @param array<string,mixed> $metrics  Aggregated metrics.
 *
 * @return array<string,mixed>
 */
function blc_build_surveillance_summary_payload($severity, array $alerts, array $metrics) {
    $severity_labels = blc_get_surveillance_severity_options();
    $severity_slug   = sanitize_key((string) $severity);
    $severity_label  = isset($severity_labels[$severity_slug]) ? $severity_labels[$severity_slug] : $severity_slug;

    $site_name = function_exists('get_bloginfo') ? get_bloginfo('name') : '';
    $subject_parts = array();

    if ($site_name !== '') {
        $subject_parts[] = $site_name;
    }

    $subject_parts[] = sprintf(
        /* translators: %s: severity label. */
        __('Surveillance liens cassés — alerte %s', 'liens-morts-detector-jlg'),
        $severity_label
    );

    $subject = implode(' · ', array_filter($subject_parts));

    $lines = array();
    foreach ($alerts as $alert) {
        $label = isset($alert['label']) && $alert['label'] !== ''
            ? (string) $alert['label']
            : (isset($alert['id']) ? (string) $alert['id'] : __('Seuil', 'liens-morts-detector-jlg'));

        $value = isset($alert['value']) ? $alert['value'] : 0;
        $threshold = isset($alert['threshold']) ? $alert['threshold'] : 0;
        $comparison = isset($alert['comparison']) ? (string) $alert['comparison'] : 'gte';
        $scope = isset($alert['scope']) ? (string) $alert['scope'] : 'global';

        if ($scope === 'taxonomy') {
            $taxonomy_label = isset($alert['taxonomy_label']) ? (string) $alert['taxonomy_label'] : '';
            $term_name = isset($alert['term_name']) ? (string) $alert['term_name'] : '';
            if ($taxonomy_label !== '' || $term_name !== '') {
                $label .= ' — ' . trim($taxonomy_label . ' ' . $term_name);
            }
        }

        $value_label = number_format_i18n(is_numeric($value) ? $value : 0, ($alert['metric'] ?? '') === 'broken_ratio' ? 1 : 0);
        $threshold_label = number_format_i18n(is_numeric($threshold) ? $threshold : 0, ($alert['metric'] ?? '') === 'broken_ratio' ? 1 : 0);

        $lines[] = sprintf(
            /* translators: 1: threshold label, 2: current value, 3: comparison operator, 4: threshold value. */
            __('%1$s : %2$s (%3$s %4$s)', 'liens-morts-detector-jlg'),
            $label,
            $value_label,
            strtoupper($comparison),
            $threshold_label
        );
    }

    $metric_section = '';
    if (isset($metrics['global'])) {
        $global = $metrics['global'];
        $ratio  = isset($global['broken_ratio']) ? (float) $global['broken_ratio'] : 0.0;
        $count  = isset($global['broken_count']) ? (int) $global['broken_count'] : 0;
        $total  = isset($global['total_tracked']) ? (int) $global['total_tracked'] : 0;

        $metric_section = sprintf(
            /* translators: 1: broken ratio, 2: broken count, 3: total tracked urls. */
            __('Ratio global : %1$s %% — Liens cassés : %2$s / %3$s suivis.', 'liens-morts-detector-jlg'),
            number_format_i18n($ratio, 1),
            number_format_i18n($count, 0),
            number_format_i18n($total, 0)
        );
    }

    $message_parts = array(
        implode("\n", $lines),
        $metric_section,
        __('Consultez le tableau de bord pour identifier les URLs prioritaires.', 'liens-morts-detector-jlg'),
    );

    return array(
        'subject'       => $subject,
        'message'       => trim(implode("\n\n", array_filter($message_parts))),
        'dataset_type'  => 'link',
        'dataset_label' => __('Surveillance proactive', 'liens-morts-detector-jlg'),
        'severity'      => $severity_slug,
        'alerts'        => $alerts,
        'metrics'       => $metrics,
    );
}

/**
 * Dispatch notifications when surveillance thresholds are triggered.
 *
 * @param array<int,array<string,mixed>> $alerts      Triggered alerts.
 * @param array<string,mixed>            $metrics     Evaluated metrics.
 * @param array<string,mixed>            $definitions Active definitions.
 * @param string                         $dataset     Dataset identifier.
 * @param array<string,mixed>            $context     Context payload.
 *
 * @return void
 */
function blc_handle_surveillance_threshold_alerts($alerts, $metrics, $definitions, $dataset, $context) {
    if ($dataset !== 'link' || empty($alerts)) {
        return;
    }

    $settings = blc_get_surveillance_escalation_settings();
    $grouped  = array();

    foreach ($alerts as $alert) {
        $severity = isset($alert['severity']) ? sanitize_key((string) $alert['severity']) : 'warning';
        if ($severity === '') {
            $severity = 'warning';
        }

        if (!isset($grouped[$severity])) {
            $grouped[$severity] = array();
        }

        $grouped[$severity][] = $alert;
    }

    foreach ($grouped as $severity => $severity_alerts) {
        $channels = blc_get_surveillance_channels_for_severity($severity, $settings);

        $recipients = $channels['email'] ? blc_get_notification_recipients_list() : array();
        $webhook_settings = $channels['webhook'] ? blc_get_notification_webhook_settings() : array('channel' => 'disabled', 'url' => '');

        if ($channels['webhook'] && !blc_is_webhook_notification_configured($webhook_settings)) {
            $channels['webhook'] = false;
            $webhook_settings   = array('channel' => 'disabled', 'url' => '');
        }

        if (!$channels['email'] && !$channels['webhook']) {
            continue;
        }

        $summary = blc_build_surveillance_summary_payload($severity, $severity_alerts, $metrics);

        blc_dispatch_scan_summary_notifications(
            'link',
            $summary,
            $channels['email'] ? $recipients : array(),
            array(
                'context'                => 'surveillance',
                'surveillance_severity'  => $severity,
                'webhook_settings'       => $webhook_settings,
                'include_details'        => true,
            )
        );
    }
}
add_action('blc_surveillance_thresholds_triggered', 'blc_handle_surveillance_threshold_alerts', 10, 5);

/**
 * Adjust the throttle window for surveillance alerts based on severity.
 *
 * @param int    $window     Default window.
 * @param string $channel    Channel identifier.
 * @param string $dataset    Dataset type.
 * @param string $context    Dispatch context.
 * @param array  $args       Additional arguments.
 *
 * @return int
 */
function blc_adjust_surveillance_notification_throttle_window($window, $channel, $dataset, $context, $args) {
    if ($context !== 'surveillance') {
        return $window;
    }

    $settings = blc_get_surveillance_escalation_settings();
    $severity = isset($args['surveillance_severity']) ? sanitize_key((string) $args['surveillance_severity']) : 'warning';

    if ($severity === 'critical') {
        return isset($settings['cooldowns']['critical']) ? (int) $settings['cooldowns']['critical'] : $window;
    }

    if ($severity === 'warning') {
        return isset($settings['cooldowns']['warning']) ? (int) $settings['cooldowns']['warning'] : $window;
    }

    return $window;
}
add_filter('blc_notification_throttle_window', 'blc_adjust_surveillance_notification_throttle_window', 10, 5);

if (!function_exists('blc_surveillance_normalize_levels')) {
    /**
     * Normalize escalation levels when the settings helper is not loaded yet.
     *
     * @param mixed $value Raw value.
     *
     * @return array<int,string>
     */
    function blc_surveillance_normalize_levels($value) {
        $choices = blc_get_surveillance_severity_options();

        if (is_string($value)) {
            $value = array($value);
        }

        if (!is_array($value)) {
            $value = array();
        }

        $normalized = array();

        foreach ($value as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            $slug = sanitize_key((string) $candidate);
            if ($slug === '' || !isset($choices[$slug])) {
                continue;
            }

            $normalized[$slug] = $slug;
        }

        return array_values($normalized);
    }
}

if (!function_exists('blc_surveillance_normalize_cooldown')) {
    /**
     * Normalize cooldown values when the settings helper is not loaded yet.
     *
     * @param mixed $value Raw value.
     *
     * @return int
     */
    function blc_surveillance_normalize_cooldown($value) {
        $value = is_numeric($value) ? (int) $value : 0;

        if ($value < 60) {
            $value = 60;
        }

        return $value;
    }
}
