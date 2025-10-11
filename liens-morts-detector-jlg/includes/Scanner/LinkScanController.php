<?php

namespace JLG\BrokenLinks\Scanner;

class LinkScanController
{
    /** @var ScanQueue */
    private $queue;

    public function __construct(ScanQueue $queue)
    {
        $this->queue = $queue;
    }

    public function runBatch(int $batch = 0, bool $isFullScan = false, bool $bypassRestWindow = false, array $jobContext = [])
    {
        return $this->queue->runBatch($batch, $isFullScan, $bypassRestWindow, $jobContext);
    }

    public function run($batch = 0, $is_full_scan = false, $bypass_rest_window = false, array $jobContext = [])
    {
        return $this->runBatch((int) $batch, (bool) $is_full_scan, (bool) $bypass_rest_window, $jobContext);
    }
}

