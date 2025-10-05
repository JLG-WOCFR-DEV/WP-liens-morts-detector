<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build a descriptive HTTP user agent string for outbound requests.
 *
 * The default value is composed of the plugin name, its current version and
 * the site URL. A filter is provided to allow advanced customization when
 * needed.
 *
 * @return string
 */
function blc_get_http_user_agent() {
    static $plugin_metadata = null;

    if ($plugin_metadata === null) {
        $default_name = 'Liens Morts Detector';
        $default_version = 'dev';

        $plugin_name = $default_name;
        $plugin_version = $default_version;

        $plugin_file = dirname(__DIR__) . '/liens-morts-detector-jlg.php';

        if (is_readable($plugin_file)) {
            if (function_exists('get_file_data')) {
                $data = get_file_data($plugin_file, [
                    'Name'    => 'Plugin Name',
                    'Version' => 'Version',
                ]);

                if (is_array($data)) {
                    if (!empty($data['Name'])) {
                        $plugin_name = trim((string) $data['Name']);
                    }
                    if (!empty($data['Version'])) {
                        $plugin_version = trim((string) $data['Version']);
                    }
                }
            } else {
                $contents = file_get_contents($plugin_file);
                if (is_string($contents)) {
                    if (preg_match('/^\s*Plugin Name:\s*(.+)$/mi', $contents, $matches) === 1) {
                        $plugin_name = trim((string) $matches[1]);
                    }
                    if (preg_match('/^\s*Version:\s*(.+)$/mi', $contents, $matches) === 1) {
                        $plugin_version = trim((string) $matches[1]);
                    }
                }
            }
        }

        if ($plugin_name === '') {
            $plugin_name = $default_name;
        }

        if ($plugin_version === '') {
            $plugin_version = $default_version;
        }

        $plugin_metadata = [
            'name'    => $plugin_name,
            'version' => $plugin_version,
        ];
    }

    $plugin_name = $plugin_metadata['name'];
    $plugin_version = $plugin_metadata['version'];

    $site_url = '';
    if (function_exists('home_url')) {
        $site_url = home_url('/');
    } elseif (function_exists('site_url')) {
        $site_url = site_url('/');
    } elseif (function_exists('get_bloginfo')) {
        $site_url = get_bloginfo('url');
    }

    if (!is_string($site_url)) {
        $site_url = '';
    }

    $site_url = trim($site_url);

    if ($site_url !== '' && function_exists('esc_url_raw')) {
        $site_url = esc_url_raw($site_url);
    }

    $user_agent = $plugin_name !== '' ? $plugin_name : 'Liens Morts Detector';
    if ($plugin_version !== '') {
        $user_agent .= '/' . $plugin_version;
    }

    if ($site_url !== '') {
        $user_agent .= '; ' . $site_url;
    }

    if (function_exists('apply_filters')) {
        return (string) apply_filters('blc_http_user_agent', $user_agent, $plugin_name, $plugin_version, $site_url);
    }

    return $user_agent;
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

    $source_charset = 'UTF-8';
    if (function_exists('get_bloginfo')) {
        $blog_charset = get_bloginfo('charset');
        if (is_string($blog_charset)) {
            $blog_charset = trim($blog_charset);
        }

        if (!empty($blog_charset)) {
            $source_charset = $blog_charset;
        }
    }

    if (function_exists('mb_convert_encoding')) {
        $converted_content = mb_convert_encoding($post_content, 'HTML-ENTITIES', $source_charset);
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
        $message = __('Impossible de charger le contenu HTML de l\'article.', 'liens-morts-detector-jlg');

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
 * Resolve a broken link database row and normalize request metadata.
 *
 * @param int         $post_id          Identifier of the post currently being edited.
 * @param int         $row_id           Identifier of the broken link row.
 * @param string|null $occurrence_value Raw occurrence value provided by the client.
 *
 * @return array{row: array<string,mixed>, occurrence_index: int|null, table: string, cache_row: array<string,string>, cache_footprint: int}
 */
function blc_resolve_link_row($post_id, $row_id, $occurrence_value = null) {
    $row_id = absint($row_id);
    if ($row_id <= 0) {
        wp_send_json_error([
            'message' => __('Le lien sélectionné est introuvable. Veuillez relancer une analyse.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_BAD_REQUEST);
    }

    $occurrence_raw = '';
    if ($occurrence_value !== null) {
        if (is_string($occurrence_value)) {
            $occurrence_raw = trim($occurrence_value);
        } elseif (is_scalar($occurrence_value)) {
            $occurrence_raw = trim((string) $occurrence_value);
        }
    }

    $has_occurrence_index = ($occurrence_raw !== '');
    $occurrence_index = null;
    if ($has_occurrence_index) {
        if (preg_match('/^-?\d+$/', $occurrence_raw) !== 1) {
            wp_send_json_error([
                'message' => __('Indice d\'occurrence invalide.', 'liens-morts-detector-jlg'),
            ], BLC_HTTP_BAD_REQUEST);
        }

        $candidate_index = (int) $occurrence_raw;
        if ($candidate_index >= 0) {
            $occurrence_index = $candidate_index;
        }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'blc_broken_links';

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, post_id, url, anchor, post_title, occurrence_index, ignored_at FROM $table_name WHERE id = %d AND type = %s",
            $row_id,
            'link'
        ),
        ARRAY_A
    );

    if (!is_array($row)) {
        wp_send_json_error([
            'message' => __('Le lien sélectionné est introuvable. Veuillez relancer une analyse.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_NOT_FOUND);
    }

    if ((int) ($row['post_id'] ?? 0) !== $post_id) {
        wp_send_json_error([
            'message' => __('Le lien sélectionné ne correspond plus à cet article. Veuillez actualiser la page.', 'liens-morts-detector-jlg'),
        ], BLC_HTTP_CONFLICT);
    }

    $stored_occurrence_raw = $row['occurrence_index'] ?? null;
    $stored_occurrence_index = null;
    if (is_numeric($stored_occurrence_raw)) {
        $stored_candidate = (int) $stored_occurrence_raw;
        if ($stored_candidate >= 0) {
            $stored_occurrence_index = $stored_candidate;
        }
    }

    if ($stored_occurrence_index !== null) {
        if ($occurrence_index === null || $stored_occurrence_index !== $occurrence_index) {
            wp_send_json_error([
                'message' => __('L\'occurrence du lien ne correspond plus. Veuillez relancer une analyse.', 'liens-morts-detector-jlg'),
            ], BLC_HTTP_CONFLICT);
        }

        $occurrence_index = max(0, $stored_occurrence_index);
    } else {
        $occurrence_index = null;
    }

    $row_for_cache = [
        'url'        => $row['url'] ?? '',
        'anchor'     => $row['anchor'] ?? '',
        'post_title' => $row['post_title'] ?? '',
    ];

    $row_footprint = blc_calculate_row_storage_footprint_bytes(
        $row_for_cache['url'],
        $row_for_cache['anchor'],
        $row_for_cache['post_title']
    );

    return [
        'row'              => $row,
        'occurrence_index' => $occurrence_index,
        'table'            => $table_name,
        'cache_row'        => $row_for_cache,
        'cache_footprint'  => $row_footprint,
    ];
}


/**
 * Load a DOMDocument/XPath pair from an UTF-8 HTML fragment.
 *
 * @param string $html HTML fragment encoded in UTF-8.
 *
 * @return array{dom: DOMDocument, xpath: DOMXPath, root: DOMElement}|array{error: string}
 */
function blc_load_dom_from_html_fragment($html) {
    $html = (string) $html;

    $previous = libxml_use_internal_errors(true);

    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrapped_html = '<!DOCTYPE html><html><body><div id="blc-fragment-root">' . $html . '</div></body></html>';
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8"?>' . $wrapped_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $errors = libxml_get_errors();

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        $message = __('Impossible de charger le contenu HTML.', 'liens-morts-detector-jlg');

        if (!empty($errors)) {
            $first_error = reset($errors);
            if ($first_error instanceof \LibXMLError) {
                $message .= ' ' . trim($first_error->message);
            }
        }

        return ['error' => $message];
    }

    $body = $dom->getElementsByTagName('div')->item(0);
    if (!$body instanceof DOMElement) {
        return ['error' => __('Impossible de localiser le fragment HTML.', 'liens-morts-detector-jlg')];
    }

    return [
        'dom'   => $dom,
        'xpath' => new DOMXPath($dom),
        'root'  => $body,
    ];
}


/**
 * Export the inner HTML of a DOMElement.
 *
 * @param DOMElement $element Element whose children will be serialized.
 *
 * @return string Serialized HTML.
 */
function blc_dom_element_inner_html(DOMElement $element) {
    $html = '';

    foreach ($element->childNodes as $child) {
        $html .= $element->ownerDocument->saveHTML($child);
    }

    return $html;
}


/**
 * Convert stored post content from the blog charset to UTF-8 for safe inline manipulation.
 *
 * @param string $post_content Raw post content as retrieved from the database.
 *
 * @return string Content converted to UTF-8 when possible.
 */
function blc_normalize_post_content_encoding($post_content) {
    $post_content = (string) $post_content;

    $source_charset = 'UTF-8';
    if (function_exists('get_bloginfo')) {
        $blog_charset = get_bloginfo('charset');
        if (is_string($blog_charset)) {
            $blog_charset = trim($blog_charset);
        }

        if (!empty($blog_charset)) {
            $source_charset = $blog_charset;
        }
    }

    if (strcasecmp($source_charset, 'UTF-8') === 0) {
        return $post_content;
    }

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($post_content, 'UTF-8', $source_charset);
        if (is_string($converted)) {
            return $converted;
        }
    }

    if (function_exists('iconv')) {
        $converted = @iconv($source_charset, 'UTF-8//IGNORE', $post_content);
        if (is_string($converted)) {
            return $converted;
        }
    }

    return $post_content;
}


/**
 * Convert UTF-8 content back to the blog charset for storage in the database.
 *
 * @param string $utf8_content Content encoded in UTF-8.
 *
 * @return string Content converted to the configured blog charset when possible.
 */
function blc_restore_post_content_encoding($utf8_content) {
    $utf8_content = (string) $utf8_content;

    $target_charset = 'UTF-8';
    if (function_exists('get_bloginfo')) {
        $blog_charset = get_bloginfo('charset');
        if (is_string($blog_charset)) {
            $blog_charset = trim($blog_charset);
        }

        if (!empty($blog_charset)) {
            $target_charset = $blog_charset;
        }
    }

    if (strcasecmp($target_charset, 'UTF-8') === 0) {
        return $utf8_content;
    }

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($utf8_content, $target_charset, 'UTF-8');
        if (is_string($converted)) {
            return $converted;
        }
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', $target_charset . '//IGNORE', $utf8_content);
        if (is_string($converted)) {
            return $converted;
        }
    }

    return $utf8_content;
}


/**
 * Update the href attribute of matching <a> tags without reserializing the whole document.
 *
 * @param string   $html              Original HTML content.
 * @param string   $target_href       Href attribute to search for (should already be sanitized).
 * @param string   $replacement_href  Replacement value for the href attribute.
 * @param int|null $occurrence_index  Zero-based occurrence index to update. When null, all matches are updated.
 *
 * @return array{content: string, updated: bool} Updated HTML content and whether at least one link was modified.
 */
function blc_replace_link_href_in_content($html, $target_href, $replacement_href, $occurrence_index = null) {
    $html            = (string) $html;
    $target_href     = blc_prepare_posted_url($target_href);
    $replacement_href = (string) $replacement_href;

    if ($target_href === '' || $replacement_href === '') {
        return ['content' => $html, 'updated' => false];
    }

    $target_occurrence = null;
    if ($occurrence_index !== null) {
        if (!is_int($occurrence_index)) {
            $occurrence_index = (int) $occurrence_index;
        }
        if ($occurrence_index < 0) {
            return ['content' => $html, 'updated' => false];
        }
        $target_occurrence = $occurrence_index;
    }

    $dom_result = blc_load_dom_from_html_fragment($html);
    if (isset($dom_result['error'])) {
        return ['content' => $html, 'updated' => false];
    }

    /** @var DOMXPath $xpath */
    $xpath = $dom_result['xpath'];
    /** @var DOMElement $root */
    $root  = $dom_result['root'];

    $updated = false;

    $links = $xpath->query('.//a[@href]');
    if ($links instanceof DOMNodeList) {
        $match_index = 0;
        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href_value = blc_prepare_posted_url($link->getAttribute('href'));
            if ($href_value !== $target_href) {
                continue;
            }

            if ($target_occurrence !== null && $match_index !== $target_occurrence) {
                $match_index++;
                continue;
            }

            $link->setAttribute('href', $replacement_href);
            $updated = true;

            if ($target_occurrence !== null) {
                break;
            }

            $match_index++;
        }
    }

    if (!$updated) {
        return ['content' => $html, 'updated' => false];
    }

    return [
        'content' => blc_dom_element_inner_html($root),
        'updated' => true,
    ];
}

/**
 * Remove matching <a> wrappers while preserving their inner HTML.
 *
 * @param string   $html             Original HTML content.
 * @param string   $target_href      Href attribute to search for (should already be sanitized).
 * @param int|null $occurrence_index Zero-based occurrence index to remove. When null, all matches are removed.
 *
 * @return array{content: string, removed: bool} Updated HTML content and whether at least one link was removed.
 */
function blc_remove_link_wrappers_from_content($html, $target_href, $occurrence_index = null) {
    $html        = (string) $html;
    $target_href = blc_prepare_posted_url($target_href);

    if ($target_href === '') {
        return ['content' => $html, 'removed' => false];
    }

    $target_occurrence = null;
    if ($occurrence_index !== null) {
        if (!is_int($occurrence_index)) {
            $occurrence_index = (int) $occurrence_index;
        }
        if ($occurrence_index < 0) {
            return ['content' => $html, 'removed' => false];
        }
        $target_occurrence = $occurrence_index;
    }

    $dom_result = blc_load_dom_from_html_fragment($html);
    if (isset($dom_result['error'])) {
        return ['content' => $html, 'removed' => false];
    }

    /** @var DOMXPath $xpath */
    $xpath = $dom_result['xpath'];
    /** @var DOMElement $root */
    $root  = $dom_result['root'];

    $removed = false;

    $links = $xpath->query('.//a[@href]');
    if ($links instanceof DOMNodeList) {
        /** @var DOMElement[] $anchors */
        $anchors = [];
        foreach ($links as $link) {
            if ($link instanceof DOMElement) {
                $anchors[] = $link;
            }
        }

        $match_index = 0;
        foreach ($anchors as $anchor) {
            $href_value = blc_prepare_posted_url($anchor->getAttribute('href'));
            if ($href_value !== $target_href) {
                continue;
            }

            if ($target_occurrence !== null && $match_index !== $target_occurrence) {
                $match_index++;
                continue;
            }

            while ($anchor->firstChild) {
                $anchor->parentNode->insertBefore($anchor->firstChild, $anchor);
            }

            $anchor->parentNode->removeChild($anchor);
            $removed = true;

            if ($target_occurrence !== null) {
                break;
            }

            $match_index++;
        }
    }

    if (!$removed) {
        return ['content' => $html, 'removed' => false];
    }

    return [
        'content' => blc_dom_element_inner_html($root),
        'removed' => true,
    ];
}


/**
 * Normalize and truncate a string before storing it in the database.
 *
 * @param string $value               Raw value to clean.
 * @param int    $max_length          Maximum allowed length.
 * @param bool   $normalize_whitespace Whether to collapse consecutive whitespace.
 *
 * @return string Cleaned value trimmed to the requested length.
 */
function blc_truncate_for_storage($value, $max_length, $normalize_whitespace = false) {
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if ($normalize_whitespace) {
        $normalized = preg_replace('/\s+/u', ' ', $value);
        if (is_string($normalized)) {
            $value = trim($normalized);
        } else {
            $fallback = preg_replace('/\s+/', ' ', $value);
            if (is_string($fallback)) {
                $value = trim($fallback);
            }
        }
    }

    $max_length = (int) $max_length;
    if ($max_length <= 0) {
        return $value;
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') > $max_length) {
            $value = mb_substr($value, 0, $max_length, 'UTF-8');
        }
    } else {
        if (strlen($value) > $max_length) {
            $value = substr($value, 0, $max_length);
        }
    }

    return $value;
}

/**
 * Prepare a URL for storage in the database by trimming it.
 *
 * @param string $url Raw URL captured during the scan or provided by the UI.
 *
 * @return string URL cleaned while preserving its full length.
 */
function blc_prepare_url_for_storage($url) {
    return blc_truncate_for_storage($url, 0, false);
}

/**
 * Prepare a generic text field (anchor, post title, etc.) for storage.
 *
 * @param string $text Raw text captured during the scan or provided by the UI.
 *
 * @return string Text cleaned to match the storage column length.
 */
function blc_prepare_text_field_for_storage($text) {
    $max_length = defined('BLC_TEXT_FIELD_LENGTH') ? (int) BLC_TEXT_FIELD_LENGTH : 255;

    return blc_truncate_for_storage($text, $max_length, true);
}

/**
 * Prepare an HTML snippet captured during the scan for safe storage.
 *
 * @param string $html Raw HTML snippet surrounding the link.
 *
 * @return string Sanitized snippet trimmed to the configured size.
 */
function blc_prepare_context_html_for_storage($html) {
    $html = trim((string) $html);

    if ($html === '') {
        return '';
    }

    if (function_exists('wp_kses_post')) {
        $sanitized = wp_kses_post($html);
        if (is_string($sanitized)) {
            $html = $sanitized;
        }
    }

    $max_length = (int) apply_filters('blc_context_html_max_length', 5000);
    if ($max_length < 0) {
        $max_length = 0;
    }

    return blc_truncate_for_storage($html, $max_length, false);
}

/**
 * Prepare a textual excerpt surrounding a link for storage.
 *
 * @param string $text Raw text captured near the link.
 *
 * @return string Clean excerpt limited to a reasonable size.
 */
function blc_prepare_context_excerpt_for_storage($text) {
    $text = trim((string) $text);

    if ($text === '') {
        return '';
    }

    if (function_exists('wp_strip_all_tags')) {
        $stripped = wp_strip_all_tags($text);
        if (is_string($stripped)) {
            $text = trim($stripped);
        }
    }

    $max_length = (int) apply_filters('blc_context_excerpt_max_length', 400);
    if ($max_length < 0) {
        $max_length = 0;
    }

    return blc_truncate_for_storage($text, $max_length, true);
}

/**
 * Determine the final URL reached by a remote request.
 *
 * @param array|\WP_Error $response    Response returned by wp_remote_*.
 * @param string          $fallback_url URL to use when no redirect target is available.
 *
 * @return string
 */
function blc_determine_response_target_url($response, $fallback_url) {
    $target = is_string($fallback_url) ? $fallback_url : '';

    if (is_wp_error($response)) {
        return $target;
    }

    $candidate = null;

    if (function_exists('wp_remote_retrieve_header')) {
        $location_header = wp_remote_retrieve_header($response, 'location');
        if (is_array($location_header) && !empty($location_header)) {
            $location_header = end($location_header);
        }

        if (is_string($location_header) && $location_header !== '') {
            $candidate = $location_header;
        }
    }

    if ($candidate === null && isset($response['http_response']) && is_object($response['http_response'])) {
        $http_response = $response['http_response'];

        if (method_exists($http_response, 'get_response_object')) {
            $requests_response = $http_response->get_response_object();
            if (is_object($requests_response) && isset($requests_response->url)) {
                $maybe_url = (string) $requests_response->url;
                if ($maybe_url !== '') {
                    $candidate = $maybe_url;
                }
            }
        } elseif (method_exists($http_response, 'get_headers')) {
            $headers = $http_response->get_headers();
            if (is_object($headers) && method_exists($headers, 'getValues')) {
                $locations = $headers->getValues('location');
                if (is_array($locations) && !empty($locations)) {
                    $candidate = (string) end($locations);
                }
            } elseif (is_array($headers) && isset($headers['location'])) {
                $location_value = $headers['location'];
                if (is_array($location_value) && !empty($location_value)) {
                    $candidate = (string) end($location_value);
                } elseif (is_string($location_value) && $location_value !== '') {
                    $candidate = $location_value;
                }
            }
        }
    }

    if (!is_string($candidate) || $candidate === '') {
        $candidate = $target;
    }

    return (string) $candidate;
}


/**
 * Calculate the approximate storage footprint of a row based on its columns.
 *
 * @param string|null $url        URL stored for the broken item.
 * @param string|null $anchor     Anchor text associated with the link/image.
 * @param string|null $post_title Title of the post where the item was found.
 *
 * @return int Number of bytes used by the provided fields.
 */
function blc_calculate_row_storage_footprint_bytes($url, $anchor = null, $post_title = null, $context_html = null, $context_excerpt = null) {
    $bytes = 0;
    foreach ([$url, $anchor, $post_title, $context_html, $context_excerpt] as $field) {
        if ($field === null) {
            continue;
        }

        $bytes += strlen((string) $field);
    }

    return $bytes;
}

/**
 * Build the option name used to cache dataset sizes.
 *
 * @param string $type Dataset type (link/image).
 *
 * @return string
 */
function blc_get_dataset_size_cache_key($type) {
    if (!function_exists('sanitize_key')) {
        return '';
    }

    $normalized = sanitize_key($type);

    if ($normalized === '') {
        return '';
    }

    return 'blc_dataset_size_' . $normalized;
}

/**
 * Persist the dataset footprint in the options table.
 *
 * @param string $type  Dataset type identifier.
 * @param int    $bytes Footprint in bytes.
 */
function blc_set_dataset_storage_footprint($type, $bytes) {
    $option_name = blc_get_dataset_size_cache_key($type);
    if ($option_name === '') {
        return;
    }

    $bytes = max(0, (int) $bytes);
    update_option($option_name, $bytes, false);
}

/**
 * Retrieve the cached dataset footprint in bytes.
 *
 * @param string $type Dataset type stored in the blc_broken_links table.
 *
 * @return int
 */
function blc_get_dataset_storage_footprint_bytes($type) {
    $option_name = blc_get_dataset_size_cache_key($type);
    if ($option_name === '') {
        return 0;
    }

    $stored = get_option($option_name, null);
    if ($stored !== null && $stored !== false && is_numeric($stored)) {
        return max(0, (int) $stored);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'blc_broken_links';

    $row_types = blc_get_dataset_row_types($type);
    if ($row_types === []) {
        return 0;
    }

    $dataset_type = strtolower((string) $type);
    $ignored_filter = ($dataset_type === 'link') ? ' AND ignored_at IS NULL' : '';

    if (count($row_types) === 1) {
        $size = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(COALESCE(LENGTH(url), 0) + COALESCE(LENGTH(anchor), 0) + COALESCE(LENGTH(post_title), 0))
                 FROM $table_name
                 WHERE type = %s" . $ignored_filter,
                reset($row_types)
            )
        );
    } else {
        $placeholders = implode(',', array_fill(0, count($row_types), '%s'));
        $query = $wpdb->prepare(
            "SELECT SUM(COALESCE(LENGTH(url), 0) + COALESCE(LENGTH(anchor), 0) + COALESCE(LENGTH(post_title), 0))
             FROM $table_name
             WHERE type IN ($placeholders)" . $ignored_filter,
            $row_types
        );
        $size = (int) $wpdb->get_var($query);
    }

    blc_set_dataset_storage_footprint($type, $size);

    return $size;
}

/**
 * Adjust the cached dataset footprint by the provided delta.
 *
 * @param string $type  Dataset type identifier.
 * @param int    $delta Positive or negative delta to apply.
 */
function blc_adjust_dataset_storage_footprint($type, $delta) {
    $option_name = blc_get_dataset_size_cache_key($type);
    if ($option_name === '' || !function_exists('get_option') || !function_exists('update_option')) {
        return;
    }

    $cached = get_option($option_name, 0);
    if (!is_numeric($cached)) {
        $cached = 0;
    }

    $new_value = (int) $cached + (int) $delta;
    if ($new_value < 0) {
        $new_value = 0;
    }

    update_option($option_name, $new_value, false);
}

/**
 * Clear cached dataset footprints.
 *
 * @param string|string[]|null $types Dataset types to clear. Null clears the default set.
 */
function blc_flush_dataset_size_cache($types = null) {
    static $flushed = [];

    if ($types === null) {
        $types = ['link', 'image'];
    }

    if (!is_array($types)) {
        $types = [$types];
    }

    foreach ($types as $type) {
        $option_name = blc_get_dataset_size_cache_key($type);
        if ($option_name === '') {
            continue;
        }

        if (isset($flushed[$option_name])) {
            continue;
        }

        delete_option($option_name);
        $flushed[$option_name] = true;
    }
}

/**
 * Determine metadata stored alongside a broken URL entry.
 *
 * @param string      $original_url   Raw URL captured before normalization.
 * @param string|null $normalized_url URL normalized for validation.
 * @param string      $site_host      Lowercase host of the current site.
 *
 * @return array{host: string, is_internal: int}
 */
function blc_get_url_metadata_for_storage($original_url, $normalized_url, $site_host) {
    $candidate = '';

    if (is_string($normalized_url) && $normalized_url !== '') {
        $candidate = $normalized_url;
    } elseif (is_string($original_url) && $original_url !== '') {
        $candidate = $original_url;
    }

    $host = '';
    $is_internal = 0;

    if ($candidate !== '') {
        $parser = function_exists('wp_parse_url') ? 'wp_parse_url' : 'parse_url';
        $parts = $parser($candidate);

        if (is_array($parts)) {
            if (!empty($parts['host'])) {
                $host = strtolower((string) $parts['host']);
                if ($site_host !== '' && $host === $site_host) {
                    $is_internal = 1;
                }
            } elseif (isset($parts['path']) && is_string($parts['path']) && strpos($parts['path'], '/') === 0) {
                $is_internal = 1;
            }
        }
    }

    $host_for_storage = '';
    if ($host !== '') {
        $max_host_length = defined('BLC_URL_HOST_LENGTH') ? (int) BLC_URL_HOST_LENGTH : 191;
        $host_for_storage = blc_truncate_for_storage($host, $max_host_length, false);
    }

    return [
        'host'        => $host_for_storage,
        'is_internal' => $is_internal,
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

/**
 * Retrieve the timeout constraints used for remote requests.
 *
 * @return array{
 *     head: array{default: float, min: float, max: float},
 *     get: array{default: float, min: float, max: float}
 * }
 */
function blc_get_request_timeout_constraints() {
    $defaults = [
        'head' => [
            'default' => 5.0,
            'min'     => 1.0,
            'max'     => 30.0,
        ],
        'get'  => [
            'default' => 10.0,
            'min'     => 1.0,
            'max'     => 60.0,
        ],
    ];

    $candidates = apply_filters('blc_request_timeout_constraints', $defaults);
    if (!is_array($candidates)) {
        $candidates = [];
    }

    $normalized = [];

    foreach ($defaults as $type => $definition) {
        $candidate = isset($candidates[$type]) && is_array($candidates[$type]) ? $candidates[$type] : [];

        $default = isset($candidate['default']) ? (float) $candidate['default'] : $definition['default'];
        $min     = isset($candidate['min']) ? (float) $candidate['min'] : $definition['min'];
        $max     = isset($candidate['max']) ? (float) $candidate['max'] : $definition['max'];

        if (!is_finite($default)) {
            $default = $definition['default'];
        }

        if (!is_finite($min)) {
            $min = $definition['min'];
        }

        if (!is_finite($max)) {
            $max = $definition['max'];
        }

        if ($min > $max) {
            $tmp = $min;
            $min = $max;
            $max = $tmp;
        }

        if ($default < $min) {
            $default = $min;
        } elseif ($default > $max) {
            $default = $max;
        }

        $normalized[$type] = [
            'default' => (float) $default,
            'min'     => (float) $min,
            'max'     => (float) $max,
        ];
    }

    return $normalized;
}

/**
 * Retrieve the constraints applied to the link scan batch size.
 *
 * @return array{default: int, min: int, max: int}
 */
function blc_get_link_batch_size_constraints() {
    $defaults = [
        'default' => 20,
        'min'     => 5,
        'max'     => 200,
    ];

    $candidates = apply_filters('blc_link_batch_size_constraints', $defaults);
    if (!is_array($candidates)) {
        $candidates = [];
    }

    $default = isset($candidates['default']) ? (int) $candidates['default'] : $defaults['default'];
    $min     = isset($candidates['min']) ? (int) $candidates['min'] : $defaults['min'];
    $max     = isset($candidates['max']) ? (int) $candidates['max'] : $defaults['max'];

    if ($min > $max) {
        $tmp = $min;
        $min = $max;
        $max = $tmp;
    }

    if ($default < $min) {
        $default = $min;
    } elseif ($default > $max) {
        $default = $max;
    }

    return [
        'default' => $default,
        'min'     => $min,
        'max'     => $max,
    ];
}

/**
 * Normalize the batch size used during link scans.
 *
 * @param mixed $value Raw batch size value.
 *
 * @return int
 */
function blc_normalize_link_batch_size($value) {
    $constraints = blc_get_link_batch_size_constraints();

    $default = isset($constraints['default']) ? (int) $constraints['default'] : 20;
    $min     = isset($constraints['min']) ? (int) $constraints['min'] : 1;
    $max     = isset($constraints['max']) ? (int) $constraints['max'] : max($min, $default);

    $sanitized = is_scalar($value) ? (int) $value : $default;

    if ($sanitized < $min) {
        $sanitized = $min;
    } elseif ($sanitized > $max) {
        $sanitized = $max;
    }

    return $sanitized;
}

/**
 * Normalize a timeout option coming from a settings form.
 *
 * @param mixed $value   Raw submitted value.
 * @param float $default Fallback value when the input is invalid.
 * @param float $min     Minimum allowed timeout in seconds.
 * @param float $max     Maximum allowed timeout in seconds.
 *
 * @return float Timeout value between $min and $max.
 */
function blc_normalize_timeout_option($value, $default, $min, $max) {
    $default = (float) $default;
    $min     = (float) $min;
    $max     = (float) $max;

    if ($min > $max) {
        $tmp = $min;
        $min = $max;
        $max = $tmp;
    }

    $candidate = $default;

    if (is_scalar($value)) {
        $value_string = trim((string) $value);
        if ($value_string !== '') {
            $value_string = str_replace(',', '.', $value_string);
            if (is_numeric($value_string)) {
                $candidate = (float) $value_string;
            }
        }
    }

    if (!is_finite($candidate)) {
        $candidate = $default;
    }

    if (!is_finite($min)) {
        $min = $default;
    }

    if (!is_finite($max)) {
        $max = $default;
    }

    if ($min === $max) {
        return (float) $min;
    }

    if ($candidate < $min) {
        $candidate = $min;
    }

    if ($candidate > $max) {
        $candidate = $max;
    }

    return (float) $candidate;
}

/**
 * Check whether a resolved IP address is considered public and safe for remote requests.
 *
 * @param string $ip Raw IP address.
 *
 * @return bool True when the IP is public, false otherwise.
 */
function blc_is_public_ip_address($ip) {
    $ip = trim((string) $ip);
    if ($ip === '') {
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
        return false;
    }

    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }

    if (strlen($packed) === 4) {
        return blc_is_public_ipv4($ip);
    }

    return blc_is_public_ipv6($ip, $packed);
}

/**
 * Determine if an IPv4 address falls outside additional blocked ranges.
 *
 * @param string $ip IPv4 address.
 *
 * @return bool
 */
function blc_is_public_ipv4($ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }

    $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
        return false;
    }

    $parts = array_map('intval', explode('.', $ip));
    if (count($parts) !== 4) {
        return false;
    }

    if ($parts[0] === 169 && $parts[1] === 254) {
        return false; // Link-local range 169.254.0.0/16.
    }

    if ($parts[0] === 100 && $parts[1] >= 64 && $parts[1] <= 127) {
        return false; // CGNAT / carrier-grade NAT range 100.64.0.0/10.
    }

    return true;
}

/**
 * Determine if an IPv6 address falls outside additional blocked ranges.
 *
 * @param string   $ip     IPv6 address.
 * @param string|null $packed Optional packed representation returned by inet_pton().
 *
 * @return bool
 */
function blc_is_public_ipv6($ip, $packed = null) {
    if ($packed === null) {
        $packed = @inet_pton($ip);
    }

    if ($packed === false || strlen($packed) !== 16) {
        return false;
    }

    $canonical = strtolower((string) @inet_ntop($packed));
    if ($canonical === '::1') {
        return false; // IPv6 loopback.
    }

    $words = unpack('n*', $packed);
    if (!is_array($words) || count($words) < 8) {
        return false;
    }

    if (($words[1] & 0xFFC0) === 0xFE80) {
        return false; // IPv6 link-local fe80::/10.
    }

    $is_ipv4_mapped = (
        $words[1] === 0 &&
        $words[2] === 0 &&
        $words[3] === 0 &&
        $words[4] === 0 &&
        $words[5] === 0 &&
        $words[6] === 0xFFFF
    );

    if ($is_ipv4_mapped) {
        $mapped_ipv4 = @inet_ntop(substr($packed, 12, 4));
        if ($mapped_ipv4 === false) {
            return false;
        }

        return blc_is_public_ipv4($mapped_ipv4);
    }

    return true;
}

/**
 * Normalize a remote host representation for consistent lookups and caching.
 *
 * @param string $host Raw host string extracted from a URL.
 * @return string Normalized host suitable for comparisons and DNS queries.
 */
function blc_normalize_remote_host($host) {
    $host = trim((string) $host);
    if ($host === '') {
        return '';
    }

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $host;
    }

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $packed = @inet_pton($host);
        if ($packed !== false) {
            $normalized = @inet_ntop($packed);
            if (is_string($normalized) && $normalized !== '') {
                return strtolower($normalized);
            }
        }

        return strtolower($host);
    }

    $decoded_host = $host;

    if (function_exists('wp_specialchars_decode')) {
        $decoded = wp_specialchars_decode($decoded_host);
        if (is_string($decoded) && $decoded !== '') {
            $decoded_host = $decoded;
        }
    }

    $ascii_host = $decoded_host;

    if (
        function_exists('idn_to_ascii') &&
        $decoded_host !== '' &&
        preg_match('/[^\x00-\x7F]/', $decoded_host) === 1
    ) {
        if (defined('INTL_IDNA_VARIANT_UTS46')) {
            $converted = @idn_to_ascii($decoded_host, 0, INTL_IDNA_VARIANT_UTS46);
        } else {
            $converted = @idn_to_ascii($decoded_host);
        }

        if (is_string($converted) && $converted !== '') {
            $ascii_host = $converted;
        }
    }

    return strtolower($ascii_host);
}

/**
 * Validate that the provided host resolves to public IP addresses.
 *
 * @param string $host Hostname or IP address extracted from the URL.
 *
 * @return bool True when every resolved IP is public.
 *
 * @filter blc_safe_remote_host_cache_ttl int Filter the cache TTL (in seconds) applied when
 *         storing safe remote host results via wp_cache_set(). The default of 0 keeps entries
 *         in memory for the duration of the request only.
 */
function blc_is_safe_remote_host($host, $allowed_hosts = null) {
    $normalized_host = blc_normalize_remote_host($host);
    if ($normalized_host === '') {
        return false;
    }

    $allowed_lookup = [];
    if ($allowed_hosts !== null) {
        if (!is_array($allowed_hosts)) {
            $allowed_hosts = [$allowed_hosts];
        }

        foreach ($allowed_hosts as $candidate_host) {
            $normalized_candidate = blc_normalize_remote_host($candidate_host);
            if ($normalized_candidate === '') {
                continue;
            }

            $allowed_lookup[$normalized_candidate] = true;
        }

        if ($allowed_lookup !== [] && !isset($allowed_lookup[$normalized_host])) {
            return false;
        }
    }

    static $in_process_cache = [];
    if (array_key_exists($normalized_host, $in_process_cache)) {
        return $in_process_cache[$normalized_host];
    }

    $cache_group = 'blc_safe_remote_host';
    $store_cache = static function ($result) use (&$in_process_cache, $normalized_host, $host, $cache_group): bool {
        $bool_result = (bool) $result;
        $in_process_cache[$normalized_host] = $bool_result;

        if (function_exists('wp_cache_set')) {
            $ttl = function_exists('apply_filters')
                ? apply_filters('blc_safe_remote_host_cache_ttl', 0, $normalized_host, $host)
                : 0;
            if (!is_int($ttl)) {
                $ttl = (int) $ttl;
            }
            if ($ttl < 0) {
                $ttl = 0;
            }

            wp_cache_set($normalized_host, ['result' => $bool_result], $cache_group, $ttl);
        }

        return $bool_result;
    };

    if (function_exists('wp_cache_get')) {
        $found = null;
        $cached_value = wp_cache_get($normalized_host, $cache_group, false, $found);
        if ($found && is_array($cached_value) && array_key_exists('result', $cached_value)) {
            $result = (bool) $cached_value['result'];
            $in_process_cache[$normalized_host] = $result;
            return $result;
        }
    }

    $lookup_host = $normalized_host;
    $ip_addresses = [];

    if (filter_var($lookup_host, FILTER_VALIDATE_IP)) {
        $ip_addresses[] = $lookup_host;
    } else {
        if (function_exists('dns_get_record')) {
            $all_records = null;

            $collect_ipv4 = static function (array $records) use (&$ip_addresses): void {
                foreach ($records as $record) {
                    $ip = isset($record['ip']) ? trim((string) $record['ip']) : '';
                    if ($ip !== '') {
                        $ip_addresses[] = $ip;
                        continue;
                    }

                    $type = isset($record['type']) ? strtoupper((string) $record['type']) : '';
                    if ($type === 'A' && isset($record['target'])) {
                        $target_ip = trim((string) $record['target']);
                        if (filter_var($target_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            $ip_addresses[] = $target_ip;
                        }
                    }
                }
            };

            $collect_ipv6 = static function (array $records) use (&$ip_addresses): bool {
                $added = false;

                foreach ($records as $record) {
                    $ipv6 = isset($record['ipv6']) ? trim((string) $record['ipv6']) : '';

                    if ($ipv6 === '') {
                        $type = isset($record['type']) ? strtoupper((string) $record['type']) : '';
                        if ($type === 'AAAA' && isset($record['target'])) {
                            $candidate = trim((string) $record['target']);
                            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                                $ipv6 = $candidate;
                            }
                        }
                    }

                    if ($ipv6 !== '') {
                        $ip_addresses[] = $ipv6;
                        $added      = true;
                    }
                }

                return $added;
            };

            if (defined('DNS_A')) {
                $records = @dns_get_record($lookup_host, DNS_A);
                if (is_array($records)) {
                    $collect_ipv4($records);
                }
            } else {
                $all_records = @dns_get_record($lookup_host);
                if (!is_array($all_records)) {
                    $all_records = [];
                }

                $collect_ipv4($all_records);
            }

            $found_ipv6 = false;

            if (defined('DNS_AAAA')) {
                $records = @dns_get_record($lookup_host, DNS_AAAA);
                if (is_array($records)) {
                    $found_ipv6 = $collect_ipv6($records);
                }
            }

            if (!$found_ipv6) {
                if ($all_records === null) {
                    $all_records = @dns_get_record($lookup_host);
                    if (!is_array($all_records)) {
                        $all_records = [];
                    }
                }

                if (!empty($all_records)) {
                    $collect_ipv6($all_records);
                }
            }
        }

        if (empty($ip_addresses)) {
            $ipv4_records = @gethostbynamel($lookup_host);
            if (is_array($ipv4_records)) {
                foreach ($ipv4_records as $ip) {
                    if ($ip !== '') {
                        $ip_addresses[] = $ip;
                    }
                }
            }
        }
    }

    if (empty($ip_addresses)) {
        return $store_cache(false);
    }

    $ip_addresses = array_unique($ip_addresses);

    foreach ($ip_addresses as $ip) {
        if (!blc_is_public_ip_address($ip)) {
            return $store_cache(false);
        }
    }

    return $store_cache(true);
}

/**
 * Map a logical dataset type to the stored row types.
 *
 * @param string $dataset_type Dataset identifier (e.g. 'link', 'image').
 * @return string[]
 */
function blc_get_dataset_row_types($dataset_type) {
    $normalized = strtolower((string) $dataset_type);

    if ($normalized === 'image') {
        $types = ['image', 'remote-image'];
    } elseif ($normalized === 'link') {
        $types = ['link', 'iframe', 'script', 'stylesheet', 'form', 'css-background'];
    } elseif ($normalized === '') {
        $types = [];
    } else {
        $types = [$normalized];
    }

    if (function_exists('apply_filters')) {
        $types = apply_filters('blc_dataset_row_types', $types, $normalized);
    }

    $types = array_filter(
        array_map(
            static function ($type) {
                return is_string($type) ? trim($type) : '';
            },
            (array) $types
        ),
        static function ($value) {
            return $value !== '';
        }
    );

    return array_values(array_unique($types));
}

/**
 * Sanitize a URL received from user input while preserving scheme-relative prefixes.
 *
 * The plugin stores the original representation of scanned links, including URLs starting
 * with "//". WordPress' default esc_url_raw() helper prepends the site scheme to such
 * values, preventing XPath lookups from matching the original attribute. This helper keeps
 * the raw prefix intact while removing leading/trailing whitespace and decoding HTML
 * entities.
 *
 * @param string $url Raw URL submitted through an AJAX request.
 * @return string Sanitized URL suitable for DOM queries and database lookups.
 */
function blc_prepare_posted_url($url) {
    $url = wp_kses_decode_entities((string) $url);

    return trim($url);
}


/**
 * Canonicalize a user-submitted URL for safe comparisons.
 *
 * This helper normalizes the scheme casing, lowercases the host for absolute
 * and scheme-relative URLs, and lowercases bare domains while leaving relative
 * paths untouched. It allows the plugin to compare the "raw" value entered by
 * the user with the sanitized version produced by WordPress without rejecting
 * innocuous changes such as host lowercasing.
 *
 * @param string $url URL to normalize.
 *
 * @return string Normalized representation suitable for string comparisons.
 */
function blc_normalize_user_input_url($url) {
    $normalized = blc_normalize_url_scheme_case(blc_prepare_posted_url($url));

    if ($normalized === '') {
        return '';
    }

    $scheme_relative = false;
    $candidate_for_parsing = $normalized;
    if (strpos($normalized, '//') === 0) {
        $scheme_relative       = true;
        $candidate_for_parsing = 'placeholder:' . $normalized;
    }

    $parts = parse_url($candidate_for_parsing);
    if (is_array($parts) && isset($parts['host']) && $parts['host'] !== '') {
        $scheme = '';
        if ($scheme_relative) {
            $scheme = '//';
        } elseif (isset($parts['scheme']) && $parts['scheme'] !== '') {
            $scheme = strtolower($parts['scheme']) . '://';
        }

        $user_info = '';
        if (isset($parts['user']) && $parts['user'] !== '') {
            $user_info = $parts['user'];
            if (isset($parts['pass']) && $parts['pass'] !== '') {
                $user_info .= ':' . $parts['pass'];
            }

            $user_info .= '@';
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        $path      = $parts['path'] ?? '';
        $query     = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment  = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $user_info . $host . $port . $path . $query . $fragment;
    }

    if (blc_url_looks_like_bare_domain($normalized)) {
        return strtolower($normalized);
    }

    return $normalized;
}


/**
 * Normalize the scheme casing of a URL while leaving the rest untouched.
 *
 * @param string $url Raw URL to normalize.
 *
 * @return string
 */
function blc_normalize_url_scheme_case($url) {
    if (!is_string($url) || $url === '') {
        return $url;
    }

    if (preg_match('#^([a-z0-9+.-]+):(.*)$#i', $url, $matches)) {
        return strtolower($matches[1]) . ':' . $matches[2];
    }

    return $url;
}


/**
 * Detect if the provided URL resembles a bare domain without scheme.
 *
 * @param string $url Raw URL to inspect.
 *
 * @return bool
 */
function blc_url_looks_like_bare_domain($url) {
    $trimmed = ltrim((string) $url);
    if ($trimmed === '') {
        return false;
    }

    if (preg_match('#^[a-z0-9+.-]+://#i', $trimmed) === 1) {
        return false;
    }

    if (strncmp($trimmed, '//', 2) === 0) {
        $trimmed = substr($trimmed, 2);
    }

    $parsed = parse_url('http://' . $trimmed);
    if (!is_array($parsed) || !isset($parsed['host']) || $parsed['host'] === '') {
        return false;
    }

    if (strpos($parsed['host'], '.') === false) {
        return false;
    }

    $host    = $parsed['host'];
    $lastDot = strrpos($host, '.');
    $tld     = $lastDot !== false ? substr($host, $lastDot + 1) : '';

    if ($tld === '' || preg_match('/^[A-Za-z]{2,}$/', $tld) !== 1) {
        return false;
    }

    return true;
}
