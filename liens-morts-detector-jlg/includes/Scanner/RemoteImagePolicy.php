<?php

if (!class_exists('BlcRemoteImagePolicyDecision')) {
    class BlcRemoteImagePolicyDecision {
        /**
         * @var bool
         */
        private $allowed;

        /**
         * @var bool
         */
        private $remoteUploadCandidate;

        /**
         * @var string
         */
        private $reason;

        private function __construct($allowed, $remote_upload_candidate, $reason) {
            $this->allowed = (bool) $allowed;
            $this->remoteUploadCandidate = (bool) $remote_upload_candidate;
            $this->reason = (string) $reason;
        }

        /**
         * @param bool   $remote_upload_candidate
         * @param string $reason
         *
         * @return self
         */
        public static function allow($remote_upload_candidate, $reason = '') {
            return new self(true, $remote_upload_candidate, $reason);
        }

        /**
         * @param string $reason
         *
         * @return self
         */
        public static function reject($reason) {
            return new self(false, false, $reason);
        }

        /**
         * @return bool
         */
        public function isAllowed() {
            return $this->allowed;
        }

        /**
         * @return bool
         */
        public function isRemoteUploadCandidate() {
            return $this->remoteUploadCandidate;
        }

        /**
         * @return string
         */
        public function getReason() {
            return $this->reason;
        }
    }
}

if (!class_exists('BlcRemoteImagePolicy')) {
    class BlcRemoteImagePolicy {
        /**
         * @var bool
         */
        private $remoteImageScanEnabled;

        public function __construct($remote_image_scan_enabled) {
            $this->remoteImageScanEnabled = (bool) $remote_image_scan_enabled;
        }

        /**
         * Decide whether the remote host can be scanned.
         *
         * @return BlcRemoteImagePolicyDecision
         */
        public function decide(BlcImageHostPolicyResult $host_result) {
            if (!$host_result->isValid()) {
                return BlcRemoteImagePolicyDecision::reject('invalid_host');
            }

            if ($host_result->isSiteHost()) {
                return BlcRemoteImagePolicyDecision::allow(false);
            }

            $host = $host_result->getHost();
            $is_safe_remote_host = blc_is_safe_remote_host($host);

            if ($host_result->isUploadHost()) {
                if (!$is_safe_remote_host) {
                    return BlcRemoteImagePolicyDecision::reject('remote_host_not_safe');
                }

                return BlcRemoteImagePolicyDecision::allow(false);
            }

            if (!$this->remoteImageScanEnabled) {
                return BlcRemoteImagePolicyDecision::reject('remote_scan_disabled');
            }

            if (!$is_safe_remote_host) {
                return BlcRemoteImagePolicyDecision::reject('remote_host_not_safe');
            }

            return BlcRemoteImagePolicyDecision::allow(true);
        }
    }
}
