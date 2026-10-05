<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-444/AT-441 follow-up (conductor, 2026-10-05) — the Lease Hub's "Lease
 * actions" menu items now open as modal dialogs (components/modal.blade.php)
 * instead of revealing a card far down the right-hand column. Covers:
 * ?action= opening the right dialog (and being ignored when invalid for the
 * lease's state), and a failed submission reopening the SAME dialog with
 * the entered values.
 */
final class LeaseActionDialogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_query_param_opens_the_matching_dialog(): void
    {
        [$lease, $user] = $this->makeActiveLeaseAndUser();

        $response = $this->actingAs($user)->get(route('corex.leases.show', ['lease' => $lease->id, 'action' => 'tenant-notice']));

        $response->assertOk();
        self::assertTrue($this->dialogIsOpen($response->getContent(), 'lease-dialog-tenant-notice'));
        self::assertFalse($this->dialogIsOpen($response->getContent(), 'lease-dialog-landlord-notice'));
        self::assertFalse($this->dialogIsOpen($response->getContent(), 'lease-dialog-renew'));
    }

    public function test_action_query_param_invalid_for_lease_state_is_ignored(): void
    {
        [$lease, $user] = $this->makeActiveLeaseAndUser(['is_month_to_month' => true]);

        // The lease is already month-to-month, so ?action=month-to-month
        // has nothing valid to open — the m2m dialog doesn't even render
        // (the "else" branch renders lease-dialog-reverse-m2m instead).
        $response = $this->actingAs($user)->get(route('corex.leases.show', ['lease' => $lease->id, 'action' => 'month-to-month']));

        $response->assertOk();
        $response->assertDontSee('lease-dialog-m2m', false);
        self::assertFalse($this->dialogIsOpen($response->getContent(), 'lease-dialog-reverse-m2m'));
    }

    public function test_action_query_param_is_ignored_on_a_draft_lease(): void
    {
        [$lease, $user] = $this->makeActiveLeaseAndUser(['status' => Lease::STATUS_DRAFT]);

        $response = $this->actingAs($user)->get(route('corex.leases.show', ['lease' => $lease->id, 'action' => 'renew']));

        $response->assertOk();
        // The renew dialog isn't even rendered for a non-active lease.
        $response->assertDontSee('lease-dialog-renew', false);
    }

    public function test_failed_tenant_notice_submission_reopens_the_same_dialog_with_entered_values(): void
    {
        [$lease, $user] = $this->makeActiveLeaseAndUser();

        // back()/the auto-flash ValidationException handler both redirect
        // to the HTTP referer — the test client sends none by default, so
        // from() is required or the redirect target isn't the Lease Hub.
        $response = $this->actingAs($user)->from(route('corex.leases.show', $lease))->followingRedirects()->post(
            route('corex.leases.renewal.tenant-notice', $lease),
            ['note' => 'Entered note text', '_lease_action' => 'tenant-notice'] // move_out_date deliberately omitted
        );

        $response->assertOk();
        self::assertTrue($this->dialogIsOpen($response->getContent(), 'lease-dialog-tenant-notice'));
        self::assertFalse($this->dialogIsOpen($response->getContent(), 'lease-dialog-landlord-notice'));
        $response->assertSee('Entered note text');
    }

    public function test_landlord_notice_validation_error_does_not_leak_old_values_into_tenant_notice_dialog(): void
    {
        [$lease, $user] = $this->makeActiveLeaseAndUser();

        $response = $this->actingAs($user)->from(route('corex.leases.show', $lease))->followingRedirects()->post(
            route('corex.leases.renewal.landlord-notice', $lease),
            ['note' => 'Landlord-side note', '_lease_action' => 'landlord-notice']
        );

        $response->assertOk();
        self::assertTrue($this->dialogIsOpen($response->getContent(), 'lease-dialog-landlord-notice'));
        self::assertFalse($this->dialogIsOpen($response->getContent(), 'lease-dialog-tenant-notice'));

        // Both forms have a field named "note" — the shared old('note')
        // value must land ONLY in the dialog that actually failed.
        $content = $response->getContent();
        $landlordBlock = $this->extractDialogBlock($content, 'lease-dialog-landlord-notice');
        $tenantBlock = $this->extractDialogBlock($content, 'lease-dialog-tenant-notice');
        self::assertStringContainsString('Landlord-side note', $landlordBlock);
        self::assertStringNotContainsString('Landlord-side note', $tenantBlock);
    }

    public function test_cancel_dialog_reopens_on_validation_error(): void
    {
        [$lease, $user] = $this->makeActiveLeaseAndUser();

        $response = $this->actingAs($user)->from(route('corex.leases.show', $lease))->followingRedirects()->post(
            route('corex.leases.cancel', $lease),
            [] // cancel_reason required, omitted deliberately
        );

        $response->assertOk();
        self::assertTrue($this->dialogIsOpen($response->getContent(), 'lease-dialog-cancel'));
    }

    /** @return array{0: Lease, 1: User} */
    private function makeActiveLeaseAndUser(array $leaseOverrides = []): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::create(array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 9000,
            'start_date' => now()->subMonth()->toDateString(),
            'source' => 'manual',
        ], $leaseOverrides));
        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Tenant', 'last_name' => uniqid(),
            'email' => 'tenant-' . uniqid() . '@example.test',
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        return [$lease, $agent];
    }

    /**
     * The modal component always renders every dialog's markup server-side
     * (Alpine toggles visibility client-side); the server-rendered signal
     * of :show is the `style="display: ...;"` on the SAME root <div> that
     * carries `x-on:open-modal.window="$event.detail == '<name>' ...`.
     */
    private function dialogIsOpen(string $html, string $name): bool
    {
        return str_contains($this->extractDialogBlock($html, $name), 'style="display: block;"');
    }

    /**
     * Isolates exactly ONE <x-modal>'s rendered HTML: from its own
     * open-modal marker through to (but not including) the NEXT dialog's
     * own marker — never a fixed byte length, which would either cut a
     * large dialog's form fields off or spill into the next dialog's
     * markup and produce a false "contains" match.
     */
    private function extractDialogBlock(string $html, string $name): string
    {
        $marker = "\$event.detail == '{$name}'";
        $start = strpos($html, $marker);
        if ($start === false) {
            return '';
        }

        // Every dialog emits the SAME marker text twice (open-modal, then
        // close-modal, adjacent lines) — skip past our own close-modal
        // line before looking for the next DIFFERENT dialog's marker.
        $ownCloseLine = strpos($html, $marker, $start + strlen($marker));
        $searchFrom = ($ownCloseLine !== false ? $ownCloseLine : $start) + strlen($marker);
        $nextDialogPos = strpos($html, "\$event.detail ==", $searchFrom);
        $end = $nextDialogPos !== false ? $nextDialogPos : strlen($html);

        return substr($html, $start, $end - $start);
    }
}
