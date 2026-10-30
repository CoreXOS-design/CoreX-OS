<?php

namespace Tests\Feature\Compliance;

use App\Http\Controllers\Compliance\FicaController;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Approving a FICA files its frozen completion report as a Document (type FICA) on the contact, once, so the attorney
 * portal (which reads documents filed to a deal's own parties, narrowed by the distribution matrix) can show it.
 */
class FicaReportFiledToContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_completion_report_is_filed_to_the_contact_once_as_fica(): void
    {
        Storage::fake('local');
        $agency = Agency::create(['name' => 'Fica Realty', 'slug' => 'fica-realty']);
        $branch = \App\Models\Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $co = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin', 'is_active' => true]);
        $contact = Contact::withoutEvents(fn () => Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Jan', 'last_name' => 'Botha', 'created_by_user_id' => $co->id]));
        $sub = FicaSubmission::withoutEvents(fn () => FicaSubmission::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_id' => $contact->id, 'requested_by' => $co->id, 'status' => 'approved',
        ]));
        Storage::disk('local')->put('fica-reports/jan.pdf', '%PDF-1.4 report');
        $sub->update(['pdf_path' => 'fica-reports/jan.pdf']);
        $type = DocumentType::firstOrCreate(['slug' => 'fica'], ['label' => 'FICA', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($co);
        $file = new \ReflectionMethod(FicaController::class, 'fileCompletionReportToContact');
        $file->setAccessible(true);
        $controller = app(FicaController::class);
        $file->invoke($controller, $sub->fresh());
        $file->invoke($controller, $sub->fresh());   // approving again must not file a second copy

        $docs = Document::withoutGlobalScopes()->where('source_type', 'fica_report')->where('source_id', $sub->id)->get();
        $this->assertCount(1, $docs);
        $this->assertSame($type->id, (int) $docs[0]->document_type_id);
        $this->assertSame('FICA completion report - Jan Botha.pdf', $docs[0]->original_name);
        $this->assertSame([$contact->id], $docs[0]->contacts()->pluck('contacts.id')->map(fn ($i) => (int) $i)->all());
    }
}
