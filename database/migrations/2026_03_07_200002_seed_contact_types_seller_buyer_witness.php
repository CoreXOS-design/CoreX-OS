<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            ['id' => 3, 'name' => 'Seller',  'color' => '#e67e22', 'sort_order' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Buyer',   'color' => '#27ae60', 'sort_order' => 4, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'name' => 'Witness', 'color' => '#7f8c8d', 'sort_order' => 5, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($rows as $row) {
            if (! DB::table('contact_types')->where('id', $row['id'])->exists()) {
                DB::table('contact_types')->insert($row);
            }
        }
    }

    public function down(): void
    {
        DB::table('contact_types')->whereIn('id', [3, 4, 5])->delete();
    }
};
