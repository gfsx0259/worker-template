<?php

declare(strict_types=1);

namespace App\Service;

use Interop\Queue\Consumer;
use Interop\Queue\Message;

interface BatchProcessorInterface
{
    /**
     * Проверяет, нужно ли сбросить накопленные данные
     */
    public function shouldFlush(): bool;

    /**
     * Выполняет сброс данных в БД/хранилище
     */
    public function flush(): bool;

    /**
     * Добавляет сообщение в буфер для отложенного ACK
     */
    public function addPendingMessage(Message $message): void;

    /**
     * Подтверждает все сообщения из буфера и очищает его
     */
    public function acknowledgeAndClear(Consumer $consumer): void;
}
