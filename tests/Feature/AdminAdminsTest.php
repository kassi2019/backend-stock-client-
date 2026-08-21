<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAdminsTest extends TestCase
{
    use RefreshDatabase;

    protected User $principal;
    protected User $secondary;
    protected User $supplierUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->principal = User::create([
            'name' => 'Admin Principal', 'phone' => '0100000000', 'password' => 'secret', 'is_active' => true,
        ]);
        $this->principal->assignRole('super_admin');

        $this->secondary = User::create([
            'name' => 'Admin Secondaire', 'phone' => '0100000001', 'password' => 'secret', 'is_active' => true,
        ]);
        $this->secondary->assignRole('admin');

        $this->supplierUser = User::create([
            'name' => 'Fournisseur A', 'phone' => '0600000001', 'password' => 'secret', 'is_active' => true,
        ]);
        $this->supplierUser->assignRole('supplier_owner');
    }

    /** Le principal liste et crée des administrateurs secondaires. */
    public function test_principal_can_list_and_create_admins(): void
    {
        Sanctum::actingAs($this->principal);

        $res = $this->getJson('/api/v1/admin/admins');
        $res->assertOk();
        $this->assertCount(2, $res->json());
        $this->assertTrue($res->json()[0]['is_primary']);

        $res = $this->postJson('/api/v1/admin/admins', [
            'name' => 'Admin N°3',
            'phone' => '0100000002',
            'email' => 'admin3@exemple.com',
            'password' => 'secret66',
        ]);
        $res->assertStatus(201)
            ->assertJsonPath('login', '0100000002')
            ->assertJsonPath('password', 'secret66');

        $new = User::where('phone', '0100000002')->first();
        $this->assertTrue($new->hasRole('admin'));
        $this->assertFalse($new->isSuperAdmin());
    }

    /** Un admin secondaire accède aux fournisseurs et forfaits. */
    public function test_secondary_admin_can_manage_suppliers_and_plans(): void
    {
        Sanctum::actingAs($this->secondary);

        $this->getJson('/api/v1/admin/suppliers')->assertOk();
        $this->getJson('/api/v1/admin/plans')->assertOk();
    }

    /** Un admin secondaire ne peut PAS gérer les administrateurs. */
    public function test_secondary_admin_cannot_manage_admins(): void
    {
        Sanctum::actingAs($this->secondary);

        $this->getJson('/api/v1/admin/admins')->assertStatus(403);
        $this->postJson('/api/v1/admin/admins', [
            'name' => 'X', 'phone' => '0100000005', 'password' => 'secret66',
        ])->assertStatus(403);
        $this->deleteJson("/api/v1/admin/admins/{$this->principal->id}")->assertStatus(403);
    }

    /** Un fournisseur ne peut rien voir de l'espace admin. */
    public function test_supplier_cannot_access_admin_space(): void
    {
        Sanctum::actingAs($this->supplierUser);

        $this->getJson('/api/v1/admin/admins')->assertStatus(403);
        $this->getJson('/api/v1/admin/suppliers')->assertStatus(403);
    }

    /** Le principal ne peut pas supprimer son propre compte. */
    public function test_principal_cannot_delete_himself(): void
    {
        Sanctum::actingAs($this->principal);

        $this->deleteJson("/api/v1/admin/admins/{$this->principal->id}")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Vous ne pouvez pas supprimer votre propre compte.');
    }

    /** Aucun compte principal (super_admin) ne peut être supprimé. */
    public function test_principal_account_cannot_be_deleted(): void
    {
        $secondPrincipal = User::create([
            'name' => 'Admin Principal 2', 'phone' => '0100000009', 'password' => 'secret', 'is_active' => true,
        ]);
        $secondPrincipal->assignRole('super_admin');

        Sanctum::actingAs($this->principal);
        $this->deleteJson("/api/v1/admin/admins/{$secondPrincipal->id}")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Le compte administrateur principal ne peut pas être supprimé.');
    }

    /** Le principal supprime un admin secondaire. */
    public function test_principal_can_delete_secondary_admin(): void
    {
        Sanctum::actingAs($this->principal);

        $this->deleteJson("/api/v1/admin/admins/{$this->secondary->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Administrateur supprimé.');

        $this->assertDatabaseMissing('users', ['id' => $this->secondary->id]);
    }

    /** Téléphone déjà utilisé → 422. */
    public function test_duplicate_phone_is_rejected(): void
    {
        Sanctum::actingAs($this->principal);

        $this->postJson('/api/v1/admin/admins', [
            'name' => 'Doublon', 'phone' => '0100000001', 'password' => 'secret66',
        ])->assertStatus(422);
    }
}
