<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-45 — Contact messaging opt-in marker.
 *
 * Mirrors the opt-out triplet (migration 2026_05_14_080004). Opt-in is a
 * recorded FACT: it sets the messaging_opt_in_* columns + isOptedIn(), is
 * recorded through a route/permission gate identical to opt-out, is INDEPENDENT
 * of opt-out (does not clear it), and does NOT change the send gate.
 */
final class MessagingOptInTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_opt_in_model_method_sets_triplet_and_helper(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->actingAs(User::find($userId))->seedContact($agencyId);

        $this->assertFalse($contact->isOptedIn());

        $contact->recordOptIn('YES via WhatsApp', $userId);
        $contact->refresh();

        $this->assertNotNull($contact->messaging_opted_in_at);
        $this->assertSame('YES via WhatsApp', $contact->messaging_opt_in_reason);
        $this->assertSame($userId, (int) $contact->messaging_opt_in_recorded_by_user_id);
        $this->assertTrue($contact->isOptedIn());
    }

    public function test_opt_in_route_records_consent_and_redirects_back(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->actingAs(User::find($userId))->seedContact($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->from(route('seller-outreach.composer.timeline', $contact))
            ->post(route('seller-outreach.composer.opt-in', $contact), [
                'reason' => 'Seller replied YES via WhatsApp',
            ]);

        $resp->assertStatus(302);
        $resp->assertSessionHas('status');

        $contact->refresh();
        $this->assertTrue($contact->isOptedIn());
        $this->assertSame('Seller replied YES via WhatsApp', $contact->messaging_opt_in_reason);
        $this->assertSame($userId, (int) $contact->messaging_opt_in_recorded_by_user_id);
    }

    public function test_opt_in_requires_a_reason(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->actingAs(User::find($userId))->seedContact($agencyId);

        $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.composer.opt-in', $contact), [])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($contact->fresh()->isOptedIn());
    }

    public function test_opt_in_and_opt_out_are_independent_facts(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->actingAs(User::find($userId))->seedContact($agencyId);

        // Opt the contact OUT first (the hard block).
        $contact->update([
            'messaging_opt_out_at'                  => now(),
            'messaging_opt_out_reason'              => 'STOP via WhatsApp',
            'messaging_opt_out_recorded_by_user_id' => $userId,
        ]);

        // Record an opt-in afterwards (the re-consent path) — it must NOT clear
        // the opt-out. Both facts coexist; the send gate still honours opt-out.
        $contact->recordOptIn('Later replied YES', $userId);
        $contact->refresh();

        $this->assertTrue($contact->isOptedIn(), 'opt-in recorded');
        $this->assertNotNull($contact->messaging_opt_out_at, 'opt-out untouched by opt-in');
        $this->assertSame('STOP via WhatsApp', $contact->messaging_opt_out_reason);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $user->id];
    }

    private function seedContact(int $agencyId): Contact
    {
        return Contact::create([
            'agency_id'  => $agencyId,
            'branch_id'  => $agencyId,
            'first_name' => 'Test',
            'last_name'  => 'Seller',
            'phone'      => '0821234567',
            'email'      => 'seller@test.example',
        ]);
    }
}
