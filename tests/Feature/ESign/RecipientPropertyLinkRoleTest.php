<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * HD-1 / P1-3 — recipients are sourced from the PROPERTY-LINK role (esign-ceremony-v3 §2.1).
 *
 * The wizard used to resolve a linked contact's signing role from the contact's GLOBAL type
 * (contact_contact_type → contact_types.esign_role), while eager-loading the property-link
 * pivot role and throwing it away. That asks the wrong question. "Seller" on a contact record
 * means "this person has been a seller to us"; it does not mean they are the seller of THIS
 * property. The property link is the only thing that knows who a contact is to THIS document.
 *
 * The global type survives as a fallback for links that predate the role being mandatory.
 *
 * Input paths proven here:
 *   - link role BEATS a conflicting global type (the doctrine case)
 *   - every pivot-role variant maps correctly (owner/seller → seller, landlord/lessor → lessor,
 *     tenant → lessee, buyer → buyer), case- and whitespace-insensitively
 *   - a portal LEAD linked to the property is never offered, even when typed globally as a Buyer
 *   - a real party the template does not sign is skipped
 *   - a legacy link with NO role falls back to the global type
 *   - a legacy link with no role AND no type takes the template's default owner role
 *   - a template with no signing_parties still honours the link role
 */
final class RecipientPropertyLinkRoleTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private Property $property;
    private ReflectionMethod $resolve;

    /** contact_types.esign_role => contact_types.id */
    private array $typeIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Shelly Beach']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);
        $this->actingAs($this->user);

        // The schema snapshot carries no seed data — create the four canonical parents.
        foreach (['seller' => 'Seller', 'buyer' => 'Buyer', 'lessor' => 'Lessor', 'lessee' => 'Lessee'] as $role => $name) {
            $this->typeIds[$role] = DB::table('contact_types')->insertGetId([
                'name' => $name, 'esign_role' => $role, 'color' => '#6366f1',
                'sort_order' => 0, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->property = Property::create([
            'title'         => '3 Bed House, Ramsgate',
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->user->id,
            'branch_id'     => $this->branch->id,
            'listing_type'  => 'sale',
            'address'       => '27 Marine Drive, Ramsgate',
            'street_number' => '27',
            'street_name'   => 'Marine Drive',
            'suburb'        => 'Ramsgate',
            'town'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
            'price'         => 2350000,
            'property_type' => 'House',
        ]);

        $this->resolve = new ReflectionMethod(ESignWizardController::class, 'resolveLinkedContactRole');
        $this->resolve->setAccessible(true);
    }

    /**
     * Link a contact to the property with a given pivot role, optionally carrying a global
     * contact type. Returns the contact AS THE WIZARD SEES IT — re-read through the relation
     * so the pivot is really loaded, not faked.
     */
    private function linkContact(
        string $firstName,
        string $lastName,
        ?string $pivotRole,
        ?string $globalEsignRole = null,
    ): Contact {
        $contact = Contact::create([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'contact_type_id'    => $globalEsignRole ? $this->typeIds[$globalEsignRole] : null,
            'first_name'         => $firstName,
            'last_name'          => $lastName,
            'phone'              => '083 455 2019',
            'email'              => strtolower($firstName) . '@example.co.za',
        ]);

        // The multi-parent pivot (AT-79) is what the fallback actually reads.
        if ($globalEsignRole) {
            DB::table('contact_contact_type')->insert([
                'contact_id'      => $contact->id,
                'contact_type_id' => $this->typeIds[$globalEsignRole],
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        $this->property->contacts()->attach($contact->id, ['role' => $pivotRole]);

        return $this->property->contacts()->where('contacts.id', $contact->id)->first();
    }

    private function resolveFor(Contact $contact, array $allowed = ['seller', 'buyer'], string $default = 'seller'): ?string
    {
        return $this->resolve->invoke(new ESignWizardController(), $contact, $allowed, $default);
    }

    /**
     * THE doctrine case. Pieter sold his Uvongo flat through us last year, so he is typed
     * "Seller" globally. On THIS listing he is the purchaser. He signs as a BUYER.
     */
    public function test_property_link_role_beats_a_conflicting_global_contact_type(): void
    {
        $pieter = $this->linkContact('Pieter', 'van Niekerk', pivotRole: 'buyer', globalEsignRole: 'seller');

        $this->assertSame('buyer', $this->resolveFor($pieter),
            'The property-link role is the authority — a global "Seller" linked here as a buyer signs as a buyer.');
    }

    /** The e-sign auto-link writes 'owner' for a seller; both spellings are the seller. */
    public function test_owner_and_seller_link_roles_both_sign_as_seller(): void
    {
        $nomsa = $this->linkContact('Nomsa', 'Dlamini', pivotRole: 'owner');
        $ethan = $this->linkContact('Ethan', 'Reddy', pivotRole: 'seller');

        $this->assertSame('seller', $this->resolveFor($nomsa));
        $this->assertSame('seller', $this->resolveFor($ethan));
    }

    /** Rental vocabulary: landlord/lessor are one party; tenant signs as the lessee. */
    public function test_rental_link_roles_map_to_lessor_and_lessee(): void
    {
        $landlord = $this->linkContact('Sarel', 'Botha', pivotRole: 'landlord');
        $lessor   = $this->linkContact('Ayanda', 'Khumalo', pivotRole: 'lessor');
        $tenant   = $this->linkContact('Farhana', 'Patel', pivotRole: 'tenant');

        $allowed = ['lessor', 'lessee'];
        $this->assertSame('lessor', $this->resolveFor($landlord, $allowed, 'landlord'));
        $this->assertSame('lessor', $this->resolveFor($lessor, $allowed, 'landlord'));
        $this->assertSame('lessee', $this->resolveFor($tenant, $allowed, 'landlord'));
    }

    /**
     * The regression the old code shipped: the portal lead services (P24 / Private Property /
     * the website) link an enquirer to the listing with role='lead' and very often type them
     * "Buyer". Resolving from the global type offered that stranger as a purchaser on the
     * mandate. The link says lead — a lead is not a party, and there is no falling back.
     */
    public function test_a_portal_lead_is_never_offered_as_a_recipient(): void
    {
        $enquirer = $this->linkContact('Jaco', 'Steyn', pivotRole: 'lead', globalEsignRole: 'buyer');

        $this->assertNull($this->resolveFor($enquirer),
            'A lead who enquired about the listing is not a party to its documents.');
    }

    /** A genuine party on the property, but not one this template asks to sign. */
    public function test_a_party_whose_role_the_template_does_not_sign_is_skipped(): void
    {
        $buyer = $this->linkContact('Lindiwe', 'Zulu', pivotRole: 'buyer');

        $this->assertNull($this->resolveFor($buyer, allowed: ['seller']),
            'A mandate signed by sellers only does not offer the buyer.');
    }

    /** Legacy row from before the link role was mandatory — only now do we ask the global type. */
    public function test_a_legacy_link_with_no_role_falls_back_to_the_global_type(): void
    {
        $legacy = $this->linkContact('Willem', 'Fourie', pivotRole: null, globalEsignRole: 'seller');

        $this->assertSame('seller', $this->resolveFor($legacy),
            'With no link role to read, the global type is the only thing left to ask.');
    }

    /** No link role AND no type: absorb, do not crash — take the template's default owner role. */
    public function test_a_link_with_no_role_and_no_type_takes_the_default_owner_role(): void
    {
        $unknown = $this->linkContact('Refilwe', 'Mabaso', pivotRole: null);

        $this->assertSame('seller', $this->resolveFor($unknown, allowed: [], default: 'seller'));
    }

    /** The pivot is free-text varchar(50); a stored " Seller " must not become a stranger. */
    public function test_the_link_role_is_case_and_whitespace_insensitive(): void
    {
        $messy = $this->linkContact('Bongani', 'Ngcobo', pivotRole: '  Owner  ');

        $this->assertSame('seller', $this->resolveFor($messy));
    }

    /** A template with no signing_parties gates nothing — but the link role still decides. */
    public function test_a_template_with_no_signing_parties_still_honours_the_link_role(): void
    {
        $buyer = $this->linkContact('Shaun', 'Naidoo', pivotRole: 'buyer', globalEsignRole: 'seller');

        $this->assertSame('buyer', $this->resolveFor($buyer, allowed: []),
            'An ungated template still must not mistake a buyer for a seller.');
    }

    /** Joint sellers: both come through, neither collapses into the other. */
    public function test_joint_sellers_are_both_offered(): void
    {
        $this->linkContact('Johan', 'Muller', pivotRole: 'owner');
        $this->linkContact('Marlene', 'Muller', pivotRole: 'owner');

        $roles = $this->property->contacts()->get()
            ->map(fn (Contact $c) => $this->resolveFor($c))
            ->all();

        $this->assertSame(['seller', 'seller'], $roles);
    }

    /** The vocabulary map itself — the pivot canon (LINK_ROLES) plus the lead exclusion. */
    public function test_the_pivot_role_vocabulary_map_is_total(): void
    {
        $this->assertSame('seller', Property::esignRoleForPivotRole('seller'));
        $this->assertSame('seller', Property::esignRoleForPivotRole('owner'));
        $this->assertSame('buyer', Property::esignRoleForPivotRole('buyer'));
        $this->assertSame('lessor', Property::esignRoleForPivotRole('landlord'));
        $this->assertSame('lessor', Property::esignRoleForPivotRole('lessor'));
        $this->assertSame('lessee', Property::esignRoleForPivotRole('tenant'));
        $this->assertSame('lessee', Property::esignRoleForPivotRole('lessee'));

        $this->assertNull(Property::esignRoleForPivotRole('lead'));
        $this->assertNull(Property::esignRoleForPivotRole(null));
        $this->assertNull(Property::esignRoleForPivotRole(''));
        $this->assertNull(Property::esignRoleForPivotRole('conveyancer'));

        $this->assertContains('lead', Property::PIVOT_NON_SIGNING_ROLES);
    }
}
