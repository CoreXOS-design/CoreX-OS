<?php

declare(strict_types=1);

namespace Tests\Feature\RentalNotices;

use App\Mail\Rentals\LeaseBreachNoticeMail;
use App\Mail\Rentals\LeaseBreachOtherPartyMail;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\LeaseBreach;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalNotice;
use App\Models\RentalNoticeTemplate;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\LeaseNoticeReviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\LeaseNotices\BuildsNoticeFixture;
use Tests\TestCase;

/**
 * rental-notices.md §2-§6 - breach notices (bulk payment, one-off other), the remedy window, notice to vacate, who is told, both portals. The
 * agency's own templates are made in the test (CoreX ships none). All mail is faked and rendered; nothing is really sent.
 */
final class LeaseBreachFlowTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function template(string $type, string $body = null, string $name = null): RentalNoticeTemplate
    {
        return RentalNoticeTemplate::create([
            'agency_id' => $this->agency->id, 'name' => $name ?? ('Our ' . $type), 'notice_type' => $type, 'is_active' => true,
            'body_html' => $body ?? match ($type) {
                'breach_payment' => '<p>{{agency_name}}: Dear {{tenant_name}}, you owe {{amount}} on {{lease_ref}} at {{property_address}}. Pay by {{remedy_deadline}}.</p>',
                'breach_other' => '<p>Dear {{tenant_name}}, clause {{clause}} was breached: {{reason}}. Remedy in {{remedy_days}} business days.</p>',
                default => '<p>Dear {{tenant_name}}, vacate {{property_address}}. Breach of {{breach_date}} not remedied by {{remedy_deadline}}.</p>',
            },
        ]);
    }

    /** More running leases (own tenant + landlord, property), like the lease in the fixture. */
    private function extraLease(string $tenantFirst, string $landlordFirst): Lease
    {
        $property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'Unit ' . uniqid(), 'address' => uniqid() . ' Church Street', 'status' => 'active', 'listing_type' => 'rental']);
        $lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id, 'status' => 'active', 'rental_amount' => 7000,
            'start_date' => Carbon::today()->subMonths(2)->toDateString(), 'end_date' => Carbon::today()->addMonths(10)->toDateString(), 'source' => 'manual', 'created_by_user_id' => $this->agent->id,
            'owner_agent_user_id' => $this->agent->id, 'tenant_agent_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->contact($tenantFirst, 'Tenant')->id, 'is_primary' => true]);
        $property->contacts()->attach($this->contact($landlordFirst, 'Landlord')->id, ['role' => 'landlord']);

        return $lease->fresh();
    }

    // ── 1. bulk payment breach ─────────────────────────────────────────────

    public function test_bulk_three_leases_amount_on_two_creates_two_breach_notices_and_the_third_is_left_alone(): void
    {
        $tpl = $this->template('breach_payment');
        $l2 = $this->extraLease('Zola', 'Zandile');
        $l3 = $this->extraLease('Yusuf', 'Yvonne');

        $res = $this->actingAs($this->agent)->post(route('corex.breaches.bulk.store'), [
            'rental_notice_template_id' => $tpl->id,
            'amount' => [$this->lease->id => 'R 1 500,50', $l2->id => '2400', $l3->id => ''],
        ])->assertOk();
        $res->assertSee('data-issued-count', false);

        $this->assertSame(2, LeaseBreach::withoutGlobalScopes()->count());
        $this->assertSame(0, LeaseBreach::withoutGlobalScopes()->where('lease_id', $l3->id)->count(), 'no amount = no breach');
        $first = LeaseBreach::withoutGlobalScopes()->where('lease_id', $this->lease->id)->firstOrFail();
        $this->assertSame('1500.50', (string) $first->amount);
        $this->assertSame('payment', $first->kind);
        $this->assertSame('tenant', $first->breach_party);
        $this->assertSame('issued', $first->status);
        $this->assertSame(Carbon::today()->toDateString(), $first->issued_on->toDateString());
        $this->assertSame(20, (int) $first->remedy_business_days);
        $this->assertSame(app(LeaseNoticeReviewService::class)->addBusinessDays(Carbon::today(), 20)->toDateString(), $first->remedy_deadline->toDateString());
        $this->assertNotNull($first->batch_ref);
        $this->assertSame(2, RentalNotice::withoutGlobalScopes()->whereNotNull('document_id')->count());
        Storage::disk('local')->assertExists(RentalNotice::withoutGlobalScopes()->first()->document->storage_path);
    }

    public function test_each_breach_is_emailed_to_the_tenant_as_the_agent_and_the_landlord_is_told_in_the_agencys_name(): void
    {
        $tpl = $this->template('breach_payment');
        $this->actingAs($this->agent)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => '1500.50']])->assertOk();

        Mail::assertSent(LeaseBreachNoticeMail::class, function (LeaseBreachNoticeMail $m) {
            $html = $m->render();

            return $m->hasTo($this->tenant->email) && ! $m->hasTo($this->landlord->email)
                && $m->sendingAgentId() === $this->agent->id
                && str_contains($html, 'Karoo Lettings') && str_contains($html, 'R 1 500.50') && str_contains($html, 'put this right by')
                && stripos($html, 'corex') === false
                && count($m->attachments()) === 1 && str_contains((string) $m->envelope()->subject, 'Breach notice (payment)');
        });
        Mail::assertSent(LeaseBreachOtherPartyMail::class, function (LeaseBreachOtherPartyMail $m) {
            $html = $m->render();

            return $m->hasTo($this->landlord->email) && ! $m->hasTo($this->tenant->email)
                && str_contains($html, 'A breach notice has been issued against your tenant Tina Tenant for outstanding R 1 500.50.')
                && str_contains($html, 'Karoo Lettings') && stripos($html, 'corex') === false && $m->sendingAgentId() === $this->agent->id;
        });
    }

    public function test_the_filled_notice_comes_from_the_agencys_own_template(): void
    {
        $tpl = $this->template('breach_payment');
        $this->actingAs($this->agent)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => '900']]);

        $notice = RentalNotice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($tpl->id, $notice->rental_notice_template_id);
        $this->assertSame('breach_payment', $notice->notice_type);
        $this->assertSame(LeaseBreach::withoutGlobalScopes()->first()->id, $notice->lease_breach_id);
        $this->assertTrue($notice->sent_to_tenant && $notice->sent_to_landlord, 'both parties may read it in their portal');
    }

    public function test_both_parties_see_the_breach_notice_in_their_portal_documents(): void
    {
        $tpl = $this->template('breach_payment');
        $this->actingAs($this->agent)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => '900']]);

        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $tenant = collect($this->getJson('/api/v1/client/rentals/documents')->assertOk()->json('documents'))->where('kind', 'notice')->values();
        $this->assertCount(1, $tenant);
        $this->assertStringContainsString('Breach notice (payment)', $tenant[0]['name']);

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $landlord = collect($this->getJson('/api/v1/client/rentals/landlord/documents')->assertOk()->json('documents'))->where('kind', 'notice')->values();
        $this->assertCount(1, $landlord);
    }

    public function test_a_second_open_payment_breach_on_the_same_lease_is_skipped_and_named_the_rest_go(): void
    {
        $tpl = $this->template('breach_payment');
        $l2 = $this->extraLease('Zola', 'Zandile');
        $this->actingAs($this->agent)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => '500']])->assertOk();

        $res = $this->actingAs($this->agent)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => '600', $l2->id => '700']])->assertOk();

        $this->assertSame(2, LeaseBreach::withoutGlobalScopes()->count(), 'one for the first lease, one for the second; the first lease\'s second was refused');
        $res->assertSee('data-qa="bulk-skipped"', false)->assertSee('already out for this lease');
    }

    public function test_a_bulk_send_with_no_amounts_is_refused_and_a_lease_you_cannot_see_is_dropped(): void
    {
        $tpl = $this->template('breach_payment');
        $this->actingAs($this->agent)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => '']])->assertSessionHasErrors('amount');
        $this->assertSame(0, LeaseBreach::withoutGlobalScopes()->count());

        // a branch-scoped agent of a second branch cannot reach this branch's lease
        $branch2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Second', 'code' => 'S2-' . $this->agency->id, 'is_active' => true]);
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch2->id, 'role' => 'branch_two']);
        foreach (['rental_breaches.manage', 'rental_breaches.view', 'leases.view'] as $key) {
            RolePermission::create(['role' => 'branch_two', 'permission_key' => $key, 'scope' => 'branch']);
        }
        PermissionService::clearCache();
        $this->actingAs($other)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => '900']])->assertSessionHasErrors('amount');
        $this->actingAs($other)->get(route('corex.breaches.bulk', ['leases' => [$this->lease->id]]))->assertOk()->assertDontSee('data-bulk-row="' . $this->lease->id . '"', false);
        $this->assertSame(0, LeaseBreach::withoutGlobalScopes()->count());
    }

    public function test_the_bulk_screen_lists_the_chosen_leases_with_an_amount_column(): void
    {
        $this->template('breach_payment');
        $l2 = $this->extraLease('Zola', 'Zandile');

        $html = $this->actingAs($this->agent)->get(route('corex.breaches.bulk', ['leases' => [$this->lease->id, $l2->id]]))->assertOk()->getContent();

        foreach ([$this->lease->id, $l2->id] as $id) {
            $this->assertStringContainsString('data-bulk-row="' . $id . '"', $html);
            $this->assertStringContainsString('name="amount[' . $id . ']"', $html);
        }
        $this->assertStringContainsString('Outstanding amount (R)', $html);
        $this->assertStringContainsString('Tina Tenant', $html);
        $this->assertStringContainsString('Last paid', $html);
        $this->assertStringContainsString('Only leases with an amount get a breach notice', $html);
    }

    public function test_the_leases_list_has_the_tick_boxes_and_the_bulk_button(): void
    {
        $html = $this->actingAs($this->agent)->get(route('corex.leases.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="leases-bulk-bar"', $html);
        $this->assertStringContainsString('name="leases[]" value="' . $this->lease->id . '"', $html);
        $this->assertStringContainsString(route('corex.breaches.bulk'), $html);
    }

    public function test_with_no_template_the_bulk_and_one_off_screens_are_real_empty_states_linking_to_the_upload(): void
    {
        $this->assertSame(0, RentalNoticeTemplate::withoutGlobalScopes()->count());

        $bulk = $this->actingAs($this->agent)->get(route('corex.breaches.bulk', ['leases' => [$this->lease->id]]))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="no-templates"', $bulk);
        $this->assertStringContainsString('payment breach notice template', $bulk);
        $this->assertStringContainsString(route('corex.rental-notice-templates.create', ['notice_type' => 'breach_payment']), $bulk);

        $one = $this->actingAs($this->agent)->get(route('corex.leases.breaches.create', $this->lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="no-templates"', $one);
        $this->assertSame(0, RentalNoticeTemplate::withoutGlobalScopes()->count(), 'nothing is ever seeded');
    }

    // ── 2. one-off "other" breach ──────────────────────────────────────────

    private function oneOff(array $over = [])
    {
        return $this->actingAs($this->agent)->post(route('corex.leases.breaches.store', $this->lease), $over + [
            'breach_party' => 'tenant', 'clause' => '9.2 The tenant shall keep the pool clean', 'reason' => 'The pool has not been maintained',
            'rental_notice_template_id' => RentalNoticeTemplate::withoutGlobalScopes()->where('notice_type', 'breach_other')->value('id'), 'intent' => 'send',
        ]);
    }

    public function test_a_one_off_breach_quotes_the_clause_and_reason_and_tells_the_landlord(): void
    {
        $this->template('breach_other');

        $this->oneOff()->assertSessionHasNoErrors();

        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('other', $breach->kind);
        $this->assertSame('9.2 The tenant shall keep the pool clean', $breach->clause);
        $this->assertSame('The pool has not been maintained', $breach->reason);
        Mail::assertSent(LeaseBreachNoticeMail::class, fn ($m) => $m->hasTo($this->tenant->email));
        Mail::assertSent(LeaseBreachOtherPartyMail::class, fn (LeaseBreachOtherPartyMail $m) => $m->hasTo($this->landlord->email) && str_contains($m->render(), 'A breach notice has been issued against your tenant Tina Tenant for a breach of the lease.'));
        $this->assertTrue(LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_BREACH_ISSUED)->where('description', 'like', '%pool has not been maintained%')->exists());
    }

    public function test_a_landlord_breach_goes_to_the_landlord_and_the_tenant_is_told(): void
    {
        $this->template('breach_other', '<p>Dear {{landlord_name}}, clause {{clause}}: {{reason}}.</p>');

        $this->oneOff(['breach_party' => 'landlord', 'clause' => '12 Landlord maintains the roof', 'reason' => 'The roof leaks'])->assertSessionHasNoErrors();

        Mail::assertSent(LeaseBreachNoticeMail::class, fn ($m) => $m->hasTo($this->landlord->email) && ! $m->hasTo($this->tenant->email));
        Mail::assertSent(LeaseBreachOtherPartyMail::class, fn (LeaseBreachOtherPartyMail $m) => $m->hasTo($this->tenant->email) && str_contains($m->render(), 'against your landlord Lenny Landlord'));
        $this->assertSame('landlord', LeaseBreach::withoutGlobalScopes()->first()->breach_party);
    }

    public function test_the_clause_and_reason_are_required_and_a_placeholder_still_empty_stops_the_send_and_the_preview_names_it(): void
    {
        $this->template('breach_other', '<p>Dear {{tenant_name}}, {{clause}}: {{reason}}. Deadline {{cure_date}}.</p>');

        $this->oneOff(['clause' => '', 'reason' => ''])->assertSessionHasErrors(['clause', 'reason']);

        $this->oneOff()->assertSessionHasErrors('figures_raw');
        $this->assertSame(0, LeaseBreach::withoutGlobalScopes()->count(), 'nothing is saved when the notice cannot be filled');
        Mail::assertNothingSent();

        $this->oneOff(['intent' => 'preview'])->assertSessionHasNoErrors();
        $preview = session('breach_preview');
        $this->assertSame(['cure_date'], $preview['missing']);
        $this->assertStringContainsString('The pool has not been maintained', $preview['html']);
        $this->assertSame(0, LeaseBreach::withoutGlobalScopes()->count());

        $this->oneOff(['figures_raw' => 'cure_date=1 December 2026'])->assertSessionHasNoErrors();
        $this->assertSame(1, LeaseBreach::withoutGlobalScopes()->count());
    }

    public function test_a_lease_that_is_not_running_takes_no_breach(): void
    {
        $this->template('breach_other');
        $this->lease->forceFill(['status' => 'draft'])->save();

        $this->oneOff()->assertSessionHasErrors('lease');
        $this->assertSame(0, LeaseBreach::withoutGlobalScopes()->count());
    }

    // ── 3. remedy window, remedy, notice to vacate ─────────────────────────

    private function issuePayment(string $amount = '1500'): LeaseBreach
    {
        $tpl = RentalNoticeTemplate::withoutGlobalScopes()->where('notice_type', 'breach_payment')->first() ?? $this->template('breach_payment');
        $this->actingAs($this->agent)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => $amount]])->assertOk();
        Mail::fake();

        return LeaseBreach::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_the_remedy_window_is_the_agencys_setting(): void
    {
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['breach_remedy_business_days' => 10]);
        $breach = $this->issuePayment();

        $this->assertSame(10, (int) $breach->remedy_business_days);
        $this->assertSame(app(LeaseNoticeReviewService::class)->addBusinessDays(Carbon::today(), 10)->toDateString(), $breach->remedy_deadline->toDateString());
        $this->assertSame(20, LeaseSetting::breachRemedyBusinessDaysFor(null), 'the default');
    }

    public function test_notice_to_vacate_is_not_offered_before_the_remedy_deadline_and_is_refused(): void
    {
        $this->template('notice_to_vacate');
        $breach = $this->issuePayment();

        $html = $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="vacate-not-yet"', $html);
        $this->assertStringContainsString($breach->remedy_deadline->format('j F Y'), $html);
        $this->assertStringNotContainsString('data-qa="vacate-form"', $html);

        $tplId = RentalNoticeTemplate::withoutGlobalScopes()->where('notice_type', 'notice_to_vacate')->value('id');
        $this->actingAs($this->agent)->post(route('corex.breaches.vacate', $breach->id), ['rental_notice_template_id' => $tplId, 'intent' => 'send'])->assertSessionHasErrors('breach');
        $this->assertSame('issued', $breach->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_notice_to_vacate_after_the_deadline_goes_to_the_tenant_tells_the_landlord_and_escalates_the_breach(): void
    {
        $vacate = $this->template('notice_to_vacate');
        $breach = $this->issuePayment();
        $breach->forceFill(['remedy_deadline' => Carbon::today()->subDay()->toDateString()])->save();

        $html = $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->getContent();
        $this->assertStringContainsString('data-qa="vacate-form"', $html);

        $this->actingAs($this->agent)->post(route('corex.breaches.vacate', $breach->id), ['rental_notice_template_id' => $vacate->id, 'intent' => 'send'])->assertSessionHasNoErrors();

        $fresh = $breach->fresh();
        $this->assertSame('escalated', $fresh->status);
        $this->assertNotNull($fresh->vacate_rental_notice_id);
        $this->assertSame('notice_to_vacate', $fresh->vacateNotice->notice_type);
        Mail::assertSent(LeaseBreachNoticeMail::class, fn ($m) => $m->hasTo($this->tenant->email) && str_contains((string) $m->envelope()->subject, 'Notice to vacate') && ! str_contains($m->render(), 'put this right by'));
        Mail::assertSent(LeaseBreachOtherPartyMail::class, fn (LeaseBreachOtherPartyMail $m) => $m->hasTo($this->landlord->email) && str_contains($m->render(), 'A notice to vacate has been issued against your tenant Tina Tenant'));
        $this->assertTrue(LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_VACATE_ISSUED)->exists());

        // and it cannot be issued twice
        $this->actingAs($this->agent)->post(route('corex.breaches.vacate', $breach->id), ['rental_notice_template_id' => $vacate->id, 'intent' => 'send'])->assertSessionHasErrors('breach');
    }

    public function test_a_remedied_breach_can_no_longer_be_escalated_and_remedy_is_logged_once(): void
    {
        $vacate = $this->template('notice_to_vacate');
        $breach = $this->issuePayment();
        $breach->forceFill(['remedy_deadline' => Carbon::today()->subDay()->toDateString()])->save();

        $this->actingAs($this->agent)->post(route('corex.breaches.remedy', $breach->id), ['note' => 'Paid in full'])->assertSessionHasNoErrors();

        $fresh = $breach->fresh();
        $this->assertSame('remedied', $fresh->status);
        $this->assertSame('Paid in full', $fresh->remedied_note);
        $this->assertSame($this->agent->id, $fresh->remedied_by_user_id);
        $this->assertTrue(LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_BREACH_REMEDIED)->where('description', 'like', '%Paid in full%')->exists());
        $this->actingAs($this->agent)->post(route('corex.breaches.vacate', $breach->id), ['rental_notice_template_id' => $vacate->id, 'intent' => 'send'])->assertSessionHasErrors('breach');
        $this->actingAs($this->agent)->post(route('corex.breaches.remedy', $breach->id), [])->assertSessionHasErrors('breach');
        $html = $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->getContent();
        $this->assertStringNotContainsString('data-qa="breach-actions"', $html);
    }

    public function test_a_landlord_breach_never_has_a_notice_to_vacate(): void
    {
        $vacate = $this->template('notice_to_vacate');
        $this->template('breach_other', '<p>{{clause}} {{reason}}</p>');
        $this->oneOff(['breach_party' => 'landlord'])->assertSessionHasNoErrors();
        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $breach->forceFill(['remedy_deadline' => Carbon::today()->subDay()->toDateString()])->save();

        $this->assertFalse($breach->fresh()->canIssueVacate());
        $this->actingAs($this->agent)->post(route('corex.breaches.vacate', $breach->id), ['rental_notice_template_id' => $vacate->id, 'intent' => 'send'])->assertSessionHasErrors('breach');
        $this->assertStringContainsString('only for a tenant who is in breach', $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->getContent());
    }

    public function test_with_no_vacate_template_the_screen_is_an_empty_state_linking_to_the_upload(): void
    {
        $breach = $this->issuePayment();
        $breach->forceFill(['remedy_deadline' => Carbon::today()->subDay()->toDateString()])->save();

        $html = $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->getContent();

        $this->assertStringContainsString('data-qa="no-templates"', $html);
        $this->assertStringContainsString(route('corex.rental-notice-templates.create', ['notice_type' => 'notice_to_vacate']), $html);
    }

    public function test_the_lease_screen_shows_the_breach_status_and_remedy_deadline_and_the_button(): void
    {
        $breach = $this->issuePayment();

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="lease-breach-panel"', $html);
        $this->assertStringContainsString('remedy by', $html);
        $this->assertStringContainsString($breach->remedy_deadline->format('j M Y'), $html);
        $this->assertStringContainsString('data-qa="lease-breach-button"', $html);
    }

    public function test_the_tenancy_log_shows_every_step(): void
    {
        $vacate = $this->template('notice_to_vacate');
        $breach = $this->issuePayment();
        $breach->forceFill(['remedy_deadline' => Carbon::today()->subDay()->toDateString()])->save();
        $this->actingAs($this->agent)->post(route('corex.breaches.vacate', $breach->id), ['rental_notice_template_id' => $vacate->id, 'intent' => 'send']);

        $lines = app(\App\Services\Rentals\LeaseTimelineService::class)->allEntriesFor($this->lease->fresh())->pluck('description')->implode(' | ');
        $this->assertStringContainsString('Payment breach notice issued to the tenant for R 1 500.00', $lines);
        $this->assertStringContainsString('other party told', $lines);
        $this->assertStringContainsString('Notice to vacate issued to the tenant', $lines);
    }

    public function test_a_user_without_the_permission_can_neither_issue_nor_open(): void
    {
        $tpl = $this->template('breach_payment');
        $plain = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'plain_breach']);
        RolePermission::create(['role' => 'plain_breach', 'permission_key' => 'leases.view', 'scope' => 'all']);
        PermissionService::clearCache();

        $this->actingAs($plain)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => '900']])->assertForbidden();
        $this->actingAs($plain)->get(route('corex.breaches.index'))->assertForbidden();
        $this->actingAs($plain)->get(route('corex.leases.breaches.create', $this->lease))->assertForbidden();
        $this->assertSame(0, LeaseBreach::withoutGlobalScopes()->count());
    }
}
