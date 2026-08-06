<?php

declare(strict_types=1);

namespace Tests\Feature\Wishlist;

use App\Http\Controllers\CoreX\ContactMatchController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wishlist feature preferences must be mutually exclusive: a feature is exactly ONE of
 * {must-have, nice-to-have, deal-breaker, none} — never in two buckets at once (Johan: garage marked
 * must-have AND deal-breaker in live data). Storage is unchanged (three JSON token arrays on
 * contact_matches); the fix makes them disjoint by construction in the UI + a server backstop.
 */
final class WishlistFeatureExclusivityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Wish Co', 'slug' => 'wish-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'role' => 'super_admin', 'is_admin' => true, 'is_active' => true,
        ]);
        $this->contact = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Bianca', 'last_name' => 'Buyer', 'created_by_user_id' => $this->user->id,
        ]));
    }

    // 1) The invariant helper — pure logic.
    public function test_conflicting_tokens_helper(): void
    {
        $this->assertSame([], ContactMatch::conflictingFeatureTokens(['garden'], ['pool'], ['garage']));
        $this->assertSame(['garage'], ContactMatch::conflictingFeatureTokens(['garage'], [], ['garage']));
        // case/whitespace-insensitive
        $this->assertSame(['garage'], ContactMatch::conflictingFeatureTokens([' Garage '], ['garage'], []));
    }

    // 2) Save → load round-trip: disjoint selections persist to the three arrays and reload as one-of.
    public function test_disjoint_selection_round_trips(): void
    {
        $this->actingAs($this->user)
            ->post(route('corex.contacts.matches.store', $this->contact), [
                'listing_type'          => 'sale',
                'must_have_features'    => ['garden'],
                'nice_to_have_features' => ['pool'],
                'deal_breakers'         => ['garage'],
            ])
            ->assertSessionHasNoErrors();

        $match = ContactMatch::withoutGlobalScopes()->where('contact_id', $this->contact->id)->firstOrFail();
        $this->assertSame(['garden'], $match->must_have_features);
        $this->assertSame(['pool'], $match->nice_to_have_features);
        $this->assertSame(['garage'], $match->deal_breakers);

        // Reload the edit form (the shared partial): the new single-selector UI is present; the old
        // three-list UI is gone; and each saved feature maps to exactly ONE bucket state.
        $html = $this->renderForm($match);
        $this->assertStringContainsString('Feature Preferences', $html);
        $this->assertStringNotContainsString('Must-Have Features', $html);    // old separate-list heading
        $this->assertStringNotContainsString('Nice-to-Have Features', $html);

        $states = str_replace('\u0022', '"', $html);
        $this->assertStringContainsString('"garden":"must"', $states);
        $this->assertStringContainsString('"pool":"nice"', $states);
        $this->assertStringContainsString('"garage":"breaker"', $states);
    }

    /** Render the shared wishlist form partial for an existing match (the edit-mode pre-fill path). */
    private function renderForm(ContactMatch $match): string
    {
        return view('corex.contacts._match-form', [
            'isEdit'          => true,
            'match'           => $match,
            'contact'         => $this->contact,
            'featureOptions'  => ContactMatchController::FEATURE_OPTIONS,
            'matchCategories' => collect(),
            'matchTypes'      => collect(),
        ])->render();
    }

    // 3) Server backstop: a bypassed submission with a feature in two buckets is REJECTED, not saved.
    public function test_conflicting_submission_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post(route('corex.contacts.matches.store', $this->contact), [
                'listing_type'       => 'sale',
                'must_have_features' => ['garage'],
                'deal_breakers'      => ['garage'],
            ])
            ->assertSessionHasErrors('must_have_features');

        $this->assertSame(0, ContactMatch::withoutGlobalScopes()->where('contact_id', $this->contact->id)->count(),
            'a conflicting wishlist must not be persisted');
    }

    // 4) Existing conflicted data loads as one-of: precedence must > deal-breaker > nice.
    public function test_existing_conflicted_record_loads_as_single_state(): void
    {
        $match = ContactMatch::withoutEvents(fn () => ContactMatch::create([
            'contact_id' => $this->contact->id, 'agency_id' => $this->agency->id,
            'created_by_user_id' => $this->user->id, 'listing_type' => 'sale',
            'must_have_features'    => ['garage'],   // the exact live conflict Johan named:
            'deal_breakers'         => ['garage'],   // garage in BOTH must-have and deal-breaker
            'nice_to_have_features' => [],
        ]));

        $states = str_replace('\u0022', '"', $this->renderForm($match));
        // Collapsed to must-have (precedence); it can no longer render as a deal-breaker at the same time.
        $this->assertStringContainsString('"garage":"must"', $states);
        $this->assertStringNotContainsString('"garage":"breaker"', $states);
    }
}
