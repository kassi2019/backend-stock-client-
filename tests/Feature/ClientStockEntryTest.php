<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientStockEntryTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;
    protected User $clientUser;
    protected Customer $customer;
    protected CustomerProduct $cp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $owner = User::create([
            'name' => 'Owner A', 'phone' => '0600000001', 'password' => 'secret', 'is_active' => true,
        ]);
        $owner->assignRole('supplier_owner');

        $this->supplier = Supplier::create([
            'owner_user_id' => $owner->id,
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

        $product = Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Œufs',
            'unit' => 'pièce',
        ]);

        $this->cp = CustomerProduct::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'initial_stock' => 100,
            'current_stock' => 80,
            'is_active' => true,
        ]);
    }

    /** Le client corrige une vente : la quantité est ré-ajoutée au stock. */
    public function test_client_can_correct_own_sale_entry(): void
    {
        Sanctum::actingAs($this->clientUser);
        $this->postJson('/api/v1/client/stock-entries', [
            'customer_product_id' => $this->cp->id,
            'sold_quantity' => 5,
        ])->assertStatus(201);

        $entry = StockEntry::first();
        $this->assertEquals(75.0, floatval($this->cp->fresh()->current_stock));

        $res = $this->deleteJson("/api/v1/client/stock-entries/{$entry->id}");
        $res->assertOk()
            ->assertJsonPath('quantity', 5)
            ->assertJsonPath('product_name', 'Œufs');

        $this->assertDatabaseMissing('stock_entries', ['id' => $entry->id]);
        $this->assertEquals(80.0, floatval($this->cp->fresh()->current_stock));
    }

    /** Le stock restauré ne dépasse jamais le total reçu. */
    public function test_correction_never_exceeds_initial_stock(): void
    {
        Sanctum::actingAs($this->clientUser);
        // Vente supérieure au stock (cas limite) : le stock tombe à 0
        $this->postJson('/api/v1/client/stock-entries', [
            'customer_product_id' => $this->cp->id,
            'sold_quantity' => 250,
        ])->assertStatus(201);

        $entry = StockEntry::first();
        $this->assertEquals(0.0, floatval($this->cp->fresh()->current_stock));

        $this->deleteJson("/api/v1/client/stock-entries/{$entry->id}")->assertOk();

        // Jamais plus que le total reçu (100)
        $this->assertLessThanOrEqual(100.0, floatval($this->cp->fresh()->current_stock));
    }

    /** Une livraison du fournisseur ne peut pas être corrigée par le client. */
    public function test_cannot_correct_delivery_entry(): void
    {
        $delivery = StockEntry::create([
            'customer_product_id' => $this->cp->id,
            'customer_id' => $this->customer->id,
            'supplier_id' => $this->supplier->id,
            'quantity' => 10,
            'entry_type' => 'delivery',
            'source' => 'supplier',
            'entry_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($this->clientUser);
        $this->deleteJson("/api/v1/client/stock-entries/{$delivery->id}")
            ->assertStatus(403);
    }

    /** Un client ne peut pas corriger la saisie d'un autre client. */
    public function test_cannot_correct_other_customer_entry(): void
    {
        Sanctum::actingAs($this->clientUser);
        $this->postJson('/api/v1/client/stock-entries', [
            'customer_product_id' => $this->cp->id,
            'sold_quantity' => 3,
        ])->assertStatus(201);
        $entry = StockEntry::first();

        // Second client du même fournisseur
        $otherUser = User::create([
            'name' => 'Client B', 'phone' => '0611111112', 'password' => 'secret', 'is_active' => true,
        ]);
        $otherUser->assignRole('client');
        Customer::create([
            'supplier_id' => $this->supplier->id,
            'owner_user_id' => $otherUser->id,
            'name' => 'Client B',
            'is_active' => true,
        ]);

        Sanctum::actingAs($otherUser);
        $this->deleteJson("/api/v1/client/stock-entries/{$entry->id}")
            ->assertStatus(404);
    }

    /** L'historique ne montre que les saisies du client connecté. */
    public function test_history_is_customer_scoped(): void
    {
        Sanctum::actingAs($this->clientUser);
        $this->postJson('/api/v1/client/stock-entries', [
            'customer_product_id' => $this->cp->id,
            'sold_quantity' => 4,
        ])->assertStatus(201);

        $res = $this->getJson('/api/v1/client/stock-entries');
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));

        // L'entrée expose le produit pour l'affichage
        $entry = $res->json('data.0');
        $this->assertArrayHasKey('customer_product', $entry);
        $this->assertSame('Œufs', $entry['customer_product']['product']['name']);
    }
}
