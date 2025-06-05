<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Parameter;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use XMLReader;

class ImportProducts extends Command
{
    protected $signature = 'import:products {file : The path to the XML file}';
    protected $description = 'Import products and categories from an XML file';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @return int
     */
    public function handle(): int
    {
        $file = $this->argument('file');
        $this->info("Checking file: {$file}");

        if (!file_exists($file)) {
            $this->error("File not found at path: {$file}");
            $realPath = realpath($file);
            $this->info("Real path: " . ($realPath ?: 'Not resolved'));
            return 1;
        }

        $this->info('Starting XML import...');

        $reader = new XMLReader();
        $reader->open($file);

        $categories = [];
        $productCount = 0;
        $categoryCount = 0;
        $errors = 0;

        // Перший прохід: збирання категорій
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'category') {
                $categoryXml = $reader->readOuterXML();
                $categories[] = $this->parseCategory($categoryXml);
            }
        }

        // Збереження категорій
        try {
            $this->saveCategories($categories);
            $categoryCount = Category::count();
        } catch (\Exception $e) {
            $this->error('Error saving categories: ' . $e->getMessage());
            \Log::error('Category import error: ' . $e->getMessage());
            return 1;
        }

        // Другий прохід: обробка товарів
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
        // Збираємо external_id батьківських категорій
        $parentIds = array_filter(array_column($categories, 'parent_id'));

        foreach ($categories as $categoryData) {
            // Пропускаємо категорію, якщо вона збігається з брендом І не є батьківською
            $brand = Brand::where('slug', Str::slug($categoryData['name'], '_'))->first();
            if ($brand && !in_array($categoryData['external_id'], $parentIds)) {
                $this->info("Skipping category {$categoryData['name']} as it matches a brand and is not a parent.");
                continue;
            }

            // Генеруємо унікальний slug
            $baseSlug = Str::slug($categoryData['name'], '_');
            $slug = $baseSlug . '-' . $categoryData['external_id'];

            Category::firstOrCreate(
                ['external_id' => $categoryData['external_id']],
                [
                    'name' => $categoryData['name'],
                    'slug' => $slug,
                ]
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
            'description_format' => (string) $xml->description_format,
            'vendor' => (string) $xml->vendor,
            'vendor_code' => (string) $xml->vendorCode,
            'barcode' => (string) $xml->barcode,
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
                'description' => $data['description'],
                'description_format' => $data['description_format'],
                'vendor_code' => $data['vendor_code'],
                'barcode' => $data['barcode'],
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
}
