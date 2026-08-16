<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\DealStepWorkOrder;
use App\Models\User;
use App\Services\DealV2\CocWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-329 — "2 COCs, 1 email" root cause. A supplier's address must resolve from the firm-level
 * email OR — when that is blank — from the supplier's CONTACT PERSON email (spec Q5, "addresses the
 * supplier's primary contact"). Before the fix the trigger loop only read the firm email, so a COC
 * whose supplier carries its email on the contact silently resolved to no address and never sent
 * (deal 1806: Electrical firm-email sent, Entomologist contact-email dropped). This pins the
 * resolver contract: firm email wins; contact email is the fallback; both-blank stays unsendable.
 */
final class WorkOrderRecipientResolutionTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);
        $this->actingAs($this->admin); // BelongsToAgency global scope reads the authed user's agency
    }

    private function provider(?string $firmEmail): AgencyServiceProvider
    {
        return AgencyServiceProvider::create([
            'agency_id' => $this->agencyId, 'name' => 'Bugs-B-Gone', 'email' => $firmEmail,
            'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
    }

    private function workOrder(AgencyServiceProvider $p): DealStepWorkOrder
    {
        // resolveRecipient reads only responsible_party + service_provider_id — an unsaved model is
        // enough (and avoids the deal_step_instance FK, irrelevant to recipient resolution).
        return new DealStepWorkOrder([
            'agency_id' => $this->agencyId, 'service_type' => 'Beetle', 'responsible_party' => 'supplier',
            'service_provider_id' => $p->id, 'status' => 'pending',
        ]);
    }

    public function test_firm_email_is_used_when_present(): void
    {
        $p  = $this->provider('firm@bugs.co.za');
        $wo = $this->workOrder($p);

        $r = app(CocWorkOrderService::class)->resolveRecipient(new \App\Models\Deal(), $wo);

        $this->assertSame('provider', $r['type']);
        $this->assertSame('firm@bugs.co.za', $r['email']);
    }

    public function test_contact_email_is_the_fallback_when_firm_email_is_blank(): void
    {
        $p = $this->provider(null); // no firm-level email — the deal-1806 shape
        AgencyServiceProviderContact::create([
            'agency_id' => $this->agencyId, 'service_provider_id' => $p->id,
            'attorney_name' => 'T. Mkhize', 'email' => 'contact@bugs.co.za', 'is_active' => true,
        ]);
        $wo = $this->workOrder($p);

        $r = app(CocWorkOrderService::class)->resolveRecipient(new \App\Models\Deal(), $wo);

        // Before the fix this was null → "No email on file" → the COC silently never sent.
        $this->assertSame('contact@bugs.co.za', $r['email']);
    }

    public function test_both_blank_stays_unsendable(): void
    {
        $p = $this->provider(null);
        AgencyServiceProviderContact::create([
            'agency_id' => $this->agencyId, 'service_provider_id' => $p->id,
            'attorney_name' => 'No-Email Contact', 'email' => null, 'is_active' => true,
        ]);
        $wo = $this->workOrder($p);

        $r = app(CocWorkOrderService::class)->resolveRecipient(new \App\Models\Deal(), $wo);

        $this->assertNull($r['email']); // no address anywhere → send() throws the clear AT-329 reason
    }
}
