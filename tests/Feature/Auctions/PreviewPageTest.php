<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use App\Services\Auctions\AuctionLotService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/** The Preview page (.ai/specs/auctions.md §16): see the public auction page as visitors will, and edit it. */
final class PreviewPageTest extends AuctionsV2TestCase
{
    private Auction $draft;
    private AuctionLot $draftLot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->draft = $this->auction(['reference' => 'AUC-T-DRAFT', 'title' => 'Winter Draft Auction', 'status' => 'draft']);
        $this->draftLot = app(AuctionLotService::class)->saveForProperty($this->property('Draft Cottage'), ['auction_id' => $this->draft->id, 'opening_bid' => 250000], $this->admin);
    }

    private function frame(string $path = '/', array $post = null)
    {
        $url = route('corex.auctions.preview.frame');

        return $post === null ? $this->get($url . '?path=' . urlencode($path)) : $this->post($url, ['path' => $path] + $post);
    }

    private function publicUrl(string $suffix = ''): string
    {
        return '/auctions/' . $this->agency->slug . $suffix;
    }

    private function asGuest(): void
    {
        Auth::guard('web')->forgetUser();
        $this->flushSession();
    }

    // ── the page ───────────────────────────────────────────────────────────

    public function test_the_editor_page_lists_the_pages_and_auctions_and_frames_the_preview(): void
    {
        $this->get(route('corex.auctions.preview'))->assertOk()
            ->assertSee('Preview page')->assertSee('Winter Draft Auction')->assertSee('Lot 1')->assertSee('Home page')
            ->assertSee('Update preview')->assertSee('Save changes')->assertSee('data-preview-frame', false)->assertSee('Open the live page');
    }

    public function test_the_editor_can_start_on_a_chosen_page(): void
    {
        $this->get(route('corex.auctions.preview', ['page' => "lot:{$this->draftLot->id}"]))->assertOk()
            ->assertSee(urlencode("/a/{$this->draft->id}/lots/{$this->draftLot->id}"), false);
    }

    // ── drafts: visible in the preview, never on the public site ──────────

    public function test_a_draft_auction_and_lot_show_in_the_preview_but_are_404_on_the_public_site(): void
    {
        $this->frame('/')->assertOk()->assertSee('Winter Draft Auction')->assertSee('Draft — not public yet');
        $this->frame("/a/{$this->draft->id}")->assertOk()->assertSee('Winter Draft Auction')->assertSee('Draft Cottage')->assertSee('only you can see it');
        $this->frame("/a/{$this->draft->id}/lots/{$this->draftLot->id}")->assertOk()->assertSee('Draft Cottage')->assertSee('R 250 000');

        $this->asGuest();
        $this->get($this->publicUrl())->assertOk()->assertDontSee('Winter Draft Auction');
        $this->get($this->publicUrl("/a/{$this->draft->id}"))->assertNotFound();
        $this->get($this->publicUrl("/a/{$this->draft->id}/lots/{$this->draftLot->id}"))->assertNotFound();
    }

    public function test_preview_mode_never_leaks_into_the_next_public_request(): void
    {
        $this->frame('/')->assertSee('Winter Draft Auction');

        $this->asGuest();
        $html = $this->get($this->publicUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('Winter Draft Auction', $html);
        $this->assertStringNotContainsString('data-preview-bar', $html, 'the staff-only banner is never on the public site');
    }

    public function test_the_preview_says_so_and_keeps_visitors_forms_inert(): void
    {
        $html = $this->frame("/a/{$this->draft->id}/lots/{$this->draftLot->id}")->assertOk()->getContent();

        $this->assertStringContainsString('data-preview-bar', $html);
        $this->assertStringContainsString('Forms and bidding are switched off', $html);
        $this->assertStringContainsString('preview\\/frame', $html, 'links move around the preview (the JSON-escaped frame URL is in the page script), not the live site');
    }

    public function test_a_page_that_is_not_part_of_the_preview_is_explained_not_broken(): void
    {
        $this->frame('/login')->assertOk()->assertSee('not part of the preview');
        $this->frame('/a/abc/../../etc')->assertOk();   // garbage falls back to a safe page
    }

    // ── unsaved edits ──────────────────────────────────────────────────────

    public function test_update_preview_shows_unsaved_edits_and_stores_nothing(): void
    {
        $this->auction(['reference' => 'AUC-T-PAST', 'title' => 'Last Year Auction', 'status' => 'completed']);

        $html = $this->frame('/', [
            'site_title' => 'Cape Property Auctions', 'intro_text' => 'Find your next home under the hammer.',
            'accent_color' => '#aa0000', 'how_it_works' => "1. Sign up\n2. Register\n3. Bid", 'footer_text' => 'Open weekdays 8 to 5',
            'show_past_auctions' => '0',
            'auctions' => [$this->draft->id => ['title' => 'Winter Auction RENAMED', 'description' => 'A lovely description']],
        ])->assertOk()->getContent();

        $this->assertStringContainsString('Cape Property Auctions', $html);
        $this->assertStringContainsString('Find your next home', $html);
        $this->assertStringContainsString('--brand: #aa0000', $html);
        $this->assertStringContainsString('How bidding works', $html);
        $this->assertStringContainsString('Open weekdays 8 to 5', $html);
        $this->assertStringContainsString('Winter Auction RENAMED', $html);
        $this->assertStringNotContainsString('Last Year Auction', $html, '"show past auctions" is off in the preview');

        // nothing was saved
        $this->assertNull(AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->first());
        $this->assertSame('Winter Draft Auction', $this->draft->fresh()->title);
        $this->assertNull($this->draft->fresh()->description);
    }

    public function test_an_unsaved_description_shows_on_the_auction_page_preview(): void
    {
        $html = $this->frame("/a/{$this->draft->id}", ['auctions' => [$this->draft->id => ['title' => 'Winter Draft Auction', 'description' => 'Brand new words about the sale']]])->assertOk()->getContent();

        $this->assertStringContainsString('Brand new words about the sale', $html);
        $this->assertNull($this->draft->fresh()->description);
    }

    public function test_an_invalid_unsaved_colour_is_ignored_in_the_preview_not_an_error(): void
    {
        $html = $this->frame('/', ['accent_color' => 'red; background:url(x)'])->assertOk()->getContent();

        $this->assertStringNotContainsString('background:url(x)', $html);
    }

    public function test_an_unsaved_banner_picture_previews_from_one_temporary_file_that_is_replaced_each_time(): void
    {
        $this->frame('/', ['hero_image' => UploadedFile::fake()->image('banner.jpg', 1200, 400)])->assertOk()->assertSee('data-hero', false)->assertSee('auction-pages/' . $this->agency->id . '/preview/u' . $this->admin->id . '-hero.jpg', false);
        $this->frame('/', ['hero_image' => UploadedFile::fake()->image('banner2.png', 1200, 400)])->assertOk();

        $temps = Storage::disk('public')->files('auction-pages/' . $this->agency->id . '/preview');
        $this->assertCount(1, $temps, 'one temporary picture per person per slot');
        $this->assertNull(AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->value('hero_image_path'), 'and nothing saved');
    }

    // ── saving ─────────────────────────────────────────────────────────────

    private function save(array $extra = [])
    {
        return $this->post(route('corex.auctions.preview.save'), $extra + ['site_title' => 'Cape Auctions', 'show_past_auctions' => '1']);
    }

    public function test_save_stores_the_page_wording_and_the_auction_content_and_the_public_page_shows_it(): void
    {
        $published = $this->auction(['reference' => 'AUC-T-PUB', 'title' => 'Spring Auction', 'status' => 'published', 'published_at' => now()]);

        $this->save([
            'site_title' => 'Cape Property Auctions', 'intro_text' => 'Welcome to our auctions', 'contact_email' => 'auctions@cape.test', 'contact_phone' => '021 555 0100',
            'accent_color' => '#118833', 'how_it_works' => 'Sign up, register, bid', 'footer_text' => 'Trading since 1999',
            'auctions' => [$published->id => ['title' => 'Spring Auction 2026', 'description' => 'Twelve coastal properties']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $s = AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame('Cape Property Auctions', $s->site_title);
        $this->assertSame('#118833', $s->accent_color);
        $this->assertSame('Trading since 1999', $s->footer_text);
        $this->assertTrue($s->show_past_auctions);
        $this->assertSame('Spring Auction 2026', $published->fresh()->title);
        $this->assertSame('Twelve coastal properties', $published->fresh()->description);

        $this->asGuest();
        $this->get($this->publicUrl())->assertOk()->assertSee('Cape Property Auctions')->assertSee('Welcome to our auctions')->assertSee('--brand: #118833', false)
            ->assertSee('Sign up, register, bid')->assertSee('Trading since 1999')->assertSee('Spring Auction 2026');
        $this->get($this->publicUrl("/a/{$published->id}"))->assertSee('Twelve coastal properties');
    }

    public function test_the_banner_is_uploaded_replaced_and_removed_on_save(): void
    {
        $this->save(['hero_image' => UploadedFile::fake()->image('one.jpg', 1200, 400)])->assertSessionHasNoErrors();
        $first = AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->value('hero_image_path');
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);
        $this->assertStringContainsString("auction-pages/{$this->agency->id}/", $first);

        $this->save(['hero_image' => UploadedFile::fake()->image('two.png', 1200, 400)]);
        $second = AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->value('hero_image_path');
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->save(['remove_hero' => '1']);
        $this->assertNull(AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->value('hero_image_path'));
        Storage::disk('public')->assertMissing($second);
    }

    public function test_a_saved_banner_shows_on_the_public_home_page(): void
    {
        $this->save(['hero_image' => UploadedFile::fake()->image('banner.jpg', 1200, 400)]);
        $this->asGuest();

        $this->get($this->publicUrl())->assertOk()->assertSee('data-hero', false);
    }

    public function test_an_auction_cover_is_uploaded_shown_and_removed(): void
    {
        $a = $this->auction(['reference' => 'AUC-T-COV', 'status' => 'published', 'published_at' => now()]);

        $this->save(['auctions' => [$a->id => ['title' => $a->title]], 'cover_image' => [$a->id => UploadedFile::fake()->image('cover.jpg', 800, 400)]])->assertSessionHasNoErrors();
        $path = $a->fresh()->cover_image_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->asGuest();
        $this->get($this->publicUrl("/a/{$a->id}"))->assertSee($path, false);

        $this->actingAs($this->admin);
        $this->save(['auctions' => [$a->id => ['title' => $a->title, 'remove_cover' => '1']]]);
        $this->assertNull($a->fresh()->cover_image_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_saving_clears_the_temporary_preview_pictures(): void
    {
        $this->frame('/', ['hero_image' => UploadedFile::fake()->image('b.jpg', 600, 200)]);
        $this->assertCount(1, Storage::disk('public')->files("auction-pages/{$this->agency->id}/preview"));

        $this->save(['hero_image' => UploadedFile::fake()->image('b.jpg', 600, 200)]);

        $this->assertCount(0, Storage::disk('public')->files("auction-pages/{$this->agency->id}/preview"));
    }

    public function test_bad_input_is_refused_and_nothing_is_saved(): void
    {
        $this->save(['accent_color' => 'blue'])->assertSessionHasErrors('accent_color');
        $this->save(['contact_email' => 'not-an-email'])->assertSessionHasErrors('contact_email');
        $this->save(['hero_image' => UploadedFile::fake()->create('banner.pdf', 100, 'application/pdf')])->assertSessionHasErrors('hero_image');
        $this->save(['hero_image' => UploadedFile::fake()->image('huge.jpg')->size(6000)])->assertSessionHasErrors('hero_image');

        $this->assertNull(AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->first());
    }

    public function test_page_text_is_shown_as_text_never_as_html(): void
    {
        $this->save(['how_it_works' => '<script>alert(1)</script>Bid safely', 'footer_text' => '<b>bold</b>']);
        $this->asGuest();

        $html = $this->get($this->publicUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Bid safely', $html);
    }

    public function test_a_finished_auction_cannot_be_edited_from_the_preview(): void
    {
        $done = $this->auction(['reference' => 'AUC-T-DONE', 'title' => 'Done Auction', 'status' => 'completed']);

        $this->save(['auctions' => [$done->id => ['title' => 'Hacked title']]]);

        $this->assertSame('Done Auction', $done->fresh()->title);
    }

    // ── who may do what ────────────────────────────────────────────────────

    public function test_an_agent_can_preview_but_not_edit_and_only_sees_their_own_auctions(): void
    {
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        \App\Services\PermissionService::clearCache();
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $mine = $this->auction(['reference' => 'AUC-T-AGENT', 'title' => 'Agents Own Auction', 'status' => 'draft', 'created_by' => $agent->id]);
        $this->actingAs($agent);

        $this->get(route('corex.auctions.preview'))->assertOk()->assertSee('Agents Own Auction')->assertDontSee('Winter Draft Auction')->assertDontSee('Save changes');
        $this->frame("/a/{$mine->id}")->assertOk()->assertSee('Agents Own Auction');
        $this->frame("/a/{$this->draft->id}")->assertNotFound();
        $this->save(['site_title' => 'Agent was here'])->assertForbidden();
        $this->assertNull(AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->first());
    }

    public function test_another_agencys_auction_cannot_be_previewed_or_edited_by_id(): void
    {
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv-'.uniqid()]);
        $foreign = Auction::withoutAgencyStamping(fn () => Auction::create(['agency_id' => $rival->id, 'reference' => 'AUC-R', 'title' => 'Rival Secret', 'format' => 'timed', 'status' => 'draft']));

        $this->get(route('corex.auctions.preview'))->assertDontSee('Rival Secret');
        $this->frame("/a/{$foreign->id}")->assertNotFound();
        $this->save(['auctions' => [$foreign->id => ['title' => 'Hijacked']]]);
        $this->assertSame('Rival Secret', $foreign->fresh()->title);
    }

    public function test_the_preview_is_hidden_when_the_agency_has_auctions_switched_off(): void
    {
        \App\Models\AgencyFeature::where('agency_id', $this->agency->id)->where('feature_key', 'auctions')->update(['enabled' => false]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);

        $this->assertContains($this->get(route('corex.auctions.preview'))->getStatusCode(), [403, 404, 302]);
    }

    // ── settings screen and wizard stay in step ───────────────────────────

    public function test_saving_the_settings_screen_keeps_the_banner_colour_and_page_text(): void
    {
        $this->save(['accent_color' => '#118833', 'how_it_works' => 'Sign up', 'footer_text' => 'Footer', 'hero_image' => UploadedFile::fake()->image('b.jpg', 600, 200)]);
        $hero = AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->value('hero_image_path');

        $this->post(route('corex.settings.auctions.update'), [
            'site_title' => 'Cape Auctions', 'soft_close_minutes' => 5, 'reserve_visibility' => 'met_not_met',
            'how_it_works' => 'Sign up', 'footer_text' => 'Footer', 'show_past_auctions' => '0',
        ])->assertSessionHasNoErrors();

        $s = AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame($hero, $s->hero_image_path);
        $this->assertSame('#118833', $s->accent_color);
        $this->assertFalse($s->show_past_auctions);
    }

    public function test_the_wizard_saver_writes_only_what_it_posts_for_the_page_fields(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'show_past_auctions' => false, 'footer_text' => 'Keep me', 'accent_color' => '#118833']);

        $request = \Illuminate\Http\Request::create('/x', 'POST', ['how_it_works' => 'Step by step']);
        $request->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\CoreX\Auctions\AuctionSettingsController::class)->updateWizard($request);

        $s = AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame('Step by step', $s->how_it_works);
        $this->assertSame('Keep me', $s->footer_text);
        $this->assertFalse($s->show_past_auctions);
        $this->assertSame('#118833', $s->accent_color);
    }
}
