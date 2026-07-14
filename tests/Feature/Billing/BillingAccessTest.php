<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Agency;
use App\Models\Billing\AgencySubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who can see whose bill.
 *
 * Spec: .ai/specs/agency-billing.md §9  (AT-11)
 *
 * The load-bearing assertion here is that an agency admin gets a 403 on
 * /admin/billing. That page shows EVERY agency's commercial terms — what we
 * charge each of them, who is on a discount, who got a sweetheart deal. A leak
 * there is not a privacy bug, it is a commercial one.
 *
 * Note the suite convention: with `role_permissions` unseeded, PermissionService
 * grants every permission, so the `permission:billing.view` middleware passes for
 * any user. That is exactly why the developer page is gated on `owner_only`
 * (a ROLE check) rather than a permission key — a permission is grantable, and a
 * misgrant would hand an agency admin every other agency's pricing.
 */
class BillingAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Role::allRoles() memoises into a STATIC array that outlives RefreshDatabase's
     * truncation, so without this a role row created in one test is still "visible"
     * (or a freshly-created one invisible) in the next. isOwnerRole() reads through
     * that cache, so a stale entry silently turns every owner into a non-owner.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Role::clearCache();

        // Seeding an agency past 10 users flips its plan, which dispatches the
        // notification job. Under QUEUE_CONNECTION=sync that job runs INLINE, and
        // it sends on the explicit 'corex' mailer — a real SMTP host. Without this
        // fake, building test fixtures opens a live TLS connection to
        // mail.corexos.co.za. Tests never touch the network.
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function makeAgency(string $name): Agency
    {
        return Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . uniqid()]);
    }

    /** An owner-role user: global role row with is_owner, and a NULL agency_id. */
    private function makeOwner(): User
    {
        // forceFill, not firstOrCreate's $values: `is_owner` is not in Role::$fillable,
        // so mass assignment silently drops it and the role lands with is_owner = NULL —
        // which makes isOwnerRole() quietly false and every owner-only assertion a 403.
        $role = Role::query()->firstOrNew(['name' => 'super_admin', 'agency_id' => null]);
        $role->forceFill([
            'label'          => 'System Owner',
            'is_owner'       => true,
            'can_be_deleted' => false,
        ])->save();

        Role::clearCache();   // the row is new — make allRoles() see it

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'is_active' => 1]);
    }

    private function makeAdmin(Agency $agency): User
    {
        return User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id, 'is_active' => 1]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The developer page — owner only
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_agency_admin_cannot_reach_the_developer_billing_page(): void
    {
        $agency = $this->makeAgency('Margate Properties');

        $this->actingAs($this->makeAdmin($agency))
            ->get(route('admin.billing.index'))
            ->assertForbidden();
    }

    public function test_an_agency_admin_cannot_set_pricing(): void
    {
        $agency = $this->makeAgency('Margate Properties');

        $this->actingAs($this->makeAdmin($agency))
            ->put(route('admin.billing.update', $agency), ['mode' => 'custom', 'custom_amount_zar' => 1])
            ->assertForbidden();

        $this->assertNull(
            DB::table('agency_subscriptions')->where('agency_id', $agency->id)->value('custom_amount_zar'),
            'A non-owner must not be able to write their own price.'
        );
    }

    public function test_an_owner_sees_every_agency_on_the_developer_page(): void
    {
        $a = $this->makeAgency('Shelly Beach Realty');
        $b = $this->makeAgency('Southbroom Estates');

        User::factory()->count(3)->create(['agency_id' => $a->id, 'is_active' => 1]);
        User::factory()->count(12)->create(['agency_id' => $b->id, 'is_active' => 1]);

        $this->actingAs($this->makeOwner())
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('Shelly Beach Realty')
            ->assertSee('Southbroom Estates')
            ->assertSee('CoreX Team')       // 3 users
            ->assertSee('CoreX Agency');    // 12 users
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The agency page — own figures only
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_admin_sees_their_own_bill(): void
    {
        $agency = $this->makeAgency('Ramsgate Realty');
        $admin  = $this->makeAdmin($agency);
        User::factory()->count(4)->create(['agency_id' => $agency->id, 'is_active' => 1]);

        // 5 seats (4 + the admin) × R450 = R2 250
        $this->actingAs($admin)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('R 2,250.00')
            ->assertSee('CoreX Team');
    }

    /**
     * Agency A's admin must never see Agency B's numbers. B is deliberately
     * given a wildly different, unmistakable figure so a leak would be loud.
     */
    public function test_an_admin_cannot_see_another_agencys_figures(): void
    {
        $mine   = $this->makeAgency('Uvongo Estates');
        $theirs = $this->makeAgency('Rival Realty');

        $admin = $this->makeAdmin($mine);                                       // 1 seat → R450

        User::factory()->count(24)->create(['agency_id' => $theirs->id, 'is_active' => 1]);
        AgencySubscription::forAgency((int) $theirs->id)
            ->forceFill(['custom_amount_zar' => 99999.00, 'custom_amount_note' => 'RIVAL SECRET RATE'])
            ->save();

        $this->actingAs($admin)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('R 450.00')
            ->assertDontSee('Rival Realty')
            ->assertDontSee('RIVAL SECRET RATE')
            ->assertDontSee('99,999.00');
    }

    public function test_the_agency_page_shows_a_discount_countdown(): void
    {
        $agency = $this->makeAgency('Hibberdene Homes');
        $admin  = $this->makeAdmin($agency);
        User::factory()->count(9)->create(['agency_id' => $agency->id, 'is_active' => 1]);   // 10 seats → R4 500

        AgencySubscription::forAgency((int) $agency->id)->forceFill([
            'discount_percent'   => 20.00,
            'discount_months'    => 6,
            'discount_starts_on' => now()->toDateString(),
            'discount_note'      => 'Launch offer',
        ])->save();

        $this->actingAs($admin)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('20% off')
            ->assertSee('6 months remaining')
            ->assertSee('R 3,600.00')   // 4500 × 0.8
            ->assertSee('Launch offer');
    }

    /** An owner with no agency switched in has no single bill — say so, don't 500. */
    public function test_an_owner_with_no_agency_context_gets_a_clear_page_not_a_500(): void
    {
        $this->actingAs($this->makeOwner())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('No agency selected');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Setting terms (owner) — the D5 invariant at the HTTP boundary
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_owner_can_set_a_custom_amount(): void
    {
        $agency = $this->makeAgency('Port Shepstone Property Group');
        User::factory()->count(6)->create(['agency_id' => $agency->id, 'is_active' => 1]);

        $this->actingAs($this->makeOwner())
            ->put(route('admin.billing.update', $agency), [
                'mode'               => 'custom',
                'custom_amount_zar'  => 'R 1 800',        // typed the way a human types it
                'custom_amount_note' => 'Negotiated launch rate',
            ])
            ->assertRedirect(route('admin.billing.index'));

        $sub = AgencySubscription::forAgency((int) $agency->id)->fresh();

        $this->assertSame('1800.00', (string) $sub->custom_amount_zar, 'Zar::parse must accept "R 1 800".');
        $this->assertNull($sub->discount_percent);
    }

    /** Switching to a discount must CLEAR the custom amount — they never coexist (D5). */
    public function test_setting_a_discount_clears_any_custom_amount(): void
    {
        $agency = $this->makeAgency('Scottburgh Property');
        User::factory()->count(6)->create(['agency_id' => $agency->id, 'is_active' => 1]);

        AgencySubscription::forAgency((int) $agency->id)->forceFill(['custom_amount_zar' => 1200.00])->save();

        $this->actingAs($this->makeOwner())
            ->put(route('admin.billing.update', $agency), [
                'mode'               => 'discount',
                'discount_percent'   => 15,
                'discount_months'    => 3,
                'discount_starts_on' => now()->toDateString(),
            ])
            ->assertRedirect();

        $sub = AgencySubscription::forAgency((int) $agency->id)->fresh();

        $this->assertNull($sub->custom_amount_zar, 'The custom amount must be cleared when a discount is set.');
        $this->assertSame('15.00', (string) $sub->discount_percent);
    }

    /** Automatic clears everything — the price goes back to following headcount. */
    public function test_switching_to_automatic_clears_both(): void
    {
        $agency = $this->makeAgency('Pennington Properties');
        User::factory()->count(6)->create(['agency_id' => $agency->id, 'is_active' => 1]);

        AgencySubscription::forAgency((int) $agency->id)->forceFill([
            'discount_percent'   => 30.00,
            'discount_months'    => 4,
            'discount_starts_on' => now()->toDateString(),
        ])->save();

        $this->actingAs($this->makeOwner())
            ->put(route('admin.billing.update', $agency), ['mode' => 'automatic'])
            ->assertRedirect();

        $sub = AgencySubscription::forAgency((int) $agency->id)->fresh();

        $this->assertNull($sub->custom_amount_zar);
        $this->assertNull($sub->discount_percent);
        $this->assertNull($sub->discount_months);
    }

    /** @dataProvider badTermsProvider */
    public function test_invalid_terms_are_rejected_with_a_message(array $payload, string $field): void
    {
        $agency = $this->makeAgency('Validation Test Agency');

        $this->actingAs($this->makeOwner())
            ->put(route('admin.billing.update', $agency), $payload)
            ->assertSessionHasErrors($field);
    }

    public static function badTermsProvider(): array
    {
        $today = now()->toDateString();

        return [
            'negative custom amount'     => [['mode' => 'custom', 'custom_amount_zar' => -100], 'custom_amount_zar'],
            'unparseable custom amount'  => [['mode' => 'custom', 'custom_amount_zar' => 'lots'], 'custom_amount_zar'],
            'custom amount missing'      => [['mode' => 'custom'], 'custom_amount_zar'],
            'zero percent discount'      => [['mode' => 'discount', 'discount_percent' => 0, 'discount_months' => 3, 'discount_starts_on' => $today], 'discount_percent'],
            'over 100 percent discount'  => [['mode' => 'discount', 'discount_percent' => 101, 'discount_months' => 3, 'discount_starts_on' => $today], 'discount_percent'],
            'discount without months'    => [['mode' => 'discount', 'discount_percent' => 20, 'discount_starts_on' => $today], 'discount_months'],
            'discount with zero months'  => [['mode' => 'discount', 'discount_percent' => 20, 'discount_months' => 0, 'discount_starts_on' => $today], 'discount_months'],
            'no mode at all'             => [[], 'mode'],
        ];
    }

    /**
     * Even if a caller POSTs BOTH sets of fields, the mode decides — and the
     * other set is discarded. There is no submitted shape that produces a row
     * holding a custom amount AND a discount.
     */
    public function test_posting_both_a_custom_amount_and_a_discount_keeps_only_the_chosen_mode(): void
    {
        $agency = $this->makeAgency('Both Fields Agency');

        $this->actingAs($this->makeOwner())
            ->put(route('admin.billing.update', $agency), [
                'mode'               => 'custom',
                'custom_amount_zar'  => 2500,
                // A stale discount block left in the POST body — must be ignored.
                'discount_percent'   => 50,
                'discount_months'    => 12,
                'discount_starts_on' => now()->toDateString(),
            ])
            ->assertRedirect();

        $sub = AgencySubscription::forAgency((int) $agency->id)->fresh();

        $this->assertSame('2500.00', (string) $sub->custom_amount_zar);
        $this->assertNull($sub->discount_percent, 'The discount block must be discarded in custom mode.');
    }
}
