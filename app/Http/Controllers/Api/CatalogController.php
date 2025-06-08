<?php

namespace App\Http\Controllers\Api;

use App\Contracts\FilterRepositoryInterface;
use App\Contracts\ProductRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Parameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    protected FilterRepositoryInterface $filterRepository;
    protected ProductRepositoryInterface $productRepository;

    public function __construct(FilterRepositoryInterface $filterRepository, ProductRepositoryInterface $productRepository)
    {
        $this->filterRepository = $filterRepository;
        $this->productRepository = $productRepository;
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function products(Request $request): JsonResponse
    {
        $filters = $request->input('filter', []);
        $sortBy = $request->input('sort_by', 'price_asc');
        $limit = (int) $request->input('limit', 5);
        $page = (int) $request->input('page', 1);

        $productIds = $this->filterRepository->getProductIds($filters);
        $products = $this->productRepository->getProducts($productIds, $sortBy, $limit, $page);

        return response()->json([
            'data' => ProductResource::collection($products->items()),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ]
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function filters(Request $request): JsonResponse
    {
        $filters = $request->input('filter', []);
        $activeIds = $this->filterRepository->getProductIds($filters);

        $availableFilters = [];
        $parameters = Parameter::all();
        foreach ($parameters as $param) {
            $values = $this->filterRepository->getFilterValues($param->slug);
            $filterValues = [];
            foreach ($values as $value) {
                $count = $this->filterRepository->getProductCount($param->slug, $value, $activeIds);
                if ($count > 0) {
                    $filterValues[] = [
                        'value' => $value,
                        'count' => $count,
                        'active' => isset($filters[$param->slug]) && in_array($value, array_map(fn($v) => \Str::slug($v, '_'), (array)$filters[$param->slug])),
                    ];
                }
            }
            if ($filterValues) {
                $availableFilters[] = [
                    'name' => $param->name,
                    'slug' => $param->slug,
                    'values' => $filterValues,
                ];
            }
        }

        return response()->json($availableFilters);
    }
}
