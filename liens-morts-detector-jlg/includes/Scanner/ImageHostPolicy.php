<?php

if (!class_exists('BlcImageHostPolicyResult')) {
    class BlcImageHostPolicyResult {
        /**
         * @var string
         */
        private $host;

        /**
         * @var bool
         */
        private $matchesSite;

        /**
         * @var bool
         */
        private $matchesUpload;

        public function __construct($host, $matches_site, $matches_upload) {
            $this->host = (string) $host;
            $this->matchesSite = (bool) $matches_site;
            $this->matchesUpload = (bool) $matches_upload;
        }

        /**
         * @return bool
         */
        public function isValid() {
            return $this->host !== '';
        }

        /**
         * @return string
         */
        public function getHost() {
            return $this->host;
        }

        /**
         * @return bool
         */
        public function isSiteHost() {
            return $this->matchesSite;
        }

        /**
         * @return bool
         */
        public function isUploadHost() {
            return $this->matchesUpload;
        }

        /**
         * @return bool
         */
        public function isRemoteHost() {
            return $this->isValid() && !$this->matchesSite && !$this->matchesUpload;
        }
    }
}

if (!class_exists('BlcImageHostPolicy')) {
    class BlcImageHostPolicy {
        /**
         * @var string
         */
        private $normalizedSiteHost;

        /**
         * @var string
         */
        private $uploadBaseurlHost;

        public function __construct($normalized_site_host, $upload_baseurl_host) {
            $this->normalizedSiteHost = (string) $normalized_site_host;
            $this->uploadBaseurlHost = (string) $upload_baseurl_host;
        }

        /**
         * Analyse a normalized URL and classify its host.
         *
         * @param string $normalized_url
         *
         * @return BlcImageHostPolicyResult
         */
        public function analyzeUrl($normalized_url) {
            $host_raw = parse_url((string) $normalized_url, PHP_URL_HOST);
            $host = is_string($host_raw) ? blc_normalize_remote_host($host_raw) : '';

            $matches_site = ($host !== '' && $this->normalizedSiteHost !== '' && $host === $this->normalizedSiteHost);
            $matches_upload = ($host !== '' && $this->uploadBaseurlHost !== '' && $host === $this->uploadBaseurlHost);

            return new BlcImageHostPolicyResult($host, $matches_site, $matches_upload);
        }
    }
}
