<?php

namespace JLG\BrokenLinks\Scanner\QueueDrivers;

require_once __DIR__ . '/../ScanQueue.php';

use JLG\BrokenLinks\Scanner\QueueDriverInterface;

class RedisQueueDriver implements QueueDriverInterface
{
    /** @var \Redis|null */
    private $client;

    /** @var bool */
    private $connected = false;

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var string */
    private $password;

    /** @var string */
    private $queueKey;

    /** @var int */
    private $blockingTimeout;

    public function __construct(array $config = [])
    {
        $this->host = isset($config['host']) ? (string) $config['host'] : '127.0.0.1';
        $this->port = isset($config['port']) ? (int) $config['port'] : 6379;
        $this->password = isset($config['password']) ? (string) $config['password'] : '';
        $this->queueKey = isset($config['queue']) ? (string) $config['queue'] : 'blc:scan-queue';
        $this->blockingTimeout = isset($config['blocking_timeout']) ? (int) $config['blocking_timeout'] : 5;
    }

    public function getSlug(): string
    {
        return 'redis';
    }

    public function getLabel(): string
    {
        if (function_exists('__')) {
            return __('Redis (Streams/Listes)', 'liens-morts-detector-jlg');
        }

        return 'Redis';
    }

    public function scheduleBatch(array $job, int $delaySeconds = 0): bool
    {
        if (!$this->connect()) {
            return false;
        }

        $payload = [
            'batch'              => isset($job['batch']) ? (int) $job['batch'] : 0,
            'is_full_scan'       => isset($job['is_full_scan']) ? (bool) $job['is_full_scan'] : false,
            'bypass_rest_window' => isset($job['bypass_rest_window']) ? (bool) $job['bypass_rest_window'] : false,
            'context'            => isset($job['context']) && is_array($job['context']) ? $job['context'] : [],
            'available_at'       => time() + max(0, $delaySeconds),
            'enqueued_at'        => time(),
        ];

        $encoded = $this->encode($payload);
        if ($encoded === '') {
            return false;
        }

        try {
            return (bool) $this->client->rPush($this->queueKey, $encoded);
        } catch (\RedisException $exception) {
            $this->connected = false;
            return false;
        }
    }

    public function receiveBatch(): ?array
    {
        if (!$this->connect()) {
            return null;
        }

        try {
            $result = $this->client->brPop([$this->queueKey], max(1, $this->blockingTimeout));
        } catch (\RedisException $exception) {
            $this->connected = false;
            return null;
        }

        if (!is_array($result) || count($result) < 2) {
            return null;
        }

        $payload = $this->decode($result[1]);
        if (!is_array($payload)) {
            return null;
        }

        $availableAt = isset($payload['available_at']) ? (int) $payload['available_at'] : 0;
        if ($availableAt > time()) {
            // Not ready yet: requeue at the end and wait.
            try {
                $this->client->rPush($this->queueKey, $result[1]);
            } catch (\RedisException $exception) {
                $this->connected = false;
            }

            $sleepDuration = min(5, max(1, $availableAt - time()));
            sleep($sleepDuration);

            return null;
        }

        return $payload;
    }

    public function acknowledge(array $job): void
    {
        // Items popped with BRPOP are removed immediately.
    }

    public function reportFailure(array $job, \Throwable $error): void
    {
        if (!$this->connect()) {
            return;
        }

        $job['error'] = $error->getMessage();
        $job['failed_at'] = time();

        try {
            $this->client->lPush($this->queueKey . ':failed', $this->encode($job));
        } catch (\RedisException $exception) {
            $this->connected = false;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function supportsAsyncPull(): bool
    {
        return true;
    }

    private function connect(): bool
    {
        if ($this->connected && $this->client instanceof \Redis) {
            return true;
        }

        if (!class_exists('Redis')) {
            return false;
        }

        $this->client = new \Redis();

        try {
            $this->connected = $this->client->connect($this->host, $this->port, 2.5);
            if (!$this->connected) {
                return false;
            }

            if ($this->password !== '') {
                $authenticated = $this->client->auth($this->password);
                if (!$authenticated) {
                    $this->connected = false;
                    return false;
                }
            }

            $this->client->setOption(\Redis::OPT_READ_TIMEOUT, $this->blockingTimeout);
        } catch (\RedisException $exception) {
            $this->connected = false;
            return false;
        }

        return $this->connected;
    }

    private function encode(array $payload): string
    {
        if (function_exists('wp_json_encode')) {
            $encoded = wp_json_encode($payload);
        } else {
            $encoded = json_encode($payload);
        }

        if (!is_string($encoded)) {
            return '';
        }

        return $encoded;
    }

    private function decode($payload)
    {
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}

