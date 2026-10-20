<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\User;
use App\Services\Rentals\LeaseHubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The lease's "Next:" step for every inspection state of an active lease (leases.md §15.13, rental-inspections.md; audit 2026-10-19 B3).
 *
 * "Review the move-out charges" may appear ONLY once an Out inspection of THIS lease is COMPLETED and not yet reviewed - never on a lease
 * with no inspection, an In under way or done, or an Out still being done, and never because of another lease's (or the property's) inspection.
 * Each case asserts the exact label, and the page cases read the "Next: ..." bar itself - the words "Start in-inspection" also sit elsewhere
 * on the page, so a page-wide substring check proves nothing.
 */
final class LeaseNextStepByInspectionStateTest extends TestCase
{
    use RefreshDatabase;

    private const REVIEW_LABEL = 'Review the move-out charges';

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        Auth::logout();

        $this->agency = Agency::create(['name' => 'Next Step Agency', 'slug' => 'ns-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '3 Next Step Road', 'status' => 'let_out', 'listing_type' => 'rental',
        ]);
        $this->lease = $this->newLease();

        foreach (['rental_inspections.view', 'rental_inspections.create', 'leases.view'] as $key) {
            DB::table('role_permissions')->insert([
                'role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => 'all',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->actingAs($this->agent);
    }

    private function newLease(array $overrides = []): Lease
    {
        $lease = Lease::create($overrides + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'deposit_amount' => 19000,
            'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addMonths(12)->toDateString(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thandi', 'last_name' => 'Step' . uniqid(), 'email' => 'step-' . uniqid() . '@example.co.za',
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        return $lease;
    }

    private function inspection(string $type, string $status, ?Lease $lease = null): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => ($lease ?? $this->lease)->id, 'property_id' => $this->property->id,
            'type' => $type, 'status' => $status, 'created_by_user_id' => $this->agent->id,
            'completed_at' => $status === RentalInspection::STATUS_COMPLETED ? now() : null,
        ]);
    }

    private function next(?Lease $lease = null): ?array
    {
        return app(LeaseHubService::class)->nextStep(($lease ?? $this->lease)->fresh(), $this->agent);
    }

    private function label(?Lease $lease = null): ?string
    {
        return $this->next($lease)['label'] ?? null;
    }

    /** The text of the page's own "Next: ..." bar (not the page as a whole). */
    private function nextBarOnThePage(?Lease $lease = null): string
    {
        $html = $this->get(route('corex.leases.show', $lease ?? $this->lease))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/Next:\s*([^<]+)</', $html, $m), 'the lease page carries a "Next:" bar');

        return trim(html_entity_decode($m[1]));
    }

    // ── The five states of a lease ────────────────────────────────────────────────────────────────

    public function test_a_brand_new_lease_with_no_inspection_next_step_is_the_incoming_inspection(): void
    {
        $this->assertSame('Start in-inspection', $this->label());
        $this->assertSame('corex.rental-inspections.create', $this->next()['route_name']);
        $this->assertSame('in', $this->next()['route_param']['type']);

        $this->assertSame('Start in-inspection', $this->nextBarOnThePage());
    }

    public function test_an_in_inspection_under_way_is_continued_whatever_its_stage(): void
    {
        foreach ([RentalInspection::STATUS_DRAFT, RentalInspection::STATUS_IN_PROGRESS, RentalInspection::STATUS_AWAITING_SIGNATURE] as $status) {
            $in = $this->inspection('in', $status);

            $this->assertSame('Continue in-inspection', $this->label(), "In inspection '{$status}'");
            $this->assertSame($in->id, $this->next()['route_param']);

            $in->delete();
        }
    }

    public function test_a_completed_in_inspection_leaves_nothing_to_review_yet(): void
    {
        $this->inspection('in', RentalInspection::STATUS_COMPLETED);

        $this->assertNull($this->next(), 'the tenancy is running: no next step, and certainly no move-out review');
        $this->assertNotSame(self::REVIEW_LABEL, $this->label());
    }

    public function test_an_out_inspection_under_way_does_not_offer_the_move_out_review(): void
    {
        $this->inspection('in', RentalInspection::STATUS_COMPLETED);

        foreach ([RentalInspection::STATUS_DRAFT, RentalInspection::STATUS_IN_PROGRESS, RentalInspection::STATUS_AWAITING_SIGNATURE] as $status) {
            $out = $this->inspection('out', $status);

            $this->assertNotSame(self::REVIEW_LABEL, $this->label(), "Out inspection '{$status}'");
            $this->assertNull($this->next(), "Out inspection '{$status}' on a lease with no notice: nothing to offer");

            $out->delete();
        }
    }

    public function test_an_out_inspection_under_way_with_a_notice_on_file_never_shows_the_review(): void
    {
        $this->inspection('in', RentalInspection::STATUS_COMPLETED);
        $this->lease->update(['notice_date' => now()->subDays(3)->toDateString()]);
        $this->assertTrue($this->lease->fresh()->hasActiveNotice(), 'fixture: the notice is on file');
        $this->inspection('out', RentalInspection::STATUS_IN_PROGRESS);

        $this->assertNotSame(self::REVIEW_LABEL, $this->label());
    }

    public function test_a_completed_out_inspection_offers_the_move_out_review_until_it_is_marked_reviewed(): void
    {
        $this->inspection('in', RentalInspection::STATUS_COMPLETED);
        $out = $this->inspection('out', RentalInspection::STATUS_COMPLETED);

        $this->assertSame(self::REVIEW_LABEL, $this->label());
        $this->assertSame('corex.leases.deposit-deductions', $this->next()['route_name']);
        $this->assertSame(self::REVIEW_LABEL, $this->nextBarOnThePage());

        $out->update(['deductions_reviewed_at' => now()]);
        $this->assertNull($this->next(), 'reviewed: the step is gone');
    }

    // ── Other states the same rule must hold in ─────────────────────────────────────────────────

    public function test_a_cancelled_out_inspection_is_not_a_completed_one(): void
    {
        $this->inspection('in', RentalInspection::STATUS_COMPLETED);
        $this->inspection('out', RentalInspection::STATUS_CANCELLED);

        $this->assertNotSame(self::REVIEW_LABEL, $this->label());
    }

    public function test_another_leases_completed_out_never_reaches_a_new_lease_on_the_same_property(): void
    {
        $previous = $this->newLease(['status' => Lease::STATUS_EXPIRED, 'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->subDay()->toDateString()]);
        $this->inspection('in', RentalInspection::STATUS_COMPLETED, $previous);
        $this->inspection('out', RentalInspection::STATUS_COMPLETED, $previous);
        $this->assertSame(self::REVIEW_LABEL, $this->label($previous), 'fixture: the OLD lease does owe the review');

        $this->assertSame('Start in-inspection', $this->label(), 'the new lease has no inspection of its own');
        $this->assertSame('Start in-inspection', $this->nextBarOnThePage());
    }

    public function test_an_ad_hoc_inspection_does_not_stand_in_for_the_in_or_the_out(): void
    {
        $this->inspection('ad_hoc', RentalInspection::STATUS_COMPLETED);

        $this->assertSame('Start in-inspection', $this->label());
    }

    public function test_a_draft_lease_never_shows_an_inspection_step_at_all(): void
    {
        $draft = $this->newLease(['status' => Lease::STATUS_DRAFT]);
        $this->inspection('out', RentalInspection::STATUS_COMPLETED, $draft);

        $this->assertNotSame(self::REVIEW_LABEL, $this->label($draft));
        $this->assertNotSame('Start in-inspection', $this->label($draft));
    }
}
