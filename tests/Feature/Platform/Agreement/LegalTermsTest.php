<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\Agency;
use App\Models\PlatformEsign\WordingVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Public terms page /legal — spec §11.13. Parts B, C, D of the current published version, any earlier version at
 * /legal/v/{n}, never Part A / the cover / the mandate / any agency data; cacheable, indexable, print-friendly.
 */
class LegalTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function v1(): WordingVersion
    {
        return app(AgreementContent::class)->ensureSeeded();
    }

    /** Publish a throwaway later version straight into the table (no layout calibration needed for these tests). */
    private function v11(string $marker = 'LATER-VERSION-WORDS'): WordingVersion
    {
        $v1 = $this->v1();
        $content = $v1->content_json;
        $content['part_b'] = str_replace('This agreement is between', $marker . ' This agreement is between', $content['part_b']);

        return WordingVersion::create(['template_id' => $v1->template_id, 'version' => '1.1', 'version_date' => '2026-11-02', 'content_json' => $content,
            'rates_json' => $v1->rates_json, 'is_published' => true, 'published_at' => now()->addMinute(), 'change_note' => 'TEST VERSION — internal note']);
    }

    public function test_legal_is_public_and_shows_only_parts_b_c_and_d_of_the_current_version(): void
    {
        $res = $this->get('/legal')->assertOk();
        $res->assertSee('Part B — Terms of Service')->assertSee('B1. This agreement')->assertSee('Part C — POPIA Operator Terms')->assertSee('Part D — Support and Acceptable Use');
        $res->assertSee('Version 1.0 — 28 September 2026')->assertSee('Current version');
        // Never Part A, the cover, or the debit order mandate.
        $res->assertDontSee('How this agreement works')->assertDontSee('Part A — Sign-up Form', false)->assertDontSee('Registered name')->assertDontSee('name of Accountholder')
            ->assertDontSee('Contract reference number');
        // Letterhead from the platform company record.
        $res->assertSee('RR Technologies (Pty) Ltd');
        $this->assertStringContainsString('name="robots" content="index, follow"', $res->getContent());
        $this->assertStringContainsString('<link rel="canonical" href="' . route('public.agreement-terms') . '">', $res->getContent());
        $this->assertStringContainsString('@media print', $res->getContent());
        $this->assertStringContainsString('public', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', $res->headers->get('Cache-Control'));
        $this->assertNotEmpty($res->headers->get('ETag'));
    }

    public function test_an_unchanged_page_answers_304_to_a_conditional_request(): void
    {
        $etag = $this->get('/legal')->assertOk()->headers->get('ETag');
        $this->get('/legal', ['If-None-Match' => $etag])->assertStatus(304);
        $this->v11();
        $this->get('/legal', ['If-None-Match' => $etag])->assertOk()->assertSee('LATER-VERSION-WORDS'); // a new version changes the tag
    }

    public function test_the_current_version_changes_when_a_newer_one_is_published_and_the_earlier_one_stays_reachable(): void
    {
        $this->v1();
        $this->v11();

        $now = $this->get('/legal')->assertOk();
        $now->assertSee('LATER-VERSION-WORDS')->assertSee('Version 1.1 — 2 November 2026')->assertSee('Versions of these terms');
        $now->assertSee('Version 1.0 — 28 September 2026')->assertSee(route('public.agreement-terms.version', '1.0'));
        $now->assertDontSee('internal note')->assertDontSee('TEST VERSION'); // change notes are owner notes, not public text

        $old = $this->get('/legal/v/1.0')->assertOk();
        $old->assertDontSee('LATER-VERSION-WORDS')->assertSee('B1. This agreement')->assertSee('Earlier version')->assertSee('Version 1.0 — 28 September 2026');
        $this->assertStringContainsString('name="robots" content="noindex, follow"', $old->getContent());
        $old->assertSee('The current version is');

        $this->get('/legal/v/1.1')->assertRedirect(route('public.agreement-terms'))->assertStatus(301);
        $this->get('/legal/v/9.9')->assertNotFound();
        $this->get('/legal/v/abc')->assertNotFound();
    }

    public function test_an_unpublished_draft_is_never_served(): void
    {
        $v1 = $this->v1();
        WordingVersion::create(['template_id' => $v1->template_id, 'version' => 'draft-abc12345', 'version_date' => '2026-11-02', 'content_json' => $v1->content_json,
            'rates_json' => $v1->rates_json, 'is_published' => false]);
        $this->get('/legal/v/draft-abc12345')->assertNotFound();
        $this->get('/legal')->assertOk()->assertDontSee('draft-abc12345');
    }

    public function test_no_agency_data_ever_reaches_the_public_page(): void
    {
        $owner = (function () {
            $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
            $role->is_owner = true;
            $role->save();
            Role::clearCache();

            return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
        })();
        $agency = Agency::create(['name' => 'Zebra Coastal Realty', 'slug' => 'zebra-' . uniqid(), 'reg_no' => '2011/987654/07', 'address' => '77 Secret Street']);
        Mail::fake();
        app(AgreementService::class)->send(['name' => 'Zed Zebra', 'email' => 'zed@throwaway.invalid', 'agency_id' => $agency->id], $owner->id);

        $this->get('/legal')->assertOk()->assertDontSee('Zebra')->assertDontSee('2011/987654/07')->assertDontSee('Secret Street')->assertDontSee('zed@throwaway.invalid')->assertDontSee('CX0000');
    }
}
