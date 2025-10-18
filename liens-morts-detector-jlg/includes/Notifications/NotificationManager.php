<?php

namespace JLG\BrokenLinks\Notifications;

use WP_Error;

/**
 * Centralize notification dispatch logic (email + webhook) with history and throttling.
 */
class NotificationManager
{
    private const HISTORY_OPTION = 'blc_notification_delivery_log';
    private const DEFAULT_HISTORY_SIZE = 50;
    private const SEVERITY_LEVELS = array(
        'info' => 0,
        'warning' => 1,
        'critical' => 2,
    );

    /**
     * @var callable
     */
    private $timeProvider;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $history = array();

    private bool $historyLoaded = false;

    private bool $historyDirty = false;

    public function __construct(?callable $timeProvider = null)
    {
        $this->timeProvider = $timeProvider ?: static function (): int {
            return time();
        };
    }

    /**
     * Dispatch scan summary notifications to configured channels.
     *
     * @param string               $datasetType Dataset identifier (link|image).
     * @param array<string, mixed> $summary     Structured summary payload.
     * @param string[]             $recipients  Email recipients.
     * @param array<string, mixed> $args        Additional arguments.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sendSummaryNotifications($datasetType, array $summary, array $recipients, array $args = array()): array
    {
        $datasetType = is_string($datasetType) ? $datasetType : '';
        $context     = isset($args['context']) ? (string) $args['context'] : 'scan';
        $webhookSettings = isset($args['webhook_settings']) && is_array($args['webhook_settings'])
            ? $args['webhook_settings']
            : \blc_get_notification_webhook_settings();

        $datasetLabel = isset($summary['dataset_label']) ? (string) $summary['dataset_label'] : $datasetType;
        if ($datasetLabel === '') {
            $datasetLabel = $datasetType;
        }

        $contextLabel = ($context === 'test')
            ? __('de test', 'liens-morts-detector-jlg')
            : __('planifiée', 'liens-morts-detector-jlg');

        $summarySeverity = $this->normalizeSeverity($summary['severity'] ?? 'info');
        $webhookThreshold = isset($webhookSettings['severity'])
            ? $this->normalizeSeverity($webhookSettings['severity'], 'warning')
            : 'warning';

        $escalationSettings = function_exists('blc_get_notification_escalation_settings')
            ? \blc_get_notification_escalation_settings()
            : array();
        if (!is_array($escalationSettings)) {
            $escalationSettings = array();
        }

        $escalationMode = isset($escalationSettings['mode']) ? (string) $escalationSettings['mode'] : 'disabled';
        $escalationThreshold = isset($escalationSettings['severity'])
            ? $this->normalizeSeverity($escalationSettings['severity'], 'critical')
            : 'critical';

        $results = array();
        $results['email'] = $this->dispatchEmailChannel(
            $datasetType,
            $context,
            $summary,
            $recipients,
            array(
                'dataset_label'   => $datasetLabel,
                'context_label'   => $contextLabel,
                'failure_message' => sprintf(
                    __('Échec de l’envoi de l’e-mail pour l’analyse %s.', 'liens-morts-detector-jlg'),
                    $datasetLabel
                ),
                'severity'        => $summarySeverity,
            ) + $args
        );

        if ($this->shouldTriggerSeverity($summarySeverity, $webhookThreshold)) {
            $results['webhook'] = $this->dispatchWebhookChannel(
                $datasetType,
                $context,
                $summary,
                $webhookSettings,
                array(
                    'dataset_label'         => $datasetLabel,
                    'context_label'         => $contextLabel,
                    'include_details'       => false,
                    'return_error_instance' => false,
                    'log_failures'          => true,
                    'channel_key'           => 'webhook',
                    'severity'              => $summarySeverity,
                    'severity_threshold'    => $webhookThreshold,
                ) + $args
            );
        } else {
            $timestamp = $this->now();
            $this->recordHistory('webhook', $datasetType, $context, 'skipped', $timestamp, array(
                'reason'             => 'below_severity',
                'severity'           => $summarySeverity,
                'severity_threshold' => $webhookThreshold,
            ));

            $results['webhook'] = array(
                'status'  => 'skipped',
                'message' => __('Notification ignorée car la sévérité est inférieure au seuil configuré.', 'liens-morts-detector-jlg'),
            );
        }

        $results['escalation'] = array('status' => 'skipped');

        if ($escalationMode === 'webhook') {
            $escalationPayload = array(
                'url'              => isset($escalationSettings['url']) ? (string) $escalationSettings['url'] : '',
                'channel'          => isset($escalationSettings['channel']) ? (string) $escalationSettings['channel'] : 'disabled',
                'message_template' => isset($escalationSettings['message_template']) ? (string) $escalationSettings['message_template'] : '',
            );

            if ($this->shouldTriggerSeverity($summarySeverity, $escalationThreshold)) {
                if (\blc_is_webhook_notification_configured($escalationPayload)) {
                    $results['escalation'] = $this->dispatchWebhookChannel(
                        $datasetType,
                        $context,
                        $summary,
                        $escalationPayload,
                        array(
                            'dataset_label'         => $datasetLabel,
                            'context_label'         => $contextLabel,
                            'include_details'       => false,
                            'return_error_instance' => false,
                            'log_failures'          => true,
                            'channel_key'           => 'escalation',
                            'severity'              => $summarySeverity,
                            'severity_threshold'    => $escalationThreshold,
                        ) + $args
                    );
                } else {
                    $timestamp = $this->now();
                    $this->recordHistory('escalation', $datasetType, $context, 'skipped', $timestamp, array(
                        'reason'             => 'missing_configuration',
                        'severity'           => $summarySeverity,
                        'severity_threshold' => $escalationThreshold,
                    ));

                    $results['escalation'] = array(
                        'status'  => 'skipped',
                        'message' => __('Escalade ignorée : le canal secondaire n’est pas configuré.', 'liens-morts-detector-jlg'),
                    );
                }
            } else {
                $timestamp = $this->now();
                $this->recordHistory('escalation', $datasetType, $context, 'skipped', $timestamp, array(
                    'reason'             => 'below_severity',
                    'severity'           => $summarySeverity,
                    'severity_threshold' => $escalationThreshold,
                ));

                $results['escalation'] = array(
                    'status'  => 'skipped',
                    'message' => __('Escalade ignorée car la sévérité est inférieure au seuil configuré.', 'liens-morts-detector-jlg'),
                );
            }
        }

        $this->persistHistory();

        return $results;
    }

    /**
     * Send a webhook notification directly (without handling email channel).
     *
     * @param string               $datasetType Dataset identifier (link|image).
     * @param array<string, mixed> $summary     Structured summary payload.
     * @param array<string, mixed> $settings    Webhook configuration.
     * @param array<string, mixed> $args        Additional arguments.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function sendWebhookOnly($datasetType, array $summary, array $settings, array $args = array())
    {
        $datasetType = is_string($datasetType) ? $datasetType : '';
        $context     = isset($args['context']) ? (string) $args['context'] : 'scan';

        $result = $this->dispatchWebhookChannel(
            $datasetType,
            $context,
            $summary,
            $settings,
            array(
                'include_details'        => true,
                'return_error_instance'  => true,
                'log_failures'           => true,
            ) + $args
        );

        $this->persistHistory();

        if (isset($result['error_instance']) && $result['error_instance'] instanceof WP_Error) {
            return $result['error_instance'];
        }

        return array(
            'response'     => $result['response'] ?? null,
            'request_args' => $result['request_args'] ?? null,
            'payload'      => $result['payload'] ?? null,
            'code'         => $result['code'] ?? null,
            'message'      => $result['message'] ?? '',
        );
    }

    /**
     * Retrieve the most recent notification history entries.
     *
     * @param int|null $limit Optional number of entries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistoryEntries(?int $limit = null): array
    {
        $history = $this->getHistory();
        if ($limit === null || $limit <= 0) {
            return $history;
        }

        return array_slice($history, -$limit);
    }

    /**
     * @param string               $datasetType
     * @param string               $context
     * @param array<string, mixed> $summary
     * @param string[]             $recipients
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function dispatchEmailChannel($datasetType, $context, array $summary, array $recipients, array $args): array
    {
        if ($recipients === array()) {
            return array('status' => 'skipped');
        }

        $timestamp = $this->now();
        $signature = $this->buildSignature('email', $datasetType, $context, $summary, array('recipients' => $recipients));
        $window    = $this->resolveThrottleWindow('email', $datasetType, $context, $args);
        $severity  = isset($args['severity']) ? $this->normalizeSeverity($args['severity']) : null;

        if ($this->shouldThrottle($signature, $window, $timestamp)) {
            $message = __('Notification ignorée pour éviter un doublon récent.', 'liens-morts-detector-jlg');
            $meta = array(
                'signature'       => $signature,
                'recipient_count' => count($recipients),
            );
            if ($severity !== null) {
                $meta['severity'] = $severity;
            }
            $this->recordHistory('email', $datasetType, $context, 'throttled', $timestamp, $meta);

            return array(
                'status'  => 'throttled',
                'message' => $message,
            );
        }

        $subject = isset($summary['subject']) ? (string) $summary['subject'] : '';
        $message = isset($summary['message']) ? (string) $summary['message'] : '';
        $sent    = \wp_mail($recipients, $subject, $message);

        if ($sent) {
            $meta = array(
                'signature'       => $signature,
                'recipient_count' => count($recipients),
            );
            if ($severity !== null) {
                $meta['severity'] = $severity;
            }
            $this->recordHistory('email', $datasetType, $context, 'sent', $timestamp, $meta);

            return array('status' => 'sent');
        }

        $errorMessage = isset($args['failure_message']) ? (string) $args['failure_message'] : '';
        if ($errorMessage === '') {
            $errorMessage = __('Échec de l’envoi de la notification e-mail.', 'liens-morts-detector-jlg');
        }

        $meta = array(
            'signature'       => $signature,
            'recipient_count' => count($recipients),
            'error'           => $errorMessage,
        );
        if ($severity !== null) {
            $meta['severity'] = $severity;
        }
        $this->recordHistory('email', $datasetType, $context, 'failed', $timestamp, $meta);

        $contextLabel = isset($args['context_label']) ? (string) $args['context_label'] : $context;
        \error_log(sprintf('BLC: Failed to send %s summary email (%s).', $datasetType, $contextLabel));

        return array(
            'status' => 'failed',
            'error'  => $errorMessage,
        );
    }

    /**
     * @param string               $datasetType
     * @param string               $context
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function dispatchWebhookChannel($datasetType, $context, array $summary, array $settings, array $args): array
    {
        if (!\blc_is_webhook_notification_configured($settings)) {
            return array('status' => 'skipped');
        }

        $timestamp    = $this->now();
        $channelKey   = isset($args['channel_key']) && is_string($args['channel_key']) && $args['channel_key'] !== ''
            ? $args['channel_key']
            : 'webhook';
        $signature    = isset($args['signature']) && is_string($args['signature'])
            ? $args['signature']
            : $this->buildSignature($channelKey, $datasetType, $context, $summary, $this->summarizeWebhookSettings($settings));
        $window       = $this->resolveThrottleWindow($channelKey, $datasetType, $context, $args);
        $includeDetails = !empty($args['include_details']);
        $returnErrorInstance = !empty($args['return_error_instance']);
        $contextLabel = isset($args['context_label']) ? (string) $args['context_label'] : $context;
        $severity = isset($args['severity']) ? $this->normalizeSeverity($args['severity']) : null;
        $severityThreshold = isset($args['severity_threshold'])
            ? $this->normalizeSeverity($args['severity_threshold'], 'warning')
            : null;

        if ($this->shouldThrottle($signature, $window, $timestamp)) {
            $message = __('Notification webhook ignorée pour éviter un doublon récent.', 'liens-morts-detector-jlg');
            $meta = array('signature' => $signature);
            if ($severity !== null) {
                $meta['severity'] = $severity;
            }
            if ($severityThreshold !== null) {
                $meta['severity_threshold'] = $severityThreshold;
            }
            $this->recordHistory($channelKey, $datasetType, $context, 'throttled', $timestamp, $meta);

            return array(
                'status'  => 'throttled',
                'message' => $message,
            );
        }

        $sendResult = $this->sendWebhookRequest($datasetType, $summary, $settings);

        if ($sendResult instanceof WP_Error) {
            $errorMessage = $sendResult->get_error_message();
            $meta = array(
                'signature' => $signature,
                'error'     => $errorMessage,
            );
            if ($severity !== null) {
                $meta['severity'] = $severity;
            }
            if ($severityThreshold !== null) {
                $meta['severity_threshold'] = $severityThreshold;
            }
            $this->recordHistory($channelKey, $datasetType, $context, 'failed', $timestamp, $meta);

            if (!empty($args['log_failures'])) {
                \error_log(sprintf('BLC: Webhook notification failed for %s summary (%s): %s', $datasetType, $contextLabel, $errorMessage));
            }

            $result = array(
                'status' => 'failed',
                'error'  => $errorMessage,
            );

            if ($returnErrorInstance) {
                $result['error_instance'] = $sendResult;
            }

            return $result;
        }

        $code = isset($sendResult['code']) ? (int) $sendResult['code'] : null;
        $meta = array(
            'signature'    => $signature,
            'webhook_code' => $code,
        );
        if ($severity !== null) {
            $meta['severity'] = $severity;
        }
        if ($severityThreshold !== null) {
            $meta['severity_threshold'] = $severityThreshold;
        }
        $this->recordHistory($channelKey, $datasetType, $context, 'sent', $timestamp, $meta);

        if (!$includeDetails) {
            return array(
                'status' => 'sent',
                'code'   => $code,
            );
        }

        return array_merge(
            array('status' => 'sent'),
            $sendResult
        );
    }

    /**
     * Execute the HTTP request for a webhook notification.
     *
     * @param string               $datasetType
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>|WP_Error
     */
    private function sendWebhookRequest($datasetType, array $summary, array $settings)
    {
        if (!\blc_is_webhook_notification_configured($settings)) {
            return new WP_Error('blc_webhook_not_configured', __('Aucun webhook configuré.', 'liens-morts-detector-jlg'));
        }

        $channel = isset($settings['channel']) ? (string) $settings['channel'] : 'generic';
        $message = \blc_render_notification_message_template($settings['message_template'] ?? '', $summary);
        if ($message === '') {
            $message = isset($summary['subject']) ? (string) $summary['subject'] : '';
        }

        $payload = \blc_build_notification_webhook_payload($channel, $message, $summary, $settings);
        $payload = \apply_filters('blc_notification_webhook_payload', $payload, $datasetType, $summary, $settings, $channel, $message);
        if ($payload instanceof WP_Error) {
            return $payload;
        }

        $encodedPayload = \wp_json_encode($payload);
        if (!is_string($encodedPayload)) {
            return new WP_Error('blc_webhook_json_encoding_failed', __('Impossible d’encoder le message de webhook en JSON.', 'liens-morts-detector-jlg'));
        }

        $requestArgs = array(
            'timeout'     => \apply_filters('blc_notification_webhook_timeout', 15, $datasetType, $settings, $payload),
            'redirection' => 3,
            'blocking'    => true,
            'headers'     => array('Content-Type' => 'application/json; charset=utf-8'),
            'body'        => $encodedPayload,
        );

        $requestArgs = \apply_filters('blc_notification_webhook_request_args', $requestArgs, $datasetType, $summary, $settings, $payload);

        $response = \wp_remote_post($settings['url'], $requestArgs);
        if (\is_wp_error($response)) {
            return $response;
        }

        $code = (int) \wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'blc_webhook_unexpected_status',
                sprintf(__('Le webhook a répondu avec le code HTTP %d.', 'liens-morts-detector-jlg'), $code),
                array('response' => $response, 'code' => $code)
            );
        }

        return array(
            'response'     => $response,
            'request_args' => $requestArgs,
            'payload'      => $payload,
            'code'         => $code,
            'message'      => $message,
        );
    }

    /**
     * Determine whether a notification should be throttled.
     */
    private function shouldThrottle(string $signature, int $window, int $timestamp): bool
    {
        if ($window <= 0 || $signature === '') {
            return false;
        }

        foreach ($this->getHistory() as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!isset($entry['signature']) || $entry['signature'] !== $signature) {
                continue;
            }

            if (!isset($entry['timestamp'])) {
                continue;
            }

            $entryTimestamp = (int) $entry['timestamp'];
            if ($entryTimestamp <= 0) {
                continue;
            }

            if (($timestamp - $entryTimestamp) < $window) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSeverity($value, string $default = 'info'): string
    {
        if (!is_scalar($value)) {
            return $default;
        }

        $normalized = strtolower((string) $value);

        return array_key_exists($normalized, self::SEVERITY_LEVELS) ? $normalized : $default;
    }

    private function compareSeverity(string $left, string $right): int
    {
        $leftLevel  = self::SEVERITY_LEVELS[$left] ?? self::SEVERITY_LEVELS['info'];
        $rightLevel = self::SEVERITY_LEVELS[$right] ?? self::SEVERITY_LEVELS['info'];

        if ($leftLevel === $rightLevel) {
            return 0;
        }

        return ($leftLevel > $rightLevel) ? 1 : -1;
    }

    private function shouldTriggerSeverity(string $severity, string $threshold): bool
    {
        return $this->compareSeverity($severity, $threshold) >= 0;
    }

    /**
     * @param string $channel
     * @param string $datasetType
     * @param string $context
     * @param array<string, mixed> $args
     */
    private function resolveThrottleWindow($channel, $datasetType, $context, array $args): int
    {
        $window = 0;
        if (isset($args['throttle']) && is_array($args['throttle'])) {
            if (isset($args['throttle'][$channel])) {
                $window = (int) $args['throttle'][$channel];
            }
        } elseif (isset($args['throttle_window'])) {
            $window = (int) $args['throttle_window'];
        }

        if (function_exists('apply_filters')) {
            $window = (int) \apply_filters('blc_notification_throttle_window', $window, $channel, $datasetType, $context, $args);
        }

        return max(0, $window);
    }

    /**
     * @param string               $channel
     * @param string               $datasetType
     * @param string               $context
     * @param string               $status
     * @param int                  $timestamp
     * @param array<string, mixed> $meta
     */
    private function recordHistory($channel, $datasetType, $context, $status, $timestamp, array $meta = array()): void
    {
        $entry = array(
            'channel'     => (string) $channel,
            'dataset_type'=> (string) $datasetType,
            'context'     => (string) $context,
            'status'      => (string) $status,
            'timestamp'   => (int) $timestamp,
        );

        foreach ($meta as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if ($key === 'recipient_count' || $key === 'webhook_code') {
                $entry[$key] = (int) $value;
                continue;
            }

            if ($key === 'severity') {
                $entry[$key] = $this->normalizeSeverity($value);
                continue;
            }

            if ($key === 'severity_threshold') {
                $entry[$key] = $this->normalizeSeverity($value, 'warning');
                continue;
            }

            $entry[$key] = $value;
        }

        $this->addHistoryEntry($entry);
    }

    private function addHistoryEntry(array $entry): void
    {
        $history = $this->getHistory();
        $history[] = $entry;

        $maxEntries = $this->resolveMaxHistory($entry);
        if ($maxEntries > 0 && count($history) > $maxEntries) {
            $history = array_slice($history, -$maxEntries);
        }

        $this->history      = $history;
        $this->historyDirty = true;
    }

    private function resolveMaxHistory(array $entry): int
    {
        $maxEntries = self::DEFAULT_HISTORY_SIZE;

        if (function_exists('apply_filters')) {
            $channel     = isset($entry['channel']) ? (string) $entry['channel'] : '';
            $datasetType = isset($entry['dataset_type']) ? (string) $entry['dataset_type'] : '';
            $context     = isset($entry['context']) ? (string) $entry['context'] : '';
            $maxEntries  = (int) \apply_filters('blc_notification_history_max_entries', $maxEntries, $channel, $datasetType, $context, $entry);
        }

        return max(0, $maxEntries);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getHistory(): array
    {
        if ($this->historyLoaded) {
            return $this->history;
        }

        $stored = array();
        if (function_exists('get_option')) {
            $stored = \get_option(self::HISTORY_OPTION, array());
        }

        if (!is_array($stored)) {
            $stored = array();
        }

        $normalized = array();
        foreach ($stored as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $normalized[] = array(
                'channel'        => isset($entry['channel']) ? (string) $entry['channel'] : '',
                'dataset_type'   => isset($entry['dataset_type']) ? (string) $entry['dataset_type'] : '',
                'context'        => isset($entry['context']) ? (string) $entry['context'] : '',
                'status'         => isset($entry['status']) ? (string) $entry['status'] : '',
                'timestamp'      => isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0,
                'signature'      => isset($entry['signature']) ? (string) $entry['signature'] : '',
                'recipient_count'=> isset($entry['recipient_count']) ? (int) $entry['recipient_count'] : null,
                'webhook_code'   => isset($entry['webhook_code']) ? (int) $entry['webhook_code'] : null,
                'error'          => isset($entry['error']) ? (string) $entry['error'] : '',
                'reason'         => isset($entry['reason']) ? (string) $entry['reason'] : '',
                'severity'       => isset($entry['severity']) ? $this->normalizeSeverity($entry['severity']) : '',
                'severity_threshold' => isset($entry['severity_threshold'])
                    ? $this->normalizeSeverity($entry['severity_threshold'], 'warning')
                    : '',
            );
        }

        foreach ($normalized as &$entry) {
            if ($entry['recipient_count'] === null) {
                unset($entry['recipient_count']);
            }
            if ($entry['webhook_code'] === null) {
                unset($entry['webhook_code']);
            }
            if ($entry['error'] === '') {
                unset($entry['error']);
            }
            if ($entry['signature'] === '') {
                unset($entry['signature']);
            }
            if ($entry['reason'] === '') {
                unset($entry['reason']);
            }
            if ($entry['severity'] === '') {
                unset($entry['severity']);
            }
            if ($entry['severity_threshold'] === '') {
                unset($entry['severity_threshold']);
            }
        }
        unset($entry);

        $this->history       = $normalized;
        $this->historyLoaded = true;

        return $this->history;
    }

    private function persistHistory(): void
    {
        if (!$this->historyDirty || !function_exists('update_option')) {
            return;
        }

        \update_option(self::HISTORY_OPTION, $this->history, false);
        $this->historyDirty = false;
    }

    /**
     * @param string               $channel
     * @param string               $datasetType
     * @param string               $context
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $meta
     */
    private function buildSignature($channel, $datasetType, $context, array $summary, array $meta = array()): string
    {
        $payload = array(
            'channel'      => (string) $channel,
            'dataset_type' => (string) $datasetType,
            'context'      => (string) $context,
            'subject'      => isset($summary['subject']) ? (string) $summary['subject'] : '',
            'message'      => isset($summary['message']) ? (string) $summary['message'] : '',
            'meta'         => $meta,
        );

        $encoded = \wp_json_encode($payload);
        if (!is_string($encoded)) {
            $encoded = json_encode($payload);
        }

        return $encoded !== false && $encoded !== null
            ? hash('sha256', (string) $encoded)
            : hash('sha256', serialize($payload));
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function summarizeWebhookSettings(array $settings): array
    {
        $summary = array(
            'channel' => isset($settings['channel']) ? (string) $settings['channel'] : 'generic',
        );

        if (isset($settings['url']) && is_string($settings['url']) && $settings['url'] !== '') {
            $summary['url_hash'] = hash('sha256', $settings['url']);
        }

        return $summary;
    }

    private function now(): int
    {
        $value = call_user_func($this->timeProvider);

        return (int) $value;
    }
}
