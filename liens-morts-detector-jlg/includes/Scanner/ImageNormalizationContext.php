<?php

if (!class_exists('BlcImageNormalizationContext')) {
    class BlcImageNormalizationContext {
        /**
         * @var array<string, mixed>
         */
        private $siteContext;

        /**
         * @var array<string, mixed>
         */
        private $uploadContext;

        /**
         * @var bool
         */
        private $remoteImageScanEnabled;

        /**
         * @var bool
         */
        private $debugMode;

        /**
         * @param array<string, mixed> $site_context
         * @param array<string, mixed> $upload_context
         * @param bool                 $remote_image_scan_enabled
         * @param bool                 $debug_mode
         */
        public function __construct(array $site_context, array $upload_context, $remote_image_scan_enabled, $debug_mode) {
            $this->siteContext = array_merge(
                [
                    'home_url_with_trailing_slash' => '',
                    'site_scheme'                  => 'https',
                    'normalized_site_host'         => '',
                    'site_host_for_metadata'       => '',
                ],
                $site_context
            );

            $this->uploadContext = array_merge(
                [
                    'upload_baseurl_host' => '',
                    'upload_baseurl'      => '',
                    'upload_basedir'      => '',
                    'normalized_basedir'  => '',
                ],
                $upload_context
            );

            $this->remoteImageScanEnabled = (bool) $remote_image_scan_enabled;
            $this->debugMode = (bool) $debug_mode;
        }

        /**
         * Create a normalizer instance configured for the current site/upload context.
         *
         * @return BlcImageUrlNormalizer
         */
        public function createNormalizer() {
            return new BlcImageUrlNormalizer(
                $this->siteContext['home_url_with_trailing_slash'],
                $this->siteContext['site_scheme'],
                $this->siteContext['normalized_site_host'],
                $this->uploadContext['upload_baseurl_host'],
                $this->uploadContext['upload_baseurl'],
                $this->uploadContext['upload_basedir'],
                $this->uploadContext['normalized_basedir'],
                $this->remoteImageScanEnabled,
                $this->debugMode
            );
        }

        /**
         * Get the site host string used for metadata storage.
         *
         * @return string
         */
        public function getSiteHostForMetadata() {
            $host = isset($this->siteContext['site_host_for_metadata'])
                ? (string) $this->siteContext['site_host_for_metadata']
                : '';

            return $host;
        }
    }
}
