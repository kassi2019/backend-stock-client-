<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'monthly_price',
        'currency', 'currency_symbol', 'currency_position',
        'max_products', 'max_customers', 'max_staff',
        'trial_days', 'is_active',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Formatter le prix avec la devise.
     */
    public function formattedPrice(): string
    {
        $price = number_format($this->monthly_price, 2, ',', ' ');
        $symbol = $this->currency_symbol ?? '€';

        if ($this->monthly_price == 0) {
            return 'Gratuit';
        }

        if (($this->currency_position ?? 'after') === 'before') {
            return $symbol . ' ' . $price . '/mois';
        }
        return $price . ' ' . $symbol . '/mois';
    }

    public function suppliers()
    {
        return $this->hasMany(Supplier::class);
    }

    /**
     * Plan d'essai par défaut.
     */
    public static function defaultTrial(): ?self
    {
        return self::where('slug', 'trial')->first() ?? self::where('is_active', true)->first();
    }
}
