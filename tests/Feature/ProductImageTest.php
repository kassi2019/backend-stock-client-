<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImageTest extends TestCase
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

        Storage::fake('products');
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('produit.jpg', 200, 200);
    }

    /** La photo est enregistrée à la création du produit. */
    public function test_store_product_with_image(): void
    {
        Sanctum::actingAs($this->owner);

        $res = $this->post('/api/v1/supplier/products', [
            'name' => 'Produit photo',
            'unit' => 'pièce',
            'image' => $this->image(),
        ], ['Accept' => 'application/json']);

        $res->assertStatus(201);

        $product = Product::first();
        $this->assertNotNull($product->image_path);
        Storage::disk('products')->assertExists($product->image_path);
        $res->assertJsonPath('image_path', $product->image_path);
    }

    /** Sans photo : image_path reste null. */
    public function test_store_product_without_image(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/supplier/products', [
            'name' => 'Produit sans photo',
            'unit' => 'pièce',
        ])->assertStatus(201);

        $this->assertNull(Product::first()->image_path);
    }

    /** Mode base64 (application mobile) : l'image est décodée et stockée. */
    public function test_store_product_with_base64_image(): void
    {
        Sanctum::actingAs($this->owner);

        $content = $this->image()->getContent();
        $res = $this->postJson('/api/v1/supplier/products', [
            'name' => 'Produit base64',
            'unit' => 'pièce',
            'image_base64' => 'data:image/png;base64,' . base64_encode($content),
        ]);

        $res->assertStatus(201);

        $product = Product::first();
        $this->assertNotNull($product->image_path);
        Storage::disk('products')->assertExists($product->image_path);
    }

    /** Mode base64 : contenu qui n'est pas une image → refusé. */
    public function test_store_product_with_invalid_base64_image(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/supplier/products', [
            'name' => 'Produit base64 invalide',
            'unit' => 'pièce',
            'image_base64' => base64_encode('ceci n\'est pas une image'),
        ])->assertStatus(422);

        $this->assertDatabaseCount('products', 0);
    }

    /** Une nouvelle photo remplace l'ancienne (ancien fichier supprimé). */
    public function test_update_product_replaces_image(): void
    {
        Sanctum::actingAs($this->owner);

        $this->post('/api/v1/supplier/products', [
            'name' => 'Produit photo',
            'unit' => 'pièce',
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $product = Product::first();
        $oldPath = $product->image_path;
        Storage::disk('products')->assertExists($oldPath);

        $this->put("/api/v1/supplier/products/{$product->id}", [
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $product->refresh();
        $this->assertNotSame($oldPath, $product->image_path);
        Storage::disk('products')->assertMissing($oldPath);
        Storage::disk('products')->assertExists($product->image_path);
    }

    /** La suppression du produit supprime aussi sa photo. */
    public function test_destroy_product_deletes_image(): void
    {
        Sanctum::actingAs($this->owner);

        $this->post('/api/v1/supplier/products', [
            'name' => 'Produit photo',
            'unit' => 'pièce',
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $product = Product::first();
        $path = $product->image_path;
        Storage::disk('products')->assertExists($path);

        $this->deleteJson("/api/v1/supplier/products/{$product->id}")->assertOk();

        Storage::disk('products')->assertMissing($path);
    }
}
