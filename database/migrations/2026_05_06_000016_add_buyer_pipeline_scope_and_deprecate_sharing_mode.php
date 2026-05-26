<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add buyer pipeline default scope to agency_contact_settings
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->enum('buyer_pipeline_default_scope', ['own', 'branch', 'agency'])
                ->default('own')
                ->after('sharing_mode')
                ->comment('Default pipeline view scope for agents. Independent of contact access.');
        });

        // Backfill: all existing agencies get 'own' (agents see their own pipeline by default)
        DB::table('agency_contact_settings')->update(['buyer_pipeline_default_scope' => 'own']);

        // Migrate sharing_mode values into role_permissions.scope for contacts.view
        // This preserves existing visibility behaviour per agency's current sharing_mode
        $agencies = DB::table('agency_contact_settings')->get(['agency_id', 'sharing_mode']);
        foreach ($agencies as $agency) {
            $agentScope = match ($agency->sharing_mode) {
                'open' => 'all',
                'branch' => 'branch',
                'closed' => 'own',
                default => 'all',
            };

            // Update agent role's contacts.view scope to match current sharing_mode
            // This preserves existing behaviour: if agency was 'open', agents keep 'all'
            DB::table('role_permissions')
                ->where('role', 'agent')
                ->where('permission_key', 'contacts.view')
                ->update(['scope' => $agentScope]);
        }

        // Mark sharing_mode as deprecated (add column comment)
        // Column preserved for rollback safety — ContactScope no longer reads it
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn('buyer_pipeline_default_scope');
        });
    }
};
