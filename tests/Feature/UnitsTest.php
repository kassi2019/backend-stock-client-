<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitsTest extends TestCase
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
        ]);

        Sanctum::actingAs($this->owner);
    }

    /** Lister : renvoie les unités du fournisseur, triées par nom. */
    public function test_index_lists_supplier_units(): void
    {
        Unit::create(['supplier_id' => $this->supplier->id, 'name' => 'casier']);
        Unit::create(['supplier_id' => $this->supplier->id, 'name' => 'pièce']);

        $res = $this->getJson('/api/v1/supplier/units');

        $res->assertStatus(200)
            ->assertJsonPath('units.0.name', 'casier')
            ->assertJsonPath('units.1.name', 'pièce');
    }

    /** Ajout : crée l'unité (espaces retirés). */
    public function test_store_adds_unit(): void
    {
        $res = $this->postJson('/api/v1/supplier/units', ['name' => '  casier  ']);

        $res->assertStatus(201)->assertJsonPath('name', 'casier');
        $this->assertDatabaseHas('units', ['supplier_id' => $this->supplier->id, 'name' => 'casier']);
    }

    /** Doublon : renvoie l'unité existante sans en créer une deuxième. */
    public function test_store_duplicate_returns_existing(): void
    {
        Unit::create(['supplier_id' => $this->supplier->id, 'name' => 'casier']);

        $res = $this->postJson('/api/v1/supplier/units', ['name' => 'casier']);

        $res->assertStatus(201);
        $this->assertSame(1, Unit::where('supplier_id', $this->supplier->id)->count());
    }

    /** Suppression refusée si des produits utilisent encore l'unité. */
    public function test_destroy_blocked_when_used_by_products(): void
    {
        $unit = Unit::create(['supplier_id' => $this->supplier->id, 'name' => 'casier']);
        Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Fruits',
            'unit' => 'casier',
        ]);

        $res = $this->deleteJson("/api/v1/supplier/units/{$unit->id}");

        $res->assertStatus(422);
        $this->assertDatabaseHas('units', ['id' => $unit->id]);
    }

    /** Suppression d'une unité non utilisée. */
    public function test_destroy_unused_unit(): void
    {
        $unit = Unit::create(['supplier_id' => $this->supplier->id, 'name' => 'casier']);

        $res = $this->deleteJson("/api/v1/supplier/units/{$unit->id}");

        $res->assertStatus(200);
        $this->assertDatabaseMissing('units', ['id' => $unit->id]);
    }

    /** Cloisonnement : un fournisseur ne peut pas supprimer l'unité d'un autre. */
    public function test_destroy_tenant_isolation(): void
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
        $otherUnit = Unit::create(['supplier_id' => $otherSupplier->id, 'name' => 'casier']);

        $res = $this->deleteJson("/api/v1/supplier/units/{$otherUnit->id}");

        $res->assertStatus(404);
        $this->assertDatabaseHas('units', ['id' => $otherUnit->id]);
    }
}
