<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Models\User;
use App\Services\SellerOutreach\SellerOutreachTemplateDefaultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-379 — every agency gets a starter WhatsApp template, not just HFC.
 *
 * HfcConsentTemplatesSeeder (§11.3) is deliberately scoped to HFC (agency_id
 * 1) — before this service existed, every OTHER agency had zero
 * seller_outreach_templates rows and nothing to send from the composer.
 */
final class SellerOutreachTemplateDefaultsTest extends TestCase
{
    use RefreshDatabase;

    /** The service's hardcoded clone source — mirrors the seeder's own convention. */
    private const HFC_ID = 1;

    public function test_agency_with_no_whatsapp_template_gets_hfcs_first_one_cloned(): void
    {
        $this->seedHfcWithOneWhatsappTemplate();
        $targetId = $this->makeAgency('New Agency');

        app(SellerOutreachTemplateDefaultsService::class)->ensureDefaults($targetId);

        $cloned = SellerOutreachTemplate::withoutGlobalScopes()
            ->where('agency_id', $targetId)
            ->where('channel', SellerOutreachTemplate::CHANNEL_WHATSAPP)
            ->first();

        $this->assertNotNull($cloned, 'a WhatsApp template must have been created for the target agency');
        $this->assertSame('General Marketing — Area Updates', $cloned->name);
        $this->assertStringContainsString('{opt_out_link}', $cloned->body);
        $this->assertTrue((bool) $cloned->is_active);
        $this->assertTrue((bool) $cloned->is_default_for_channel);

        $hfcBody = SellerOutreachTemplate::withoutGlobalScopes()
            ->where('agency_id', self::HFC_ID)->where('channel', 'whatsapp')->value('body');
        $this->assertSame($hfcBody, $cloned->body, 'the clone must be verbatim, not rewritten');
    }

    public function test_hfc_itself_is_never_touched(): void
    {
        $this->seedHfcWithOneWhatsappTemplate();
        $before = SellerOutreachTemplate::withoutGlobalScopes()->where('agency_id', self::HFC_ID)->count();

        app(SellerOutreachTemplateDefaultsService::class)->ensureDefaults(self::HFC_ID);

        $this->assertSame(
            $before,
            SellerOutreachTemplate::withoutGlobalScopes()->where('agency_id', self::HFC_ID)->count(),
            'HFC is the clone source, never a target'
        );
    }

    /** An agency that already has ANY WhatsApp template (even inactive) keeps its own — never overwritten. */
    public function test_agency_with_an_existing_whatsapp_template_is_left_alone(): void
    {
        $this->seedHfcWithOneWhatsappTemplate();
        $targetId = $this->makeAgency('Has Its Own');

        SellerOutreachTemplate::withoutGlobalScopes()->create([
            'agency_id' => $targetId,
            'name' => 'Custom Intro',
            'channel' => SellerOutreachTemplate::CHANNEL_WHATSAPP,
            'body' => 'Their own custom body.',
            'is_active' => false,
            'is_default_for_channel' => false,
            'include_tracking_link' => false,
        ]);

        app(SellerOutreachTemplateDefaultsService::class)->ensureDefaults($targetId);

        $templates = SellerOutreachTemplate::withoutGlobalScopes()->where('agency_id', $targetId)->get();
        $this->assertCount(1, $templates, 'the pre-existing template must not be supplemented or replaced');
        $this->assertSame('Custom Intro', $templates->first()->name);
    }

    /** Idempotent: calling it twice for the same target does not duplicate the clone. */
    public function test_calling_it_twice_does_not_duplicate(): void
    {
        $this->seedHfcWithOneWhatsappTemplate();
        $targetId = $this->makeAgency('New Agency');
        $service = app(SellerOutreachTemplateDefaultsService::class);

        $service->ensureDefaults($targetId);
        $service->ensureDefaults($targetId);

        $this->assertSame(
            1,
            SellerOutreachTemplate::withoutGlobalScopes()->where('agency_id', $targetId)->count()
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedHfcWithOneWhatsappTemplate(): void
    {
        DB::table('agencies')->insert([
            'id' => self::HFC_ID, 'name' => 'Home Finders Coastal', 'slug' => 'hfc',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => self::HFC_ID, 'agency_id' => self::HFC_ID, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        SellerOutreachTemplate::withoutGlobalScopes()->create([
            'agency_id' => self::HFC_ID,
            'name' => 'General Marketing — Area Updates',
            'channel' => SellerOutreachTemplate::CHANNEL_WHATSAPP,
            'body' => "Hi {seller_name}, this is {agent_name} from {agency_name}.\nManage your preferences or opt out anytime: {opt_out_link}.",
            'description' => 'Consent request — area updates.',
            'is_active' => true,
            'is_default_for_channel' => true,
            'include_tracking_link' => false,
        ]);
    }

    private function makeAgency(string $name): int
    {
        $id = (int) DB::table('agencies')->insertGetId([
            'name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $id, 'agency_id' => $id, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        User::factory()->create(['agency_id' => $id, 'branch_id' => $id, 'role' => 'admin']);

        return $id;
    }
}
