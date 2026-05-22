<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Part A1 — captured leads from teaser presentation views.
 *
 * Lead-capture happens when an anonymous visitor on /p/{teaser_token}
 * submits the form. We try to match an existing Contact by email or
 * phone; on match → contact_id populated; on miss → a new Contact is
 * created in the agency and contact_id is then populated. Either way
 * the lead row records the form submission for analytics.
 *
 * relationship + intent are deliberately small enums — they answer the
 * "what kind of lead is this" question the agent first asks.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('presentation_teaser_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_link_id')->constrained('presentation_snapshot_links')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->foreignId('presentation_id')->constrained('presentations')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 200)->nullable();
            $table->string('phone', 30)->nullable();

            $table->enum('relationship', ['owner', 'considering_selling', 'agent', 'researcher', 'other'])
                ->default('other');
            $table->enum('intent', ['sell_now', 'sell_soon', 'just_curious', 'other'])
                ->default('other');

            $table->boolean('consent_marketing')->default(false);
            $table->boolean('consent_contact')->default(true);
            $table->text('notes')->nullable();

            $table->timestamp('captured_at')->useCurrent();
            $table->timestamp('converted_to_contact_at')->nullable();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('snapshot_link_id');
            $table->index(['agency_id', 'captured_at']);
            $table->index('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_teaser_leads');
    }
};
