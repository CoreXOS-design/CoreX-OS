<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Models\Contact;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrderQuote;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.27.5 — exactly what the crew sees on the
 * phone: no prices by default, prices only with the setting, the tenant's
 * name + phone only with the setting, and never the landlord, quote amounts,
 * approval state, history or other cards.
 */
final class CrewJobLinkViewTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew View');
    }

    private function open(\App\Models\RentalJobCard $card): \Illuminate\Testing\TestResponse
    {
        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin);

        return $this->get('/secure/job-cards/' . $issued['raw_token']);
    }

    public function test_the_view_has_the_job_and_no_prices_by_default(): void
    {
        $card = $this->makeJobCard();
        $card->forceFill(['scheduled_at' => now()->addDay()])->save();

        $this->open($card)->assertOk()
            ->assertSee('Fix the geyser')->assertSee('12 Crew Street', false)->assertSee('Key is under the pot plant')
            ->assertSee('Team 1')->assertSee('What to load')->assertSee('Geyser element')->assertSee('Plumber hour')
            ->assertSee('Upload photos')
            ->assertSee('Mark work completed')
            ->assertDontSee('R 900')->assertDontSee('450.00')->assertDontSee('1,800.00');
    }

    public function test_prices_show_only_with_the_agency_setting(): void
    {
        $card = $this->makeJobCard();
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_prices' => true]);

        $this->open($card)->assertOk()->assertSee('R 900.00')->assertSee('R 1,800.00');
    }

    public function test_tenant_name_and_phone_only_with_the_agency_setting(): void
    {
        $card = $this->makeJobCard();
        $this->attachTenant($card->fresh(), 'Tina', 'Tenant', '0831112222');

        $this->open($card->fresh())->assertOk()->assertDontSee('Tina Tenant')->assertDontSee('0831112222');

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_tenant_contact' => true]);
        $this->open($card->fresh())->assertOk()->assertSee('Tina Tenant')->assertSee('0831112222');
    }

    public function test_never_the_landlord_quote_or_history(): void
    {
        $landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Lenny', 'last_name' => 'Landlordson', 'email' => 'lenny@example.invalid', 'phone' => '0849998888',
        ]);
        ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');
        $card = $this->makeJobCard();
        $card->logUpdate('quote_sent', $this->admin, 'Secret quote note R 77,777');
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_prices' => true, 'crew_link_show_tenant_contact' => true]);

        $this->open($card)->assertOk()
            ->assertDontSee('Lenny')->assertDontSee('Landlordson')->assertDontSee('0849998888')->assertDontSee('lenny@example.invalid')
            ->assertDontSee('Secret quote note')->assertDontSee('77,777')
            ->assertDontSee('Quote Rev')->assertDontSee('approval', false);
    }

    public function test_another_cards_title_never_appears(): void
    {
        $other = $this->makeJobCard(['title' => 'Somebody else job']);
        $card = $this->makeJobCard();

        $this->open($card)->assertOk()->assertDontSee('Somebody else job');
    }

    public function test_the_page_is_mobile_ready_and_not_indexed(): void
    {
        $card = $this->makeJobCard();

        $this->open($card)->assertOk()
            ->assertSee('name="viewport"', false)->assertSee('noindex', false)
            ->assertSee('capture="environment"', false);
    }

    public function test_the_view_carries_the_agency_name_not_a_hardcoded_one(): void
    {
        $card = $this->makeJobCard();

        $this->open($card)->assertOk()->assertSee('Crew View Agency')->assertDontSee('Home Finders');
    }
}
