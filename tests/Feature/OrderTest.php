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

class OrderTest extends TestCase
{
    use RefreshDatabase;

    /** Client utilisé par makeProduct() pour rattacher les customer_products. */
    protected Customer $clientCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    // --- Helpers de mise en place (style repo, sans factories) ---

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

    private function makeSupplier(User $owner, string $name = 'Fournisseur A'): Supplier
    {
        return Supplier::create([
            'owner_user_id' => $owner->id,
            'name' => $name,
            'subscription_status' => 'active',
        ]);
    }

    private function makeClient(Supplier $supplier, string $name = 'Client A', string $phone = '0611111111'): array
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

    private function makeProduct(Supplier $supplier, string $name = 'Œufs', string $unit = 'pièce'): array
    {
        $product = Product::create([
            'supplier_id' => $supplier->id,
            'name' => $name,
            'unit' => $unit,
        ]);
        $cp = CustomerProduct::create([
            'supplier_id' => $supplier->id,
            'customer_id' => $this->clientCustomer->id,
            'product_id' => $product->id,
            'initial_stock' => 100,
            'current_stock' => 80,
            'is_active' => true,
        ]);
        return [$product, $cp];
    }

    private function createPendingOrder(User $clientUser, int $cpId, float $qty = 10, ?string $note = null): int
    {
        Sanctum::actingAs($clientUser);
        $res = $this->postJson('/api/v1/client/orders', [
            'items' => [['customer_product_id' => $cpId, 'quantity' => $qty]],
            'note' => $note,
        ]);
        $res->assertStatus(201);
        return $res->json('order.id');
    }

    // --- Tests ---

    /** Cycle complet : création → notifs équipe → acceptation → réception → stock à jour. */
    public function test_full_order_lifecycle_updates_stock_and_notifies(): void
    {
        $owner = $this->makeUser('Owner A', '0600000001', 'supplier_owner');
        $supplier = $this->makeSupplier($owner);
        $staff = $this->makeUser('Staff A', '0600000002', 'supplier_staff');
        $supplier->users()->attach($staff->id, ['role' => 'manager', 'is_active' => true]);
        [$clientUser, $customer] = $this->makeClient($supplier);
        $this->clientCustomer = $customer;
        [, $cp] = $this->makeProduct($supplier);

        // 1. Création par le client
        $orderId = $this->createPendingOrder($clientUser, $cp->id, 10, 'Livrer tôt svp');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'supplier_id' => $supplier->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
            'note' => 'Livrer tôt svp',
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'customer_product_id' => $cp->id,
            'product_name' => 'Œufs',
            'quantity' => 10,
        ]);

        // Notifications : owner + staff (pas de doublon)
        $this->assertNotNull($owner->notifications()->where('data->type', 'order_created')->first());
        $this->assertNotNull($staff->notifications()->where('data->type', 'order_created')->first());

        // 2. Acceptation par le fournisseur
        Sanctum::actingAs($owner);
        $res = $this->postJson("/api/v1/supplier/orders/{$orderId}/accept");
        $res->assertOk()->assertJsonPath('order.status', 'accepted');
        $this->assertNotNull($clientUser->notifications()->where('data->type', 'order_accepted')->first());

        // 3. Réception confirmée par le client → stock automatique
        Sanctum::actingAs($clientUser);
        $res = $this->postJson("/api/v1/client/orders/{$orderId}/confirm-delivery");
        $res->assertOk()->assertJsonPath('order.status', 'delivered');

        $cpFresh = $cp->fresh();
        $this->assertEquals(110.0, floatval($cpFresh->initial_stock));
        $this->assertEquals(90.0, floatval($cpFresh->current_stock));
        $this->assertNotNull($cpFresh->last_entry_at);

        $this->assertDatabaseHas('stock_entries', [
            'customer_product_id' => $cp->id,
            'quantity' => 10,
            'entry_type' => 'delivery',
            'source' => 'supplier',
            'note' => "Commande #{$orderId}",
            'entered_by_user_id' => $clientUser->id,
        ]);

        $this->assertNotNull($owner->notifications()->where('data->type', 'order_delivered')->first());
    }

    /** Refus : motif obligatoire, notification au client. */
    public function test_reject_requires_reason_and_notifies_client(): void
    {
        $owner = $this->makeUser('Owner A', '0600000001', 'supplier_owner');
        $supplier = $this->makeSupplier($owner);
        [$clientUser, $customer] = $this->makeClient($supplier);
        $this->clientCustomer = $customer;
        [, $cp] = $this->makeProduct($supplier);
        $orderId = $this->createPendingOrder($clientUser, $cp->id);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/reject", [])
            ->assertStatus(422);

        $res = $this->postJson("/api/v1/supplier/orders/{$orderId}/reject", ['reason' => 'Rupture de stock']);
        $res->assertOk()->assertJsonPath('order.status', 'rejected')
            ->assertJsonPath('order.rejection_reason', 'Rupture de stock');

        $this->assertNotNull($clientUser->notifications()->where('data->type', 'order_rejected')->first());
    }

    /** Annulation par le client tant que pending — sans impact sur le stock. */
    public function test_client_can_cancel_pending_order_without_stock_change(): void
    {
        $owner = $this->makeUser('Owner A', '0600000001', 'supplier_owner');
        $supplier = $this->makeSupplier($owner);
        [$clientUser, $customer] = $this->makeClient($supplier);
        $this->clientCustomer = $customer;
        [, $cp] = $this->makeProduct($supplier);
        $orderId = $this->createPendingOrder($clientUser, $cp->id);

        Sanctum::actingAs($clientUser);
        $this->postJson("/api/v1/client/orders/{$orderId}/cancel")
            ->assertOk()->assertJsonPath('order.status', 'cancelled');

        $this->assertEquals(80.0, floatval($cp->fresh()->current_stock));
    }

    /** Transitions interdites. */
    public function test_forbidden_transitions(): void
    {
        $owner = $this->makeUser('Owner A', '0600000001', 'supplier_owner');
        $supplier = $this->makeSupplier($owner);
        [$clientUser, $customer] = $this->makeClient($supplier);
        $this->clientCustomer = $customer;
        [, $cp] = $this->makeProduct($supplier);
        $orderId = $this->createPendingOrder($clientUser, $cp->id);

        // Un client ne peut pas toucher aux routes fournisseur
        Sanctum::actingAs($clientUser);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/accept")->assertStatus(403);

        // Confirmation de réception impossible tant que pending
        $this->postJson("/api/v1/client/orders/{$orderId}/confirm-delivery")
            ->assertStatus(422)
            ->assertJsonPath('message', "Cette commande n'est pas en attente de livraison.");

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/accept")->assertOk();
        // Double acceptation refusée
        $this->postJson("/api/v1/supplier/orders/{$orderId}/accept")
            ->assertStatus(422);

        // Annulation impossible une fois acceptée
        Sanctum::actingAs($clientUser);
        $this->postJson("/api/v1/client/orders/{$orderId}/cancel")
            ->assertStatus(422);

        // Double confirmation refusée
        $this->postJson("/api/v1/client/orders/{$orderId}/confirm-delivery")->assertOk();
        $this->postJson("/api/v1/client/orders/{$orderId}/confirm-delivery")
            ->assertStatus(422);
    }

    /** Isolation : un fournisseur B ne voit pas les commandes de A ; un client A pas celles de B. */
    public function test_tenant_isolation(): void
    {
        $ownerA = $this->makeUser('Owner A', '0600000001', 'supplier_owner');
        $supplierA = $this->makeSupplier($ownerA, 'Fournisseur A');
        $ownerB = $this->makeUser('Owner B', '0600000002', 'supplier_owner');
        $supplierB = $this->makeSupplier($ownerB, 'Fournisseur B');

        [$clientA, $customerA] = $this->makeClient($supplierA, 'Client A', '0611111111');
        [$clientB] = $this->makeClient($supplierB, 'Client B', '0611111112');

        $this->clientCustomer = $customerA;
        [, $cpA] = $this->makeProduct($supplierA, 'Œufs');
        $orderId = $this->createPendingOrder($clientA, $cpA->id);

        // Fournisseur B → 404 sur la commande de A
        Sanctum::actingAs($ownerB);
        $this->getJson("/api/v1/supplier/orders/{$orderId}")->assertStatus(404);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/accept")->assertStatus(404);

        // Client A voit sa commande, client B → 404
        Sanctum::actingAs($clientA);
        $this->getJson("/api/v1/client/orders/{$orderId}")->assertOk();

        Sanctum::actingAs($clientB);
        $this->getJson("/api/v1/client/orders/{$orderId}")->assertStatus(404);
    }

    /** Livraison bloquée si le produit du client a été détaché entre-temps. */
    public function test_delivery_blocked_when_product_detached(): void
    {
        $owner = $this->makeUser('Owner A', '0600000001', 'supplier_owner');
        $supplier = $this->makeSupplier($owner);
        [$clientUser, $customer] = $this->makeClient($supplier);
        $this->clientCustomer = $customer;
        [, $cp] = $this->makeProduct($supplier);
        $orderId = $this->createPendingOrder($clientUser, $cp->id);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/accept")->assertOk();

        // Détachement du produit (suppression dure, comme detachProduct)
        $cp->delete();

        Sanctum::actingAs($clientUser);
        $this->postJson("/api/v1/client/orders/{$orderId}/confirm-delivery")
            ->assertStatus(422);

        $this->assertSame('accepted', \App\Models\Order::find($orderId)->status);
        $this->assertDatabaseMissing('stock_entries', ['note' => "Commande #{$orderId}"]);
    }

    /** Validations de création. */
    public function test_create_validation(): void
    {
        $owner = $this->makeUser('Owner A', '0600000001', 'supplier_owner');
        $supplier = $this->makeSupplier($owner);
        [$clientUser, $customer] = $this->makeClient($supplier);
        $this->clientCustomer = $customer;
        [, $cp] = $this->makeProduct($supplier);

        // Client d'un autre fournisseur : son CP est hors du catalogue du client
        $otherOwner = $this->makeUser('Owner B', '0600000002', 'supplier_owner');
        $otherSupplier = $this->makeSupplier($otherOwner, 'Fournisseur B');
        [, $otherCustomer] = $this->makeClient($otherSupplier, 'Client B', '0611111112');
        $otherProduct = Product::create(['supplier_id' => $otherSupplier->id, 'name' => 'Farine', 'unit' => 'kg']);
        $otherCp = CustomerProduct::create([
            'supplier_id' => $otherSupplier->id,
            'customer_id' => $otherCustomer->id,
            'product_id' => $otherProduct->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($clientUser);

        $this->postJson('/api/v1/client/orders', [])->assertStatus(422);
        $this->postJson('/api/v1/client/orders', [
            'items' => [['customer_product_id' => $cp->id, 'quantity' => 0]],
        ])->assertStatus(422);
        $this->postJson('/api/v1/client/orders', [
            'items' => [
                ['customer_product_id' => $cp->id, 'quantity' => 1],
                ['customer_product_id' => $cp->id, 'quantity' => 2],
            ],
        ])->assertStatus(422);
        $this->postJson('/api/v1/client/orders', [
            'items' => [['customer_product_id' => $otherCp->id, 'quantity' => 1]],
        ])->assertStatus(404);
    }

    /** Prix figés à la commande : sous-totaux, total, insensibles aux changements de prix ultérieurs. */
    public function test_order_snapshots_prices_and_computes_totals(): void
    {
        $owner = $this->makeUser('Owner A', '0600000001', 'supplier_owner');
        $supplier = $this->makeSupplier($owner);
        [$clientUser, $customer] = $this->makeClient($supplier);
        $this->clientCustomer = $customer;

        [$pricedProduct, $pricedCp] = $this->makeProduct($supplier, 'Œufs', 'pièce');
        $pricedProduct->update(['price' => 1000]);
        [, $unpricedCp] = $this->makeProduct($supplier, 'Farine', 'kg');

        Sanctum::actingAs($clientUser);
        $res = $this->postJson('/api/v1/client/orders', [
            'items' => [
                ['customer_product_id' => $pricedCp->id, 'quantity' => 10],
                ['customer_product_id' => $unpricedCp->id, 'quantity' => 5],
            ],
        ])->assertStatus(201);

        // Total = 1000 × 10, produit sans prix exclu mais compté à part
        $this->assertEquals(10000.0, $res->json('order.total'));
        $this->assertEquals(1, $res->json('order.unpriced_items_count'));
        $this->assertEquals(1000.0, $res->json('order.items.0.unit_price'));
        $this->assertEquals(10000.0, $res->json('order.items.0.line_total'));
        $this->assertNull($res->json('order.items.1.unit_price'));
        $this->assertNull($res->json('order.items.1.line_total'));

        $this->assertDatabaseHas('order_items', [
            'customer_product_id' => $pricedCp->id,
            'unit_price' => 1000,
        ]);

        // Changement de prix après la commande : le total reste figé
        $orderId = $res->json('order.id');
        $pricedProduct->update(['price' => 2000]);

        Sanctum::actingAs($clientUser);
        $clientView = $this->getJson("/api/v1/client/orders/{$orderId}")->assertOk();
        $this->assertEquals(10000.0, $clientView->json('order.total'));
        $this->assertEquals(1000.0, $clientView->json('order.items.0.unit_price'));

        // Le fournisseur voit le même total
        Sanctum::actingAs($owner);
        $supplierView = $this->getJson("/api/v1/supplier/orders/{$orderId}")->assertOk();
        $this->assertEquals(10000.0, $supplierView->json('order.total'));
        $this->assertEquals(1, $supplierView->json('order.unpriced_items_count'));
    }

    /** pending-count cloisonné par fournisseur. */
    public function test_pending_count_is_tenant_scoped(): void
    {
        $ownerA = $this->makeUser('Owner A', '0600000001', 'supplier_owner');
        $supplierA = $this->makeSupplier($ownerA, 'Fournisseur A');
        $ownerB = $this->makeUser('Owner B', '0600000002', 'supplier_owner');
        $supplierB = $this->makeSupplier($ownerB, 'Fournisseur B');

        [$clientA, $customerA] = $this->makeClient($supplierA, 'Client A', '0611111111');
        $this->clientCustomer = $customerA;
        [, $cpA] = $this->makeProduct($supplierA);
        $this->createPendingOrder($clientA, $cpA->id);

        Sanctum::actingAs($ownerA);
        $this->getJson('/api/v1/supplier/orders/pending-count')
            ->assertOk()->assertJsonPath('pending_count', 1);

        Sanctum::actingAs($ownerB);
        $this->getJson('/api/v1/supplier/orders/pending-count')
            ->assertOk()->assertJsonPath('pending_count', 0);
    }

    /** Fournisseur suspendu : ne peut pas accepter, mais le client peut commander. */
    public function test_suspended_supplier_cannot_accept_but_client_can_order(): void
    {
        $owner = $this->makeUser('Owner A', '0600000001', 'supplier_owner');
        $supplier = $this->makeSupplier($owner);
        [$clientUser, $customer] = $this->makeClient($supplier);
        $this->clientCustomer = $customer;
        [, $cp] = $this->makeProduct($supplier);

        $supplier->update(['subscription_status' => 'suspended']);

        // Le client peut toujours commander (cohérent avec les flux client existants)
        Sanctum::actingAs($clientUser);
        $orderId = $this->createPendingOrder($clientUser, $cp->id);

        // Le fournisseur suspendu ne peut pas accepter
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/accept")
            ->assertStatus(403)
            ->assertJsonPath('reason', 'subscription_expired');
    }
}
