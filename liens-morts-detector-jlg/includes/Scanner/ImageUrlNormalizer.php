<?php

require_once __DIR__ . '/ImageHostPolicy.php';
require_once __DIR__ . '/RemoteImagePolicy.php';
require_once __DIR__ . '/UploadPathResolver.php';
require_once __DIR__ . '/ImageNormalizationResult.php';

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

        /**
         * @var BlcImageHostPolicy
         */
        private $imageHostPolicy;

        /**
         * @var BlcRemoteImagePolicy
         */
        private $remoteImagePolicy;

        /**
         * @var BlcUploadPathResolver
         */
        private $uploadPathResolver;

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

            $this->imageHostPolicy = new BlcImageHostPolicy(
                $this->normalizedSiteHost,
                $this->uploadBaseurlHost
            );

            $this->remoteImagePolicy = new BlcRemoteImagePolicy(
                $this->remoteImageScanEnabled
            );

            $this->uploadPathResolver = new BlcUploadPathResolver(
                $this->uploadBaseurl,
                $this->uploadBasedir,
                $this->normalizedBasedir
            );
        }

        /**
         * Normalize a candidate image URL and return path metadata.
         *
         * @param string $candidate_url Raw URL extracted from the DOM.
         * @param string $permalink     Permalink of the scanned post.
         *
         * @return BlcImageNormalizationResult
         */
        public function normalize($candidate_url, $permalink) {
            $candidate_url = trim((string) $candidate_url);
            if ($candidate_url === '') {
                return BlcImageNormalizationResult::failure('empty_candidate_url');
            }

            $normalized_image_url = blc_normalize_link_url(
                $candidate_url,
                $this->homeUrlWithTrailingSlash,
                $this->siteScheme,
                $permalink
            );

            if (!is_string($normalized_image_url) || $normalized_image_url === '') {
                return BlcImageNormalizationResult::failure('normalization_failed', $candidate_url);
            }

            $host_result = $this->imageHostPolicy->analyzeUrl($normalized_image_url);
            if (!$host_result->isValid()) {
                return BlcImageNormalizationResult::failure('invalid_image_host', $candidate_url, $normalized_image_url);
            }

            $remote_decision = $this->remoteImagePolicy->decide($host_result);
            if (!$remote_decision->isAllowed()) {
                return BlcImageNormalizationResult::failure($remote_decision->getReason(), $candidate_url, $normalized_image_url);
            }

            $path_resolution = $this->uploadPathResolver->resolve(
                $normalized_image_url,
                $remote_decision->isRemoteUploadCandidate()
            );

            if (!$path_resolution->isSuccessful()) {
                return BlcImageNormalizationResult::failure($path_resolution->getReason(), $candidate_url, $normalized_image_url);
            }

            return BlcImageNormalizationResult::success(
                $candidate_url,
                $normalized_image_url,
                $path_resolution->getFilePath(),
                $path_resolution->getDecodedRelativePath(),
                $remote_decision->isRemoteUploadCandidate()
            );
        }
    }
}

