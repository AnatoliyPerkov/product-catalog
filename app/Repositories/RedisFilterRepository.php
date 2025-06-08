<?php

namespace App\Repositories;

use App\Contracts\FilterRepositoryInterface;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class RedisFilterRepository implements FilterRepositoryInterface
{
    /**
     * @param array $filters
     * @return array
     */
    public function getProductIds(array $filters): array
    {
        $productIds = null;
        foreach ($filters as $key => $value) {
            $valueSlug = Str::slug($value, '_');
            $redisKey = "filter:{$key}:{$valueSlug}";
            $ids = Redis::smembers($redisKey);
            if (empty($ids)) {
                return [];
            }
            $productIds = $productIds ? array_intersect($productIds, $ids) : $ids;
        }
        return $productIds ?: [];
    }

    /**
     * @param string $paramSlug
     * @return array
     */
    public function getFilterValues(string $paramSlug): array
    {
        return Redis::smembers("filter_values:{$paramSlug}");
    }

    /**
     * @param string $paramSlug
     * @param string $value
     * @param array|null $activeIds
     * @return int
     */
    public function getProductCount(string $paramSlug, string $value, ?array $activeIds): int
    {
        $countKey = "filter:{$paramSlug}:{$value}";
        $ids = Redis::smembers($countKey);
        return $activeIds ? count(array_intersect($ids, $activeIds)) : count($ids);
    }
}
