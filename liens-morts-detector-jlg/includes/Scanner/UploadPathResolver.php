<?php

if (!class_exists('BlcUploadPathResolution')) {
    class BlcUploadPathResolution {
        /**
         * @var bool
         */
        private $successful;

        /**
         * @var string
         */
        private $filePath;

        /**
         * @var string
         */
        private $decodedRelativePath;

        /**
         * @var string
         */
        private $reason;

        private function __construct($successful, $file_path, $decoded_relative_path, $reason) {
            $this->successful = (bool) $successful;
            $this->filePath = (string) $file_path;
            $this->decodedRelativePath = (string) $decoded_relative_path;
            $this->reason = (string) $reason;
        }

        /**
         * @param string $file_path
         * @param string $decoded_relative_path
         *
         * @return self
         */
        public static function success($file_path, $decoded_relative_path) {
            return new self(true, $file_path, $decoded_relative_path, '');
        }

        /**
         * @param string $reason
         *
         * @return self
         */
        public static function failure($reason) {
            return new self(false, '', '', $reason);
        }

        /**
         * @return bool
         */
        public function isSuccessful() {
            return $this->successful;
        }

        /**
         * @return string
         */
        public function getFilePath() {
            return $this->filePath;
        }

        /**
         * @return string
         */
        public function getDecodedRelativePath() {
            return $this->decodedRelativePath;
        }

        /**
         * @return string
         */
        public function getReason() {
            return $this->reason;
        }
    }
}

if (!class_exists('BlcUploadPathResolver')) {
    class BlcUploadPathResolver {
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

        public function __construct($upload_baseurl, $upload_basedir, $normalized_basedir) {
            $this->uploadBaseurl = (string) $upload_baseurl;
            $this->uploadBasedir = (string) $upload_basedir;
            $this->normalizedBasedir = (string) $normalized_basedir;
        }

        /**
         * Resolve a normalized image URL to a filesystem path within the uploads directory.
         *
         * @param string $normalized_image_url
         * @param bool   $is_remote_upload_candidate
         *
         * @return BlcUploadPathResolution
         */
        public function resolve($normalized_image_url, $is_remote_upload_candidate) {
            if ($this->uploadBaseurl === '' || $this->uploadBasedir === '' || $this->normalizedBasedir === '') {
                return BlcUploadPathResolution::failure('missing_upload_configuration');
            }

            $image_scheme = parse_url($normalized_image_url, PHP_URL_SCHEME);
            $normalized_upload_baseurl = $this->uploadBaseurl;
            if ($image_scheme && $this->uploadBaseurl !== '') {
                $normalized_upload_baseurl = set_url_scheme($this->uploadBaseurl, $image_scheme);
            }

            $normalized_upload_baseurl_length = strlen($normalized_upload_baseurl);
            if ($normalized_upload_baseurl_length === 0) {
                return BlcUploadPathResolution::failure('empty_upload_baseurl');
            }

            if (!$is_remote_upload_candidate) {
                if (strncasecmp($normalized_image_url, $normalized_upload_baseurl, $normalized_upload_baseurl_length) !== 0) {
                    return BlcUploadPathResolution::failure('url_outside_upload_baseurl');
                }
            }

            $image_path_from_url = function_exists('wp_parse_url')
                ? wp_parse_url($normalized_image_url, PHP_URL_PATH)
                : parse_url($normalized_image_url, PHP_URL_PATH);

            if (!is_string($image_path_from_url) || $image_path_from_url === '') {
                return BlcUploadPathResolution::failure('missing_url_path');
            }

            $image_path = wp_normalize_path($image_path_from_url);

            $parsed_upload_baseurl = function_exists('wp_parse_url')
                ? wp_parse_url($normalized_upload_baseurl)
                : parse_url($normalized_upload_baseurl);

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
                return BlcUploadPathResolution::failure('url_outside_upload_path');
            }

            $relative_path = ltrim(substr($image_path_trimmed, $upload_base_path_trimmed_length), '/');
            if ($relative_path === '') {
                return BlcUploadPathResolution::failure('empty_relative_path');
            }

            $decoded_relative_path = rawurldecode($relative_path);
            $decoded_relative_path = ltrim($decoded_relative_path, '/\\');
            if ($decoded_relative_path === '') {
                return BlcUploadPathResolution::failure('empty_decoded_relative_path');
            }

            if (preg_match('#(^|[\\/])\.\.([\\/]|$)#', $decoded_relative_path)) {
                return BlcUploadPathResolution::failure('path_traversal_detected');
            }

            $file_path = wp_normalize_path(trailingslashit($this->uploadBasedir) . $decoded_relative_path);
            if (strpos($file_path, $this->normalizedBasedir) !== 0) {
                return BlcUploadPathResolution::failure('path_outside_basedir');
            }

            return BlcUploadPathResolution::success($file_path, $decoded_relative_path);
        }
    }
}
