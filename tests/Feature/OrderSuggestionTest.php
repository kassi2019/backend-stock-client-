<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;
    protected User $owner;
    protected User $clientUser;
    protected Customer $customer;
    protected CustomerProduct $cp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        // Figer « aujourd'hui » au 09/08/2026 comme dans l'exemple utilisateur
        Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00'));

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

        $product = Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Eau céleste',
            'unit' => 'bouteille',
        ]);

        $this->cp = CustomerProduct::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'product_id' => $product->id,
            'initial_stock' => 61,
            'current_stock' => 23, // 61 reçus − 38 vendus (exemple utilisateur)
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function deliveredOrder(string $date): Order
    {
        $o = Order::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'status' => 'delivered',
            'created_by_user_id' => $this->clientUser->id,
        ]);
        $o->created_at = Carbon::parse($date)->setHour(10)->setMinute(0)->setSecond(0);
        $o->save();

        return $o;
    }

    private function sale(string $date, float $qty): void
    {
        StockEntry::create([
            'customer_product_id' => $this->cp->id,
            'customer_id' => $this->customer->id,
            'supplier_id' => $this->supplier->id,
            'quantity' => $qty,
            'entry_type' => 'declared',
            'source' => 'client',
            'entered_by_user_id' => $this->clientUser->id,
            'entry_date' => $date,
        ]);
    }

    /** Exemple utilisateur : 1 commande livrée le 01/08, ventes du 02 au 08/08. */
    public function test_exemple_utilisateur_une_periode(): void
    {
        $this->deliveredOrder('2026-08-01');
        foreach ([
            '2026-08-02' => 5, '2026-08-03' => 4, '2026-08-04' => 7,
            '2026-08-05' => 3, '2026-08-06' => 0, '2026-08-07' => 10, '2026-08-08' => 9,
        ] as $day => $qty) {
            $this->sale($day, $qty);
        }

        Sanctum::actingAs($this->clientUser);
        $res = $this->getJson('/api/v1/client/order-suggestions')->assertOk();

        // C1 = 38 ; moyenne = 38 ; +20 % = 45,6 ; stock 23 → 45,6 − 23 = 22,6 → 23
        $res->assertJsonPath('suggestions.0.consumption_count', 1)
            ->assertJsonPath('suggestions.0.average_consumption', 38)
            ->assertJsonPath('suggestions.0.suggested_quantity', 23)
            ->assertJsonPath('suggestions.0.stock_insufficient', true);
    }

    /** Stock suffisant : la suggestion tombe à 0. */
    public function test_stock_suffisant_suggestion_zero(): void
    {
        $this->deliveredOrder('2026-08-01');
        $this->sale('2026-08-02', 5);
        $this->cp->update(['current_stock' => 500]);

        Sanctum::actingAs($this->clientUser);
        $res = $this->getJson('/api/v1/client/order-suggestions')->assertOk();

        $res->assertJsonPath('suggestions.0.suggested_quantity', 0)
            ->assertJsonPath('suggestions.0.stock_insufficient', false);
    }

    /** Plusieurs commandes livrées : périodes fermées + période en cours. */
    public function test_periodes_fermees_et_moyenne(): void
    {
        $this->deliveredOrder('2026-07-01'); // C3 : 18 unités
        $this->deliveredOrder('2026-07-15'); // C2 : 12 unités
        $this->deliveredOrder('2026-08-01'); // C1 : 38 unités (période en cours)
        $this->sale('2026-07-02', 18);
        $this->sale('2026-07-16', 12);
        $this->sale('2026-08-02', 38);

        Sanctum::actingAs($this->clientUser);
        $res = $this->getJson('/api/v1/client/order-suggestions')->assertOk();

        // Moyenne = (38 + 12 + 18) / 3 = 22,67 ; +20 % = 27,2 ; stock 23 → 4,2 → 5
        $res->assertJsonPath('suggestions.0.consumption_count', 3)
            ->assertJsonPath('suggestions.0.average_consumption', 22.67)
            ->assertJsonPath('suggestions.0.suggested_quantity', 5);
    }

    /** Fenêtre glissante : au plus 5 consommations, les plus récentes. */
    public function test_fenetre_glissante_max_5(): void
    {
        foreach (['2026-04-01', '2026-04-20', '2026-05-10', '2026-06-01', '2026-06-20', '2026-07-10', '2026-08-01'] as $d) {
            $this->deliveredOrder($d);
        }
        // Une seule vente récente : seule la dernière période compte
        $this->sale('2026-08-02', 10);

        Sanctum::actingAs($this->clientUser);
        $res = $this->getJson('/api/v1/client/order-suggestions')->assertOk();

        // 7 commandes → 6 périodes possibles → plafonnées à 5
        $res->assertJsonPath('suggestions.0.consumption_count', 5);
    }

    /** Les commandes annulées/refusées/en attente ne délimitent pas de période. */
    public function test_commandes_non_livrees_ignorees(): void
    {
        $this->deliveredOrder('2026-08-01');
        $this->sale('2026-08-02', 10);

        // Commandes non livrées (plus récentes) : ignorées
        Order::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'status' => 'cancelled',
            'created_by_user_id' => $this->clientUser->id,
        ]);
        Order::create([
            'supplier_id' => $this->supplier->id,
            'customer_id' => $this->customer->id,
            'status' => 'pending',
            'created_by_user_id' => $this->clientUser->id,
        ]);

        Sanctum::actingAs($this->clientUser);
        $res = $this->getJson('/api/v1/client/order-suggestions')->assertOk();

        // Une seule période (la commande livrée du 01/08 → aujourd'hui)
        $res->assertJsonPath('suggestions.0.consumption_count', 1)
            ->assertJsonPath('suggestions.0.average_consumption', 10);
    }

    /** Aucune commande livrée : aucune suggestion de quantité. */
    public function test_aucune_commande_livree(): void
    {
        Sanctum::actingAs($this->clientUser);
        $res = $this->getJson('/api/v1/client/order-suggestions')->assertOk();

        $res->assertJsonPath('suggestions.0.consumption_count', 0)
            ->assertJsonPath('suggestions.0.suggested_quantity', 0)
            ->assertJsonPath('suggestions.0.stock_insufficient', false);
    }
}
