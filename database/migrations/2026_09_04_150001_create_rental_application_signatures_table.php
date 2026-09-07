<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_application_id')->constrained()->cascadeOnDelete();
            // AT-392 — the applicant signs twice: the truth-of-information declaration
            // and the TPN credit-check consent. Agent never signs (see spec §3).
            $table->enum('kind', ['declaration', 'tpn_consent']);
            $table->string('signature_path');
            $table->timestamp('signed_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->unique(['rental_application_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_signatures');
    }
};
