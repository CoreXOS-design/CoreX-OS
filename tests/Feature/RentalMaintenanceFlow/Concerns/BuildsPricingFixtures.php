<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow\Concerns;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrderSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\PerformanceSetting;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\CrewJobService;
use App\Services\Rentals\CrewViewContext;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;

/**
 * Shared fixtures for the maintenance-flow Build 1 tests (.ai/specs/rental-work-orders.md §17.4 / §17.5 / §17.11):
 * the crew-link world (agency, branch, admin, property, crew) plus pricing switched on, a landlord on the property,
 * the catalogue's types/units, and helpers to add office lines, crew drafts, and to grant exact role permissions.
 * Every address is @example.invalid — nothing here can reach a real inbox.
 */
trait BuildsPricingFixtures
{
    use BuildsCrewLinkFixtures;

    protected Contact $landlord;

    protected function pricingWorld(string $label = 'Pricing', array $settings = [], bool $vat = false, string $captureMode = Agency::VAT_CAPTURE_EXCL): void
    {
        $this->buildCrewLinkWorld($label);

        if ($vat) {
            $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => $captureMode]);
            PerformanceSetting::set('vat_rate', '15', $this->agency->id);
            RentalVatType::seedDefaultsFor($this->agency->id);
        }
        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);

        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], array_merge([
            'capture_prices_on_job_cards' => true, 'no_approval_spend_threshold' => 1000000,
        ], $settings));

        $this->landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Jane', 'last_name' => 'Landlord', 'email' => 'jane-' . uniqid() . '@example.invalid',
        ]);
        ContactPropertyLinker::link($this->landlord->id, $this->property->id, 'landlord');
    }

    /** An open job card with no lines, assigned to the crew. */
    protected function emptyCard(array $overrides = []): RentalJobCard
    {
        $card = app(RentalJobCardService::class)->createStandalone([
            'property_id' => $this->property->id, 'title' => 'Bathroom repair', 'access_notes' => 'Ring the bell',
        ], $this->admin);
        $card->forceFill(array_merge(['rental_crew_id' => $this->crew->id], $overrides))->save();

        return $card->fresh();
    }

    /** An OFFICE line, through the real service. */
    protected function officeLine(RentalJobCard $card, array $attributes): RentalJobCardLine
    {
        return app(RentalJobCardService::class)->addLine($card, $attributes + ['type' => 'part', 'quantity' => 1], $this->admin);
    }

    protected function catalogueItem(string $code, string $kind, ?float $price = null, ?float $cost = null): RentalCatalogueItem
    {
        return RentalCatalogueItem::create([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', $kind)->firstOrFail()->id,
            'code' => $code, 'description' => $code . ' item', 'default_price' => $price, 'default_cost' => $cost, 'sort_order' => 1,
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    protected function crewCtx(RentalJobCard $card, string $via = CrewViewContext::VIA_JOB_LINK, bool $showCosts = false): CrewViewContext
    {
        // Reuse the card's live link when there is one: issuing a NEW link revokes the old one, and a test that holds the raw
        // token of the first would then be looking at the "unavailable" page.
        $live = RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $card->id)
            ->where('purpose', RentalSecureAccessToken::PURPOSE_CREW_JOB_CARD)->whereNull('revoked_at')->latest('id')->first();
        $tokenId = $live?->id ?? app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin)['token']->id;

        return new CrewViewContext(
            agencyId: $this->agency->id, crewId: $this->crew->id, tokenId: $tokenId,
            via: $via, showCosts: $showCosts, showTenantContact: false,
            ip: '203.0.113.7', userAgent: 'TestPhone/1.0',
            actorLabel: ($via === CrewViewContext::VIA_CREW_PAGE ? 'via crew page' : 'via crew link') . ' — Team 1',
        );
    }

    /** A crew draft line through the real crew service. */
    protected function crewDraft(RentalJobCard $card, array $data = [], ?CrewViewContext $ctx = null): RentalJobCardLine
    {
        return app(CrewJobService::class)->addLine($card, $data + [
            'type' => 'part', 'description' => 'Tap washer', 'quantity' => 2, 'unit' => 'each', 'unit_cost' => 12.5,
        ], [], $ctx ?? $this->crewCtx($card));
    }

    /** A line the crew sent and the office has not yet decided (the state a crew's "Send to office" leaves it in). */
    protected function awaitingLine(RentalJobCard $card, array $data = []): RentalJobCardLine
    {
        $ctx = $this->crewCtx($card);
        $line = $this->crewDraft($card, $data, $ctx);
        app(CrewJobService::class)->sendToOffice($card, $ctx);

        return $line->fresh();
    }

    /** Make the agency "seeded" and give `$role` EXACTLY these permission keys (all with 'all' scope). */
    protected function grantExactly(string $role, array $keys): void
    {
        Role::firstOrCreate(['name' => $role, 'agency_id' => $this->agency->id], ['label' => ucfirst($role)]);
        \Illuminate\Support\Facades\DB::table('role_permissions')->where('role', $role)->where('agency_id', $this->agency->id)->delete();
        foreach ($keys as $key) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();
    }

    private int $roleCounter = 0;

    /**
     * A same-agency, same-branch user holding exactly $keys (+ the view key every job card page needs). Every call mints its OWN
     * role, so two users in one test can hold different keys (roles are per-agency rows; reusing "agent" would let the second
     * call silently rewrite the first user's grants).
     */
    protected function agentHolding(array $keys): User
    {
        $this->grantExactly('admin', ['rental_job_cards.view', 'rental_job_cards.create', 'rental_job_cards.share', 'rental_job_cards.sign_off', 'rental_job_cards.send_quote', 'rental_job_cards.price', 'rental_job_cards.view_costs', 'rental_work_orders.manage_settings', 'rental_catalogue.view', 'rental_catalogue.manage']);
        $role = 'tester' . (++$this->roleCounter) . uniqid();
        $this->grantExactly($role, array_values(array_unique(array_merge(['rental_job_cards.view'], $keys))));

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'email' => 'agent-' . uniqid() . '@example.invalid']);
    }

    protected function standardVat(): RentalVatType
    {
        return RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_AGENCY_RATE)->firstOrFail();
    }
}
