<?php

declare(strict_types=1);

namespace Tests\Feature\Mic;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use App\Services\Matching\BuyerMatchRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** `prospecting:recompute-matches --stale`: only buyers whose cache is not on the current matching rules, restartable (mic-work-tab.md 13.15 addendum). */
final class RecomputeStaleOptionTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private function buyerWithWishlist(string $first): Contact
    {
        $c = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'is_buyer' => true, 'buyer_state' => 'new', 'first_name' => $first, 'last_name' => 'Buyer',
            'phone' => '082' . random_int(1000000, 9999999), 'email' => strtolower($first) . '-' . Str::random(4) . '@e.co.za',
        ]);
        ContactMatch::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'contact_id' => $c->id, 'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            'price_max' => 1_200_000, 'property_types' => ['House'], 'beds_min' => 2]);

        return $c;
    }

    private function row(Contact $c, int $listingId, ?string $rules): void
    {
        DB::table('prospecting_buyer_matches')->insert([
            'prospecting_listing_id' => $listingId, 'contact_id' => $c->id, 'agency_id' => $this->agency->id, 'score' => 90, 'tier' => 'strong',
            'matched_features' => json_encode($rules === null ? ['engine' => 'canonical'] : ['engine' => 'canonical', 'rules' => $rules]),
            'matched_at' => now(), 'last_recompute_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_only_stale_buyers_are_rebuilt_and_a_second_run_finds_nothing_left(): void
    {
        $this->setUpAgency();
        $current = $this->buyerWithWishlist('Done');
        $stale = $this->buyerWithWishlist('Old');
        $partly = $this->buyerWithWishlist('Part');
        $l1 = $this->listing(['property_type' => 'House', 'bedrooms' => 3, 'price' => 900_000]);
        $l2 = $this->listing(['property_type' => 'House', 'bedrooms' => 3, 'price' => 950_000]);
        $this->row($current, $l1, BuyerMatchRules::VERSION);
        $this->row($stale, $l1, null);
        $this->row($partly, $l1, BuyerMatchRules::VERSION);
        $this->row($partly, $l2, '0.0-older');

        Artisan::call('prospecting:recompute-matches', ['--stale' => true, '--dry-run' => true]);
        $this->assertStringContainsString('Processing 2 buyer(s)', Artisan::output(), 'the one on the current rules is left alone');

        Artisan::call('prospecting:recompute-matches', ['--stale' => true]);
        $out = Artisan::output();
        $this->assertStringContainsString('contact ' . $stale->id . ' ', $out);
        $this->assertStringContainsString('contact ' . $partly->id . ' ', $out);
        $this->assertStringNotContainsString('contact ' . $current->id . ' ', $out);
        $this->assertSame(0, DB::table('prospecting_buyer_matches')->where('contact_id', $stale->id)->where(fn ($q) => $q->whereNull('matched_features')->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(matched_features, '$.rules')) <> ?", [BuyerMatchRules::VERSION]))->count());

        Artisan::call('prospecting:recompute-matches', ['--stale' => true]);
        $this->assertStringContainsString('Processing 0 buyer(s)', Artisan::output(), 'restartable: nothing left once they are all on the current rules');
    }
}
