<?php

declare(strict_types=1);

/* @var array $params */

use Enthusiast\WorkerTemplate\BatchProcessorInterface;
use Enthusiast\WorkerTemplate\BatchProcessorNoop;
use Enthusiast\WorkerTemplate\MetricsAggregator;
use Enthusiast\WorkerTemplate\MetricsAggregatorInterface;
use OpenTelemetry\API\Globals;
use Interop\Queue\Producer;
use Interop\Queue\Context;
use Enthusiast\WorkerTemplate\QueueSerializer;

return [
    BatchProcessorInterface::class => BatchProcessorNoop::class,
    MetricsAggregatorInterface::class => static function () {
        return new MetricsAggregator(
            Globals::meterProvider(),
            $_ENV['OTEL_SERVICE_NAME'],
        );
    },
    Producer::class => static function (Context $transportContext) {
        $producer = $transportContext->createProducer();
        $producer->setSerializer(new QueueSerializer());

        return $producer;
    },
];
