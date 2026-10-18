<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan, 2026-09-22 (property 4283 / rental application 290) — "rent and
 * deposit on an approved rental application" §1, precedence ruling: the
 * approved application amount wins; the property is the fallback when the
 * application carries no approved amount. Same rule for both rent and
 * deposit.
 *
 * The actual precedence DECISION (which of the two values wins once a
 * property is picked) lives in view-readonly.blade.php's Alpine select()
 * handler — client-side JS this suite cannot exercise (Standard -1s, no
 * browser harness). What IS testable and load-bearing server-side is the
 * data select() is handed: the page's initial Alpine state
 * (approvedRentalAmount/approvedDepositAmount/rentalAmount/depositAmount/
 * rentalAmountSource/depositAmountSource), embedded via Js::from() in the
 * rendered HTML. RentalApplicationSearchPropertiesRentalDetailsTest already
 * covers the OTHER half (a picked property's own rental_amount/
 * deposit_amount arriving correctly via the search endpoint) — together
 * these two suites cover every value the client-side precedence combines;
 * only the combining logic itself is unverified here.
 */
final class RentalApplicationRentDepositPrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function contact(string $email): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => $email, 'phone' => '0821234567',
        ]);
    }

    /**
     * Johan, QA1, 2026-10-07 — SUPERSEDES the 2026-09-22 ruling this file was written for. The approved
     * application screen no longer asks for rent, deposit or dates at all: choosing the property opens the
     * lease screen (leases.md §15.3), which takes the terms pre-filled from the PROPERTY's rent. See
     * LeaseFromApprovalTest for the pre-fill and the above-approved policy.
     */
    public function test_view_readonly_no_longer_carries_lease_terms_and_leads_to_the_lease_screen(): void
    {
        $contact = $this->contact('tenant-approved@example.co.za');
        $app = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'approved',
            'approved_rental_amount' => 10000, 'approved_deposit_amount' => 10000,
        ]);

        $resp = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));

        $resp->assertOk();
        $resp->assertDontSee('name="rental_amount"', false);
        $resp->assertDontSee('name="lease_start_date"', false);
        $resp->assertSee('Continue to the lease');
    }

    private function authoriser(string $tier = 'ro'): User
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->agency->update([
            $tier === 'co' ? 'rental_application_co_user_ids' : 'rental_application_ro_user_ids' => [$user->id],
        ]);
        $this->agency->refresh();

        return $user;
    }

    public function test_approve_action_saves_the_optional_approved_deposit_amount(): void
    {
        $ro = $this->authoriser('ro');
        $contact = $this->contact('tenant-authorise@example.co.za');
        $app = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'under_assessment', 'submitted_for_approval_at' => now(),
        ]);

        $resp = $this->actingAs($ro)->post(
            route('corex.rental-applications.authorisation.approve', $app),
            ['approved_rental_amount' => '15000', 'approved_deposit_amount' => '15000'],
        );

        $resp->assertSessionDoesntHaveErrors();
        $app->refresh();
        self::assertSame('approved', $app->status);
        self::assertSame('15000.00', $app->approved_rental_amount);
        self::assertSame('15000.00', $app->approved_deposit_amount);
    }

    public function test_approve_action_leaves_approved_deposit_amount_null_when_omitted(): void
    {
        $ro = $this->authoriser('ro');
        $contact = $this->contact('tenant-authorise-nodeposit@example.co.za');
        $app = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'under_assessment', 'submitted_for_approval_at' => now(),
        ]);

        $resp = $this->actingAs($ro)->post(
            route('corex.rental-applications.authorisation.approve', $app),
            ['approved_rental_amount' => '15000'],
        );

        $resp->assertSessionDoesntHaveErrors();
        $app->refresh();
        self::assertSame('approved', $app->status);
        self::assertNull($app->approved_deposit_amount);
    }
}
