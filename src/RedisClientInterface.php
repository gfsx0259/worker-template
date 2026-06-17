<?php

declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

interface RedisClientInterface
{
    public function rawCommand(mixed ...$args): mixed;

    public function getLastError(): ?string;

    public function clearLastError(): void;

    /**
     * @param list<string> $args
     */
    public function eval(string $script, array $args, int $numKeys): mixed;

    /**
     * @param array<int|string, mixed>|int|null $options
     */
    public function set(string $key, mixed $value, array|int|null $options = null): string|bool;

    public function del(string ...$keys): int|false;

    public function exists(string $key): bool|int;

    public function zRem(string $key, mixed ...$members): int|false;

    public function zAdd(string $key, float $score, string $member): int|false;

    public function hMSet(string $key, array $fields): bool;

    public function expire(string $key, int $ttl): bool;

    public function multi(int $mode = \Redis::MULTI): mixed;

    public function exec(): array|false;
}
