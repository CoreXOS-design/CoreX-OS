<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'emergency_contact_name')) {
                $table->string('emergency_contact_name', 150)->nullable();
            }
            if (!Schema::hasColumn('users', 'emergency_contact_phone')) {
                $table->string('emergency_contact_phone', 30)->nullable();
            }
            if (!Schema::hasColumn('users', 'emergency_contact_relationship')) {
                $table->string('emergency_contact_relationship', 50)->nullable();
            }
            if (!Schema::hasColumn('users', 'next_of_kin_name')) {
                $table->string('next_of_kin_name', 150)->nullable();
            }
            if (!Schema::hasColumn('users', 'next_of_kin_phone')) {
                $table->string('next_of_kin_phone', 30)->nullable();
            }
            if (!Schema::hasColumn('users', 'next_of_kin_relationship')) {
                $table->string('next_of_kin_relationship', 50)->nullable();
            }
            if (!Schema::hasColumn('users', 'home_address')) {
                $table->text('home_address')->nullable();
            }
            if (!Schema::hasColumn('users', 'marital_status')) {
                $table->enum('marital_status', [
                    'single', 'married', 'divorced', 'widowed', 'life_partner', 'other',
                ])->nullable();
            }
            if (!Schema::hasColumn('users', 'dependents_count')) {
                $table->tinyInteger('dependents_count')->unsigned()->default(0);
            }
            if (!Schema::hasColumn('users', 'medical_aid_provider')) {
                $table->string('medical_aid_provider', 100)->nullable();
            }
            if (!Schema::hasColumn('users', 'medical_aid_number')) {
                $table->string('medical_aid_number', 50)->nullable();
            }
            if (!Schema::hasColumn('users', 'medical_aid_main_member')) {
                $table->boolean('medical_aid_main_member')->default(false);
            }
            if (!Schema::hasColumn('users', 'medical_aid_dependents_count')) {
                $table->tinyInteger('medical_aid_dependents_count')->unsigned()->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = [
                'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
                'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship',
                'home_address', 'marital_status', 'dependents_count',
                'medical_aid_provider', 'medical_aid_number', 'medical_aid_main_member',
                'medical_aid_dependents_count',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
