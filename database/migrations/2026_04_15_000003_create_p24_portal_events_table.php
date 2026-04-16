<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p24_portal_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('portal_id')->nullable()->index();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('actor_type')->default('portal_visitor'); // portal_visitor|admin|system
            $table->string('actor_label')->nullable();
            $table->string('event'); // portal.opened, portal.row.confirmed, etc.
            $table->unsignedBigInteger('target_row_id')->nullable()->index();
            $table->string('target_external_id')->nullable();
            $table->json('meta_json')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['agency_id', 'created_at']);
            $table->index(['portal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p24_portal_events');
    }
};
