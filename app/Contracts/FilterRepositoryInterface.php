<?php

namespace App\Contracts;

interface FilterRepositoryInterface
{
    public function getProductIds(array $filters): array;
    public function getFilterValues(string $paramSlug): array;
    public function getProductCount(string $paramSlug, string $value, ?array $activeIds): int;
}
