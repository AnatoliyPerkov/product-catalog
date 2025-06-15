<?php

namespace App\Repositories;

use App\Contracts\ProductRepositoryInterface;
use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductRepository implements ProductRepositoryInterface
{
    /**
     * Отримує список продуктів із пагінацією на основі ID, сортування та параметрів
     * Фільтрує продукти за ID, сортує за ціною та повертає пагінований результат
     * @param array|null $productIds Масив ID продуктів для фільтрації (опціонально)
     * @param string $sortBy Параметр сортування (price_asc або price_desc)
     * @param int $limit Кількість елементів на сторінці
     * @param int $page Номер сторінки
     * @return LengthAwarePaginator Пагінований список продуктів
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
