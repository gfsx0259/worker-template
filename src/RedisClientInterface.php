<?php

declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

interface RedisClientInterface
{
    public function rawCommand(mixed ...$args): mixed;

    public function getLastError(): ?string;
}
