<?php

declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

use Redis;

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
    public function set(string $key, mixed $value, array|int|null $options = null): Redis|string|bool;

    public function del(string ...$keys): Redis|int|false;

    public function exists(string $key): Redis|bool|int;

    public function zRem(string $key, mixed ...$members): Redis|int|false;

    public function zAdd(string $key, float $score, string $member): Redis|int|false;

    public function hMSet(string $key, array $fields): Redis|bool;

    public function hGet(string $key, string $field): Redis|string|false;

    public function incr(string $key): Redis|int|false;

    public function expire(string $key, int $ttl): Redis|bool;

    public function multi(int $mode = \Redis::MULTI): Redis|false;

    public function exec(): array|false;
}
