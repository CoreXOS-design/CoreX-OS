<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduces the bug reported on live-testing (a real shared-match link
 * showed "2 MATCHES" / "Properties found 2" but "Showing 1 of 1"): the
 * price-range slider's default `max` value is derived from the highest
 * match price, but if that value doesn't land on the slider's own
 * {min + n*step} grid, the browser's native range-input value-sanitisation
 * algorithm silently snaps the rendered `value` DOWN to the nearest valid
 * step below it — hiding the most expensive match on first load, with no
 * filter ever "applied" by the buyer.
 *
 * This test can only assert what the SERVER renders (the min/max/step/value
 * attributes on the two range inputs) — the actual browser snapping is a
 * client-side HTML parsing behaviour no PHP test can execute. Asserting the
 * rendered bounds always land exactly on the step grid is what guarantees
 * the browser has nothing to snap.
 */
final class SharedMatchPriceSliderBoundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_price_slider_bounds_are_step_aligned_and_include_every_match_price(): void
    {
        $agency = Agency::create(['name' => 'Slider Bounds Co', 'slug' => 'sbc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
        ]);
        $contact = Contact::create([
            'agency_id' => $agency->id, 'first_name' => 'Ali', 'last_name' => 'Jrklm',
            'created_by_user_id' => $agent->id, 'is_buyer' => true, 'buyer_state' => 'warm',
        ]);

        $match = ContactMatch::create([
            'agency_id'          => $agency->id,
            'contact_id'         => $contact->id,
            'created_by_user_id' => $agent->id,
            'name'               => 'Test wishlist',
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'price_min'          => 1_000_000,
            'price_max'          => 2_000_000,
        ]);

        // Two real matches whose prices are NOT multiples of any round step —
        // the exact shape that hid the R1,800,000 match on live-testing.
        $lowerPrice  = 1_395_000;
        $higherPrice = 1_800_000;
        foreach ([$lowerPrice, $higherPrice] as $price) {
            Property::forceCreate([
                'agency_id'     => $agency->id,
                'agent_id'      => $agent->id,
                'title'         => 'Test listing ' . $price,
                'status'        => 'active',
                'listing_type'  => 'sale',
                'price'         => $price,
                'beds'          => 3,
                'garages'       => 1,
                'property_type' => 'House',
            ]);
        }

        $response = $this->get(route('shared.match', ['token' => $match->share_token]))->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/Properties found\s*<span[^>]*>\s*2\s*<\/span>/',
            $html,
            'fixture setup: expected both properties to be counted as matches'
        );

        // Pull the server-rendered min/max/step off the price-max thumb.
        $this->assertMatchesRegularExpression(
            '/class="js-f-price-max range" min="(\d+)" max="(\d+)" step="(\d+)" value="(\d+)"/',
            $html
        );
        preg_match('/class="js-f-price-max range" min="(\d+)" max="(\d+)" step="(\d+)" value="(\d+)"/', $html, $m);
        [, $min, $max, $step, $value] = $m;
        $min = (int) $min;
        $max = (int) $max;
        $step = (int) $step;
        $value = (int) $value;

        // The bug: an unaligned bound. This is the exact condition the HTML
        // range-input spec snaps `value` down for — asserting it can never
        // recur is the regression guard.
        $this->assertSame(0, ($max - $min) % $step, 'slider min/max must land on the step grid or the browser silently snaps the default value down');

        // The default value must be the actual max, never rounded below it.
        $this->assertSame($max, $value);
        $this->assertGreaterThanOrEqual($higherPrice, $max, 'default slider max must never sit below the most expensive real match');
        $this->assertLessThanOrEqual($lowerPrice, $min, 'default slider min must never sit above the least expensive real match');
    }
}
