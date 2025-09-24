<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convert HTML content from an arbitrary charset to UTF-8.
 *
 * DOMDocument expects UTF-8 when the pseudo XML header declares it. This helper attempts
 * to perform a best-effort conversion using multibyte functions when available and falls
 * back to iconv before returning the original string as a last resort.
 *
 * @param string $html            Raw HTML fragment to convert.
 * @param string $source_charset  Charset the HTML fragment is encoded in.
 *
 * @return string HTML encoded in UTF-8 when possible.
 */
function blc_convert_html_to_utf8($html, $source_charset = 'UTF-8') {
    $html = (string) $html;

    $source_charset = is_string($source_charset) ? trim($source_charset) : '';
    if ($source_charset === '') {
        $source_charset = 'UTF-8';
    }

    if (strcasecmp($source_charset, 'UTF-8') !== 0) {
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $source_charset);
            if ($converted !== false) {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv($source_charset, 'UTF-8//IGNORE', $html);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $html;
    }

    if (function_exists('mb_check_encoding') && !@mb_check_encoding($html, 'UTF-8')) {
        $converted = @mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        if ($converted !== false) {
            return $converted;
        }
    }

    return $html;
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

    $converted_content = blc_convert_html_to_utf8($post_content, $source_charset);

    $loaded = $dom->loadHTML('<?xml encoding="UTF-8"?>' . $converted_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
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
 * @param string $html            Original HTML content.
 * @param string $target_href     Href attribute to search for (should already be sanitized).
 * @param string $replacement_href Replacement value for the href attribute.
 *
 * @return array{content: string, updated: bool} Updated HTML content and whether at least one link was modified.
 */
function blc_replace_link_href_in_content($html, $target_href, $replacement_href) {
    $html            = (string) $html;
    $target_href     = blc_prepare_posted_url($target_href);
    $replacement_href = (string) $replacement_href;

    if ($target_href === '' || $replacement_href === '') {
        return ['content' => $html, 'updated' => false];
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
        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href_value = blc_prepare_posted_url($link->getAttribute('href'));
            if ($href_value !== $target_href) {
                continue;
            }

            $link->setAttribute('href', $replacement_href);
            $updated = true;
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
 * @param string $html        Original HTML content.
 * @param string $target_href Href attribute to search for (should already be sanitized).
 *
 * @return array{content: string, removed: bool} Updated HTML content and whether at least one link was removed.
 */
function blc_remove_link_wrappers_from_content($html, $target_href) {
    $html        = (string) $html;
    $target_href = blc_prepare_posted_url($target_href);

    if ($target_href === '') {
        return ['content' => $html, 'removed' => false];
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

        foreach ($anchors as $anchor) {
            $href_value = blc_prepare_posted_url($anchor->getAttribute('href'));
            if ($href_value !== $target_href) {
                continue;
            }

            while ($anchor->firstChild) {
                $anchor->parentNode->insertBefore($anchor->firstChild, $anchor);
            }

            $anchor->parentNode->removeChild($anchor);
            $removed = true;
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
 * Build the transient key used to cache dataset sizes.
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
 * Retrieve the cached dataset footprint in bytes.
 *
 * @param string $type Dataset type stored in the blc_broken_links table.
 *
 * @return int
 */
function blc_get_dataset_storage_footprint_bytes($type) {
    $cache_key = blc_get_dataset_size_cache_key($type);
    if ($cache_key === '') {
        return 0;
    }

    if (function_exists('get_transient')) {
        $cached = get_transient($cache_key);
        if ($cached !== false && is_numeric($cached)) {
            return (int) $cached;
        }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'blc_broken_links';

    $size = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(COALESCE(LENGTH(url), 0) + COALESCE(LENGTH(anchor), 0) + COALESCE(LENGTH(post_title), 0))
             FROM $table_name
             WHERE type = %s",
            $type
        )
    );

    if (function_exists('set_transient')) {
        $expiration = defined('MINUTE_IN_SECONDS') ? (int) MINUTE_IN_SECONDS * 10 : 600;
        set_transient($cache_key, $size, $expiration);
    }

    return $size;
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
        $cache_key = blc_get_dataset_size_cache_key($type);
        if ($cache_key === '') {
            continue;
        }

        if (isset($flushed[$cache_key])) {
            continue;
        }

        if (function_exists('delete_transient')) {
            delete_transient($cache_key);
        }

        $flushed[$cache_key] = true;
    }
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
 * Validate that the provided host resolves to public IP addresses.
 *
 * @param string $host Hostname or IP address extracted from the URL.
 *
 * @return bool True when every resolved IP is public.
 */
function blc_is_safe_remote_host($host) {
    $host = trim((string) $host);
    if ($host === '') {
        return false;
    }

    static $cache = [];

    $cache_key = function_exists('mb_strtolower')
        ? mb_strtolower($host, 'UTF-8')
        : strtolower($host);

    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $ip_addresses = [];

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ip_addresses[] = $host;
    } else {
        $lookup_host = $host;

        if (function_exists('idn_to_ascii')) {
            $decoded_host = $host;

            if (function_exists('wp_specialchars_decode')) {
                $decoded = wp_specialchars_decode($decoded_host);
                if (is_string($decoded) && $decoded !== '') {
                    $decoded_host = $decoded;
                }
            }

            if ($decoded_host !== '' && preg_match('/[^\x00-\x7F]/', $decoded_host) === 1) {
                if (defined('INTL_IDNA_VARIANT_UTS46')) {
                    $converted = @idn_to_ascii($decoded_host, 0, INTL_IDNA_VARIANT_UTS46);
                } else {
                    $converted = @idn_to_ascii($decoded_host);
                }

                if (is_string($converted) && $converted !== '') {
                    $lookup_host = $converted;
                }
            }
        }

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
        $cache[$cache_key] = false;
        return false;
    }

    $ip_addresses = array_unique($ip_addresses);

    foreach ($ip_addresses as $ip) {
        if (!blc_is_public_ip_address($ip)) {
            $cache[$cache_key] = false;
            return false;
        }
    }

    $cache[$cache_key] = true;
    return true;
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
