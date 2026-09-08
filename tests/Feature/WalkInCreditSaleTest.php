<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalkInCreditSaleTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Supplier $supplier;
    protected Customer $customer;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->owner = User::create([
            'name' => 'Owner A', 'phone' => '0600000001', 'password' => 'secret', 'is_active' => true,
        ]);
        $this->owner->assignRole('supplier_owner');

        $this->supplier = Supplier::create([
            'owner_user_id' => $this->owner->id,
            'name' => 'Fournisseur A',
            'subscription_status' => 'active',
        ]);

        $this->customer = Customer::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Client A',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Eau céleste',
            'unit' => 'bouteille',
            'price' => 500,
            'stock_quantity' => 100,
        ]);
    }

    /** Remise à crédit : entrepôt baisse, stock client monte, crédit augmente. */
    public function test_credit_sale_updates_warehouse_customer_stock_and_credit(): void
    {
        Sanctum::actingAs($this->owner);

        $res = $this->postJson('/api/v1/supplier/walk-in-credit-sales', [
            'customer_id' => $this->customer->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 10],
            ],
        ])->assertStatus(201);

        $res->assertJsonPath('total_value', 5000);

        // Entrepôt : 100 − 10 = 90
        $this->assertEquals(90.0, floatval($this->product->fresh()->stock_quantity));

        // Stock client : le produit est rattaché avec 10 reçus / 10 restants
        $cp = \App\Models\CustomerProduct::where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($cp);
        $this->assertEquals(10.0, floatval($cp->initial_stock));
        $this->assertEquals(10.0, floatval($cp->current_stock));

        // Livraison valorisée (prix figé) → crédit du client
        $entry = StockEntry::where('customer_id', $this->customer->id)->first();
        $this->assertSame('delivery', $entry->entry_type);
        $this->assertEquals(500.0, floatval($entry->unit_price));

        $fin = $this->getJson('/api/v1/supplier/finances')->assertOk();
        $fin->assertJsonPath('customers.0.delivered_total', 5000)
            ->assertJsonPath('total_credit', 5000);
    }

    /** Client inexistant refusé. */
    public function test_unknown_customer_rejected(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/supplier/walk-in-credit-sales', [
            'customer_id' => 9999,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10]],
        ])->assertStatus(404);
    }

    /** Quantité nulle ou négative refusée. */
    public function test_zero_quantity_rejected(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/supplier/walk-in-credit-sales', [
            'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 0]],
        ])->assertStatus(422);
    }
}
