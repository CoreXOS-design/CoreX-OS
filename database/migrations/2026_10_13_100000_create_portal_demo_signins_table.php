<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// QA demo sign-in (never on Staging/live): one hashed, revocable token per demo contact and side. The table is harmless anywhere (nothing
// reads it unless the route is registered, which only happens on APP_ENV=qa with the flag on). Spec: rental-portal-access.md "QA demo sign-in".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_demo_signins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->index();
            $table->string('role', 16);                       // owner | tenant
            $table->char('token_hash', 64)->unique();          // sha256 of the token; the token itself is never stored
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_demo_signins');
    }
};
