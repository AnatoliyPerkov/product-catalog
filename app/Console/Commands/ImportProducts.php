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

    /**
     * @return int
     */
    public function handle(): int
    {
        $file = $this->argument('file');
        $this->info("Checking file: {$file}");

        if (!file_exists($file)) {
            $this->error("File not found at path: {$file}");
            return 1;
        }

        $this->info('Starting XML import...');

        $reader = new XMLReader();
        $reader->open($file);

        $categories = [];
        $productCount = 0;
        $categoryCount = 0;
        $errors = 0;

        // Перший прохід: категорії
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'category') {
                $categoryXml = $reader->readOuterXML();
                $categories[] = $this->parseCategory($categoryXml);
            }
        }

        try {
            $this->saveCategories($categories);
            $categoryCount = Category::count();
        } catch (\Exception $e) {
            $this->error('Error saving categories: ' . $e->getMessage());
            \Log::error('Category import error: ' . $e->getMessage());
            return 1;
        }

        // Другий прохід: товари
        $reader->open($file);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'offer') {
                $productXml = $reader->readOuterXML();
                $productData = $this->parseProduct($productXml);

                if ($this->validateProduct($productData)) {
                    try {
                        $this->saveProduct($productData);
                        $productCount++;
                    } catch (\Exception $e) {
                        $this->error('Error saving product: ' . $e->getMessage());
                        \Log::error('Product import error: ' . $e->getMessage());
                        $errors++;
                    }
                } else {
                    $errors++;
                }
            }
        }

        $reader->close();

        // Оновлення множин у Redis
        try {
            $this->updateRedisSets();
            $this->info('Redis sets updated successfully.');
        } catch (\Exception $e) {
            $this->error('Error updating Redis sets: ' . $e->getMessage());
            \Log::error('Redis update error: ' . $e->getMessage());
            $errors++;
        }

        $this->info("Imported $categoryCount categories and $productCount products with $errors errors.");
        return 0;
    }

    /**
     * @param $categoryXml
     * @return array
     */
    private function parseCategory($categoryXml)
    {
        $xml = simplexml_load_string($categoryXml);
        return [
            'external_id' => (string) $xml['id'],
            'name' => (string) $xml,
            'parent_id' => isset($xml['parentId']) ? (string) $xml['parentId'] : null,
        ];
    }

    /**
     * @param $categories
     */
    private function saveCategories($categories)
    {
        $parentIds = array_filter(array_column($categories, 'parent_id'));

        foreach ($categories as $categoryData) {
            $brand = Brand::where('slug', Str::slug($categoryData['name'], '_'))->first();
            if ($brand && !in_array($categoryData['external_id'], $parentIds)) {
                $this->info("Skipping category {$categoryData['name']} as it matches a brand and is not a parent.");
                continue;
            }

            $baseSlug = Str::slug($categoryData['name'], '_');
            $slug = $baseSlug . '-' . $categoryData['external_id'];

            Category::firstOrCreate(
                ['external_id' => $categoryData['external_id']],
                ['name' => $categoryData['name'], 'slug' => $slug]
            );
        }

        foreach ($categories as $categoryData) {
            if ($categoryData['parent_id']) {
                $category = Category::where('external_id', $categoryData['external_id'])->first();
                $parent = Category::where('external_id', $categoryData['parent_id'])->first();
                if ($parent) {
                    $category->update(['parent_id' => $parent->id]);
                } else {
                    $this->warn("Parent category {$categoryData['parent_id']} not found for {$categoryData['external_id']}.");
                }
            }
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
                $data['parameters'][] = [
                    'name' => $paramName,
                    'slug' => Str::slug($paramName, '_'),
                    'value' => (string) $param,
                ];
            }
        }

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

        foreach ($data['pictures'] as $url) {
            ProductImage::firstOrCreate(
                ['product_id' => $product->id, 'url' => $url],
                ['url' => $url]
            );
        }

        foreach ($data['parameters'] as $paramData) {
            $parameter = Parameter::firstOrCreate(
                ['slug' => $paramData['slug']],
                ['name' => $paramData['name']]
            );

            $product->parameters()->syncWithoutDetaching([
                $parameter->id => ['value' => $paramData['value']],
            ]);
        }
    }

    // додаємо redis
    private function updateRedisSets()
    {
        $redis = Redis::connection();
        $redis->del($redis->keys('filter:*'));
        $redis->del($redis->keys('filter_values:*'));

        \Log::channel('import')->info('Starting Redis sets update');

        // Пакетна обробка брендів
        Product::with('brand')
            ->orderBy('id')
            ->chunk(100, function ($products) use ($redis) {
                $brands = $products->pluck('brand')->unique('id');
                foreach ($brands as $brand) {
                    $brandSlug = Str::slug($brand->name, '_');
                    $productIds = $products->where('brand_id', $brand->id)->pluck('id')->toArray();
                    if ($productIds) {
                        $redis->sadd("filter:brand:{$brandSlug}", ...$productIds);
                        $redis->sadd('filter_values:brand', $brandSlug);
                        \Log::channel('import')->info('Added brand filter', [
                            'brand_slug' => $brandSlug,
                            'product_count' => count($productIds),
                        ]);
                    }
                }
            });

        // Пакетна обробка параметрів
        \DB::table('product_parameters')
            ->join('parameters', 'product_parameters.parameter_id', '=', 'parameters.id')
            ->select('product_parameters.product_id', 'parameters.slug as param_slug', 'product_parameters.value')
            ->orderBy('product_parameters.id') // Додано
            ->chunk(100, function ($relations) use ($redis) {
                \Log::channel('import')->info('Processing parameter chunk', ['count' => count($relations)]);
                foreach ($relations as $relation) {
                    $valueSlug = Str::slug($relation->value, '_');
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
}
