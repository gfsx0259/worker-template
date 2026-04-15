<?php

declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

use Interop\Queue\Consumer;
use Interop\Queue\Message;

interface BatchProcessorInterface
{
    /** Принимает функцию подтверждения от транспорта */
    public function configure(callable $ackCallback): void;

    /** Вызывается когда очередь пуста (таймаут receive) */
    public function onIdle(): void;

    /** Вызывается после обработки каждого сообщения */
    public function onAfterMessage(Message $message, array $tags): void;

    /** Вызывается каждый цикл (внутри процессора своя логика таймера) */
    public function onCleanup(): void;

    /** Вызывается перед завершением работы воркера */
    public function onShutdown(): void;

    /** Добавляет сообщение в буфер ожидания подтверждения */
    public function addPendingMessage(Message $message): void;

    /** Подтверждает все накопленные сообщения и очищает буфер */
    public function acknowledgeAndClear(Consumer $consumer): void;
}
