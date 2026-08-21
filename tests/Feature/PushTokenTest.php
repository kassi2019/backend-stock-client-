<?php

namespace Tests\Feature;

use App\Models\PushToken;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushTokenTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->owner = User::create([
            'name' => 'Owner A', 'phone' => '0600000001', 'password' => 'secret', 'is_active' => true,
        ]);
        $this->owner->assignRole('supplier_owner');
    }

    /** Enregistrement d'un token push pour l'utilisateur connecté. */
    public function test_store_registers_token(): void
    {
        Sanctum::actingAs($this->owner);

        $res = $this->postJson('/api/v1/push-token', [
            'token' => 'fcm-token-abc',
            'platform' => 'android',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('push_tokens', [
            'user_id' => $this->owner->id,
            'token' => 'fcm-token-abc',
            'platform' => 'android',
        ]);
    }

    /** Un même token enregistré par un autre compte est déplacé vers lui. */
    public function test_store_moves_token_between_users(): void
    {
        $other = User::create([
            'name' => 'Client A', 'phone' => '0611111111', 'password' => 'secret', 'is_active' => true,
        ]);
        $other->assignRole('client');

        PushToken::create(['user_id' => $other->id, 'token' => 'fcm-token-abc', 'platform' => 'android']);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/push-token', [
            'token' => 'fcm-token-abc',
            'platform' => 'android',
        ])->assertStatus(201);

        $this->assertDatabaseHas('push_tokens', ['user_id' => $this->owner->id, 'token' => 'fcm-token-abc']);
        $this->assertDatabaseMissing('push_tokens', ['user_id' => $other->id, 'token' => 'fcm-token-abc']);
    }

    /** Suppression à la déconnexion (uniquement ses propres tokens). */
    public function test_destroy_removes_own_token_only(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/push-token', ['token' => 'fcm-token-abc'])->assertStatus(201);

        $this->deleteJson('/api/v1/push-token', ['token' => 'fcm-token-abc'])->assertStatus(200);
        $this->assertDatabaseMissing('push_tokens', ['token' => 'fcm-token-abc']);
    }

    /** Créer une notification n'échoue pas même sans configuration Firebase. */
    public function test_notification_creation_does_not_fail_without_fcm(): void
    {
        Sanctum::actingAs($this->owner);

        // Le compte de service n'existe pas en environnement de test :
        // l'observateur push doit rester silencieux.
        $this->owner->notify(new \App\Notifications\ProductNotification(
            'product_created',
            1,
            'Test',
            'Message de test',
        ));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->owner->id,
        ]);
    }
}
