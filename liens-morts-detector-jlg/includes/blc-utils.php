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
 * Met à jour le contenu d'un article en appliquant une modification sur les liens correspondant à une URL donnée.
 *
 * La fonction charge le contenu de l'article, recherche les balises <a> ciblées, applique la callback fournie
 * puis enregistre le contenu mis à jour.
 *
 * @param int      $post_id    Identifiant de l'article à modifier.
 * @param string   $target_url URL du lien à rechercher dans le contenu.
 * @param callable $callback   Fonction appelée avec le DOMDocument et la liste des balises <a> correspondantes.
 *                             Elle doit effectuer les modifications nécessaires sur le DOM.
 *
 * @return array{success: true, content: string}|array{error: string} Résultat de l'opération.
 */
function blc_update_link_in_post($post_id, $target_url, callable $callback) {
    $post = get_post($post_id);
    if (!$post) {
        return ['error' => 'Article non trouvé.'];
    }

    $dom_data = blc_load_dom_from_post($post->post_content);
    if (isset($dom_data['error'])) {
        return ['error' => $dom_data['error']];
    }

    /** @var DOMDocument $dom */
    $dom = $dom_data['dom'];
    /** @var DOMXPath $xpath */
    $xpath = $dom_data['xpath'];

    $escaped_url = function_exists('esc_attr')
        ? esc_attr($target_url)
        : htmlspecialchars($target_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $anchors = $xpath->query(sprintf('//a[@href="%s"]', $escaped_url));

    if ($anchors->length === 0) {
        return ['error' => 'Le lien n\'a pas été trouvé dans le contenu de l\'article.'];
    }

    $callback_result = $callback($dom, $anchors);
    if (is_array($callback_result) && isset($callback_result['error'])) {
        return $callback_result;
    }

    $new_content = $dom->saveHTML();
    $slashed_content = function_exists('wp_slash') ? wp_slash($new_content) : $new_content;

    $update_result = wp_update_post([
        'ID' => $post_id,
        'post_content' => $slashed_content,
    ], true);

    $is_wp_error = function_exists('is_wp_error') && is_wp_error($update_result);

    if (!$update_result || $is_wp_error) {
        $error_message = 'La mise à jour de l\'article a échoué.';
        if ($is_wp_error && method_exists($update_result, 'get_error_message')) {
            $error_message .= ' ' . $update_result->get_error_message();
        }

        return ['error' => $error_message];
    }

    return [
        'success' => true,
        'content' => $new_content,
    ];
}
