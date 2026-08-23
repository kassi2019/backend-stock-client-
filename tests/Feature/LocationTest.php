<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $clientUser;
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

        Storage::fake('products');
    }

    /** Le fournisseur localise le magasin d'un client : photo + coordonnées. */
    public function test_supplier_locates_customer_shop(): void
    {
        Sanctum::actingAs($this->owner);

        $res = $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/location", [
            'latitude' => 5.3600,
            'longitude' => -4.0083,
            'photo_base64' => 'data:image/png;base64,' . base64_encode(UploadedFile::fake()->image('shop.png')->getContent()),
        ])->assertOk();

        $res->assertJsonPath('customer.latitude', 5.36);

        $customer = $this->customer->fresh();
        $this->assertNotNull($customer->shop_image_path);
        $this->assertEquals(5.36, $customer->latitude);
        Storage::disk('products')->assertExists($customer->shop_image_path);
    }

    /** Le client localise son propre magasin. */
    public function test_client_locates_own_shop(): void
    {
        Sanctum::actingAs($this->clientUser);

        $this->postJson('/api/v1/client/location', [
            'latitude' => 5.3600,
            'longitude' => -4.0083,
        ])->assertOk();

        $this->assertEquals(5.36, $this->customer->fresh()->latitude);
    }

    /** Le fournisseur localise son propre magasin. */
    public function test_supplier_locates_own_shop(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/supplier/location', [
            'latitude' => 5.3600,
            'longitude' => -4.0083,
        ])->assertOk();

        $this->assertEquals(5.36, $this->supplier->fresh()->latitude);
    }

    /** Coordonnées hors bornes → refusées. */
    public function test_invalid_coordinates_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/location", [
            'latitude' => 123,
            'longitude' => -4.0083,
        ])->assertStatus(422);

        $this->assertNull($this->customer->fresh()->latitude);
    }

    /** La liste des clients expose les coordonnées (pour la carte). */
    public function test_customers_list_exposes_coordinates(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/location", [
            'latitude' => 5.3600,
            'longitude' => -4.0083,
        ])->assertOk();

        $this->getJson('/api/v1/supplier/customers')
            ->assertOk()
            ->assertJsonPath('0.latitude', 5.36)
            ->assertJsonPath('0.longitude', -4.0083);
    }

    /** La liste admin des fournisseurs expose les coordonnées (pour la carte). */
    public function test_admin_suppliers_list_exposes_coordinates(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/supplier/location', [
            'latitude' => 5.3600,
            'longitude' => -4.0083,
        ])->assertOk();

        // Repasser en admin pour appeler la route admin
        $admin = User::create([
            'name' => 'Admin', 'phone' => '0699999999', 'password' => 'secret', 'is_active' => true,
        ]);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/suppliers')
            ->assertOk()
            ->assertJsonPath('0.latitude', 5.36);
    }
}
