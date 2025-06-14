<?php

namespace App\Http\Controllers\Api;

use App\Contracts\FilterRepositoryInterface;
use App\Contracts\ProductRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Resources\ProductResource;
use Illuminate\Support\Facades\Log;

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
    public function filters(Request $request): JsonResponse
    {
        $filters = $request->input('filter', []);
        Log::info('Filters request started', ['filters' => $filters, 'query' => $request->query()]);

        try {
            Log::debug('Controller calling getFilterValues', [
                'paramSlug' => $request->input('paramSlug', 'all'),
                'filters' => $filters
            ]);

            $result = $this->filterRepository->getFilterValues($request->input('paramSlug', 'all'), $filters);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Filters request failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $filters,
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function products(Request $request): JsonResponse
    {
        $filters = $request->input('filter', []);
        $sortBy = $request->input('sort', 'price') . '_' . $request->input('order', 'asc');
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 10);

        try {
            $productIds = $this->filterRepository->getProductIds($filters);
            $products = $this->productRepository->getProducts($productIds, $sortBy, $limit, $page);

            $total = 0;
            if (isset($filters['category'])) {
                $categorySlug = (string)($filters['category'][0] ?? '');
                if (!$categorySlug) {
                    return response()->json(['error' => 'Category slug is required'], 400);
                }

                $category = Category::where('slug', $categorySlug)->first();
                if (!$category) {
                    Log::warning('Category not found', ['slug' => $categorySlug]);
                    return response()->json(['error' => 'Category not found'], 404);
                }

                $total = $this->filterRepository->getProductCount('category', (string)$category->id, $productIds, $filters);
            } elseif (isset($filters['brand'])) {
                $brandSlug = (string)($filters['brand'][0] ?? '');
                if (!$brandSlug) {
                    Log::warning('Empty brand slug provided', ['filters' => $filters]);
                    return response()->json(['error' => 'Brand slug is required'], 400);
                }

                $brand = Brand::where('slug', $brandSlug)->first();
                if (!$brand) {
                    Log::warning('Brand not found', ['slug' => $brandSlug]);
                    return response()->json(['error' => 'Brand not found'], 404);
                }

                $total = $this->filterRepository->getProductCount('brand', (string)$brand->id, $productIds, $filters);
            } else {
                foreach ($filters as $slug => $values) {
                    if (str_starts_with($slug, 'param_')) {
                        $value = (string)($values[0] ?? '');
                        if (!$value) {
                            Log::warning('Empty parameter value provided', ['slug' => $slug, 'filters' => $filters]);
                            continue;
                        }
                        $total = $this->filterRepository->getProductCount($slug, $value, $productIds, $filters);
                        break;
                    }
                }
            }

            if ($total === 0) {
                $total = count($productIds);
            }

            return response()->json([
                'data' => ProductResource::collection($products->items()),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $total,
                    'last_page' => ceil($total / $products->perPage()),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Products request failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $filters,
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
}
