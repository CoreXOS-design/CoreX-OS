<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agency_leave_visibility_matrix', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('viewing_role', 50);
            $table->string('leave_owner_role', 50);
            $table->boolean('same_branch_only')->default(true);
            $table->boolean('can_see')->default(false);
            $table->timestamps();

            $table->unique(
                ['agency_id', 'viewing_role', 'leave_owner_role', 'same_branch_only'],
                'alvm_agency_viewer_owner_branch_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_leave_visibility_matrix');
    }
};
