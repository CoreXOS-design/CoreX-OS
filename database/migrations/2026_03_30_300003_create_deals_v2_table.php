<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals_v2', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->enum('deal_type', ['bond', 'cash', 'sale_of_2nd']);
            $table->enum('status', ['active', 'completed', 'cancelled', 'on_hold'])->default('active');
            $table->foreignId('property_id')->constrained('properties');
            $table->foreignId('listing_agent_id')->constrained('users');
            $table->foreignId('selling_agent_id')->nullable()->constrained('users');
            $table->foreignId('pipeline_template_id')->constrained('deal_pipeline_templates');
            $table->unsignedBigInteger('linked_deal_id')->nullable();
            $table->decimal('purchase_price', 14, 2);
            $table->decimal('commission_percentage', 5, 2)->nullable();
            $table->decimal('commission_amount', 12, 2);
            $table->decimal('commission_vat', 12, 2);
            $table->date('offer_date');
            $table->date('expected_registration')->nullable();
            $table->date('actual_registration')->nullable();
            $table->enum('overall_rag', ['grey', 'green', 'amber', 'red', 'overdue'])->default('grey');
            $table->text('notes')->nullable();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('linked_deal_id')->references('id')->on('deals_v2')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals_v2');
    }
};
