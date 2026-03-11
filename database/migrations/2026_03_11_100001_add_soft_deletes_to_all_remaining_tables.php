<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that need a deleted_at column for SoftDeletes compliance.
     * Every model must use SoftDeletes — non-negotiable #1.
     */
    private array $tables = [
        'activity_targets',
        'agencies',
        'ai_conversations',
        'ai_daily_briefings',
        'ai_feedback',
        'ai_messages',
        'article_pool',
        'branch_settings',
        'commercial_evaluation_financials',
        'company_expenses',
        'contact_documents',
        'contact_matches',
        'contact_notes',
        'contact_types',
        'contacts',
        'daily_activities',
        'deal_logs',
        'deal_money_lines',
        'deal_settlements',
        'document_library_items',
        'document_types',
        'finance_audit_items',
        'finance_audit_runs',
        'finance_computed_values',
        'finance_definitions',
        'knowledge_chunks',
        'listing_import_rows',
        'listing_import_runs',
        'listing_snapshots',
        'listing_stocks',
        'listing_targets',
        'market_analytics_runs',
        'monthly_target_goals',
        'nexus_permissions',
        'p24_import_log',
        'p24_listings',
        'p24_price_changes',
        'pdf_splitter_feedback',
        'pdf_splitter_learned_phrases',
        'performance_settings',
        'portal_listing_observations',
        'portal_listings',
        'presentation_active_listings',
        'presentation_document_library_items',
        'presentation_fields',
        'presentation_listing_price_history',
        'presentation_sections',
        'presentation_snapshots',
        'presentation_sold_comps',
        'presentation_url_snapshots',
        'presentation_versions',
        'property_files',
        'property_notes',
        'property_setting_items',
        'rental_agents',
        'rental_amount_versions',
        'role_permissions',
        'sale_probability_runs',
        'sales_document_recipients',
        'sales_document_sends',
        'targets',
        'tv_access_codes',
        'users',
        'worksheets',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
