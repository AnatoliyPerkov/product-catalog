<?php

namespace App\Repositories;

use App\Contracts\ProductRepositoryInterface;
use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentProductRepository implements ProductRepositoryInterface
{
    /**
     * @param array|null $productIds
     * @param string $sortBy
     * @param int $limit
     * @param int $page
     * @return LengthAwarePaginator
     */
    public function getProducts(?array $productIds, string $sortBy, int $limit, int $page): LengthAwarePaginator
    {
        $query = Product::query();
        if ($productIds) {
            $query->whereIn('id', $productIds);
        }

        if ($sortBy === 'price_asc') {
            $query->orderBy('price', 'asc');
        } elseif ($sortBy === 'price_desc') {
            $query->orderBy('price', 'desc');
        }

        return $query->with(['brand', 'category'])->paginate($limit, ['*'], 'page', $page);
    }
}
