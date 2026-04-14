<?php

declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

use Exception;
use Interop\Queue\Consumer;
use Interop\Queue\Context;
use Interop\Queue\Message;
use OpenTelemetry\API\Trace\Span;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Базовый класс для воркеров с накоплением данных (batching)
 */
abstract class AbstractBufferedConsumer extends Command
{
    protected ?Consumer $consumer = null;

    public function __construct(
        private readonly Context $transportContext,
        protected readonly LoggerInterface $logger,
        protected readonly MetricsAggregatorInterface $metricsAggregator,
        protected readonly BatchProcessorInterface $batchProcessor,
        private readonly string $inputTopic,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->consumer = $this->transportContext->createConsumer(
            $this->transportContext->createQueue($this->inputTopic)
        );

        $running = true;

        pcntl_signal(SIGTERM, function () use (&$running, $output) {
            $output->writeln("\n⚠️  Received SIGTERM, shutting down...");
            $running = false;
        });
        pcntl_signal(SIGINT, function () use (&$running, $output) {
            $output->writeln("\n⚠️  Received SIGINT, shutting down...");
            $running = false;
        });

        $output->writeln("🚀 Consumer started. Press Ctrl+C to stop.\n");

        while ($running) {
            pcntl_signal_dispatch();

            try {
                $message = $this->consumer->receive(500);

                if ($message === null) {
                    if ($this->batchProcessor->shouldFlush() && $this->batchProcessor->flush()) {
                        $this->batchProcessor->acknowledgeAndClear($this->consumer);
                    }
                    continue;
                }

                $span = Span::getCurrent();
                $spanScope = $span->activate();

                $processingStart = hrtime(true);

                $tags = [];

                try {
                    $tags = $this->processMessage($message);
                } catch (Exception $e) {
                    $this->logger->error("Processing failed: " . $e->getMessage());
                } finally {
                    $this->metricsAggregator->recordProcessingTime((hrtime(true) - $processingStart) / 1e6, $tags);

                    try {
                        $spanScope->detach();
                    } catch (Throwable) {}

                    $span->end();
                }

                if ($this->batchProcessor->shouldFlush() && $this->batchProcessor->flush()) {
                    $this->batchProcessor->acknowledgeAndClear($this->consumer);
                }

                $this->batchProcessor->cleanUp(fn (Message $message) => $this->consumer->acknowledge($message));
            } catch (Exception $e) {
                $this->logger->error("Kafka error: " . $e->getMessage());
                sleep(2);
            }
        }

        $output->writeln("🛑 Stopping... Flushing remaining data.");

        if ($this->batchProcessor->flush()) {
            $this->batchProcessor->acknowledgeAndClear($this->consumer);
        }

        return ExitCode::OK;
    }

    /**
     * Метод, который должны реализовать дочерние воркеры.
     * Должен возвращать теги для метрик (например, ['matched' => 'true']).
     */
    abstract protected function processMessage(Message $message): array;
}