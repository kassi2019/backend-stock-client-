<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarehouseStockEntry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PackConversionTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Supplier $supplier;
    protected Product $product; // pack_size = 6

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
            'name' => 'Eau céleste',
            'unit' => 'bouteille',
            'pack_size' => 6,
        ]);
    }

    /** Réception en paquets : 10 paquets de 6 → 60 bouteilles. */
    public function test_receive_in_packs_converts_to_base_units(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/supplier/warehouse-stock/{$this->product->id}/receive", [
            'quantity' => 10,
            'in_packs' => true,
        ])->assertStatus(201);

        $this->assertEquals(60.0, floatval($this->product->fresh()->stock_quantity));

        $entry = WarehouseStockEntry::first();
        $this->assertEquals(60.0, floatval($entry->quantity));
        $this->assertStringContainsString('paquets', $entry->note);
    }

    /** Vente comptoir en paquets : 2 paquets → 12 bouteilles retirées. */
    public function test_walk_in_sale_in_packs_converts_to_base_units(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/supplier/warehouse-stock/{$this->product->id}/receive", [
            'quantity' => 10,
            'in_packs' => true,
        ])->assertStatus(201);

        $res = $this->postJson('/api/v1/supplier/walk-in-sales', [
            'product_id' => $this->product->id,
            'sold_quantity' => 2,
            'in_packs' => true,
        ])->assertStatus(201);

        $res->assertJsonPath('sold', 12)
            ->assertJsonPath('stock_quantity', 48);
    }

    /** Vente en paquets sur un produit sans conditionnement → refusée. */
    public function test_in_packs_rejected_when_product_has_no_pack_size(): void
    {
        Sanctum::actingAs($this->owner);

        $plain = Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Riz',
            'unit' => 'sac',
            'stock_quantity' => 50,
        ]);

        $this->postJson('/api/v1/supplier/walk-in-sales', [
            'product_id' => $plain->id,
            'sold_quantity' => 1,
            'in_packs' => true,
        ])->assertStatus(422);
    }

    /** Livraison à un client saisie en paquets : initial_stock converti. */
    public function test_attach_batch_in_packs_converts_initial_stock(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/supplier/warehouse-stock/{$this->product->id}/receive", [
            'quantity' => 10,
            'in_packs' => true,
        ])->assertStatus(201);

        $clientUser = User::create([
            'name' => 'Client A', 'phone' => '0611111111', 'password' => 'secret', 'is_active' => true,
        ]);
        $clientUser->assignRole('client');
        $customer = Customer::create([
            'supplier_id' => $this->supplier->id,
            'owner_user_id' => $clientUser->id,
            'name' => 'Client A',
            'is_active' => true,
        ]);

        $res = $this->postJson("/api/v1/supplier/customers/{$customer->id}/products/batch", [
            'products' => [
                ['product_id' => $this->product->id, 'initial_stock' => 2, 'in_packs' => true],
            ],
        ])->assertStatus(201);

        $res->assertJsonPath('attached.0', $this->product->id);

        // Le stock du client est en unités de base : 2 paquets × 6 = 12 bouteilles
        $cp = \App\Models\CustomerProduct::where('customer_id', $customer->id)->first();
        $this->assertEquals(12.0, floatval($cp->initial_stock));

        // Le rattachement ne décrémente pas l'entrepôt (la livraison MAJ le fera) :
        // le restant distribuable passe de 60 à 48 via le calcul stock − distribué.
        $this->assertEquals(60.0, floatval($this->product->fresh()->stock_quantity));

        $res = $this->getJson('/api/v1/supplier/products');
        $res->assertJsonPath('products.0.remaining_stock', 48);
    }

    /** MAJ livraison en paquets : 1 paquet → +6 bouteilles. */
    public function test_update_delivery_in_packs_converts_quantity(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/supplier/warehouse-stock/{$this->product->id}/receive", [
            'quantity' => 10,
            'in_packs' => true,
        ])->assertStatus(201);

        $clientUser = User::create([
            'name' => 'Client A', 'phone' => '0611111111', 'password' => 'secret', 'is_active' => true,
        ]);
        $clientUser->assignRole('client');
        $customer = Customer::create([
            'supplier_id' => $this->supplier->id,
            'owner_user_id' => $clientUser->id,
            'name' => 'Client A',
            'is_active' => true,
        ]);

        $attach = $this->postJson("/api/v1/supplier/customers/{$customer->id}/products/batch", [
            'products' => [
                ['product_id' => $this->product->id, 'initial_stock' => 10],
            ],
        ])->assertStatus(201);

        $cpId = \App\Models\CustomerProduct::where('customer_id', $customer->id)->first()->id;

        $this->putJson("/api/v1/supplier/customer-products/{$cpId}", [
            'delivery_qty' => 1,
            'in_packs' => true,
        ])->assertOk()
            ->assertJsonPath('customer_product.recu', 16);

        $this->assertEquals(54.0, floatval($this->product->fresh()->stock_quantity));
    }
}
