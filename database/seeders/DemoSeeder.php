<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // --- Admin plateforme ---
        $admin = User::create([
            'name' => 'Admin Plateforme',
            'phone' => '0100000000',
            'email' => 'admin@gestion-stock.com',
            'password' => Hash::make('admin123'),
        ]);
        $admin->assignRole('super_admin');

        // --- Fournisseur démo (grossiste œufs) ---
        $supplierOwner = User::create([
            'name' => 'Jean Grossiste',
            'phone' => '0600000001',
            'email' => 'jean@oeufs-gros.fr',
            'password' => Hash::make('fournisseur123'),
        ]);
        $supplierOwner->assignRole('supplier_owner');

        $supplier = Supplier::create([
            'owner_user_id' => $supplierOwner->id,
            'name' => 'Œufs en Gros SARL',
            'legal_name' => 'Œufs en Gros SARL',
            'email' => 'contact@oeufs-gros.fr',
            'phone' => '0600000001',
            'address' => '12 rue du Commerce, 75001 Paris',
            'subscription_status' => 'active',
            'plan_id' => \App\Models\Plan::where('slug', 'premium')->first()?->id,
        ]);

        // --- Produits ---
        $produits = [
            ['name' => 'Œufs moyens (boîte de 30)', 'unit' => 'boîte', 'category' => 'Œufs'],
            ['name' => 'Œufs gros (boîte de 30)', 'unit' => 'boîte', 'category' => 'Œufs'],
            ['name' => 'Œufs bio (boîte de 12)', 'unit' => 'boîte', 'category' => 'Œufs bio'],
            ['name' => 'Œufs de caille (barquette)', 'unit' => 'barquette', 'category' => 'Œufs'],
            ['name' => 'Blancs d\'œufs (litre)', 'unit' => 'litre', 'category' => 'Ovoproduits'],
        ];

        $productIds = [];
        foreach ($produits as $p) {
            $product = Product::create(array_merge($p, ['supplier_id' => $supplier->id]));
            $productIds[] = $product->id;
        }

        // --- Clients ---
        $clientsData = [
            [
                'name' => 'Épicerie du Centre',
                'contact_name' => 'Marie Dupont',
                'phone' => '0600000010',
                'password' => 'client123',
                'address' => '5 place du Marché, 69001 Lyon',
                'frequency' => 'daily',
                'products' => [0, 1, 2], // indices des produits
            ],
            [
                'name' => 'Bio Marché',
                'contact_name' => 'Paul Martin',
                'phone' => '0600000011',
                'password' => 'client123',
                'address' => '22 avenue Verte, 33000 Bordeaux',
                'frequency' => 'every_2_days',
                'products' => [2, 4],
            ],
            [
                'name' => 'Café de la Gare',
                'contact_name' => 'Lucie Bernard',
                'phone' => '0600000012',
                'password' => 'client123',
                'address' => '1 rue de la Gare, 59000 Lille',
                'frequency' => 'weekly',
                'products' => [0, 1],
            ],
        ];

        foreach ($clientsData as $cData) {
            $user = User::create([
                'name' => $cData['contact_name'],
                'phone' => $cData['phone'],
                'password' => Hash::make($cData['password']),
            ]);
            $user->assignRole('client');

            $customer = Customer::create([
                'supplier_id' => $supplier->id,
                'owner_user_id' => $user->id,
                'name' => $cData['name'],
                'contact_name' => $cData['contact_name'],
                'phone' => $cData['phone'],
                'address' => $cData['address'],
                'default_frequency' => $cData['frequency'],
            ]);

            // Rattacher les produits
            foreach ($cData['products'] as $idx) {
                CustomerProduct::create([
                    'customer_id' => $customer->id,
                    'product_id' => $productIds[$idx],
                    'supplier_id' => $supplier->id,
                    'initial_stock' => 50,
                    'current_stock' => 50,
                    'reorder_point' => 10,
                ]);
            }
        }

        // --- Utilisateur multi-rôle : fournisseur + client ---
        $multiUser = User::create([
            'name' => 'Fatou Multi',
            'phone' => '0600000099',
            'password' => Hash::make('0000'),
        ]);
        $multiUser->assignRole('supplier_owner');
        $multiUser->assignRole('client');

        // Son fournisseur
        $multiSupplier = Supplier::create([
            'owner_user_id' => $multiUser->id,
            'name' => 'Fatou Distribution',
            'phone' => '0600000099',
            'subscription_status' => 'trial',
            'plan_id' => \App\Models\Plan::where('slug', 'trial')->first()?->id,
            'trial_ends_at' => now()->addDays(30),
        ]);

        // Ses produits
        Product::create([
            'supplier_id' => $multiSupplier->id,
            'name' => 'Riz parfumé (sac 25kg)',
            'unit' => 'sac',
            'category' => 'Riz',
        ]);

        // Elle est aussi cliente de "Œufs en Gros SARL"
        $multiCustomer = Customer::create([
            'supplier_id' => $supplier->id,
            'owner_user_id' => $multiUser->id,
            'name' => 'Épicerie Fatou',
            'contact_name' => 'Fatou',
            'phone' => '0600000099',
            'address' => 'Marché central',
            'default_frequency' => 'daily',
        ]);
        CustomerProduct::create([
            'customer_id' => $multiCustomer->id,
            'product_id' => $productIds[0],
            'supplier_id' => $supplier->id,
            'initial_stock' => 30,
            'current_stock' => 30,
            'reorder_point' => 5,
        ]);
    }
}
