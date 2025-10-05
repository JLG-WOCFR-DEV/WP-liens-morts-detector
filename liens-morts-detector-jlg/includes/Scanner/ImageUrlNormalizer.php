<?php

if (!class_exists('BlcImageUrlNormalizer')) {
    class BlcImageUrlNormalizer {
        /**
         * @var string
         */
        private $homeUrlWithTrailingSlash;

        /**
         * @var string
         */
        private $siteScheme;

        /**
         * @var string
         */
        private $normalizedSiteHost;

        /**
         * @var string
         */
        private $uploadBaseurlHost;

        /**
         * @var string
         */
        private $uploadBaseurl;

        /**
         * @var string
         */
        private $uploadBasedir;

        /**
         * @var string
         */
        private $normalizedBasedir;

        /**
         * @var bool
         */
        private $remoteImageScanEnabled;

        /**
         * @var bool
         */
        private $debugMode;

        public function __construct(
            $home_url_with_trailing_slash,
            $site_scheme,
            $normalized_site_host,
            $upload_baseurl_host,
            $upload_baseurl,
            $upload_basedir,
            $normalized_basedir,
            $remote_image_scan_enabled,
            $debug_mode
        ) {
            $this->homeUrlWithTrailingSlash = (string) $home_url_with_trailing_slash;
            $this->siteScheme = (string) $site_scheme;
            $this->normalizedSiteHost = (string) $normalized_site_host;
            $this->uploadBaseurlHost = (string) $upload_baseurl_host;
            $this->uploadBaseurl = (string) $upload_baseurl;
            $this->uploadBasedir = (string) $upload_basedir;
            $this->normalizedBasedir = (string) $normalized_basedir;
            $this->remoteImageScanEnabled = (bool) $remote_image_scan_enabled;
            $this->debugMode = (bool) $debug_mode;
        }

        /**
         * Normalize a candidate image URL and return path metadata.
         *
         * @param string $candidate_url Raw URL extracted from the DOM.
         * @param string $permalink     Permalink of the scanned post.
         *
         * @return array<string, mixed>|null
         */
        public function normalize($candidate_url, $permalink) {
            $candidate_url = trim((string) $candidate_url);
            if ($candidate_url === '') {
                return null;
            }

            $normalized_image_url = blc_normalize_link_url(
                $candidate_url,
                $this->homeUrlWithTrailingSlash,
                $this->siteScheme,
                $permalink
            );

            if (!is_string($normalized_image_url) || $normalized_image_url === '') {
                return null;
            }

            $image_host_raw = parse_url($normalized_image_url, PHP_URL_HOST);
            $image_host = is_string($image_host_raw) ? blc_normalize_remote_host($image_host_raw) : '';
            if ($image_host === '') {
                return null;
            }

            $hosts_match_site = ($image_host !== '' && $this->normalizedSiteHost !== '' && $image_host === $this->normalizedSiteHost);
            $hosts_match_upload = ($image_host !== '' && $this->uploadBaseurlHost !== '' && $image_host === $this->uploadBaseurlHost);
            $is_remote_upload_candidate = false;

            if (!$hosts_match_site && !$hosts_match_upload) {
                if (!$this->remoteImageScanEnabled) {
                    if ($this->debugMode) {
                        error_log("  -> Image distante ignorée (analyse désactivée) : " . $normalized_image_url);
                    }
                    return null;
                }

                $is_safe_remote_host = blc_is_safe_remote_host($image_host);
                if (!$is_safe_remote_host) {
                    if ($this->debugMode) {
                        error_log("  -> Image ignorée (IP non autorisée) : " . $normalized_image_url);
                    }
                    return null;
                }

                $is_remote_upload_candidate = true;
            } elseif (!$hosts_match_site && $hosts_match_upload) {
                $is_safe_remote_host = blc_is_safe_remote_host($image_host);
                if (!$is_safe_remote_host) {
                    if ($this->debugMode) {
                        error_log("  -> Image ignorée (IP non autorisée) : " . $normalized_image_url);
                    }
                    return null;
                }
            }

            if ($this->uploadBaseurl === '' || $this->uploadBasedir === '' || $this->normalizedBasedir === '') {
                return null;
            }

            $image_scheme = parse_url($normalized_image_url, PHP_URL_SCHEME);
            $normalized_upload_baseurl = $this->uploadBaseurl;
            if ($image_scheme && $this->uploadBaseurl !== '') {
                $normalized_upload_baseurl = set_url_scheme($this->uploadBaseurl, $image_scheme);
            }

            $normalized_upload_baseurl_length = strlen($normalized_upload_baseurl);
            if ($normalized_upload_baseurl_length === 0) {
                return null;
            }

            if (
                !$is_remote_upload_candidate &&
                strncasecmp($normalized_image_url, $normalized_upload_baseurl, $normalized_upload_baseurl_length) !== 0
            ) {
                return null;
            }

            $image_path_from_url = function_exists('wp_parse_url')
                ? wp_parse_url($normalized_image_url, PHP_URL_PATH)
                : parse_url($normalized_image_url, PHP_URL_PATH);
            if (!is_string($image_path_from_url) || $image_path_from_url === '') {
                return null;
            }

            $image_path = wp_normalize_path($image_path_from_url);

            $parsed_upload_baseurl = function_exists('wp_parse_url') ? wp_parse_url($normalized_upload_baseurl) : parse_url($normalized_upload_baseurl);
            $upload_base_path = '';
            if (is_array($parsed_upload_baseurl) && !empty($parsed_upload_baseurl['path'])) {
                $upload_base_path = wp_normalize_path($parsed_upload_baseurl['path']);
            }

            $upload_base_path_trimmed = ltrim(trailingslashit($upload_base_path), '/');
            $upload_base_path_trimmed_length = strlen($upload_base_path_trimmed);
            $image_path_trimmed = ltrim($image_path, '/');

            if (
                $upload_base_path_trimmed_length === 0 ||
                strncasecmp($image_path_trimmed, $upload_base_path_trimmed, $upload_base_path_trimmed_length) !== 0
            ) {
                return null;
            }

            $relative_path = ltrim(substr($image_path_trimmed, $upload_base_path_trimmed_length), '/');
            if ($relative_path === '') {
                return null;
            }

            $decoded_relative_path = rawurldecode($relative_path);
            $decoded_relative_path = ltrim($decoded_relative_path, '/\\');
            if ($decoded_relative_path === '') {
                return null;
            }

            if (preg_match('#(^|[\\/])\.\.([\\/]|$)#', $decoded_relative_path)) {
                return null;
            }

            $file_path = wp_normalize_path(trailingslashit($this->uploadBasedir) . $decoded_relative_path);
            if (strpos($file_path, $this->normalizedBasedir) !== 0) {
                return null;
            }

            return [
                'original_url'              => $candidate_url,
                'normalized_url'            => $normalized_image_url,
                'file_path'                 => $file_path,
                'decoded_relative_path'     => $decoded_relative_path,
                'is_remote_upload_candidate' => $is_remote_upload_candidate,
            ];
        }
    }
}

