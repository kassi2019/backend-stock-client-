<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttachProductsBatchTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Supplier $supplier;
    protected Customer $customer;

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

        $clientUser = User::create([
            'name' => 'Client A', 'phone' => '0611111111', 'password' => 'secret', 'is_active' => true,
        ]);
        $clientUser->assignRole('client');

        $this->customer = Customer::create([
            'supplier_id' => $this->supplier->id,
            'owner_user_id' => $clientUser->id,
            'name' => 'Client A',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->owner);
    }

    private function makeProduct(string $name, float $stock = 0): Product
    {
        return Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => $name,
            'unit' => 'pièce',
            'stock_quantity' => $stock,
        ]);
    }

    private int $customerSeq = 0;

    private function makeCustomer(string $name): Customer
    {
        $this->customerSeq++;
        $user = User::create([
            'name' => $name,
            'phone' => '061200' . str_pad((string) $this->customerSeq, 4, '0', STR_PAD_LEFT),
            'password' => 'secret',
            'is_active' => true,
        ]);
        $user->assignRole('client');

        return Customer::create([
            'supplier_id' => $this->supplier->id,
            'owner_user_id' => $user->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    /** Rattachement en lot : crée les liens avec stock initial et seuil. */
    public function test_batch_attaches_products(): void
    {
        $p1 = $this->makeProduct('Riz', 10);
        $p2 = $this->makeProduct('Huile', 5);
        $p3 = $this->makeProduct('Sucre', 2);

        $res = $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/products/batch", [
            'products' => [
                ['product_id' => $p1->id, 'initial_stock' => 10, 'reorder_point' => 5],
                ['product_id' => $p2->id, 'initial_stock' => 3],
                ['product_id' => $p3->id, 'initial_stock' => 0],
            ],
        ]);

        $res->assertStatus(201)->assertJsonPath('message', '3 produit(s) rattaché(s).');
        $this->assertSame(3, CustomerProduct::where('customer_id', $this->customer->id)->count());
        $this->assertDatabaseHas('customer_products', [
            'customer_id' => $this->customer->id,
            'product_id' => $p1->id,
            'initial_stock' => 10,
            'current_stock' => 10,
            'reorder_point' => 5,
        ]);
    }

    /** Les produits déjà rattachés sont ignorés sans être écrasés. */
    public function test_batch_skips_already_attached(): void
    {
        $p1 = $this->makeProduct('Riz', 0);
        $p2 = $this->makeProduct('Huile', 2);
        CustomerProduct::create([
            'customer_id' => $this->customer->id,
            'product_id' => $p1->id,
            'supplier_id' => $this->supplier->id,
            'initial_stock' => 4,
            'current_stock' => 4,
        ]);

        $res = $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/products/batch", [
            'products' => [
                ['product_id' => $p1->id, 'initial_stock' => 99],
                ['product_id' => $p2->id, 'initial_stock' => 2],
            ],
        ]);

        $res->assertStatus(201)->assertJsonPath('attached.0', $p2->id);
        $this->assertDatabaseHas('customer_products', [
            'customer_id' => $this->customer->id,
            'product_id' => $p1->id,
            'initial_stock' => 4, // inchangé
        ]);
        $this->assertDatabaseHas('customer_products', [
            'customer_id' => $this->customer->id,
            'product_id' => $p2->id,
            'initial_stock' => 2,
        ]);
    }

    /** Tout déjà rattaché → 422 avec message clair. */
    public function test_batch_all_already_attached(): void
    {
        $p1 = $this->makeProduct('Riz');
        CustomerProduct::create([
            'customer_id' => $this->customer->id,
            'product_id' => $p1->id,
            'supplier_id' => $this->supplier->id,
            'initial_stock' => 4,
            'current_stock' => 4,
        ]);

        $res = $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/products/batch", [
            'products' => [['product_id' => $p1->id, 'initial_stock' => 99]],
        ]);

        $res->assertStatus(422);
    }

    /** Un produit d'un autre fournisseur est refusé. */
    public function test_batch_rejects_foreign_product(): void
    {
        $otherOwner = User::create([
            'name' => 'Owner B', 'phone' => '0600000002', 'password' => 'secret', 'is_active' => true,
        ]);
        $otherOwner->assignRole('supplier_owner');
        $otherSupplier = Supplier::create([
            'owner_user_id' => $otherOwner->id,
            'name' => 'Fournisseur B',
            'subscription_status' => 'active',
        ]);
        $foreign = Product::create([
            'supplier_id' => $otherSupplier->id,
            'name' => 'Riz B',
            'unit' => 'pièce',
        ]);

        $res = $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/products/batch", [
            'products' => [['product_id' => $foreign->id, 'initial_stock' => 5]],
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, CustomerProduct::where('customer_id', $this->customer->id)->count());
    }

    /** Validation : la quantité livrée est obligatoire pour chaque produit. */
    public function test_batch_requires_initial_stock(): void
    {
        $p1 = $this->makeProduct('Riz');

        $res = $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/products/batch", [
            'products' => [['product_id' => $p1->id]],
        ]);

        $res->assertStatus(422);
    }

    /** Rattachement refusé si la quantité dépasse le reste distribuable. */
    public function test_batch_rejects_qty_exceeding_remaining(): void
    {
        $product = $this->makeProduct('Coca', 100);
        // Le client 1 a déjà reçu 50
        CustomerProduct::create([
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'supplier_id' => $this->supplier->id,
            'initial_stock' => 50,
            'current_stock' => 50,
        ]);
        $customer2 = $this->makeCustomer('Client B');

        $res = $this->postJson("/api/v1/supplier/customers/{$customer2->id}/products/batch", [
            'products' => [['product_id' => $product->id, 'initial_stock' => 100]],
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Quantité insuffisante pour « Coca » : il ne reste que 50 à distribuer.');
        $this->assertSame(0, CustomerProduct::where('customer_id', $customer2->id)->count());
    }

    /** Le rattachement est accepté jusqu'au reste exact. */
    public function test_batch_allows_up_to_remaining(): void
    {
        $product = $this->makeProduct('Coca', 100);
        CustomerProduct::create([
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'supplier_id' => $this->supplier->id,
            'initial_stock' => 50,
            'current_stock' => 50,
        ]);
        $customer2 = $this->makeCustomer('Client B');

        $res = $this->postJson("/api/v1/supplier/customers/{$customer2->id}/products/batch", [
            'products' => [['product_id' => $product->id, 'initial_stock' => 50]],
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('customer_products', [
            'customer_id' => $customer2->id,
            'product_id' => $product->id,
            'initial_stock' => 50,
        ]);
    }

    /** Le rattachement simple refuse aussi le dépassement. */
    public function test_single_attach_rejects_exceeding_remaining(): void
    {
        $product = $this->makeProduct('Coca', 100);
        CustomerProduct::create([
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'supplier_id' => $this->supplier->id,
            'initial_stock' => 50,
            'current_stock' => 50,
        ]);
        $customer2 = $this->makeCustomer('Client B');

        $res = $this->postJson("/api/v1/supplier/customers/{$customer2->id}/products", [
            'product_id' => $product->id,
            'initial_stock' => 80,
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, CustomerProduct::where('customer_id', $customer2->id)->count());
    }

    /** L'index produits expose le reste distribuable (stock − déjà attribué). */
    public function test_products_index_includes_remaining_stock(): void
    {
        $product = $this->makeProduct('Coca', 100);
        CustomerProduct::create([
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'supplier_id' => $this->supplier->id,
            'initial_stock' => 50,
            'current_stock' => 50,
        ]);

        $res = $this->getJson('/api/v1/supplier/products');

        $res->assertStatus(200)
            ->assertJsonPath('products.0.remaining_stock', 50);
    }
}
