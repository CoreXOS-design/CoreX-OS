<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extra detail fields on contacts
        Schema::table('contacts', function (Blueprint $table) {
            $table->date('birthday')->nullable()->after('notes');
            $table->string('id_number', 20)->nullable()->after('birthday');
            $table->text('address')->nullable()->after('id_number');
        });

        // Notes per contact
        Schema::create('contact_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        // Documents / drive uploads per contact
        Schema::create('contact_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0); // bytes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_documents');
        Schema::dropIfExists('contact_notes');
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['birthday', 'id_number', 'address']);
        });
    }
};
