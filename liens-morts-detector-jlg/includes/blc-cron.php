<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retourne les planifications personnalisées proposées par défaut.
 *
 * @since 1.1.0
 *
 * @return array<string, array{interval:int, display:string}> Tableau associatif de définitions.
 */
function blc_get_default_cron_schedules() {
    return array(
        'blc_hourly'       => array(
            'interval' => HOUR_IN_SECONDS,
            'display'  => __('Toutes les heures', 'liens-morts-detector-jlg'),
        ),
        'blc_six_hours'    => array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => __('Toutes les 6 heures', 'liens-morts-detector-jlg'),
        ),
        'blc_twelve_hours' => array(
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => __('Toutes les 12 heures', 'liens-morts-detector-jlg'),
        ),
        'weekly'           => array(
            'interval' => 7 * DAY_IN_SECONDS,
            'display'  => __('Une fois par semaine', 'liens-morts-detector-jlg'),
        ),
        'monthly'          => array(
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => __('Une fois par mois', 'liens-morts-detector-jlg'),
        ),
    );
}

/**
 * Ajoute des planifications personnalisées (hebdomadaire, mensuelle, etc.) à la liste des
 * fréquences de WP-Cron.
 *
 * @param array $schedules Le tableau des planifications existantes.
 * @return array Le tableau des planifications mis à jour.
 */
function blc_add_cron_schedules($schedules) {
    $default_definitions = blc_get_default_cron_schedules();

    /**
     * Permet de modifier la liste des planifications ajoutées par le plugin.
     *
     * @since 1.1.0
     *
     * @param array $default_definitions Tableau associatif `slug => array( 'interval' => int, 'display' => string )`.
     */
    $definitions = apply_filters('blc_cron_schedule_definitions', $default_definitions);

    foreach ($definitions as $slug => $definition) {
        if (!is_scalar($slug)) {
            continue;
        }

        $slug = (string) $slug;
        if ('' === $slug || !is_array($definition)) {
            continue;
        }

        $interval = isset($definition['interval']) ? (int) $definition['interval'] : 0;
        if ($interval <= 0) {
            continue;
        }

        $display = isset($definition['display']) ? (string) $definition['display'] : $slug;

        $schedules[$slug] = array(
            'interval' => $interval,
            'display'  => $display,
        );
    }

    $custom_hours = blc_get_custom_frequency_hours();
    $default_custom_schedule = array(
        'interval' => max(HOUR_IN_SECONDS, $custom_hours * HOUR_IN_SECONDS),
        'display'  => sprintf(
            /* translators: %d: number of hours. */
            __('Toutes les %d heures (personnalisé)', 'liens-morts-detector-jlg'),
            $custom_hours
        ),
    );

    /**
     * Permet de modifier l'intervalle personnalisé avant son enregistrement auprès de WP-Cron.
     *
     * @since 1.1.0
     *
     * @param array $schedule Tableau contenant les clés `interval` (en secondes) et `display`.
     * @param int   $custom_hours Nombre d'heures configuré via l'interface d'administration.
     */
    $custom_schedule = apply_filters('blc_custom_cron_schedule', $default_custom_schedule, $custom_hours);

    if (is_array($custom_schedule) && isset($custom_schedule['interval'])) {
        $interval = (int) $custom_schedule['interval'];

        if ($interval > 0) {
            $display = isset($custom_schedule['display'])
                ? (string) $custom_schedule['display']
                : $default_custom_schedule['display'];

            $schedules['blc_custom_interval'] = array(
                'interval' => $interval,
                'display'  => $display,
            );

            /**
             * Se déclenche lorsque le créneau personnalisé est enregistré auprès de WP-Cron.
             *
             * @since 1.1.0
             *
             * @param array $schedule     Tableau contenant les clés `interval` et `display`.
             * @param int   $custom_hours Nombre d'heures configuré pour l'intervalle personnalisé.
             */
            do_action('blc_custom_cron_schedule_registered', $schedules['blc_custom_interval'], $custom_hours);
        }
    }

    $image_custom_hours = blc_get_image_custom_frequency_hours();
    $default_image_custom_schedule = array(
        'interval' => max(HOUR_IN_SECONDS, $image_custom_hours * HOUR_IN_SECONDS),
        'display'  => sprintf(
            /* translators: %d: number of hours. */
            __('Toutes les %d heures (analyse images)', 'liens-morts-detector-jlg'),
            $image_custom_hours
        ),
    );

    /**
     * Permet de modifier l'intervalle personnalisé dédié aux scans d'images
     * avant son enregistrement auprès de WP-Cron.
     *
     * @since 1.4.0
     *
     * @param array $schedule        Tableau contenant les clés `interval` (en secondes) et `display`.
     * @param int   $image_custom_hours Nombre d'heures configuré via l'interface d'administration.
     */
    $image_custom_schedule = apply_filters('blc_image_custom_cron_schedule', $default_image_custom_schedule, $image_custom_hours);

    if (is_array($image_custom_schedule) && isset($image_custom_schedule['interval'])) {
        $interval = (int) $image_custom_schedule['interval'];

        if ($interval > 0) {
            $display = isset($image_custom_schedule['display'])
                ? (string) $image_custom_schedule['display']
                : $default_image_custom_schedule['display'];

            $schedules['blc_image_custom_interval'] = array(
                'interval' => $interval,
                'display'  => $display,
            );

            /**
             * Se déclenche lorsque le créneau personnalisé pour les images est enregistré auprès de WP-Cron.
             *
             * @since 1.4.0
             *
             * @param array $schedule Tableau contenant les clés `interval` et `display`.
             * @param int   $image_custom_hours Nombre d'heures configuré pour l'intervalle personnalisé d'images.
             */
            do_action('blc_image_custom_cron_schedule_registered', $schedules['blc_image_custom_interval'], $image_custom_hours);
        }
    }

    return $schedules;
}

/**
 * Récupère le nombre d'heures configuré pour l'intervalle personnalisé.
 *
 * @param int|null $hours Valeur brute à normaliser.
 *
 * @return int
 */
function blc_get_custom_frequency_hours($hours = null) {
    $raw_value = (null === $hours) ? get_option('blc_frequency_custom_hours', 24) : $hours;

    if (!is_numeric($raw_value)) {
        $raw_value = 24;
    }

    $parsed_value = (int) $raw_value;
    $min_hours    = 1;
    $max_hours    = (int) apply_filters('blc_custom_frequency_max_hours', 24 * 30);

    if ($max_hours < $min_hours) {
        $max_hours = $min_hours;
    }

    return max($min_hours, min($max_hours, $parsed_value));
}

/**
 * Normalise l'heure de départ de l'intervalle personnalisé.
 *
 * @param string|null $time Valeur brute à normaliser.
 *
 * @return string Heure au format HH:MM.
 */
function blc_get_custom_frequency_time($time = null) {
    $raw_value = (null === $time) ? get_option('blc_frequency_custom_time', '00:00') : $time;
    $raw_value = trim((string) $raw_value);

    if ($raw_value === '') {
        $raw_value = '00:00';
    }

    $pattern = '/^(\d{1,2})(?::(\d{1,2}))?$/';
    if (preg_match($pattern, $raw_value, $matches) === 1) {
        $hour   = max(0, min(23, (int) $matches[1]));
        $minute = isset($matches[2]) ? max(0, min(59, (int) $matches[2])) : 0;

        return sprintf('%02d:%02d', $hour, $minute);
    }

    $digits = preg_replace('/\D/', '', $raw_value);
    if ($digits === '') {
        return '00:00';
    }

    $hour   = max(0, min(23, (int) substr($digits, 0, 2)));
    $minute = (strlen($digits) >= 4)
        ? max(0, min(59, (int) substr($digits, 2, 2)))
        : 0;

    return sprintf('%02d:%02d', $hour, $minute);
}

/**
 * Récupère le nombre d'heures configuré pour l'intervalle personnalisé des scans d'images.
 *
 * @param int|null $hours Valeur brute à normaliser.
 *
 * @return int
 */
function blc_get_image_custom_frequency_hours($hours = null) {
    $raw_value = (null === $hours) ? get_option('blc_image_scan_frequency_custom_hours', 168) : $hours;

    if (!is_numeric($raw_value)) {
        $raw_value = 168;
    }

    $parsed_value = (int) $raw_value;
    $min_hours    = 1;
    $max_hours    = (int) apply_filters('blc_image_custom_frequency_max_hours', 24 * 90);

    if ($max_hours < $min_hours) {
        $max_hours = $min_hours;
    }

    return max($min_hours, min($max_hours, $parsed_value));
}

/**
 * Normalise l'heure de départ de l'intervalle personnalisé pour les scans d'images.
 *
 * @param string|null $time Valeur brute à normaliser.
 *
 * @return string Heure au format HH:MM.
 */
function blc_get_image_custom_frequency_time($time = null) {
    $raw_value = (null === $time) ? get_option('blc_image_scan_frequency_custom_time', '02:00') : $time;
    $raw_value = trim((string) $raw_value);

    if ($raw_value === '') {
        $raw_value = '02:00';
    }

    $pattern = '/^(\d{1,2})(?::(\d{1,2}))?$/';
    if (preg_match($pattern, $raw_value, $matches) === 1) {
        $hour   = max(0, min(23, (int) $matches[1]));
        $minute = isset($matches[2]) ? max(0, min(59, (int) $matches[2])) : 0;

        return sprintf('%02d:%02d', $hour, $minute);
    }

    $digits = preg_replace('/\D/', '', $raw_value);
    if ($digits === '') {
        return '02:00';
    }

    $hour   = max(0, min(23, (int) substr($digits, 0, 2)));
    $minute = (strlen($digits) >= 4)
        ? max(0, min(59, (int) substr($digits, 2, 2)))
        : 0;

    return sprintf('%02d:%02d', $hour, $minute);
}

/**
 * Détermine l'identifiant de planification WP-Cron à utiliser pour la fréquence donnée.
 *
 * @param string $frequency Valeur soumise dans les réglages.
 *
 * @return string
 */
function blc_resolve_cron_schedule_slug($frequency) {
    $frequency = trim((string) $frequency);

    if ($frequency === '') {
        return 'daily';
    }

    if ($frequency === 'custom') {
        return 'blc_custom_interval';
    }

    return $frequency;
}

/**
 * Détermine l'identifiant de planification WP-Cron à utiliser pour la fréquence d'analyse des images.
 *
 * @param string $frequency Valeur soumise dans les réglages.
 *
 * @return string
 */
function blc_resolve_image_cron_schedule_slug($frequency) {
    $frequency = trim((string) $frequency);

    if ($frequency === '') {
        return 'weekly';
    }

    if ($frequency === 'custom') {
        return 'blc_image_custom_interval';
    }

    return $frequency;
}

/**
 * Calcule le prochain timestamp de départ pour une planification personnalisée.
 *
 * @param string      $time_string         Heure de départ au format HH:MM.
 * @param int|null    $reference_timestamp Timestamp de référence (par défaut, maintenant).
 *
 * @return int
 */
function blc_calculate_custom_schedule_timestamp($time_string, $reference_timestamp = null) {
    $normalized_time = blc_get_custom_frequency_time($time_string);
    $parts           = explode(':', $normalized_time);
    $hour            = isset($parts[0]) ? (int) $parts[0] : 0;
    $minute          = isset($parts[1]) ? (int) $parts[1] : 0;

    if (function_exists('wp_timezone')) {
        $timezone = wp_timezone();
    } elseif (function_exists('wp_timezone_string')) {
        $timezone = new DateTimeZone((string) wp_timezone_string());
    } else {
        $timezone_string = get_option('timezone_string');
        $timezone        = $timezone_string ? new DateTimeZone((string) $timezone_string) : new DateTimeZone('UTC');
    }

    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone('UTC');
    }

    $now = ($reference_timestamp !== null)
        ? new DateTimeImmutable('@' . (int) $reference_timestamp)
        : new DateTimeImmutable('now', $timezone);

    if ($now->getTimezone()->getName() !== $timezone->getName()) {
        $now = $now->setTimezone($timezone);
    }

    $candidate = $now->setTime($hour, $minute, 0);

    if ($candidate <= $now) {
        $candidate = $candidate->modify('+1 day');
    }

    return $candidate->getTimestamp();
}

/**
 * Calcule le prochain timestamp de départ pour une planification personnalisée des scans d'images.
 *
 * @param string   $time_string          Heure de départ au format HH:MM.
 * @param int|null $reference_timestamp  Timestamp de référence (par défaut, maintenant).
 *
 * @return int
 */
function blc_calculate_image_custom_schedule_timestamp($time_string, $reference_timestamp = null) {
    $normalized_time = blc_get_image_custom_frequency_time($time_string);
    $parts           = explode(':', $normalized_time);
    $hour            = isset($parts[0]) ? (int) $parts[0] : 0;
    $minute          = isset($parts[1]) ? (int) $parts[1] : 0;

    if (function_exists('wp_timezone')) {
        $timezone = wp_timezone();
    } elseif (function_exists('wp_timezone_string')) {
        $timezone = new DateTimeZone((string) wp_timezone_string());
    } else {
        $timezone_string = get_option('timezone_string');
        $timezone        = $timezone_string ? new DateTimeZone((string) $timezone_string) : new DateTimeZone('UTC');
    }

    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone('UTC');
    }

    $now = ($reference_timestamp !== null)
        ? new DateTimeImmutable('@' . (int) $reference_timestamp)
        : new DateTimeImmutable('now', $timezone);

    if ($now->getTimezone()->getName() !== $timezone->getName()) {
        $now = $now->setTimezone($timezone);
    }

    $candidate = $now->setTime($hour, $minute, 0);

    if ($candidate <= $now) {
        $candidate = $candidate->modify('+1 day');
    }

    return $candidate->getTimestamp();
}

/**
 * (Re)programme la tâche cron principale du plugin.
 *
 * @param array $args {
 *     Arguments facultatifs.
 *
 *     @type string|null $frequency          Fréquence souhaitée (valeur du champ `blc_frequency`).
 *     @type int|null    $custom_hours       Nombre d'heures pour l'intervalle personnalisé.
 *     @type string|null $custom_time        Heure de départ pour l'intervalle personnalisé.
 *     @type string      $context            Contexte d'appel (activation, réglages, etc.).
 *     @type int|null    $reference_timestamp Timestamp de référence pour le calcul personnalisé.
 * }
 *
 * @return array {
 *     @type bool   $success           Indique si la programmation a réussi.
 *     @type string $schedule          Identifiant WP-Cron utilisé.
 *     @type int    $timestamp         Timestamp planifié pour le prochain déclenchement.
 *     @type bool   $restore_attempted Indique si une tentative de restauration a été effectuée.
 *     @type bool   $restored          Indique si la restauration a réussi.
 *     @type int    $previous_timestamp Ancien timestamp planifié (le cas échéant).
 *     @type string $previous_schedule  Ancienne récurrence (le cas échéant).
 *     @type string $error_code         Code d'erreur éventuel.
 *     @type string $error_message      Message d'erreur technique.
 * }
 */
function blc_reset_link_check_schedule(array $args = array()) {
    $defaults = array(
        'frequency'           => null,
        'custom_hours'        => null,
        'custom_time'         => null,
        'context'             => 'settings',
        'reference_timestamp' => null,
    );

    $args = array_merge($defaults, $args);

    $frequency      = (null === $args['frequency']) ? get_option('blc_frequency', 'daily') : $args['frequency'];
    $schedule_slug  = blc_resolve_cron_schedule_slug($frequency);
    $custom_hours   = blc_get_custom_frequency_hours($args['custom_hours']);
    $custom_time    = blc_get_custom_frequency_time($args['custom_time']);
    $timestamp      = ('blc_custom_interval' === $schedule_slug)
        ? blc_calculate_custom_schedule_timestamp($custom_time, $args['reference_timestamp'])
        : time();
    $previous_event_timestamp = wp_next_scheduled('blc_check_links');
    $previous_event_schedule  = wp_get_schedule('blc_check_links');

    $result = array(
        'success'            => false,
        'schedule'           => $schedule_slug,
        'timestamp'          => $timestamp,
        'restore_attempted'  => false,
        'restored'           => false,
        'previous_timestamp' => $previous_event_timestamp,
        'previous_schedule'  => $previous_event_schedule,
        'error_code'         => '',
        'error_message'      => '',
    );

    $schedules = wp_get_schedules();
    if (!isset($schedules[$schedule_slug])) {
        $result['error_code']    = 'missing_schedule';
        $result['error_message'] = sprintf('BLC: Schedule "%s" is not registered.', $schedule_slug);

        return $result;
    }

    wp_clear_scheduled_hook('blc_check_links');

    $scheduled = wp_schedule_event($timestamp, $schedule_slug, 'blc_check_links');

    if (false === $scheduled) {
        $result['error_code']    = 'schedule_failed';
        $result['error_message'] = sprintf(
            'BLC: Failed to schedule automatic link check (frequency: %s, context: %s).',
            $schedule_slug,
            isset($args['context']) ? (string) $args['context'] : 'unknown'
        );

        error_log($result['error_message']);
        do_action('blc_check_links_schedule_failed', $schedule_slug, $args['context']);

        if (false !== $previous_event_timestamp && null !== $previous_event_timestamp) {
            $restore_timestamp = (int) $previous_event_timestamp;
            if ($restore_timestamp <= time()) {
                $restore_timestamp = time() + HOUR_IN_SECONDS;
            }

            $restore_schedule = $previous_event_schedule ? $previous_event_schedule : 'daily';
            $restored         = wp_schedule_event($restore_timestamp, $restore_schedule, 'blc_check_links');

            $result['restore_attempted'] = true;
            $result['restored']          = (false !== $restored);
        }

        return $result;
    }

    $result['success'] = true;

    do_action(
        'blc_check_links_schedule_updated',
        $schedule_slug,
        $timestamp,
        array(
            'frequency'    => $frequency,
            'custom_hours' => $custom_hours,
            'custom_time'  => $custom_time,
            'context'      => $args['context'],
        )
    );

    return $result;
}

/**
 * (Re)programme la tâche cron pour les scans d'images automatiques.
 *
 * @param array $args {
 *     Arguments facultatifs.
 *
 *     @type string|null $frequency            Fréquence souhaitée (valeur du champ `blc_image_scan_frequency`).
 *     @type int|null    $custom_hours         Nombre d'heures pour l'intervalle personnalisé.
 *     @type string|null $custom_time          Heure de départ pour l'intervalle personnalisé.
 *     @type string      $context              Contexte d'appel (activation, réglages, etc.).
 *     @type int|null    $reference_timestamp  Timestamp de référence pour le calcul personnalisé.
 * }
 *
 * @return array{
 *     success:bool,
 *     schedule:string,
 *     timestamp:int,
 *     restore_attempted:bool,
 *     restored:bool,
 *     previous_timestamp:int|false,
 *     previous_schedule:string|false,
 *     error_code:string,
 *     error_message:string
 * }
 */
function blc_reset_image_check_schedule(array $args = array()) {
    $defaults = array(
        'frequency'           => null,
        'custom_hours'        => null,
        'custom_time'         => null,
        'context'             => 'settings',
        'reference_timestamp' => null,
    );

    $args = array_merge($defaults, $args);

    $frequency     = (null === $args['frequency']) ? get_option('blc_image_scan_frequency', 'weekly') : $args['frequency'];
    $schedule_slug = blc_resolve_image_cron_schedule_slug($frequency);
    $custom_hours  = blc_get_image_custom_frequency_hours($args['custom_hours']);
    $custom_time   = blc_get_image_custom_frequency_time($args['custom_time']);
    $timestamp     = ('blc_image_custom_interval' === $schedule_slug)
        ? blc_calculate_image_custom_schedule_timestamp($custom_time, $args['reference_timestamp'])
        : time();
    $previous_event_timestamp = wp_next_scheduled('blc_check_image_batch', array(0, true));
    $previous_event_schedule  = wp_get_schedule('blc_check_image_batch', array(0, true));

    $result = array(
        'success'            => false,
        'schedule'           => $schedule_slug,
        'timestamp'          => $timestamp,
        'restore_attempted'  => false,
        'restored'           => false,
        'previous_timestamp' => $previous_event_timestamp,
        'previous_schedule'  => $previous_event_schedule,
        'error_code'         => '',
        'error_message'      => '',
    );

    $schedules = wp_get_schedules();
    if (!isset($schedules[$schedule_slug])) {
        $result['error_code']    = 'missing_schedule';
        $result['error_message'] = sprintf('BLC: Schedule "%s" is not registered for image scans.', $schedule_slug);

        return $result;
    }

    wp_clear_scheduled_hook('blc_check_image_batch', array(0, true));

    $scheduled = wp_schedule_event($timestamp, $schedule_slug, 'blc_check_image_batch', array(0, true));

    if (false === $scheduled) {
        $result['error_code']    = 'schedule_failed';
        $result['error_message'] = sprintf(
            'BLC: Failed to schedule automatic image check (frequency: %s, context: %s).',
            $schedule_slug,
            isset($args['context']) ? (string) $args['context'] : 'unknown'
        );

        error_log($result['error_message']);
        do_action('blc_check_image_batch_schedule_failed', 0, true, 'automatic_schedule');

        if (false !== $previous_event_timestamp && null !== $previous_event_timestamp) {
            $restore_timestamp = (int) $previous_event_timestamp;
            if ($restore_timestamp <= time()) {
                $restore_timestamp = time() + HOUR_IN_SECONDS;
            }

            $restore_schedule = $previous_event_schedule ? $previous_event_schedule : 'weekly';
            $restored         = wp_schedule_event($restore_timestamp, $restore_schedule, 'blc_check_image_batch', array(0, true));

            $result['restore_attempted'] = true;
            $result['restored']          = (false !== $restored);
        }

        return $result;
    }

    $result['success'] = true;

    do_action(
        'blc_check_image_schedule_updated',
        $schedule_slug,
        $timestamp,
        array(
            'frequency'    => $frequency,
            'custom_hours' => $custom_hours,
            'custom_time'  => $custom_time,
            'context'      => $args['context'],
        )
    );

    return $result;
}
