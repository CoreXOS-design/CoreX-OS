<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\LeaseActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * leases.md §3.8 — Archive and Restore for leases, and "the property's let status follows its active lease"
 * on archive, cancel, end and restore. Johan, QA1, 2026-10-07: lease 93 could not be archived and nothing reset
 * the property.
 */
final class LeaseArchiveRestoreTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'Archive Agency', 'slug' => 'arch-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function property(string $status = 'active'): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => 'Property ' . uniqid(), 'status' => $status, 'listing_type' => 'rental',
        ]);
    }

    private function lease(Property $property, string $status = Lease::STATUS_DRAFT, array $attrs = []): Lease
    {
        return Lease::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'property_id' => $property->id, 'status' => $status,
            'rental_amount' => 5000, 'start_date' => now()->subMonth(), 'source' => 'manual',
            'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    /** An active lease the way activation makes one — the property goes to "Let out", its old status remembered. */
    private function activeLease(Property $property): Lease
    {
        $lease = $this->lease($property);
        app(LeaseActivationService::class)->activate($lease);

        return $lease->fresh();
    }

    private function archive(Lease $lease, string $reason = 'Created by mistake')
    {
        return $this->actingAs($this->admin)->post(route('corex.leases.archive', $lease), ['archive_reason' => $reason]);
    }

    public function test_archiving_an_active_lease_cancels_hides_and_releases_the_property(): void
    {
        $property = $this->property('to_let');
        $lease = $this->activeLease($property);
        $this->assertSame('let_out', $property->fresh()->status);

        $this->archive($lease, 'Wrongly created')->assertRedirect(route('corex.leases.index'));

        $gone = Lease::withTrashed()->find($lease->id);
        $this->assertTrue($gone->trashed());
        $this->assertSame(Lease::STATUS_CANCELLED, $gone->status);
        $this->assertSame('active', $gone->archived_from_status);
        $this->assertSame('Wrongly created', $gone->archive_reason);
        $this->assertSame($this->admin->id, $gone->archived_by_user_id);
        $this->assertSame($this->admin->id, $gone->cancelled_by_user_id);
        $this->assertSame('to_let', $property->fresh()->status, 'the property goes back to what it was before it was let');
        $this->assertNull($property->fresh()->status_before_letting);

        $event = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_ARCHIVED)->first();
        $this->assertNotNull($event, 'archiving is written to the lease history');
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertStringContainsString('Wrongly created', $event->description);
    }

    public function test_archived_lease_is_hidden_from_the_default_list_and_shown_under_show_archived(): void
    {
        $live = $this->lease($this->property(), Lease::STATUS_DRAFT);
        $archived = $this->lease($this->property(), Lease::STATUS_DRAFT);
        $this->archive($archived);

        $default = $this->actingAs($this->admin)->get(route('corex.leases.index'));
        $this->assertSame([$live->id], $default->viewData('leases')->pluck('id')->all());

        $only = $this->actingAs($this->admin)->get(route('corex.leases.index', ['archived' => 1]));
        $this->assertSame([$archived->id], $only->viewData('leases')->pluck('id')->all());
        $only->assertSee('Created by mistake')->assertSee('data-test="lease-restore-button"', false);
    }

    public function test_a_reason_is_required(): void
    {
        $lease = $this->lease($this->property());

        $this->actingAs($this->admin)->post(route('corex.leases.archive', $lease), ['archive_reason' => ''])
            ->assertSessionHasErrors('archive_reason');

        $this->assertFalse($lease->fresh()->trashed());
    }

    public function test_every_status_can_be_archived_and_a_cancelled_lease_keeps_its_status(): void
    {
        foreach ([Lease::STATUS_CANCELLED, Lease::STATUS_EXPIRED] as $status) {
            $property = $this->property();
            $lease = $this->lease($property, $status);
            $this->archive($lease)->assertSessionHasNoErrors();

            $gone = Lease::withTrashed()->find($lease->id);
            $this->assertTrue($gone->trashed(), "a $status lease archives");
            $this->assertSame($status, $gone->status);
            $this->assertSame('active', $property->fresh()->status, 'a lease that was not active never touches the property');
        }
    }

    public function test_a_lease_with_escalation_history_can_be_archived_nothing_is_deleted(): void
    {
        $lease = $this->lease($this->property(), Lease::STATUS_CANCELLED);
        \App\Models\LeaseEscalation::create([
            'lease_id' => $lease->id, 'effective_date' => now()->toDateString(),
            'previous_rental_amount' => 5000, 'new_rental_amount' => 5400, 'escalation_rate_percent' => 8,
        ]);

        $this->archive($lease)->assertSessionHasNoErrors();

        $this->assertTrue(Lease::withTrashed()->find($lease->id)->trashed());
        $this->assertSame(1, \App\Models\LeaseEscalation::where('lease_id', $lease->id)->count());
    }

    public function test_the_property_stays_let_while_another_live_lease_is_still_active_on_it(): void
    {
        $property = $this->property('to_let');
        $first = $this->activeLease($property);
        // Bad legacy data: two active leases on one property.
        $second = $this->lease($property, Lease::STATUS_ACTIVE);

        $this->archive($first)->assertSessionHasNoErrors();

        $this->assertSame('let_out', $property->fresh()->status);
        $this->assertSame(Lease::STATUS_ACTIVE, $second->fresh()->status);
    }

    public function test_an_archived_lease_does_not_block_a_new_lease_on_the_property_or_for_the_same_tenant(): void
    {
        $property = $this->property();
        $tenant = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tess', 'last_name' => 'Tenant', 'email' => 't' . uniqid() . '@example.test']);
        $old = $this->activeLease($property);
        LeaseTenant::create(['lease_id' => $old->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $this->archive($old);

        $new = $this->lease($property);
        LeaseTenant::create(['lease_id' => $new->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        app(LeaseActivationService::class)->activate($new);

        $this->assertSame(Lease::STATUS_ACTIVE, $new->fresh()->status);
        $this->assertSame('let_out', $property->fresh()->status);
    }

    public function test_a_legacy_archived_active_lease_does_not_block_activation_either(): void
    {
        // Archived by the old bare soft-delete, status still "active".
        $property = $this->property();
        $ghost = $this->lease($property, Lease::STATUS_ACTIVE);
        $ghost->delete();

        $new = $this->lease($property);
        app(LeaseActivationService::class)->activate($new);

        $this->assertSame(Lease::STATUS_ACTIVE, $new->fresh()->status);
    }

    public function test_a_bare_delete_of_an_active_lease_releases_the_property_too(): void
    {
        $property = $this->property('to_let');
        $lease = $this->activeLease($property);

        $this->actingAs($this->admin)->delete(route('corex.leases.destroy', $lease))->assertRedirect();

        $this->assertSame(Lease::STATUS_CANCELLED, Lease::withTrashed()->find($lease->id)->status);
        $this->assertSame('to_let', $property->fresh()->status);
    }

    public function test_restoring_an_active_lease_brings_it_back_active_and_lets_the_property_out_again(): void
    {
        $property = $this->property('to_let');
        $lease = $this->activeLease($property);
        $this->archive($lease);

        $this->actingAs($this->admin)->post(route('corex.leases.restore', $lease->id))
            ->assertRedirect(route('corex.leases.show', $lease->id));

        $back = Lease::find($lease->id);
        $this->assertNotNull($back);
        $this->assertSame(Lease::STATUS_ACTIVE, $back->status);
        $this->assertNull($back->cancelled_at);
        $this->assertNull($back->cancel_reason);
        $this->assertNull($back->archive_reason);
        $this->assertNull($back->archived_from_status);
        $this->assertSame('let_out', $property->fresh()->status);
        $this->assertNotNull(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_RESTORED)->first());
    }

    public function test_restore_is_refused_when_another_lease_became_active_on_the_property(): void
    {
        $property = $this->property();
        $old = $this->activeLease($property);
        $this->archive($old);
        $replacement = $this->lease($property);
        app(LeaseActivationService::class)->activate($replacement);

        $this->actingAs($this->admin)->post(route('corex.leases.restore', $old->id))
            ->assertSessionHasErrors('lease')
            ->assertSessionHas('error');

        $this->assertTrue(Lease::withTrashed()->find($old->id)->trashed(), 'it stays archived');
        $this->assertSame(Lease::STATUS_ACTIVE, $replacement->fresh()->status);
        $this->assertSame('let_out', $property->fresh()->status);
    }

    public function test_restore_is_refused_while_the_property_is_archived(): void
    {
        $property = $this->property();
        $lease = $this->activeLease($property);
        $this->archive($lease);
        $property->delete();

        $this->expectException(ValidationException::class);
        app(\App\Services\Rentals\LeaseArchiveService::class)->restore(Lease::withTrashed()->find($lease->id), $this->admin);
    }

    public function test_a_draft_and_a_cancelled_lease_come_back_as_they_were(): void
    {
        $draft = $this->lease($this->property(), Lease::STATUS_DRAFT);
        $cancelled = $this->lease($this->property(), Lease::STATUS_CANCELLED, ['cancelled_at' => now(), 'cancel_reason' => 'Tenant withdrew']);
        $this->archive($draft);
        $this->archive($cancelled);

        $this->actingAs($this->admin)->post(route('corex.leases.restore', $draft->id));
        $this->actingAs($this->admin)->post(route('corex.leases.restore', $cancelled->id));

        $this->assertSame(Lease::STATUS_DRAFT, Lease::find($draft->id)->status);
        $this->assertNull(Lease::find($draft->id)->cancelled_at);
        $this->assertSame(Lease::STATUS_CANCELLED, Lease::find($cancelled->id)->status);
        $this->assertSame('Tenant withdrew', Lease::find($cancelled->id)->cancel_reason, 'a genuine cancel record is not wiped');
    }

    public function test_archiving_and_restoring_an_active_lease_needs_the_cancel_permission(): void
    {
        Role::create(['name' => 'clerk', 'label' => 'Clerk', 'agency_id' => $this->agency->id]);
        foreach (['leases.view', 'leases.create'] as $key) {
            RolePermission::updateOrCreate(['role' => 'clerk', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();
        $clerk = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'clerk']);

        $active = $this->activeLease($this->property());
        $this->actingAs($clerk)->post(route('corex.leases.archive', $active), ['archive_reason' => 'x'])->assertForbidden();
        $this->assertFalse($active->fresh()->trashed());

        // …but may archive a lease that is already over.
        $over = $this->lease($this->property(), Lease::STATUS_CANCELLED);
        $this->actingAs($clerk)->post(route('corex.leases.archive', $over), ['archive_reason' => 'tidy'])->assertRedirect();
        $this->assertTrue(Lease::withTrashed()->find($over->id)->trashed());
    }

    public function test_the_lease_screen_offers_archive_for_an_active_and_for_a_cancelled_lease(): void
    {
        $active = $this->activeLease($this->property());
        $cancelled = $this->lease($this->property(), Lease::STATUS_CANCELLED);

        $this->actingAs($this->admin)->get(route('corex.leases.show', $active))
            ->assertOk()->assertSee('data-test="lease-archive-menu-item"', false)->assertSee('Archive lease');
        $this->actingAs($this->admin)->get(route('corex.leases.show', $cancelled))
            ->assertOk()->assertSee('data-test="lease-archive-button"', false)->assertSee('Archive lease');
    }

    // ── The property's let status follows its active lease: cancel and end ──

    public function test_cancelling_an_active_lease_releases_the_property(): void
    {
        $property = $this->property('to_let');
        $lease = $this->activeLease($property);

        $this->actingAs($this->admin)->post(route('corex.leases.cancel', $lease), ['cancel_reason' => 'Test'])->assertRedirect();

        $this->assertSame('to_let', $property->fresh()->status);
    }

    public function test_cancel_never_leaves_the_property_stuck_on_let_out_when_its_old_status_is_not_usable(): void
    {
        $property = $this->property('to_let');
        $lease = $this->activeLease($property);
        // The remembered pre-let status is one the agency's vocabulary does not allow.
        $property->forceFill(['status_before_letting' => 'no_such_status'])->save();
        \App\Models\LeaseSetting::withoutGlobalScopes()->updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['default_pre_let_status' => 'also_not_a_status'],
        );

        $this->actingAs($this->admin)->post(route('corex.leases.cancel', $lease), ['cancel_reason' => 'Test'])->assertRedirect();

        $this->assertSame('active', $property->fresh()->status);
    }

    public function test_cancel_keeps_the_property_let_while_another_live_lease_is_active_on_it(): void
    {
        $property = $this->property('to_let');
        $first = $this->activeLease($property);
        $this->lease($property, Lease::STATUS_ACTIVE); // legacy duplicate

        $this->actingAs($this->admin)->post(route('corex.leases.cancel', $first), ['cancel_reason' => 'Test'])->assertRedirect();

        $this->assertSame('let_out', $property->fresh()->status);
    }
}
