<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact Testimonials.
 *
 * A testimonial captured on the Contact who gave it, optionally published to
 * the agency's public website via the Agency Public API (pull + webhooks).
 * Agents capture; principals/admins publish (the `published` flag).
 *
 * Spec: .ai/specs/testimonials.md §3.1
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_testimonials')) {
            return;
        }

        Schema::create('contact_testimonials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->text('body');
            // Public author name shown on the website. Defaults to the contact's
            // full name, editable per testimonial (POPIA — e.g. "Andre R.").
            $table->string('display_name', 150);
            $table->unsignedTinyInteger('rating')->nullable();

            // The "send to website" tick (Company Settings → Website).
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'published']);
            $table->index('contact_id');

            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('published_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_testimonials');
    }
};
