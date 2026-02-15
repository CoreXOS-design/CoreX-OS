<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_capture_rentals')
                ->default(false)
                ->after('is_active');

            $table->index(['can_capture_rentals']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['can_capture_rentals']);
            $table->dropColumn('can_capture_rentals');
        });
    }
};
