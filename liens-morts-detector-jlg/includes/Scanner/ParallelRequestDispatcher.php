<?php

namespace JLG\BrokenLinks\Scanner;

use Requests_Exception;
use Requests_Response;
use WP_Error;

class ParallelRequestDispatcher
{
    /** @var HttpClientInterface */
    private $client;

    /** @var int */
    private $concurrency;

    /** @var callable */
    private $dispatcher;

    /** @var int */
    private $linkDelayMs;

    /** @var float */
    private $lastRemoteRequestCompletedAt = 0.0;

    /** @var array<int, array<string, mixed>> */
    private $pending = [];

    public function __construct(HttpClientInterface $client, $concurrency, $linkDelayMs, $dispatcher = null)
    {
        $this->client = $client;
        $this->concurrency = max(1, (int) $concurrency);
        $this->linkDelayMs = max(0, (int) $linkDelayMs);
        $this->dispatcher = $dispatcher ?: $this->resolveDispatcher();
    }

    public static function fromFilters(HttpClientInterface $client, $concurrency, $linkDelayMs): self
    {
        $dispatcher = null;
        if (function_exists('apply_filters')) {
            $dispatcher = apply_filters('blc_parallel_requests_dispatcher', null);
        }

        if (!is_callable($dispatcher)) {
            $dispatcher = static function (array $requests) {
                if (empty($requests)) {
                    return [];
                }

                if (!class_exists('Requests')) {
                    if (defined('ABSPATH') && defined('WPINC')) {
                        $requests_class_path = trailingslashit(ABSPATH) . WPINC . '/class-requests.php';
                        if (file_exists($requests_class_path)) {
                            require_once $requests_class_path;
                        }
                    }
                    if (!class_exists('Requests')) {
                        return [];
                    }
                }

                return \Requests::request_multiple($requests);
            };
        }

        return new self($client, $concurrency, $linkDelayMs, $dispatcher);
    }

    public function enqueue(
        $url,
        array $headArgs,
        array $getArgs,
        $scanMethod,
        array $temporaryStatuses,
        callable $callback
    ): void {
        $this->pending[] = [
            'url'                => $url,
            'head_args'          => $headArgs,
            'get_args'           => $getArgs,
            'scan_method'        => $scanMethod,
            'temporary_statuses' => $temporaryStatuses,
            'callback'           => $callback,
        ];

        $this->dispatch(false);
    }

    public function drain(): void
    {
        $this->dispatch(true);
    }

    private function dispatch($force): void
    {
        while (!empty($this->pending) && ($force || count($this->pending) >= $this->concurrency)) {
            $batch = array_splice($this->pending, 0, min($this->concurrency, count($this->pending)));
            $this->executeBatch($batch);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function executeBatch(array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        $headRequests = [];
        foreach ($batch as $index => $job) {
            $requestKey = 'head-' . $index;
            $headRequests[$requestKey] = $this->buildRequest($job['url'], 'HEAD', $job['head_args']);
        }

        $headResponses = $this->sendRequests($headRequests);

        $getJobs = [];

        foreach ($batch as $index => $job) {
            $requestKey = 'head-' . $index;
            $headResponse = $headResponses[$requestKey] ?? new WP_Error('blc_missing_head_response', 'Missing HEAD response.');

            $needsGetFallback = false;
            $fallbackDueToTemporaryStatus = false;
            $headRequestDisallowed = false;

            if ($job['scan_method'] === 'precise') {
                if (is_wp_error($headResponse)) {
                    $needsGetFallback = true;
                } else {
                    $headStatus = (int) $this->client->responseCode($headResponse);
                    if (in_array($headStatus, $job['temporary_statuses'], true)) {
                        $needsGetFallback = true;
                        $fallbackDueToTemporaryStatus = true;
                    } elseif (in_array($headStatus, [403, 405, 501], true)) {
                        $needsGetFallback = true;
                    }
                }
            } else {
                if (!is_wp_error($headResponse)) {
                    $headStatus = (int) $this->client->responseCode($headResponse);
                    if (in_array($headStatus, [403, 405, 501], true)) {
                        $needsGetFallback = true;
                        $headRequestDisallowed = true;
                    }
                }
            }

            if ($needsGetFallback) {
                $getJobs[] = [
                    'index'                      => $index,
                    'job'                        => $job,
                    'fallback_due_to_temporary'  => $fallbackDueToTemporaryStatus,
                    'head_request_disallowed'    => $headRequestDisallowed,
                ];
            } else {
                $this->triggerCallback(
                    $job,
                    $headResponse,
                    $headRequestDisallowed,
                    $fallbackDueToTemporaryStatus,
                    false
                );
            }
        }

        if (empty($getJobs)) {
            return;
        }

        $getRequests = [];
        foreach ($getJobs as $entry) {
            $requestKey = 'get-' . $entry['index'];
            $getRequests[$requestKey] = $this->buildRequest($entry['job']['url'], 'GET', $entry['job']['get_args']);
        }

        $getResponses = $this->sendRequests($getRequests);

        foreach ($getJobs as $entry) {
            $requestKey = 'get-' . $entry['index'];
            $response = $getResponses[$requestKey] ?? new WP_Error('blc_missing_get_response', 'Missing GET response.');
            $this->triggerCallback(
                $entry['job'],
                $response,
                $entry['head_request_disallowed'],
                $entry['fallback_due_to_temporary'],
                true
            );
        }
    }

    private function triggerCallback(
        array $job,
        $response,
        $headRequestDisallowed,
        $fallbackDueToTemporaryStatus,
        $usedGetRequest
    ): void {
        $callback = $job['callback'];
        $callback($response, $headRequestDisallowed, $fallbackDueToTemporaryStatus, $usedGetRequest);
    }

    private function buildRequest($url, $method, array $args): array
    {
        $headers = [];
        if (isset($args['user-agent'])) {
            $headers['user-agent'] = (string) $args['user-agent'];
        }

        $options = [];
        if (isset($args['timeout'])) {
            $options['timeout'] = (float) $args['timeout'];
        }
        if (isset($args['redirection'])) {
            $options['redirects'] = (int) $args['redirection'];
        }
        if (isset($args['limit_response_size'])) {
            $options['max_bytes'] = (int) $args['limit_response_size'];
        }

        return [
            'url'     => $url,
            'type'    => $method,
            'headers' => $headers,
            'data'    => $args['body'] ?? null,
            'options' => $options,
            'args'    => $args,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $requests
     * @return array<string, mixed>
     */
    private function sendRequests(array $requests): array
    {
        if (empty($requests)) {
            return [];
        }

        foreach ($requests as $_) {
            $this->waitForRemoteSlot();
        }

        $dispatcher = $this->dispatcher;
        $responses = $dispatcher($requests);
        if (!is_array($responses)) {
            $responses = [];
        }

        $normalized = [];
        foreach ($requests as $key => $requestSpec) {
            $raw = $responses[$key] ?? null;
            if ($raw === null) {
                $args = isset($requestSpec['args']) && is_array($requestSpec['args']) ? $requestSpec['args'] : [];
                $method = strtoupper((string) ($requestSpec['type'] ?? 'GET'));
                if ($method === 'HEAD') {
                    $raw = $this->client->head($requestSpec['url'], $args);
                } else {
                    $raw = $this->client->get($requestSpec['url'], $args);
                }
            }
            $normalized[$key] = $this->normalizeResponse($raw);
            $this->markRemoteRequestComplete();
        }

        return $normalized;
    }

    private function normalizeResponse($raw)
    {
        if ($raw instanceof WP_Error) {
            return $raw;
        }

        if ($raw instanceof Requests_Response) {
            return [
                'headers'  => $raw->headers->getAll(),
                'body'     => $raw->body,
                'response' => [
                    'code'    => $raw->status_code,
                    'message' => $raw->status_text,
                ],
            ];
        }

        if ($raw instanceof Requests_Exception) {
            $code = method_exists($raw, 'getCode') ? (int) $raw->getCode() : 0;
            return new WP_Error('http_request_failed', $raw->getMessage(), ['status' => $code]);
        }

        if (is_array($raw)) {
            return $raw;
        }

        if ($raw === null) {
            return new WP_Error('http_request_failed', 'Empty HTTP response.');
        }

        return $raw;
    }

    private function waitForRemoteSlot(): void
    {
        if ($this->linkDelayMs <= 0) {
            return;
        }

        $delaySeconds = $this->linkDelayMs / 1000;
        if ($this->lastRemoteRequestCompletedAt > 0) {
            $elapsed = microtime(true) - $this->lastRemoteRequestCompletedAt;
            $remaining = $delaySeconds - $elapsed;
            if ($remaining > 0) {
                usleep((int) round($remaining * 1000000));
            }
        }
    }

    private function markRemoteRequestComplete(): void
    {
        $this->lastRemoteRequestCompletedAt = microtime(true);
    }

    private function resolveDispatcher(): callable
    {
        return static function (array $requests) {
            if (empty($requests)) {
                return [];
            }

            if (!class_exists('Requests')) {
                if (defined('ABSPATH') && defined('WPINC')) {
                    $requests_class_path = trailingslashit(ABSPATH) . WPINC . '/class-requests.php';
                    if (file_exists($requests_class_path)) {
                        require_once $requests_class_path;
                    }
                }
                if (!class_exists('Requests')) {
                    return [];
                }
            }

            return \Requests::request_multiple($requests);
        };
    }
}
