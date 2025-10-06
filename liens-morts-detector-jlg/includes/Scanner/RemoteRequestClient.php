<?php

namespace JLG\BrokenLinks\Scanner;

use WP_Error;

class RemoteRequestClient
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
            $lastResponse = $this->dispatchRequest($method, $url, $requestArgs);

            if (!$this->shouldRetry($lastResponse, $attempt, $attempts)) {
                return $lastResponse;
            }

            $delay = min($maxDelayMs, $delayMs * (2 ** ($attempt - 1)));
            $retryAfter = $this->getRetryAfterDelay($lastResponse);
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
        if (!isset($prepared['headers']) || !is_array($prepared['headers'])) {
            $prepared['headers'] = [];
        }

        if (!isset($prepared['headers']['User-Agent'])) {
            $prepared['headers']['User-Agent'] = $userAgent;
        }

        $prepared['user-agent'] = $prepared['headers']['User-Agent'];

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
        if ($header === '') {
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

