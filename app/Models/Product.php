<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'sku',
        'name',
        'thumbnail',
        'cover_image',
        'short_description',
        'description',
        'purchase_price',
        // The trade price: what the outlet pays us.
        'sale_price',
        // The retail price printed on the pack: what the shopper pays. A rep is
        // asked this at the counter, so it has to be on screen.
        'mrp',
        'stock',
        'slug',
        'category_id',
        'status',
        'created_at',
        'updated_at',
    ];

    public function category() {
        return $this->belongsTo(Category::class);
    }

    public function orderItems() {
        return $this->hasMany(OrderItem::class);
    }

}
