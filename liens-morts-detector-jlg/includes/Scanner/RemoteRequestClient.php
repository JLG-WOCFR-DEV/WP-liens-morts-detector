<?php

namespace JLG\BrokenLinks\Scanner;

use WP_Error;

class RemoteRequestClient implements HttpClientInterface
{
    /**
     * Default request arguments applied to every HTTP call.
     *
     * @var array<string, mixed>
     */
    private $defaultArgs;

    /**
     * Retry configuration (max attempts, delay, rate limiting, etc.).
     *
     * @var array<string, int>
     */
    private $retryPlan;

    /**
     * Pool of user agents used to minimise blocking by remote services.
     *
     * @var string[]
     */
    private $userAgents;

    /**
     * Timestamp of the last outbound HTTP request in microseconds.
     *
     * @var float
     */
    private $lastRequestAt = 0.0;

    public function __construct(array $defaultArgs = [], array $retryPlan = [], array $userAgents = [])
    {
        $defaults = [
            'timeout'     => 10,
            'redirection' => 5,
            'decompress'  => true,
        ];

        $retryDefaults = [
            'max_attempts'     => 3,
            'initial_delay_ms' => 250,
            'rate_limit_ms'    => 100,
            'max_delay_ms'     => 2000,
        ];

        if (function_exists('apply_filters')) {
            $defaults = (array) apply_filters('blc_remote_request_defaults', $defaults);
            $retryDefaults = (array) apply_filters('blc_remote_request_retry_defaults', $retryDefaults);
        }

        $this->defaultArgs = array_merge($defaults, $defaultArgs);
        $this->retryPlan   = array_merge($retryDefaults, $retryPlan);
        $this->userAgents  = $userAgents !== [] ? array_values(array_filter($userAgents, 'is_string')) : $this->getDefaultUserAgents();
    }

    public function head($url, array $args = [])
    {
        return $this->requestWithRetries('head', $url, $args);
    }

    public function get($url, array $args = [])
    {
        return $this->requestWithRetries('get', $url, $args);
    }

    public function responseCode($response)
    {
        return \wp_remote_retrieve_response_code($response);
    }

    /**
     * Perform an HTTP request with retry/backoff logic and basic rate limiting.
     *
     * @param string               $method HTTP verb to execute (head|get).
     * @param string               $url    Target URL.
     * @param array<string, mixed> $args   Additional request arguments.
     *
     * @return array|WP_Error
     */
    protected function requestWithRetries($method, $url, array $args)
    {
        $attempts       = max(1, (int) ($this->retryPlan['max_attempts'] ?? 3));
        $delayMs        = max(0, (int) ($this->retryPlan['initial_delay_ms'] ?? 250));
        $maxDelayMs     = max($delayMs, (int) ($this->retryPlan['max_delay_ms'] ?? 2000));
        $lastResponse   = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->enforceRateLimit();

            $requestArgs = $this->prepareRequestArguments($args, $attempt);
            $requestStartedAt = microtime(true);
            $lastResponse = $this->dispatchRequest($method, $url, $requestArgs);
            $durationMs = (int) round((microtime(true) - $requestStartedAt) * 1000);

            $retryAfter = $this->getRetryAfterDelay($lastResponse);
            $willRetry = $this->shouldRetry($lastResponse, $attempt, $attempts);

            $this->recordRequestMetrics(
                $method,
                $url,
                $requestArgs,
                $attempt,
                $attempts,
                $durationMs,
                $lastResponse,
                $willRetry,
                $retryAfter
            );

            if (!$willRetry) {
                return $lastResponse;
            }

            $delay = min($maxDelayMs, $delayMs * (2 ** ($attempt - 1)));
            if ($retryAfter !== null) {
                $delay = max($delay, $retryAfter);
            }

            $this->sleepMilliseconds($delay);
        }

        return $lastResponse;
    }

    /**
     * Merge default arguments with request-specific ones.
     *
     * @param array<string, mixed> $args
     * @param int                  $attempt
     *
     * @return array<string, mixed>
     */
    private function prepareRequestArguments(array $args, $attempt)
    {
        $prepared = array_merge($this->defaultArgs, $args);

        $userAgent = $this->pickUserAgent($attempt);

        $headers = [];
        if (isset($prepared['headers']) && is_array($prepared['headers'])) {
            $headers = $prepared['headers'];
        }

        $existingHeaderKey = null;
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            if (strcasecmp($name, 'User-Agent') !== 0) {
                continue;
            }

            $existingHeaderKey = $name;

            if (is_scalar($value)) {
                $candidate = trim((string) $value);
                if ($candidate !== '') {
                    $userAgent = $candidate;
                }
            }

            break;
        }

        if ($existingHeaderKey === null) {
            $existingHeaderKey = 'User-Agent';
        }

        $headers[$existingHeaderKey] = $userAgent;

        $prepared['headers'] = $headers;
        $prepared['user-agent'] = $userAgent;

        return $prepared;
    }

    /**
     * Issue the HTTP request using WordPress helper functions.
     *
     * @param string               $method
     * @param string               $url
     * @param array<string, mixed> $args
     *
     * @return array|WP_Error
     */
    private function dispatchRequest($method, $url, array $args)
    {
        $this->lastRequestAt = microtime(true);

        if ($method === 'head') {
            return \wp_safe_remote_head($url, $args);
        }

        return \wp_safe_remote_get($url, $args);
    }

    /**
     * Determine if the response should be retried.
     *
     * @param array|WP_Error $response
     * @param int            $attempt
     * @param int            $maxAttempts
     *
     * @return bool
     */
    private function shouldRetry($response, $attempt, $maxAttempts)
    {
        if ($attempt >= $maxAttempts) {
            return false;
        }

        if ($response instanceof WP_Error) {
            return true;
        }

        $code = (int) \wp_remote_retrieve_response_code($response);

        if ($code === 0) {
            return true;
        }

        if ($code === 429) {
            return true;
        }

        if ($code >= 500) {
            return true;
        }

        return false;
    }

    /**
     * Ensure a minimum delay between two consecutive requests.
     *
     * @return void
     */
    private function enforceRateLimit()
    {
        $intervalMs = max(0, (int) ($this->retryPlan['rate_limit_ms'] ?? 0));

        if ($intervalMs <= 0 || $this->lastRequestAt === 0.0) {
            return;
        }

        $elapsedMs = (microtime(true) - $this->lastRequestAt) * 1000;
        if ($elapsedMs >= $intervalMs) {
            return;
        }

        $this->sleepMilliseconds((int) ($intervalMs - $elapsedMs));
    }

    /**
     * Sleep helper accepting milliseconds.
     *
     * @param int $milliseconds
     *
     * @return void
     */
    private function sleepMilliseconds($milliseconds)
    {
        if ($milliseconds <= 0) {
            return;
        }

        if (function_exists('usleep')) {
            usleep($milliseconds * 1000);
        } else {
            $seconds = (int) ceil($milliseconds / 1000);
            if ($seconds > 0) {
                sleep($seconds);
            }
        }
    }

    /**
     * Extract retry-after delay from the response when available.
     *
     * @param array|WP_Error $response
     *
     * @return int|null Delay in milliseconds or null if none.
     */
    private function getRetryAfterDelay($response)
    {
        if ($response instanceof WP_Error) {
            return null;
        }

        $header = \wp_remote_retrieve_header($response, 'retry-after');
        if (!is_string($header) || $header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return (int) $header * 1000;
        }

        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }

        $delay = ($timestamp - time()) * 1000;

        return $delay > 0 ? (int) $delay : null;
    }

    /**
     * Pick a user agent string based on the attempt count.
     *
     * @param int $attempt
     *
     * @return string
     */
    private function pickUserAgent($attempt)
    {
        if ($this->userAgents === []) {
            return 'BrokenLinkChecker/1.0 (+https://wordpress.org/plugins/liens-morts-detector-jlg/)';
        }

        $index = ($attempt - 1) % count($this->userAgents);

        return $this->userAgents[$index];
    }

    /**
     * Build and dispatch request metrics for observability.
     *
     * @param string               $method
     * @param string               $url
     * @param array<string, mixed> $args
     * @param int                  $attempt
     * @param int                  $maxAttempts
     * @param int                  $durationMs
     * @param array|WP_Error       $response
     * @param bool                 $willRetry
     * @param int|null             $retryAfterMs
     *
     * @return void
     */
    private function recordRequestMetrics(
        $method,
        $url,
        array $args,
        $attempt,
        $maxAttempts,
        $durationMs,
        $response,
        $willRetry,
        $retryAfterMs
    ) {
        $metrics = $this->createRequestMetricsPayload(
            $method,
            $url,
            $args,
            $attempt,
            $maxAttempts,
            $durationMs,
            $response,
            $willRetry,
            $retryAfterMs
        );

        if (function_exists('\\blc_record_remote_request_metrics')) {
            \blc_record_remote_request_metrics($metrics);
        }

        if (function_exists('\\do_action')) {
            \do_action('blc_remote_request_metrics', $metrics, $response, $args);
        }
    }

    /**
     * Assemble the metrics payload describing the request attempt.
     *
     * @param string               $method
     * @param string               $url
     * @param array<string, mixed> $args
     * @param int                  $attempt
     * @param int                  $maxAttempts
     * @param int                  $durationMs
     * @param array|WP_Error       $response
     * @param bool                 $willRetry
     * @param int|null             $retryAfterMs
     *
     * @return array<string, mixed>
     */
    private function createRequestMetricsPayload(
        $method,
        $url,
        array $args,
        $attempt,
        $maxAttempts,
        $durationMs,
        $response,
        $willRetry,
        $retryAfterMs
    ) {
        $parsedUrl = function_exists('wp_parse_url') ? \wp_parse_url($url) : parse_url($url);

        $host = '';
        if (is_array($parsedUrl) && isset($parsedUrl['host'])) {
            $host = (string) $parsedUrl['host'];
            if (function_exists('blc_normalize_remote_host')) {
                $normalizedHost = \blc_normalize_remote_host($host);
                if ($normalizedHost !== '') {
                    $host = $normalizedHost;
                }
            } else {
                $host = strtolower($host);
            }
        }

        $path = '/';
        if (is_array($parsedUrl) && isset($parsedUrl['path'])) {
            $candidatePath = (string) $parsedUrl['path'];
            if ($candidatePath !== '') {
                $path = $candidatePath;
            }
        }

        $methodLabel = strtoupper((string) $method);
        $timestamp = time();
        $responseCode = 0;
        $success = false;

        $errorCode = '';
        $errorMessage = '';

        if ($response instanceof WP_Error) {
            $errorCode = (string) $response->get_error_code();
            $errorMessage = $response->get_error_message();
        } else {
            $responseCode = (int) \wp_remote_retrieve_response_code($response);
            if (!$willRetry && $responseCode >= 200 && $responseCode < 400) {
                $success = true;
            }
        }

        $metrics = [
            'method'         => $methodLabel,
            'url'            => (string) $url,
            'host'           => $host,
            'path'           => $path,
            'attempt'        => (int) $attempt,
            'max_attempts'   => (int) $maxAttempts,
            'duration_ms'    => max(0, (int) $durationMs),
            'timestamp'      => (int) $timestamp,
            'response_code'  => $responseCode,
            'success'        => $success,
            'will_retry'     => (bool) $willRetry,
            'retry_after_ms' => ($retryAfterMs !== null) ? max(0, (int) $retryAfterMs) : null,
        ];

        if ($errorCode !== '' || $errorMessage !== '') {
            $metrics['error_code'] = $errorCode;
            $metrics['error_message'] = $errorMessage;
        }

        if (isset($args['user-agent']) && is_string($args['user-agent'])) {
            $metrics['user_agent'] = trim($args['user-agent']);
        }

        return $metrics;
    }

    /**
     * Provide a default pool of user agent strings inspired by common browsers.
     *
     * @return string[]
     */
    private function getDefaultUserAgents()
    {
        return [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Safari/605.1.15',
            'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
        ];
    }
}

