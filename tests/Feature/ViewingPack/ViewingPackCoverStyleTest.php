<?php

declare(strict_types=1);

namespace Tests\Feature\ViewingPack;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\ViewingPackCoverAuditEntry;
use App\Services\ViewingPack\ViewingPackBuyerPdfService;
use App\Services\ViewingPack\ViewingPackCoverService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Viewing Pack cover styles (.ai/specs/viewing-pack.md §14).
 *
 * Proves: the default stays today's cover; "Classic welcome" draws every input; each input
 * missing in turn degrades cleanly (no band line, no broken image); the three cover text
 * fields fall back to the agency's own tagline / website / phone and never to anything
 * HFC-specific; colours follow the rulings (navy #002060 unless the agency has its own navy,
 * accent #C00000, light blue from the agency's icon colour); the settings save is
 * validated, audited and does not wipe sibling settings; the preview renders the same
 * partial the PDF uses; the wizard row saves through the canonical saver; and the cover
 * is exactly one A4 page in the real PDF.
 */
final class ViewingPackCoverStyleTest extends TestCase
{
    use RefreshDatabase;

    private ViewingPackCoverService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('public');
        $this->svc = app(ViewingPackCoverService::class);
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        Mockery::close();
        parent::tearDown();
    }

    // ── fixtures ───────────────────────────────────────────────────────────────────────

    private function agency(array $attrs = []): Agency
    {
        return Agency::create(array_merge([
            'name' => 'Cove Realty ' . Str::random(4),
            'slug' => 'cove-' . Str::lower(Str::random(8)),
        ], $attrs));
    }

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
            'name' => 'Pat Agent', 'cell' => '0821112222', 'email' => 'pat@cove.example',
        ]);
    }

    /** A real PNG on the public disk, returned as its public-relative path. */
    private function storePng(string $path, int $w = 400, int $h = 120): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, 5, 5, $w - 5, $h - 5, imagecolorallocate($img, 10, 80, 160));
        ob_start();
        imagepng($img);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return $path;
    }

    private function html(Agency $agency, ?User $agent, array $overrides = []): string
    {
        return view('command-center.viewing-packs.buyer-pack.cover', $this->svc->previewViewData($agency, $agent, $overrides))->render();
    }

    private function classicAgency(array $attrs = []): Agency
    {
        return $this->agency(array_merge([
            'viewing_pack_cover_style'   => 'classic_welcome',
            'viewing_pack_cover_slogan'  => 'WHERE PROFESSIONALISM MEETS REAL ESTATE',
            'viewing_pack_cover_website' => 'www.cove.example',
            'viewing_pack_cover_phone'   => '039 000 1111',
        ], $attrs));
    }

    // ── default stays the current cover ────────────────────────────────────────────────

    public function test_default_style_is_standard_and_renders_the_current_cover(): void
    {
        $agency = $this->agency();
        $agent = $this->admin($agency);

        // (Agency::find() is memoised in-process and would return the unsaved model, so read the column itself.)
        $this->assertSame('standard', Agency::query()->whereKey($agency->id)->value('viewing_pack_cover_style'));
        $this->assertFalse($this->svc->dataFor($agency, $agent)['isClassic']);

        $html = $this->html($agency, $agent);
        $this->assertStringContainsString('Welcome to your', $html);
        $this->assertStringContainsString('Registered with the PPRA', $html);
        $this->assertStringNotContainsString('Viewing day', $html);   // classic wording only
    }

    public function test_unknown_style_value_falls_back_to_standard(): void
    {
        $agency = $this->agency();
        $this->assertSame('standard', ViewingPackCoverService::normaliseStyle('nonsense'));
        $this->assertSame('standard', ViewingPackCoverService::normaliseStyle(null));
        $this->assertFalse($this->svc->dataFor($agency, null, ['viewing_pack_cover_style' => 'nonsense'])['isClassic']);
    }

    // ── classic: everything present ────────────────────────────────────────────────────

    public function test_classic_cover_draws_every_input(): void
    {
        $agency = $this->classicAgency([
            'logo_path' => $this->storePng('agencies/x/logo.png', 600, 150),
            'icon_color' => '#00B4D8',
        ]);
        $agent = $this->admin($agency);

        $d = $this->svc->dataFor($agency, $agent);
        $this->assertTrue($d['isClassic']);
        $this->assertSame('#002060', $d['navy']);
        $this->assertSame('#C00000', $d['accent']);
        $this->assertSame('#00B4D8', $d['light']);
        $this->assertNotNull($d['logo']);
        $this->assertLessThanOrEqual(646, $d['logo']['w']);

        $html = $this->html($agency, $agent);
        $this->assertStringContainsString('Welcome to', $html);
        $this->assertStringContainsString('Your</div>', $html);
        $this->assertStringContainsString('Viewing day', $html);
        $this->assertStringContainsString('WHERE PROFESSIONALISM MEETS REAL ESTATE', $html);
        $this->assertStringContainsString('www.cove.example', $html);
        $this->assertStringContainsString('039 000 1111', $html);
        $this->assertStringContainsString('Pat Agent', $html);
        $this->assertStringContainsString('0821112222', $html);
        $this->assertStringContainsString('pat@cove.example', $html);
        $this->assertStringContainsString('rotate(-90deg)', $html);
        $this->assertStringContainsString('#002060', $html);
        $this->assertStringContainsString('#C00000', $html);
        $this->assertStringNotContainsString('Registered with the PPRA', $html);   // "nothing else on the cover"
        $this->assertStringNotContainsString('Sample Buyer', $html);
    }

    // ── fallbacks to existing agency data ──────────────────────────────────────────────

    public function test_blank_cover_fields_fall_back_to_the_agency_tagline_website_and_phone(): void
    {
        $agency = $this->agency([
            'viewing_pack_cover_style' => 'classic_welcome',
            'tagline' => 'Sea, sand and sold',
            'website_url' => 'https://www.fallback.example/',
            'phone' => '031 555 0000',
        ]);

        $d = $this->svc->dataFor($agency, null);
        $this->assertSame('Sea, sand and sold', $d['slogan']);
        $this->assertSame('www.fallback.example', $d['website']);   // scheme + trailing slash stripped for display
        $this->assertSame('031 555 0000', $d['phone']);

        // An explicit cover value wins over the fallback.
        $agency->forceFill(['viewing_pack_cover_slogan' => 'Cover line'])->save();
        $this->assertSame('Cover line', $this->svc->dataFor($agency->fresh(), null)['slogan']);
    }

    public function test_second_agency_with_nothing_set_shows_no_other_agencys_wording(): void
    {
        $agency = $this->agency(['viewing_pack_cover_style' => 'classic_welcome']);
        $agent = $this->admin($agency);

        $html = $this->html($agency, $agent);
        $this->assertStringNotContainsString('PROFESSIONALISM', $html);
        $this->assertStringNotContainsString('hfcoastal', strtolower($html));
        $this->assertStringNotContainsString('039 315', $html);
        $this->assertStringNotContainsString('Home Finders', $html);
        $this->assertStringContainsString('Welcome to', $html);   // still a complete cover
    }

    // ── each input missing in turn ─────────────────────────────────────────────────────

    public function test_each_missing_input_degrades_cleanly(): void
    {
        // No slogan, website or phone anywhere → no band text, no light-blue block, no <table>.
        $bare = $this->agency(['viewing_pack_cover_style' => 'classic_welcome']);
        $agent = $this->admin($bare);
        $html = $this->html($bare, $agent);
        $this->assertStringNotContainsString('rotate(-90deg)', $html);
        $this->assertStringNotContainsString('letter-spacing:1.5px', $html);
        $this->assertStringContainsString('background:#002060', $html);   // the band itself is always drawn

        // Only a phone → only the phone line; no slogan block behind it.
        $phoneOnly = $this->agency(['viewing_pack_cover_style' => 'classic_welcome', 'viewing_pack_cover_phone' => '039 111 2222']);
        $html = $this->html($phoneOnly, $agent);
        $this->assertStringContainsString('039 111 2222', $html);
        $this->assertStringNotContainsString('width:330px', $html);

        // No logo → the agency name as text, never an <img> with an empty src.
        $this->assertStringContainsString($bare->name, $html . $this->html($bare, $agent));
        $this->assertStringNotContainsString('<img src=""', $this->html($bare, $agent));

        // No agent photo → no <img> at all (no logo either) and the text block is intact.
        $this->assertStringNotContainsString('<img', $this->html($bare, $agent));

        // No agent cell / email → those lines are not drawn.
        $noContact = User::factory()->create(['agency_id' => $bare->id, 'name' => 'No Contact', 'cell' => null, 'phone' => null, 'email' => 'x@y.example']);
        $d = $this->svc->dataFor($bare, $noContact);
        $this->assertSame('', $d['agentCell']);
        $this->assertStringNotContainsString('0821112222', $this->html($bare, $noContact));
    }

    public function test_corrupt_or_missing_image_files_are_omitted_not_broken(): void
    {
        Storage::disk('public')->put('agencies/bad/logo.png', 'not an image at all');
        $agency = $this->classicAgency(['logo_path' => 'agencies/bad/logo.png']);
        $d = $this->svc->dataFor($agency, $this->admin($agency));
        $this->assertNull($d['logo']);
        $this->assertStringNotContainsString('<img', $this->html($agency, null));

        $gone = $this->classicAgency(['logo_path' => 'agencies/never/existed.png']);
        $this->assertNull($this->svc->dataFor($gone, null)['logo']);
    }

    // ── colours ────────────────────────────────────────────────────────────────────────

    public function test_colour_rules(): void
    {
        // The agency's OWN navy overrides the style navy; the platform default does not.
        $own = $this->agency(['viewing_pack_cover_style' => 'classic_welcome', 'default_color' => '#112233']);
        $this->assertSame('#112233', $this->svc->dataFor($own, null)['navy']);

        $platform = $this->agency(['viewing_pack_cover_style' => 'classic_welcome', 'default_color' => '#0b2a4a']);
        $this->assertSame('#002060', $this->svc->dataFor($platform, null)['navy']);

        $none = $this->agency(['viewing_pack_cover_style' => 'classic_welcome', 'default_color' => '']);
        $this->assertSame('#002060', $this->svc->dataFor($none, null)['navy']);

        // Accent: configurable, validated, default red; an invalid stored value is ignored.
        $this->assertSame('#C00000', $this->svc->dataFor($none, null)['accent']);
        $this->assertSame('#123ABC', $this->svc->dataFor($none, null, ['viewing_pack_cover_accent_color' => '#123abc'])['accent']);
        $this->assertSame('#C00000', $this->svc->dataFor($none, null, ['viewing_pack_cover_accent_color' => 'red'])['accent']);

        // Light blue = the agency's icon colour, then button colour, then a built-in blue.
        $this->assertSame('#0EA5E9', $this->svc->dataFor($this->agency(['icon_color' => '#0ea5e9']), null)['light']);
        $this->assertSame('#00B4D8', $this->svc->dataFor($this->agency(['icon_color' => '', 'button_color' => '']), null)['light']);
    }

    // ── portrait: cut-out first, plain photo second, nothing third ─────────────────────

    public function test_cutout_portrait_is_preferred_then_plain_photo_then_none(): void
    {
        $agency = $this->classicAgency();
        $this->storePng('agents/9/photo-cutout.png', 300, 380);
        $this->storePng('agents/9/photo.png', 200, 200);

        $withCutout = Mockery::mock(User::class)->makePartial();
        $withCutout->name = 'Cut Out';
        $withCutout->shouldReceive('profilePhotoCutoutUrl')->andReturn('http://x.test/storage/agents/9/photo-cutout.png');
        $withCutout->shouldReceive('profilePhotoUrl')->andReturn('http://x.test/storage/agents/9/photo.png');
        $d = $this->svc->dataFor($agency, $withCutout);
        $this->assertSame(376, $d['photo']['h']);    // 300x380 fitted into 380x376 → height-bound, aspect kept
        $this->assertSame(297, $d['photo']['w']);

        $plainOnly = Mockery::mock(User::class)->makePartial();
        $plainOnly->name = 'Plain';
        $plainOnly->shouldReceive('profilePhotoCutoutUrl')->andReturn(null);
        $plainOnly->shouldReceive('profilePhotoUrl')->andReturn('http://x.test/storage/agents/9/photo.png');
        $this->assertSame(376, $this->svc->dataFor($agency, $plainOnly)['photo']['h']);

        $neither = Mockery::mock(User::class)->makePartial();
        $neither->name = 'None';
        $neither->shouldReceive('profilePhotoCutoutUrl')->andReturn(null);
        $neither->shouldReceive('profilePhotoUrl')->andReturn(null);
        $this->assertNull($this->svc->dataFor($agency, $neither)['photo']);
    }

    // ── settings save: validated, audited, nothing else wiped ──────────────────────────

    public function test_branding_save_persists_validates_and_audits(): void
    {
        $agency = $this->agency(['tagline' => 'Keep me', 'phone' => '031 000 0000', 'trading_name' => 'Keep Trading']);
        $admin = $this->admin($agency);

        $this->actingAs($admin)->put(route('admin.company-settings.update', $agency), [
            'viewing_pack_cover_style'        => 'classic_welcome',
            'viewing_pack_cover_slogan'       => '  WHERE PROFESSIONALISM MEETS REAL ESTATE ',
            'viewing_pack_cover_website'      => 'www.cove.example',
            'viewing_pack_cover_phone'        => '039 315 0857',
            'viewing_pack_cover_accent_color' => '#c00000',
        ])->assertRedirect();

        $a = $agency->fresh();
        $this->assertSame('classic_welcome', $a->viewing_pack_cover_style);
        $this->assertSame('WHERE PROFESSIONALISM MEETS REAL ESTATE', $a->viewing_pack_cover_slogan);
        $this->assertSame('www.cove.example', $a->viewing_pack_cover_website);
        $this->assertSame('039 315 0857', $a->viewing_pack_cover_phone);
        $this->assertSame('#C00000', $a->viewing_pack_cover_accent_color);
        // The existing agency fields the cover falls back to are never touched by this feature.
        $this->assertSame('Keep me', $a->tagline);
        $this->assertSame('031 000 0000', $a->phone);
        $this->assertSame('Keep Trading', $a->trading_name);

        $audit = ViewingPackCoverAuditEntry::where('agency_id', $agency->id)->get();
        $this->assertCount(1, $audit);
        $this->assertSame($admin->id, $audit[0]->changed_by_user_id);
        $this->assertSame('standard', $audit[0]->old_values['viewing_pack_cover_style']);
        $this->assertSame('classic_welcome', $audit[0]->new_values['viewing_pack_cover_style']);

        // Saving the same values again writes no second audit row (idempotent).
        $this->actingAs($admin)->put(route('admin.company-settings.update', $agency), [
            'viewing_pack_cover_style' => 'classic_welcome',
            'viewing_pack_cover_slogan' => 'WHERE PROFESSIONALISM MEETS REAL ESTATE',
        ])->assertRedirect();
        $this->assertCount(1, ViewingPackCoverAuditEntry::where('agency_id', $agency->id)->get());

        // Blank text fields are stored as null (= use the fallback); a changed field audits old → new.
        $this->actingAs($admin)->put(route('admin.company-settings.update', $agency), [
            'viewing_pack_cover_website' => '',
        ])->assertRedirect();
        $this->assertNull($agency->fresh()->viewing_pack_cover_website);
        $last = ViewingPackCoverAuditEntry::where('agency_id', $agency->id)->latest('id')->first();
        $this->assertSame('www.cove.example', $last->old_values['viewing_pack_cover_website']);
        $this->assertNull($last->new_values['viewing_pack_cover_website']);
    }

    public function test_invalid_values_are_rejected_and_a_sibling_form_never_wipes_the_cover(): void
    {
        $agency = $this->classicAgency(['viewing_pack_cover_accent_color' => '#123456']);
        $admin = $this->admin($agency);

        $this->actingAs($admin)->put(route('admin.company-settings.update', $agency), ['viewing_pack_cover_style' => 'comic_sans'])
            ->assertSessionHasErrors('viewing_pack_cover_style');
        $this->actingAs($admin)->put(route('admin.company-settings.update', $agency), ['viewing_pack_cover_accent_color' => 'red'])
            ->assertSessionHasErrors('viewing_pack_cover_accent_color');
        $this->actingAs($admin)->put(route('admin.company-settings.update', $agency), ['viewing_pack_cover_slogan' => str_repeat('x', 121)])
            ->assertSessionHasErrors('viewing_pack_cover_slogan');
        $this->assertSame('classic_welcome', $agency->fresh()->viewing_pack_cover_style);
        $this->assertSame('#123456', $agency->fresh()->viewing_pack_cover_accent_color);

        // A blank style post never nulls the NOT NULL column.
        $this->actingAs($admin)->put(route('admin.company-settings.update', $agency), ['viewing_pack_cover_style' => ''])->assertRedirect();
        $this->assertSame('classic_welcome', $agency->fresh()->viewing_pack_cover_style);

        // The company form (which never posts the cover keys) leaves them all alone.
        $this->actingAs($admin)->put(route('admin.company-settings.update', $agency), ['trading_name' => 'Renamed Ltd'])->assertRedirect();
        $a = $agency->fresh();
        $this->assertSame('Renamed Ltd', $a->trading_name);
        $this->assertSame('classic_welcome', $a->viewing_pack_cover_style);
        $this->assertSame('www.cove.example', $a->viewing_pack_cover_website);
        $this->assertSame('#123456', $a->viewing_pack_cover_accent_color);
    }

    // ── preview: same partial, unsaved values, scoped ──────────────────────────────────

    public function test_preview_renders_the_same_cover_with_unsaved_values_and_writes_nothing(): void
    {
        $agency = $this->classicAgency();
        $admin = $this->admin($agency);

        $saved = $this->actingAs($admin)->get(route('admin.company-settings.cover-preview', $agency))->assertOk();
        $saved->assertSee('WHERE PROFESSIONALISM MEETS REAL ESTATE', false);
        $saved->assertSee('Pat Agent', false);

        $typed = $this->actingAs($admin)->put(route('admin.company-settings.cover-preview', $agency), [
            'viewing_pack_cover_slogan' => 'A NEW SLOGAN',
            'viewing_pack_cover_accent_color' => '#0A0B0C',
        ])->assertOk();
        $typed->assertSee('A NEW SLOGAN', false)->assertSee('#0A0B0C', false);
        $typed->assertDontSee('WHERE PROFESSIONALISM', false);

        $this->assertSame('WHERE PROFESSIONALISM MEETS REAL ESTATE', $agency->fresh()->viewing_pack_cover_slogan);
        $this->assertSame(0, ViewingPackCoverAuditEntry::count());
    }

    public function test_preview_is_blocked_across_agencies_and_without_permission(): void
    {
        $a = $this->classicAgency();
        $b = $this->classicAgency();
        $adminA = $this->admin($a);

        $this->actingAs($adminA)->get(route('admin.company-settings.cover-preview', $b))->assertForbidden();
        $this->actingAs($adminA)->put(route('admin.company-settings.update', $b), ['viewing_pack_cover_style' => 'standard'])->assertForbidden();
        $this->assertSame('classic_welcome', $b->fresh()->viewing_pack_cover_style);
    }

    public function test_branding_tab_shows_the_cover_section(): void
    {
        $agency = $this->classicAgency();
        $admin = $this->admin($agency);

        $this->actingAs($admin)->get(route('admin.company-settings', ['agency' => $agency->id]))
            ->assertOk()
            ->assertSee('Viewing pack cover')
            ->assertSee('Classic welcome')
            ->assertSee('Cover preview')
            ->assertSee('name="viewing_pack_cover_slogan"', false)
            ->assertSee('name="viewing_pack_cover_accent_color"', false);
    }

    // ── setup wizard row ───────────────────────────────────────────────────────────────

    public function test_wizard_branding_step_carries_the_cover_rows_and_saves_through_the_canonical_saver(): void
    {
        $agency = $this->agency(['trading_name' => 'Keep Trading Ltd', 'tagline' => 'Keep tagline']);
        $admin = $this->admin($agency);
        $s = new AgencyOnboardingSetup();
        $s->agency_id = $agency->id;
        $s->token = AgencyOnboardingSetup::generateToken();
        $s->slug = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
        $s->current_step = 1;
        $s->completed_steps = [];
        $s->expires_at = now()->addDays(30);
        $s->save();

        // Every row has the sentence-level explain + a concrete "What this changes".
        $controls = collect(config('agency-onboarding-copy.branding.controls'))->keyBy('key');
        foreach (ViewingPackCoverService::SETTING_KEYS as $key) {
            $this->assertTrue($controls->has($key), "wizard row missing for {$key}");
            $this->assertNotEmpty($controls[$key]['explain']);
            $this->assertNotEmpty($controls[$key]['affects']);
            $this->assertSame('agency', $controls[$key]['source']);
        }

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'branding']))
            ->assertOk()->assertSee('Buyer viewing pack', false)->assertSee('Cover accent colour', false);

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'branding']), [
            'viewing_pack_cover_style' => 'classic_welcome',
            'viewing_pack_cover_slogan' => 'Wizard slogan',
        ])->assertRedirect();

        $a = $agency->fresh();
        $this->assertSame('classic_welcome', $a->viewing_pack_cover_style);
        $this->assertSame('Wizard slogan', $a->viewing_pack_cover_slogan);
        $this->assertSame('Keep Trading Ltd', $a->trading_name);   // a subset post wipes nothing
        $this->assertSame('Keep tagline', $a->tagline);
    }

    // ── the real PDF: one A4 page, text present ────────────────────────────────────────

    public function test_classic_cover_is_exactly_one_a4_page_in_the_real_pdf(): void
    {
        $agency = $this->classicAgency([
            'logo_path' => $this->storePng('agencies/x/logo.png', 800, 200),
            'viewing_pack_cover_slogan' => 'WHERE PROFESSIONALISM MEETS REAL ESTATE AND THEN SOME MORE WORDS TO PROVE LENGTH',
        ]);
        $agent = $this->admin($agency);

        $viewData = $this->svc->previewViewData($agency, $agent);
        $bytes = (string) Pdf::setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'dpi' => 96])
            ->loadView('command-center.viewing-packs.buyer-pack.cover', $viewData)->setPaper('a4', 'portrait')->output();

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page[^s]/', $bytes), 'cover must be exactly one page');
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+595\.28\d*\s+841\.89\d*\s*\]/', $bytes);
    }

    public function test_pdf_service_cover_data_carries_the_cover_block(): void
    {
        $agency = $this->classicAgency();
        $agent = $this->admin($agency);
        $pack = new \App\Models\ViewingPack();
        $pack->setRelation('agency', $agency);
        $pack->setRelation('agent', $agent);
        $pack->setRelation('contact', null);
        $pack->setRelation('viewingPackProperties', collect());

        $svc = app(ViewingPackBuyerPdfService::class);
        $m = new \ReflectionMethod($svc, 'coverData');
        $m->setAccessible(true);
        $data = $m->invoke($svc, $pack);

        $this->assertTrue($data['cover']['isClassic']);
        $this->assertSame('www.cove.example', $data['cover']['website']);
        $this->assertSame('Pat Agent', $data['cover']['agentName']);
        $this->assertSame($agency->name, $data['agencyName']);   // standard-cover keys unchanged
    }
}
