<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use HasFactory;

    protected $fillable = ['supplier_id', 'name'];

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
}
