<?php
/**
 * Utilities for caching dashboard statistics.
 */

// Sécurité : empêche l'accès direct au fichier.
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_get_top_domain_cache_version')) {
    /**
     * Retrieve the current cache version used to namespace dashboard cache keys.
     *
     * @return int
     */
    function blc_get_top_domain_cache_version() {
        if (!function_exists('get_option')) {
            return 1;
        }

        $raw_version = get_option('blc_top_domain_cache_version', 1);
        if (!is_numeric($raw_version)) {
            $raw_version = 1;
        }

        $version = max(1, (int) $raw_version);

        if ($version !== (int) $raw_version && function_exists('update_option')) {
            update_option('blc_top_domain_cache_version', $version, false);
        }

        return $version;
    }
}

if (!function_exists('blc_bump_top_domain_cache_version')) {
    /**
     * Increment the cache version, invalidating any previously stored values.
     *
     * @return int The new cache version.
     */
    function blc_bump_top_domain_cache_version() {
        if (!function_exists('update_option')) {
            return 1;
        }

        $previous_version = blc_get_top_domain_cache_version();
        $next_version     = $previous_version + 1;

        update_option('blc_top_domain_cache_version', $next_version, false);

        if (function_exists('do_action')) {
            /**
             * Fires when the dashboard top domains cache version changes.
             *
             * @param int $next_version     New cache version.
             * @param int $previous_version Previous cache version.
             */
            do_action('blc_top_domain_cache_version_changed', $next_version, $previous_version);
        }

        return $next_version;
    }
}

if (!function_exists('blc_get_top_domain_cache_ttl')) {
    /**
     * Determine the lifetime for cached dashboard data.
     *
     * @param int $limit Requested number of domains.
     *
     * @return int Lifetime in seconds.
     */
    function blc_get_top_domain_cache_ttl($limit) {
        $default_ttl = defined('MINUTE_IN_SECONDS') ? 5 * MINUTE_IN_SECONDS : 300;

        if (function_exists('apply_filters')) {
            $filtered_ttl = apply_filters('blc_top_domain_cache_ttl', $default_ttl, (int) $limit);
            if (is_numeric($filtered_ttl)) {
                $default_ttl = (int) $filtered_ttl;
            }
        }

        if ($default_ttl < 0) {
            $default_ttl = 0;
        }

        return $default_ttl;
    }
}

if (!function_exists('blc_get_top_domain_cache_keys')) {
    /**
     * Build cache identifiers for the requested limit and current version.
     *
     * @param int $limit
     *
     * @return array{cache_key:string,transient_key:string}
     */
    function blc_get_top_domain_cache_keys($limit) {
        $version      = blc_get_top_domain_cache_version();
        $normalized   = max(1, (int) $limit);
        $cache_key    = sprintf('top_domains_v%d_limit_%d', $version, $normalized);
        $transient_key = 'blc_' . $cache_key;

        return array(
            'cache_key'     => $cache_key,
            'transient_key' => $transient_key,
        );
    }
}

if (!function_exists('blc_get_cached_top_domain_stats')) {
    /**
     * Retrieve cached dashboard domain statistics if available.
     *
     * @param int $limit
     *
     * @return array<int, array<string, mixed>>|null
     */
    function blc_get_cached_top_domain_stats($limit) {
        $keys = blc_get_top_domain_cache_keys($limit);

        if (function_exists('wp_cache_get')) {
            $found  = false;
            $cached = wp_cache_get($keys['cache_key'], 'blc_dashboard', false, $found);
            if ($found) {
                return is_array($cached) ? $cached : array();
            }
        }

        if (function_exists('get_transient')) {
            $cached = get_transient($keys['transient_key']);
            if ($cached !== false) {
                return is_array($cached) ? $cached : array();
            }
        }

        return null;
    }
}

if (!function_exists('blc_store_top_domain_stats_cache')) {
    /**
     * Persist dashboard domain statistics in cache backends.
     *
     * @param int                                  $limit
     * @param array<int, array<string, mixed>>     $domains
     *
     * @return void
     */
    function blc_store_top_domain_stats_cache($limit, array $domains) {
        $keys = blc_get_top_domain_cache_keys($limit);
        $ttl  = blc_get_top_domain_cache_ttl($limit);

        if (function_exists('wp_cache_set')) {
            wp_cache_set($keys['cache_key'], $domains, 'blc_dashboard', $ttl);
        }

        if (function_exists('set_transient')) {
            set_transient($keys['transient_key'], $domains, $ttl);
        }
    }
}

if (!function_exists('blc_invalidate_top_domain_stats_cache')) {
    /**
     * Invalidate cached dashboard statistics by bumping the version namespace.
     *
     * @return int The new cache version.
     */
    function blc_invalidate_top_domain_stats_cache() {
        return blc_bump_top_domain_cache_version();
    }
}
