<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalLeaseTemplate;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\LeaseAgreementDocumentValues;
use App\Services\Rentals\LeaseNoticeTermsService;
use App\Services\Rentals\RentalPortalFaqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §18 — the lease's own notice and early-cancellation terms: they start from the AGENCY's defaults, are
 * editable per lease (capture, renewal, lease screen, own permission, every change logged), feed the lease agreement document
 * and the portal FAQ from the SAME columns, and are back-filled onto old leases by a reversible, dry-run-first command.
 */
final class LeaseNoticeTermsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $rival;
    private Branch $branch;
    private User $admin;
    private Property $property;
    private Contact $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 09:00:00'));

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->rival = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        Branch::create(['agency_id' => $this->rival->id, 'name' => 'Karoo']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->property = $this->makeProperty($this->agency, $this->branch);
        $this->tenant = $this->makeContact($this->agency, 'Thandi');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function makeProperty(Agency $agency, Branch $branch): Property
    {
        return Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $this->admin->id ?? null,
            'title' => 'Unit ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function makeContact(Agency $agency, string $first): Contact
    {
        $branchId = Branch::withoutGlobalScopes()->where('agency_id', $agency->id)->value('id');

        return Contact::create(['agency_id' => $agency->id, 'branch_id' => $branchId, 'first_name' => $first, 'last_name' => 'Test', 'email' => strtolower($first) . uniqid() . '@example.test']);
    }

    private function lease(array $over = [], ?Agency $agency = null, ?Property $property = null): Lease
    {
        $agency ??= $this->agency;
        $property ??= $agency->id === $this->agency->id ? $this->property : $this->makeProperty($agency, Branch::withoutGlobalScopes()->where('agency_id', $agency->id)->first());
        $lease = Lease::create($over + [
            'agency_id' => $agency->id, 'branch_id' => $property->branch_id, 'property_id' => $property->id, 'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 9000, 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'source' => 'manual', 'created_by_user_id' => $this->admin->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->makeContact($agency, 'T' . uniqid())->id, 'is_primary' => true]);

        return $lease;
    }

    private function terms(Lease $lease): ?LeaseAgreementTerms
    {
        return LeaseAgreementTerms::withoutGlobalScopes()->where('lease_id', $lease->id)->first();
    }

    private function setting(array $attrs, ?Agency $agency = null): void
    {
        LeaseSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => ($agency ?? $this->agency)->id], $attrs);
    }

    private function payload(array $over = []): array
    {
        return $over + ['property_id' => $this->property->id, 'rental_amount' => '8500', 'start_date' => '2026-11-01', 'tenant_contact_ids' => [$this->tenant->id]];
    }

    private function userWith(array $permissions): User
    {
        static $n = 0;
        $role = 'nt_role_' . (++$n);
        Role::create(['name' => $role, 'label' => $role, 'agency_id' => $this->agency->id]);
        foreach ($permissions as $key) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true]);
    }

    private function svc(): LeaseNoticeTermsService
    {
        return app(LeaseNoticeTermsService::class);
    }

    // ── the agency's defaults ───────────────────────────────────────────────

    public function test_a_new_lease_starts_with_the_agencys_default_notice_terms_and_says_where_they_came_from(): void
    {
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload())->assertSessionHasNoErrors();

        $lease = Lease::withoutGlobalScopes()->latest('id')->first();
        $t = $this->terms($lease);
        $this->assertSame(30, $t->notice_period);
        $this->assertSame('days', $t->notice_period_unit);
        $this->assertSame('yes', $t->early_cancellation_allowed);
        $this->assertSame(30, $t->early_cancellation_notice, 'the same as the ordinary notice when the agency set none of its own');
        $this->assertNull($t->earliest_notice_date, 'no "not before" rule by default');
        $this->assertNull($t->early_cancellation_penalty);
        $this->assertSame('agency_default', $t->notice_terms_source);

        $created = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_CREATED)->first();
        $this->assertSame('agency_default', $created->metadata['notice_terms']['source']);
        $this->assertSame(30, $created->metadata['notice_terms']['notice_period']);
    }

    public function test_the_agencys_own_settings_decide_the_defaults_nothing_is_hardcoded(): void
    {
        $this->setting([
            'tenant_notice_period_days' => 2, 'tenant_notice_period_unit' => 'months', 'default_earliest_notice_months' => 3,
            'default_early_cancellation_allowed' => 'yes', 'default_early_cancellation_notice' => 20, 'default_early_cancellation_notice_unit' => 'weeks',
            'default_early_cancellation_penalty' => 'One month rent',
        ]);

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['start_date' => '2026-11-01']))->assertSessionHasNoErrors();

        $t = $this->terms(Lease::withoutGlobalScopes()->latest('id')->first());
        $this->assertSame([2, 'months'], [$t->notice_period, $t->notice_period_unit]);
        $this->assertSame('2027-02-01', $t->earliest_notice_date->toDateString(), 'start date + 3 months');
        $this->assertSame([20, 'weeks'], [$t->early_cancellation_notice, $t->early_cancellation_notice_unit]);
        $this->assertSame('One month rent', $t->early_cancellation_penalty);
    }

    public function test_another_agencys_defaults_never_leak(): void
    {
        $this->setting(['tenant_notice_period_days' => 90, 'default_early_cancellation_allowed' => 'no'], $this->rival);

        $defaults = $this->svc()->defaultsFor($this->agency->id, Carbon::parse('2026-11-01'));

        $this->assertSame(30, $defaults['notice_period']);
        $this->assertSame('yes', $defaults['early_cancellation_allowed']);
        $this->assertSame(90, $this->svc()->defaultsFor($this->rival->id)['notice_period']);
        $this->assertNull($this->svc()->defaultsFor($this->rival->id)['early_cancellation_notice'], 'early cancellation "no" carries no notice');
    }

    // ── the capture screen ──────────────────────────────────────────────────

    public function test_the_capture_screen_shows_the_notice_block_with_the_defaults_filled_in(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="lease-notice-terms"', $html);
        $this->assertStringContainsString('name="notice[notice_period]"', $html);
        $this->assertStringContainsString('name="notice[early_cancellation_allowed]"', $html);
        $this->assertStringContainsString('name="notice[earliest_notice_date]"', $html);
        $this->assertStringContainsString('From your agency defaults', $html);
        $this->assertMatchesRegularExpression('/name="notice\[notice_period\]"[^>]*value="30"/', $html);
        $this->assertStringContainsString('name="notice[earliest_termination_date]"', $html, 'shown here when no linked agreement already asks for it');
    }

    public function test_what_the_agent_types_is_what_the_lease_gets(): void
    {
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload([
            'notice' => [
                'notice_period' => '60', 'notice_period_unit' => 'days', 'earliest_notice_date' => '2027-02-01',
                'earliest_termination_date' => '2027-04-30', 'early_cancellation_allowed' => 'yes',
                'early_cancellation_notice' => '20', 'early_cancellation_notice_unit' => 'days', 'early_cancellation_penalty' => 'Two months rent',
            ],
        ]))->assertSessionHasNoErrors();

        $t = $this->terms(Lease::withoutGlobalScopes()->latest('id')->first());
        $this->assertSame([60, 'days'], [$t->notice_period, $t->notice_period_unit]);
        $this->assertSame('2027-02-01', $t->earliest_notice_date->toDateString());
        $this->assertSame('2027-04-30', $t->earliest_termination_date->toDateString());
        $this->assertSame('Two months rent', $t->early_cancellation_penalty);
        $this->assertSame('captured', $t->notice_terms_source);
    }

    public function test_a_length_without_a_unit_takes_the_agencys_unit_and_no_early_cancellation_carries_no_notice_or_penalty(): void
    {
        $this->setting(['tenant_notice_period_unit' => 'weeks']);

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload([
            'notice' => ['notice_period' => '4', 'early_cancellation_allowed' => 'no', 'early_cancellation_notice' => '10', 'early_cancellation_penalty' => 'ignored'],
        ]))->assertSessionHasNoErrors();

        $t = $this->terms(Lease::withoutGlobalScopes()->latest('id')->first());
        $this->assertSame([4, 'weeks'], [$t->notice_period, $t->notice_period_unit]);
        $this->assertSame('no', $t->early_cancellation_allowed);
        $this->assertNull($t->early_cancellation_notice);
        $this->assertNull($t->early_cancellation_penalty);
    }

    public function test_a_bad_unit_or_a_date_before_the_lease_starts_is_refused_in_words_and_nothing_is_created(): void
    {
        $before = Lease::withoutGlobalScopes()->count();

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['notice' => ['notice_period' => '30', 'notice_period_unit' => 'fortnights']]))
            ->assertSessionHasErrors('notice.notice_period_unit');
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['notice' => ['earliest_notice_date' => '2026-01-01']]))
            ->assertSessionHasErrors('notice.earliest_notice_date');
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['notice' => ['notice_period' => '0']]))
            ->assertSessionHasErrors('notice.notice_period');

        $this->assertSame($before, Lease::withoutGlobalScopes()->count());
    }

    public function test_a_renewal_starts_from_the_term_it_renews_with_dates_moved_to_the_new_start(): void
    {
        $current = $this->lease(['start_date' => '2025-11-01', 'end_date' => '2026-10-31']);
        $this->svc()->save($current, [
            'notice_period' => 2, 'notice_period_unit' => 'months', 'earliest_notice_date' => '2026-02-01', 'early_cancellation_allowed' => 'yes',
            'early_cancellation_notice' => 3, 'early_cancellation_notice_unit' => 'weeks', 'early_cancellation_penalty' => 'One month rent',
        ], $this->admin, 'captured');

        $html = $this->actingAs($this->admin)->get(route('corex.leases.renewal.create', $current))->assertOk()->getContent();
        $this->assertStringContainsString('From the term being renewed', $html);
        $this->assertMatchesRegularExpression('/name="notice\[notice_period\]"[^>]*value="2"/', $html);

        $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $current), [
            'rental_amount' => '9500', 'start_date' => '2026-11-01',
        ])->assertSessionHasNoErrors();

        $new = Lease::withoutGlobalScopes()->where('previous_lease_id', $current->id)->first();
        $t = $this->terms($new);
        $this->assertSame([2, 'months'], [$t->notice_period, $t->notice_period_unit]);
        $this->assertSame('2027-02-01', $t->earliest_notice_date->toDateString(), '1 Feb 2026 moved by the 365 days between the two starts (1 Nov 2025 -> 1 Nov 2026)');
        $this->assertSame('One month rent', $t->early_cancellation_penalty);
        $this->assertSame('carried_forward', $t->notice_terms_source);
    }

    public function test_a_renewal_of_a_lease_with_no_terms_takes_the_agency_defaults(): void
    {
        $current = $this->lease(['start_date' => '2025-11-01', 'end_date' => '2026-10-31']);
        $this->setting(['tenant_notice_period_days' => 45]);

        $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $current), ['rental_amount' => '9500', 'start_date' => '2026-11-01'])->assertSessionHasNoErrors();

        $t = $this->terms(Lease::withoutGlobalScopes()->where('previous_lease_id', $current->id)->first());
        $this->assertSame(45, $t->notice_period);
        $this->assertSame('agency_default', $t->notice_terms_source);
    }

    // ── the lease screen: editable per lease, own permission, every change logged ─────────────

    public function test_the_lease_screen_shows_a_lease_with_no_terms_as_not_on_record_and_one_with_terms_with_real_dates(): void
    {
        $lease = $this->lease();
        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="lease-notice-card"', $html);
        $this->assertStringContainsString('Not on record', $html);

        $this->svc()->save($lease, ['notice_period' => 30, 'notice_period_unit' => 'days', 'early_cancellation_allowed' => 'yes', 'early_cancellation_notice' => 20, 'early_cancellation_notice_unit' => 'days', 'earliest_termination_date' => '2027-02-28'], $this->admin, 'captured');
        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))->assertOk()->getContent();
        $this->assertStringContainsString('30 days', $html);
        $this->assertStringContainsString('2027-01-29', $html, 'earliest notice = 28 Feb 2027 less 30 days');
        $this->assertStringContainsString('2027-05-01', $html, 'notice to leave at the end date (31 May 2027 less 30 days)');
        $this->assertStringContainsString('allowed with 20 days notice', $html);
    }

    public function test_editing_the_terms_saves_logs_who_from_to_and_a_repeat_logs_nothing(): void
    {
        $lease = $this->lease();
        $this->svc()->save($lease, ['notice_period' => 30, 'notice_period_unit' => 'days'], $this->admin, 'captured');

        $this->actingAs($this->admin)->put(route('corex.leases.notice-terms.update', $lease), ['notice' => [
            'notice_period' => '2', 'notice_period_unit' => 'months', 'early_cancellation_allowed' => 'yes', 'early_cancellation_notice' => '20', 'early_cancellation_penalty' => 'One month rent',
        ]])->assertRedirect(route('corex.leases.show', $lease))->assertSessionHas('success', 'Notice terms updated.');

        $t = $this->terms($lease);
        $this->assertSame([2, 'months'], [$t->notice_period, $t->notice_period_unit]);
        $this->assertSame('edited', $t->notice_terms_source);

        $event = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_CHANGED)->latest('id')->first();
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertEquals(['from' => 30, 'to' => 2], $event->metadata['changes']['notice_period']);
        $this->assertEquals(['from' => 'days', 'to' => 'months'], $event->metadata['changes']['notice_period_unit']);
        $this->assertStringContainsString('Notice period: 30 → 2', $event->description);

        $count = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_CHANGED)->count();
        $this->actingAs($this->admin)->put(route('corex.leases.notice-terms.update', $lease), ['notice' => ['notice_period' => '2', 'notice_period_unit' => 'months']])
            ->assertSessionHas('success', 'The notice terms were already set that way.');
        $this->assertSame($count, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_CHANGED)->count());
    }

    public function test_an_edit_can_clear_a_term_and_the_cleared_value_is_logged(): void
    {
        $lease = $this->lease();
        $this->svc()->save($lease, ['early_cancellation_allowed' => 'yes', 'early_cancellation_penalty' => 'Fee'], $this->admin, 'captured');

        $this->actingAs($this->admin)->put(route('corex.leases.notice-terms.update', $lease), ['notice' => ['early_cancellation_penalty' => '']])->assertSessionHasNoErrors();

        $this->assertNull($this->terms($lease)->early_cancellation_penalty);
        $this->assertSame('yes', $this->terms($lease)->early_cancellation_allowed, 'a key not posted is left alone');
    }

    public function test_the_edit_needs_its_own_permission_and_the_lease_must_be_yours(): void
    {
        $lease = $this->lease();
        $without = $this->userWith(['leases.view', 'leases.create']);
        $this->actingAs($without)->put(route('corex.leases.notice-terms.update', $lease), ['notice' => ['notice_period' => '10']])->assertForbidden();
        $this->assertNull($this->terms($lease));

        $with = $this->userWith(['leases.view', 'lease_notice_terms.edit']);
        $this->actingAs($with)->put(route('corex.leases.notice-terms.update', $lease), ['notice' => ['notice_period' => '10']])->assertRedirect();
        $this->assertSame(10, $this->terms($lease)->notice_period);

        // another agency can neither see nor change this lease (404), and our admin cannot change theirs
        $rivalAdmin = User::factory()->create(['agency_id' => $this->rival->id, 'branch_id' => Branch::withoutGlobalScopes()->where('agency_id', $this->rival->id)->value('id'), 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($rivalAdmin)->put(route('corex.leases.notice-terms.update', $lease), ['notice' => ['notice_period' => '99']])->assertNotFound();
        $this->assertSame(10, $this->terms($lease)->notice_period);

        $foreign = $this->lease([], $this->rival);
        $this->actingAs($this->admin)->put(route('corex.leases.notice-terms.update', $foreign), ['notice' => ['notice_period' => '10']]);
        $this->assertNull($this->terms($foreign));
    }

    public function test_the_permission_is_a_role_manager_key_with_working_defaults(): void
    {
        $keys = collect(config('corex-permissions.permissions') ?? config('corex-permissions'))->flatten(1)->pluck('key')->filter()->all();
        $flat = [];
        array_walk_recursive($keys, function ($v) use (&$flat) { $flat[] = $v; });
        $all = json_encode(config('corex-permissions'));
        $this->assertStringContainsString('lease_notice_terms.edit', $all);
        $this->assertGreaterThanOrEqual(3, substr_count($all, 'lease_notice_terms.edit'), 'declared once and given to the branch-manager and agent defaults');
    }

    public function test_nothing_can_be_edited_while_the_agreement_is_out_for_signing(): void
    {
        $lease = $this->lease(['signing_status' => Lease::SIGNING_OUT_FOR_SIGNING]);

        $this->actingAs($this->admin)->put(route('corex.leases.notice-terms.update', $lease), ['notice' => ['notice_period' => '10']])
            ->assertSessionHasErrors('notice');

        $this->assertNull($this->terms($lease));
    }

    public function test_dates_out_of_order_are_refused_on_the_lease_screen(): void
    {
        $lease = $this->lease();

        $this->actingAs($this->admin)->put(route('corex.leases.notice-terms.update', $lease), ['notice' => ['earliest_notice_date' => '2026-01-01']])
            ->assertSessionHasErrors('notice.earliest_notice_date');
        $this->actingAs($this->admin)->put(route('corex.leases.notice-terms.update', $lease), ['notice' => ['earliest_notice_date' => '2027-03-01', 'earliest_termination_date' => '2027-02-01']])
            ->assertSessionHasErrors('notice.earliest_notice_date');
    }

    // ── one value: the lease agreement document and the portal read the same columns ──────────

    public function test_the_lease_agreement_document_is_filled_from_the_same_values_the_portal_reads(): void
    {
        $lease = $this->lease();
        $this->svc()->save($lease, [
            'notice_period' => 45, 'notice_period_unit' => 'days', 'earliest_notice_date' => '2026-12-01', 'early_cancellation_allowed' => 'yes',
            'early_cancellation_notice' => 20, 'early_cancellation_notice_unit' => 'days', 'early_cancellation_penalty' => 'Two months rent',
        ], $this->admin, 'captured');
        $agreement = new RentalLeaseTemplate(['field_map' => [
            'rent' => ['field' => 'rent'], 'start_date' => ['field' => 'start'], 'notice_period' => ['field' => 'np'], 'notice_period_unit' => ['field' => 'npu'],
            'earliest_notice_date' => ['field' => 'en'], 'early_cancellation_allowed' => ['field' => 'eca'], 'early_cancellation_notice' => ['field' => 'ecn'],
            'early_cancellation_penalty' => ['field' => 'ecp'],
        ]]);

        $doc = app(LeaseAgreementDocumentValues::class)->forLease($lease->fresh(), $agreement);

        $this->assertSame('45', $doc['notice_period']);
        $this->assertSame('days', $doc['notice_period_unit']);
        $this->assertSame('2026-12-01', $doc['earliest_notice_date']);
        $this->assertSame('yes', $doc['early_cancellation_allowed']);
        $this->assertSame('20', $doc['early_cancellation_notice']);
        $this->assertSame('Two months rent', $doc['early_cancellation_penalty']);

        $faq = app(RentalPortalFaqService::class)->values($lease->fresh(), $this->agency->id, 'tenant');
        $this->assertSame('45 days', $faq['notice_period'], 'the portal says what the document says');
        $this->assertSame('1 Dec 2026', $faq['earliest_notice_date']);
        $this->assertSame('20 days', $faq['early_cancellation_notice']);
        $this->assertSame('Two months rent', $faq['early_cancellation_penalty']);
    }

    public function test_the_registry_carries_the_notice_terms_in_their_own_group_and_the_map_panel_can_require_them(): void
    {
        $fields = config('lease-agreement-fields.fields');
        foreach (LeaseNoticeTermsService::KEYS as $key) {
            $this->assertSame('notice', $fields[$key]['group'], $key);
            $this->assertSame('terms', $fields[$key]['side']);
            $this->assertSame($key, $fields[$key]['column']);
        }
        $this->assertContains('notice', config('lease-agreement-fields.requirable_groups'));
    }

    // ── the portal FAQ answers with real dates worked out for that lease ──────────────────────

    public function test_the_faq_answers_with_the_dates_worked_out_for_that_lease(): void
    {
        $lease = $this->lease(['start_date' => '2026-06-01', 'end_date' => '2027-05-31']);
        $this->svc()->save($lease, [
            'notice_period' => 2, 'notice_period_unit' => 'months', 'earliest_termination_date' => '2027-02-28',
            'early_cancellation_allowed' => 'yes', 'early_cancellation_notice' => 20, 'early_cancellation_notice_unit' => 'days', 'early_cancellation_penalty' => 'One month rent',
        ], $this->admin, 'captured');

        $faq = app(RentalPortalFaqService::class)->forLease($lease->fresh(), 'tenant');

        $this->assertSame(['notice', 'early'], array_column($faq, 'key'));
        $a = $faq[0]['answer'];
        $this->assertStringContainsString('at least 2 months', $a);
        $this->assertStringContainsString('cannot end before 28 Feb 2027', $a);
        $this->assertStringContainsString('give notice from 28 Dec 2026', $a, '28 Feb 2027 less 2 months');
        $this->assertStringContainsString('runs until 31 May 2027', $a);
        $this->assertStringContainsString('give notice by 31 Mar 2027', $a, '31 May 2027 less 2 months');
        $b = $faq[1]['answer'];
        $this->assertStringContainsString('You may cancel early by giving 20 days written notice.', $b);
        $this->assertStringContainsString('A penalty applies: One month rent', $b);
    }

    public function test_notice_that_can_already_be_given_says_now_and_a_passed_notice_by_date_is_not_printed(): void
    {
        $lease = $this->lease(['start_date' => '2026-06-01', 'end_date' => '2026-11-15']);
        $this->svc()->save($lease, ['notice_period' => 60, 'notice_period_unit' => 'days', 'earliest_notice_date' => '2026-08-01'], $this->admin, 'captured');

        $a = app(RentalPortalFaqService::class)->forLease($lease->fresh(), 'tenant')[0]['answer'];

        $this->assertStringContainsString('You can give notice now.', $a);
        $this->assertStringNotContainsString('give notice by', $a, '16 Sep 2026 is behind us');
    }

    public function test_no_early_cancellation_is_stated_in_the_agencys_own_words(): void
    {
        $lease = $this->lease();
        $this->svc()->save($lease, ['notice_period' => 30, 'notice_period_unit' => 'days', 'earliest_termination_date' => '2027-02-28', 'early_cancellation_allowed' => 'no'], $this->admin, 'captured');
        \App\Models\RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['faq_tenant_cancel_no' => 'Hierdie huurkontrak laat nie vroeë kansellasie toe nie.']);

        $b = app(RentalPortalFaqService::class)->forLease($lease->fresh(), 'tenant')[1]['answer'];

        $this->assertStringContainsString('Hierdie huurkontrak laat nie vroeë kansellasie toe nie.', $b);
        $this->assertStringNotContainsString('{', $b);
    }

    public function test_the_owner_reads_the_owner_wording_for_the_same_values(): void
    {
        $lease = $this->lease();
        $this->svc()->save($lease, ['notice_period' => 30, 'notice_period_unit' => 'days', 'early_cancellation_allowed' => 'yes', 'early_cancellation_notice' => 20, 'early_cancellation_notice_unit' => 'days'], $this->admin, 'captured');

        $faq = app(RentalPortalFaqService::class)->forLease($lease->fresh(), 'landlord');

        $this->assertSame('Can the tenant give notice?', $faq[0]['question']);
        $this->assertStringContainsString('The tenant may cancel early by giving 20 days written notice.', $faq[1]['answer'] ?? '');
    }

    public function test_a_lease_with_no_terms_still_shows_no_faq(): void
    {
        $this->setting(['tenant_notice_period_days' => 30]);

        $this->assertSame([], app(RentalPortalFaqService::class)->forLease($this->lease(), 'tenant'));
    }

    // ── the back-fill: dry-run first, reversible, logged ─────────────────────────────────────

    public function test_the_dry_run_counts_and_writes_nothing(): void
    {
        $this->lease();
        $this->lease(['status' => Lease::STATUS_CANCELLED]);
        $own = $this->lease();
        $this->svc()->save($own, ['notice_period' => 90, 'notice_period_unit' => 'days'], $this->admin, 'captured');

        $this->artisan('leases:backfill-notice-terms', ['--dry-run' => true, '--agency' => $this->agency->id])
            ->expectsOutputToContain('DRY RUN')->assertExitCode(0);

        $this->assertSame(1, LeaseAgreementTerms::withoutGlobalScopes()->count(), 'only the lease that already had terms has a row');
        $this->assertSame(0, LeaseEvent::where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_BACKFILLED)->count());
    }

    public function test_the_backfill_fills_only_leases_without_terms_from_that_agencys_defaults_and_logs_it(): void
    {
        $this->setting(['tenant_notice_period_days' => 60]);
        $bare = $this->lease();
        $withEarliestOnly = $this->lease();
        LeaseAgreementTerms::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'lease_id' => $withEarliestOnly->id, 'earliest_termination_date' => '2027-02-28', 'source' => 'captured']);
        $own = $this->lease();
        $this->svc()->save($own, ['notice_period' => 90, 'notice_period_unit' => 'days'], $this->admin, 'captured');
        $cancelled = $this->lease(['status' => Lease::STATUS_CANCELLED]);
        $rivalLease = $this->lease([], $this->rival);

        $this->artisan('leases:backfill-notice-terms')->assertExitCode(0);

        $t = $this->terms($bare);
        $this->assertSame([60, 'days', 'yes'], [$t->notice_period, $t->notice_period_unit, $t->early_cancellation_allowed]);
        $this->assertSame('agency_default', $t->notice_terms_source);
        $this->assertSame(60, $this->terms($withEarliestOnly)->notice_period);
        $this->assertSame('2027-02-28', $this->terms($withEarliestOnly)->earliest_termination_date->toDateString(), "the agreement's own date is never touched");
        $this->assertSame(90, $this->terms($own)->notice_period, "a lease's own notice period is never overwritten");
        $this->assertNull($this->terms($cancelled));
        $this->assertSame(30, $this->terms($rivalLease)->notice_period, "the other agency got ITS defaults (the 30-day fall-back), not ours");

        $event = LeaseEvent::where('lease_id', $bare->id)->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_BACKFILLED)->first();
        $this->assertNotNull($event);
        $this->assertSame(60, $event->metadata['values']['notice_period']);

        // running it again fills nothing new
        $this->artisan('leases:backfill-notice-terms')->assertExitCode(0);
        $this->assertSame(1, LeaseEvent::where('lease_id', $bare->id)->where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_BACKFILLED)->count());
    }

    public function test_the_portal_faq_appears_once_the_backfill_has_run(): void
    {
        $lease = $this->lease();
        $this->assertSame([], app(RentalPortalFaqService::class)->forLease($lease, 'tenant'));

        $this->artisan('leases:backfill-notice-terms')->assertExitCode(0);

        $faq = app(RentalPortalFaqService::class)->forLease($lease->fresh(), 'tenant');
        $this->assertNotEmpty($faq);
        $this->assertStringContainsString('at least 30 days', $faq[0]['answer']);
        $this->assertStringContainsString('give notice by 1 May 2027', $faq[0]['answer'], '31 May 2027 less 30 days');
    }

    public function test_revert_removes_exactly_what_the_backfill_wrote_and_leaves_a_lease_changed_since(): void
    {
        $untouched = $this->lease();
        $edited = $this->lease();
        $this->artisan('leases:backfill-notice-terms')->assertExitCode(0);
        $this->actingAs($this->admin)->put(route('corex.leases.notice-terms.update', $edited), ['notice' => ['notice_period' => '75']])->assertRedirect();

        $this->artisan('leases:backfill-notice-terms', ['--revert' => true, '--dry-run' => true])->expectsOutputToContain('Reverted 1')->assertExitCode(0);
        $this->assertSame(30, $this->terms($untouched)->notice_period, 'a dry-run revert changes nothing');

        $this->artisan('leases:backfill-notice-terms', ['--revert' => true])->assertExitCode(0);

        $this->assertNull($this->terms($untouched)->notice_period);
        $this->assertNull($this->terms($untouched)->early_cancellation_allowed);
        $this->assertNull($this->terms($untouched)->notice_terms_source);
        $this->assertSame(75, $this->terms($edited)->notice_period, 'changed after the back-fill: stays as chosen');
        $this->assertSame(2, LeaseEvent::where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_BACKFILLED)->where('lease_id', $untouched->id)->count(), 'the back-fill and its reversal are both on record');

        $this->artisan('leases:backfill-notice-terms', ['--revert' => true])->assertExitCode(0);
        $this->assertSame(2, LeaseEvent::where('event_type', LeaseEvent::TYPE_NOTICE_TERMS_BACKFILLED)->where('lease_id', $untouched->id)->count(), 'a second revert does nothing');
    }

    // ── the agency's defaults: settings page + wizard ────────────────────────

    public function test_the_lease_settings_saver_is_bounded_and_leaves_absent_fields_alone(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => '60', 'tenant_notice_period_days' => '2', 'tenant_notice_period_unit' => 'months',
            'default_earliest_notice_months' => '3', 'default_early_cancellation_allowed' => 'no', 'default_early_cancellation_penalty' => ' One month ',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, LeaseSetting::tenantNoticePeriodDaysFor($this->agency->id));
        $this->assertSame('months', LeaseSetting::tenantNoticePeriodUnitFor($this->agency->id));
        $this->assertSame(3, LeaseSetting::earliestNoticeMonthsFor($this->agency->id));
        $this->assertSame('no', LeaseSetting::earlyCancellationAllowedFor($this->agency->id));
        $this->assertSame('One month', LeaseSetting::earlyCancellationPenaltyFor($this->agency->id));

        // a post that carries none of the new fields (an older wizard render) leaves them exactly as they are
        $this->actingAs($this->admin)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => '45'])->assertSessionHasNoErrors();
        $this->assertSame('months', LeaseSetting::tenantNoticePeriodUnitFor($this->agency->id));
        $this->assertSame('no', LeaseSetting::earlyCancellationAllowedFor($this->agency->id));

        $this->actingAs($this->admin)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => '45', 'tenant_notice_period_unit' => 'years'])->assertSessionHasErrors('tenant_notice_period_unit');
        $this->actingAs($this->admin)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => '45', 'default_earliest_notice_months' => '0'])->assertSessionHasNoErrors();
        $this->assertNull(LeaseSetting::earliestNoticeMonthsFor($this->agency->id), '0 / blank = no rule');
    }

    public function test_every_new_default_reaches_the_settings_page_and_the_setup_wizard(): void
    {
        $page = $this->actingAs($this->admin)->get(route('corex.settings.leases.edit'))->assertOk()->getContent();
        foreach (['tenant_notice_period_unit', 'default_earliest_notice_months', 'default_early_cancellation_allowed', 'default_early_cancellation_notice', 'default_early_cancellation_notice_unit', 'default_early_cancellation_penalty'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $page, $field);
        }

        $keys = [];
        $all = config('agency-onboarding-copy');
        array_walk_recursive($all, function ($v, $k) use (&$keys) { if ($k === 'key') { $keys[] = $v; } });
        foreach (['tenant_notice_period_unit', 'default_earliest_notice_months', 'default_early_cancellation_allowed', 'default_early_cancellation_notice', 'default_early_cancellation_notice_unit', 'default_early_cancellation_penalty', 'faq_tenant_cancel_yes', 'faq_tenant_cancel_no', 'faq_landlord_cancel_yes', 'faq_landlord_cancel_no'] as $key) {
            $this->assertContains($key, $keys, "{$key} reaches the Setup Wizard (non-negotiable #10a)");
        }
    }
}
