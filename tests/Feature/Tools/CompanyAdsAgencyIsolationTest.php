<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\AgentSocialAccount;
use App\Models\AiTemplateGeneration;
use App\Models\CompanyAd;
use App\Models\CompanyAdImage;
use App\Models\PropertyMarketingPost;
use App\Models\Role;
use App\Models\User;
use App\Services\AdTemplates\AiTemplateBuilderService;
use App\Services\MetaOAuthService;
use App\Services\MetaPublishingService;
use App\Services\PermissionService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Isolation audit (2026-10-18, Johan: "making sure no agency can see other agencies' templates or stuff or anything")
 * of everything Company Ads added (E-1 list / builder / pictures, E-2 AI, E-3 posting, the Agency Phone block).
 *
 * Every test is agency B (and a CoreX owner account working in agency A) attacking agency A's records through the real
 * routes: nothing of A may be listed, opened, changed, copied, archived, restored, posted, saved from, counted or shown.
 * Meta is faked at the Guzzle layer with an EMPTY queue, so any call that reached Meta would throw.
 */
final class CompanyAdsAgencyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private int $a;
    private int $b;
    private User $managerA;
    private User $managerB;
    private CompanyAd $adA;
    private CompanyAd $archivedA;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 20, 8, 0, 0, 'UTC'));
        Storage::fake('public');
        Storage::fake('local');
        $this->app->instance(MetaPublishingService::class, new MetaPublishingService(new Client(['handler' => HandlerStack::create(new MockHandler([]))])));
        $this->app->instance(MetaOAuthService::class, new MetaOAuthService(new Client(['handler' => HandlerStack::create(new MockHandler([]))])));

        [$this->a, $this->managerA] = $this->agencyWithManager(['name' => 'Alpha Homes', 'phone' => '021 111 1111', 'default_color' => '#a1a1a1']);
        [$this->b, $this->managerB] = $this->agencyWithManager(['name' => 'Bravo Rentals', 'phone' => '031 222 2222', 'default_color' => '#b2b2b2']);
        $this->adA       = $this->ad($this->a, $this->managerA, 'ALPHA-XMAS-AD');
        $this->archivedA = $this->ad($this->a, $this->managerA, 'ALPHA-OLD-AD', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PermissionService::clearCache();
        Role::clearCache();
        parent::tearDown();
    }

    // ── E-1: list, builder, changes ────────────────────────────────────────

    public function test_another_agency_never_sees_the_list_entries_or_their_brand(): void
    {
        $this->ad($this->b, $this->managerB, 'BRAVO-EASTER-AD');

        $html = $this->actingAs($this->managerB)->get(route('tools.ad-manager.templates.company', ['status' => 'all']))->assertOk()->getContent();

        $this->assertStringContainsString('BRAVO-EASTER-AD', $html);
        $this->assertStringNotContainsString('ALPHA-XMAS-AD', $html);
        $this->assertStringNotContainsString('ALPHA-OLD-AD', $html);
        // Previews draw B's own brand (name, phone) - never A's.
        $this->assertStringContainsString('031 222 2222', $html);
        $this->assertStringNotContainsString('Alpha Homes', $html);
        $this->assertStringNotContainsString('021 111 1111', $html);
        // Search cannot reach A's either.
        $this->get(route('tools.ad-manager.templates.company', ['q' => 'ALPHA', 'status' => 'all']))->assertOk()->assertDontSee('ALPHA-XMAS-AD');
    }

    public function test_another_agency_cannot_open_change_copy_archive_or_restore_by_id(): void
    {
        $this->actingAs($this->managerB);

        $this->get(route('tools.ad-manager.company-ads.builder.edit', $this->adA->id))->assertNotFound();
        $this->putJson(route('api.v1.ad-manager.company-ads.update', $this->adA->id), ['name' => 'Hacked', 'layout_json' => ['elements' => []]])->assertNotFound();
        $this->post(route('tools.ad-manager.company-ads.duplicate', $this->adA->id))->assertNotFound();
        $this->post(route('tools.ad-manager.company-ads.archive', $this->adA->id))->assertNotFound();
        $this->post(route('tools.ad-manager.company-ads.restore', $this->archivedA->id))->assertNotFound();

        $fresh = CompanyAd::withoutGlobalScopes()->find($this->adA->id);
        $this->assertSame('ALPHA-XMAS-AD', $fresh->name);
        $this->assertNull($fresh->deleted_at);
        $this->assertNotNull(CompanyAd::withoutGlobalScopes()->find($this->archivedA->id)->deleted_at);
        $this->assertSame(0, CompanyAd::withoutGlobalScopes()->where('agency_id', $this->b)->count(), 'no copy landed anywhere');
    }

    public function test_a_saved_ad_can_never_show_another_agencys_uploaded_picture_and_uploads_go_to_ones_own_folder(): void
    {
        $picA = $this->upload($this->a, $this->managerA);
        $this->actingAs($this->managerB);

        $this->postJson(route('api.v1.ad-manager.company-ads.store'), ['name' => 'Bravo ad', 'layout_json' => [
            'canvasPreset' => 'facebook',
            'elements' => [['field' => 'custom_image', 'src' => $picA->url(), 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100]],
        ]])->assertOk();
        $saved = CompanyAd::withoutGlobalScopes()->where('name', 'Bravo ad')->firstOrFail();
        $this->assertSame($this->b, (int) $saved->agency_id);
        $this->assertSame('', $saved->layout_json['elements'][0]['src'], 'agency A\'s picture is blanked');

        $res = $this->post(route('api.v1.ad-manager.company-ads.images'), ['file' => UploadedFile::fake()->image('p.jpg', 400, 300)], ['Accept' => 'application/json'])->assertOk()->json();
        $img = CompanyAdImage::withoutGlobalScopes()->findOrFail($res['id']);
        $this->assertSame($this->b, (int) $img->agency_id);
        $this->assertStringStartsWith('company-ads/' . $this->b . '/', $img->path);
    }

    // ── E-2: the AI pop-up ─────────────────────────────────────────────────

    public function test_another_agency_cannot_use_a_picture_save_a_design_or_eat_the_allowance(): void
    {
        $picA = $this->upload($this->a, $this->managerA);
        $genA = AiTemplateGeneration::withoutGlobalScopes()->create([
            'agency_id' => $this->a, 'user_id' => $this->managerA->id, 'kind' => 'company_ad', 'idea' => 'x', 'ad_size' => 'facebook',
            'status' => 'success', 'options_json' => [['name' => 'A', 'layout' => ['canvasPreset' => 'facebook', 'elements' => []], 'caption' => '']],
        ]);
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        Http::fake();
        $this->actingAs($this->managerB);

        // A's uploaded picture.
        $this->postJson(route('api.v1.ad-manager.company-ads.ai.generate'), ['idea' => 'Hello', 'ad_size' => 'facebook', 'picture' => 'place', 'company_ad_image_id' => $picA->id])
            ->assertStatus(422)->assertJsonFragment(['message' => 'That picture is no longer available. Please upload it again.']);
        Http::assertNothingSent();

        // A's generation (even with B's own user id forged onto nothing): not found.
        $this->postJson(route('api.v1.ad-manager.company-ads.ai.save', $genA->id), ['option' => 0])->assertNotFound();
        $this->assertSame(0, CompanyAd::withoutGlobalScopes()->where('agency_id', $this->b)->count());

        // A's monthly use never counts against B.
        $this->assertSame(1, app(AiTemplateBuilderService::class)->usage($this->a)['used']);
        $this->assertSame(0, app(AiTemplateBuilderService::class)->usage($this->b)['used']);
    }

    // ── E-3: posting and Scheduled Posts ───────────────────────────────────

    public function test_another_agency_cannot_post_schedule_see_or_touch_company_ad_posts(): void
    {
        $this->connect($this->managerB);
        $postA = $this->post_($this->a, $this->managerA, $this->adA);
        $adminB = $this->userIn($this->b, 'admin');
        $this->actingAs($this->managerB);
        $own = $this->storedImage($this->managerB);

        // Posting A's ad (now or later) is refused; nothing reaches Meta (empty queue would throw).
        foreach (['now', 'at'] as $mode) {
            $this->postJson(route('api.v1.ad-manager.scheduled.store'), array_filter([
                'items' => [['company_ad_id' => $this->adA->id, 'copy' => 'x', 'image_path' => $own]], 'platforms' => ['facebook'], 'mode' => $mode,
                'date' => $mode === 'at' ? '2026-10-22' : null, 'time' => $mode === 'at' ? '09:00' : null,
            ]))->assertStatus(422)->assertJsonFragment(['error' => 'That company ad is no longer available (it may have been archived).']);
        }
        // A picture from A's folder is refused too.
        $theirPic = "marketing-scheduled/{$this->a}/{$this->managerA->id}/" . Str::uuid() . '.jpg';
        Storage::disk('public')->put($theirPic, 'x');
        $bAd = $this->ad($this->b, $this->managerB, 'BRAVO-AD');
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), ['items' => [['company_ad_id' => $bAd->id, 'copy' => 'x', 'image_path' => $theirPic]],
            'platforms' => ['facebook'], 'mode' => 'now'])->assertStatus(422);
        $this->assertSame(1, PropertyMarketingPost::withoutGlobalScopes()->count(), 'only A\'s own post exists');

        // B's admin (all-scope) never sees A's post; B cannot cancel / edit / reschedule it by id.
        $this->actingAs($adminB)->get(route('tools.ad-manager.scheduled'))->assertOk()->assertDontSee('ALPHA-XMAS-AD');
        $this->post(route('tools.ad-manager.scheduled.cancel', $postA))->assertNotFound();
        $this->post(route('tools.ad-manager.scheduled.update', $postA), ['copy' => 'x', 'date' => '2026-10-27', 'time' => '09:00'])->assertNotFound();
        DB::table('property_marketing_posts')->where('id', $postA)->update(['status' => 'cancelled']);
        $this->post(route('tools.ad-manager.scheduled.reschedule', $postA), ['date' => '2026-10-27', 'time' => '09:00'])->assertNotFound();
        $this->assertSame('cancelled', PropertyMarketingPost::withoutGlobalScopes()->find($postA)->status);
    }

    // ── A CoreX owner account working in an agency ─────────────────────────

    public function test_an_owner_account_sees_and_changes_only_the_agency_it_is_working_in(): void
    {
        Role::forceCreate(['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => true]);
        Role::clearCache();
        // An owner whose home agency is A and who has NOT used the agency switcher: AgencyScope does not limit it.
        $owner = $this->userIn($this->a, 'super_admin');
        $this->assertTrue($owner->isOwnerRole());
        $adB = $this->ad($this->b, $this->managerB, 'BRAVO-PRIVATE-AD');
        $this->connect($owner);
        $this->actingAs($owner);

        $this->get(route('tools.ad-manager.templates.company', ['status' => 'all']))->assertOk()
            ->assertSee('ALPHA-XMAS-AD')->assertDontSee('BRAVO-PRIVATE-AD');
        $this->get(route('tools.ad-manager.company-ads.builder.edit', $adB->id))->assertNotFound();
        $this->putJson(route('api.v1.ad-manager.company-ads.update', $adB->id), ['name' => 'x', 'layout_json' => ['elements' => []]])->assertNotFound();
        $this->post(route('tools.ad-manager.company-ads.duplicate', $adB->id))->assertNotFound();
        $this->post(route('tools.ad-manager.company-ads.archive', $adB->id))->assertNotFound();

        // What it makes lands in the agency it is working in.
        $this->postJson(route('api.v1.ad-manager.company-ads.store'), ['name' => 'Owner made', 'layout_json' => ['canvasPreset' => 'facebook', 'elements' => []]])->assertOk();
        $this->assertSame($this->a, (int) CompanyAd::withoutGlobalScopes()->where('name', 'Owner made')->value('agency_id'));

        // It cannot post B's ad.
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), ['items' => [['company_ad_id' => $adB->id, 'copy' => 'x', 'image_path' => $this->storedImage($owner)]],
            'platforms' => ['facebook'], 'mode' => 'now'])->assertStatus(422);
        $this->assertSame('BRAVO-PRIVATE-AD', CompanyAd::withoutGlobalScopes()->find($adB->id)->name);
        $this->assertSame(0, PropertyMarketingPost::withoutGlobalScopes()->count());
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithManager(array $attrs): array
    {
        $id = (int) DB::table('agencies')->insertGetId(array_merge(['slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()], $attrs));

        return [$id, $this->userIn($id, 'branch_manager')];
    }

    private function userIn(int $agency, string $role): User
    {
        $branch = DB::table('branches')->where('agency_id', $agency)->value('id')
            ?? (int) DB::table('branches')->insertGetId(['agency_id' => $agency, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

        return User::factory()->create(['agency_id' => $agency, 'branch_id' => $branch, 'role' => $role]);
    }

    private function ad(int $agency, User $user, string $name, bool $archived = false): CompanyAd
    {
        $id = (int) DB::table('company_ads')->insertGetId([
            'agency_id' => $agency, 'user_id' => $user->id, 'name' => $name, 'canvas' => 'facebook',
            'layout_json' => json_encode(['canvasW' => 1200, 'canvasH' => 628, 'canvasPreset' => 'facebook', 'elements' => []]),
            'caption' => 'Hi', 'ai_generated' => false, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $archived ? now() : null,
        ]);

        return CompanyAd::withoutGlobalScopes()->findOrFail($id);
    }

    private function upload(int $agency, User $user): CompanyAdImage
    {
        $path = 'company-ads/' . $agency . '/' . Str::uuid() . '.jpg';
        $img = imagecreatetruecolor(50, 50);
        ob_start();
        imagejpeg($img);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        $id = (int) DB::table('company_ad_images')->insertGetId(['agency_id' => $agency, 'user_id' => $user->id, 'path' => $path, 'width' => 50, 'height' => 50, 'created_at' => now(), 'updated_at' => now()]);

        return CompanyAdImage::withoutGlobalScopes()->findOrFail($id);
    }

    private function connect(User $user): void
    {
        AgentSocialAccount::withoutGlobalScopes()->create([
            'agency_id' => $user->agency_id, 'user_id' => $user->id, 'platform' => 'facebook', 'platform_page_id' => '1000' . $user->id,
            'platform_page_name' => 'Page', 'access_token' => 'EAABsecrettokenvalue1234567890', 'token_expires_at' => now()->addDays(30), 'is_active' => true,
        ]);
    }

    private function storedImage(User $user): string
    {
        $path = "marketing-scheduled/{$user->agency_id}/{$user->id}/" . Str::uuid() . '.jpg';
        Storage::disk('public')->put($path, 'jpeg-bytes');

        return $path;
    }

    private function post_(int $agency, User $user, CompanyAd $ad): int
    {
        return (int) DB::table('property_marketing_posts')->insertGetId([
            'agency_id' => $agency, 'company_ad_id' => $ad->id, 'user_id' => $user->id, 'platform' => 'facebook', 'ad_copy' => 'x',
            'image_urls' => json_encode([]), 'status' => 'scheduled', 'scheduled_for' => '2026-10-25 07:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
