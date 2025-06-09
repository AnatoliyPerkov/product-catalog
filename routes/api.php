<?php

use App\Http\Controllers\Api\CatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('catalog')->group(function () {
    Route::get('/products', [CatalogController::class, 'products'])->name('catalog.products');
    Route::get('/filters', [CatalogController::class, 'filters'])->name('catalog.filters');
});
