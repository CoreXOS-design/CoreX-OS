<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_v2_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals_v2')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->enum('role', ['buyer', 'seller', 'co_buyer', 'co_seller', 'conveyancer', 'bond_originator', 'other']);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_v2_contacts');
    }
};
