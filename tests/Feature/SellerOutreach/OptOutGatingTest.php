<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-35 — unified, channel-aware outreach opt-out gating. The send-gate must
 * block on the generic per-channel opt_out_* flags, not only on
 * messaging_opt_out_at (the previous gap). Read-side gating only.
 */
final class OptOutGatingTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin']);
        $this->actingAs($this->user);
    }

    private function contact(array $overrides = []): Contact
    {
        return Contact::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'first_name' => 'Seller', 'last_name' => 'Test',
            'phone' => '+27821234567', 'email' => 'seller-' . Str::random(6) . '@example.test',
        ], $overrides));
    }

    private function property(): Property
    {
        $id = (int) DB::table('properties')->insertGetId([
            'external_id' => 'T-' . Str::random(8), 'title' => '1 Test Rd', 'address' => '1 Test Rd',
            'street_number' => '1', 'street_name' => 'Test Rd', 'suburb' => 'Uvongo',
            'price' => 1_200_000, 'property_type' => 'house', 'status' => 'active', 'is_demo' => false,
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Property::withoutGlobalScopes()->findOrFail($id);
    }

    private function compose(Contact $contact, string $channel)
    {
        return app(SellerOutreachComposerService::class)->composeContext(
            agencyId:        $this->agencyId,
            contact:         $contact,
            property:        $this->property(),
            channel:         $channel,
            templateId:      null,
            agent:           $this->user,
            bodyOverride:    'Hi, here is your link {tracking_link}. Reply STOP to opt out.',
            subjectOverride: 'Buyers for your property',
        );
    }

    /** THE GAP: opt_out_whatsapp blocks WhatsApp even when messaging_opt_out_at is null. */
    public function test_opt_out_whatsapp_blocks_whatsapp_send(): void
    {
        $contact = $this->contact(['opt_out_whatsapp' => true]);
        $this->assertNull($contact->messaging_opt_out_at);

        $ctx = $this->compose($contact, 'whatsapp');
        $this->assertTrue($ctx->optOutBlocks, 'opt_out_whatsapp must block a WhatsApp send');
        $this->assertFalse($ctx->isSendable());
        $this->assertSame('WhatsApp', $ctx->optOutReason);
    }

    /** Channel-aware: opt_out_whatsapp does NOT block an email send. */
    public function test_opt_out_whatsapp_does_not_block_email_send(): void
    {
        $contact = $this->contact(['opt_out_whatsapp' => true]);
        $ctx = $this->compose($contact, 'email');
        $this->assertFalse($ctx->optOutBlocks, 'opt_out_whatsapp must not block email');
    }

    /** THE GAP: opt_out_email blocks email even when messaging_opt_out_at is null. */
    public function test_opt_out_email_blocks_email_send(): void
    {
        $contact = $this->contact(['opt_out_email' => true]);
        $this->assertNull($contact->messaging_opt_out_at);

        $ctx = $this->compose($contact, 'email');
        $this->assertTrue($ctx->optOutBlocks, 'opt_out_email must block an email send');
        $this->assertSame('email', $ctx->optOutReason);
    }

    /** Channel-aware: opt_out_email does NOT block a WhatsApp send. */
    public function test_opt_out_email_does_not_block_whatsapp_send(): void
    {
        $contact = $this->contact(['opt_out_email' => true]);
        $ctx = $this->compose($contact, 'whatsapp');
        $this->assertFalse($ctx->optOutBlocks);
    }

    /** Regression: the global messaging opt-out (STOP) still blocks every channel. */
    public function test_messaging_opt_out_blocks_both_channels(): void
    {
        $contact = $this->contact(['messaging_opt_out_at' => now()]);
        $this->assertTrue($this->compose($contact, 'whatsapp')->optOutBlocks);
        $this->assertTrue($this->compose($contact, 'email')->optOutBlocks);
        $this->assertSame('messaging (replied STOP)', $this->compose($contact, 'whatsapp')->optOutReason);
    }

    /** A contact with no opt-out of any kind is not blocked. */
    public function test_clean_contact_is_not_blocked(): void
    {
        $contact = $this->contact();
        $this->assertFalse($this->compose($contact, 'whatsapp')->optOutBlocks);
        $this->assertFalse($this->compose($contact, 'email')->optOutBlocks);
    }
}
