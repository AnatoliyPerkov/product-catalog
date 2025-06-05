<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['external_id', 'name', 'price', 'description', 'brand_id', 'category_id', 'stock'];

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function parameters()
    {
        return $this->belongsToMany(Parameter::class, 'product_parameters')
            ->withPivot('value')
            ->withTimestamps();
    }
}
