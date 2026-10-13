<?php

namespace App\Http\Resources\Api\V1\Product;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Category\CategoryResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'thumbnail' => $this->thumbnail ? asset($this->thumbnail) : "",
            'coverImage' => $this->cover_image ? asset($this->cover_image) : "",
            'shortDescription' => $this->short_description,
            'description' => $this->description,
            'purchasePrice' => round((float) $this->purchase_price,2),
            // The trade price: what the outlet pays us.
            'salePrice' => round((float) $this->sale_price,2),
            /* The retail price printed on the pack. Null until somebody
             * records it — the screens say "not set" rather than showing a
             * confident 0 next to a real trade price. */
            'mrp' => $this->mrp !== null ? round((float) $this->mrp, 2) : null,
            'stock' => (int) $this->stock,
            'slug' => $this->slug,
            'category' => new CategoryResource($this->category),
            'categoryName' => $this->category?->name,
            'status' => $this->status,
        ];
    }
}
