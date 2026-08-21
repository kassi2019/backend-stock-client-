<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'customer_product_id',
        'product_id',
        'product_name',
        'unit',
        'quantity',
        'delivered_quantity',
        'unit_price',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'delivered_quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
    ];

    /**
     * Quantité restant à livrer pour cet article.
     */
    public function remainingQuantity(): float
    {
        return max(0, floatval($this->quantity) - floatval($this->delivered_quantity));
    }

    // --- Relations ---

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function customerProduct()
    {
        return $this->belongsTo(CustomerProduct::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
