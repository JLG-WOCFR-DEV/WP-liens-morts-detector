<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_normalize_scan_state_type')) {
    /**
     * Normalize the scan type identifier.
     *
     * @param string $scan_type Raw scan type input.
     * @return string Normalized scan type slug.
     */
    function blc_normalize_scan_state_type($scan_type)
    {
        $scan_type = is_string($scan_type) ? strtolower($scan_type) : '';

        if ($scan_type === 'image' || $scan_type === 'images') {
            return 'image';
        }

        return 'link';
    }
}

if (!function_exists('blc_get_scan_state_option_name')) {
    /**
     * Retrieve the option name used to persist a scan state.
     *
     * @param string $scan_type Scan type identifier.
     * @return string
     */
    function blc_get_scan_state_option_name($scan_type)
    {
        $normalized = blc_normalize_scan_state_type($scan_type);

        return sprintf('blc_%s_scan_state', $normalized);
    }
}

if (!function_exists('blc_get_default_scan_state')) {
    /**
     * Get the default structure for a scan state.
     *
     * @param string $scan_type Scan type identifier.
     * @return array
     */
    function blc_get_default_scan_state($scan_type)
    {
        $normalized = blc_normalize_scan_state_type($scan_type);

        return [
            'scan_type'          => $normalized,
            'status'             => 'idle',
            'current_batch'      => 0,
            'processed_batches'  => 0,
            'total_batches'      => 0,
            'progress'           => 0,
            'next_batch'         => null,
            'is_full_scan'       => false,
            'bypass_rest_window' => false,
            'updated_at'         => time(),
            'error_code'         => '',
            'error_message'      => '',
            'reason'             => '',
        ];
    }
}

if (!function_exists('blc_calculate_scan_progress')) {
    /**
     * Calculate scan progress percentage.
     *
     * @param int $processed_batches Number of processed batches.
     * @param int $total_batches     Total number of batches.
     * @return int
     */
    function blc_calculate_scan_progress($processed_batches, $total_batches)
    {
        $processed = max(0, (int) $processed_batches);
        $total     = max(0, (int) $total_batches);

        if ($total <= 0) {
            return $processed > 0 ? 100 : 0;
        }

        if ($processed > $total) {
            $processed = $total;
        }

        return (int) floor(($processed / $total) * 100);
    }
}

if (!function_exists('blc_normalize_scan_state')) {
    /**
     * Sanitize and normalize a scan state payload.
     *
     * @param string $scan_type Scan type identifier.
     * @param array  $state     Raw state data.
     * @return array
     */
    function blc_normalize_scan_state($scan_type, array $state)
    {
        $defaults = blc_get_default_scan_state($scan_type);
        $state    = array_merge($defaults, $state);

        $state['scan_type'] = blc_normalize_scan_state_type($scan_type);

        $allowed_statuses = ['idle', 'running', 'queued', 'completed', 'error'];
        if (!in_array($state['status'], $allowed_statuses, true)) {
            $state['status'] = 'idle';
        }

        $state['current_batch'] = max(0, (int) $state['current_batch']);
        $state['processed_batches'] = max(0, (int) $state['processed_batches']);
        $state['total_batches'] = max(0, (int) $state['total_batches']);

        if ($state['total_batches'] > 0) {
            if ($state['processed_batches'] > $state['total_batches']) {
                $state['processed_batches'] = $state['total_batches'];
            }

            if ($state['current_batch'] >= $state['total_batches']) {
                $state['current_batch'] = max(0, $state['total_batches'] - 1);
            }
        }

        if (!isset($state['progress']) || !is_numeric($state['progress'])) {
            $state['progress'] = blc_calculate_scan_progress($state['processed_batches'], $state['total_batches']);
        } else {
            $state['progress'] = max(0, min(100, (int) round($state['progress'])));
        }

        $state['next_batch'] = isset($state['next_batch']) && $state['next_batch'] !== null
            ? max(0, (int) $state['next_batch'])
            : null;

        $state['is_full_scan'] = !empty($state['is_full_scan']);
        $state['bypass_rest_window'] = !empty($state['bypass_rest_window']);
        $state['updated_at'] = isset($state['updated_at']) ? max(0, (int) $state['updated_at']) : time();
        $state['error_code'] = isset($state['error_code']) && is_scalar($state['error_code']) ? (string) $state['error_code'] : '';
        $state['error_message'] = isset($state['error_message']) && is_scalar($state['error_message']) ? (string) $state['error_message'] : '';
        $state['reason'] = isset($state['reason']) && is_scalar($state['reason']) ? (string) $state['reason'] : '';

        if ($state['status'] === 'completed') {
            $state['processed_batches'] = max($state['processed_batches'], $state['total_batches']);
            if ($state['total_batches'] > 0) {
                $state['processed_batches'] = $state['total_batches'];
            }
            $state['progress'] = 100;
            $state['next_batch'] = null;
            $state['reason'] = '';
            $state['error_code'] = '';
            $state['error_message'] = '';
        }

        if ($state['status'] !== 'error') {
            $state['error_code'] = '';
            $state['error_message'] = '';
        }

        return $state;
    }
}

if (!function_exists('blc_get_scan_state')) {
    /**
     * Retrieve the persisted scan state for the requested type.
     *
     * @param string $scan_type Scan type identifier.
     * @return array
     */
    function blc_get_scan_state($scan_type)
    {
        $option_name = blc_get_scan_state_option_name($scan_type);
        $stored      = get_option($option_name, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        return blc_normalize_scan_state($scan_type, $stored);
    }
}

if (!function_exists('blc_update_scan_state')) {
    /**
     * Persist the scan state for the requested type.
     *
     * @param string $scan_type Scan type identifier.
     * @param array  $state     Data to merge into the current state.
     * @return array Normalized scan state after update.
     */
    function blc_update_scan_state($scan_type, array $state)
    {
        $current   = blc_get_scan_state($scan_type);
        $merged    = array_merge($current, $state);
        $normalized = blc_normalize_scan_state($scan_type, $merged);

        update_option(blc_get_scan_state_option_name($scan_type), $normalized, false);

        return $normalized;
    }
}

if (!function_exists('blc_mark_scan_state_running')) {
    /**
     * Mark the scan state as running for the provided batch.
     *
     * @param string $scan_type Scan type identifier.
     * @param int    $batch     Current batch index.
     * @param array  $extra     Additional data to merge.
     * @return array
     */
    function blc_mark_scan_state_running($scan_type, $batch, array $extra = [])
    {
        $payload = array_merge([
            'status'             => 'running',
            'current_batch'      => max(0, (int) $batch),
            'reason'             => '',
            'error_code'         => '',
            'error_message'      => '',
            'updated_at'         => time(),
        ], $extra);

        return blc_update_scan_state($scan_type, $payload);
    }
}

if (!function_exists('blc_mark_scan_state_queued')) {
    /**
     * Mark the scan state as queued for a specific batch.
     *
     * @param string $scan_type Scan type identifier.
     * @param int    $batch     Batch index that is queued.
     * @param string $reason    Reason describing the queued state.
     * @param array  $extra     Additional data to merge.
     * @return array
     */
    function blc_mark_scan_state_queued($scan_type, $batch, $reason = '', array $extra = [])
    {
        $payload = array_merge([
            'status'             => 'queued',
            'current_batch'      => max(0, (int) $batch),
            'next_batch'         => max(0, (int) $batch),
            'reason'             => (string) $reason,
            'error_code'         => '',
            'error_message'      => '',
            'updated_at'         => time(),
        ], $extra);

        return blc_update_scan_state($scan_type, $payload);
    }
}

if (!function_exists('blc_mark_scan_state_completed')) {
    /**
     * Mark the scan state as completed.
     *
     * @param string $scan_type Scan type identifier.
     * @param int    $batch     Last processed batch index.
     * @param array  $extra     Additional data to merge.
     * @return array
     */
    function blc_mark_scan_state_completed($scan_type, $batch, array $extra = [])
    {
        $payload = array_merge([
            'status'             => 'completed',
            'current_batch'      => max(0, (int) $batch),
            'processed_batches'  => max(0, (int) $batch),
            'next_batch'         => null,
            'reason'             => '',
            'error_code'         => '',
            'error_message'      => '',
            'updated_at'         => time(),
        ], $extra);

        return blc_update_scan_state($scan_type, $payload);
    }
}

if (!function_exists('blc_mark_scan_state_error')) {
    /**
     * Mark the scan state as errored.
     *
     * @param string $scan_type    Scan type identifier.
     * @param int    $batch        Batch index at which the error occurred.
     * @param string $error_code   Error code.
     * @param string $error_message Error message.
     * @param array  $extra        Additional data to merge.
     * @return array
     */
    function blc_mark_scan_state_error($scan_type, $batch, $error_code = '', $error_message = '', array $extra = [])
    {
        $payload = array_merge([
            'status'        => 'error',
            'current_batch' => max(0, (int) $batch),
            'error_code'    => (string) $error_code,
            'error_message' => (string) $error_message,
            'updated_at'    => time(),
        ], $extra);

        return blc_update_scan_state($scan_type, $payload);
    }
}

if (!function_exists('blc_reset_scan_state')) {
    /**
     * Remove the stored scan state for a scan type.
     *
     * @param string $scan_type Scan type identifier.
     * @return void
     */
    function blc_reset_scan_state($scan_type)
    {
        delete_option(blc_get_scan_state_option_name($scan_type));
    }
}
