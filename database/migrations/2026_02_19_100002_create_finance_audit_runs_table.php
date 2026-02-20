<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_audit_runs', function (Blueprint $table) {
            $table->id();

            $table->string('period')->comment('YYYY-MM');
            $table->json('scope')->nullable()->comment('e.g. {branch_id: 1, deal_ids: []}');
            $table->string('status')->default('running')->comment('running|complete|failed');

            $table->string('engine_version', 20)->default('v0');

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_audit_runs');
    }
};
