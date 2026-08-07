<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Live incident 2026-08-07 — Retha's WhatsApp pitch to "Belinda Ehlers Meyer" reported
 * "this number is not on WhatsApp" (never delivered). She confirmed not_sent on the sent
 * page, corrected the contact's phone number, and could no longer resend: the compose
 * screen hard-blocked with "Recently contacted" citing the very pitch that never sent.
 *
 * Root cause: markOutreachPending() (AT-81) is stamped optimistically at send-click, before
 * the agent's truthful "did it send?" confirmation exists. markNotSent() correctly mirrored
 * the failure to the comms archive (excluding it from tile counts + last-contacted) but never
 * released the AT-81 clock it started — so isOutreachPending() stayed true forever, hard-
 * blocking every future compose via OutreachContext::isSendable()'s pendingBlocks check.
 * Separately, cooldownSignal() (the "Recently contacted" soft banner) looked up the most
 * recent send by sent_at alone, with no outcome filter, so it kept citing the not_sent row.
 *
 * Fix: markNotSent() now calls clearOutreachPending(); cooldownSignal() now excludes
 * outcome = not_sent from its lookup.
 */
final class OutreachNotSentReleasesPendingTest extends TestCase
{
    use RefreshDatabase;

    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
            'phone'     => '+27821110000',
        ]);
        return [$agencyId, $user->id];
    }

    private function seedContactWithAddress(int $agencyId, string $firstName = 'Belinda'): Contact
    {
        return Contact::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'first_name'    => $firstName,
            'last_name'     => 'EhlersMeyer',
            'phone'         => '+2782' . random_int(1000000, 9999999),
            'email'         => strtolower($firstName) . '-' . Str::random(6) . '@example.test',
            'street_number' => '25',
            'street_name'   => 'Shepstone Street',
            'suburb'        => 'Margate',
        ]);
    }

    private function seedDefaultTemplate(int $agencyId): void
    {
        DB::table('seller_outreach_templates')->insert([
            'agency_id'              => $agencyId,
            'name'                   => 'Prospecting Introduction — Sales & Rentals',
            'channel'                => 'whatsapp',
            'subject'                => null,
            'body'                   => "Hi {seller_name}, demand is strong in {property_town}. {tracking_link} Reply STOP to {opt_out_link}.",
            'description'            => 'test default',
            'is_active'              => true,
            'is_default_for_channel' => true,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    /**
     * The exact repro: send -> agent confirms not_sent -> assert the AT-81 clock is
     * released AND a fresh compose is sendable again, with no stale cooldown banner.
     */
    public function test_not_sent_releases_pending_clock_and_unblocks_resend(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedContactWithAddress($agencyId);
        $agent = User::find($userId);

        $this->assertSame(Contact::OUTREACH_INITIAL, $contact->outreachConsentState());

        // 1. Send the pitch — born 'sent' optimistically, AT-81 clock starts.
        $sendResponse = $this->actingAs($agent)
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel' => 'whatsapp',
                'body'    => "Hi {seller_name}. {tracking_link} Reply STOP.",
            ]);
        $sendResponse->assertOk();
        $sendId = $sendResponse->json('send_id') ?? $sendResponse->json('id');
        $this->assertNotNull($sendId, 'submit() must return the send id for the sent-page flow');

        $contact->refresh();
        $this->assertTrue($contact->isOutreachPending(), 'sending starts the AT-81 clock');
        $this->assertSame(SellerOutreachSend::OUTCOME_SENT, SellerOutreachSend::withoutGlobalScopes()->findOrFail($sendId)->outcome);

        // 2. Agent confirms on the sent page: WhatsApp did NOT actually go out.
        $notSentResponse = $this->actingAs($agent)
            ->postJson(route('seller-outreach.composer.not-sent', ['contact' => $contact, 'send' => $sendId]));
        $notSentResponse->assertOk();

        $send = SellerOutreachSend::withoutGlobalScopes()->findOrFail($sendId);
        $this->assertSame(SellerOutreachSend::OUTCOME_NOT_SENT, $send->outcome);

        // 3. THE FIX — the AT-81 clock must be released, not left stuck forever.
        $contact->refresh();
        $this->assertFalse($contact->isOutreachPending(), 'a pitch that never delivered must not stay pending');
        $this->assertNull($contact->outreach_permission_asked_at);

        // 4. A fresh compose must be sendable again — no pendingBlocks hard block,
        //    and no stale "Recently contacted" citing the not_sent pitch. Call the
        //    composer service directly (same call the compose screen makes) rather
        //    than rendering the full page — no Vite/asset dependency, pure logic.
        $this->actingAs($agent);
        $context = app(\App\Services\SellerOutreach\SellerOutreachComposerService::class)
            ->composeContext($agencyId, $contact->fresh(), null, 'whatsapp', null, $agent);

        $this->assertFalse($context->pendingBlocks, 'not_sent must not leave the contact hard-blocked as pending');
        $this->assertTrue($context->isSendable(), 'agent must be able to resend after a confirmed not_sent');
        $this->assertNull($context->cooldownSignal, 'a not_sent pitch must not trigger the recently-contacted banner');
    }

    /**
     * A GENUINE sent pitch must still start and hold the AT-81 clock, and must still
     * populate the cooldown banner — this fix must not weaken the real guard.
     */
    public function test_genuinely_sent_pitch_still_blocks_and_shows_cooldown(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedContactWithAddress($agencyId, 'Genuine');
        $agent = User::find($userId);

        $sendResponse = $this->actingAs($agent)
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel' => 'whatsapp',
                'body'    => "Hi {seller_name}. {tracking_link} Reply STOP.",
            ]);
        $sendResponse->assertOk();

        $contact->refresh();
        $this->assertTrue($contact->isOutreachPending());

        $this->actingAs($agent);
        $context = app(\App\Services\SellerOutreach\SellerOutreachComposerService::class)
            ->composeContext($agencyId, $contact->fresh(), null, 'whatsapp', null, $agent);

        $this->assertTrue($context->pendingBlocks, 'a genuinely-pending contact must stay hard-blocked');
        $this->assertFalse($context->isSendable());
        $this->assertNotNull($context->cooldownSignal, 'a genuine sent pitch must still show the recently-contacted banner');
    }
}
