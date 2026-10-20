<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Mail\Rentals\RentalJobCardCrewLinkMail;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Services\Rentals\JobSiteInfo;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * AT-459 (.ai/specs/rental-work-orders.md §14.32) — the single "Access notes" box is now WHO lets the worker in (name), HOW to reach them
 * (phone) and the NOTE. The work order holds it; the job card, the crew link page, the crew email and the printed card read it from there.
 * The crew page and the crew email also carry the tenant (when the agency allows it) and an Open in Maps link.
 */
final class CrewAccessContactAndSiteInfoTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Site Info');
    }

    private function accessFields(): array
    {
        return ['access_contact_name' => 'Caretaker Carl', 'access_contact_phone' => '082 555 0101', 'access_note' => 'Gate code 4321'];
    }

    // ── where it is kept ─────────────────────────────────────────────────────────────────

    public function test_the_work_order_holds_the_access_contact_and_the_job_card_reads_it_from_there(): void
    {
        $card = $this->makeJobCard();
        $wo = $card->workOrder()->firstOrFail();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.access-contact.update', $wo), $this->accessFields())
            ->assertRedirect()->assertSessionHasNoErrors();

        $wo->refresh();
        $this->assertSame(['Caretaker Carl', '082 555 0101', 'Gate code 4321'], [$wo->access_contact_name, $wo->access_contact_phone, $wo->access_note]);
        $this->assertNull($card->fresh()->access_notes, "the card's own copy is cleared so the work order is the single source");
        $read = JobSiteInfo::accessContact($wo, $card->fresh());
        $this->assertSame(['Caretaker Carl', '082 555 0101', '0825550101', 'Gate code 4321'], [$read['name'], $read['phone'], $read['tel'], $read['note']]);
    }

    public function test_the_job_card_edit_writes_to_its_work_order(): void
    {
        $card = $this->makeJobCard(['status' => RentalJobCard::STATUS_DRAFT, 'scheduled_at' => null]);

        $this->actingAs($this->admin)->put(route('corex.rental-job-cards.update', $card), ['title' => 'Fix the geyser properly'] + [
            'access_contact_name' => 'Caretaker Carl', 'access_contact_phone' => '082 555 0101', 'access_notes' => 'Gate code 4321',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Fix the geyser properly', $card->fresh()->title);
        $wo = $card->workOrder()->firstOrFail();
        $this->assertSame(['Caretaker Carl', '082 555 0101', 'Gate code 4321'], [$wo->access_contact_name, $wo->access_contact_phone, $wo->access_note]);
    }

    public function test_a_job_card_with_no_work_order_keeps_its_own_copy(): void
    {
        $card = $this->makeJobCard(['status' => RentalJobCard::STATUS_DRAFT, 'scheduled_at' => null]);
        $card->forceFill(['rental_work_order_id' => null])->save();

        $this->actingAs($this->admin)->put(route('corex.rental-job-cards.update', $card), [
            'title' => 'Fix the geyser', 'access_contact_name' => 'Own Copy', 'access_contact_phone' => '0821110000', 'access_notes' => 'Own note',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $card->refresh();
        $this->assertSame(['Own Copy', '0821110000', 'Own note'], [$card->access_contact_name, $card->access_contact_phone, $card->access_notes]);
        $this->assertSame('Own Copy', JobSiteInfo::accessContact(null, $card)['name']);
    }

    public function test_clearing_a_box_really_clears_it_and_a_closed_work_order_cannot_be_changed(): void
    {
        $card = $this->makeJobCard();
        $wo = $card->workOrder()->firstOrFail();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.access-contact.update', $wo), $this->accessFields());
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.access-contact.update', $wo), ['access_contact_name' => '', 'access_contact_phone' => '', 'access_note' => ''])->assertRedirect();

        $wo->refresh();
        $this->assertNull($wo->access_contact_name);
        $this->assertNull($wo->access_note);

        $wo->forceFill(['status' => \App\Models\RentalWorkOrder::STATUS_COMPLETED])->save();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.access-contact.update', $wo), $this->accessFields())->assertStatus(409);
        $this->assertNull($wo->fresh()->access_contact_name);
    }

    public function test_another_agencys_work_order_is_not_reachable(): void
    {
        $card = $this->makeJobCard();
        $wo = $card->workOrder()->firstOrFail();
        $other = \App\Models\Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $branch = \App\Models\Branch::forceCreate(['name' => 'Main', 'agency_id' => $other->id]);
        $stranger = \App\Models\User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $this->actingAs($stranger)->post(route('corex.rental-work-orders.access-contact.update', $wo), $this->accessFields())->assertStatus(404);
        $this->assertNull($wo->fresh()->access_contact_name);
    }

    // ── the crew link page ───────────────────────────────────────────────────────────────

    public function test_the_crew_page_shows_the_access_contact_the_tenant_and_open_in_maps(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_tenant_contact' => true]);
        $card = $this->makeJobCard();
        $this->attachTenant($card, 'Tina', 'Tenant', '083 111 2222');
        $wo = $card->workOrder()->firstOrFail();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.access-contact.update', $wo), $this->accessFields());
        auth()->logout();
        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($card->fresh(), $this->admin)['raw_token'];

        $html = $this->get(route('rentals.crew-job.show', $raw))->assertOk()->getContent();

        $this->assertStringContainsString('data-access-contact', $html);
        $this->assertStringContainsString('Caretaker Carl', $html);
        $this->assertStringContainsString('href="tel:0825550101"', $html);
        $this->assertStringContainsString('Gate code 4321', $html);
        $this->assertStringContainsString('Tina Tenant', $html);
        $this->assertStringContainsString('href="tel:0831112222"', $html);
        $this->assertStringContainsString('Open in Maps', $html);
    }

    public function test_the_crew_page_hides_the_tenant_when_the_agency_switched_it_off(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_tenant_contact' => false]);
        $card = $this->makeJobCard();
        $this->attachTenant($card, 'Tina', 'Tenant', '083 111 2222');
        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($card->fresh(), $this->admin)['raw_token'];

        $html = $this->get(route('rentals.crew-job.show', $raw))->assertOk()->getContent();

        $this->assertStringNotContainsString('Tina', $html);
        $this->assertStringNotContainsString('0831112222', $html);
        $this->assertStringContainsString('Key is under the pot plant', $html, 'the access note still shows');
    }

    // ── the crew email ───────────────────────────────────────────────────────────────────

    public function test_the_crew_email_carries_access_contact_tenant_and_open_in_maps(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_tenant_contact' => true]);
        $card = $this->makeJobCard();
        $this->attachTenant($card, 'Tina', 'Tenant', '083 111 2222');
        $wo = $card->workOrder()->firstOrFail();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.access-contact.update', $wo), $this->accessFields());

        $html = (new RentalJobCardCrewLinkMail($card->fresh(), 'https://example.invalid/secure/job-cards/abc', null, $this->admin))->render();

        $this->assertStringContainsString('Open in Maps', $html);
        $this->assertStringContainsString('google.com/maps/search', $html);
        $this->assertStringContainsString('Tina Tenant', $html);
        $this->assertStringContainsString('href="tel:0831112222"', $html);
        $this->assertStringContainsString('Caretaker Carl', $html);
        $this->assertStringContainsString('href="tel:0825550101"', $html);
        $this->assertStringContainsString('Gate code 4321', $html);
    }

    public function test_the_crew_email_leaves_the_tenant_out_when_the_agency_switched_it_off(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_tenant_contact' => false]);
        $card = $this->makeJobCard();
        $this->attachTenant($card, 'Tina', 'Tenant', '083 111 2222');

        $html = (new RentalJobCardCrewLinkMail($card->fresh(), 'https://example.invalid/secure/job-cards/abc', null, $this->admin))->render();

        $this->assertStringNotContainsString('Tina', $html);
        $this->assertStringNotContainsString('0831112222', $html);
        $this->assertStringContainsString('Open in Maps', $html);
        $this->assertStringContainsString('Key is under the pot plant', $html);
    }
}
