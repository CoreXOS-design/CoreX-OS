<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleMap = [
            'seller' => 'owner',
            'lessor' => 'lessor',
            'buyer' => 'buyer',
            'lessee' => 'tenant',
        ];

        $nullRoles = DB::table('contact_property')->whereNull('role')->get();

        foreach ($nullRoles as $record) {
            $esignRole = DB::table('contacts')
                ->join('contact_types', 'contacts.contact_type_id', '=', 'contact_types.id')
                ->where('contacts.id', $record->contact_id)
                ->value('contact_types.esign_role');

            if ($esignRole && isset($roleMap[$esignRole])) {
                DB::table('contact_property')
                    ->where('contact_id', $record->contact_id)
                    ->where('property_id', $record->property_id)
                    ->whereNull('role')
                    ->update(['role' => $roleMap[$esignRole]]);
            }
        }
    }

    public function down(): void
    {
        // Not reversible — we don't know which roles were originally NULL
    }
};
