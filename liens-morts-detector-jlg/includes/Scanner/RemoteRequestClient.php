<?php

namespace JLG\BrokenLinks\Scanner;

class RemoteRequestClient
{
    private int $linkDelayMs;
    private float $lastCompletedAt = 0.0;

    public function __construct(int $linkDelayMs)
    {
        if ($linkDelayMs < 0) {
            $linkDelayMs = 0;
        }

        $this->linkDelayMs = $linkDelayMs;
    }

    public function waitForSlot(): void
    {
        if ($this->linkDelayMs <= 0) {
            return;
        }

        $delaySeconds = $this->linkDelayMs / 1000;
        if ($this->lastCompletedAt > 0) {
            $elapsed = microtime(true) - $this->lastCompletedAt;
            $remaining = $delaySeconds - $elapsed;
            if ($remaining > 0) {
                usleep((int) round($remaining * 1000000));
            }
        }
    }

    public function markRequestComplete(): void
    {
        $this->lastCompletedAt = microtime(true);
    }

    public function head(string $url, array $args = [])
    {
        $this->waitForSlot();
        $response = wp_safe_remote_head($url, $args);
        $this->markRequestComplete();

        return $response;
    }

    public function get(string $url, array $args = [])
    {
        $this->waitForSlot();
        $response = wp_safe_remote_get($url, $args);
        $this->markRequestComplete();

        return $response;
    }
}
