<?php

declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

final class MetricsAggregator implements MetricsAggregatorInterface
{
    private const string HISTOGRAM_NAME_TEMPLATE = '%s.%s';

    private ?MeterInterface $meter;

    private ?HistogramInterface $processingTimeHistogram = null;
    private ?HistogramInterface $flushDurationHistogram = null;
    private ?HistogramInterface $queueWaitTimeHistogram = null;

    public function __construct(
        private readonly MeterProviderInterface $meterProvider,
        private readonly string $serviceName,
    ) {
        $this->meter = $this->meterProvider->getMeter($this->serviceName);

        $this->registerHistograms();
    }

    public function flush(): void
    {
        $this->meterProvider->forceFlush();
    }

    public function recordProcessingTime(float $durationMs, array $attributes): void
    {
        $this->processingTimeHistogram?->record($durationMs, $attributes);
    }

    public function recordFlushDuration(float $durationMs, int $batchSize): void
    {
        $this->flushDurationHistogram?->record($durationMs, [
            'batch_size' => $this->bucketizeBatchSize($batchSize),
        ]);
    }

    public function recordQueueWaitTime(float $waitMs, int $batchSize): void
    {
        $this->queueWaitTimeHistogram?->record($waitMs, [
            'batch_size' => $this->bucketizeBatchSize($batchSize),
        ]);
    }

    /**
     * Группирует размер пачки в бакеты, чтобы не раздувать кардинальность
     */
    private function bucketizeBatchSize(int $size): string
    {
        if ($size <= 5) return '1-5';
        if ($size <= 10) return '6-10';
        if ($size <= 25) return '11-25';
        if ($size <= 50) return '26-50';
        return '51+';
    }

    private function registerHistograms(): void
    {
        $this->processingTimeHistogram = $this->meter->createHistogram(
            sprintf(self::HISTOGRAM_NAME_TEMPLATE, $this->serviceName, 'processing_time_ms'),
            'Time spent processing incoming message',
            'ms',
            [0.1, 0.5, 1, 2.5, 5, 10, 25, 50, 100, 250, 500, 1000]
        );

        $this->flushDurationHistogram = $this->meter->createHistogram(
            sprintf(self::HISTOGRAM_NAME_TEMPLATE, $this->serviceName, 'flush_duration_ms'),
            'Time spent flushing batch to database',
            'ms',
            [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000]
        );

        $this->queueWaitTimeHistogram = $this->meter->createHistogram(
            sprintf(self::HISTOGRAM_NAME_TEMPLATE, $this->serviceName, 'queue_wait_ms'),
            'Time spent waiting in buffer before acknowledge',
            'ms',
            [10, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000]
        );
    }
}