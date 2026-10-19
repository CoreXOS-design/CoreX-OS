<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\Agency;
use App\Models\Property;
use App\Models\User;
use App\Services\AdTemplates\AdLayoutValidator;
use App\Services\AdTemplates\CompanyAdBuilderService;
use App\Services\AdTemplates\CompanyAdLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The "Agency Phone" ad block (Johan, 2026-10-18: yes to the open item in company-ads.md §14a): the agency's own main
 * phone number from Company Settings, offered in the Ad Builder for property templates and company ads, known to the
 * AI validator, and part of a company ad's "Our contact details" tick. Always the requesting / owning agency's number.
 */
final class AgencyPhoneAdBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_kernel_offers_the_block_and_the_validator_accepts_it(): void
    {
        $js = (string) file_get_contents(public_path('js/corex-ad-render.js'));
        $this->assertStringContainsString("{ type: 'agency_phone', group: 'branding', label: 'Agency Phone'", $js);
        $this->assertMatchesRegularExpression('/agency_phone:\s*\{[^}]*preview:/', $js);

        $this->assertArrayHasKey('agency_phone', AdLayoutValidator::FIELDS);
        $this->assertArrayHasKey('agency_phone', AdLayoutValidator::fieldsFor(AdLayoutValidator::MODE_COMPANY));
        $this->assertContains('agency_phone', CompanyAdLayout::ALLOWED_FIELDS);

        $res = app(AdLayoutValidator::class)->validateAndRepair(['name' => 'x', 'canvasBg' => '#0b1220', 'elements' => [
            ['field' => 'image_1', 'x' => 0, 'y' => 0, 'w' => 600, 'h' => 628],
            ['field' => 'agency_name', 'x' => 640, 'y' => 40, 'w' => 500, 'h' => 60, 'fontSize' => 30, 'color' => '#ffffff'],
            ['field' => 'agency_phone', 'x' => 640, 'y' => 120, 'w' => 500, 'h' => 40, 'fontSize' => 22, 'color' => '#ffffff'],
        ]], 'facebook');
        $this->assertTrue($res['ok'], implode(' | ', $res['errors']));
        $this->assertSame('Agency Phone', collect($res['layout']['elements'])->firstWhere('field', 'agency_phone')['label']);
    }

    public function test_listing_ads_and_company_ads_carry_their_own_agencys_number(): void
    {
        [$a, $ua] = $this->agencyWithUser(['phone' => '021 555 0101']);
        [$b, $ub] = $this->agencyWithUser(['phone' => '039 555 0202']);
        $this->actingAs($ua);
        $property = Property::create(['agency_id' => $a, 'agent_id' => $ua->id, 'title' => 'House', 'status' => 'active', 'listing_type' => 'sale', 'property_type' => 'house']);

        $this->assertSame('021 555 0101', $property->fresh()->load('agency')->adData()['agency_phone']);
        $this->assertSame('021 555 0101', CompanyAdLayout::brandData($ua, Agency::find($a))['agency_phone']);
        $this->assertSame('039 555 0202', CompanyAdLayout::brandData($ub, Agency::find($b))['agency_phone']);

        // An agency with no number shows nothing there (the block is simply empty).
        [$c, $uc] = $this->agencyWithUser(['phone' => null]);
        $this->assertSame('', CompanyAdLayout::brandData($uc, Agency::find($c))['agency_phone']);
    }

    public function test_our_contact_details_means_the_website_and_the_phone_the_agency_has(): void
    {
        $both    = new Agency(['website_url' => 'https://example.test', 'phone' => '021 555 0101']);
        $phone   = new Agency(['website_url' => '', 'phone' => '021 555 0101']);
        $neither = new Agency(['website_url' => '', 'phone' => '']);

        $this->assertSame(['website', 'agency_phone'], CompanyAdBuilderService::contactFields($both));
        $this->assertSame(['agency_phone'], CompanyAdBuilderService::contactFields($phone));
        $this->assertSame([], CompanyAdBuilderService::contactFields($neither));
    }

    public function test_a_company_ad_design_without_the_agency_phone_is_not_shown_when_contact_is_ticked(): void
    {
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        Storage::fake('local');
        [, $user] = $this->agencyWithUser(['phone' => '021 555 0101', 'website_url' => '']);

        $base = ['name' => 'A', 'canvasBg' => '#0b1220', 'elements' => [
            ['field' => 'custom_text', 'x' => 40, 'y' => 40, 'w' => 800, 'h' => 120, 'fontSize' => 44, 'color' => '#ffffff', 'text' => 'Happy holidays'],
            ['field' => 'logo', 'x' => 40, 'y' => 500, 'w' => 300, 'h' => 80],
            ['field' => 'line', 'x' => 40, 'y' => 200, 'w' => 600, 'h' => 4],
        ]];
        $withPhone = $base;
        $withPhone['elements'][] = ['field' => 'agency_phone', 'x' => 40, 'y' => 260, 'w' => 400, 'h' => 40, 'fontSize' => 22, 'color' => '#ffffff'];
        $calls = 0;
        Http::fake(function () use (&$calls, $base, $withPhone) {
            $calls++;
            $options = $calls === 1 ? [$withPhone, $base, $withPhone] : [$base];

            return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['options' => $options])]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200);
        });
        $this->actingAs($user);

        $gen = app(CompanyAdBuilderService::class)->generate($user, 'Happy holidays', 'facebook', null, 'none', mustInclude: ['contact']);

        $this->assertCount(2, $gen->options_json);
        foreach ($gen->options_json as $o) {
            $this->assertContains('agency_phone', collect($o['layout']['elements'])->pluck('field'));
        }
        $this->assertStringContainsString('the agency_phone element', json_decode(Http::recorded()[0][0]->body(), true)['messages'][0]['content'][0]['text'] ?? json_encode(json_decode(Http::recorded()[0][0]->body(), true)['messages'][0]['content']));
    }

    /** @return array{0: int, 1: User} */
    private function agencyWithUser(array $attrs = []): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId(array_merge([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
        $branchId = (int) DB::table('branches')->insertGetId(['agency_id' => $agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

        return [$agencyId, User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent'])];
    }
}
