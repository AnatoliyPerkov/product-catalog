<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Перетворює модель продукту в масив для JSON-відповіді
     * Формує масив із ключовими полями продукту: ID, назва, ціна, опис
     * @param Request $request HTTP-запит
     * @return array Масив даних продукту
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => number_format($this->price, 2),
            'description' => $this->description,
        ];
    }
}
