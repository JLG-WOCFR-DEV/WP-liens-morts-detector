<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Charge le contenu HTML d'un article dans un DOMDocument et retourne également un DOMXPath.
 *
 * Cette fonction centralise la gestion des erreurs libxml et garantit la restauration de la
 * configuration précédente de libxml_use_internal_errors().
 *
 * @param string $post_content Contenu de l'article.
 * @return array{dom: DOMDocument, xpath: DOMXPath}|array{error: string} Tableau associatif contenant le DOMDocument et DOMXPath, ou un message d'erreur.
 */
function blc_load_dom_from_post($post_content) {
    $previous = libxml_use_internal_errors(true);

    $dom = new DOMDocument();

    if (function_exists('mb_convert_encoding')) {
        $converted_content = mb_convert_encoding($post_content, 'HTML-ENTITIES', 'UTF-8');
        if ($converted_content === false) {
            $converted_content = $post_content;
        }
    } else {
        $converted_content = $post_content;
    }

    $loaded = $dom->loadHTML($converted_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $errors = libxml_get_errors();

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        $message = 'Impossible de charger le contenu HTML de l\'article.';

        if (!empty($errors)) {
            $first_error = reset($errors);
            if ($first_error instanceof \LibXMLError) {
                $message .= ' ' . trim($first_error->message);
            }
        }

        return ['error' => $message];
    }

    return [
        'dom' => $dom,
        'xpath' => new DOMXPath($dom),
    ];
}

/**
 * Normalize a value received from an <input type="time"> field to a two-digit hour string.
 *
 * The HTML control can return values such as "08", "08:00" or "08:00:30" depending on the
 * browser. We only store the hour component, clamped between 00 and 23 and padded with a
 * leading zero when required.
 *
 * @param string $value   Raw value coming from the form.
 * @param string $default Fallback used when no hour can be extracted.
 *
 * @return string Two-digit hour string between "00" and "23".
 */
function blc_normalize_hour_option($value, $default = '00') {
    $value   = trim((string) $value);
    $default = trim((string) $default);

    if ($default === '') {
        $default = '00';
    }

    $default_digits = preg_replace('/\D/', '', $default);
    if ($default_digits === '') {
        $default_digits = '0';
    }
    $default_hour = max(0, min(23, (int) $default_digits));

    $candidate = $value === '' ? $default : $value;
    $parts     = explode(':', $candidate);
    $hour_part = $parts[0] !== '' ? $parts[0] : $default;
    $hour_part = trim((string) $hour_part);

    if ($hour_part === '') {
        $hour_part = (string) $default_hour;
    }

    $hour_digits = preg_replace('/\D/', '', $hour_part);
    if ($hour_digits === '') {
        $hour_digits = (string) $default_hour;
    }

    $hour = max(0, min(23, (int) $hour_digits));

    return str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
}

/**
 * Prepare a value for an <input type="time"> attribute while preserving existing minutes.
 *
 * @param string $value   Stored option value.
 * @param string $default Fallback hour when the value is empty.
 *
 * @return string Formatted time string compliant with the control.
 */
function blc_prepare_time_input_value($value, $default = '00') {
    $value = trim((string) $value);

    if ($value === '') {
        $value = $default;
    }

    if (preg_match('/^\d{1,2}(:\d{1,2}){1,2}$/', $value) === 1) {
        $parts  = explode(':', $value);
        $hour   = str_pad((string) max(0, min(23, (int) $parts[0])), 2, '0', STR_PAD_LEFT);
        $minute = isset($parts[1]) ? str_pad(substr($parts[1], 0, 2), 2, '0', STR_PAD_LEFT) : '00';

        return $hour . ':' . $minute;
    }

    if (preg_match('/^\d{1,2}$/', $value) === 1) {
        return str_pad($value, 2, '0', STR_PAD_LEFT) . ':00';
    }

    $hour = blc_normalize_hour_option($value, $default);

    return $hour . ':00';
}
