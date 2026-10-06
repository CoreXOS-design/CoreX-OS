<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow\Concerns;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalMailDispatcher;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;

/**
 * Build 2 (.ai/specs/rental-work-orders.md §17.20) — shared fixtures for the approvals / variation / emergency / external-flow
 * tests: the crew-link world (agency, branch, admin, rental property) plus a landlord with a deliverable (@example.invalid)
 * email, a recording fake of the agency mailbox path, suppliers, and ready-made internal and external work orders.
 * Nothing here can reach a real inbox.
 */
trait BuildsApprovalFixtures
{
    use BuildsCrewLinkFixtures;

    protected object $mailbox;
    protected Contact $landlord;

    protected function approvalWorld(string $label = 'Approvals'): void
    {
        $this->buildCrewLinkWorld($label);
        // the crew fixtures set a very high limit so jobs already "under way" are authorised; these tests are ABOUT the limit
        $this->property->forceFill(['rental_no_approval_spend_threshold' => null])->save();
        Mail::fake();
        $this->mailbox = new class extends RentalMailDispatcher {
            /** @var array<int, array{0: ?string, 1: BaseSignatureMail}> */
            public array $sent = [];

            public function __construct() {}

            public function send(?string $recipientEmail, BaseSignatureMail $mail): void
            {
                $this->sent[] = [$recipientEmail, $mail];
            }
        };
        $this->app->instance(RentalMailDispatcher::class, $this->mailbox);
        $this->landlord = $this->linkLandlord();
    }

    protected function linkLandlord(string $first = 'Lenny', string $role = 'landlord'): Contact
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => 'Landlordson', 'email' => strtolower($first) . '-' . uniqid() . '@example.invalid',
        ]);
        $this->property->contacts()->attach($contact->id, ['role' => $role]);

        return $contact;
    }

    /** @return array<int, BaseSignatureMail> every mail sent through the agency mailbox path, optionally of one class */
    protected function sent(?string $class = null): array
    {
        return array_values(array_map(fn ($row) => $row[1], array_filter($this->mailbox->sent, fn ($row) => $class === null || $row[1] instanceof $class)));
    }

    protected function supplier(string $name = 'Acme Plumbing', ?string $email = 'acme@example.invalid'): AgencyServiceProvider
    {
        return AgencyServiceProvider::create([
            'agency_id' => $this->agency->id, 'name' => $name, 'email' => $email, 'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
    }

    protected function setting(array $attrs): void
    {
        RentalWorkOrderSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], $attrs);
    }

    /** An outside-contractor work order (no job card). */
    protected function externalWorkOrder(array $attrs = []): RentalWorkOrder
    {
        return RentalWorkOrder::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'title' => 'Burst pipe', 'description' => 'Pipe burst under the sink', 'trade_type' => 'plumbing',
            'status' => RentalWorkOrder::STATUS_REPORTED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    /**
     * An internal job (work order + draft job card together) with ONE priced part line of $price (excl VAT; the fixture agency
     * is not VAT registered, so that is also what the owner pays).
     *
     * @return array{0: RentalJobCard, 1: RentalWorkOrder}
     */
    protected function internalJob(float $price = 1000.0, array $lineAttrs = []): array
    {
        $service = app(RentalJobCardService::class);
        $card = $service->createForProperty($this->property, ['title' => 'Replace the geyser'], $this->admin);
        $service->addLine($card, array_merge(['type' => 'part', 'description' => 'Geyser 150L', 'quantity' => 1, 'unit' => 'each', 'unit_price' => $price], $lineAttrs), $this->admin);

        return [$card->fresh(), $card->workOrder()->first()];
    }

    /** The job's quote sent to the owner; returns the work order after the gate decided. */
    protected function sendQuote(RentalJobCard $card): RentalWorkOrder
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();

        return $card->workOrder()->first();
    }

    /** The words a person would read in a rendered mail / page / PDF view: no <style>, no tags, entities decoded. */
    protected function visibleText(string $html): string
    {
        return html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#si', '', $html)), ENT_QUOTES);
    }

    protected function clientFor(Contact $contact): ClientUser
    {
        $clientUser = ClientUser::create(['email' => $contact->email, 'current_agency_id' => $contact->agency_id]);
        $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();

        return $clientUser;
    }
}
