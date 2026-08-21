<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Rôles de la plateforme
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin', 'guard_name' => 'web']); // admin secondaire (créé par le principal)
        Role::create(['name' => 'supplier_owner', 'guard_name' => 'web']);
        Role::create(['name' => 'supplier_staff', 'guard_name' => 'web']);
        Role::create(['name' => 'client', 'guard_name' => 'web']);
    }
}
