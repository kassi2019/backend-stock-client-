<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Supplier $supplier;
    protected Customer $customer;
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

        $product = Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Eau céleste',
            'unit' => 'bouteille',
            'price' => 500,
        ]);

        $this->cp = CustomerProduct::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'initial_stock' => 100,
            'current_stock' => 60,
            'is_active' => true,
        ]);
    }

    private function delivery(float $qty, ?float $unitPrice): void
    {
        StockEntry::create([
            'customer_product_id' => $this->cp->id,
            'customer_id' => $this->customer->id,
            'supplier_id' => $this->supplier->id,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'entry_type' => 'delivery',
            'source' => 'supplier',
            'entered_by_user_id' => $this->owner->id,
            'entry_date' => now()->toDateString(),
        ]);
    }

    /** Le crédit = valeur des livraisons (prix figé à la livraison). */
    public function test_credit_computed_from_deliveries(): void
    {
        $this->delivery(10, 500); // 5 000
        $this->delivery(4, 600);  // 2 400 (prix figé différent du prix actuel)

        Sanctum::actingAs($this->owner);
        $res = $this->getJson('/api/v1/supplier/finances')->assertOk();

        $res->assertJsonPath('total_credit', 7400)
            ->assertJsonPath('customers.0.customer_name', 'Client A')
            ->assertJsonPath('customers.0.delivered_total', 7400)
            ->assertJsonPath('customers.0.paid_total', 0)
            ->assertJsonPath('customers.0.credit', 7400);
    }

    /** Livraison sans prix figé → prix actuel du produit. */
    public function test_delivery_without_unit_price_uses_product_price(): void
    {
        $this->delivery(3, null); // 3 × 500 (prix produit actuel)

        Sanctum::actingAs($this->owner);
        $res = $this->getJson('/api/v1/supplier/finances')->assertOk();

        $res->assertJsonPath('total_credit', 1500);
    }

    /** Les encaissements réduisent le solde et le crédit total. */
    public function test_payments_reduce_balance(): void
    {
        $this->delivery(10, 500); // 5 000

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/payments", [
            'amount' => 2000,
            'note' => 'Acompte',
        ])->assertStatus(201);

        $res = $this->getJson('/api/v1/supplier/finances')->assertOk();
        $res->assertJsonPath('total_credit', 3000)
            ->assertJsonPath('customers.0.paid_total', 2000)
            ->assertJsonPath('customers.0.balance', 3000)
            ->assertJsonPath('customers.0.credit', 3000);

        // Historique des paiements
        $hist = $this->getJson("/api/v1/supplier/customers/{$this->customer->id}/payments")->assertOk();
        $hist->assertJsonPath('payments.0.amount', 2000)
            ->assertJsonPath('payments.0.note', 'Acompte');
    }

    /** Montant invalide refusé. */
    public function test_payment_amount_must_be_positive(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/payments", [
            'amount' => 0,
        ])->assertStatus(422);
    }

    /** Le tableau de bord fournisseur expose le crédit total. */
    public function test_dashboard_exposes_total_credit(): void
    {
        $this->delivery(10, 500);

        Sanctum::actingAs($this->owner);
        $res = $this->getJson('/api/v1/supplier/dashboard')->assertOk();

        $res->assertJsonPath('summary.total_credit', 5000);
    }

    /** Le rattachement initial (batch) crée une livraison valorisée. */
    public function test_attach_batch_creates_valued_delivery(): void
    {
        // Nouveau produit (pas encore rattaché) pour tester le rattachement initial
        $newProduct = Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Riz 25kg',
            'unit' => 'sac',
            'price' => 500,
            'stock_quantity' => 100,
        ]);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/products/batch", [
            'products' => [
                ['product_id' => $newProduct->id, 'initial_stock' => 20],
            ],
        ])->assertStatus(201);

        $res = $this->getJson('/api/v1/supplier/finances')->assertOk();
        $res->assertJsonPath('customers.0.delivered_total', 10000); // 20 × 500
    }
}
