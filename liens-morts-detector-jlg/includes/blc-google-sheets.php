<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_google_sheets_sanitize_text')) {
    /**
     * Sanitize plain text values without relying on the admin context.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    function blc_google_sheets_sanitize_text($value)
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        $value = strip_tags($value);

        return preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    }
}

if (!function_exists('blc_normalize_google_sheets_settings')) {
    /**
     * Normalize the Google Sheets integration settings.
     *
     * @param array<string,mixed> $settings Raw settings array.
     *
     * @return array<string,mixed>
     */
    function blc_normalize_google_sheets_settings(array $settings)
    {
        $defaults = [
            'enabled'                  => false,
            'spreadsheet_id'           => '',
            'ranges'                   => [
                'link'  => 'Links!A1',
                'image' => 'Images!A1',
            ],
            'client_id'                => '',
            'client_secret'            => '',
            'access_token'             => '',
            'refresh_token'            => '',
            'access_token_expires_at'  => 0,
            'last_synced_at'           => 0,
            'last_synced_dataset'      => '',
            'last_error'               => '',
            'last_error_code'          => '',
            'last_error_at'            => 0,
        ];

        $normalized = array_merge($defaults, $settings);

        $normalized['enabled'] = !empty($normalized['enabled']);
        $normalized['spreadsheet_id'] = blc_google_sheets_sanitize_text($normalized['spreadsheet_id']);
        $normalized['client_id'] = blc_google_sheets_sanitize_text($normalized['client_id']);
        $normalized['client_secret'] = blc_google_sheets_sanitize_text($normalized['client_secret']);
        $normalized['access_token'] = blc_google_sheets_sanitize_text($normalized['access_token']);
        $normalized['refresh_token'] = blc_google_sheets_sanitize_text($normalized['refresh_token']);
        $normalized['last_synced_dataset'] = blc_google_sheets_sanitize_text($normalized['last_synced_dataset']);
        $normalized['last_error'] = blc_google_sheets_sanitize_text($normalized['last_error']);
        $normalized['last_error_code'] = blc_google_sheets_sanitize_text($normalized['last_error_code']);

        $normalized['access_token_expires_at'] = isset($normalized['access_token_expires_at'])
            ? max(0, (int) $normalized['access_token_expires_at'])
            : 0;
        $normalized['last_synced_at'] = isset($normalized['last_synced_at']) ? max(0, (int) $normalized['last_synced_at']) : 0;
        $normalized['last_error_at'] = isset($normalized['last_error_at']) ? max(0, (int) $normalized['last_error_at']) : 0;

        $ranges = isset($normalized['ranges']) && is_array($normalized['ranges']) ? $normalized['ranges'] : [];
        $normalized_ranges = $defaults['ranges'];
        foreach ($ranges as $dataset => $range) {
            $dataset = is_string($dataset) ? strtolower(trim($dataset)) : '';
            if ($dataset === '') {
                continue;
            }

            $normalized_ranges[$dataset] = blc_google_sheets_sanitize_text($range);
        }

        $normalized['ranges'] = $normalized_ranges;

        return $normalized;
    }
}

if (!function_exists('blc_get_google_sheets_settings')) {
    /**
     * Retrieve the persisted Google Sheets integration settings.
     *
     * @return array<string,mixed>
     */
    function blc_get_google_sheets_settings()
    {
        $stored = get_option('blc_google_sheets_settings', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return blc_normalize_google_sheets_settings($stored);
    }
}

if (!function_exists('blc_update_google_sheets_settings')) {
    /**
     * Persist Google Sheets integration settings.
     *
     * @param array<string,mixed> $settings Settings to persist.
     *
     * @return array<string,mixed>
     */
    function blc_update_google_sheets_settings(array $settings)
    {
        $normalized = blc_normalize_google_sheets_settings($settings);

        update_option('blc_google_sheets_settings', $normalized, false);

        return $normalized;
    }
}

if (!function_exists('blc_google_sheets_prepare_settings_for_response')) {
    /**
     * Prepare Google Sheets settings returned by REST endpoints by masking secrets.
     *
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    function blc_google_sheets_prepare_settings_for_response(array $settings)
    {
        $prepared = $settings;

        $prepared['has_client_secret'] = $settings['client_secret'] !== '';
        $prepared['has_access_token'] = $settings['access_token'] !== '';
        $prepared['has_refresh_token'] = $settings['refresh_token'] !== '';

        $prepared['client_secret'] = null;
        $prepared['access_token'] = null;
        $prepared['refresh_token'] = null;

        return $prepared;
    }
}

if (!function_exists('blc_is_google_sheets_integration_enabled')) {
    /**
     * Determine if the Google Sheets connector is configured.
     *
     * @return bool
     */
    function blc_is_google_sheets_integration_enabled()
    {
        $settings = blc_get_google_sheets_settings();

        if (!$settings['enabled']) {
            return false;
        }

        if ($settings['spreadsheet_id'] === '') {
            return false;
        }

        if ($settings['access_token'] === '' && $settings['refresh_token'] === '') {
            return false;
        }

        return true;
    }
}

if (!function_exists('blc_get_google_sheets_range_for_dataset')) {
    /**
     * Resolve the spreadsheet range used for a dataset.
     *
     * @param string               $dataset_type Dataset identifier.
     * @param array<string,mixed>  $settings     Google Sheets settings.
     *
     * @return string
     */
    function blc_get_google_sheets_range_for_dataset($dataset_type, array $settings)
    {
        $dataset_type = is_string($dataset_type) ? strtolower(trim($dataset_type)) : '';
        if ($dataset_type === '') {
            return '';
        }

        $ranges = isset($settings['ranges']) && is_array($settings['ranges']) ? $settings['ranges'] : [];
        $range = isset($ranges[$dataset_type]) ? (string) $ranges[$dataset_type] : '';

        if ($range === '' && function_exists('apply_filters')) {
            $range = apply_filters('blc_google_sheets_dataset_range', $range, $dataset_type, $settings);
        }

        return $range;
    }
}

if (!function_exists('blc_google_sheets_store_error')) {
    /**
     * Store an error related to the Google Sheets integration and log it when possible.
     *
     * @param string $code    Error identifier.
     * @param string $message Human readable description.
     *
     * @return void
     */
    function blc_google_sheets_store_error($code, $message)
    {
        $settings = blc_get_google_sheets_settings();
        $settings['last_error_code'] = blc_google_sheets_sanitize_text($code);
        $settings['last_error']      = blc_google_sheets_sanitize_text($message);
        $settings['last_error_at']   = time();

        blc_update_google_sheets_settings($settings);

        if (function_exists('error_log')) {
            error_log(sprintf('BLC Google Sheets: %s (%s)', $settings['last_error'] ?: $code, $settings['last_error_code']));
        }
    }
}

if (!function_exists('blc_google_sheets_maybe_refresh_token')) {
    /**
     * Refresh the OAuth access token when it is missing or expired.
     *
     * @param array<string,mixed> $settings Current settings.
     *
     * @return array<string,mixed>
     */
    function blc_google_sheets_maybe_refresh_token(array $settings)
    {
        $now = time();
        $expires_at = isset($settings['access_token_expires_at']) ? (int) $settings['access_token_expires_at'] : 0;

        if ($settings['access_token'] !== '' && $expires_at > ($now + 60)) {
            return $settings;
        }

        if ($settings['refresh_token'] === '' || $settings['client_id'] === '' || $settings['client_secret'] === '') {
            return $settings;
        }

        if (!function_exists('wp_remote_post')) {
            return $settings;
        }

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body'    => [
                    'client_id'     => $settings['client_id'],
                    'client_secret' => $settings['client_secret'],
                    'refresh_token' => $settings['refresh_token'],
                    'grant_type'    => 'refresh_token',
                ],
                'timeout' => 20,
            ]
        );

        if (is_wp_error($response)) {
            blc_google_sheets_store_error('token_refresh_failed', $response->get_error_message());

            return $settings;
        }

        $status_code = function_exists('wp_remote_retrieve_response_code')
            ? wp_remote_retrieve_response_code($response)
            : (is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0);

        if ($status_code !== 200) {
            $body = function_exists('wp_remote_retrieve_body')
                ? wp_remote_retrieve_body($response)
                : (is_array($response) && isset($response['body']) ? (string) $response['body'] : '');

            blc_google_sheets_store_error('token_refresh_http_error', sprintf(__('HTTP %d when refreshing Google Sheets token: %s', 'liens-morts-detector-jlg'), $status_code, $body));

            return $settings;
        }

        $body = function_exists('wp_remote_retrieve_body')
            ? wp_remote_retrieve_body($response)
            : (is_array($response) && isset($response['body']) ? (string) $response['body'] : '');

        $payload = json_decode($body, true);
        if (!is_array($payload) || empty($payload['access_token'])) {
            blc_google_sheets_store_error('token_refresh_invalid_body', __('Invalid payload received from Google token endpoint.', 'liens-morts-detector-jlg'));

            return $settings;
        }

        $settings['access_token'] = blc_google_sheets_sanitize_text($payload['access_token']);
        $expires_in = isset($payload['expires_in']) ? (int) $payload['expires_in'] : 3600;
        if ($expires_in < 60) {
            $expires_in = 3600;
        }
        $settings['access_token_expires_at'] = time() + $expires_in;
        $settings['last_error']      = '';
        $settings['last_error_code'] = '';

        return blc_update_google_sheets_settings($settings);
    }
}

if (!function_exists('blc_google_sheets_extract_csv_values')) {
    /**
     * Extract rows from a CSV file to send to Google Sheets.
     *
     * @param string $file_path CSV file path.
     *
     * @return array<int,array<int,string>>|\WP_Error
     */
    function blc_google_sheets_extract_csv_values($file_path)
    {
        if (!is_string($file_path) || $file_path === '' || !is_readable($file_path)) {
            return new \WP_Error(
                'blc_google_sheets_csv_unreadable',
                __('Unable to read the generated CSV for Google Sheets export.', 'liens-morts-detector-jlg')
            );
        }

        $handle = fopen($file_path, 'rb');
        if ($handle === false) {
            return new \WP_Error(
                'blc_google_sheets_csv_unreadable',
                __('Unable to open the generated CSV for Google Sheets export.', 'liens-morts-detector-jlg')
            );
        }

        $rows = [];
        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = array_map(static fn($value) => (string) $value, $data);
        }

        fclose($handle);

        return $rows;
    }
}

if (!function_exists('blc_google_sheets_push_values')) {
    /**
     * Push data to Google Sheets using the batchUpdate endpoint.
     *
     * @param string                        $spreadsheet_id Spreadsheet identifier.
     * @param string                        $range          Target range (e.g. `Links!A1`).
     * @param array<int,array<int,string>>  $values         Values to send.
     * @param string                        $access_token   OAuth access token.
     *
     * @return array|\WP_Error
     */
    function blc_google_sheets_push_values($spreadsheet_id, $range, array $values, $access_token)
    {
        $spreadsheet_id = blc_google_sheets_sanitize_text($spreadsheet_id);
        $range = blc_google_sheets_sanitize_text($range);
        $access_token = (string) $access_token;

        if ($spreadsheet_id === '' || $range === '' || $access_token === '') {
            return new \WP_Error(
                'blc_google_sheets_missing_configuration',
                __('Google Sheets integration is missing required configuration.', 'liens-morts-detector-jlg')
            );
        }

        if (!function_exists('wp_remote_post')) {
            return new \WP_Error(
                'blc_google_sheets_http_unavailable',
                __('The WordPress HTTP API is unavailable for Google Sheets requests.', 'liens-morts-detector-jlg')
            );
        }

        $payload = [
            'valueInputOption' => 'RAW',
            'data'             => [
                [
                    'range'          => $range,
                    'majorDimension' => 'ROWS',
                    'values'         => $values,
                ],
            ],
        ];

        $json_payload = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        if (!is_string($json_payload) || $json_payload === '') {
            return new \WP_Error(
                'blc_google_sheets_encode_error',
                __('Unable to encode the Google Sheets payload.', 'liens-morts-detector-jlg')
            );
        }

        $timeout = 20;
        if (function_exists('apply_filters')) {
            $maybe_timeout = apply_filters('blc_google_sheets_request_timeout', $timeout, $spreadsheet_id, $range);
            if (is_numeric($maybe_timeout)) {
                $timeout = max(5, (int) $maybe_timeout);
            }
        }

        $response = wp_remote_post(
            sprintf('https://sheets.googleapis.com/v4/spreadsheets/%s/values:batchUpdate', rawurlencode($spreadsheet_id)),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => $json_payload,
                'timeout' => $timeout,
            ]
        );

        if (is_wp_error($response)) {
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
                'blc_google_sheets_http_error',
                sprintf(__('Google Sheets API returned HTTP %d: %s', 'liens-morts-detector-jlg'), $status_code, $body)
            );
        }

        return $response;
    }
}

if (!function_exists('blc_google_sheets_handle_report_export')) {
    /**
     * Handle the `blc_report_export_generated` action to push exports to Google Sheets.
     *
     * @param string               $dataset_type Dataset identifier.
     * @param array<string,mixed>  $metadata     Export metadata.
     * @param array<string,mixed>  $status       Latest scan status.
     *
     * @return void
     */
    function blc_google_sheets_handle_report_export($dataset_type, array $metadata, array $status)
    {
        if (!blc_is_google_sheets_integration_enabled()) {
            return;
        }

        $settings = blc_get_google_sheets_settings();
        $settings = blc_google_sheets_maybe_refresh_token($settings);

        $range = blc_get_google_sheets_range_for_dataset($dataset_type, $settings);
        if ($range === '') {
            return;
        }

        if (!isset($metadata['file_path'])) {
            return;
        }

        $values = blc_google_sheets_extract_csv_values((string) $metadata['file_path']);
        if (is_wp_error($values)) {
            blc_google_sheets_store_error($values->get_error_code(), $values->get_error_message());

            return;
        }

        $response = blc_google_sheets_push_values(
            $settings['spreadsheet_id'],
            $range,
            $values,
            $settings['access_token']
        );

        if (is_wp_error($response)) {
            blc_google_sheets_store_error($response->get_error_code(), $response->get_error_message());

            return;
        }

        $settings['last_synced_at']      = time();
        $settings['last_synced_dataset']  = blc_google_sheets_sanitize_text($dataset_type);
        $settings['last_error']           = '';
        $settings['last_error_code']      = '';
        $settings['last_error_at']        = 0;

        blc_update_google_sheets_settings($settings);
    }
}

if (!function_exists('blc_register_google_sheets_rest_routes')) {
    /**
     * Register REST API endpoints for the Google Sheets connector.
     *
     * @return void
     */
    function blc_register_google_sheets_rest_routes()
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'blc/v1',
            '/google-sheets/settings',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => 'blc_rest_get_google_sheets_settings',
                    'permission_callback' => 'blc_rest_manage_google_sheets_permissions',
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => 'blc_rest_update_google_sheets_settings',
                    'permission_callback' => 'blc_rest_manage_google_sheets_permissions',
                    'args'                => [
                        'enabled'         => [
                            'type'     => 'boolean',
                            'required' => false,
                        ],
                        'spreadsheet_id' => [
                            'type'     => 'string',
                            'required' => false,
                        ],
                        'client_id'      => [
                            'type'     => 'string',
                            'required' => false,
                        ],
                        'client_secret'  => [
                            'type'      => 'string',
                            'required'  => false,
                            'nullable'  => true,
                        ],
                        'ranges'         => [
                            'type'     => 'object',
                            'required' => false,
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'blc/v1',
            '/google-sheets/token',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => 'blc_rest_store_google_sheets_token',
                'permission_callback' => 'blc_rest_manage_google_sheets_permissions',
                'args'                => [
                    'access_token'  => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'expires_in'    => [
                        'type'     => 'integer',
                        'required' => false,
                    ],
                    'refresh_token' => [
                        'type'     => 'string',
                        'required' => false,
                    ],
                    'client_id'     => [
                        'type'     => 'string',
                        'required' => false,
                    ],
                    'client_secret' => [
                        'type'     => 'string',
                        'required' => false,
                    ],
                ],
            ]
        );
    }
}

if (!function_exists('blc_rest_manage_google_sheets_permissions')) {
    /**
     * Check if the current user can manage the Google Sheets integration.
     *
     * @return bool
     */
    function blc_rest_manage_google_sheets_permissions()
    {
        if (function_exists('blc_current_user_can_manage_settings')) {
            return blc_current_user_can_manage_settings();
        }

        return current_user_can('manage_options');
    }
}

if (!function_exists('blc_rest_get_google_sheets_settings')) {
    /**
     * REST callback returning the current Google Sheets settings.
     *
     * @return \WP_REST_Response|array<string,mixed>
     */
    function blc_rest_get_google_sheets_settings()
    {
        return rest_ensure_response(blc_google_sheets_prepare_settings_for_response(blc_get_google_sheets_settings()));
    }
}

if (!function_exists('blc_rest_update_google_sheets_settings')) {
    /**
     * REST callback updating Google Sheets configuration.
     *
     * @param \WP_REST_Request $request REST request instance.
     *
     * @return \WP_REST_Response
     */
    function blc_rest_update_google_sheets_settings(\WP_REST_Request $request)
    {
        $current = blc_get_google_sheets_settings();
        $params  = $request->get_json_params();
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

        foreach (['spreadsheet_id', 'client_id', 'client_secret'] as $key) {
            if (array_key_exists($key, $params)) {
                if ($key === 'client_secret' && $params[$key] === null) {
                    continue;
                }

                $current[$key] = blc_google_sheets_sanitize_text($params[$key]);
            }
        }

        if (isset($params['ranges']) && is_array($params['ranges'])) {
            foreach ($params['ranges'] as $dataset => $range) {
                $dataset = is_string($dataset) ? strtolower(trim($dataset)) : '';
                if ($dataset === '') {
                    continue;
                }

                $current['ranges'][$dataset] = blc_google_sheets_sanitize_text($range);
            }
        }

        $updated = blc_update_google_sheets_settings($current);

        return rest_ensure_response(blc_google_sheets_prepare_settings_for_response($updated));
    }
}

if (!function_exists('blc_rest_store_google_sheets_token')) {
    /**
     * REST callback storing OAuth tokens after a successful authorization flow.
     *
     * @param \WP_REST_Request $request REST request instance.
     *
     * @return \WP_REST_Response
     */
    function blc_rest_store_google_sheets_token(\WP_REST_Request $request)
    {
        $settings = blc_get_google_sheets_settings();

        $access_token = blc_google_sheets_sanitize_text($request->get_param('access_token'));
        $refresh_token = $request->get_param('refresh_token');
        $client_id = $request->get_param('client_id');
        $client_secret = $request->get_param('client_secret');

        if ($access_token !== '') {
            $settings['access_token'] = $access_token;
        }

        if (is_string($refresh_token) && $refresh_token !== '') {
            $settings['refresh_token'] = blc_google_sheets_sanitize_text($refresh_token);
        }

        if (is_string($client_id) && $client_id !== '') {
            $settings['client_id'] = blc_google_sheets_sanitize_text($client_id);
        }

        if (is_string($client_secret) && $client_secret !== '') {
            $settings['client_secret'] = blc_google_sheets_sanitize_text($client_secret);
        }

        $expires_in = (int) $request->get_param('expires_in');
        if ($expires_in <= 0) {
            $expires_in = 3600;
        }

        $settings['access_token_expires_at'] = time() + $expires_in;
        $settings['last_error']               = '';
        $settings['last_error_code']          = '';
        $settings['last_error_at']            = 0;

        $updated = blc_update_google_sheets_settings($settings);

        return rest_ensure_response(blc_google_sheets_prepare_settings_for_response($updated));
    }
}

add_action('blc_report_export_generated', 'blc_google_sheets_handle_report_export', 10, 3);
add_action('rest_api_init', 'blc_register_google_sheets_rest_routes');

