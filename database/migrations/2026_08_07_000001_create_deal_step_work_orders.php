<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-229 COC sub-process — the RUNTIME per-deal work-order selection.
 *
 * The pipeline-step TEMPLATE config (`deal_pipeline_step_work_orders`) says which COC types a
 * step OFFERS. This table is the per-DEAL selection an agent makes on the live pipeline: which
 * COCs this deal actually needs, who is RESPONSIBLE for each, and who the work order is emailed
 * to. The supplier (when responsible = supplier) is captured here; the send audits through the
 * AT-228 distribution path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_step_work_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deal_step_instance_id')->index();
            $table->unsignedBigInteger('dr1_deal_id')->nullable()->index();
            $table->unsignedBigInteger('agency_id')->nullable()->index();

            $table->string('service_type', 40);
            // Who is responsible for obtaining this COC + who the work order goes to:
            // seller | listing_agent | selling_agent | supplier | transfer_attorney
            $table->string('responsible_party', 30)->default('supplier');
            $table->unsignedBigInteger('service_provider_id')->nullable(); // when responsible = supplier

            $table->string('recipient_name', 255)->nullable();  // snapshot at send
            $table->string('recipient_email', 255)->nullable(); // snapshot at send
            $table->text('cc_emails')->nullable();              // de-duped CC list, snapshot

            $table->string('status', 20)->default('pending');   // pending | sent
            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('sent_by_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('deal_step_instance_id')->references('id')->on('deal_step_instances')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_step_work_orders');
    }
};
