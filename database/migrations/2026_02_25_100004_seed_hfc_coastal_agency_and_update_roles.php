<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Insert HFC Coastal as the first agency
        DB::table('agencies')->insert([
            'name'            => 'HFC Coastal',
            'slug'            => 'hfc-coastal',
            'primary_color'   => '#0b2a4a',
            'secondary_color' => '#00b4d8',
            'logo_path'       => null,
            'is_active'       => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $agencyId = DB::getPdo()->lastInsertId();

        // Assign all existing branches to HFC Coastal
        DB::table('branches')->update(['agency_id' => $agencyId]);

        // Elevate all current admins to super_admin
        DB::table('users')
            ->where('role', 'admin')
            ->orWhere('is_admin', 1)
            ->update(['role' => 'super_admin']);

        // Assign agency_id to all non-super_admin users
        DB::table('users')
            ->where('role', '!=', 'super_admin')
            ->update(['agency_id' => $agencyId]);
    }

    public function down(): void
    {
        // Revert super_admin back to admin
        DB::table('users')->where('role', 'super_admin')->update(['role' => 'admin']);

        // Clear agency_id from users and branches
        DB::table('users')->update(['agency_id' => null]);
        DB::table('branches')->update(['agency_id' => null]);

        // Remove HFC Coastal agency
        DB::table('agencies')->where('slug', 'hfc-coastal')->delete();
    }
};
