<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalSettingAuditEntry;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderInvoice;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Services\Rentals\RentalWorkOrderInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.31 — the supplier's invoice document on a work order. Proven here: filing (type / size
 * limits that are per-agency settings), change, replace (old file kept), share with owner, archive and restore (never a hard
 * delete), every step in the work order's history; the amount feeds the Complete form's cost box as a suggestion and never writes
 * the cost, who-pays or approval; permission by role and the work order's own record scope; and WHO SEES WHAT — the owner sees an
 * invoice only while it is shared (page payload, list payload and the file), the tenant never sees an invoice or an amount.
 */
final class SupplierInvoiceTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private RentalWorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();
        AuditContext::reset();
        $this->buildFlowWorld();
        $this->workOrder = $this->externalJob();
    }

    private function pdf(string $name = 'invoice.pdf', int $kb = 40): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    /** @param array<string, mixed> $over */
    private function fileInvoice(array $over = [], ?User $as = null, ?RentalWorkOrder $wo = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('corex.rental-work-orders.invoices.store', $wo ?? $this->workOrder), array_merge([
            'invoice_number' => 'INV-114', 'invoice_date' => now()->toDateString(), 'amount' => '1850.50', 'document' => $this->pdf(),
        ], $over));
    }

    private function file(string $number = 'INV-114', array $attrs = []): RentalWorkOrderInvoice
    {
        $path = 'rental-work-order-invoices/' . $this->agency->id . '/' . $this->workOrder->id . '/' . uniqid() . '.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');

        return RentalWorkOrderInvoice::create(array_merge([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $this->workOrder->id, 'invoice_number' => $number,
            'invoice_date' => now()->toDateString(), 'amount' => 1850.50, 'document_storage_path' => $path,
            'document_original_name' => 'invoice.pdf', 'document_mime' => 'application/pdf', 'uploaded_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    private function history(string $type)
    {
        return $this->workOrder->updates()->where('update_type', $type)->get();
    }

    // ── Filing, list, limits ────────────────────────────────────────────

    public function test_an_invoice_is_filed_with_its_number_date_amount_and_file_and_the_history_says_so(): void
    {
        $this->fileInvoice()->assertRedirect()->assertSessionHasNoErrors();

        $invoice = RentalWorkOrderInvoice::firstOrFail();
        $this->assertSame('INV-114', $invoice->invoice_number);
        $this->assertSame('1850.50', (string) $invoice->amount);
        $this->assertFalse($invoice->share_with_owner, 'an invoice starts private to the office');
        $this->assertSame($this->admin->id, $invoice->uploaded_by_user_id);
        $this->assertSame($this->agency->id, $invoice->agency_id);
        Storage::disk('local')->assertExists($invoice->document_storage_path);
        $this->assertStringStartsWith('rental-work-order-invoices/' . $this->agency->id . '/' . $this->workOrder->id . '/', $invoice->document_storage_path);
        $this->assertCount(1, $this->history('invoice_uploaded'));
        $this->assertStringContainsString('INV-114', $this->history('invoice_uploaded')->first()->note);

        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $this->workOrder))
            ->assertOk()->assertSee('data-invoices-panel', false)->assertSee('INV-114')->assertSee('R1,850.50');
    }

    public function test_several_invoices_can_be_filed_on_one_work_order(): void
    {
        $this->fileInvoice()->assertSessionHasNoErrors();
        $this->fileInvoice(['invoice_number' => 'INV-115', 'amount' => '200'])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->workOrder->invoices()->count());
        $this->assertEqualsWithDelta(2050.50, app(RentalWorkOrderInvoiceService::class)->total($this->workOrder), 0.001);
    }

    public function test_a_wrong_file_type_a_too_big_file_a_missing_file_and_a_repeated_number_are_refused_in_plain_words(): void
    {
        $this->fileInvoice(['document' => UploadedFile::fake()->create('invoice.txt', 10, 'text/plain')])->assertSessionHasErrors('document');
        $this->fileInvoice(['document' => UploadedFile::fake()->create('invoice.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('document');
        $this->fileInvoice(['document' => null])->assertSessionHasErrors('document');
        $this->fileInvoice(['invoice_number' => ''])->assertSessionHasErrors('invoice_number');
        $this->fileInvoice(['amount' => '-5'])->assertSessionHasErrors('amount');
        $this->fileInvoice(['invoice_date' => now()->addDays(3)->toDateString()])->assertSessionHasErrors('invoice_date');
        $this->assertSame(0, RentalWorkOrderInvoice::count(), 'nothing half-saved');

        $this->fileInvoice()->assertSessionHasNoErrors();
        $this->fileInvoice(['invoice_number' => ' inv-114 '])->assertSessionHasErrors('invoice');
        $this->assertSame(1, RentalWorkOrderInvoice::count());
    }

    public function test_the_size_and_type_limits_are_the_agencys_own_settings(): void
    {
        $this->fileInvoice(['document' => UploadedFile::fake()->create('scan.jpg', 40, 'image/jpeg'), 'invoice_number' => 'A'])->assertSessionHasNoErrors();   // default: PDF + photos
        $this->fileInvoice(['document' => UploadedFile::fake()->create('iphone.heic', 40, 'image/heic'), 'invoice_number' => 'B'])->assertSessionHasErrors('document');

        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['invoice_allowed_file_types' => 'pdf_images_heic', 'invoice_max_file_mb' => 1]);
        $this->fileInvoice(['document' => UploadedFile::fake()->create('iphone.heic', 40, 'image/heic'), 'invoice_number' => 'B'])->assertSessionHasNoErrors();
        $this->fileInvoice(['document' => $this->pdf('big.pdf', 1500), 'invoice_number' => 'C'])->assertSessionHasErrors('document');

        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['invoice_allowed_file_types' => 'pdf', 'invoice_max_file_mb' => 5]);
        $this->fileInvoice(['document' => UploadedFile::fake()->create('scan.png', 40, 'image/png'), 'invoice_number' => 'D'])->assertSessionHasErrors('document');
        $this->fileInvoice(['document' => $this->pdf('ok.pdf', 3000), 'invoice_number' => 'E'])->assertSessionHasNoErrors();

        // a second agency is untouched by the first one's limits (defaults)
        $this->assertSame(10, RentalWorkOrderSetting::invoiceMaxFileMbFor(999999));
        $this->assertSame(['pdf', 'jpg', 'jpeg', 'png', 'webp'], RentalWorkOrderSetting::invoiceAllowedExtensionsFor(999999));
    }

    // ── Change, replace, archive, restore ───────────────────────────────

    public function test_changing_the_facts_and_replacing_the_file_keeps_the_old_file_and_is_audited(): void
    {
        $invoice = $this->file();
        $oldPath = $invoice->document_storage_path;

        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.invoices.update', [$this->workOrder, $invoice]), [
            'invoice_number' => 'INV-114', 'invoice_date' => now()->toDateString(), 'amount' => '2000', 'document' => $this->pdf('corrected.pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('2000.00', (string) $invoice->amount);
        $this->assertNotSame($oldPath, $invoice->document_storage_path);
        Storage::disk('local')->assertExists($oldPath);
        Storage::disk('local')->assertExists($invoice->document_storage_path);
        $this->assertSame($oldPath, $invoice->superseded_documents[0]['path']);
        $this->assertSame($this->admin->id, $invoice->superseded_documents[0]['replaced_by']);
        $this->assertCount(1, $this->history('invoice_replaced'));

        // a facts-only change logs "changed"; an identical re-save logs nothing
        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.invoices.update', [$this->workOrder, $invoice]), ['amount' => '2100'])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->history('invoice_changed'));
        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.invoices.update', [$this->workOrder, $invoice]), ['amount' => '2100'])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->history('invoice_changed'));
    }

    public function test_archive_is_a_soft_delete_that_keeps_the_file_and_can_be_restored_and_everything_is_audited(): void
    {
        $invoice = $this->file();

        $this->actingAs($this->admin)->delete(route('corex.rental-work-orders.invoices.destroy', [$this->workOrder, $invoice]))->assertRedirect();
        $this->assertSoftDeleted($invoice);
        Storage::disk('local')->assertExists($invoice->document_storage_path);
        $this->assertSame($this->admin->id, $invoice->fresh()?->archived_by_user_id ?? RentalWorkOrderInvoice::withTrashed()->find($invoice->id)->archived_by_user_id);
        $this->assertCount(1, $this->history('invoice_archived'));

        // the archived invoice is still reachable by the office (with a restore path) and is not on the live list
        $show = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $this->workOrder))->assertOk();
        $show->assertSee('data-archived-invoice="' . $invoice->id . '"', false)->assertSee('data-invoices-empty', false);
        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.invoices.download', [$this->workOrder, $invoice->id]))->assertOk();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.invoices.restore', [$this->workOrder, $invoice->id]))->assertRedirect();
        $this->assertNull(RentalWorkOrderInvoice::find($invoice->id)->deleted_at);
        $this->assertCount(1, $this->history('invoice_restored'));
    }

    // ── The amount feeds the cost record, it never writes it ────────────

    public function test_the_amount_is_offered_on_the_complete_form_and_never_written_to_cost_or_who_pays_or_approval(): void
    {
        $this->fileInvoice()->assertSessionHasNoErrors();
        $this->fileInvoice(['invoice_number' => 'INV-115', 'amount' => '149.50'])->assertSessionHasNoErrors();

        $wo = $this->workOrder->fresh();
        $this->assertNull($wo->cost_amount, 'filing an invoice never records the cost');
        $this->assertNull($wo->paid_by);
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $wo->owner_approval_status);
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->status);

        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $this->workOrder))
            ->assertOk()->assertSee('complete-work-order-form', false)->assertSee('Mark in progress')   // the Supplier section still draws around the new panel
            ->assertSee('data-cost-from-invoices', false)->assertSee('value="2000.00"', false);
        $this->assertSame(2000.0, app(RentalWorkOrderInvoiceService::class)->suggestedCost($wo));

        // the existing completion rules run exactly as before on whatever figure is submitted: R2,000 is above the agency's no-approval
        // limit, so with no owner approval on record the SAME refusal as always applies - the invoice does not get around it
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $this->workOrder), ['paid_by' => 'owner', 'cost_amount' => '2000'])->assertSessionHasErrors();
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $this->workOrder->fresh()->status);
        $this->assertNull($this->workOrder->fresh()->cost_amount);

        $this->workOrder->forceFill(['owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED, 'approved_amount' => 2000, 'approval_basis' => RentalWorkOrder::BASIS_OWNER_DECISION])->save();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $this->workOrder), ['paid_by' => 'owner', 'cost_amount' => '2000'])->assertSessionHasNoErrors();
        $done = $this->workOrder->fresh();
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $done->status);
        $this->assertSame('2000.00', (string) $done->cost_amount);
        $this->assertSame('owner', $done->paid_by);
    }

    public function test_once_a_cost_is_recorded_nothing_is_suggested_and_a_difference_is_flagged_not_fixed(): void
    {
        $this->file('INV-114', ['amount' => 900]);
        $this->workOrder->forceFill(['cost_amount' => 750])->save();

        $this->assertNull(app(RentalWorkOrderInvoiceService::class)->suggestedCost($this->workOrder->fresh()));
        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $this->workOrder))
            ->assertOk()->assertSee('data-invoice-mismatch', false);
        $this->assertSame('750.00', (string) $this->workOrder->fresh()->cost_amount, 'the recorded cost is not rewritten');
    }

    public function test_a_work_order_with_no_invoice_suggests_nothing(): void
    {
        $this->assertNull(app(RentalWorkOrderInvoiceService::class)->suggestedCost($this->workOrder));
        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $this->workOrder))->assertOk()->assertDontSee('data-cost-from-invoices', false);
    }

    // ── Permission through roles, and the record scope ──────────────────

    public function test_the_permission_decides_who_can_file_see_and_download_an_invoice(): void
    {
        $invoice = $this->file();
        $without = $this->userWith(['rental_work_orders.view' => 'all', 'rental_work_orders.create' => 'all']);
        $with = $this->userWith(['rental_work_orders.view' => 'all', 'rental_work_orders.manage_invoices' => 'all'], 'branch_manager');

        $this->fileInvoice([], $without)->assertForbidden();
        $this->actingAs($without)->get(route('corex.rental-work-orders.invoices.download', [$this->workOrder, $invoice->id]))->assertForbidden();
        $this->actingAs($without)->delete(route('corex.rental-work-orders.invoices.destroy', [$this->workOrder, $invoice]))->assertForbidden();
        $this->actingAs($without)->get(route('corex.rental-work-orders.show', $this->workOrder))
            ->assertOk()->assertDontSee('data-invoices-panel', false)->assertDontSee('INV-114');

        $this->actingAs($with)->get(route('corex.rental-work-orders.invoices.download', [$this->workOrder, $invoice->id]))->assertOk();
        $this->fileInvoice(['invoice_number' => 'NEW-1'], $with)->assertSessionHasNoErrors();
        $this->assertSame(2, $this->workOrder->invoices()->count());
        $this->assertContains('rental_work_orders.manage_invoices', collect(config('corex-permissions.permissions'))->pluck('key')->all());
    }

    public function test_another_agency_and_another_work_orders_invoice_are_a_404_or_403_never_a_file(): void
    {
        $invoice = $this->file();
        $theirs = $this->foreignWorkOrder();
        [, $otherAgent] = $this->otherAgencyWorld();

        // a user from another agency cannot reach this work order's invoice at all
        $this->actingAs($otherAgent)->get(route('corex.rental-work-orders.invoices.download', [$this->workOrder, $invoice->id]))->assertNotFound();
        $this->fileInvoice([], $otherAgent)->assertStatus(404);

        // our own user cannot reach it through a different work order's URL
        $mine2 = $this->externalJob(['title' => 'Second']);
        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.invoices.download', [$mine2, $invoice->id]))->assertNotFound();
        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.invoices.update', [$mine2, $invoice]), ['amount' => '1'])->assertNotFound();
        $this->actingAs($this->admin)->delete(route('corex.rental-work-orders.invoices.destroy', [$mine2, $invoice]))->assertNotFound();
        $this->assertNull($invoice->fresh()->deleted_at);
        $this->assertTrue($theirs->agency_id !== $this->agency->id);
    }

    // ── Who sees what: owner only when shared, tenant never ─────────────

    public function test_the_share_tick_changes_what_the_owner_sees_and_is_audited(): void
    {
        $invoice = $this->file('INV-114', ['amount' => 4321.09]);
        $svc = app(RentalWorkOrderInvoiceService::class);

        $this->assertSame([], $svc->ownerPayload($this->workOrder), 'private by default');

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.invoices.share', [$this->workOrder, $invoice]), ['share_with_owner' => 1])->assertRedirect();
        $this->assertTrue($invoice->fresh()->share_with_owner);
        $this->assertNotNull($invoice->fresh()->shared_at);
        $this->assertCount(1, $this->history('invoice_shared'));
        $this->assertSame('INV-114', $svc->ownerPayload($this->workOrder)[0]['invoice_number']);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.invoices.share', [$this->workOrder, $invoice]), ['share_with_owner' => 0])->assertRedirect();
        $this->assertSame([], $svc->ownerPayload($this->workOrder));
        $this->assertCount(1, $this->history('invoice_unshared'));
    }

    public function test_the_owner_sees_a_shared_invoice_on_their_work_order_page_list_and_file_but_not_an_unshared_or_archived_one(): void
    {
        $shared = $this->file('INV-SHARED', ['amount' => 4321.09, 'share_with_owner' => true, 'shared_at' => now()]);
        $private = $this->file('INV-PRIVATE', ['amount' => 7777.77]);
        $archived = $this->file('INV-ARCHIVED', ['amount' => 8888.88, 'share_with_owner' => true, 'shared_at' => now()]);
        $archived->delete();
        $this->actingAsLandlord();

        $show = $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}")->assertOk();
        $this->assertSame(['INV-SHARED'], collect($show->json('work_order.invoices'))->pluck('invoice_number')->all());
        $this->assertEquals(4321.09, $show->json('work_order.invoices.0.amount'));
        $json = $show->getContent();
        foreach (['INV-PRIVATE', 'INV-ARCHIVED', '7777', '8888', 'document_storage_path', 'rental-work-order-invoices/', 'uploaded_by'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "owner payload leaked {$leak}");
        }

        $list = $this->getJson('/api/v1/client/rentals/landlord/work-orders')->assertOk();
        $this->assertSame(['INV-SHARED'], collect($list->json('work_orders.0.client.invoices'))->pluck('invoice_number')->all());

        $url = $show->json('work_order.invoices.0.view_url');
        $this->assertStringContainsString("/work-orders/{$this->workOrder->id}/invoices/{$shared->id}/file", $url);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($show->json('work_order.invoices.0.download_url'))->assertOk();
        $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}/invoices/{$private->id}/file")->assertNotFound();
        $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}/invoices/{$archived->id}/file")->assertNotFound();

        // un-share it and the very same link stops working
        $shared->forceFill(['share_with_owner' => false])->save();
        $this->getJson($url)->assertNotFound();
        $this->assertSame([], $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}")->json('work_order.invoices'));
    }

    public function test_the_tenant_never_sees_an_invoice_or_an_amount_even_when_it_is_shared_with_the_owner(): void
    {
        $shared = $this->file('INV-SHARED', ['amount' => 4321.09, 'share_with_owner' => true, 'shared_at' => now()]);
        $this->workOrder->forceFill(['lease_id' => $this->lease->id])->save();
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $list = $this->getJson('/api/v1/client/rentals/work-orders')->assertOk();
        $show = $this->getJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}")->assertOk();
        foreach ([$list->getContent(), $show->getContent()] as $json) {
            foreach (['INV-SHARED', '4321', 'invoice', 'Invoice'] as $leak) {
                $this->assertStringNotContainsString($leak, $json, "a supplier invoice leaked to the tenant: {$leak}");
            }
        }
        // the tenant cannot reach the owner's invoice file by guessing the URL
        $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}/invoices/{$shared->id}/file")->assertStatus(404);
        $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}")->assertStatus(404);
    }

    public function test_another_owner_and_another_agencys_owner_cannot_reach_the_invoice(): void
    {
        $shared = $this->file('INV-SHARED', ['share_with_owner' => true, 'shared_at' => now()]);
        $otherProperty = $this->makeProperty($this->agency, $this->admin, '4 Beach Road, Uvongo');
        $neighbour = $this->makeLandlord($this->agency, $otherProperty, ['email' => 'neighbour.' . uniqid() . '@example.invalid']);
        [$otherAgency, $otherAgent, $otherProp] = $this->otherAgencyWorld();
        $capeOwner = $this->makeLandlord($otherAgency, $otherProp, ['email' => 'cape.' . uniqid() . '@example.invalid']);

        foreach ([$neighbour, $capeOwner] as $stranger) {
            Sanctum::actingAs($this->clientUserFor($stranger), ['client']);
            $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}/invoices/{$shared->id}/file")->assertNotFound();
            $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}")->assertNotFound();
        }
        $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}/invoices/{$shared->id}/file")->assertNotFound();
    }

    private function actingAsLandlord(): void
    {
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
    }

    // ── The two upload-limit settings ───────────────────────────────────

    public function test_the_limits_save_from_settings_have_a_guarded_saver_and_leave_an_audit_trail(): void
    {
        $url = route('corex.settings.rental-work-orders.invoice-limits');

        $this->actingAs($this->admin)->post($url, ['invoice_max_file_mb' => 25])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(25, RentalWorkOrderSetting::invoiceMaxFileMbFor($this->agency->id));
        $this->assertSame('pdf_images', RentalWorkOrderSetting::invoiceAllowedFileTypesFor($this->agency->id), 'a field not posted is left alone');

        $this->actingAs($this->admin)->post($url, ['invoice_allowed_file_types' => 'pdf'])->assertSessionHasNoErrors();
        $this->assertSame(25, RentalWorkOrderSetting::invoiceMaxFileMbFor($this->agency->id), 'posting only the types does not reset the size');
        $this->assertSame('pdf', RentalWorkOrderSetting::invoiceAllowedFileTypesFor($this->agency->id));

        $this->actingAs($this->admin)->post($url, ['invoice_max_file_mb' => 0])->assertSessionHasErrors('invoice_max_file_mb');
        $this->actingAs($this->admin)->post($url, ['invoice_max_file_mb' => 51])->assertSessionHasErrors('invoice_max_file_mb');
        $this->actingAs($this->admin)->post($url, ['invoice_allowed_file_types' => 'exe'])->assertSessionHasErrors('invoice_allowed_file_types');
        $this->assertSame(25, RentalWorkOrderSetting::invoiceMaxFileMbFor($this->agency->id));

        $this->assertSame(['invoice_allowed_file_types', 'invoice_max_file_mb'], RentalSettingAuditEntry::where('agency_id', $this->agency->id)->pluck('setting_key')->sort()->values()->all());

        $this->actingAs($this->userWith(['rental_work_orders.view' => 'all'], 'agent', []))->post($url, ['invoice_max_file_mb' => 5])->assertForbidden();
        $this->assertSame(25, RentalWorkOrderSetting::invoiceMaxFileMbFor($this->agency->id));
    }
}
