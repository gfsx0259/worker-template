<?php

declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

use Interop\Queue\Message;

trait BatchProcessorLifecycleDefaults
{
    /**
     * @var null|callable(Message): void
     */
    private $ackCallback = null;

    public function configure(callable $ackCallback): void
    {
        $this->ackCallback = $ackCallback;
    }

    /** Безопасный вызов ACK изнутри процессора */
    protected function ack(Message $message): void
    {
        if ($this->ackCallback !== null) {
            ($this->ackCallback)($message);
        }
    }

    public function onIdle(): void {}
    public function onAfterMessage(Message $message, array $tags): void {}
    public function onCleanup(): void {}
    public function onShutdown(): void {}
}
