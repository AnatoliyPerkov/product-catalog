<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Parameter;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use XMLReader;

class ImportProducts extends Command
{
    protected $signature = 'import:products {file : The path to the XML file}';
    protected $description = 'Import products and categories from an XML file';

    public function handle(): int
    {
        $file = $this->argument('file');
        $this->info("Checking file: {$file}");
        \Log::channel('import')->info('Starting import process', ['file' => $file]);

        if (!file_exists($file)) {
            $this->error("File not found at path: {$file}");
            \Log::channel('import')->error('File not found', ['file' => $file]);
            return 1;
        }

        $this->info('Starting XML import...');
        \Log::channel('import')->info('XML import started');

        $reader = new XMLReader();
        $reader->open($file);

        $categories = [];
        $productCount = 0;
        $categoryCount = 0;
        $errors = 0;

        // Перший прохід: категорії
        \Log::channel('import')->info('Starting first pass: parsing categories');
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'category') {
                $categoryXml = $reader->readOuterXML();
                $categories[] = $this->parseCategory($categoryXml);
            }
        }
        \Log::channel('import')->info('First pass completed', ['category_count' => count($categories)]);

        try {
            $this->saveCategories($categories);
            $categoryCount = Category::count();
            \Log::channel('import')->info('Categories saved successfully', ['total_categories' => $categoryCount]);
        } catch (\Exception $e) {
            $this->error('Error saving categories: ' . $e->getMessage());
            \Log::channel('import')->error('Category import error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }

        // Другий прохід: товари
        \Log::channel('import')->info('Starting second pass: parsing products');
        $reader->open($file);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'offer') {
                $productXml = $reader->readOuterXML();
                $productData = $this->parseProduct($productXml);

                if ($this->validateProduct($productData)) {
                    try {
                        $this->saveProduct($productData);
                        $productCount++;
                        \Log::channel('import')->info('Product saved', [
                            'external_id' => $productData['external_id'],
                            'name' => $productData['name'],
                        ]);
                    } catch (\Exception $e) {
                        $this->error('Error saving product: ' . $e->getMessage());
                        \Log::channel('import')->error('Product import error', [
                            'external_id' => $productData['external_id'],
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        $errors++;
                    }
                } else {
                    \Log::channel('import')->warning('Invalid product data', [
                        'external_id' => $productData['external_id'],
                        'data' => $productData,
                    ]);
                    $errors++;
                }
            }
        }

        $reader->close();
        \Log::channel('import')->info('Second pass completed', ['product_count' => $productCount, 'errors' => $errors]);

        // Оновлення множин у Redis
        try {
            $this->updateRedisSets();
            $this->info('Redis sets updated successfully.');
            \Log::channel('import')->info('Redis sets updated successfully');
        } catch (\Exception $e) {
            $this->error('Error updating Redis sets: ' . $e->getMessage());
            \Log::channel('import')->error('Redis update error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $errors++;
        }

        $this->info("Imported $categoryCount categories and $productCount products with $errors errors.");
        \Log::channel('import')->info('Import process completed', [
            'categories' => $categoryCount,
            'products' => $productCount,
            'errors' => $errors,
        ]);
        return 0;
    }

    /**
     * @param $categoryXml
     * @return array
     */
    private function parseCategory($categoryXml)
    {
        $xml = simplexml_load_string($categoryXml);
        $categoryData = [
            'external_id' => (string) $xml['id'],
            'name' => (string) $xml,
            'parent_id' => isset($xml['parentId']) ? (string) $xml['parentId'] : null,
        ];
        \Log::channel('import')->debug('Parsed category', $categoryData);
        return $categoryData;
    }

    /**
     * @param $categories
     */
    private function saveCategories($categories)
    {
        $parentIds = array_filter(array_column($categories, 'parent_id'));
        $redis = Redis::connection();

        foreach ($categories as $categoryData) {
            $brand = Brand::where('slug', Str::slug($categoryData['name'], '_'))->first();
            if ($brand && !in_array($categoryData['external_id'], $parentIds)) {
                $this->info("Skipping category {$categoryData['name']} as it matches a brand and is not a parent.");
                \Log::channel('import')->info('Skipped category due to brand match', [
                    'name' => $categoryData['name'],
                    'external_id' => $categoryData['external_id'],
                ]);
                continue;
            }

            $baseSlug = Str::slug($categoryData['name'], '_');
            $slug = $baseSlug . '-' . $categoryData['external_id'];

            $category = Category::firstOrCreate(
                ['external_id' => $categoryData['external_id']],
                ['name' => $categoryData['name'], 'slug' => $slug]
            );
            \Log::channel('import')->info('Category saved', [
                'external_id' => $categoryData['external_id'],
                'name' => $categoryData['name'],
                'slug' => $slug,
            ]);
        }

        foreach ($categories as $categoryData) {
            if ($categoryData['parent_id']) {
                $category = Category::where('external_id', $categoryData['external_id'])->first();
                $parent = Category::where('external_id', $categoryData['parent_id'])->first();
                if ($parent) {
                    $category->update(['parent_id' => $parent->id]);
                    \Log::channel('import')->info('Updated category parent', [
                        'category_id' => $category->id,
                        'parent_id' => $parent->id,
                    ]);
                } else {
                    $this->warn("Parent category {$categoryData['parent_id']} not found for {$categoryData['external_id']}.");
                    \Log::channel('import')->warning('Parent category not found', [
                        'category_external_id' => $categoryData['external_id'],
                        'parent_external_id' => $categoryData['parent_id'],
                    ]);
                }
            }
        }

        // Кешування нащадків
        $allCategories = Category::all();
        foreach ($allCategories as $category) {
            $descendantIds = $this->getAllDescendantIds($category);
            $redisKey = "category:descendants:{$category->id}";
            $redis->set($redisKey, json_encode($descendantIds));
            \Log::channel('import')->info('Cached category descendants', [
                'category_id' => $category->id,
                'slug' => $category->slug,
                'descendant_ids' => $descendantIds,
                'redis_key' => $redisKey,
            ]);
        }
    }

    /**
     * @param $productXml
     * @return array
     */
    private function parseProduct($productXml): array
    {
        $xml = simplexml_load_string($productXml);
        $data = [
            'external_id' => (string) $xml['id'],
            'available' => filter_var($xml['available'], FILTER_VALIDATE_BOOLEAN),
            'category_id' => (string) $xml->categoryId,
            'currency' => (string) $xml->currencyId,
            'name' => (string) $xml->name,
            'price' => (float) $xml->price,
            'stock' => (int) $xml->stock_quantity,
            'description' => (string) $xml->description,
            'description_format' => isset($xml->description_format) ? (string) $xml->description_format : null,
            'vendor' => (string) $xml->vendor,
            'vendor_code' => isset($xml->vendorCode) ? (string) $xml->vendorCode : null,
            'barcode' => isset($xml->barcode) ? (string) $xml->barcode : null,
            'pictures' => [],
            'parameters' => [],
        ];

        foreach ($xml->picture as $picture) {
            $data['pictures'][] = (string) $picture;
        }

        foreach ($xml->param as $param) {
            $paramName = (string) $param['name'];
            if ($paramName !== 'Бренд') {
                $paramValue = (string) $param;
                $data['parameters'][] = [
                    'name' => $paramName,
                    'slug' => Str::slug($paramName, '_'),
                    'value' => $paramValue,
                    'value_slug' => Str::slug($paramValue, '_'),
                ];
            }
        }

        \Log::channel('import')->debug('Parsed product', [
            'external_id' => $data['external_id'],
            'name' => $data['name'],
            'category_id' => $data['category_id'],
        ]);
        return $data;
    }

    /**
     * @param $data
     * @return bool
     */
    private function validateProduct($data): bool
    {
        if (empty($data['external_id']) || empty($data['name']) || empty($data['price']) || empty($data['vendor']) || empty($data['category_id'])) {
            $this->error('Invalid product data: missing required fields.');
            \Log::channel('import')->warning('Product validation failed', [
                'external_id' => $data['external_id'] ?? 'unknown',
                'data' => $data,
            ]);
            return false;
        }

        return true;
    }

    /**
     * @param $data
     */
    private function saveProduct($data)
    {
        $brand = Brand::firstOrCreate(
            ['slug' => Str::slug($data['vendor'], '_')],
            ['name' => $data['vendor']]
        );
        \Log::channel('import')->info('Brand saved', [
            'slug' => $brand->slug,
            'name' => $brand->name,
        ]);

        $category = Category::where('external_id', $data['category_id'])->firstOrFail();

        $product = Product::updateOrCreate(
            ['external_id' => $data['external_id']],
            [
                'name' => $data['name'],
                'price' => $data['price'],
                'stock' => $data['stock'],
                'description' => $data['description'] ?: null,
                'description_format' => $data['description_format'] ?: null,
                'vendor_code' => $data['vendor_code'] ?: null,
                'barcode' => $data['barcode'] ?: null,
                'available' => $data['available'],
                'currency' => $data['currency'],
                'brand_id' => $brand->id,
                'category_id' => $category->id,
            ]
        );
        \Log::channel('import')->info('Product saved', [
            'external_id' => $data['external_id'],
            'name' => $data['name'],
            'category_id' => $category->id,
        ]);

        foreach ($data['pictures'] as $url) {
            ProductImage::firstOrCreate(
                ['product_id' => $product->id, 'url' => $url],
                ['url' => $url]
            );
            \Log::channel('import')->debug('Product image saved', [
                'product_id' => $product->id,
                'url' => $url,
            ]);
        }

        foreach ($data['parameters'] as $paramData) {
            $parameter = Parameter::firstOrCreate(
                ['slug' => $paramData['slug']],
                ['name' => $paramData['name']]
            );
            \Log::channel('import')->debug('Parameter saved', [
                'slug' => $paramData['slug'],
                'name' => $paramData['name'],
            ]);

            // Нормалізуємо значення для збереження в базі
            $normalizedValue = $this->normalizeFilterValue($paramData['value']);
            $valueSlug = $paramData['value_slug'];
            $product->parameters()->syncWithoutDetaching([
                $parameter->id => [
                    'value' => $normalizedValue,
                    'value_slug' => $valueSlug,
                ],
            ]);
            \Log::channel('import')->debug('Product parameter attached', [
                'product_id' => $product->id,
                'parameter_id' => $parameter->id,
                'value' => $normalizedValue,
            ]);
        }
    }

    private function updateRedisSets()
    {
        $redis = Redis::connection();
        $redis->del($redis->keys('filter:*'));
        $redis->del($redis->keys('filter_values:*'));
        $redis->del($redis->keys('filter:result:*'));

        \Log::channel('import')->info('Starting Redis sets update');

        // Бренди
        Product::with(['brand'])
            ->where('available', true)
            ->orderBy('id')
            ->chunk(100, function ($products) use ($redis) {
                $brands = $products->pluck('brand')->unique('id');
                foreach ($brands as $brand) {
                    if ($brand && $brand->id) {
                        $productIds = $products->where('brand_id', $brand->id)->pluck('id')->toArray();
                        if ($productIds) {
                            $redis->sadd("filter:brand:{$brand->id}", ...$productIds);
                            $redis->sadd('filter_values:brand', $brand->slug);
                            \Log::channel('import')->info('Added brand filter', [
                                'brand_id' => $brand->id,
                                'brand_slug' => $brand->slug,
                                'product_count' => count($productIds),
                            ]);
                        }
                    }
                }
            });

        // Категорії
        Product::with(['category'])
            ->where('available', true)
            ->orderBy('id')
            ->chunk(100, function ($products) use ($redis) {
                $categories = $products->pluck('category')->unique('id');
                foreach ($categories as $category) {
                    if ($category && $category->id) {
                        $productIds = $products->where('category_id', $category->id)->pluck('id')->toArray();
                        if ($productIds) {
                            // Поточна категорія
                            $redis->sadd("filter:category:{$category->id}", ...$productIds);
                            $redis->sadd('filter_values:category', $category->slug);
                            \Log::channel('import')->info('Added category filter', [
                                'category_id' => $category->id,
                                'category_slug' => $category->slug,
                                'product_count' => count($productIds),
                                'product_ids' => array_slice($productIds, 0, 10),
                            ]);

                            // Кешування filter:result:*
                            $filters = ['category' => [$category->slug]];
                            $redisKey = 'filter:result:' . md5(json_encode($filters));
                            $redis->set($redisKey, json_encode($productIds));
                            \Log::channel('import')->info('Cached filter result', [
                                'filters' => $filters,
                                'redis_key' => $redisKey,
                                'product_count' => count($productIds),
                                'product_ids' => array_slice($productIds, 0, 10),
                            ]);

                            // Дочірні категорії
                            $descendantIds = $this->getAllDescendantIds($category);
                            foreach ($descendantIds as $descendantId) {
                                $redis->sadd("filter:category:{$descendantId}", ...$productIds);
                                $descendantCategory = Category::find($descendantId);
                                \Log::channel('import')->info('Added products to descendant category filter', [
                                    'descendant_id' => $descendantId,
                                    'descendant_slug' => $descendantCategory ? $descendantCategory->slug : 'unknown',
                                    'product_count' => count($productIds),
                                    'product_ids' => array_slice($productIds, 0, 10),
                                ]);
                            }

                            // Батьківські категорії
                            $parent = Category::find($category->parent_id);
                            while ($parent) {
                                $redis->sadd("filter:category:{$parent->id}", ...$productIds);
                                \Log::channel('import')->info('Added products to parent category filter', [
                                    'parent_id' => $parent->id,
                                    'parent_slug' => $parent->slug,
                                    'product_count' => count($productIds),
                                    'product_ids' => array_slice($productIds, 0, 10),
                                ]);
                                $parent = Category::find($parent->parent_id);
                            }
                        }
                    }
                }
            });

        // Параметри
        \DB::table('product_parameters')
            ->join('parameters', 'product_parameters.parameter_id', '=', 'parameters.id')
            ->select('product_parameters.product_id', 'parameters.slug as param_slug', 'product_parameters.value_slug')
            ->orderBy('product_parameters.id')
            ->chunk(100, function ($relations) use ($redis) {
                \Log::channel('import')->info('Processing parameter chunk', ['count' => count($relations)]);
                foreach ($relations as $relation) {
                    $valueSlug = $relation->value_slug;
                    $redis->sadd("filter:{$relation->param_slug}:{$valueSlug}", $relation->product_id);
                    $redis->sadd("filter_values:{$relation->param_slug}", $valueSlug);
                    \Log::channel('import')->info('Added parameter filter', [
                        'param_slug' => $relation->param_slug,
                        'value_slug' => $valueSlug,
                        'product_id' => $relation->product_id,
                    ]);
                }
            });

        \Log::channel('import')->info('Redis sets update completed');
    }

    /**
     * @param Category $category
     * @return array
     */
    private function getAllDescendantIds(Category $category): array
    {
        $descendantIds = $category->descendants()->pluck('id')->toArray();
        \Log::channel('import')->debug('Retrieved descendant IDs', [
            'category_id' => $category->id,
            'descendant_ids' => $descendantIds,
        ]);
        return $descendantIds;
    }

    /**
     * @param string $value
     * @return string
     */
    private function normalizeFilterValue(string $value): string
    {
        // Базова нормалізація
        $normalized = str_replace('_', ' ', $value);
        $normalized = ucwords($normalized);

        // Завантажуємо словники з конфігурації
        $replacements = config('filters.replacements', []);
        $colorReplacements = config('filters.colors', []);
        $materialReplacements = config('filters.materials', []);

        // Динамічна обробка складів
        if (preg_match('/^(\d+)\s*(\w+)\s*(\d*)\s*(\w*)$/', $normalized, $matches)) {
            $percentage1 = $matches[1];
            $material1 = $matches[2];
            $percentage2 = $matches[3];
            $material2 = $matches[4];

            $material1 = $materialReplacements[strtolower($material1)] ?? ucfirst($material1);
            if ($percentage2 && $material2) {
                $material2 = $materialReplacements[strtolower($material2)] ?? ucfirst($material2);
                $result = "{$percentage1}% {$material1}, {$percentage2}% {$material2}";
                \Log::channel('import')->debug('Normalized material composition', [
                    'original' => $value,
                    'normalized' => $result,
                ]);
                return $result;
            }
            $result = "{$percentage1}% {$material1}";
            \Log::channel('import')->debug('Normalized material composition', [
                'original' => $value,
                'normalized' => $result,
            ]);
            return $result;
        }

        // Динамічна обробка кольорів
        $key = strtolower(str_replace(' ', '_', $value));
        if (isset($colorReplacements[$key])) {
            \Log::channel('import')->debug('Normalized color value', [
                'original' => $value,
                'normalized' => $colorReplacements[$key],
            ]);
            return $colorReplacements[$key];
        }

        // Логування нових значень
        if (!isset($replacements[$key]) && !isset($colorReplacements[$key]) && !preg_match('/^(\d+)\s*(\w+)\s*(\d*)\s*(\w*)$/', $normalized)) {
            \Log::channel('import')->info('New filter value needs normalization', ['value' => $value]);
        }

        $result = $replacements[$key] ?? $normalized;
        \Log::channel('import')->debug('Normalized filter value', [
            'original' => $value,
            'normalized' => $result,
        ]);
        return $result;
    }
}
