<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-488 - sending an application back must not lose what the agent captured, and the bulk-marks save is locked like
 * every other write while the application is with the authoriser. Spec: .ai/specs/rental-applications.md (AT-488).
 */
final class RentalApplicationSendBackSavesAt488Test extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Test Agency', 'slug' => 'ta-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    public function test_the_bulk_marks_save_is_locked_for_the_agent_while_the_application_is_with_the_authoriser(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $app = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'under_assessment',
            'submitted_at' => now()->subDay(), 'submitted_for_approval_at' => now(),
        ]);
        Storage::fake('local');
        $path = 'rental-applications/' . $app->id . '/documents/' . Str::random(20) . '.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 fake content');
        $documentId = (int) DB::table('documents')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'payslip.pdf', 'storage_path' => $path, 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 100, 'document_type_id' => null,
            'source_type' => 'rental_application', 'source_id' => $app->id,
            'uploaded_by' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $document = Document::findOrFail($documentId);

        $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.documents.highlight', [$app, $document]), ['marks' => []])
            ->assertStatus(423);
    }

    public function test_the_send_back_action_saves_the_assessment_and_every_dirty_document_before_it_posts(): void
    {
        $js = file_get_contents(resource_path('views/corex/rental-applications/review.blade.php'));
        $start = strpos($js, 'async sendBackToApplicant()');
        $this->assertNotFalse($start);
        $body = substr($js, $start, strpos($js, 'submissionHistoryOpen:', $start) - $start);

        $saveFields = strpos($body, 'await this.saveFieldsAwaitable()');
        $saveMarks = strpos($body, 'await this.saveAllDirtyDocuments()');
        $post = strpos($body, 'await fetch(url');
        $this->assertNotFalse($saveFields);
        $this->assertNotFalse($saveMarks);
        $this->assertNotFalse($post);
        $this->assertLessThan($post, $saveFields, 'the assessment must be saved before the send-back is posted');
        $this->assertLessThan($post, $saveMarks, 'dirty highlights/notes must be saved before the send-back is posted');
        $this->assertStringContainsString('Not sent', $body, 'a failed save must be reported and must stop the send');
        $this->assertStringContainsString('window.__raSuppressUnloadGuard = true', $body, 'the reload after a send-back must not trigger the leave-site prompt');
    }
}
