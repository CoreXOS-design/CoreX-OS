<?php

namespace Tests\Unit\Outreach;

use App\Models\Contact;
use App\Services\ContactDuplicateService;
use App\Services\SellerOutreach\MarketingConsentService;
use Tests\TestCase;

/**
 * AT-117 §4b — the consolidated canMarketTo() predicate. DB-free: contacts are
 * unsaved models with agency_id = 0 so communicationStatus() skips its live-
 * transaction lookup, and isContactSuppressed() is overridden so no
 * marketing_suppressions query runs. We assert the consolidation reproduces the
 * composer's gate (suppressed / opted-out / pending block) plus the per-channel
 * canSendVia() layer — without inventing new rules.
 */
class CanMarketToTest extends TestCase
{
    /** Build the service with a controllable isContactSuppressed(). */
    private function service(bool $suppressed = false): MarketingConsentService
    {
        $svc = new class(app(ContactDuplicateService::class)) extends MarketingConsentService {
            public bool $suppressedFlag = false;
            public function isContactSuppressed(Contact $contact): bool
            {
                return $this->suppressedFlag;
            }
        };
        $svc->suppressedFlag = $suppressed;
        return $svc;
    }

    private function contact(array $attrs): Contact
    {
        $c = new Contact();
        $c->agency_id = 0; // skip the live-transaction DB lookup in communicationStatus()
        $c->forceFill($attrs);
        return $c;
    }

    public function test_opted_in_contact_is_marketable(): void
    {
        $svc = $this->service();
        $c = $this->contact(['messaging_opt_out_at' => null, 'opt_out_whatsapp' => false]);
        $this->assertTrue($svc->canMarketTo($c, 'whatsapp'));
        $this->assertNull($svc->marketingBlockReason($c, 'whatsapp'));
    }

    public function test_suppressed_contact_blocked(): void
    {
        $svc = $this->service(suppressed: true);
        $c = $this->contact(['messaging_opt_out_at' => null]);
        $this->assertFalse($svc->canMarketTo($c, 'whatsapp'));
        $this->assertSame('suppressed', $svc->marketingBlockReason($c, 'whatsapp'));
    }

    public function test_marketing_opted_out_blocked(): void
    {
        $svc = $this->service();
        $c = $this->contact([
            'messaging_opt_out_at' => now(),
            'messaging_all_blocked' => false,
            'messaging_opt_out_kind' => Contact::OPT_OUT_KIND_DECLINED,
        ]);
        $this->assertFalse($svc->canMarketTo($c, 'whatsapp'));
        $this->assertSame(Contact::COMM_MARKETING_OPTED_OUT, $svc->marketingBlockReason($c, 'whatsapp'));
    }

    public function test_all_blocked_blocked(): void
    {
        $svc = $this->service();
        $c = $this->contact(['messaging_opt_out_at' => now(), 'messaging_all_blocked' => true]);
        $this->assertFalse($svc->canMarketTo($c, 'whatsapp'));
        $this->assertSame(Contact::COMM_ALL_BLOCKED, $svc->marketingBlockReason($c, 'whatsapp'));
    }

    public function test_pending_outreach_blocked(): void
    {
        $svc = $this->service();
        // opt_out_at null + opted_in_at null + permission_asked_at set = pending.
        $c = $this->contact([
            'messaging_opt_out_at' => null,
            'messaging_opted_in_at' => null,
            'outreach_permission_asked_at' => now(),
        ]);
        $this->assertTrue($c->isOutreachPending());
        $this->assertFalse($svc->canMarketTo($c, 'whatsapp'));
        $this->assertSame('pending', $svc->marketingBlockReason($c, 'whatsapp'));
    }

    public function test_per_channel_opt_out_respected(): void
    {
        $svc = $this->service();
        // Opted-in overall, but the whatsapp channel is individually opted out.
        $c = $this->contact([
            'messaging_opt_out_at' => null,
            'opt_out_whatsapp' => true,
            'opt_out_email' => false,
        ]);
        $this->assertFalse($svc->canMarketTo($c, 'whatsapp'));
        $this->assertSame('channel_opted_out', $svc->marketingBlockReason($c, 'whatsapp'));
        $this->assertTrue($svc->canMarketTo($c, 'email')); // other channel still open
    }
}
