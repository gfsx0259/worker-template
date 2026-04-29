<?php
declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

use Interop\Queue\Destination;
use Interop\Queue\Exception;
use Interop\Queue\Exception\InvalidDestinationException;
use Interop\Queue\Exception\InvalidMessageException;
use Interop\Queue\Producer;
use Interop\Queue\Context;

final readonly class QueueProducer
{
    public function __construct(
        private Producer $producer,
        private Context $context,
    ) {}

    /**
     * @throws InvalidDestinationException
     * @throws InvalidMessageException
     * @throws Exception|\JsonException
     */
    public function send(Destination $destination, array $body, array $headers = []): void
    {
        $message = $this->context->createMessage(
            json_encode($body, JSON_THROW_ON_ERROR),
            $headers,
        );

        $this->producer->send($destination, $message);
    }

    public function sendWithoutWrapper(Destination $destination, array $body, array $headers = []): void
    {
        $this->send($destination, $body, array_merge(['format' => QueueSerializer::FORMAT_RAW], $headers));
    }
}
