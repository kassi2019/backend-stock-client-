<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'owner_user_id',
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'notes',
        'default_frequency',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // --- Scopes ---

    public function scopeForSupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    // --- Relations ---

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function customerProducts()
    {
        return $this->hasMany(CustomerProduct::class);
    }

    public function stockEntries()
    {
        return $this->hasMany(StockEntry::class);
    }
}
