<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Events\RentalApplication\RentalApplicationApproved;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-482 - approving an application sets the upper rent of the applicant's active rental Core Matches to the approved
 * amount (floor), agency-scoped, never undoing the approval; the model cap still stops a later edit going above it.
 * Spec: .ai/specs/rental-applications.md (AT-482).
 */
final class RentalApplicationApprovalSetsCoreMatchCeilingAt482Test extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Test Agency', 'slug' => 'ta-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function match(array $attrs = []): ContactMatch
    {
        return ContactMatch::create(array_merge([
            'agency_id' => $this->agency->id, 'contact_id' => $this->contact->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'name' => 'Rental search', 'listing_type' => 'rental',
            'status' => ContactMatch::STATUS_ACTIVE,
        ], $attrs));
    }

    private function approved(float $amount = 10500.90, array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'approved', 'approved_rental_amount' => $amount,
        ], $attrs));
    }

    public function test_every_active_rental_match_gets_the_approved_amount_as_its_upper_rent(): void
    {
        $empty = $this->match();
        $higher = $this->match(['price_max' => 25000]);
        $lower = $this->match(['price_max' => 6000]);
        $legacy = $this->match(['listing_type' => 'to_let', 'price_max' => 30000]);
        $app = $this->approved(10500.90);

        event(new RentalApplicationApproved($app, $this->agent->id));

        foreach ([$empty, $higher, $lower, $legacy] as $m) {
            $this->assertSame(10500, (int) $m->fresh()->price_max, 'match ' . $m->id);
        }
        $log = RentalApplicationAuditLog::where('rental_application_id', $app->id)->where('event_type', 'rental_ceiling_set')->first();
        $this->assertNotNull($log, 'the change is written to the audit trail');
        $this->assertStringContainsString('10 500', (string) $log->human_summary);
    }

    public function test_sale_paused_and_archived_matches_and_other_contacts_are_left_alone(): void
    {
        $sale = $this->match(['listing_type' => 'sale', 'price_max' => 2000000]);
        $paused = $this->match(['status' => ContactMatch::STATUS_PAUSED, 'price_max' => 22000]);
        $archived = $this->match(['price_max' => 22000]);
        $archived->delete();
        $other = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Other', 'last_name' => 'Person', 'email' => 'o@example.co.za']);
        $othersMatch = $this->match(['contact_id' => $other->id, 'price_max' => 22000]);

        event(new RentalApplicationApproved($this->approved(10000), $this->agent->id));

        $this->assertSame(2000000, (int) $sale->fresh()->price_max);
        $this->assertSame(22000, (int) $paused->fresh()->price_max);
        $this->assertSame(22000, (int) ContactMatch::withTrashed()->find($archived->id)->price_max);
        $this->assertSame(22000, (int) $othersMatch->fresh()->price_max);
    }

    public function test_a_match_in_another_agency_is_never_touched(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $foreign = ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $otherAgency->id, 'contact_id' => $this->contact->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'name' => 'Foreign', 'listing_type' => 'rental',
            'status' => ContactMatch::STATUS_ACTIVE, 'price_max' => 22000,
        ]);

        event(new RentalApplicationApproved($this->approved(10000), $this->agent->id));

        $this->assertSame(22000, (int) ContactMatch::withoutGlobalScopes()->find($foreign->id)->price_max);
    }

    public function test_declined_and_withdrawn_leave_the_matches_alone(): void
    {
        $m = $this->match(['price_max' => 22000]);
        $app = $this->approved(10000, ['status' => 'declined', 'approved_rental_amount' => null]);

        event(new \App\Events\RentalApplication\RentalApplicationDeclined($app, $this->agent->id));
        event(new \App\Events\RentalApplication\RentalApplicationWithdrawn($app, $this->agent->id));

        $this->assertSame(22000, (int) $m->fresh()->price_max);
    }

    public function test_a_later_edit_cannot_push_the_ceiling_above_the_approved_amount_even_for_a_legacy_type(): void
    {
        $this->approved(10000);
        $legacy = $this->match(['listing_type' => 'rent']);

        $legacy->price_max = 50000;
        $legacy->save();

        $this->assertSame(10000, (int) $legacy->fresh()->price_max);
    }

    public function test_a_failure_in_the_listener_never_undoes_the_approval(): void
    {
        $app = $this->approved(10000);
        $app->contact_id = 999999; // no such contact: nothing to update, must not throw
        $listener = new \App\Listeners\Contact\SetRentalCoreMatchCeilingOnApproval();

        $listener->handle(new RentalApplicationApproved($app, $this->agent->id));

        $this->assertSame('approved', $app->fresh()->status);
    }

    public function test_an_override_re_approval_for_a_new_amount_moves_the_ceiling(): void
    {
        $m = $this->match();
        $app = $this->approved(10000);
        event(new RentalApplicationApproved($app, $this->agent->id));
        $this->assertSame(10000, (int) $m->fresh()->price_max);

        $app->forceFill(['approved_rental_amount' => 8000])->save();
        event(new RentalApplicationApproved($app->fresh(), $this->agent->id));

        $this->assertSame(8000, (int) $m->fresh()->price_max);
    }
}
