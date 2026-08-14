<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;
    protected User $owner;
    protected User $clientUser;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->owner = $this->makeUser('Owner A', '0600000001', 'supplier_owner');
        $this->supplier = Supplier::create([
            'owner_user_id' => $this->owner->id,
            'name' => 'Fournisseur A',
            'subscription_status' => 'active',
        ]);

        [$this->clientUser, $this->customer] = $this->makeClient($this->supplier, 'Client A', '0611111111');
    }

    // --- Helpers ---

    private function makeUser(string $name, string $phone, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'password' => 'secret',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        return $user;
    }

    private function makeClient(Supplier $supplier, string $name, string $phone): array
    {
        $user = $this->makeUser($name, $phone, 'client');
        $customer = Customer::create([
            'supplier_id' => $supplier->id,
            'owner_user_id' => $user->id,
            'name' => $name,
            'is_active' => true,
        ]);
        return [$user, $customer];
    }

    private function makePlan(string $symbol, string $position): Plan
    {
        return Plan::create([
            'name' => "Plan {$symbol}",
            'slug' => 'plan-' . strtolower($symbol),
            'monthly_price' => 10,
            'max_products' => 100,
            'max_customers' => 100,
            'max_staff' => 1,
            'trial_days' => 30,
            'is_active' => true,
            'currency' => 'EUR',
            'currency_symbol' => $symbol,
            'currency_position' => $position,
        ]);
    }

    private function createProduct(Supplier $supplier, string $name, $price = null, bool $isActive = true): Product
    {
        return Product::create([
            'supplier_id' => $supplier->id,
            'name' => $name,
            'unit' => 'pièce',
            'price' => $price,
            'is_active' => $isActive,
        ]);
    }

    private function acceptOrderAsOwner(int $orderId): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/accept")->assertOk();
    }

    // --- Tests ---

    public function test_product_price_validation(): void
    {
        Sanctum::actingAs($this->owner);

        $res = $this->postJson('/api/v1/supplier/products', [
            'name' => 'Œufs XL', 'unit' => 'boîte', 'price' => 12.5,
        ]);
        $res->assertStatus(201);
        $this->assertDatabaseHas('products', ['name' => 'Œufs XL', 'price' => '12.50']);

        $this->postJson('/api/v1/supplier/products', [
            'name' => 'Mauvais prix', 'unit' => 'boîte', 'price' => -1,
        ])->assertStatus(422);

        $this->postJson('/api/v1/supplier/products', [
            'name' => 'Prix texte', 'unit' => 'boîte', 'price' => 'gratuit',
        ])->assertStatus(422);

        // Mise à jour : prix puis retrait du prix
        $productId = $res->json('id');
        $this->putJson("/api/v1/supplier/products/{$productId}", ['price' => 15])
            ->assertOk()->assertJsonPath('price', '15.00');
        $this->putJson("/api/v1/supplier/products/{$productId}", ['price' => null])
            ->assertOk()->assertJsonPath('price', null);
    }

    public function test_catalog_isolation_and_currency(): void
    {
        $plan = $this->makePlan('$', 'before');
        $this->supplier->update(['plan_id' => $plan->id]);

        // Produits du fournisseur A : actif avec prix, actif sans prix, inactif
        $this->createProduct($this->supplier, 'Œufs XL', 12.5);
        $this->createProduct($this->supplier, 'Œufs moyens', null);
        $this->createProduct($this->supplier, 'Ancien produit', 5, false);

        // Fournisseur B avec son propre produit
        $ownerB = $this->makeUser('Owner B', '0600000002', 'supplier_owner');
        $supplierB = Supplier::create([
            'owner_user_id' => $ownerB->id, 'name' => 'Fournisseur B', 'subscription_status' => 'active',
        ]);
        $this->createProduct($supplierB, 'Farine', 20);

        Sanctum::actingAs($this->clientUser);
        $res = $this->getJson('/api/v1/client/catalog');
        $res->assertOk();

        $names = collect($res->json('products'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Œufs XL', 'Œufs moyens'], $names);

        $this->assertEquals([
            'code' => 'EUR',
            'symbol' => '$',
            'position' => 'before',
        ], $res->json('currency'));

        $xl = collect($res->json('products'))->firstWhere('name', 'Œufs XL');
        $this->assertSame(12.5, $xl['price']);
        $moyens = collect($res->json('products'))->firstWhere('name', 'Œufs moyens');
        $this->assertNull($moyens['price']);
    }

    public function test_price_change_notifies_all_active_clients(): void
    {
        // Clients : A (actif), B (actif), C (inactif), D (autre fournisseur)
        [, $customerB] = $this->makeClient($this->supplier, 'Client B', '0611111112');
        [$userC, $customerC] = $this->makeClient($this->supplier, 'Client C', '0611111113');
        $customerC->update(['is_active' => false]);
        $userC->update(['is_active' => false]);

        $ownerB = $this->makeUser('Owner B', '0600000002', 'supplier_owner');
        $supplierB = Supplier::create([
            'owner_user_id' => $ownerB->id, 'name' => 'Fournisseur B', 'subscription_status' => 'active',
        ]);
        [$userD] = $this->makeClient($supplierB, 'Client D', '0611111114');

        $product = $this->createProduct($this->supplier, 'Œufs XL', 10);

        // null → prix
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/supplier/products/{$product->id}", ['price' => 12])->assertOk();

        $this->assertNotNull($this->clientUser->notifications()->where('data->type', 'price_updated')->first());
        $this->assertNotNull($customerB->owner->notifications()->where('data->type', 'price_updated')->first());
        $this->assertCount(0, $userC->notifications()->where('data->type', 'price_updated')->get());
        $this->assertCount(0, $userD->notifications()->where('data->type', 'price_updated')->get());

        $payload = $this->clientUser->notifications()->first()->data;
        $this->assertSame('price_updated', $payload['type']);
        $this->assertSame($product->id, $payload['product_id']);
        $this->assertStringContainsString('Œufs XL', $payload['title']);

        // prix → prix (5.00 == 5 : pas de doublon de notification)
        $before = $this->clientUser->notifications()->count();
        $this->putJson("/api/v1/supplier/products/{$product->id}", ['price' => 5.00])->assertOk();
        $afterSame = $this->clientUser->notifications()->count();
        $this->assertGreaterThan($before, $afterSame); // 12 → 5 : notifié

        $this->putJson("/api/v1/supplier/products/{$product->id}", ['price' => 5])->assertOk();
        $this->assertSame($afterSame, $this->clientUser->notifications()->count()); // 5 == 5.00 : pas notifié

        // prix → null
        $this->putJson("/api/v1/supplier/products/{$product->id}", ['price' => null])->assertOk();
        $hasRetire = $this->clientUser->notifications()->get()
            ->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'retiré'));
        $this->assertTrue($hasRetire);
    }

    public function test_product_created_notifies_active_clients(): void
    {
        Sanctum::actingAs($this->owner);

        // Création avec prix → notification
        $this->postJson('/api/v1/supplier/products', [
            'name' => 'Nouveau produit', 'unit' => 'boîte', 'price' => 8,
        ])->assertStatus(201);
        $this->assertNotNull($this->clientUser->notifications()->where('data->type', 'product_created')->first());

        // Création sans prix → aucune notification
        $before = $this->clientUser->notifications()->count();
        $this->postJson('/api/v1/supplier/products', [
            'name' => 'Sans prix', 'unit' => 'boîte',
        ])->assertStatus(201);
        $this->assertSame($before, $this->clientUser->notifications()->count());
    }

    public function test_order_by_product_id_creates_cp(): void
    {
        $product = $this->createProduct($this->supplier, 'Œufs XL', 12.5);

        Sanctum::actingAs($this->clientUser);
        $res = $this->postJson('/api/v1/client/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ]);
        $res->assertStatus(201);

        $cp = CustomerProduct::where('customer_id', $this->customer->id)
            ->where('product_id', $product->id)
            ->first();
        $this->assertNotNull($cp);
        $this->assertEquals(0.0, floatval($cp->initial_stock));
        $this->assertEquals(0.0, floatval($cp->current_stock));
        $this->assertTrue((bool) $cp->is_active);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $res->json('order.id'),
            'customer_product_id' => $cp->id,
            'product_name' => 'Œufs XL',
        ]);
    }

    public function test_reorder_reuses_cp(): void
    {
        $product = $this->createProduct($this->supplier, 'Œufs XL', 12.5);

        Sanctum::actingAs($this->clientUser);
        $this->postJson('/api/v1/client/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);
        $this->postJson('/api/v1/client/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertStatus(201);

        $this->assertDatabaseCount('customer_products', 1);
    }

    public function test_confirm_delivery_increments_auto_created_cp(): void
    {
        $product = $this->createProduct($this->supplier, 'Œufs XL', 12.5);

        Sanctum::actingAs($this->clientUser);
        $orderId = $this->postJson('/api/v1/client/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 6]],
        ])->json('order.id');

        $this->acceptOrderAsOwner($orderId);

        Sanctum::actingAs($this->clientUser);
        $this->postJson("/api/v1/client/orders/{$orderId}/confirm-delivery")->assertOk();

        $cp = CustomerProduct::where('customer_id', $this->customer->id)->first();
        $this->assertEquals(6.0, floatval($cp->fresh()->initial_stock));
        $this->assertEquals(6.0, floatval($cp->fresh()->current_stock));
        $this->assertDatabaseHas('stock_entries', [
            'customer_product_id' => $cp->id,
            'entry_type' => 'delivery',
            'quantity' => 6,
        ]);
    }

    public function test_order_other_supplier_product_404(): void
    {
        $ownerB = $this->makeUser('Owner B', '0600000002', 'supplier_owner');
        $supplierB = Supplier::create([
            'owner_user_id' => $ownerB->id, 'name' => 'Fournisseur B', 'subscription_status' => 'active',
        ]);
        $productB = $this->createProduct($supplierB, 'Farine', 20);

        Sanctum::actingAs($this->clientUser);
        $this->postJson('/api/v1/client/orders', [
            'items' => [['product_id' => $productB->id, 'quantity' => 1]],
        ])->assertStatus(404);
    }

    public function test_order_validation_both_or_neither(): void
    {
        $product = $this->createProduct($this->supplier, 'Œufs XL', 12.5);
        $cp = CustomerProduct::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'initial_stock' => 10,
            'current_stock' => 10,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->clientUser);

        // Les deux ids → 422
        $this->postJson('/api/v1/client/orders', [
            'items' => [['customer_product_id' => $cp->id, 'product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);

        // Aucun id → 422
        $this->postJson('/api/v1/client/orders', [
            'items' => [['quantity' => 1]],
        ])->assertStatus(422);

        // Même produit via les deux chemins → 422 (doublon)
        $this->postJson('/api/v1/client/orders', [
            'items' => [
                ['customer_product_id' => $cp->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertStatus(422);
    }
}
