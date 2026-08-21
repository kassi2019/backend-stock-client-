<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->string('name', 30);
            $table->timestamps();

            $table->unique(['supplier_id', 'name'], 'unit_supp_name_uq');
            $table->foreign('supplier_id', 'unit_supp_fk')->references('id')->on('suppliers')->onDelete('cascade');
        });

        // Backfill : unités par défaut + unités réellement utilisées par les
        // produits existants, pour chaque fournisseur déjà en base.
        $now = now();
        $rows = [];

        $defaults = ['pièce', 'boîte', 'carton', 'paquet', 'kg', 'litre'];
        foreach (DB::table('suppliers')->pluck('id') as $supplierId) {
            foreach ($defaults as $name) {
                $rows[] = ['supplier_id' => $supplierId, 'name' => $name, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (DB::table('products')->select('supplier_id', 'unit')->distinct()->get() as $p) {
            if ($p->unit) {
                $rows[] = ['supplier_id' => $p->supplier_id, 'name' => $p->unit, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        $rows = array_unique($rows, SORT_REGULAR);
        DB::table('units')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
