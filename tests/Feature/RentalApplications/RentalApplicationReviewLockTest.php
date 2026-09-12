<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\RentalApplicationDocumentMark;
use App\Models\RentalApplicationHighlighter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-12, Johan-approved — cc4's walk found a plain agent could still
 * open and edit the Review screen after submitting to the authoriser: two
 * people acting on the same in-flight decision at once, agent free to
 * change the very affordability figures the authoriser is deciding on.
 * Every write endpoint the agent's Review screen uses now refuses while
 * isPendingAuthorisation() is true — server-side, not a disabled button
 * (the stale-review-URL lesson applies here the same way). Proves: the lock
 * fires on every listed endpoint for the agent, the SAME shared endpoints
 * stay open for the authoriser, and the lock lifts the moment the
 * application comes back (submitted_for_approval_at cleared).
 */
final class RentalApplicationReviewLockTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function agent(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function pendingApplication(User $agent): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'under_assessment',
            'submitted_at' => now()->subDay(), 'submitted_for_approval_at' => now(),
        ]);
    }

    private function attachDocument(RentalApplication $rentalApplication, User $agent): Document
    {
        Storage::fake('local');
        $storagePath = 'rental-applications/' . $rentalApplication->id . '/documents/' . Str::random(20) . '.pdf';
        Storage::disk('local')->put($storagePath, '%PDF-1.4 fake content');

        $documentId = (int) DB::table('documents')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'payslip.pdf', 'storage_path' => $storagePath, 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 100,
            'document_type_id' => null, 'source_type' => 'rental_application', 'source_id' => $rentalApplication->id,
            'uploaded_by' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Document::findOrFail($documentId);
    }

    public function test_agent_capture_create_is_blocked_while_pending_authorisation(): void
    {
        $agent = $this->agent();
        $app = $this->pendingApplication($agent);
        $document = $this->attachDocument($app, $agent);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $incomeHighlighter = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Income')->where('role_scope', 'agent')->firstOrFail();

        $response = $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.documents.capture-entries.store', [$app, $document]), [
                'mark_uid' => 'blocked-create', 'page' => 0, 'points' => [['x' => 10, 'y' => 10], ['x' => 100, 'y' => 10]], 'width' => 26,
                'highlighter_id' => $incomeHighlighter->id, 'entry_type' => 'income', 'entry_amount' => 500,
            ]);

        $response->assertStatus(423);
        self::assertSame(0, RentalApplicationDocumentMark::where('mark_uid', 'blocked-create')->count());
    }

    public function test_agent_manual_add_assessment_save_and_capture_update_delete_are_all_blocked_while_pending_authorisation(): void
    {
        $agent = $this->agent();
        $app = $this->pendingApplication($agent);
        $document = $this->attachDocument($app, $agent);

        $mark = RentalApplicationDocumentMark::create([
            'agency_id' => $this->agency->id, 'document_id' => $document->id, 'rental_application_id' => $app->id,
            'mark_uid' => 'existing-entry', 'type' => 'highlight', 'page' => 0,
            'points' => [['x' => 10, 'y' => 10], ['x' => 100, 'y' => 10]], 'width' => 26,
            'author_user_id' => $agent->id, 'author_name' => $agent->name, 'author_role' => 'agent',
            'entry_type' => 'income', 'entry_amount' => 500,
        ]);

        $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.capture-entries.store-manual', $app), [
                'mark_uid' => 'blocked-manual', 'entry_type' => 'income', 'entry_amount' => 100,
            ])->assertStatus(423);

        $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.review.assessment', $app), [
                'statement_period_from' => '2026-08-01', 'statement_period_to' => '2026-08-31',
            ])->assertStatus(423);

        $this->actingAs($agent)
            ->putJson(route('corex.rental-applications.capture-entries.update', [$app, 'existing-entry']), [
                'entry_amount' => 999,
            ])->assertStatus(423);

        $this->actingAs($agent)
            ->deleteJson(route('corex.rental-applications.capture-entries.destroy', [$app, 'existing-entry']))
            ->assertStatus(423);

        $mark->refresh();
        self::assertSame('500.00', (string) $mark->entry_amount, 'the update attempt must not have changed anything');
        self::assertNull($mark->deleted_at, 'the delete attempt must not have removed anything');
        self::assertSame(
            0,
            RentalApplicationDocumentMark::where('rental_application_id', $app->id)->where('mark_uid', 'blocked-manual')->count()
        );
    }

    public function test_authoriser_capture_create_on_the_same_shared_endpoint_is_unaffected(): void
    {
        $agent = $this->agent();
        $app = $this->pendingApplication($agent);
        $document = $this->attachDocument($app, $agent);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $incomeHighlighter = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Income')->where('role_scope', 'authoriser')->first()
            ?? RentalApplicationHighlighter::where('agency_id', $this->agency->id)->where('label', 'Income')->where('role_scope', 'both')->firstOrFail();

        $this->agency->update(['rental_application_ro_user_ids' => [$agent->id]]);
        \App\Models\Agency::forgetFindMemo();
        $agent->refresh();

        $response = $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.authorisation.documents.capture-entries.store', [$app, $document]), [
                'mark_uid' => 'authoriser-entry', 'page' => 0, 'points' => [['x' => 10, 'y' => 10], ['x' => 100, 'y' => 10]], 'width' => 26,
                'highlighter_id' => $incomeHighlighter->id, 'entry_type' => 'income', 'entry_amount' => 500,
            ]);

        $response->assertOk();
        self::assertSame(1, RentalApplicationDocumentMark::where('mark_uid', 'authoriser-entry')->count());
    }

    public function test_the_lock_lifts_once_the_application_comes_back_to_the_agent(): void
    {
        $agent = $this->agent();
        $app = $this->pendingApplication($agent);
        $document = $this->attachDocument($app, $agent);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $incomeHighlighter = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Income')->where('role_scope', 'agent')->firstOrFail();

        $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.documents.capture-entries.store', [$app, $document]), [
                'mark_uid' => 'still-locked', 'page' => 0, 'points' => [['x' => 10, 'y' => 10], ['x' => 100, 'y' => 10]], 'width' => 26,
                'highlighter_id' => $incomeHighlighter->id, 'entry_type' => 'income', 'entry_amount' => 500,
            ])->assertStatus(423);

        // Same transition the authoriser's own "request more info" action
        // performs (RentalApplicationAuthorisationController::requestMoreInfo())
        // — clears submitted_for_approval_at, status stays 'under_assessment'.
        $app->submitted_for_approval_at = null;
        $app->save();
        self::assertFalse($app->fresh()->isPendingAuthorisation());

        $response = $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.documents.capture-entries.store', [$app, $document]), [
                'mark_uid' => 'now-unlocked', 'page' => 0, 'points' => [['x' => 10, 'y' => 10], ['x' => 100, 'y' => 10]], 'width' => 26,
                'highlighter_id' => $incomeHighlighter->id, 'entry_type' => 'income', 'entry_amount' => 500,
            ]);

        $response->assertOk();
        self::assertSame(1, RentalApplicationDocumentMark::where('mark_uid', 'now-unlocked')->count());
    }
}
