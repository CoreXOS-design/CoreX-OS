<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use App\Services\SellerOutreach\MarketingConsentService;
use App\Services\SellerOutreach\SellerOutreachLandingService;
use App\Services\SellerOutreach\TransactionStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-81 — outreach no-response lapse + the 5-state consent doctrine.
 *
 * States: INITIAL → (send) PENDING → (reply yes) CONFIRMED | (reply no) DECLINED
 * | (silence past window) NO_RESPONSE. NO_RESPONSE is master opted-out but
 * DISTINCT from DECLINED (re-contactable in future). Proven paths: pending-on-
 * send + re-send block, opt-in/opt-out/click clear pending, timeout lapse
 * (dry-run + live), every non-lapse guard, mid-reply safety, decline-upgrades-
 * no_response, and that NO_RESPONSE never reads as an explicit decline.
 */
final class OutreachNoResponseLapseTest extends TestCase
{
    use RefreshDatabase;

    /** Setting default is 7 and is agency-configurable. */
    public function test_no_response_window_defaults_to_seven_and_is_configurable(): void
    {
        [$agencyId] = $this->seedAgency();
        $settings = AgencyContactSettings::forAgency($agencyId);
        $this->assertSame(7, $settings->outreachNoResponseDays());

        $settings->update(['outreach_no_response_days' => 21]);
        $this->assertSame(21, AgencyContactSettings::forAgency($agencyId)->outreachNoResponseDays());
    }

    /** 1 — sending a consent-request moves INITIAL → PENDING and blocks a re-send. */
    public function test_send_moves_contact_to_pending_and_blocks_resend(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedContactWithAddress($agencyId);

        $this->assertSame(Contact::OUTREACH_INITIAL, $contact->outreachConsentState());

        $first = $this->actingAs(User::find($userId))
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel' => 'whatsapp',
                'body'    => "Hi {seller_name}. {tracking_link} Reply STOP.",
            ]);
        $first->assertOk();

        $contact->refresh();
        $this->assertNotNull($contact->outreach_permission_asked_at, 'clock started');
        $this->assertTrue($contact->isOutreachPending());
        $this->assertSame(Contact::OUTREACH_PENDING, $contact->outreachConsentState());
        // Still master opted_in while pending (not an opt-out).
        $this->assertSame(Contact::COMM_OPTED_IN, $contact->communicationStatus());

        // Re-send is HARD blocked while pending — with a way-forward message.
        $second = $this->actingAs(User::find($userId))
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel' => 'whatsapp',
                'body'    => "Hi again {seller_name}. {tracking_link} Reply STOP.",
            ]);
        $second->assertStatus(422);
        $this->assertStringContainsString('awaiting their reply', (string) $second->json('message'));
        // No second send row created.
        $this->assertSame(1, SellerOutreachSend::withoutGlobalScopes()->where('contact_id', $contact->id)->count());
    }

    /** 3a — opt-in reply → CONFIRMED + pending cleared. */
    public function test_opt_in_confirms_and_clears_pending(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContactWithAddress($agencyId);
        $contact->markOutreachPending();
        $this->assertTrue($contact->isOutreachPending());

        app(MarketingConsentService::class)->optInContact($contact, 'Self-service opt-in link', $userId);

        $contact->refresh();
        $this->assertNull($contact->outreach_permission_asked_at, 'pending cleared');
        $this->assertNotNull($contact->messaging_opted_in_at);
        $this->assertSame(Contact::OUTREACH_CONFIRMED, $contact->outreachConsentState());
    }

    /** 3b — opt-out reply → DECLINED + pending cleared. */
    public function test_opt_out_declines_and_clears_pending(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContactWithAddress($agencyId);
        $contact->markOutreachPending();

        app(MarketingConsentService::class)->optOutContact(
            contact: $contact, reason: 'Self-service opt-out link', source: 'self_service_link', actorUserId: $userId, blockAll: false,
        );

        $contact->refresh();
        $this->assertNull($contact->outreach_permission_asked_at, 'pending cleared');
        $this->assertNotNull($contact->messaging_opt_out_at);
        $this->assertSame(Contact::OPT_OUT_KIND_DECLINED, $contact->messaging_opt_out_kind);
        $this->assertSame(Contact::OUTREACH_DECLINED, $contact->outreachConsentState());
    }

    /** 3c — a click clears pending (engagement). */
    public function test_click_clears_pending(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContactWithAddress($agencyId);
        $contact->markOutreachPending();
        $send = $this->seedSend($agencyId, $contact->id, $userId, sentAt: now(), outcome: SellerOutreachSend::OUTCOME_SENT);

        app(SellerOutreachLandingService::class)->recordClick($send, Request::create('/m/' . $send->tracking_short_code, 'GET'));

        $contact->refresh();
        $this->assertNull($contact->outreach_permission_asked_at, 'click cleared pending');
        $this->assertFalse($contact->isOutreachPending());
    }

    /** 6 + 10 — a silent contact past the window lapses to opted_out·no_response, NOT declined. */
    public function test_timeout_lapses_silent_contact_to_no_response(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        AgencyContactSettings::forAgency($agencyId)->update(['outreach_no_response_days' => 7]);
        $contact = $this->seedPendingSilentContact($agencyId, $userId, askedDaysAgo: 10);

        // Dry-run changes nothing.
        Artisan::call('outreach:recompute-no-response', ['--dry-run' => true, '--agency' => $agencyId]);
        $contact->refresh();
        $this->assertNull($contact->messaging_opt_out_at, 'dry-run made no change');
        $this->assertSame(Contact::OUTREACH_PENDING, $contact->outreachConsentState());

        // Live run lapses.
        Artisan::call('outreach:recompute-no-response', ['--agency' => $agencyId]);
        $contact->refresh();

        $this->assertNotNull($contact->messaging_opt_out_at, 'lapsed to opted-out');
        $this->assertSame(Contact::OPT_OUT_KIND_NO_RESPONSE, $contact->messaging_opt_out_kind);
        $this->assertNull($contact->outreach_permission_asked_at, 'pending cleared on lapse');
        $this->assertFalse((bool) $contact->messaging_all_blocked, 'marketing-only — transactional stays open');

        // Reads correctly as no_response, NOT as an explicit decline.
        $this->assertSame(Contact::OUTREACH_NO_RESPONSE, $contact->outreachConsentState());
        $this->assertSame(Contact::COMM_MARKETING_OPTED_OUT, $contact->communicationStatus());
        $meta = $contact->communicationStatusMeta();
        $this->assertSame(Contact::OUTREACH_NO_RESPONSE, $meta['key']);
        $this->assertStringContainsStringIgnoringCase('no response', $meta['label']);

        // The latest send outcome reflects the auto-lapse.
        $send = SellerOutreachSend::withoutGlobalScopes()->where('contact_id', $contact->id)->latest('sent_at')->first();
        $this->assertSame(SellerOutreachSend::OUTCOME_NO_RESPONSE, $send->outcome);
    }

    /** 7 — non-lapse guards: within window / clicked / opted-in / opted-out / live transaction. */
    public function test_timeout_skips_within_window_and_engaged_and_live_transaction(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        AgencyContactSettings::forAgency($agencyId)->update(['outreach_no_response_days' => 7]);

        // (a) within window — asked 3 days ago.
        $within = $this->seedPendingSilentContact($agencyId, $userId, askedDaysAgo: 3);
        // (b) clicked — past window but the send has first_clicked_at.
        $clicked = $this->seedPendingSilentContact($agencyId, $userId, askedDaysAgo: 10);
        SellerOutreachSend::withoutGlobalScopes()->where('contact_id', $clicked->id)
            ->update(['first_clicked_at' => now()->subDays(9), 'outcome' => SellerOutreachSend::OUTCOME_CLICKED]);
        // (c) already opted-in — past window but confirmed.
        $optedIn = $this->seedPendingSilentContact($agencyId, $userId, askedDaysAgo: 10);
        $optedIn->forceFill(['messaging_opted_in_at' => now()->subDay()])->save();
        // (d) already opted-out — past window but declined.
        $optedOut = $this->seedPendingSilentContact($agencyId, $userId, askedDaysAgo: 10);
        $optedOut->forceFill(['messaging_opt_out_at' => now()->subDay(), 'messaging_opt_out_kind' => Contact::OPT_OUT_KIND_DECLINED])->save();

        Artisan::call('outreach:recompute-no-response', ['--agency' => $agencyId]);

        $within->refresh(); $clicked->refresh(); $optedIn->refresh(); $optedOut->refresh();
        $this->assertNull($within->messaging_opt_out_at, 'within window not lapsed');
        $this->assertNull($clicked->messaging_opt_out_at, 'clicked not lapsed');
        $this->assertNull($optedIn->messaging_opt_out_at, 'confirmed not lapsed');
        $this->assertSame(Contact::OPT_OUT_KIND_DECLINED, $optedOut->messaging_opt_out_kind, 'declined not overwritten by no_response');

        // (e) live transaction — past window, silent, but in a live sale → not lapsed.
        $inDeal = $this->seedPendingSilentContact($agencyId, $userId, askedDaysAgo: 10);
        $this->app->instance(TransactionStateService::class, new class extends TransactionStateService {
            public function isInLiveTransaction(int $agencyId, Contact $contact): bool { return true; }
        });
        Artisan::call('outreach:recompute-no-response', ['--agency' => $agencyId]);
        $inDeal->refresh();
        $this->assertNull($inDeal->messaging_opt_out_at, 'live-transaction contact not lapsed');
    }

    /** 4 — mid-reply safety: an opt-in landing during the window stops the lapse. */
    public function test_reply_during_window_prevents_false_lapse(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        AgencyContactSettings::forAgency($agencyId)->update(['outreach_no_response_days' => 7]);
        $contact = $this->seedPendingSilentContact($agencyId, $userId, askedDaysAgo: 10);

        // The reply lands just before the sweep runs → pending cleared, confirmed.
        app(MarketingConsentService::class)->optInContact($contact, 'replied', $userId);

        Artisan::call('outreach:recompute-no-response', ['--agency' => $agencyId]);
        $contact->refresh();

        $this->assertSame(Contact::OUTREACH_CONFIRMED, $contact->outreachConsentState(), 'a mid-window reply is never lapsed');
        $this->assertNull($contact->messaging_opt_out_at);
    }

    /** 9 — an explicit decline UPGRADES a prior no_response lapse (decline always wins). */
    public function test_explicit_decline_upgrades_a_prior_no_response(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContactWithAddress($agencyId);
        // Already lapsed to no_response.
        app(MarketingConsentService::class)->optOutContact(
            contact: $contact, reason: 'No response', source: 'system:no_response', blockAll: false,
            kind: Contact::OPT_OUT_KIND_NO_RESPONSE,
        );
        $contact->refresh();
        $this->assertSame(Contact::OUTREACH_NO_RESPONSE, $contact->outreachConsentState());

        // Now they explicitly decline.
        app(MarketingConsentService::class)->optOutContact(
            contact: $contact, reason: 'Self-service opt-out link', source: 'self_service_link', blockAll: false,
            kind: Contact::OPT_OUT_KIND_DECLINED,
        );
        $contact->refresh();
        $this->assertSame(Contact::OPT_OUT_KIND_DECLINED, $contact->messaging_opt_out_kind, 'decline upgrades no_response');
        $this->assertSame(Contact::OUTREACH_DECLINED, $contact->outreachConsentState());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
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

    private function seedContactWithAddress(int $agencyId, string $firstName = 'Thandi'): Contact
    {
        return Contact::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'first_name'    => $firstName,
            'last_name'     => 'Mkhize',
            'phone'         => '+2782' . random_int(1000000, 9999999),
            'email'         => strtolower($firstName) . '-' . Str::random(6) . '@example.test',
            'street_number' => '14',
            'street_name'   => 'Marine Drive',
            'suburb'        => 'Margate',
        ]);
    }

    private function seedSend(int $agencyId, int $contactId, int $userId, $sentAt, string $outcome): SellerOutreachSend
    {
        return SellerOutreachSend::create([
            'agency_id'           => $agencyId,
            'contact_id'          => $contactId,
            'agent_id'            => $userId,
            'channel'             => 'whatsapp',
            'body_snapshot'       => 'Hi there. https://example.test/m/abc Reply STOP.',
            'facts_snapshot'      => ['merge_fields' => []],
            'tracking_short_code' => Str::random(6),
            'opt_out_token'       => Str::random(48),
            'recipient_phone_snapshot' => '27821234567',
            'address_snapshot'    => '14 Marine Drive, Margate',
            'suburb_snapshot'     => 'Margate',
            'sent_at'             => $sentAt,
            'outcome'             => $outcome,
        ]);
    }

    /** A contact that was asked N days ago, has a clean 'sent' send, and stayed silent. */
    private function seedPendingSilentContact(int $agencyId, int $userId, int $askedDaysAgo): Contact
    {
        $contact = $this->seedContactWithAddress($agencyId, 'Sipho' . random_int(100, 999));
        $this->seedSend($agencyId, $contact->id, $userId, sentAt: now()->subDays($askedDaysAgo), outcome: SellerOutreachSend::OUTCOME_SENT);
        $contact->forceFill(['outreach_permission_asked_at' => now()->subDays($askedDaysAgo)])->save();
        return $contact;
    }

    private function seedDefaultTemplate(int $agencyId): void
    {
        DB::table('seller_outreach_templates')->insert([
            'agency_id'              => $agencyId,
            'name'                   => 'Initial outreach — sale',
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
}
