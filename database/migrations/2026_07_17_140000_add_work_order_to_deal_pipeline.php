<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-229 DR2 W3 — pipeline-step supplier work-orders.
 *
 * (1) Catalogues the `work_authorisation` document_type (Q6) so the outbound
 *     work-order flows through the AT-227 matrix + audit like `coc_request`.
 * (2) Per-step config (Q1, set in pipeline setup — no hard-coded setting):
 *     `sends_work_order` (the tickbox), `work_order_service_type` (COC/Pest/Gas —
 *     the service line), `work_order_trigger_point` (when it surfaces). NO supplier
 *     column — the supplier is picked/created at send time (Q2).
 */
return new class extends Migration
{
    public function up(): void
    {
        // (1) Catalogued document type (idempotent; global reference row — travels
        //     via deploy:sync-reference-data per AT-162).
        DB::table('document_types')->updateOrInsert(
            ['slug' => 'work_authorisation'],
            [
                'label'              => 'Work Authorisation',
                'sort_order'         => 905,
                'is_active'          => 1,
                'grouping'           => 'shared',
                'fica_slot'          => 'none',
                'buyer_pack_eligible' => 0,
                'updated_at'         => now(),
                'created_at'         => now(),
            ]
        );

        // (2) Per-step config.
        Schema::table('deal_pipeline_steps', function (Blueprint $table) {
            $table->boolean('sends_work_order')->default(false)->after('required_before');
            $table->string('work_order_service_type', 40)->nullable()->after('sends_work_order');
            $table->string('work_order_trigger_point', 20)->nullable()->default('activated')->after('work_order_service_type');
        });
    }

    public function down(): void
    {
        Schema::table('deal_pipeline_steps', function (Blueprint $table) {
            $table->dropColumn(['sends_work_order', 'work_order_service_type', 'work_order_trigger_point']);
        });
        DB::table('document_types')->where('slug', 'work_authorisation')->delete();
    }
};
