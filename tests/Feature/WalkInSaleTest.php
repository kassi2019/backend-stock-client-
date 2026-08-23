<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarehouseStockEntry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalkInSaleTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Supplier $supplier;
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

        $this->product = Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Riz 25kg',
            'unit' => 'sac',
            'stock_quantity' => 100,
        ]);
    }

    /** Vente comptoir en mode vendu : le stock entrepôt diminue. */
    public function test_walk_in_sale_decrements_warehouse_stock(): void
    {
        Sanctum::actingAs($this->owner);

        $res = $this->postJson('/api/v1/supplier/walk-in-sales', [
            'product_id' => $this->product->id,
            'sold_quantity' => 20,
        ])->assertStatus(201);

        $res->assertJsonPath('sold', 20)
            ->assertJsonPath('stock_quantity', 80);

        $this->assertEquals(80.0, floatval($this->product->fresh()->stock_quantity));

        $entry = WarehouseStockEntry::first();
        $this->assertSame('sale', $entry->entry_type);
        $this->assertEquals(-20.0, floatval($entry->quantity));
    }

    /** Mode restant : le vendu est calculé (stock 100, restant 40 → vendu 60). */
    public function test_walk_in_sale_remaining_mode_computes_sold(): void
    {
        Sanctum::actingAs($this->owner);

        $res = $this->postJson('/api/v1/supplier/walk-in-sales', [
            'product_id' => $this->product->id,
            'remaining_quantity' => 40,
        ])->assertStatus(201);

        $res->assertJsonPath('sold', 60)
            ->assertJsonPath('stock_quantity', 40);

        $this->assertEquals(40.0, floatval($this->product->fresh()->stock_quantity));
    }

    /** Mode restant incohérent : restant > stock entrepôt → bloqué. */
    public function test_walk_in_sale_rejects_remaining_above_stock(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/supplier/walk-in-sales', [
            'product_id' => $this->product->id,
            'remaining_quantity' => 150,
        ])->assertStatus(422);

        $this->assertDatabaseCount('warehouse_stock_entries', 0);
        $this->assertEquals(100.0, floatval($this->product->fresh()->stock_quantity));
    }

    /** Le stock entrepôt expose les ventes comptoir du jour (sold_today). */
    public function test_warehouse_index_exposes_sold_today(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/supplier/walk-in-sales', [
            'product_id' => $this->product->id,
            'sold_quantity' => 15,
        ])->assertStatus(201);

        $res = $this->getJson('/api/v1/supplier/warehouse-stock');
        $res->assertOk()
            ->assertJsonPath('products.0.sold_today', 15)
            ->assertJsonPath('products.0.stock_quantity', 85);
    }
}
