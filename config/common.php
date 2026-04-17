<?php

declare(strict_types=1);

/* @var array $params */

use Enthusiast\WorkerTemplate\BatchProcessorInterface;
use Enthusiast\WorkerTemplate\BatchProcessorNoop;
use Enthusiast\WorkerTemplate\MetricsAggregator;
use Enthusiast\WorkerTemplate\MetricsAggregatorInterface;
use OpenTelemetry\API\Globals;

return [
    BatchProcessorInterface::class => BatchProcessorNoop::class,
    MetricsAggregatorInterface::class => static function () {
        return new MetricsAggregator(
            Globals::meterProvider(),
            $_ENV['OTEL_SERVICE_NAME'],
        );
    },
];
