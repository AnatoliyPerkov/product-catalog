<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $external_id
 * @property string $name
 * @property float $price
 * @property string|null $description
 * @property string|null $description_format
 * @property string|null $vendor_code
 * @property string|null $barcode
 * @property bool $available
 * @property string $currency
 * @property int $brand_id
 * @property int $category_id
 * @property int $stock
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Brand $brand
 * @property-read Category $category
 * @property-read Collection|Parameter[] $parameters
 */
class Product extends Model
{
    protected $fillable = [
        'external_id',
        'name',
        'price',
        'stock',
        'description',
        'description_format',
        'vendor_code',
        'barcode',
        'available',
        'currency',
        'brand_id',
        'category_id',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function parameters(): BelongsToMany
    {
        return $this->belongsToMany(Parameter::class, 'product_parameters')
            ->withPivot('value')
            ->withTimestamps();
    }
}
