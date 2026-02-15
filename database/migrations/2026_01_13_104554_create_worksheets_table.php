<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('worksheets', function (Blueprint $table) {
 $table->id();

$table->foreignId('user_id')->constrained()->cascadeOnDelete();

$table->string('period'); // e.g. 2026-01

$table->decimal('personal_net_target', 10, 2)->default(0);
$table->decimal('business_net_target', 10, 2)->default(0);
$table->decimal('want_net_target', 10, 2)->default(0);

$table->decimal('avg_sale_price', 12, 2)->default(1060000);
$table->decimal('commission_percent', 5, 2)->default(7.50);
$table->decimal('paye_percent', 5, 2)->default(18.00);

$table->decimal('agent_split_percent', 5, 2)->default(50.00);

$table->decimal('correctly_priced_percent', 5, 2)->default(40.00);

$table->integer('current_listings')->default(0);

$table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worksheets');
    }
};
