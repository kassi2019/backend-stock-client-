<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_product_id',
        'customer_id',
        'supplier_id',
        'quantity',
        'note',
        'entry_type',
        'source',
        'entered_by_user_id',
        'entry_date',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'entry_date' => 'date',
    ];

    // --- Scopes ---

    public function scopeForSupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    // --- Relations ---

    public function customerProduct()
    {
        return $this->belongsTo(CustomerProduct::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }
}
