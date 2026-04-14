<?php

declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

interface MetricsAggregatorInterface
{
    public function flush();

    public function recordProcessingTime(float $durationMs, array $attributes): void;

    public function recordFlushDuration(float $durationMs, int $batchSize): void;

    public function recordQueueWaitTime(float $waitMs, int $batchSize): void;
}
