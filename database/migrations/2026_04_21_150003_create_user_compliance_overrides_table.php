<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_compliance_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();

            $table->string('compliance_item', 50);
            $table->enum('override_type', ['exempt', 'waived', 'not_applicable']);

            $table->text('reason');

            $table->foreignId('created_by')->constrained('users');

            $table->date('expires_at')->nullable();

            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'compliance_item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_compliance_overrides');
    }
};
