<?php

namespace App\Contracts;

interface FilterRepositoryInterface
{
    public function getProductIds(array $filters): array;
    public function getFilterValues(string $paramSlug, array $filters = []): array;
    public function getProductCount(string $paramSlug, string $value, ?array $activeIds, array $filters = []): int;
}
