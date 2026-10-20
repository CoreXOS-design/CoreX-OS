<?php

namespace Tests\Feature\Platform\Agreement;

use App\Events\Platform\SubscriptionAgreementCompleted;
use App\Models\Agency;
use App\Models\Billing\AgencySubscription;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\SubscriptionPricingService;
use App\Services\PlatformEsign\Agreement\AgreementPricing;
use App\Services\PlatformEsign\Agreement\AgreementSample;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan 2026-10-20 (audit M3, decision 4): the monthly fee quoted for more than the last band carries into billing automatically
 * the moment the Subscription Agreement is fully signed. Event SubscriptionAgreementCompleted -> Billing\ApplyAgreedQuotedFee.
 * Spec: agency-timeline-and-platform-esign.md §11.5a, agency-billing.md D7.
 */
class AgreementQuotedFeeBillingTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const ACCOUNT = '62123456789';
    private const ID = '8001015009087';

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    public function test_the_quoted_fee_becomes_the_agencys_monthly_amount_when_the_agreement_is_fully_signed(): void
    {
        $owner  = $this->owner();
        $agency = $this->agency();
        AgencySubscription::forAgency((int) $agency->id)->forceFill(['discount_percent' => 10, 'discount_months' => 3, 'discount_starts_on' => now()->toDateString(), 'discount_note' => 'old deal'])->save();

        $doc = $this->send($owner, ['agency_id' => $agency->id, 'quoted_fee' => '6 200']);
        $this->agencySigns($doc, ['agents' => '40', 'branches' => '1']);
        $this->assertNull(AgencySubscription::forAgency((int) $agency->id)->custom_amount_zar, 'nothing changes before the agreement is fully signed');
        $this->countersign($owner, $doc)->assertOk();

        $sub = AgencySubscription::forAgency((int) $agency->id);
        $this->assertSame('completed', $doc->fresh()->status);
        $this->assertEquals(6200.0, (float) $sub->custom_amount_zar);
        $this->assertStringContainsString('signed Subscription Agreement (document ' . $doc->id, (string) $sub->custom_amount_note);
        $this->assertNull($sub->discount_percent, 'a custom amount and a discount are never both set (D5)');
        $this->assertNull($sub->discount_note);
        $this->assertSame(6200.0, app(SubscriptionPricingService::class)->quoteFor($agency->fresh())->payableZar);
        $this->assertSame(1, $doc->events()->where('event', 'billing_quoted_fee_applied')->count());
    }

    public function test_an_agreement_without_a_quoted_fee_leaves_billing_alone(): void
    {
        $owner  = $this->owner();
        $agency = $this->agency();
        AgencySubscription::forAgency((int) $agency->id)->forceFill(['discount_percent' => 10, 'discount_months' => 3, 'discount_starts_on' => now()->toDateString()])->save();

        $doc = $this->send($owner, ['agency_id' => $agency->id]);
        $this->agencySigns($doc, ['agents' => '4', 'branches' => '1']);
        $this->countersign($owner, $doc)->assertOk();

        $sub = AgencySubscription::forAgency((int) $agency->id);
        $this->assertNull($sub->custom_amount_zar);
        $this->assertEquals(10, (float) $sub->discount_percent, 'an existing discount is untouched');
        $this->assertSame(0, $doc->events()->where('event', 'like', 'billing_%')->count());
    }

    public function test_a_quoted_fee_is_not_applied_when_the_agency_signs_in_a_band(): void
    {
        $owner  = $this->owner();
        $agency = $this->agency();
        AgencySubscription::forAgency((int) $agency->id)->forceFill(['discount_percent' => 10, 'discount_months' => 3, 'discount_starts_on' => now()->toDateString()])->save();

        $doc = $this->send($owner, ['agency_id' => $agency->id, 'quoted_fee' => '6200']);
        $this->agencySigns($doc, ['agents' => '12', 'branches' => '1']);
        $this->countersign($owner, $doc)->assertOk();

        $sub = AgencySubscription::forAgency((int) $agency->id);
        $this->assertNull($sub->custom_amount_zar, 'signed in a band: billing follows the bands, not the quote');
        $this->assertEquals(10, (float) $sub->discount_percent, 'an existing discount is untouched');
        $this->assertSame(0, $doc->events()->where('event', 'billing_quoted_fee_applied')->count());
        $this->assertSame(1, $doc->events()->where('event', 'billing_quoted_fee_not_applied')->count());
    }

    public function test_the_monthly_amount_includes_additional_branches(): void
    {
        $owner  = $this->owner();
        $agency = $this->agency();
        $doc = $this->send($owner, ['agency_id' => $agency->id, 'quoted_fee' => '6200']);
        $this->agencySigns($doc, ['agents' => '40', 'branches' => '3']);
        $this->countersign($owner, $doc)->assertOk();

        $branch = (float) array_merge(AgreementPricing::BAND_RATES, (array) $doc->fresh()->wording->rates_json)['branch'];
        $this->assertGreaterThan(0, $branch);
        $this->assertEquals(6200 + 2 * $branch, (float) AgencySubscription::forAgency((int) $agency->id)->custom_amount_zar);
    }

    public function test_a_whole_term_discount_is_carried_into_the_monthly_amount(): void
    {
        $owner  = $this->owner();
        $agency = $this->agency();
        $doc = $this->send($owner, ['agency_id' => $agency->id, 'quoted_fee' => '6200', 'variation_type' => 'percent', 'variation_value' => '10']);
        $this->agencySigns($doc, ['agents' => '40', 'branches' => '1']);
        $this->countersign($owner, $doc)->assertOk();

        $this->assertEquals(5580.0, (float) AgencySubscription::forAgency((int) $agency->id)->custom_amount_zar);
        $this->assertSame(0, $doc->events()->where('event', 'billing_quoted_fee_discount_not_applied')->count());
    }

    public function test_a_discount_for_the_first_months_bills_the_normal_fee_and_says_so_on_the_document(): void
    {
        $owner  = $this->owner();
        $agency = $this->agency();
        $doc = $this->send($owner, ['agency_id' => $agency->id, 'quoted_fee' => '6200', 'variation_type' => 'percent', 'variation_value' => '10', 'variation_months' => '3']);
        $this->agencySigns($doc, ['agents' => '40', 'branches' => '1']);
        $this->countersign($owner, $doc)->assertOk();

        $this->assertEquals(6200.0, (float) AgencySubscription::forAgency((int) $agency->id)->custom_amount_zar);
        $this->assertSame(1, $doc->events()->where('event', 'billing_quoted_fee_discount_not_applied')->count());
    }

    public function test_completion_announces_the_agreement_once_with_the_agency_and_the_document(): void
    {
        $owner  = $this->owner();
        $agency = $this->agency();
        $doc = $this->send($owner, ['agency_id' => $agency->id, 'quoted_fee' => '6200']);
        $this->agencySigns($doc, ['agents' => '40', 'branches' => '1']);

        Event::fake([SubscriptionAgreementCompleted::class]);
        $this->countersign($owner, $doc)->assertOk();

        Event::assertDispatchedTimes(SubscriptionAgreementCompleted::class, 1);
        Event::assertDispatched(SubscriptionAgreementCompleted::class, fn ($e) => $e->agencyId === (int) $agency->id
            && $e->documentId === (int) $doc->id && $e->actorUserId === (int) $owner->id);
    }

    public function test_an_agreement_with_no_agency_linked_says_the_fee_was_not_applied(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, ['quoted_fee' => '6200']);
        $this->agencySigns($doc, ['agents' => '40', 'branches' => '1']);

        Event::fake([SubscriptionAgreementCompleted::class]);
        $this->countersign($owner, $doc)->assertOk();

        Event::assertNotDispatched(SubscriptionAgreementCompleted::class);
        $this->assertSame(1, $doc->events()->where('event', 'billing_quoted_fee_not_applied')->count());
    }

    public function test_the_listener_is_idempotent_and_only_touches_the_agreements_own_agency(): void
    {
        $owner  = $this->owner();
        $agency = $this->agency();
        $other  = $this->agency('Second Agency');
        $doc = $this->send($owner, ['agency_id' => $agency->id, 'quoted_fee' => '6200']);
        $this->agencySigns($doc, ['agents' => '40', 'branches' => '1']);
        $this->countersign($owner, $doc)->assertOk();

        event(new SubscriptionAgreementCompleted((int) $agency->id, (int) $doc->id, $owner->id)); // a replay
        $this->assertEquals(6200.0, (float) AgencySubscription::forAgency((int) $agency->id)->custom_amount_zar);
        $this->assertNull(AgencySubscription::forAgency((int) $other->id)->custom_amount_zar);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function svc(): AgreementService
    {
        return app(AgreementService::class);
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Platform Owner']);
    }

    private function agency(string $name = 'Cape Rentals'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . uniqid()]);
    }

    private function send(User $owner, array $extra = []): Document
    {
        Mail::fake();

        return $this->svc()->send($extra + ['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'cell' => '+27 82 555 0123'], $owner->id);
    }

    private function token(Document $d, string $role = 'r1'): string
    {
        return Signer::where('document_id', $d->id)->where('role_key', $role)->value('token');
    }

    private function agencySigns(Document $doc, array $override): void
    {
        $token = $this->token($doc);
        $total = $this->svc()->totalPages($doc);
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'pp'])->assertOk();
        foreach (range(1, $total) as $p) {
            $this->postJson(route('platform-esign.agreement.initial-page', [$token, $p]))->assertOk();
        }
        $values = $override + ['da_account' => self::ACCOUNT, 'm_account' => self::ACCOUNT] + AgreementSample::ctx($doc->wording)['values'] + ['sigA' => self::PNG, 'sigM' => self::PNG];
        $this->postJson(route('platform-esign.agreement.submit', $token), ['values' => $values, 'id_number' => self::ID, 'consent' => 1])->assertOk();
    }

    private function countersign(User $owner, Document $doc)
    {
        return $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), [
            'values' => ['rr_name' => 'Platform Owner', 'rr_capacity' => 'Director', 'rr_place' => 'Durban', 'rr_date' => now()->toDateString(), 'sigR' => self::PNG],
            'initials' => 'PO', 'pages' => range(1, $this->svc()->totalPages($doc)),
        ]);
    }
}
