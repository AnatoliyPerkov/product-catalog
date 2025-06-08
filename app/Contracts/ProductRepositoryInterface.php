<?php

namespace App\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface ProductRepositoryInterface
{
    public function getProducts(?array $productIds, string $sortBy, int $limit, int $page): LengthAwarePaginator;
}
