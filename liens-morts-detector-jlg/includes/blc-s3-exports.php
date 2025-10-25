<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_s3_sanitize_text')) {
    /**
     * Sanitize plain text values for S3 settings.
     *
     * @param mixed $value   Raw value.
     * @param array $options Sanitization options.
     *
     * @return string
     */
    function blc_s3_sanitize_text($value, array $options = [])
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (!empty($options['allow_url'])) {
            $value = strip_tags($value);

            return preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        }

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        $value = strip_tags($value);

        return preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    }
}

if (!function_exists('blc_s3_sanitize_credential')) {
    /**
     * Sanitize credentials (access / secret keys) without stripping valid characters.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    function blc_s3_sanitize_credential($value)
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = trim($value);
        $value = strip_tags($value);

        return preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    }
}

if (!function_exists('blc_s3_normalize_object_prefix')) {
    /**
     * Normalize the object prefix used for uploads.
     *
     * @param string $prefix Raw prefix.
     *
     * @return string
     */
    function blc_s3_normalize_object_prefix($prefix)
    {
        $prefix = is_string($prefix) ? $prefix : '';
        $prefix = str_replace('\\', '/', trim($prefix));
        $prefix = preg_replace('#/{2,}#', '/', $prefix);
        $prefix = trim($prefix, '/');

        if ($prefix === '') {
            return '';
        }

        return $prefix;
    }
}

if (!function_exists('blc_normalize_s3_settings')) {
    /**
     * Normalize the S3 integration settings.
     *
     * @param array<string,mixed> $settings Raw settings array.
     *
     * @return array<string,mixed>
     */
    function blc_normalize_s3_settings(array $settings)
    {
        $defaults = [
            'enabled'             => false,
            'bucket'              => '',
            'region'              => 'us-east-1',
            'endpoint'            => 'https://s3.amazonaws.com',
            'access_key_id'       => '',
            'secret_access_key'   => '',
            'session_token'       => '',
            'object_prefix'       => 'blc-reports',
            'use_path_style'      => false,
            'last_synced_at'      => 0,
            'last_synced_dataset' => '',
            'last_synced_key'     => '',
            'last_error'          => '',
            'last_error_code'     => '',
            'last_error_at'       => 0,
        ];

        $normalized = array_merge($defaults, $settings);

        $normalized['enabled'] = !empty($normalized['enabled']);
        $normalized['bucket'] = blc_s3_sanitize_text($normalized['bucket']);
        $normalized['region'] = blc_s3_sanitize_text($normalized['region']);
        $normalized['endpoint'] = blc_s3_sanitize_text($normalized['endpoint'], ['allow_url' => true]);
        $normalized['endpoint'] = $normalized['endpoint'] !== '' ? rtrim($normalized['endpoint'], '/') : $defaults['endpoint'];

        $normalized['access_key_id'] = blc_s3_sanitize_credential($normalized['access_key_id']);
        $normalized['secret_access_key'] = blc_s3_sanitize_credential($normalized['secret_access_key']);
        $normalized['session_token'] = blc_s3_sanitize_credential($normalized['session_token']);

        $normalized['object_prefix'] = blc_s3_normalize_object_prefix($normalized['object_prefix']);
        $normalized['use_path_style'] = !empty($normalized['use_path_style']);

        $normalized['last_synced_at'] = isset($normalized['last_synced_at']) ? max(0, (int) $normalized['last_synced_at']) : 0;
        $normalized['last_synced_dataset'] = blc_s3_sanitize_text($normalized['last_synced_dataset']);
        $normalized['last_synced_key'] = blc_s3_sanitize_text($normalized['last_synced_key']);

        $normalized['last_error'] = blc_s3_sanitize_text($normalized['last_error']);
        $normalized['last_error_code'] = blc_s3_sanitize_text($normalized['last_error_code']);
        $normalized['last_error_at'] = isset($normalized['last_error_at']) ? max(0, (int) $normalized['last_error_at']) : 0;

        return $normalized;
    }
}

if (!function_exists('blc_get_s3_settings')) {
    /**
     * Retrieve persisted S3 settings.
     *
     * @return array<string,mixed>
     */
    function blc_get_s3_settings()
    {
        $stored = get_option('blc_s3_export_settings', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return blc_normalize_s3_settings($stored);
    }
}

if (!function_exists('blc_update_s3_settings')) {
    /**
     * Persist S3 settings.
     *
     * @param array<string,mixed> $settings Settings to persist.
     *
     * @return array<string,mixed>
     */
    function blc_update_s3_settings(array $settings)
    {
        $normalized = blc_normalize_s3_settings($settings);

        update_option('blc_s3_export_settings', $normalized, false);

        return $normalized;
    }
}

if (!function_exists('blc_s3_prepare_settings_for_response')) {
    /**
     * Prepare settings returned by REST endpoints by masking credentials.
     *
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    function blc_s3_prepare_settings_for_response(array $settings)
    {
        $prepared = $settings;

        $prepared['has_access_key_id'] = $settings['access_key_id'] !== '';
        $prepared['has_secret_access_key'] = $settings['secret_access_key'] !== '';
        $prepared['has_session_token'] = $settings['session_token'] !== '';

        $prepared['access_key_id'] = null;
        $prepared['secret_access_key'] = null;
        $prepared['session_token'] = null;

        return $prepared;
    }
}

if (!function_exists('blc_is_s3_integration_enabled')) {
    /**
     * Determine if the S3 connector is configured.
     *
     * @return bool
     */
    function blc_is_s3_integration_enabled()
    {
        $settings = blc_get_s3_settings();

        if (!$settings['enabled']) {
            return false;
        }

        if ($settings['bucket'] === '' || $settings['region'] === '') {
            return false;
        }

        if ($settings['access_key_id'] === '' || $settings['secret_access_key'] === '') {
            return false;
        }

        return true;
    }
}

if (!function_exists('blc_s3_store_error')) {
    /**
     * Store an error related to the S3 integration and log it when possible.
     *
     * @param string $code    Error identifier.
     * @param string $message Human readable description.
     *
     * @return void
     */
    function blc_s3_store_error($code, $message)
    {
        $settings = blc_get_s3_settings();
        $settings['last_error_code'] = blc_s3_sanitize_text($code);
        $settings['last_error'] = blc_s3_sanitize_text($message);
        $settings['last_error_at'] = time();

        blc_update_s3_settings($settings);

        if (function_exists('error_log')) {
            error_log(sprintf('BLC S3 export: %s (%s)', $settings['last_error'] ?: $code, $settings['last_error_code']));
        }
    }
}

if (!function_exists('blc_s3_sanitize_slug')) {
    /**
     * Convert arbitrary text to a safe slug for object keys.
     *
     * @param string $value Raw value.
     *
     * @return string
     */
    function blc_s3_sanitize_slug($value)
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9\-]+/', '-', $value);
        $value = trim($value, '-');

        return $value !== '' ? $value : 'report';
    }
}

if (!function_exists('blc_s3_generate_object_key')) {
    /**
     * Generate the object key used to upload a report to S3.
     *
     * @param string               $dataset_type Dataset identifier.
     * @param array<string,mixed>  $metadata     Export metadata.
     * @param array<string,mixed>  $settings     Current settings.
     *
     * @return string
     */
    function blc_s3_generate_object_key($dataset_type, array $metadata, array $settings)
    {
        $prefix = isset($settings['object_prefix']) ? (string) $settings['object_prefix'] : '';
        $prefix = blc_s3_normalize_object_prefix($prefix);
        $dataset_slug = blc_s3_sanitize_slug(is_string($dataset_type) ? $dataset_type : '');

        $timestamp = isset($metadata['completed_at']) ? (int) $metadata['completed_at'] : 0;
        if ($timestamp <= 0) {
            $timestamp = time();
        }

        $date_segment = gmdate('Ymd', $timestamp);
        $time_segment = gmdate('His', $timestamp);

        $job_id = '';
        if (isset($metadata['job_id']) && is_string($metadata['job_id'])) {
            $job_id = blc_s3_sanitize_slug($metadata['job_id']);
        }

        $file_name = sprintf('report-%s-%s.csv', $dataset_slug, $time_segment);
        if ($job_id !== '') {
            $file_name = sprintf('report-%s-%s-%s.csv', $dataset_slug, $job_id, $time_segment);
        }

        $key_parts = [];
        if ($prefix !== '') {
            $key_parts[] = $prefix;
        }
        $key_parts[] = $dataset_slug;
        $key_parts[] = $date_segment;
        $key_parts[] = $file_name;

        $object_key = implode('/', $key_parts);

        if (function_exists('apply_filters')) {
            /** @var string $object_key */
            $object_key = apply_filters('blc_s3_object_key', $object_key, $dataset_type, $metadata, $settings);
        }

        return $object_key;
    }
}

if (!function_exists('blc_s3_encode_path')) {
    /**
     * Encode a S3 path segment while keeping the `/` separators intact.
     *
     * @param string $path Raw path.
     *
     * @return string
     */
    function blc_s3_encode_path($path)
    {
        $path = str_replace('\\', '/', $path);
        $segments = array_filter(explode('/', $path), static fn($part) => $part !== '');

        return implode('/', array_map(static fn($part) => rawurlencode($part), $segments));
    }
}

if (!function_exists('blc_s3_build_request_components')) {
    /**
     * Build the request URL, host header and canonical URI for a PUT request.
     *
     * @param array<string,mixed> $settings   S3 settings.
     * @param string              $object_key Object key.
     *
     * @return array{0:string,1:string,2:string}
     */
    function blc_s3_build_request_components(array $settings, $object_key)
    {
        $endpoint = isset($settings['endpoint']) && $settings['endpoint'] !== ''
            ? (string) $settings['endpoint']
            : 'https://s3.amazonaws.com';
        $parsed = parse_url($endpoint);
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        $port = isset($parsed['port']) ? (int) $parsed['port'] : null;
        $base_path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
        $bucket = isset($settings['bucket']) ? $settings['bucket'] : '';
        $use_path_style = !empty($settings['use_path_style']);

        $object_path = blc_s3_encode_path($object_key);

        if ($use_path_style) {
            $path_segments = [$base_path, blc_s3_encode_path($bucket), $object_path];
            $path = implode('/', array_filter($path_segments, static fn($value) => $value !== ''));
            $path = '/' . ltrim($path, '/');
            $host_header = $host;
        } else {
            $path_segments = [$base_path, $object_path];
            $path = implode('/', array_filter($path_segments, static fn($value) => $value !== ''));
            $path = '/' . ltrim($path, '/');
            $host_header = $bucket !== '' ? $bucket . '.' . $host : $host;
        }

        if ($port !== null && $port > 0 && !in_array($port, [80, 443], true)) {
            $host_header .= ':' . $port;
        }

        $url = sprintf('%s://%s%s', $scheme, $host_header, $path);

        return [$url, $host_header, $path];
    }
}

if (!function_exists('blc_s3_sign_request')) {
    /**
     * Generate headers required to sign the S3 PUT request.
     *
     * @param string              $method     HTTP method.
     * @param string              $canonical_uri Canonical URI.
     * @param string              $host       Host header value.
     * @param string              $region     AWS region.
     * @param string              $access_key Access key ID.
     * @param string              $secret_key Secret access key.
     * @param string              $payload_hash SHA256 hash of the payload.
     * @param array<string,mixed> $settings   S3 settings.
     *
     * @return array<string,string>
     */
    function blc_s3_sign_request($method, $canonical_uri, $host, $region, $access_key, $secret_key, $payload_hash, array $settings)
    {
        $service = 's3';
        $timestamp = time();
        $amz_date = gmdate('Ymd\THis\Z', $timestamp);
        $date_stamp = gmdate('Ymd', $timestamp);

        $canonical_headers = [
            'host' => strtolower($host),
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date' => $amz_date,
        ];

        if (!empty($settings['session_token'])) {
            $canonical_headers['x-amz-security-token'] = $settings['session_token'];
        }

        ksort($canonical_headers);

        $canonical_headers_string = '';
        foreach ($canonical_headers as $name => $value) {
            $canonical_headers_string .= sprintf("%s:%s\n", strtolower($name), trim($value));
        }

        $signed_headers = implode(';', array_keys($canonical_headers));

        $canonical_request = implode("\n", [
            strtoupper($method),
            $canonical_uri,
            '',
            $canonical_headers_string,
            $signed_headers,
            $payload_hash,
        ]);

        $credential_scope = sprintf('%s/%s/%s/aws4_request', $date_stamp, $region, $service);
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amz_date,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $k_secret = 'AWS4' . $secret_key;
        $k_date = hash_hmac('sha256', $date_stamp, $k_secret, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        $authorization_header = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        $headers = [
            'Authorization' => $authorization_header,
            'X-Amz-Date' => $amz_date,
            'X-Amz-Content-Sha256' => $payload_hash,
            'Host' => $host,
        ];

        if (!empty($settings['session_token'])) {
            $headers['X-Amz-Security-Token'] = $settings['session_token'];
        }

        return $headers;
    }
}

if (!function_exists('blc_s3_put_object')) {
    /**
     * Upload a file to S3.
     *
     * @param array<string,mixed> $settings   S3 settings.
     * @param string              $object_key Object key to use.
     * @param string              $file_path  Path to the CSV file.
     *
     * @return array|\WP_Error
     */
    function blc_s3_put_object(array $settings, $object_key, $file_path)
    {
        $file_path = (string) $file_path;
        if ($file_path === '' || !is_readable($file_path)) {
            return new \WP_Error(
                'blc_s3_file_unreadable',
                __('Unable to read the generated CSV for S3 export.', 'liens-morts-detector-jlg')
            );
        }

        $payload_hash = hash_file('sha256', $file_path);
        if (!is_string($payload_hash) || $payload_hash === '') {
            $payload_hash = hash('sha256', '');
        }

        [$url, $host, $canonical_uri] = blc_s3_build_request_components($settings, $object_key);

        $headers = blc_s3_sign_request(
            'PUT',
            $canonical_uri,
            $host,
            $settings['region'],
            $settings['access_key_id'],
            $settings['secret_access_key'],
            $payload_hash,
            $settings
        );

        $headers['Content-Type'] = 'text/csv';

        $fileSize = @filesize($file_path);
        if (is_int($fileSize) && $fileSize >= 0) {
            $headers['Content-Length'] = (string) $fileSize;
        }

        $timeout = 20;
        if (function_exists('apply_filters')) {
            $maybe_timeout = apply_filters('blc_s3_request_timeout', $timeout, $settings, $object_key);
            if (is_numeric($maybe_timeout)) {
                $timeout = max(5, (int) $maybe_timeout);
            }
        }

        $canStreamUpload = class_exists('\\Requests_Transport_cURL')
            && class_exists('\\Requests_Hooks')
            && class_exists('\\Requests')
            && function_exists('curl_init');

        if ($canStreamUpload) {
            $handle = fopen($file_path, 'rb');
            if ($handle === false) {
                return new \WP_Error(
                    'blc_s3_file_unreadable',
                    __('Unable to open the generated CSV for S3 export.', 'liens-morts-detector-jlg')
                );
            }

            $hooks = new \Requests_Hooks();
            $hooks->register('curl.before_send', function ($curlHandle) use ($handle, $fileSize) {
                curl_setopt($curlHandle, CURLOPT_UPLOAD, true);
                curl_setopt($curlHandle, CURLOPT_INFILE, $handle);

                if (is_int($fileSize) && $fileSize >= 0) {
                    curl_setopt($curlHandle, CURLOPT_INFILESIZE, $fileSize);
                }
            });

            try {
                $requestsResponse = \Requests::request(
                    $url,
                    $headers,
                    null,
                    'PUT',
                    [
                        'timeout'    => $timeout,
                        'hooks'      => $hooks,
                        'transport'  => 'Requests_Transport_cURL',
                        'blocking'   => true,
                    ]
                );
            } catch (\Exception $exception) {
                fclose($handle);

                return new \WP_Error(
                    'blc_s3_http_error',
                    sprintf(
                        __('S3 request failed: %s', 'liens-morts-detector-jlg'),
                        $exception->getMessage()
                    )
                );
            }

            fclose($handle);

            $response = [
                'body'     => $requestsResponse->body,
                'headers'  => $requestsResponse->headers instanceof \Requests_Response_Headers
                    ? $requestsResponse->headers->getAll()
                    : [],
                'response' => [
                    'code'    => $requestsResponse->status_code,
                    'message' => $requestsResponse->status_text,
                ],
            ];
        } else {
            if (!function_exists('wp_remote_request')) {
                return new \WP_Error(
                    'blc_s3_http_unavailable',
                    __('The WordPress HTTP API is unavailable for S3 requests.', 'liens-morts-detector-jlg')
                );
            }

            $handle = fopen($file_path, 'rb');
            if ($handle === false) {
                return new \WP_Error(
                    'blc_s3_file_unreadable',
                    __('Unable to open the generated CSV for S3 export.', 'liens-morts-detector-jlg')
                );
            }

            $body = stream_get_contents($handle);
            fclose($handle);

            if ($body === false) {
                return new \WP_Error(
                    'blc_s3_file_unreadable',
                    __('Unable to open the generated CSV for S3 export.', 'liens-morts-detector-jlg')
                );
            }

            $response = wp_remote_request($url, [
                'method'  => 'PUT',
                'headers' => $headers,
                'body'    => $body,
                'timeout' => $timeout,
            ]);
        }

        if (blc_is_wp_error($response)) {
            return $response;
        }

        $status_code = function_exists('wp_remote_retrieve_response_code')
            ? wp_remote_retrieve_response_code($response)
            : (is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0);

        if ($status_code < 200 || $status_code >= 300) {
            $body = function_exists('wp_remote_retrieve_body')
                ? wp_remote_retrieve_body($response)
                : (is_array($response) && isset($response['body']) ? (string) $response['body'] : '');

            return new \WP_Error(
                'blc_s3_http_error',
                sprintf(__('S3 endpoint returned HTTP %d: %s', 'liens-morts-detector-jlg'), $status_code, $body)
            );
        }

        return $response;
    }
}

if (!function_exists('blc_s3_handle_report_export')) {
    /**
     * Handle the `blc_report_export_generated` action to upload exports to S3.
     *
     * @param string               $dataset_type Dataset identifier.
     * @param array<string,mixed>  $metadata     Export metadata.
     * @param array<string,mixed>  $status       Latest scan status.
     *
     * @return void
     */
    function blc_s3_handle_report_export($dataset_type, array $metadata, array $status)
    {
        if (!blc_is_s3_integration_enabled()) {
            return;
        }

        if (!isset($metadata['file_path'])) {
            return;
        }

        $settings = blc_get_s3_settings();
        $object_key = blc_s3_generate_object_key($dataset_type, $metadata, $settings);

        $response = blc_s3_put_object($settings, $object_key, (string) $metadata['file_path']);

        if (blc_is_wp_error($response)) {
            blc_s3_store_error($response->get_error_code(), $response->get_error_message());

            return;
        }

        $settings['last_synced_at'] = time();
        $settings['last_synced_dataset'] = blc_s3_sanitize_text($dataset_type);
        $settings['last_synced_key'] = blc_s3_sanitize_text($object_key);
        $settings['last_error'] = '';
        $settings['last_error_code'] = '';
        $settings['last_error_at'] = 0;

        blc_update_s3_settings($settings);
    }
}

if (!function_exists('blc_register_s3_rest_routes')) {
    /**
     * Register REST API endpoints for the S3 connector.
     *
     * @return void
     */
    function blc_register_s3_rest_routes()
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'blc/v1',
            '/s3/settings',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => 'blc_rest_get_s3_settings',
                    'permission_callback' => 'blc_rest_manage_s3_permissions',
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => 'blc_rest_update_s3_settings',
                    'permission_callback' => 'blc_rest_manage_s3_permissions',
                    'args'                => [
                        'enabled'           => [
                            'type'     => 'boolean',
                            'required' => false,
                        ],
                        'bucket'            => [
                            'type'     => 'string',
                            'required' => false,
                        ],
                        'region'            => [
                            'type'     => 'string',
                            'required' => false,
                        ],
                        'endpoint'          => [
                            'type'     => 'string',
                            'required' => false,
                        ],
                        'access_key_id'     => [
                            'type'     => 'string',
                            'required' => false,
                        ],
                        'secret_access_key' => [
                            'type'     => 'string',
                            'required' => false,
                        ],
                        'session_token'     => [
                            'type'     => 'string',
                            'required' => false,
                        ],
                        'object_prefix'     => [
                            'type'     => 'string',
                            'required' => false,
                        ],
                        'use_path_style'    => [
                            'type'     => 'boolean',
                            'required' => false,
                        ],
                    ],
                ],
            ]
        );
    }
}

if (!function_exists('blc_rest_manage_s3_permissions')) {
    /**
     * Check if the current user can manage the S3 integration.
     *
     * @return bool
     */
    function blc_rest_manage_s3_permissions()
    {
        if (function_exists('blc_current_user_can_manage_settings')) {
            return blc_current_user_can_manage_settings();
        }

        return current_user_can('manage_options');
    }
}

if (!function_exists('blc_rest_get_s3_settings')) {
    /**
     * REST callback returning current S3 settings.
     *
     * @return \WP_REST_Response|array<string,mixed>
     */
    function blc_rest_get_s3_settings()
    {
        return rest_ensure_response(blc_s3_prepare_settings_for_response(blc_get_s3_settings()));
    }
}

if (!function_exists('blc_rest_update_s3_settings')) {
    /**
     * REST callback updating S3 configuration.
     *
     * @param \WP_REST_Request $request REST request instance.
     *
     * @return \WP_REST_Response
     */
    function blc_rest_update_s3_settings(\WP_REST_Request $request)
    {
        $current = blc_get_s3_settings();
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        if ($request->has_param('enabled')) {
            $enabled_param = $request->get_param('enabled');
            if (function_exists('rest_sanitize_boolean')) {
                $current['enabled'] = rest_sanitize_boolean($enabled_param);
            } else {
                $current['enabled'] = !empty($enabled_param);
            }
        }

        foreach (['bucket', 'region'] as $key) {
            if (array_key_exists($key, $params)) {
                $current[$key] = blc_s3_sanitize_text($params[$key]);
            }
        }

        if (array_key_exists('endpoint', $params)) {
            $current['endpoint'] = blc_s3_sanitize_text($params['endpoint'], ['allow_url' => true]);
        }

        foreach (['access_key_id', 'secret_access_key', 'session_token'] as $credential_key) {
            if (array_key_exists($credential_key, $params)) {
                if ($params[$credential_key] === null) {
                    continue;
                }

                $current[$credential_key] = blc_s3_sanitize_credential($params[$credential_key]);
            }
        }

        if (array_key_exists('object_prefix', $params)) {
            $current['object_prefix'] = blc_s3_normalize_object_prefix($params['object_prefix']);
        }

        if ($request->has_param('use_path_style')) {
            $use_path_style = $request->get_param('use_path_style');
            if (function_exists('rest_sanitize_boolean')) {
                $current['use_path_style'] = rest_sanitize_boolean($use_path_style);
            } else {
                $current['use_path_style'] = !empty($use_path_style);
            }
        }

        $updated = blc_update_s3_settings($current);

        return rest_ensure_response(blc_s3_prepare_settings_for_response($updated));
    }
}

add_action('blc_report_export_generated', 'blc_s3_handle_report_export', 10, 3);
add_action('rest_api_init', 'blc_register_s3_rest_routes');

