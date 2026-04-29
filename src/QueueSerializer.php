<?php
declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

use Enqueue\RdKafka\RdKafkaMessage;
use Enqueue\RdKafka\Serializer;
use Interop\Queue\Message;

class QueueSerializer implements Serializer
{
    public const int FORMAT_RAW = 1;

    public function toString(Message $message): string
    {
        $body = $message->getBody();

        if ($message->getProperty('format') === self::FORMAT_RAW) {
            return $body;
        }

        $json = json_encode([
            'body' => $body,
            'properties' => $message->getProperties(),
            'headers' => $message->getHeaders(),
        ]);

        if (\JSON_ERROR_NONE !== json_last_error()) {
            throw new \InvalidArgumentException(sprintf('The malformed json given. Error %s and message %s', json_last_error(), json_last_error_msg()));
        }

        return $json;
    }

    public function toMessage(string $string): RdKafkaMessage
    {
        return json_decode($string);
    }
}
