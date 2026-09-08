<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductBulkTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Supplier $supplier;

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
            'plan_id' => Plan::create([
                'name' => 'Test', 'slug' => 'test-plan', 'monthly_price' => 0,
                'max_products' => 10, 'max_customers' => 10, 'max_staff' => 2, 'trial_days' => 0,
            ])->id,
        ]);
    }

    /** Les lignes valides sont créées, les erreurs sont listées sans bloquer. */
    public function test_bulk_import_creates_valid_rows_and_reports_errors(): void
    {
        Sanctum::actingAs($this->owner);

        $res = $this->postJson('/api/v1/supplier/products/bulk', [
            'products' => [
                ['name' => 'Eau céleste', 'unit' => 'bouteille', 'price' => 500],
                ['name' => '', 'unit' => 'pièce'],                       // nom manquant → erreur
                ['name' => 'Riz 25kg', 'unit' => 'sac', 'category' => 'Céréales', 'pack_size' => 6],
            ],
        ])->assertStatus(201);

        $res->assertJsonPath('imported', 2);
        $this->assertCount(1, $res->json('errors'));
        $this->assertEquals(2, Product::count());
        $this->assertNotNull(Product::where('name', 'Eau céleste')->first());
    }

    /** Un doublon dans la base est ignoré avec une erreur. */
    public function test_bulk_import_skips_duplicates(): void
    {
        Product::create(['supplier_id' => $this->supplier->id, 'name' => 'Eau céleste', 'unit' => 'bouteille']);

        Sanctum::actingAs($this->owner);
        $res = $this->postJson('/api/v1/supplier/products/bulk', [
            'products' => [
                ['name' => 'Eau céleste', 'unit' => 'bouteille'],
                ['name' => 'Riz', 'unit' => 'sac'],
            ],
        ])->assertStatus(201);

        $res->assertJsonPath('imported', 1);
        $this->assertStringContainsString('existe déjà', $res->json('errors.0.message'));
    }

    /** Le quota du forfait bloque l'import au-delà de la limite. */
    public function test_bulk_import_respects_quota(): void
    {
        // Déjà 9 produits sur 10 possibles
        for ($i = 1; $i <= 9; $i++) {
            Product::create(['supplier_id' => $this->supplier->id, 'name' => "Produit {$i}", 'unit' => 'pièce']);
        }

        Sanctum::actingAs($this->owner);
        $res = $this->postJson('/api/v1/supplier/products/bulk', [
            'products' => [
                ['name' => 'Produit A', 'unit' => 'pièce'],
                ['name' => 'Produit B', 'unit' => 'pièce'],
            ],
        ])->assertStatus(201);

        $res->assertJsonPath('imported', 1);
        $this->assertStringContainsString('Quota atteint', $res->json('errors.0.message'));
    }
}
