<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('anniversary_date')->nullable()->after('agency_id');
            $table->unsignedBigInteger('sponsored_by_user_id')->nullable()->after('anniversary_date');
            $table->string('agent_tier', 20)->default('standard')->after('sponsored_by_user_id');
            $table->boolean('is_mentor_eligible')->default(false)->after('agent_tier');

            $table->foreign('sponsored_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['sponsored_by_user_id']);
            $table->dropColumn(['anniversary_date', 'sponsored_by_user_id', 'agent_tier', 'is_mentor_eligible']);
        });
    }
};
