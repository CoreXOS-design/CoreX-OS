<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Mail\Billing\AgencyPlanChangedMail;
use App\Models\Agency;
use App\Models\Billing\AgencySubscription;
use App\Models\Branch;
use App\Models\User;
use App\Services\Billing\SubscriptionPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The October 2026 price list — spec agency-billing §3 D7 (Johan 2026-10-19).
 *
 * A NEW agency pays one flat monthly fee for the band its headcount falls in (1 → R450, 2 → R900, 3–5 → R1 495, 6–10 → R2 495,
 * 11–15 → R3 295, 16–20 → R3 995, 21–25 → R4 595, 26–30 → R5 095; more than 30 on quotation) plus R750 for each branch after the
 * first. An agency that was already on CoreX keeps the Team / Agency prices it signed for.
 */
class BandPricingTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = app(SubscriptionPricingService::class);
    }

    private function makeAgency(string $name = 'Cape Rentals'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . uniqid()]);
    }

    private function hire(Agency $agency, int $count): void
    {
        User::factory()->count($count)->create(['agency_id' => $agency->id, 'is_active' => 1]);
    }

    public static function bands(): array
    {
        return [
            [1, 450], [2, 900], [3, 1495], [5, 1495], [6, 2495], [10, 2495], [11, 3295], [15, 3295],
            [16, 3995], [20, 3995], [21, 4595], [25, 4595], [26, 5095], [30, 5095],
        ];
    }

    /** @dataProvider bands */
    public function test_a_new_agency_pays_the_flat_fee_of_its_band(int $users, int $fee): void
    {
        Mail::fake();
        $agency = $this->makeAgency();
        $this->hire($agency, $users);

        $quote = $this->pricing->quoteFor($agency->fresh());
        $this->assertTrue($quote->usesBands);
        $this->assertSame((float) $fee, $quote->payableZar, "$users users");
        $this->assertCount(1, $quote->linesIn('seats'));
        $this->assertFalse($quote->onQuotation);
    }

    public function test_the_band_is_named_in_plain_words(): void
    {
        Mail::fake();
        $agency = $this->makeAgency();
        $this->hire($agency, 4);
        $this->assertSame('3 to 5 agents', $this->pricing->quoteFor($agency->fresh())->planLabel);
    }

    public function test_every_branch_after_the_first_costs_seven_hundred_and_fifty(): void
    {
        Mail::fake();
        $agency = $this->makeAgency();
        $this->hire($agency, 1);
        foreach (['Main', 'North', 'South'] as $b) {
            Branch::create(['agency_id' => $agency->id, 'name' => $b]);
        }

        $quote = $this->pricing->quoteFor($agency->fresh());
        $this->assertSame(2, $quote->billableBranches);
        $this->assertSame(450.0 + 2 * 750.0, $quote->payableZar);
    }

    public function test_more_than_thirty_users_is_on_quotation_until_a_custom_amount_is_set(): void
    {
        Mail::fake();
        $agency = $this->makeAgency();
        $this->hire($agency, 31);

        $quote = $this->pricing->quoteFor($agency->fresh());
        $this->assertSame(AgencySubscription::PLAN_QUOTE, $quote->derivedPlan);
        $this->assertTrue($quote->onQuotation);
        $this->assertSame(0.0, $quote->payableZar, 'nobody is billed a number that was never agreed');

        AgencySubscription::forAgency((int) $agency->id)->update(['custom_amount_zar' => 6500]);
        $quote = $this->pricing->quoteFor($agency->fresh());
        $this->assertFalse($quote->onQuotation);
        $this->assertSame(6500.0, $quote->payableZar);
    }

    public function test_moving_band_is_silent_and_going_above_the_last_band_tells_corex(): void
    {
        Mail::fake();
        $agency = $this->makeAgency();
        $this->hire($agency, 1);
        $this->assertSame('band1', DB::table('agency_subscriptions')->where('agency_id', $agency->id)->value('plan'));
        $this->hire($agency, 29);
        $this->assertSame('band8', DB::table('agency_subscriptions')->where('agency_id', $agency->id)->value('plan'));
        Mail::assertNothingSent();

        $this->hire($agency, 1);
        $this->assertSame(AgencySubscription::PLAN_QUOTE, DB::table('agency_subscriptions')->where('agency_id', $agency->id)->value('plan'));
        Mail::assertSent(AgencyPlanChangedMail::class, 1);
        Mail::assertSent(AgencyPlanChangedMail::class, fn (AgencyPlanChangedMail $m) => $m->needsQuote
            && $m->fromPlanLabel === '26 to 30 agents' && $m->toPlanLabel === 'More than 30 agents' && $m->seats === 31);
    }

    public function test_an_agency_on_the_old_prices_keeps_them(): void
    {
        Mail::fake();
        $agency = $this->makeAgency('Home Finders Coastal');
        AgencySubscription::forAgency((int) $agency->id)->update(['price_list' => AgencySubscription::PRICE_LIST_TIERS]);
        $this->hire($agency, 13);

        $quote = $this->pricing->quoteFor($agency->fresh());
        $this->assertFalse($quote->usesBands);
        $this->assertSame(AgencySubscription::PLAN_AGENCY, $quote->derivedPlan);
        $this->assertSame(1495.0 + 10 * 295.0 + 3 * 250.0, $quote->payableZar);
    }

    public function test_the_migration_keeps_every_existing_agency_on_the_old_prices(): void
    {
        $withRow = $this->makeAgency('Existing With Row');
        AgencySubscription::forAgency((int) $withRow->id);
        DB::table('agency_subscriptions')->update(['price_list' => null]);
        $withoutRow = $this->makeAgency('Existing Without Row');

        (require database_path('migrations/2026_11_16_100700_add_price_list_to_agency_subscriptions_table.php'))->up();

        foreach ([$withRow, $withoutRow] as $agency) {
            $this->assertSame(AgencySubscription::PRICE_LIST_TIERS, DB::table('agency_subscriptions')->where('agency_id', $agency->id)->value('price_list'), $agency->name);
        }
        $this->assertSame(AgencySubscription::PRICE_LIST_BANDS, AgencySubscription::forAgency((int) $this->makeAgency('Brand New')->id)->price_list);
    }

    /** Johan 2026-10-20 (decision 1): every existing agency moves to the bands; decision 3: nobody is emailed for it. */
    public function test_the_follow_up_migration_moves_every_agency_to_the_bands_silently_and_keeps_custom_terms(): void
    {
        Mail::fake();
        $small  = $this->makeAgency('Small Existing');
        $big    = $this->makeAgency('Big Existing');
        $custom = $this->makeAgency('Custom Deal');
        $this->hire($small, 4);
        $this->hire($big, 31);
        $this->hire($custom, 12);
        // The state the first migration leaves: everyone on the old tiers, on a tier plan.
        DB::table('agency_subscriptions')->update(['price_list' => AgencySubscription::PRICE_LIST_TIERS, 'plan' => AgencySubscription::PLAN_TEAM]);
        DB::table('agency_subscriptions')->whereIn('agency_id', [$big->id, $custom->id])->update(['plan' => AgencySubscription::PLAN_AGENCY]);
        DB::table('agency_subscriptions')->where('agency_id', $custom->id)->update(['custom_amount_zar' => 4000, 'custom_amount_note' => 'negotiated']);

        Mail::fake(); // only what the migration sends counts (hiring 31 on today's default list already told CoreX)
        (require database_path('migrations/2026_11_16_100900_move_all_agencies_to_band_pricing.php'))->up();

        $row = fn (Agency $a) => DB::table('agency_subscriptions')->where('agency_id', $a->id)->first();
        foreach ([$small, $big, $custom] as $a) {
            $this->assertSame(AgencySubscription::PRICE_LIST_BANDS, $row($a)->price_list, $a->name);
        }
        $this->assertSame('band3', $row($small)->plan);
        $this->assertSame(AgencySubscription::PLAN_QUOTE, $row($big)->plan);
        $this->assertSame('band5', $row($custom)->plan);
        Mail::assertNothingSent();

        $this->assertSame(1495.0, $this->pricing->quoteFor($small->fresh())->payableZar);
        $this->assertTrue($this->pricing->quoteFor($big->fresh())->onQuotation, 'above the last band waits for a quoted fee');
        $this->assertSame(4000.0, $this->pricing->quoteFor($custom->fresh())->payableZar, 'an agreed custom amount still wins');

        (require database_path('migrations/2026_11_16_100900_move_all_agencies_to_band_pricing.php'))->up();
        $this->assertSame('band3', $row($small)->plan, 'running it again changes nothing');
        Mail::assertNothingSent();
    }

    public function test_the_billing_pages_show_the_band_and_the_quotation(): void
    {
        Mail::fake();
        $agency = $this->makeAgency();
        $this->hire($agency, 31);
        $html = view('billing.index', ['agency' => $agency, 'quote' => $this->pricing->quoteFor($agency->fresh()),
            'billableUsers' => [], 'excludedUsers' => ['deactivated' => 0, 'archived' => 0], 'branchRows' => []])->render();
        $this->assertStringContainsString('Your price band', $html);
        $this->assertStringContainsString('Your monthly fee is on quotation', $html);
    }
}
