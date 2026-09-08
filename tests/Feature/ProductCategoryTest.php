<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCategoryTest extends TestCase
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
    }

    /** Création et listage des catégories du fournisseur. */
    public function test_supplier_manages_own_categories(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/supplier/categories', ['name' => 'Boissons'])
            ->assertStatus(201);
        $this->postJson('/api/v1/supplier/categories', ['name' => 'Céréales'])
            ->assertStatus(201);

        $res = $this->getJson('/api/v1/supplier/categories')->assertOk();
        $this->assertEquals(['Boissons', 'Céréales'], $res->json('categories.*.name'));
    }

    /** Deux fournisseurs ont chacun leurs catégories (isolation). */
    public function test_categories_are_isolated_between_suppliers(): void
    {
        $otherOwner = User::create([
            'name' => 'Owner B', 'phone' => '0600000002', 'password' => 'secret', 'is_active' => true,
        ]);
        $otherOwner->assignRole('supplier_owner');
        $other = Supplier::create([
            'owner_user_id' => $otherOwner->id,
            'name' => 'Fournisseur B',
            'subscription_status' => 'active',
        ]);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/supplier/categories', ['name' => 'Boissons'])->assertStatus(201);

        Sanctum::actingAs($otherOwner);
        $res = $this->getJson('/api/v1/supplier/categories')->assertOk();
        $this->assertCount(0, $res->json('categories'));
    }

    /** Suppression refusée si un produit utilise encore la catégorie. */
    public function test_cannot_delete_category_in_use(): void
    {
        Sanctum::actingAs($this->owner);

        $cat = ProductCategory::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Boissons',
        ]);

        Product::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Eau céleste',
            'unit' => 'bouteille',
            'category' => 'Boissons',
        ]);

        $this->deleteJson("/api/v1/supplier/categories/{$cat->id}")
            ->assertStatus(422);

        // Suppression possible une fois le produit sans cette catégorie
        Product::first()->update(['category' => null]);
        $this->deleteJson("/api/v1/supplier/categories/{$cat->id}")->assertOk();
    }

    /** Suppression refusée pour la catégorie d'un autre fournisseur. */
    public function test_cannot_delete_other_supplier_category(): void
    {
        $otherOwner = User::create([
            'name' => 'Owner B', 'phone' => '0600000002', 'password' => 'secret', 'is_active' => true,
        ]);
        $otherOwner->assignRole('supplier_owner');
        $other = Supplier::create([
            'owner_user_id' => $otherOwner->id,
            'name' => 'Fournisseur B',
            'subscription_status' => 'active',
        ]);

        $cat = ProductCategory::create([
            'supplier_id' => $other->id,
            'name' => 'Boissons',
        ]);

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/v1/supplier/categories/{$cat->id}")->assertStatus(404);
    }
}
