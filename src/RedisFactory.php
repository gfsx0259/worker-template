<?php

declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

use Redis;
use RedisSentinel;
use RuntimeException;
use Throwable;

class RedisFactory
{
    public static function getRedis(string $hostsString, ?string $password = null, ?string $persistentId = null, string $masterName = 'mymaster'): Redis
    {
        $sentinels = array_map(
            fn ($hostString) => explode(':', $hostString),
            explode(',', $hostsString),
        );

        $masterAddr = null;

        if (count($sentinels) > 1) {
            foreach ($sentinels as $sentinelInfo) {
                [$host, $port] = $sentinelInfo;

                try {
                    $sentinelClient = new RedisSentinel([
                        'host' => $host,
                        'port' => (int) $port,
                        'connectTimeout' => 1,
                        'readTimeout' => 1,
                        'retryInterval' => 100,
                        'auth' => $password,
                    ]);

                    $masterAddr = $sentinelClient->getMasterAddrByName($masterName);

                    if ($masterAddr) {
                        break;
                    }
                } catch (Throwable $e) {
                    echo $e->getMessage();
                    continue;
                }
            }

            if ($masterAddr === null) {
                throw new RuntimeException('Redis Sentinel: Could not find master, check network and group name');
            }
        } else {
            $masterAddr = $sentinels[0];
        }

        $redis = new Redis();

        if ($persistentId) {
            if (!$redis->pconnect($masterAddr[0], (int) $masterAddr[1], 1, $persistentId)) {
                throw new RuntimeException("Redis: Could not connect to master $masterAddr[0]:$masterAddr[1]");
            }
        } else {
            if (!$redis->connect($masterAddr[0], (int) $masterAddr[1])) {
                throw new RuntimeException("Redis: Could not connect to master $masterAddr[0]:$masterAddr[1]");
            }
        }

        if ($password) {
            $redis->auth($password);
        }

        return $redis;
    }
}