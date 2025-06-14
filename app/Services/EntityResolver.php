<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

trait EntityResolver
{
    /**
     * @param string $modelClass
     * @param string $value
     * @return Model|null
     */
    protected function resolveEntity(string $modelClass, string $value): ?Model
    {
        $model = app($modelClass);
        $entity = $model::where('id', $value)->first() ?? $model::where('slug', $value)->first();
        if (!$entity) {
            Log::warning("{$modelClass} not found", ['value' => $value]);
        }
        return $entity;
    }
}
