<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarehouseStockEntry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseStockTest extends TestCase
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
    }

    private function makeProduct(string $name = 'Œufs', ?float $threshold = null, float $stock = 0): Product
    {
        return Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => $name,
            'unit' => 'pièce',
            'stock_quantity' => $stock,
            'stock_threshold' => $threshold,
        ]);
    }

    /** Réception : le stock monte et l'entrée est journalisée. */
    public function test_receive_increases_warehouse_stock_and_creates_entry(): void
    {
        $product = $this->makeProduct();
        Sanctum::actingAs($this->owner);

        $res = $this->postJson("/api/v1/supplier/warehouse-stock/{$product->id}/receive", [
            'quantity' => 10,
            'note' => 'Achat du matin',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('product.stock_quantity', 10)
            ->assertJsonPath('message', 'Réception enregistrée. Stock entrepôt mis à jour.');

        $this->assertDatabaseHas('warehouse_stock_entries', [
            'product_id' => $product->id,
            'quantity' => 10.00,
            'entry_type' => 'receipt',
            'note' => 'Achat du matin',
        ]);
        $this->assertEquals(10.0, floatval($product->fresh()->stock_quantity));
    }

    /** Ajustement : fixe le total, note obligatoire, delta signé journalisé. */
    public function test_adjust_sets_total_and_requires_note(): void
    {
        $product = $this->makeProduct(stock: 30);
        Sanctum::actingAs($this->owner);

        // Sans note → 422
        $this->postJson("/api/v1/supplier/warehouse-stock/{$product->id}/adjust", [
            'quantity' => 25,
        ])->assertStatus(422);

        // Avec note → total fixé
        $res = $this->postJson("/api/v1/supplier/warehouse-stock/{$product->id}/adjust", [
            'quantity' => 25,
            'note' => 'Inventaire de fin de mois',
        ]);
        $res->assertOk()->assertJsonPath('product.stock_quantity', 25);

        $this->assertDatabaseHas('warehouse_stock_entries', [
            'product_id' => $product->id,
            'quantity' => -5.00,
            'entry_type' => 'adjustment',
            'note' => 'Inventaire de fin de mois',
        ]);
    }

    /** Historique : scopé produit, ordre desc, montants en nombre. */
    public function test_warehouse_history_scoped_and_ordered(): void
    {
        $product = $this->makeProduct();
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/supplier/warehouse-stock/{$product->id}/receive", ['quantity' => 10]);
        $this->postJson("/api/v1/supplier/warehouse-stock/{$product->id}/receive", ['quantity' => 5]);

        $other = $this->makeProduct('Lait');
        $this->postJson("/api/v1/supplier/warehouse-stock/{$other->id}/receive", ['quantity' => 7]);

        $res = $this->getJson("/api/v1/supplier/warehouse-stock/{$product->id}/history");
        $res->assertOk()->assertJsonPath('current_stock', 15);
        $entries = $res->json('entries.data');
        $this->assertCount(2, $entries);
        $this->assertEquals(5.0, $entries[0]['quantity']);
        $this->assertEquals(10.0, $entries[1]['quantity']);
    }

    /** MAJ livraison manuelle : stock client augmenté ET entrepôt déduit. */
    public function test_manual_delivery_deducts_warehouse_stock(): void
    {
        $product = $this->makeProduct(stock: 50);
        $cp = CustomerProduct::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'initial_stock' => 100,
            'current_stock' => 80,
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/supplier/customer-products/{$cp->id}", [
            'delivery_qty' => 12,
        ])->assertOk();

        $this->assertEquals(112.0, floatval($cp->fresh()->initial_stock));
        $this->assertEquals(38.0, floatval($product->fresh()->stock_quantity));
        $this->assertDatabaseHas('warehouse_stock_entries', [
            'product_id' => $product->id,
            'quantity' => -12.00,
            'entry_type' => 'delivery',
            'note' => 'Livraison à « Client A » (saisie manuelle)',
        ]);
    }

    /** Confirmation de réception d'une commande : déduction par article. */
    public function test_confirm_delivery_deducts_warehouse_stock(): void
    {
        $clientUser = $this->customer->owner;
        $product = $this->makeProduct(stock: 30);
        $cp = CustomerProduct::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'initial_stock' => 0,
            'current_stock' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($clientUser);
        $orderId = $this->postJson('/api/v1/client/orders', [
            'items' => [['customer_product_id' => $cp->id, 'quantity' => 8]],
        ])->assertStatus(201)->json('order.id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/accept")->assertOk();

        Sanctum::actingAs($clientUser);
        $this->postJson("/api/v1/client/orders/{$orderId}/confirm-delivery")->assertOk();

        $this->assertEquals(22.0, floatval($product->fresh()->stock_quantity));
        $this->assertDatabaseHas('warehouse_stock_entries', [
            'product_id' => $product->id,
            'quantity' => -8.00,
            'entry_type' => 'delivery',
            'note' => "Livraison commande #{$orderId}",
        ]);
    }

    /** Livrer plus que le disponible n'est jamais bloqué : stock négatif + alerte critique. */
    public function test_warehouse_stock_can_go_negative(): void
    {
        $product = $this->makeProduct(stock: 5);
        $cp = CustomerProduct::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'initial_stock' => 0,
            'current_stock' => 0,
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/supplier/customer-products/{$cp->id}", [
            'delivery_qty' => 10,
        ])->assertOk();

        $this->assertEquals(-5.0, floatval($product->fresh()->stock_quantity));
        $this->assertDatabaseHas('stock_alerts', [
            'product_id' => $product->id,
            'type' => 'warehouse_stock',
            'severity' => 'critical',
            'resolved_at' => null,
        ]);
    }

    /** Passage sous le seuil → alerte ; réception au-dessus → résolution auto. */
    public function test_alert_created_below_threshold_and_auto_resolved_above(): void
    {
        $product = $this->makeProduct(threshold: 10, stock: 15);
        Sanctum::actingAs($this->owner);

        // Livraison qui fait passer à 5 (sous le seuil de 10)
        $cp = CustomerProduct::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'initial_stock' => 0,
            'current_stock' => 0,
            'is_active' => true,
        ]);
        $this->putJson("/api/v1/supplier/customer-products/{$cp->id}", ['delivery_qty' => 10])->assertOk();

        $alert = StockAlert::where('product_id', $product->id)->whereNull('resolved_at')->first();
        $this->assertNotNull($alert);
        $this->assertEquals('warning', $alert->severity);

        // Réception qui repasse au-dessus → résolue
        $this->postJson("/api/v1/supplier/warehouse-stock/{$product->id}/receive", ['quantity' => 10])->assertStatus(201);
        $this->assertNotNull(StockAlert::find($alert->id)->fresh()->resolved_at);
    }

    /** Deux déductions successives sous le seuil → une seule alerte ouverte. */
    public function test_alert_no_duplicate_on_multiple_deductions(): void
    {
        $product = $this->makeProduct(threshold: 10, stock: 12);
        $cp = CustomerProduct::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'initial_stock' => 0,
            'current_stock' => 0,
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/supplier/customer-products/{$cp->id}", ['delivery_qty' => 4])->assertOk();
        $this->putJson("/api/v1/supplier/customer-products/{$cp->id}", ['delivery_qty' => 4])->assertOk();

        $count = StockAlert::where('product_id', $product->id)->whereNull('resolved_at')->count();
        $this->assertEquals(1, $count);
    }

    /** Produit créé avec un seuil (stock 0) → alerte immédiate ; seuil retiré → résolue. */
    public function test_warehouse_alert_on_product_create_and_threshold_update(): void
    {
        Sanctum::actingAs($this->owner);

        $product = $this->postJson('/api/v1/supplier/products', [
            'name' => 'Farine', 'unit' => 'sac', 'stock_threshold' => 5,
        ])->assertStatus(201)->json();

        $alert = StockAlert::where('product_id', $product['id'])->whereNull('resolved_at')->first();
        $this->assertNotNull($alert);

        $this->putJson("/api/v1/supplier/products/{$product['id']}", [
            'stock_threshold' => null,
        ])->assertOk();

        $this->assertNotNull(StockAlert::find($alert->id)->fresh()->resolved_at);
    }

    /** Message sur commande : le client reçoit la notification ; refusé sur commande annulée. */
    public function test_order_message_notifies_client(): void
    {
        $clientUser = $this->customer->owner;
        $product = $this->makeProduct(stock: 2);
        $cp = CustomerProduct::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'initial_stock' => 0,
            'current_stock' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($clientUser);
        $orderId = $this->postJson('/api/v1/client/orders', [
            'items' => [['customer_product_id' => $cp->id, 'quantity' => 10]],
        ])->assertStatus(201)->json('order.id');

        Sanctum::actingAs($this->owner);
        $res = $this->postJson("/api/v1/supplier/orders/{$orderId}/message", [
            'message' => 'Il nous reste 2 pièces, arrivage prévu le 20/08. Voulez-vous attendre ?',
        ]);
        $res->assertOk()->assertJsonPath('message', 'Message envoyé au client.');
        $this->assertNotNull(
            $clientUser->notifications()->where('data->type', 'order_message')->first()
        );

        // Message vide → 422
        $this->postJson("/api/v1/supplier/orders/{$orderId}/message", ['message' => ''])
            ->assertStatus(422);

        // Commande refusée → 422
        $this->postJson("/api/v1/supplier/orders/{$orderId}/reject", ['reason' => 'Test'])->assertOk();
        $this->postJson("/api/v1/supplier/orders/{$orderId}/message", [
            'message' => 'Trop tard',
        ])->assertStatus(422);
    }

    /** Isolation multi-tenant : le fournisseur B ne voit ni ne modifie les produits de A. */
    public function test_warehouse_tenant_isolation(): void
    {
        $ownerB = User::create([
            'name' => 'Owner B', 'phone' => '0600000002', 'password' => 'secret', 'is_active' => true,
        ]);
        $ownerB->assignRole('supplier_owner');
        $supplierB = Supplier::create([
            'owner_user_id' => $ownerB->id,
            'name' => 'Fournisseur B',
            'subscription_status' => 'active',
        ]);

        $productA = $this->makeProduct(stock: 30);

        Sanctum::actingAs($ownerB);

        $res = $this->getJson('/api/v1/supplier/warehouse-stock');
        $res->assertOk();
        $this->assertCount(0, $res->json('products'));

        $this->postJson("/api/v1/supplier/warehouse-stock/{$productA->id}/receive", ['quantity' => 5])
            ->assertStatus(404);

        $this->assertEquals(30.0, floatval($productA->fresh()->stock_quantity));
    }

    /** Résumé exact et montants renvoyés en nombres (pas en strings). */
    public function test_warehouse_index_summary_and_float_format(): void
    {
        $this->makeProduct('A', threshold: 10, stock: 5);   // sous le seuil
        $this->makeProduct('B', threshold: 10, stock: 20);  // au-dessus
        $this->makeProduct('C', stock: -3);                 // négatif sans seuil
        Sanctum::actingAs($this->owner);

        $res = $this->getJson('/api/v1/supplier/warehouse-stock');
        $res->assertOk()
            ->assertJsonPath('summary.total_products', 3)
            ->assertJsonPath('summary.low_count', 1)
            ->assertJsonPath('summary.negative_count', 1);

        $products = $res->json('products');
        // Nombre natif JSON (int|float), jamais une string « 5.00 »
        $this->assertIsNotString($products[0]['stock_quantity']);
        $this->assertIsNumeric($products[0]['stock_quantity']);
    }
}
