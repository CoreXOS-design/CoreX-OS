<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexus_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();        // e.g. "view_dashboard"
            $table->string('label');                 // e.g. "View Dashboard"
            $table->string('section');               // e.g. "dashboard", "agency-tracker"
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role');                  // admin, branch_manager, agent
            $table->string('permission_key');        // FK to nexus_permissions.key
            $table->timestamps();

            $table->unique(['role', 'permission_key']);
            $table->index('role');
            $table->index('permission_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('nexus_permissions');
    }
};
