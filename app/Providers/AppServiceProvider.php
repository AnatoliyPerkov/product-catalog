<?php

namespace App\Providers;

use App\Contracts\FilterRepositoryInterface;
use App\Contracts\ProductRepositoryInterface;
use App\Repositories\ProductRepository;
use App\Repositories\RedisFilterRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FilterRepositoryInterface::class, RedisFilterRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, ProductRepository::class);

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
