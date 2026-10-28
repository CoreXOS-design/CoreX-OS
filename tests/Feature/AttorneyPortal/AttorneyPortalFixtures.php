<?php

namespace Tests\Feature\AttorneyPortal;

use App\Mail\OtpMail;
use App\Models\Agency;
use App\Models\AttorneyPortalSetting;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\AttorneyPortal\AttorneyPortalAccessService;
use App\Services\DealV2\DocumentDistributionMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Shared builders for the attorney portal tests. One test = one agency signed in (AgencyScope is memoised per process). */
trait AttorneyPortalFixtures
{
    protected function world(bool $enabled = true): array
    {
        Storage::fake('local');
        Mail::fake();

        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'cr-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'name' => 'Agent Smith']);
        AttorneyPortalSetting::create(['agency_id' => $agency->id, 'attorney_portal_enabled' => $enabled]);

        $firm = AgencyServiceProvider::create(['agency_id' => $agency->id, 'name' => 'BBB Inc', 'specialty' => 'transfer_attorney', 'email' => 'firm@bbb.co.za', 'is_active' => true, 'created_by_id' => $agent->id]);
        $a = AgencyServiceProviderContact::create(['agency_id' => $agency->id, 'service_provider_id' => $firm->id, 'attorney_name' => 'Adv Botha', 'email' => 'botha@bbb.co.za', 'is_active' => true, 'created_by_id' => $agent->id]);
        $b = AgencyServiceProviderContact::create(['agency_id' => $agency->id, 'service_provider_id' => $firm->id, 'attorney_name' => 'Adv Naidoo', 'email' => 'naidoo@bbb.co.za', 'is_active' => true, 'created_by_id' => $agent->id]);

        return compact('agency', 'branch', 'agent', 'firm', 'a', 'b');
    }

    /** A deal (+twin, +property, +seller) with the given transfer / bond attorney contacts. */
    protected function deal(array $w, array $attorney = [], array $over = []): array
    {
        $agency = $w['agency'];
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScope(AgencyScope::class)->create([
            'external_id' => 'T-' . Str::random(6), 'title' => 'Home', 'address' => ($over['address'] ?? '12 Marine Dr'), 'suburb' => 'Shelly Beach',
            'agent_id' => $w['agent']->id, 'agency_id' => $agency->id, 'branch_id' => $w['branch']->id,
        ]));
        $seller = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $w['branch']->id, 'first_name' => 'Annelise', 'last_name' => 'vd Merwe',
            'email' => 'seller' . Str::random(4) . '@ex.co.za', 'phone' => '0821234567', 'created_by_user_id' => $w['agent']->id, 'agent_id' => $w['agent']->id,
        ]));
        $buyer = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $w['branch']->id, 'first_name' => 'Pieter', 'last_name' => 'Buyer',
            'email' => 'buyer' . Str::random(4) . '@ex.co.za', 'phone' => '0829999999', 'created_by_user_id' => $w['agent']->id, 'agent_id' => $w['agent']->id,
        ]));
        $property->contacts()->attach($seller->id, ['role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);

        $twinId = DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $w['agent']->id,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $w['branch']->id, 'agency_id' => $agency->id,
            'created_by_id' => $w['agent']->id, 'property_id' => $property->id, 'created_at' => now(), 'updated_at' => now(),
            'expected_registration' => $over['expected_registration'] ?? null,
        ]);
        $deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_950_000, 'total_commission' => 112_125,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Annelise vd Merwe', 'buyer_name' => 'Pieter Buyer', 'property_address' => ($over['address'] ?? '12 Marine Dr'),
            'agency_id' => $agency->id, 'branch_id' => $w['branch']->id, 'property_id' => $property->id, 'deal_v2_id' => $twinId,
            'attorney_provider_id' => $attorney['transfer_firm'] ?? null,
            'attorney_contact_id' => $attorney['transfer'] ?? null,
            'bond_attorney_provider_id' => $attorney['bond_firm'] ?? null,
            'bond_attorney_contact_id' => $attorney['bond'] ?? null,
            'registration_date' => $over['registration_date'] ?? null,
            'accepted_status' => $over['accepted_status'] ?? null,
        ]));
        DB::table('deal_contacts')->insert([
            ['deal_id' => $deal->id, 'contact_id' => $seller->id, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()],
            ['deal_id' => $deal->id, 'contact_id' => $buyer->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return compact('deal', 'property', 'seller', 'buyer', 'twinId') + ['agency' => $agency, 'agent' => $w['agent']];
    }

    /** Attorney set on a deal: contact $c of firm $w['firm'] as transfer attorney. */
    protected function transferTo(array $w, AgencyServiceProviderContact $c): array
    {
        return ['transfer' => $c->id, 'transfer_firm' => $w['firm']->id];
    }

    /** File a document; $on = 'deal' | 'property' | 'contact'. */
    protected function filedDoc(array $d, string $slug, string $on = 'deal', ?Contact $contact = null): Document
    {
        $type = DocumentType::firstOrCreate(['slug' => $slug], ['label' => ucfirst(str_replace('_', ' ', $slug)), 'sort_order' => 1, 'is_active' => true]);
        $path = 'docs/' . Str::random(8) . '.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4 {$slug} " . Str::random(6));
        $doc = Document::withoutGlobalScopes()->create([
            'agency_id' => $d['agency']->id, 'original_name' => strtoupper($slug) . '.pdf', 'storage_path' => $path, 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 1000, 'document_type_id' => $type->id,
            'source_type' => $on === 'deal' ? 'deal' : 'upload', 'source_id' => $on === 'deal' ? $d['deal']->id : null,
            'deal_id' => $on === 'deal' ? $d['twinId'] : null, 'uploaded_by' => $d['agent']->id,
        ]);
        if ($on === 'property') {
            $doc->properties()->syncWithoutDetaching([$d['property']->id]);
        }
        if ($on === 'contact') {
            $doc->contacts()->syncWithoutDetaching([$contact->id]);
        }

        return $doc;
    }

    protected function matrix(int $agencyId, string $slug, array $roles): void
    {
        $typeId = DocumentType::firstOrCreate(['slug' => $slug], ['label' => ucfirst($slug), 'sort_order' => 1, 'is_active' => true])->id;
        app(DocumentDistributionMatrix::class)->setTypeDistribution($agencyId, $typeId, $roles);
    }

    /** Invite $contact and run the real link + emailed-code sign-in over HTTP. Returns [token, cookieValue]. */
    protected function signIn(AgencyServiceProviderContact $contact, User $actor): array
    {
        $result = app(AttorneyPortalAccessService::class)->invite($contact, $actor);
        $token  = basename(parse_url($result['url'], PHP_URL_PATH));

        $this->get(route('attorney.link', $token))->assertOk();
        $this->post(route('attorney.code', $token))->assertRedirect();

        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) { $code = $m->code; return true; });

        $resp = $this->post(route('attorney.verify', $token), ['code' => $code]);
        $resp->assertRedirect(route('attorney.deals'));
        $cookie = $resp->getCookie(config('attorney-portal.cookie'), false);
        $this->assertNotNull($cookie, 'the sign-in sets the portal cookie');

        return [$token, $cookie->getValue()];
    }
}
