<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auctions v2 (.ai/specs/auctions.md §24): the buyer tokens an external auction website holds for a signed-in buyer. Hashed; revoked, never deleted. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auction_buyer_tokens')) {
            return;
        }
        Schema::create('auction_buyer_tokens', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_user_id')->index();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('api_key_id')->nullable();
            $t->string('token_hash', 64)->unique();
            $t->string('ip', 45)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_buyer_tokens');
    }
};
