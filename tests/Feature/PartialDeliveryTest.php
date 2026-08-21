<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Supplier $supplier;
    protected User $clientUser;
    protected Customer $customer;
    protected Product $product;
    protected CustomerProduct $cp;

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

        $this->clientUser = User::create([
            'name' => 'Client A', 'phone' => '0611111111', 'password' => 'secret', 'is_active' => true,
        ]);
        $this->clientUser->assignRole('client');

        $this->customer = Customer::create([
            'supplier_id' => $this->supplier->id,
            'owner_user_id' => $this->clientUser->id,
            'name' => 'Client A',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Œufs',
            'unit' => 'pièce',
            'stock_quantity' => 5,
        ]);

        $this->cp = CustomerProduct::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'initial_stock' => 0,
            'current_stock' => 0,
            'is_active' => true,
        ]);
    }

    /** Crée une commande de 10 et la fait accepter par le fournisseur. */
    private function acceptedOrder(float $qty = 10): int
    {
        Sanctum::actingAs($this->clientUser);
        $orderId = $this->postJson('/api/v1/client/orders', [
            'items' => [['customer_product_id' => $this->cp->id, 'quantity' => $qty]],
        ])->assertStatus(201)->json('order.id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/accept")->assertOk();

        return $orderId;
    }

    private function orderItemId(int $orderId): int
    {
        return Order::find($orderId)->items()->first()->id;
    }

    /** Livraison partielle : stock client +5, entrepôt −5, reste 5, commande acceptée. */
    public function test_partial_delivery_updates_stocks_and_tracks_remaining(): void
    {
        $orderId = $this->acceptedOrder();
        $itemId = $this->orderItemId($orderId);

        Sanctum::actingAs($this->owner);
        $res = $this->postJson("/api/v1/supplier/orders/{$orderId}/deliver", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 5]],
        ]);

        $res->assertOk()
            ->assertJsonPath('order.status', 'accepted')
            ->assertJsonPath('order.items.0.delivered_quantity', 5)
            ->assertJsonPath('order.items.0.remaining', 5);

        $this->assertEquals(5.0, floatval($this->cp->fresh()->current_stock));
        $this->assertEquals(0.0, floatval($this->product->fresh()->stock_quantity));

        $this->assertDatabaseHas('stock_entries', [
            'customer_product_id' => $this->cp->id,
            'quantity' => 5.00,
            'note' => "Commande #{$orderId} (livraison partielle)",
        ]);
        $this->assertDatabaseHas('warehouse_stock_entries', [
            'product_id' => $this->product->id,
            'quantity' => -5.00,
            'entry_type' => 'delivery',
        ]);

        // Le client est notifié de la livraison partielle
        $this->assertNotNull(
            $this->clientUser->notifications()->where('data->type', 'order_partial_delivery')->first()
        );
    }

    /** Livrer le reste clôt la commande (delivered) et notifie le client. */
    public function test_deliver_remaining_completes_order(): void
    {
        $orderId = $this->acceptedOrder();
        $itemId = $this->orderItemId($orderId);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/deliver", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 5]],
        ])->assertOk();

        $res = $this->postJson("/api/v1/supplier/orders/{$orderId}/deliver", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 5]],
        ]);

        $res->assertOk()
            ->assertJsonPath('order.status', 'delivered')
            ->assertJsonPath('order.items.0.remaining', 0);

        $this->assertEquals(10.0, floatval($this->cp->fresh()->current_stock));
        $this->assertEquals(-5.0, floatval($this->product->fresh()->stock_quantity));

        $this->assertNotNull(
            $this->clientUser->notifications()->where('data->type', 'order_delivered')->first()
        );
    }

    /** Livrer plus que le reste → 422. */
    public function test_over_delivery_is_rejected(): void
    {
        $orderId = $this->acceptedOrder();
        $itemId = $this->orderItemId($orderId);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/deliver", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 11]],
        ])->assertStatus(422);
    }

    /** Impossible de livrer une commande non acceptée. */
    public function test_deliver_requires_accepted_order(): void
    {
        Sanctum::actingAs($this->clientUser);
        $orderId = $this->postJson('/api/v1/client/orders', [
            'items' => [['customer_product_id' => $this->cp->id, 'quantity' => 10]],
        ])->assertStatus(201)->json('order.id');
        $itemId = $this->orderItemId($orderId);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/deliver", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 5]],
        ])->assertStatus(422);
    }

    /** La fenêtre des restes liste la commande, puis se vide après livraison complète. */
    public function test_pending_deliveries_list(): void
    {
        $orderId = $this->acceptedOrder();
        $itemId = $this->orderItemId($orderId);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/deliver", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 5]],
        ])->assertOk();

        $res = $this->getJson('/api/v1/supplier/orders/pending-deliveries');
        $res->assertOk()->assertJsonPath('total', 1);
        $line = $res->json('pending.0');
        $this->assertEquals($orderId, $line['order_id']);
        $this->assertEquals('Client A', $line['customer_name']);
        $this->assertEquals(5.0, $line['items'][0]['remaining']);

        // Livraison du reste → la liste se vide
        $this->postJson("/api/v1/supplier/orders/{$orderId}/deliver", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 5]],
        ])->assertOk();

        $this->getJson('/api/v1/supplier/orders/pending-deliveries')
            ->assertOk()->assertJsonPath('total', 0);
    }

    /** Après une livraison partielle, le client ne confirme que le reste. */
    public function test_confirm_delivery_adds_only_remaining_after_partial(): void
    {
        $orderId = $this->acceptedOrder();
        $itemId = $this->orderItemId($orderId);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/deliver", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 5]],
        ])->assertOk();

        Sanctum::actingAs($this->clientUser);
        $res = $this->postJson("/api/v1/client/orders/{$orderId}/confirm-delivery");
        $res->assertOk()->assertJsonPath('order.status', 'delivered');

        $this->assertEquals(10.0, floatval($this->cp->fresh()->current_stock));
        // L'entrepôt passe à -5 : la déduction n'est jamais bloquante
        $this->assertEquals(-5.0, floatval($this->product->fresh()->stock_quantity));
    }

    /** Isolation multi-tenant sur la livraison et la liste des restes. */
    public function test_partial_delivery_tenant_isolation(): void
    {
        $orderId = $this->acceptedOrder();
        $itemId = $this->orderItemId($orderId);

        $ownerB = User::create([
            'name' => 'Owner B', 'phone' => '0600000002', 'password' => 'secret', 'is_active' => true,
        ]);
        $ownerB->assignRole('supplier_owner');
        Supplier::create([
            'owner_user_id' => $ownerB->id,
            'name' => 'Fournisseur B',
            'subscription_status' => 'active',
        ]);

        Sanctum::actingAs($ownerB);
        $this->postJson("/api/v1/supplier/orders/{$orderId}/deliver", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 5]],
        ])->assertStatus(404);

        $this->getJson('/api/v1/supplier/orders/pending-deliveries')
            ->assertOk()->assertJsonPath('total', 0);
    }
}
