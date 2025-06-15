<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

trait RedisCache
{
    protected $redisPrefix = 'filter:';

    /**
     * Отримує дані з Redis або виконує callback для їх створення
     * Перевіряє кеш, повертає збережені дані або кешує нові на заданий час
     * @param string $key Ключ для Redis
     * @param callable $callback Функція для створення даних, якщо кеш порожній
     * @param int $ttl Час життя кешу в секундах (за замовчуванням 86400)
     * @return mixed Дані з кешу або результат callback
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
     * Виконує операцію SINTERSTORE в Redis для обчислення перетину множин
     * Зберігає результат у результуючому ключі, встановлює TTL та повертає кількість елементів
     * @param string $resultKey Ключ для збереження результату
     * @param array $setKeys Масив ключів множин для перетину
     * @param int $ttl Час життя результуючого ключа в секундах (за замовчуванням 300)
     * @return int Кількість елементів у результуючій множині
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
