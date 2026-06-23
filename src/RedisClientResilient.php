<?php

declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

use Exception;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use RedisSentinel;
use RuntimeException;

/**
 * Redis-клиент с разрешением master через Sentinel и переподключением при сбоях.
 *
 * Предназначен для долгоживущих воркеров: при failover адрес master меняется,
 * поэтому при ошибке соединения клиент заново опрашивает Sentinel.
 */
final class RedisClientResilient implements RedisClientInterface
{
    private const float CONNECT_TIMEOUT = 1.0;
    private const float READ_TIMEOUT = 1.0;
    private const int RETRY_INTERVAL_MS = 100;

    private ?Redis $redis = null;

    /** @var list<array{host: string, port: int}> */
    private readonly array $sentinelNodes;

    public function __construct(
        string $sentinelHosts,
        private readonly ?string $masterPassword = null,
        private readonly ?string $sentinelPassword = null,
        private readonly ?string $persistentId = null,
        private readonly string $masterName = 'mymaster',
        private readonly ?LoggerInterface $logger = null,
        private readonly int $maxAttempts = 3,
    ) {
        $this->sentinelNodes = self::parseSentinelHosts($sentinelHosts);
    }

    /**
     * @throws Exception
     */
    public function rawCommand(mixed ...$args): mixed
    {
        return $this->execute(static fn (Redis $redis): mixed => $redis->rawCommand(...$args));
    }

    public function getLastError(): ?string
    {
        return $this->redis?->getLastError() ?: null;
    }

    public function clearLastError(): void
    {
        $this->redis?->clearLastError();
    }

    /**
     * @param list<string> $args
     */
    public function eval(string $script, array $args, int $numKeys): mixed
    {
        return $this->execute(static fn (Redis $redis): mixed => $redis->eval($script, $args, $numKeys));
    }

    /**
     * @param array<int|string, mixed>|int|null $options
     */
    public function set(string $key, mixed $value, array|int|null $options = null): Redis|string|bool
    {
        return $this->execute(static fn (Redis $redis): mixed => $redis->set($key, $value, $options));
    }

    public function del(string ...$keys): Redis|int|false
    {
        return $this->execute(static fn (Redis $redis): mixed => $redis->del(...$keys));
    }

    public function exists(string $key): Redis|bool|int
    {
        return $this->execute(static fn (Redis $redis): mixed => $redis->exists($key));
    }

    public function zRem(string $key, mixed ...$members): Redis|int|false
    {
        return $this->execute(static fn (Redis $redis): mixed => $redis->zRem($key, ...$members));
    }

    public function zAdd(string $key, float $score, string $member): Redis|int|false
    {
        return $this->execute(static fn (Redis $redis): mixed => $redis->zAdd($key, $score, $member));
    }

    public function hMSet(string $key, array $fields): Redis|bool
    {
        return $this->execute(static fn (Redis $redis): mixed => $redis->hMSet($key, $fields));
    }

    public function hGet(string $key, string $field): Redis|string|false
    {
        return $this->execute(static fn (Redis $redis): mixed => $redis->hGet($key, $field));
    }

    public function incr(string $key): Redis|int|false
    {
        return $this->execute(static fn (Redis $redis): mixed => $redis->incr($key));
    }

    public function expire(string $key, int $ttl): Redis|bool
    {
        return $this->execute(static fn (Redis $redis): mixed => $redis->expire($key, $ttl));
    }

    public function multi(int $mode = \Redis::MULTI): Redis|false
    {
        return $this->execute(static fn (Redis $redis): Redis|false => $redis->multi($mode));
    }

    public function exec(): array|false
    {
        return $this->execute(static fn (Redis $redis): array|false => $redis->exec());
    }

    /**
     * @template T
     * @param callable(Redis): T $callback
     * @return T
     * @throws Exception
     */
    private function execute(callable $callback): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $redis = $this->connection();
                $result = $callback($redis);

                $error = $redis->getLastError();

                if ($error !== null && $error !== '' && self::isRecoverableError($error)) {
                    throw new RedisException($error);
                }

                return $result;
            } catch (RedisException $e) {
                if (!self::isRecoverableError($e->getMessage())) {
                    throw $e;
                }

                $lastException = $e;
                $this->logger?->warning('Redis connection lost, re-resolving master', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                $this->disconnect();

                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }
            }
        }

        throw $lastException ?? new RuntimeException('Redis: command failed without exception');
    }

    private function connection(): Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        $this->redis = $this->connectToMaster();

        return $this->redis;
    }

    private function connectToMaster(): Redis
    {
        [$host, $port] = $this->resolveMaster();

        $this->logger?->info("Connected to master at $host:$port");

        $redis = new Redis();
        $context = $this->masterPassword !== null ? ['auth' => $this->masterPassword] : [];

        $connected = $this->persistentId !== null
            ? $redis->pconnect(
                $host,
                $port,
                self::CONNECT_TIMEOUT,
                $this->persistentId,
                self::RETRY_INTERVAL_MS,
                self::READ_TIMEOUT,
                $context !== [] ? $context : null,
            )
            : $redis->connect(
                $host,
                $port,
                self::CONNECT_TIMEOUT,
                null,
                self::RETRY_INTERVAL_MS,
                self::READ_TIMEOUT,
                $context !== [] ? $context : null,
            );

        if (!$connected) {
            throw new RuntimeException("Redis: could not connect to master {$host}:{$port}");
        }

        $this->logger?->info('Connected to Redis master', [
            'host' => $host,
            'port' => $port,
            'master' => $this->masterName,
        ]);

        return $redis;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveMaster(): array
    {
        $lastError = null;

        foreach ($this->sentinelNodes as $node) {
            try {
                $options = [
                    'host' => $node['host'],
                    'port' => $node['port'],
                    'connectTimeout' => self::CONNECT_TIMEOUT,
                    'readTimeout' => self::READ_TIMEOUT,
                    'retryInterval' => self::RETRY_INTERVAL_MS,
                ];

                if ($this->sentinelPassword !== null) {
                    $options['auth'] = $this->sentinelPassword;
                }

                $sentinel = new RedisSentinel($options);
                $master = $sentinel->getMasterAddrByName($this->masterName);

                if ($master !== false && isset($master[0], $master[1])) {
                    return [(string) $master[0], (int) $master[1]];
                }
            } catch (RedisException $e) {
                $lastError = $e->getMessage();
                $this->logger?->debug('Sentinel node unavailable', [
                    'host' => $node['host'],
                    'port' => $node['port'],
                    'error' => $lastError,
                ]);
            }
        }

        throw new RuntimeException(
            "Redis: could not resolve master '{$this->masterName}' via Sentinel"
            . ($lastError !== null ? ": {$lastError}" : ''),
        );
    }

    private function disconnect(): void
    {
        if ($this->redis === null) {
            return;
        }

        try {
            $this->redis->close();
        } catch (RedisException) {
            // Соединение уже мёртвое — просто сбрасываем клиент.
        }

        $this->redis = null;
    }

    /**
     * phpredis не имеет OPT_THROW_ERRORS: при обрыве связи бросает RedisException,
     * остальные ошибки чаще возвращает через false + getLastError().
     */
    private static function isRecoverableError(string $message): bool
    {
        $patterns = [
            'Connection',
            'connection',
            'read error on connection',
            'Broken pipe',
            'went away',
            'READONLY',
            'LOADING',
            'CLUSTERDOWN',
            'Master down',
            'Redis server went away',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{host: string, port: int}>
     */
    private static function parseSentinelHosts(string $hostsString): array
    {
        $nodes = array_map(
            static function (string $hostString): array {
                $parts = explode(':', trim($hostString));
                if (!isset($parts[0], $parts[1])) {
                    throw new RuntimeException("Invalid sentinel host format: {$hostString}");
                }

                return [
                    'host' => $parts[0],
                    'port' => (int) $parts[1],
                ];
            },
            explode(',', $hostsString),
        );

        if ($nodes === []) {
            throw new RuntimeException('Redis: sentinel hosts list is empty');
        }

        return $nodes;
    }
}
