<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

trait EntityResolver
{
    /**
     * Знаходить модель за ID або слагом
     * Шукає запис у базі даних за заданим значенням, спочатку як ID, потім як слаг
     * @param string $modelClass Клас моделі (наприклад, App\Models\Category)
     * @param string $value Значення для пошуку (ID або слаг)
     * @return Model|null Знайдена модель або null, якщо не знайдено
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
