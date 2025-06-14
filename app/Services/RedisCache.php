<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

trait RedisCache
{
    protected $redisPrefix = 'filter:';

    /**
     * @param string $key
     * @param callable $callback
     * @param int $ttl
     * @return mixed
     */
    public function getFromRedis(string $key, callable $callback, int $ttl = 86400): mixed
    {
        try {
            $cachedData = Redis::get($key);
            if ($cachedData) {
                Log::debug('Retrieved from Redis cache', ['key' => $key]);
                return json_decode($cachedData, true);
            }
        } catch (\Exception $e) {
            Log::error('Redis read error', [
                'key' => $key,
                'message' => $e->getMessage(),
            ]);
        }

        $data = $callback();
        try {
            Redis::setex($key, $ttl, json_encode($data));
            Log::info('Cached in Redis', ['key' => $key]);
        } catch (\Exception $e) {
            Log::error('Redis write error', [
                'key' => $key,
                'message' => $e->getMessage(),
            ]);
        }

        return $data;
    }

    /**
     * @param string $resultKey
     * @param array $setKeys
     * @param int $ttl
     * @return int
     */
    public function sinterstore(string $resultKey, array $setKeys, int $ttl = 300): int
    {
        try {
            Redis::sinterstore($resultKey, ...$setKeys);
            Redis::expire($resultKey, $ttl);
            return (int)Redis::scard($resultKey);
        } catch (\Exception $e) {
            Log::error('Redis sinterstore error', [
                'resultKey' => $resultKey,
                'message' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
