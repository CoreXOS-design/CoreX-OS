<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAssessment;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-392 — Round 8, cc5's re-test found two things survived Round 7:
 *
 * RA-02 was incomplete: only 4 of 9 numeric money fields on this feature
 * were sanitized. The authoriser's approved_rental_amount — the exact
 * field cc5 found still rejecting a comma — plus the assessment panel's
 * three income/expense fields and the settings screen's qualifying
 * ratio, were validated raw. Fixed the same way as the original 4:
 * RentalApplication::sanitizeNumericInput() generalized to take an
 * explicit field list, wired into each of the three controllers that
 * own these fields.
 *
 * RA-03 was still broken specifically on review.blade.php — the screen
 * an agent actually reviews a returned application on. The "added after
 * submission" badge only ever existed on show.blade.php; the earlier
 * regression tests only ever rendered show.blade.php, so they stayed
 * green while testing a surface the agent doesn't use for this. This
 * file renders the REAL review route.
 */
final class RentalApplicationRound8FixesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function application(array $attrs = []): RentalApplication
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'returned', 'submitted_at' => now()->subDay(),
        ], $attrs));
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

    private function agent(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    // ── RA-02: authoriser approve amount — every SA money spelling ────────

    public function test_authoriser_approve_accepts_every_south_african_money_spelling(): void
    {
        $ro = $this->authoriser('ro');
        $cases = ['8,500' => '8500.00', '8 500' => '8500.00', 'R8 500' => '8500.00', '8500.50' => '8500.50'];

        foreach ($cases as $typed => $expected) {
            $app = $this->application(['status' => 'under_assessment', 'submitted_for_approval_at' => now()]);

            $response = $this->actingAs($ro)->post(
                route('corex.rental-applications.authorisation.approve', $app),
                ['approved_rental_amount' => $typed]
            );

            $response->assertSessionDoesntHaveErrors();
            $this->assertSame($expected, $app->fresh()->approved_rental_amount, "Typed '{$typed}' must store as {$expected}.");
            $this->assertSame('approved', $app->fresh()->status);
        }
    }

    public function test_authoriser_approve_rejects_genuinely_invalid_amounts_after_sanitizing(): void
    {
        $ro = $this->authoriser('ro');
        $app = $this->application(['status' => 'under_assessment', 'submitted_for_approval_at' => now()]);

        $response = $this->actingAs($ro)->post(
            route('corex.rental-applications.authorisation.approve', $app),
            ['approved_rental_amount' => 'not an amount']
        );

        $response->assertSessionHasErrors('approved_rental_amount');
        $this->assertNull($app->fresh()->approved_rental_amount);
        $this->assertSame('under_assessment', $app->fresh()->status);
    }

    // ── RA-02: assessment panel — every SA money spelling, each field ────

    // Round 9 (item 5) — monthly_income/other_monthly_income/monthly_expenses
    // became growable item lists; see RentalApplicationRound9AffordabilityTest
    // for the money-format + auto-row-add + soft-delete-sync coverage.
    public function test_assessment_income_items_accept_every_south_african_money_spelling(): void
    {
        $agent = $this->agent();
        $cases = ['8,500' => '8500.00', '8 500' => '8500.00', 'R8 500' => '8500.00', '8500.50' => '8500.50'];

        foreach ($cases as $typed => $expected) {
            $app = $this->application();
            $this->actingAs($agent)->post(
                route('corex.rental-applications.review.assessment', $app),
                ['income_items' => [['description' => 'Salary', 'amount' => $typed]]]
            )->assertSessionDoesntHaveErrors();

            $assessment = RentalApplicationAssessment::where('rental_application_id', $app->id)->first();
            $this->assertSame($expected, $assessment->incomeItems->first()->amount, "income item typed '{$typed}' must store as {$expected}.");
        }
    }

    public function test_assessment_expense_items_accept_every_south_african_money_spelling(): void
    {
        $agent = $this->agent();
        $cases = ['8,500' => '8500.00', '8 500' => '8500.00', 'R8 500' => '8500.00', '8500.50' => '8500.50'];

        foreach ($cases as $typed => $expected) {
            $app = $this->application();
            $this->actingAs($agent)->post(
                route('corex.rental-applications.review.assessment', $app),
                ['expense_items' => [['description' => 'Rent', 'amount' => $typed]]]
            )->assertSessionDoesntHaveErrors();

            $assessment = RentalApplicationAssessment::where('rental_application_id', $app->id)->first();
            $this->assertSame($expected, $assessment->expenseItems->first()->amount, "expense item typed '{$typed}' must store as {$expected}.");
        }
    }

    // ── RA-02: settings — qualifying ratio, every SA money spelling ──────

    public function test_qualifying_formula_accepts_every_south_african_style_number(): void
    {
        $owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        // Round 9 — the field is now a percentage-of-gross-income (e.g.
        // "28.5"), not a rent multiplier. The sanitizer must still be
        // harmless when applied (no-op) rather than break it.
        $response = $this->actingAs($owner)->post(
            route('corex.settings.rental-applications.qualifying-formula'),
            ['max_rent_percent_of_gross_income' => '28.5']
        );
        $response->assertSessionDoesntHaveErrors();
        $setting = RentalApplicationQualifyingSetting::where('agency_id', $this->agency->id)->first();
        $this->assertSame('28.50', $setting->max_rent_percent_of_gross_income);

        // A value with a stray space (e.g. "28.5 " from a mobile keyboard)
        // is still cleaned, not rejected.
        $response2 = $this->actingAs($owner)->post(
            route('corex.settings.rental-applications.qualifying-formula'),
            ['max_rent_percent_of_gross_income' => '28.5 ']
        );
        $response2->assertSessionDoesntHaveErrors();
        $this->assertSame('28.50', $setting->fresh()->max_rent_percent_of_gross_income);
    }

    // ── RA-03: the REAL review screen, not show.blade.php ────────────────

    public function test_the_review_screen_badges_a_document_added_after_submission(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        $original = Document::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'original-at-submit.pdf', 'storage_path' => 'x', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 10,
            'source_type' => 'rental_application', 'source_id' => $app->id,
        ]);
        $original->created_at = $app->submitted_at->copy()->subMinute();
        $original->save();

        $late = Document::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'added-later.pdf', 'storage_path' => 'x', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 10,
            'source_type' => 'rental_application', 'source_id' => $app->id,
            'uploaded_by' => $agent->id,
        ]);
        $late->created_at = $app->submitted_at->copy()->addHour();
        $late->save();

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $response->assertSee('Added after submission');
        $response->assertSee('added by ' . $agent->name, false);
        $response->assertSee('from applicant');

        // The original-at-submission document must NOT carry the badge.
        preg_match('/Supporting Documents.*$/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches);
        $this->assertSame(1, substr_count($matches[0], 'Added after submission'));
    }

    public function test_the_review_screen_does_not_badge_documents_added_before_submission(): void
    {
        $agent = $this->agent();
        $app = $this->application(['status' => 'in_progress', 'submitted_at' => null]);
        Document::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'pre-submit.pdf', 'storage_path' => 'x', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 10,
            'source_type' => 'rental_application', 'source_id' => $app->id,
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $response->assertDontSee('Added after submission');
    }
}
