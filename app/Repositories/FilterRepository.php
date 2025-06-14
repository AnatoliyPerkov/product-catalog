<?php

namespace App\Repositories;

use App\Contracts\FilterRepositoryInterface;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Parameter;
use App\Models\Product;
use App\Services\EntityResolver;
use App\Services\RedisCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class FilterRepository implements FilterRepositoryInterface
{
    use RedisCache, EntityResolver;

    /**
     * Get all descendant category IDs recursively.
     * @param Category $category
     * @return array
     */
    protected function getAllDescendantIds(Category $category): array
    {
        $cacheKey = "category:descendants:{$category->id}";
        $this->log('debug', 'Calling getAllDescendantIds', ['category_id' => $category->id]);
        return $this->getFromRedis($cacheKey, function () use ($category, $cacheKey) {
                $ids = [$category->id];
                $children = $category->children()->get(['id']);
                foreach ($children as $child) {
                    $ids = array_merge($ids, $this->getAllDescendantIds($child));
                }
                $uniqueIds = array_unique($ids);
                return [
                    'category_id' => $category->id,
                    'slug' => $category->slug,
                    'descendant_ids' => $uniqueIds,
                    'redis_key' => $cacheKey,
                ];
            })['descendant_ids'] ?? [$category->id];
    }

    /**
     * Get product IDs based on filters using Redis.
     * @param array $filters
     * @return array
     */
    public function getProductIds(array $filters): array
    {
        try {
            $cacheKey = $this->redisPrefix . 'product_ids:' . md5(json_encode($filters));
            $cached = Redis::get($cacheKey);
            if ($cached) {
                return array_map('intval', json_decode($cached, true));
            }

            $setKeys = $this->buildRedisSetKeysForFilters($filters);

            if (empty($setKeys)) {
                $this->log('warning', 'No Redis sets for product IDs', ['filters' => $filters]);
                $ids = Product::where('available', 1)->pluck('id')->toArray();
                Redis::setex($cacheKey, 300, json_encode($ids));
                return $ids;
            }

            $resultKey = $this->redisPrefix . 'result:ids:' . md5(implode(':', $setKeys));
            $count = $this->sinterstore($resultKey, $setKeys);
            $ids = array_map('intval', Redis::smembers($resultKey) ?: []);
            Redis::setex($cacheKey, 300, json_encode($ids));

            return $ids;
        } catch (\Exception $e) {
            $this->log('error', 'Product IDs error', [
                'message' => $e->getMessage(),
                'filters' => $filters,
            ]);
            return [];
        }
    }
    /**
     * Get product count for a filter value using Redis set intersections.
     * @param string $paramSlug
     * @param string $value
     * @param array|null $activeIds
     * @param array $filters
     * @return int
     */
    public function getProductCount(string $paramSlug, string $value, ?array $activeIds = null, array $filters = []): int
    {
        try {
            $cacheKey = md5(json_encode([$paramSlug, $value, $filters, $activeIds]));
            if (isset($this->countCache[$cacheKey])) {
                return $this->countCache[$cacheKey];
            }

            $setKeys = $this->buildRedisSetKeys($paramSlug, $value, $filters);

            if ($activeIds) {
                $tempKey = $this->redisPrefix . 'temp:active_ids:' . md5(implode(',', $activeIds));
                Redis::pipeline(function ($pipe) use ($tempKey, $activeIds) {
                    $pipe->sadd($tempKey, ...$activeIds);
                    $pipe->expire($tempKey, 300);
                });
                $setKeys[] = $tempKey;
            }

            if (empty($setKeys)) {
                return count($activeIds ?? []);
            }

            // Перевірка існування ключів через pipeline
            $existsResults = Redis::pipeline(function ($pipe) use ($setKeys) {
                foreach ($setKeys as $key) {
                    $pipe->exists($key);
                }
            });

            if (in_array(0, $existsResults, true)) {
                return 0;
            }

            $resultKey = $this->redisPrefix . 'result:' . md5(implode(':', $setKeys));
            $count = $this->sinterstore($resultKey, $setKeys);
            $this->countCache[$cacheKey] = $count;

            return $count;
        } catch (\Exception $e) {
            $this->log('error', 'Product count error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'paramSlug' => $paramSlug,
                'value' => $value,
            ]);
            return 0;
        }
    }
    /**
     * Process filter values for categories or brands.
     * @param string $type
     * @param array $filters
     * @param array $activeIds
     * @return array
     */
    protected function processFilterValues(string $type, array $filters, array $activeIds): array
    {
        $result = [];
        $config = [
            'category' => [
                'model' => Category::class,
                'name' => 'Категорія',
                'slug' => 'category',
                'query' => fn($q) => isset($filters['category'])
                    ? $q->whereIn('parent_id', Category::whereIn('slug', (array)$filters['category'])->pluck('id')->toArray())
                        ->distinct()
                    : $q->whereNull('parent_id'),
                'map' => fn($item) => [
                    'value' => $item->slug,
                    'name' => $item->name,
                    'count' => $this->getProductCount(
                        'category',
                        $item->slug,
                        $this->getProductIds(array_diff_key($filters, ['brand' => null])),
                        array_diff_key($filters, ['brand' => null])
                    ),
                    'has_children' => $item->children()->exists(),
                    'active' => isset($filters['category']) && in_array($item->slug, (array)$filters['category']),
                ],
            ],
            'brand' => [
                'model' => Brand::class,
                'name' => 'Бренди',
                'slug' => 'brand',
                'query' => fn($q) => $q->whereHas('products', fn($q) => $q->where('available', true))
                    ->when(isset($filters['category']), function ($q) use ($filters) {
                        $categoryIds = Category::whereIn('slug', (array)$filters['category'])
                            ->get()
                            ->whenEmpty(fn() => [], fn($categories) => $categories->flatMap(fn($cat) => $this->getAllDescendantIds($cat)))
                            ->unique()
                            ->toArray();
                        if ($categoryIds) {
                            $q->whereHas('products', fn($q) => $q->whereIn('category_id', $categoryIds));
                        }
                    })->limit(50),
                'map' => fn($item) => [
                    'value' => $item->slug,
                    'name' => $item->name,
                    'count' => $this->getProductCount(
                        'brand',
                        $item->slug,
                        $this->getProductIds(array_diff_key($filters, ['brand' => null])),
                        array_diff_key($filters, ['brand' => null])
                    ),
                    'active' => isset($filters['brand']) && in_array($item->slug, (array)$filters['brand']),
                ],
            ],
        ];

        if (isset($config[$type])) {
            $cfg = $config[$type];
            $query = $cfg['model']::query()->select(['id', 'name', 'slug']);
            $cfg['query']($query);
            $items = $query->get()->map(function ($item) use ($cfg, $filters) {
                if (is_null($item->slug)) {
                    Log::warning("{$cfg['model']} with null slug detected", ['id' => $item->id, 'name' => $item->name]);
                    return null;
                }
                return $cfg['map']($item);
            })->filter()->unique('value')->values()->toArray();

            if ($items) {
                $result = [
                    'name' => $cfg['name'],
                    'slug' => $cfg['slug'],
                    'values' => $items,
                ];
            }
        }

        return $result;
    }    /**
     * Process parameter filter values from Redis with caching, limited to subcategory products.
     * @param string $paramSlug
     * @param array $filters
     * @param array $activeIds
     * @return array
     */
    protected function processParameterFilterValues(string $paramSlug, array $filters, array $activeIds): array
    {
        $cacheKey = $this->redisPrefix . 'filter:params:' . md5($paramSlug . ':' . json_encode($filters) . ':' . md5(implode(',', $activeIds)));
        $cachedResult = Redis::get($cacheKey);
        if ($cachedResult) {
            return json_decode($cachedResult, true);
        }

        $result = [];

        $categorySlugs = !empty($filters['category']) ? (array)$filters['category'] : [];
        if (empty($categorySlugs)) {
            return $result;
        }

        $categoryIds = Category::whereIn('slug', $categorySlugs)
            ->get()
            ->flatMap(fn($cat) => $this->getAllDescendantIds($cat))
            ->unique()
            ->toArray();

        if (empty($categoryIds)) {
            return $result;
        }

        $relevantSlugs = $paramSlug === 'all' ? [] : array_merge(array_keys($filters), [$paramSlug]);
        $relevantSlugs = array_diff($relevantSlugs, ['category', 'brand']);

        $query = Parameter::whereHas('products', function ($query) use ($categoryIds) {
            $query->whereIn('category_id', $categoryIds)
                ->where('available', true);
        })->with(['products' => function ($query) use ($categoryIds) {
            $query->whereIn('category_id', $categoryIds)
                ->where('available', true)
                ->select(['products.id'])
                ->withPivot(['value', 'value_slug']);
        }]);

        if (!empty($relevantSlugs)) {
            $query->whereIn('slug', $relevantSlugs);
        }

        $parameters = $query->get()->keyBy('slug');

        foreach ($parameters as $fullSlug => $parameter) {
            $key = "filter_values:{$fullSlug}";
            $valueSlugs = Redis::smembers($key);
            if (empty($valueSlugs)) {
                $this->log('error', 'Empty value slugs for parameter', ['key' => $key, 'paramSlug' => $fullSlug]);
                continue;
            }

            $values = [];
            $modifiedFilters = array_diff_key($filters, [$fullSlug => null, 'brand' => null]);
            $tempActiveIds = $this->getProductIds($modifiedFilters);

            foreach ($parameter->products as $product) {
                $valueSlug = $product->pivot->value_slug;
                $value = $product->pivot->value;

                if (!in_array($valueSlug, $valueSlugs)) {
                    continue;
                }

                $count = $this->getProductCount($fullSlug, $valueSlug, $tempActiveIds, $modifiedFilters);
                $isActive = isset($filters[$fullSlug]) && (in_array($valueSlug, (array)$filters[$fullSlug]) || in_array($value, (array)$filters[$fullSlug]));

                $values[] = [
                    'value' => $value,
                    'value_slug' => $valueSlug,
                    'count' => $count,
                    'active' => $isActive,
                ];
            }

            $values = collect($values)->unique('value_slug')->values()->toArray();

            if (!empty($values)) {
                $result[] = [
                    'name' => $parameter->name,
                    'slug' => $fullSlug,
                    'values' => $values,
                ];
            }
        }

        Redis::setex($cacheKey, 300, json_encode($result));
        return $result;
    }
    /**
     * Get available filter values with counts.
     * Parameters are included if a subcategory is selected or parameters are applied.
     * @param string $paramSlug
     * @param array $filters
     * @return array
     */
    public function getFilterValues(string $paramSlug, array $filters = []): array
    {
        try {
            $this->log('debug', 'Calling getFilterValues', [
                'paramSlug' => $paramSlug,
                'filters' => $filters
            ]);
            $activeIds = $this->getProductIds($filters);
            $result = [];

            // Обробка категорій
            $categoryResult = $this->processFilterValues('category', $filters, $activeIds);
            if ($categoryResult) {
                $result['category'] = $categoryResult;
            }

            // Обробка брендів
            $brandResult = $this->processFilterValues('brand', $filters, $activeIds);
            if ($brandResult) {
                $result['brand'] = $brandResult;
            }

            $hasSubcategory = false;
            $hasParameters = false;

            if (!empty($filters['category'])) {
                $categorySlugs = (array)$filters['category'];
                $hasSubcategory = Category::whereIn('slug', $categorySlugs)
                    ->whereNotNull('parent_id')
                    ->exists();
                $this->log('debug', 'Checking hasSubcategory', [
                    'categorySlugs' => $categorySlugs,
                    'hasSubcategory' => $hasSubcategory
                ]);
            }

            foreach ($filters as $slug => $values) {
                if (!in_array($slug, ['category', 'brand']) && Parameter::where('slug', $slug)->exists()) {
                    $hasParameters = true;
                    break;
                }
            }

            if ($hasSubcategory || $hasParameters) {
                $paramResults = $this->processParameterFilterValues($paramSlug, $filters, $activeIds);
                foreach ($paramResults as $paramResult) {
                    $result[$paramResult['slug']]

                        = $paramResult;
                }
            }

            return array_values($result);
        } catch (\Exception $e) {
            $this->log('error', 'Filter values error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'paramSlug' => $paramSlug,
            ]);
            return [];
        }
    }
    /**
     * Log message with context.
     * @param string $level
     * @param string $message
     * @param array $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::$level($message, array_merge(['class' => __CLASS__], $context));
    }

    /**
     * Add Redis set keys for a single filter.
     * @param array $setKeys
     * @param string $filterSlug
     * @param string $value
     * @param string $expectedType
     */
    protected function addFilterSetKeys(array &$setKeys, string $filterSlug, string $value, string $expectedType): void
    {
        $isCategory = $filterSlug === 'category' && $expectedType === 'category';
        $isBrand = $filterSlug === 'brand' && $expectedType === 'brand';
        $isParameter = $expectedType === 'parameters';

        if (!$isCategory && !$isBrand && !$isParameter) {
            $this->log('warning', 'Invalid filter type for set keys', [
                'filterSlug' => $filterSlug,
                'expectedType' => $expectedType,
                'value' => $value,
            ]);
            return;
        }

        if ($isCategory) {
            $category = $this->resolveEntity(Category::class, $value);
            if ($category) {
                $categoryIds = $this->getFromRedis("category:descendants:{$category->id}", function () use ($category) {
                        $descendantIds = $this->getAllDescendantIds($category);
                        return [
                            'descendant_ids' => array_unique($descendantIds),
                            'category_id' => $category->id,
                        ];
                    })['descendant_ids'] ?? [$category->id];

                foreach (array_unique($categoryIds) as $catId) {
                    $key = $this->redisPrefix . "category:{$catId}";
                    if (!in_array($key, $setKeys)) {
                        $setKeys[] = $key;
                    }
                }
            } else {
                $this->log('warning', 'Category not resolved', ['value' => $value]);
            }
        } elseif ($isBrand) {
            $brand = $this->resolveEntity(Brand::class, $value);
            if ($brand) {
                $key = $this->redisPrefix . "brand:{$brand->id}";
                if (!in_array($key, $setKeys)) {
                    $setKeys[] = $key;
                }
            }
        } elseif ($isParameter) {
            $parameter = Parameter::where('slug', $filterSlug)->first();
            if ($parameter) {
                $pivot = \DB::table('product_parameters')
                    ->where('parameter_id', $parameter->id)
                    ->where(function ($query) use ($value) {
                        $query->where('value', $value)
                            ->orWhere('value_slug', $value);
                    })
                    ->first();

                if ($pivot && $pivot->value_slug) {
                    $redisValue = $pivot->value_slug;
                } else {
                    $redisValue = $value;
                    $this->log('warning', 'Value slug not found for parameter', [
                        'filterSlug' => $filterSlug,
                        'value' => $value,
                        'parameter_id' => $parameter->id,
                    ]);
                }

                $key = $this->redisPrefix . "filter:{$filterSlug}:{$redisValue}";
                if (str_starts_with($this->redisPrefix, 'filter:')) {
                    $key = "filter:{$filterSlug}:{$redisValue}";
                }

                if (!in_array($key, $setKeys)) {
                    $setKeys[] = $key;
                }
            } else {
                $this->log('warning', 'Parameter not found', ['filterSlug' => $filterSlug, 'value' => $value]);
            }
        }
    }

    /**
     * Build Redis set keys for a specific filter and additional filters.
     * @param string $paramSlug
     * @param string $value
     * @param array $filters
     * @return array
     */
    protected function buildRedisSetKeys(string $paramSlug, string $value, array $filters): array
    {
        $setKeys = [];
        $groupedKeys = [];

        if (!isset($groupedKeys[$paramSlug])) {
            $groupedKeys[$paramSlug] = [];
        }

        $expectedType = $paramSlug === 'category' ? 'category' : ($paramSlug === 'brand' ? 'brand' : 'parameters');
        $this->addFilterSetKeys($groupedKeys[$paramSlug], $paramSlug, $value, $expectedType);

        foreach ($filters as $filterSlug => $values) {
            $expectedType = $filterSlug === 'category' ? 'category' : ($filterSlug === 'brand' ? 'brand' : 'parameters');
            if (!isset($groupedKeys[$filterSlug])) {
                $groupedKeys[$filterSlug] = [];
            }
            foreach ((array)$values as $val) {
                if ($filterSlug === $paramSlug && $val === $value) {
                    continue;
                }
                $this->addFilterSetKeys($groupedKeys[$filterSlug], $filterSlug, $val, $expectedType);
            }
        }

        foreach ($groupedKeys as $filterSlug => $keys) {
            if (count($keys) > 1 && $filterSlug !== 'category' && $filterSlug !== 'brand') {
                $unionKey = $this->redisPrefix . "temp:union:{$filterSlug}:" . md5(implode(':', $keys));
                Redis::sunionstore($unionKey, ...$keys);
                Redis::expire($unionKey, 300);
                $setKeys[] = $unionKey;
            } else {
                $setKeys = array_merge($setKeys, $keys);
            }
        }

        return array_unique($setKeys);
    }

    /**
     * Build Redis set keys for all filters, combining multiple keys per type with SUNIONSTORE.
     * @param array $filters
     * @return array
     */
    protected function buildRedisSetKeysForFilters(array $filters): array
    {
        $setKeys = [];
        $groupedKeys = [];

        foreach ($filters as $filterSlug => $values) {
            $expectedType = $filterSlug === 'category' ? 'category' : ($filterSlug === 'brand' ? 'brand' : 'parameters');
            if (!isset($groupedKeys[$filterSlug])) {
                $groupedKeys[$filterSlug] = [];
            }
            foreach ((array)$values as $value) {
                $this->addFilterSetKeys($groupedKeys[$filterSlug], $filterSlug, $value, $expectedType);
            }
        }

        foreach ($groupedKeys as $filterSlug => $keys) {
            if (count($keys) > 1) {
                $unionKey = $this->redisPrefix . "temp:union:{$filterSlug}:" . md5(implode(':', $keys));
                Redis::sunionstore($unionKey, ...$keys);
                Redis::expire($unionKey, 300);
                $setKeys[] = $unionKey;
            } else {
                $setKeys = array_merge($setKeys, $keys);
            }
        }

        return array_unique($setKeys);
    }
}
