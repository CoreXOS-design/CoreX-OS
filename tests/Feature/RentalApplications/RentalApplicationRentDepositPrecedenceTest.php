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

    public function test_view_readonly_prefills_from_the_approved_amounts_when_present(): void
    {
        $contact = $this->contact('tenant-approved@example.co.za');
        $app = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'approved',
            'approved_rental_amount' => 50000, 'approved_deposit_amount' => 50000,
        ]);

        $resp = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));

        $resp->assertOk();
        // Js::from() renders each value as its own single-quoted JS literal
        // inside the x-data string (not double-quoted JSON — Laravel's Js::
        // helper deliberately uses single quotes so it embeds cleanly into
        // a double-quoted HTML attribute with no entity-encoding needed) —
        // assert on the literal, not a loose "contains 50000" (which a
        // stray thousand-count or id elsewhere on the page could also
        // match). approved_rental_amount/approved_deposit_amount are
        // decimal:2 casts, so Eloquent hands Js::from() the STRING
        // "50000.00", not the bare number — this is the existing, already-
        // correct behaviour (x-model on a number input coerces it fine),
        // not something this fix changed.
        $resp->assertSee("approvedRentalAmount: '50000.00'", false);
        $resp->assertSee("approvedDepositAmount: '50000.00'", false);
        $resp->assertSee("rentalAmount: '50000.00'", false);
        $resp->assertSee("depositAmount: '50000.00'", false);
        $resp->assertSee("rentalAmountSource: 'approved'", false);
        $resp->assertSee("depositAmountSource: 'approved'", false);
    }

    public function test_view_readonly_has_no_approved_source_when_both_are_absent(): void
    {
        $contact = $this->contact('tenant-noapproval@example.co.za');
        $app = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'approved',
            // approved_rental_amount / approved_deposit_amount left null —
            // 'approved' is reachable with a null approved_rental_amount
            // (e.g. a status set directly, or a pre-existing row from
            // before this column existed) — the page must not crash or
            // fabricate a source label for either field.
        ]);

        $resp = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));

        $resp->assertOk();
        $resp->assertSee('approvedRentalAmount: null', false);
        $resp->assertSee('approvedDepositAmount: null', false);
        $resp->assertSee('rentalAmountSource: null', false);
        $resp->assertSee('depositAmountSource: null', false);
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
