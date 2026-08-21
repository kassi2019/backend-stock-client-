<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected User $principal;
    protected User $supplierOwner;
    protected Supplier $supplier;
    protected User $clientUser;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->principal = User::create([
            'name' => 'Admin Principal', 'phone' => '0100000000', 'password' => 'secret', 'is_active' => true,
        ]);
        $this->principal->assignRole('super_admin');

        $this->supplierOwner = User::create([
            'name' => 'Owner A', 'phone' => '0600000001', 'password' => 'secret', 'is_active' => true,
        ]);
        $this->supplierOwner->assignRole('supplier_owner');

        $this->supplier = Supplier::create([
            'owner_user_id' => $this->supplierOwner->id,
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
    }

    /** L'admin réinitialise le mot de passe du fournisseur. */
    public function test_admin_can_reset_supplier_password(): void
    {
        Sanctum::actingAs($this->principal);

        $this->postJson("/api/v1/admin/suppliers/{$this->supplier->id}/reset-password", [
            'password' => 'nouveau66',
        ])->assertOk()
            ->assertJsonPath('login', '0600000001')
            ->assertJsonPath('password', 'nouveau66');

        $this->assertTrue(Hash::check('nouveau66', $this->supplierOwner->fresh()->password));
    }

    /** Mot de passe trop court → 422. */
    public function test_admin_reset_requires_min_6_chars(): void
    {
        Sanctum::actingAs($this->principal);

        $this->postJson("/api/v1/admin/suppliers/{$this->supplier->id}/reset-password", [
            'password' => '123',
        ])->assertStatus(422);
    }

    /** La réinitialisation déconnecte les appareils du fournisseur. */
    public function test_supplier_tokens_are_revoked_after_reset(): void
    {
        $this->supplierOwner->createToken('appareil-test');
        $this->assertGreaterThan(0, $this->supplierOwner->tokens()->count());

        Sanctum::actingAs($this->principal);
        $this->postJson("/api/v1/admin/suppliers/{$this->supplier->id}/reset-password", [
            'password' => 'nouveau66',
        ])->assertOk();

        $this->assertEquals(0, $this->supplierOwner->tokens()->count());
    }

    /** Un fournisseur ne peut pas réinitialiser un mot de passe de fournisseur. */
    public function test_supplier_cannot_reset_supplier_password(): void
    {
        Sanctum::actingAs($this->supplierOwner);

        $this->postJson("/api/v1/admin/suppliers/{$this->supplier->id}/reset-password", [
            'password' => 'nouveau66',
        ])->assertStatus(403);
    }

    /** Le fournisseur réinitialise le mot de passe du client à 0000. */
    public function test_supplier_can_reset_client_password_to_0000(): void
    {
        Sanctum::actingAs($this->supplierOwner);

        $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/reset-password")
            ->assertOk()
            ->assertJsonPath('message', 'Mot de passe réinitialisé à 0000.')
            ->assertJsonPath('login_phone', '0611111111');

        $this->assertTrue(Hash::check('0000', $this->clientUser->fresh()->password));
    }

    /** La réinitialisation du client déconnecte son appareil. */
    public function test_client_tokens_are_revoked_after_reset(): void
    {
        $this->clientUser->createToken('appareil-test');
        $this->assertGreaterThan(0, $this->clientUser->tokens()->count());

        Sanctum::actingAs($this->supplierOwner);
        $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/reset-password")->assertOk();

        $this->assertEquals(0, $this->clientUser->tokens()->count());
    }

    /** Isolation : le fournisseur B ne peut pas réinitialiser le client de A. */
    public function test_customer_reset_is_tenant_scoped(): void
    {
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
        $this->postJson("/api/v1/supplier/customers/{$this->customer->id}/reset-password")
            ->assertStatus(404);
    }
}
