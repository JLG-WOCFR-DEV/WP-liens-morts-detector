<?php

if (!class_exists('BlcImageNormalizationResult')) {
    class BlcImageNormalizationResult {
        /**
         * @var bool
         */
        private $successful;

        /**
         * @var string
         */
        private $reason;

        /**
         * @var string
         */
        private $originalUrl;

        /**
         * @var string
         */
        private $normalizedUrl;

        /**
         * @var string
         */
        private $filePath;

        /**
         * @var string
         */
        private $decodedRelativePath;

        /**
         * @var bool
         */
        private $remoteUploadCandidate;

        private function __construct($successful, $reason, $original_url, $normalized_url, $file_path, $decoded_relative_path, $remote_upload_candidate) {
            $this->successful = (bool) $successful;
            $this->reason = (string) $reason;
            $this->originalUrl = (string) $original_url;
            $this->normalizedUrl = (string) $normalized_url;
            $this->filePath = (string) $file_path;
            $this->decodedRelativePath = (string) $decoded_relative_path;
            $this->remoteUploadCandidate = (bool) $remote_upload_candidate;
        }

        /**
         * @param string $original_url
         * @param string $normalized_url
         * @param string $file_path
         * @param string $decoded_relative_path
         * @param bool   $remote_upload_candidate
         *
         * @return self
         */
        public static function success($original_url, $normalized_url, $file_path, $decoded_relative_path, $remote_upload_candidate) {
            return new self(true, '', $original_url, $normalized_url, $file_path, $decoded_relative_path, $remote_upload_candidate);
        }

        /**
         * @param string $reason
         * @param string $original_url
         * @param string $normalized_url
         *
         * @return self
         */
        public static function failure($reason, $original_url = '', $normalized_url = '') {
            return new self(false, $reason, $original_url, $normalized_url, '', '', false);
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
        public function getReason() {
            return $this->reason;
        }

        /**
         * @return string
         */
        public function getOriginalUrl() {
            return $this->originalUrl;
        }

        /**
         * @return string
         */
        public function getNormalizedUrl() {
            return $this->normalizedUrl;
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
         * @return bool
         */
        public function isRemoteUploadCandidate() {
            return $this->remoteUploadCandidate;
        }
    }
}
