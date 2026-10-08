<?php

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\LeaseNoticeTermsService;
use App\Services\Rentals\RentalCommandCentreService;
use App\Services\Rentals\RentalPortalFaqService;
use App\Services\WebTemplateFieldPartyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §18.7 / §18.8 — notice terms reach a tenant or owner only once an agent has confirmed them against the
 * signed lease; the "to check" list (lease list filter + needs-action queue) and the one-click Confirm terms; a signed lease
 * is edited only with a reason; the shipped lease agreement prints the terms and the merge-field picker lists them.
 */
class LeaseNoticeTermsConfirmTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $rival;
    private Branch $branch;
    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 09:00:00'));

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->rival = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        Branch::create(['agency_id' => $this->rival->id, 'name' => 'Karoo']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->property = $this->makeProperty($this->agency);
    }

    private function makeProperty(Agency $agency): Property
    {
        $branchId = Branch::withoutGlobalScopes()->where('agency_id', $agency->id)->value('id');

        return Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branchId, 'agent_id' => $this->admin->id ?? null,
            'title' => 'Unit ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function lease(array $over = [], ?Agency $agency = null): Lease
    {
        $agency ??= $this->agency;
        $property = $agency->id === $this->agency->id ? $this->makeProperty($agency) : $this->makeProperty($agency);
        $lease = Lease::create($over + [
            'agency_id' => $agency->id, 'branch_id' => $property->branch_id, 'property_id' => $property->id, 'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 9000, 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'source' => 'manual', 'created_by_user_id' => $this->admin->id,
        ]);
        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $property->branch_id, 'first_name' => 'T' . uniqid(), 'last_name' => 'Test', 'email' => uniqid() . '@example.test']);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        return $lease;
    }

    private function terms(Lease $lease): ?LeaseAgreementTerms
    {
        return LeaseAgreementTerms::withoutGlobalScopes()->where('lease_id', $lease->id)->first();
    }

    private function svc(): LeaseNoticeTermsService
    {
        return app(LeaseNoticeTermsService::class);
    }

    private function faq(Lease $lease, string $who = 'tenant'): array
    {
        return app(RentalPortalFaqService::class)->forLease($lease->fresh(), $who);
    }

    private function backfilled(): Lease
    {
        $lease = $this->lease();
        $this->artisan('leases:backfill-notice-terms', ['--agency' => $this->agency->id])->assertExitCode(0);

        return $lease->fresh();
    }

    private function userWith(array $permissions): User
    {
        static $n = 0;
        $role = 'cf_role_' . (++$n);
        Role::create(['name' => $role, 'label' => $role, 'agency_id' => $this->agency->id]);
        foreach ($permissions as $key) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true]);
    }

    // ── 1. nothing unconfirmed reaches the portal ────────────────────────────────────────

    public function test_terms_an_agent_saved_or_captured_are_confirmed_and_a_default_fill_is_not(): void
    {
        $edited = $this->lease();
        $this->svc()->save($edited, ['notice_period' => 60, 'notice_period_unit' => 'days'], $this->admin, 'edited');
        $this->assertNotNull($this->terms($edited)->notice_terms_confirmed_at);
        $this->assertSame($this->admin->id, (int) $this->terms($edited)->notice_terms_confirmed_by);
        $this->assertNotEmpty($this->faq($edited));

        $filled = $this->backfilled();
        $this->assertNull($this->terms($filled)->notice_terms_confirmed_at);
        $this->assertSame([], $this->faq($filled), 'agency-default terms are not stated to a tenant or owner');
        $this->assertSame([], $this->faq($filled, 'landlord'));
    }

    public function test_a_capture_confirms_the_terms_the_agent_was_shown(): void
    {
        $tenant = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Test', 'email' => 't' . uniqid() . '@example.test']);
        $property = $this->makeProperty($this->agency);
        $this->actingAs($this->admin)->post(route('corex.leases.store'), [
            'property_id' => $property->id, 'rental_amount' => '8500', 'start_date' => '2026-11-01', 'tenant_contact_ids' => [$tenant->id],
        ])->assertSessionHasNoErrors();

        $lease = Lease::withoutGlobalScopes()->where('property_id', $property->id)->firstOrFail();
        $this->assertNotNull($this->terms($lease)->notice_terms_confirmed_at, 'the agent captured it on the new-lease screen');
    }

    public function test_a_renewal_made_without_a_screen_carries_confirmation_only_from_a_confirmed_term(): void
    {
        $confirmed = $this->lease();
        $this->svc()->save($confirmed, ['notice_period' => 45, 'notice_period_unit' => 'days'], $this->admin, 'edited');
        [$values, $source] = $this->svc()->forRenewal($confirmed->fresh(), Carbon::parse('2027-06-01'));
        $next = $this->lease();
        $this->svc()->save($next, $values, $this->admin, $source, false, null, $source === 'carried_forward' && $this->svc()->isConfirmed($confirmed->fresh()));
        $this->assertNotNull($this->terms($next)->notice_terms_confirmed_at);

        $unconfirmed = $this->backfilled();
        [$values2, $source2] = $this->svc()->forRenewal($unconfirmed, Carbon::parse('2027-06-01'));
        $next2 = $this->lease();
        $this->svc()->save($next2, $values2, $this->admin, $source2, false, null, $source2 === 'carried_forward' && $this->svc()->isConfirmed($unconfirmed));
        $this->assertNull($this->terms($next2)->notice_terms_confirmed_at);
    }

    // ── 3. Confirm terms: one click, audited, permission via roles ───────────────────────

    public function test_confirm_terms_is_one_audited_click_and_the_portal_then_answers(): void
    {
        $lease = $this->backfilled();
        $this->assertSame([], $this->faq($lease));

        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="lease-notice-unconfirmed"', $html);
        $this->assertStringContainsString('data-qa="lease-notice-confirm"', $html);

        $this->actingAs($this->admin)->post(route('corex.leases.notice-terms.confirm', $lease))->assertRedirect(route('corex.leases.show', $lease))->assertSessionHas('success');

        $this->assertNotNull($this->terms($lease)->notice_terms_confirmed_at);
        $event = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_CONFIRMED)->get();
        $this->assertCount(1, $event);
        $this->assertSame($this->admin->id, (int) $event[0]->actor_user_id);
        $this->assertNotEmpty($this->faq($lease));

        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="lease-notice-confirmed"', $html);
        $this->assertStringNotContainsString('data-qa="lease-notice-confirm"', $html);

        // pressing it again changes and logs nothing
        $this->actingAs($this->admin)->post(route('corex.leases.notice-terms.confirm', $lease));
        $this->assertCount(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_CONFIRMED)->get());
    }

    public function test_confirming_needs_the_permission_and_stays_inside_the_agency(): void
    {
        $lease = $this->backfilled();

        $without = $this->userWith(['leases.view']);
        $this->actingAs($without)->post(route('corex.leases.notice-terms.confirm', $lease))->assertForbidden();
        $this->assertNull($this->terms($lease)->notice_terms_confirmed_at);

        $rivalAdmin = User::factory()->create(['agency_id' => $this->rival->id, 'branch_id' => Branch::withoutGlobalScopes()->where('agency_id', $this->rival->id)->value('id'), 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($rivalAdmin)->post(route('corex.leases.notice-terms.confirm', $lease))->assertNotFound();
        $this->assertNull($this->terms($lease)->notice_terms_confirmed_at);

        $with = $this->userWith(['leases.view', 'lease_notice_terms.edit']);
        $this->actingAs($with)->post(route('corex.leases.notice-terms.confirm', $lease))->assertRedirect();
        $this->assertNotNull($this->terms($lease)->notice_terms_confirmed_at);
    }

    public function test_a_lease_with_no_terms_has_nothing_to_confirm(): void
    {
        $lease = $this->lease();
        $this->assertFalse($this->svc()->confirm($lease, $this->admin));
        $this->assertSame(0, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_CONFIRMED)->count());
    }

    public function test_saving_the_same_values_does_not_count_as_confirming_them(): void
    {
        $lease = $this->backfilled();
        $same = $this->svc()->stored($this->terms($lease));
        $this->svc()->save($lease, array_filter($same, fn ($v) => $v !== null), $this->admin, 'edited');
        $this->assertNull($this->terms($lease)->notice_terms_confirmed_at, 'an edit that changes nothing is not a confirmation — press Confirm terms');
    }

    // ── 3. the "to check" list ──────────────────────────────────────────────────────

    public function test_the_lease_list_filter_shows_only_unconfirmed_leases_within_the_agency(): void
    {
        $todo = $this->backfilled();
        $none = $this->lease();
        $done = $this->lease();
        $this->svc()->save($done, ['notice_period' => 30, 'notice_period_unit' => 'days'], $this->admin, 'edited');
        $foreign = $this->lease([], $this->rival);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.index', ['notice_terms' => 'unconfirmed']))->assertOk()->getContent();
        $this->assertStringContainsString(route('corex.leases.show', $todo), $html);
        $this->assertStringContainsString(route('corex.leases.show', $none), $html, 'no terms at all is also "to check"');
        $this->assertStringNotContainsString(route('corex.leases.show', $done), $html);
        $this->assertStringNotContainsString(route('corex.leases.show', $foreign), $html);
        $this->assertStringContainsString('data-qa="lease-filter-notice-terms"', $html);

        $all = $this->actingAs($this->admin)->get(route('corex.leases.index'))->assertOk()->getContent();
        $this->assertStringContainsString(route('corex.leases.show', $done), $all);
    }

    public function test_the_needs_action_queue_lists_unconfirmed_active_leases_and_drops_them_once_confirmed(): void
    {
        $todo = $this->backfilled();
        $foreign = $this->lease([], $this->rival);
        $this->actingAs($this->admin);
        $rows = fn () => app(RentalCommandCentreService::class)->queueItems($this->admin, 'all')->filter(fn ($i) => $i['type'] === 'confirm_notice_terms');

        $ids = $rows()->map(fn ($i) => $i['lease']->id)->all();
        $this->assertContains($todo->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $row = $rows()->first(fn ($i) => $i['lease']->id === $todo->id);
        $this->assertSame('Confirm notice terms', $row['label']);
        $this->assertStringContainsString('agency defaults', $row['detail']);
        $this->assertSame('corex.leases.show', $row['route']);

        $this->svc()->confirm($todo, $this->admin);
        $this->assertNotContains($todo->id, $rows()->map(fn ($i) => $i['lease']->id)->all());

        $ended = $this->lease(['status' => Lease::STATUS_EXPIRED]);
        $this->assertNotContains($ended->id, $rows()->map(fn ($i) => $i['lease']->id)->all(), 'only active leases');
    }

    public function test_revert_leaves_a_backfilled_lease_an_agent_has_since_confirmed(): void
    {
        $lease = $this->backfilled();
        $this->svc()->confirm($lease, $this->admin);

        $this->artisan('leases:backfill-notice-terms', ['--revert' => true, '--agency' => $this->agency->id])->assertExitCode(0);

        $this->assertSame(30, $this->terms($lease)->notice_period, 'confirmed terms are the lease\'s own now');
        $this->assertNotNull($this->terms($lease)->notice_terms_confirmed_at);
    }

    // ── 5. editing a signed lease ────────────────────────────────────────────────────

    public function test_a_signed_lease_is_edited_only_with_a_reason_and_it_is_logged(): void
    {
        $lease = $this->lease(['signing_status' => Lease::SIGNING_SIGNED]);
        $this->svc()->save($lease, ['notice_period' => 30, 'notice_period_unit' => 'days'], $this->admin, 'edited');
        $url = route('corex.leases.notice-terms.update', $lease);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))->assertOk()->getContent();
        $this->assertStringContainsString('an addendum is needed', $html);
        $this->assertStringContainsString('data-qa="lease-notice-reason"', $html);

        $this->actingAs($this->admin)->put($url, ['notice' => ['notice_period' => '60']])->assertSessionHasErrors('notice_reason');
        $this->assertSame(30, $this->terms($lease)->notice_period, 'no silent edit');

        $this->actingAs($this->admin)->put($url, ['notice' => ['notice_period' => '60'], 'notice_reason' => '   '])->assertSessionHasErrors('notice_reason');
        $this->assertSame(30, $this->terms($lease)->notice_period);

        $this->actingAs($this->admin)->put($url, ['notice' => ['notice_period' => '60'], 'notice_reason' => 'Addendum signed 2 Oct 2026 extends the notice period'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(60, $this->terms($lease)->notice_period);

        $event = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_CHANGED)->orderByDesc('id')->first();
        $this->assertSame('Addendum signed 2 Oct 2026 extends the notice period', $event->metadata['reason']);
        $this->assertStringContainsString('reason: Addendum signed', $event->description);
        $this->assertStringContainsString('Notice period: 30 → 60', $event->description);
    }

    public function test_an_unsigned_lease_needs_no_reason(): void
    {
        $lease = $this->lease(['signing_status' => Lease::SIGNING_NOT_SENT]);
        $this->actingAs($this->admin)->put(route('corex.leases.notice-terms.update', $lease), ['notice' => ['notice_period' => '45', 'notice_period_unit' => 'days']])->assertSessionHasNoErrors();
        $this->assertSame(45, $this->terms($lease)->notice_period);
        $this->assertNull(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_CHANGED)->first()->metadata['reason']);
    }

    // ── 4. the shipped lease agreement and the merge-field picker ────────────────────────

    public function test_the_shipped_lease_agreement_prints_the_terms_and_prints_nothing_when_there_are_none(): void
    {
        $base = ['initialsParties' => []];
        $with = view('docuperfect.web-templates.lease-agreement-popi-v8', $base + [
            'notice_period' => '60', 'notice_period_unit' => 'days', 'earliest_notice_date' => '2026-12-01',
            'early_cancellation_allowed' => 'yes', 'early_cancellation_notice' => '20', 'early_cancellation_notice_unit' => 'days', 'early_cancellation_penalty' => 'One month rent',
        ])->render();
        foreach (['notice_period', 'notice_period_unit', 'earliest_notice_date', 'early_cancellation_allowed', 'early_cancellation_notice', 'early_cancellation_notice_unit', 'early_cancellation_penalty'] as $field) {
            $this->assertStringContainsString('data-field="' . $field . '"', $with, $field);
        }
        $this->assertStringContainsString('5.5 The notice and early cancellation terms recorded for this lease', $with);
        $this->assertStringContainsString('One month rent', $with);
        $this->assertStringContainsString('2026-12-01', $with);

        $without = view('docuperfect.web-templates.lease-agreement-popi-v8', $base)->render();
        $this->assertStringNotContainsString('5.5 The notice and early cancellation', $without);
        $this->assertStringContainsString('5.4 The lease and all provisions', $without, 'the rest of clause 5 is untouched');
    }

    public function test_the_merge_field_picker_lists_the_terms_and_a_signer_cannot_edit_them(): void
    {
        foreach (['notice_period', 'notice_period_unit', 'earliest_notice_date', 'early_cancellation_allowed', 'early_cancellation_notice', 'early_cancellation_notice_unit', 'early_cancellation_penalty'] as $column) {
            $row = DB::table('docuperfect_named_fields')->where('source_type', 'computed')->where('source_column', $column)->whereNull('deleted_at')->get();
            $this->assertCount(1, $row, "catalogue row for {$column}");
            $this->assertSame('system', WebTemplateFieldPartyMap::getPartyForField($column), "{$column} is system-filled");
        }
    }
}
