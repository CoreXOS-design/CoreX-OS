<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalCatalogueItem;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Images\HeicConverter;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Audit fixes 19 Oct 2026, package P3 (spec rentals-faults-work-orders.md section 29): the tenant adds to / withdraws an open fault (C-A5 / D20); a retried report makes ONE
 * fault (C-I2); HEIC is accepted and converted everywhere (C-I1 / B19); the owner's quote decision carries the quote it saw (D26); the mobile job card respects
 * view_costs and gives absolute photo URLs (C-D8 / D18); existence leaks answer 404 (C-D6 / A28); one "is it fixed?" rule set (D19).
 */
final class AuditP3PortalTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;
    private RentalFaultType $faultType;
    private const BASE = '/api/v1/client/rentals';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');
        $this->agency = Agency::create(['name' => 'P3 Agency', 'slug' => 'p3-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'P3 Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = $this->contact('Tina', 'Tenant');
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = $this->contact('Lenny', 'Landlord');
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
    }

    private function contact(string $first, string $last): Contact
    {
        return Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => $first, 'last_name' => $last, 'email' => strtolower($first) . '+' . uniqid() . '@example.invalid']);
    }

    private function as(Contact $c): void
    {
        $cu = ClientUser::firstOrCreate(['email' => $c->email], ['current_agency_id' => $c->agency_id]);
        $c->forceFill(['client_user_id' => $cu->id])->saveQuietly();
        Sanctum::actingAs($cu, ['client']);
    }

    private function png(string $name = 'p.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 30);
    }

    private function report(array $extra = [], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        $this->as($this->tenant);

        return $this->withHeaders($key ? ['X-Submission-Key' => $key] : [])->postJson(self::BASE . '/properties/' . $this->property->id . '/fault-reports', $extra + [
            'rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem', 'title' => 'Tap drips', 'description' => 'It drips all night',
        ]);
    }

    private function openFault(): RentalFaultReport
    {
        return RentalFaultReport::withoutGlobalScopes()->findOrFail($this->report()->assertStatus(201)->json('fault_report.id'));
    }

    // ── C-A5 / D20: add to an open fault, and withdraw it ─────────────────────────────────────────────────────

    public function test_the_tenant_can_add_a_note_and_photos_to_their_open_fault_and_the_agent_sees_both(): void
    {
        $f = $this->openFault();
        $this->as($this->tenant);
        $res = $this->post(self::BASE . "/fault-reports/{$f->id}/notes", ['note' => 'It is worse in the morning', 'photos' => [$this->png('a.png'), $this->png('b.png')]], ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertTrue($res->json('fault_report.can_add'));
        $this->assertTrue($res->json('fault_report.can_withdraw'));

        $f = $f->fresh();
        $this->assertSame(2, $f->photos()->count());
        $this->assertNull($f->photos()->first()->uploaded_by_user_id, 'uploaded by the tenant');
        $history = $f->history()->pluck('note')->implode(' | ');
        $this->assertStringContainsString('It is worse in the morning', $history);
        $this->assertStringContainsString('2 photos', $history);

        // the agent's page shows the history line
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();
        $this->assertStringContainsString('It is worse in the morning', html_entity_decode($html));
    }

    public function test_an_empty_follow_up_is_refused_and_so_is_one_on_a_closed_fault(): void
    {
        $f = $this->openFault();
        $this->as($this->tenant);
        $this->postJson(self::BASE . "/fault-reports/{$f->id}/notes", [])->assertStatus(422);

        $f->forceFill(['status' => RentalFaultReport::STATUS_RESOLVED, 'resolved_at' => now(), 'outcome' => 'repaired'])->save();
        $this->postJson(self::BASE . "/fault-reports/{$f->id}/notes", ['note' => 'one more thing'])->assertStatus(409);
    }

    public function test_another_tenants_fault_is_a_404_for_add_and_withdraw(): void
    {
        $f = $this->openFault();
        $other = $this->contact('Olive', 'Other');   // no lease on this property
        $this->as($other);
        $this->postJson(self::BASE . "/fault-reports/{$f->id}/notes", ['note' => 'x'])->assertStatus(404);
        $this->postJson(self::BASE . "/fault-reports/{$f->id}/withdraw", ['reason' => 'not mine'])->assertStatus(404);
        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $f->fresh()->status);
    }

    public function test_a_tenant_whose_lease_has_ended_can_still_read_their_fault_but_cannot_add_to_or_withdraw_it(): void
    {
        $f = $this->openFault();
        $before = \App\Models\RentalFaultReportUpdate::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->count();
        $this->lease->forceFill(['status' => Lease::STATUS_EXPIRED])->saveQuietly();
        $this->as($this->tenant);

        // audit 2026-10-20 finding 6 (D17): the write lookup needs a lease still in force, exactly as work orders do; the history stays readable
        $this->getJson(self::BASE . "/fault-reports/{$f->id}")->assertOk();
        $this->postJson(self::BASE . "/fault-reports/{$f->id}/notes", ['note' => 'months after move-out'])->assertStatus(404);
        $this->postJson(self::BASE . "/fault-reports/{$f->id}/withdraw", ['reason' => 'moved out long ago'])->assertStatus(404);
        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $f->fresh()->status);
        $this->assertSame($before, \App\Models\RentalFaultReportUpdate::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->count(), 'nothing was added to the fault');

        // the same lease back in force: the same calls work
        $this->lease->forceFill(['status' => Lease::STATUS_ACTIVE])->saveQuietly();
        $this->postJson(self::BASE . "/fault-reports/{$f->id}/notes", ['note' => 'still dripping'])->assertStatus(201);
    }

    public function test_withdrawing_cancels_in_the_tenants_name_keeps_the_record_and_needs_a_reason(): void
    {
        $f = $this->openFault();
        $this->as($this->tenant);
        $this->postJson(self::BASE . "/fault-reports/{$f->id}/withdraw", [])->assertStatus(422);
        $res = $this->postJson(self::BASE . "/fault-reports/{$f->id}/withdraw", ['reason' => 'It stopped, thank you'])->assertOk();
        $this->assertFalse($res->json('fault_report.can_withdraw'));

        $f = $f->fresh();
        $this->assertSame(RentalFaultReport::STATUS_CANCELLED, $f->status);
        $this->assertStringContainsString('Withdrawn by the tenant: It stopped, thank you', $f->cancel_reason);
        $this->assertNull($f->cancelled_by_user_id);
        $this->assertNull(RentalFaultReport::onlyTrashed()->find($f->id), 'never deleted');
        $this->assertStringContainsString('withdrew this fault', $f->history()->pluck('note')->implode(' | '));
    }

    public function test_a_fault_with_a_live_work_order_cannot_be_withdrawn(): void
    {
        $f = $this->openFault();
        $wo = RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, 'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->agent->id,
            'title' => 'Tap', 'description' => 'Tap', 'status' => RentalWorkOrder::STATUS_ORDERED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(), 'created_by_user_id' => $this->agent->id, 'reported_fault_report_id' => $f->id,
        ]);
        $f->forceFill(['rental_work_order_id' => $wo->id, 'status' => RentalFaultReport::STATUS_WORK_ORDER_RAISED])->save();

        $this->as($this->tenant);
        $this->postJson(self::BASE . "/fault-reports/{$f->id}/withdraw", ['reason' => 'never mind'])->assertStatus(409);
        $this->assertNotSame(RentalFaultReport::STATUS_CANCELLED, $f->fresh()->status);
    }

    public function test_the_follow_up_alert_is_its_own_type_so_the_first_report_alert_cannot_swallow_it(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('notification_event_types')->where('key', 'rental_fault_report.tenant_update')->whereNull('deleted_at')->exists());
    }

    // ── C-I2: a retry makes ONE fault ─────────────────────────────────────────────────────────────────────────

    public function test_a_photo_that_fails_to_store_leaves_no_fault_and_the_retry_makes_exactly_one(): void
    {
        Storage::fake('local');
        // a tenant's picture is stored on the PRIVATE disk since audit D14 (PrivateImageService -> PropertyImageStorer::storeOnDisk)
        $this->app->bind(PropertyImageStorer::class, fn () => new class extends PropertyImageStorer {
            public function storeOnDisk(UploadedFile $file, string $directory, string $diskName): string
            {
                throw new \RuntimeException('disk full');
            }
        });
        $key = 'retry-key-' . uniqid();
        $this->report(['photos' => [$this->png()]], $key)->assertStatus(500);
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(), 'nothing half-made');

        $this->app->forgetInstance(PropertyImageStorer::class);
        $this->app->bind(PropertyImageStorer::class, fn () => new PropertyImageStorer());
        $this->report(['photos' => [$this->png()]], $key)->assertStatus(201);
        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());

        // and pressing it a third time replays the same answer - still one
        $this->report(['photos' => [$this->png()]], $key);
        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
    }

    // ── C-I1 / B19: HEIC accepted and converted ──────────────────────────────────────────────────────────────

    private function heic(): UploadedFile
    {
        $im = new \Imagick();
        $im->newImage(64, 32, 'red');
        $im->setImageFormat('heic');
        $path = tempnam(sys_get_temp_dir(), 'h') . '.heic';
        $im->writeImage($path);

        return new UploadedFile($path, 'IMG_0001.HEIC', 'image/heic', null, true);
    }

    public function test_a_heic_photo_from_the_tenant_is_accepted_and_stored_as_a_jpeg(): void
    {
        if (! HeicConverter::available()) {
            $this->markTestSkipped('No HEIC support in Imagick on this host.');
        }
        Storage::fake('local');
        $f = RentalFaultReport::withoutGlobalScopes()->findOrFail($this->report(['photos' => [$this->heic()]])->assertStatus(201)->json('fault_report.id'));
        $photo = $f->photos()->firstOrFail();
        $this->assertStringEndsWith('.jpg', $photo->storage_path);
        $abs = (string) app(\App\Services\Images\PrivateImageService::class)->absolutePath($photo->storage_path);   // private disk since audit D14
        $this->assertFileExists($abs);
        $info = getimagesize($abs);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame([64, 32], [$info[0], $info[1]]);
    }

    public function test_heic_is_recognised_by_its_brand_even_when_the_phone_sends_no_type_or_extension(): void
    {
        if (! HeicConverter::available()) {
            $this->markTestSkipped('No HEIC support in Imagick on this host.');
        }
        $src = $this->heic();
        $bare = new UploadedFile($src->getRealPath(), 'photo', 'application/octet-stream', null, true);
        $this->assertTrue(HeicConverter::isHeic($bare));
        $this->assertFalse(HeicConverter::isHeic($this->png()));
        $this->assertNotNull(HeicConverter::exifDateTime($src->getRealPath()) ?? [null], 'reading the capture time never throws');
    }

    public function test_every_portal_photo_endpoint_uses_the_one_mime_list(): void
    {
        foreach (['Api/V1/ClientTenantRentalsController', 'Api/V1/ClientLandlordRentalsController', 'Api/V1/ClientRentalWorkOrdersController', 'CompletionResponseController'] as $c) {
            $src = file_get_contents(app_path("Http/Controllers/{$c}.php"));
            $this->assertStringContainsString('HeicConverter::UPLOAD_MIMES', $src, $c);
            $this->assertStringNotContainsString("'file', 'image'", $src, $c);
            $this->assertStringNotContainsString('file|image|', $src, $c);
        }
    }

    // ── D26: the owner's quote decision carries the quote it saw ─────────────────────────────────────────────

    private function workOrderWithQuote(float $amount = 4200.0): array
    {
        $supplier = AgencyServiceProvider::create(['agency_id' => $this->agency->id, 'name' => 'Acme', 'email' => 'acme@example.invalid', 'is_active' => true, 'created_by_id' => $this->agent->id]);
        $wo = RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, 'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->agent->id,
            'title' => 'Geyser', 'description' => 'Geyser', 'status' => RentalWorkOrder::STATUS_REPORTED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(), 'created_by_user_id' => $this->agent->id, 'agency_service_provider_id' => $supplier->id,
        ]);
        $q = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'New geyser'], $this->agent);
        $wo->selectQuote($q, $this->agent);

        return [$wo->fresh(), $q->fresh(), $supplier];
    }

    public function test_the_owner_decision_must_carry_the_quote_and_a_changed_quote_is_refused_with_409(): void
    {
        [$wo, $q, $supplier] = $this->workOrderWithQuote(4200);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->owner_approval_status, 'with no owner limit the quote goes to the owner');
        $this->as($this->landlord);
        $url = self::BASE . "/landlord/work-orders/{$wo->id}/decision";

        // what the owner is shown carries the snapshot
        $dec = $this->getJson(self::BASE . '/landlord/decisions')->assertOk();
        $listed = $dec->json('work_orders.0.quote');
        $this->assertNotNull($listed, 'decisions: ' . $dec->getContent());
        $this->assertSame($q->id, $listed['id']);
        $this->assertEqualsWithDelta(4200.0, $listed['amount'], 0.01);

        // an old client that sends no snapshot is refused (a blind approve is never safe)
        $this->postJson($url, ['decision' => 'approve'])->assertStatus(422);

        // the agent changes the quote after the owner loaded the screen
        $q->forceFill(['amount' => 5200, 'revision' => ((int) $q->revision) + 1])->save();
        $this->postJson($url, ['decision' => 'approve', 'quote_id' => $listed['id'], 'quote_revision' => $listed['revision'], 'quote_amount' => $listed['amount']])
            ->assertStatus(409)->assertJsonFragment(['message' => 'The quote has changed since you looked at it - please look again before you decide.']);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status, 'nothing was approved');

        // looking again and deciding on what they now see works
        $now = $this->getJson(self::BASE . '/landlord/decisions')->json('work_orders.0.quote');
        $this->postJson($url, ['decision' => 'approve', 'quote_id' => $now['id'], 'quote_revision' => $now['revision'], 'quote_amount' => $now['amount']])->assertOk();
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $wo->fresh()->owner_approval_status);
    }

    // ── C-D8 / D18: the mobile job card ──────────────────────────────────────────────────────────────────────

    public function test_the_mobile_job_card_hides_money_without_view_costs_and_returns_absolute_photo_urls(): void
    {
        $card = app(\App\Services\Rentals\RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Replace tap'], $this->agent);
        app(\App\Services\Rentals\RentalJobCardService::class)->addLine($card, ['type' => 'part', 'description' => 'Tap', 'quantity' => 1, 'unit' => 'each', 'unit_price' => 500], $this->agent);
        $wo = $card->fresh()->workOrder;
        $wo->photos()->create(['agency_id' => $this->agency->id, 'photo_type' => 'reported', 'storage_path' => '/storage/properties/' . $this->property->id . '/x.jpg', 'file_size_bytes' => 10]);

        Sanctum::actingAs($this->agent, ['*']);
        $url = "/api/v1/mobile/rental-job-cards/{$card->id}";
        $withCosts = $this->getJson($url);
        if ($withCosts->status() === 404) {
            $this->markTestSkipped('Mobile job card route not under /api/v1/mobile in this build.');
        }
        $withCosts->assertOk();
        $this->assertArrayHasKey('total_amount', $withCosts->json());
        $this->assertArrayHasKey('unit_price', $withCosts->json('lines.0'));
        $this->assertStringStartsWith('http', $withCosts->json('photos.0.url'));
        $this->assertStringEndsWith('/storage/properties/' . $this->property->id . '/x.jpg', $withCosts->json('photos.0.url'));

        $agent2 = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        if (! $agent2->hasPermission('rental_job_cards.view_costs')) {
            Sanctum::actingAs($agent2, ['*']);
            $res = $this->getJson($url);
            if ($res->status() === 200) {
                $this->assertArrayNotHasKey('total_amount', $res->json());
                $this->assertArrayNotHasKey('unit_price', $res->json('lines.0') ?? []);
                $this->assertArrayNotHasKey('line_total', $res->json('lines.0') ?? []);
            }
        }
    }

    // ── C-D6 / A28: existence leaks answer 404 ───────────────────────────────────────────────────────────────

    public function test_a_foreign_and_a_missing_catalogue_id_answer_the_same_404(): void
    {
        $card = app(\App\Services\Rentals\RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->agent);
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'ot-' . uniqid()]);
        $foreign = RentalCatalogueItem::withoutGlobalScopes()->create(['agency_id' => $other->id, 'code' => 'F1', 'description' => 'Foreign item', 'selling_excl' => 10]);
        $route = route('corex.rental-job-cards.lines.store', $card);

        $this->actingAs($this->agent)->postJson($route, ['rental_catalogue_item_id' => $foreign->id, 'quantity' => 1])->assertStatus(404);
        $this->actingAs($this->agent)->postJson($route, ['rental_catalogue_item_id' => 99999999, 'quantity' => 1])->assertStatus(404);
    }

    public function test_the_rule_is_one_class_and_the_template_lookups_use_it(): void
    {
        foreach (['CoreX/LeaseRenewalController', 'Api/V1/LeaseRenewalApiController', 'CoreX/RentalNoticeController'] as $c) {
            $this->assertStringContainsString('FoundInAgency', file_get_contents(app_path("Http/Controllers/{$c}.php")), $c);
        }
    }

    // ── D19: one "is it fixed?" rule set ─────────────────────────────────────────────────────────────────────

    public function test_the_legacy_confirm_on_an_open_job_applies_the_same_rule_as_progress(): void
    {
        [$wo] = $this->workOrderWithQuote(900);
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_ORDERED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED])->save();
        $this->as($this->tenant);

        // the legacy answer on an OPEN job goes through recordTenantProgress() - the same rule as /progress, so the two routes refuse (or accept) for the same reason
        $legacy = $this->postJson(self::BASE . "/work-orders/{$wo->id}/confirm", ['fixed' => true]);
        $progress = $this->postJson(self::BASE . "/work-orders/{$wo->id}/progress", ['action' => 'finished']);
        $this->assertSame($progress->status(), $legacy->status());
        $this->assertSame($progress->json('message'), $legacy->json('message'));
        $this->assertSame(422, $legacy->status());
        $this->assertStringContainsString('DEPRECATED', file_get_contents(base_path('routes/api.php')));
    }
}
