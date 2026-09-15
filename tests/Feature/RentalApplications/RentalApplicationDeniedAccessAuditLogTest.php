<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationDocumentMark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * cc4 walk, scope-check pass, 2026-09-13 — Johan's proof standard: "show a
 * real 403, 404 or 423 plus the attempt in the audit log." Neither
 * AuthorizesRentalApplicationAccess::guardRentalApplication() nor
 * HandlesRentalApplicationDocumentMarks::guardCaptureEntryOwnership() had
 * ANY audit trail before this pass — a denied attempt left no record
 * anywhere. Same shape as the RO/CO settings fix's own Log::warning().
 *
 * The live cross-agency 403/404/423 proofs for this pass were run against
 * the real QA1 site BEFORE this logging was added (the authorization LOGIC
 * itself is unchanged, already deployed, and already proven live); this
 * file is the verification for the logging addition specifically, since
 * that code cannot be proven live until it is pulled into the deploy
 * checkout.
 */
final class RentalApplicationDeniedAccessAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_same_agency_wrong_scope_denial_is_logged(): void
    {
        Log::spy();

        $agency = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        $branchOwner = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch Owner']);
        $branchOther = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch Other']);

        $owningAgent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branchOwner->id, 'role' => 'agent']);
        $otherAgent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branchOther->id, 'role' => 'agent']);
        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branchOwner->id, 'first_name' => 'A', 'last_name' => 'Contact', 'email' => 'contact-' . uniqid() . '@example.test']);

        $application = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branchOwner->id,
            'contact_id' => $contact->id, 'created_by_user_id' => $owningAgent->id,
            'status' => 'sent', 'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);

        // A plain 'agent'-role user's own-scope ceiling means a DIFFERENT
        // agent in the SAME agency, same-or-different branch, cannot see
        // this application — the exact "reaches guardRentalApplication()
        // and gets denied there" case (a genuine cross-agency id never
        // reaches this method at all; it 404s earlier at route-model-
        // binding, via BelongsToAgency).
        $response = $this->actingAs($otherAgent)->get(route('corex.rental-applications.show', $application));

        $response->assertForbidden();

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'denied access at guardRentalApplication()')
                && $context['acting_user_id'] === $otherAgent->id
                && $context['rental_application_id'] === $application->id);
    }

    public function test_capture_entry_ownership_denial_is_logged(): void
    {
        Log::spy();

        $agency = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch B']);
        $author = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $otherAdmin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'B', 'last_name' => 'Contact', 'email' => 'contact-' . uniqid() . '@example.test']);

        $application = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'contact_id' => $contact->id, 'created_by_user_id' => $author->id,
            'status' => 'in_progress', 'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);

        $mark = RentalApplicationDocumentMark::create([
            'agency_id' => $agency->id, 'rental_application_id' => $application->id,
            'mark_uid' => 'test-mark-' . uniqid(), 'author_user_id' => $author->id,
            'author_name' => $author->name, 'author_role' => 'agent',
            'entry_type' => 'income', 'entry_amount' => 5000,
        ]);

        $response = $this->actingAs($otherAdmin)->putJson(
            route('corex.rental-applications.capture-entries.update', [$application, $mark->mark_uid]),
            ['entry_amount' => 9999],
        );

        $response->assertForbidden();

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'denied capture-entry edit at guardCaptureEntryOwnership()')
                && $context['acting_user_id'] === $otherAdmin->id
                && $context['mark_id'] === $mark->id);

        $this->assertSame(5000.0, (float) $mark->fresh()->entry_amount);
    }
}
