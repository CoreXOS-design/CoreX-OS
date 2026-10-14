<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Mail\Rentals\RentalLandlordDecisionNeededMail;
use App\Models\Agency;
use App\Models\AgencyMapSettings;
use App\Models\Branch;
use App\Models\CommissionSetting;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\OutboundMailGuardCapture;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Models\User;
use App\Services\SellerOutreach\SellerOutreachTemplateDefaultsService;
use App\Services\SellerOutreach\SellerOutreachTemplateValidator;
use App\Services\WebTemplateDataService;
use App\Support\MailFromName;
use App\Support\PlatformSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Cape walk, 14 Oct 2026 - Johan: nothing a SECOND agency's staff or clients see may be Home Finders Coastal's (or the KZN South Coast's).
 * Bug-class: every case runs for a second agency ("Cape Town Rentals") while the install's own config and agency 1 are HFC's.
 *
 *  1. the sender NAME of mails that did not choose one (fault mails to owner / tenant ...) is the agency the mail is about
 *  2. a new agency's starter outreach text names no agency or region of its own
 *  3. a property's public "View listing" link is its agency's own website, else CoreX's public page - never another agency's site
 *  4. an agreement's district is the property's, else blank - never a default municipality
 *  5. a new agency's commission settings are not HFC's numbers
 *  6. the map opens on the agency's own area, else the country - never the KZN South Coast
 *  7. a scanner: no view / mail / config / script says HFC, Home Finders, Uvongo, Margate, Port Shepstone, Ray Nkonyeni, KZN, hfcoastal
 *     outside the listed agency-1 seed data and demo showcase pages
 */
final class SecondAgencyNeverSeesAnotherAgencyTest extends TestCase
{
    use RefreshDatabase;

    private Agency $hfc;
    private Agency $cape;
    private Branch $capeBranch;
    private User $capeAgent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'app.name' => 'CoreX OS',
            'mail.default' => 'array',
            'mail.from.name' => 'Home Finders Coastal',
            'mail.from.address' => 'system@hfcoastal.co.za',
        ]);
        PlatformSender::flush();

        $this->hfc = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-coastal', 'email' => 'letting@hfcoastal.co.za']);
        $this->cape = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'ctr-' . uniqid(), 'email' => 'hello@ctr.test']);
        $this->capeBranch = Branch::create(['agency_id' => $this->cape->id, 'name' => 'Cape Town Central']);
        $this->capeAgent = User::factory()->create(['agency_id' => $this->cape->id, 'branch_id' => $this->capeBranch->id, 'role' => 'admin']);
    }

    private function capeFault(): RentalFaultReport
    {
        $property = Property::forceCreate([
            'agency_id' => $this->cape->id, 'agent_id' => $this->capeAgent->id, 'branch_id' => $this->capeBranch->id,
            'title' => 'Sea Point Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->cape->id, 'branch_id' => $this->capeBranch->id, 'property_id' => $property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => now()->subMonth(),
            'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $tenant = Contact::withoutGlobalScopes()->create(['agency_id' => $this->cape->id, 'branch_id' => $this->capeBranch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 't' . uniqid() . '@example.test']);
        $type = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->cape->id, 'name' => 'Geyser', 'category' => 'Plumbing', 'urgency' => 'urgent',
            'first_aid_steps' => 'Switch off.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);

        return RentalFaultReport::create([
            'agency_id' => $this->cape->id, 'property_id' => $property->id, 'lease_id' => $lease->id, 'rental_fault_type_id' => $type->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'title' => 'Geyser leaks', 'description' => 'Wet floor.',
            'created_by_user_id' => $this->capeAgent->id, 'reported_at' => now(), 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
        ]);
    }

    private function fromHeaderOfLastCapture(): string
    {
        $raw = (string) OutboundMailGuardCapture::query()->latest('id')->value('raw_mime');
        self::assertNotSame('', $raw, 'the guard captured the mail');
        // unfold the header block, then read From
        $head = preg_replace("/\r?\n[ \t]+/", ' ', (string) strstr($raw, "\r\n\r\n", true) ?: (string) strstr($raw, "\n\n", true)) ?? '';
        preg_match('/^From:\s*(.+)$/mi', $head, $m);

        return trim($m[1] ?? '');
    }

    // ── 1. sender name ────────────────────────────────────────────────────────────────

    public function test_a_fault_mail_to_the_owner_leaves_under_the_lease_agencys_name_not_the_install_default(): void
    {
        $fault = $this->capeFault();
        $mail = new RentalLandlordDecisionNeededMail($fault, 'Olive Owner', 'olive@example.test');

        Mail::to('olive@example.test')->sendNow($mail);

        $from = $this->fromHeaderOfLastCapture();
        self::assertStringContainsString('Cape Town Rentals', $from, 'the owner reads the lease agency as the sender');
        self::assertStringNotContainsString('Home Finders', $from);
    }

    public function test_the_rename_finds_the_agency_from_whatever_record_the_mail_was_built_from(): void
    {
        $fault = $this->capeFault();
        $mail = new RentalLandlordDecisionNeededMail($fault, 'Olive Owner', 'olive@example.test');
        $email = (new Email())->from(new Address('system@hfcoastal.co.za', 'Home Finders Coastal'))->to('olive@example.test')->subject('x')->text('x');

        MailFromName::rebrand(new MessageSending($email, $mail->buildViewData()));
        self::assertSame('Cape Town Rentals', $email->getFrom()[0]->getName());
        self::assertSame('system@hfcoastal.co.za', $email->getFrom()[0]->getAddress(), 'only the name is changed here (the address is PlatformSender\'s job)');

        // a mail that names an agency in its own data array
        $email2 = (new Email())->from(new Address('x@y.test', 'Home Finders Coastal'))->to('a@b.test')->subject('x')->text('x');
        MailFromName::rebrand(new MessageSending($email2, ['agencyId' => $this->cape->id]));
        self::assertSame('Cape Town Rentals', $email2->getFrom()[0]->getName());
    }

    public function test_a_mail_that_names_no_agency_leaves_under_the_platforms_own_name_never_an_agencys(): void
    {
        $email = (new Email())->from(new Address('system@hfcoastal.co.za', 'Home Finders Coastal'))->to('a@b.test')->subject('x')->text('x');

        MailFromName::rebrand(new MessageSending($email, ['message' => 'nothing about an agency']));

        self::assertSame('CoreX OS', $email->getFrom()[0]->getName(), 'mail.from.name is an agency\'s name here, so the platform name is used');
        self::assertSame('CoreX OS', PlatformSender::name());
    }

    public function test_a_mail_that_chose_its_own_sender_name_is_left_as_it_set_it(): void
    {
        $email = (new Email())->from(new Address('x@y.test', 'Pat Agent via Cape Town Rentals'))->to('a@b.test')->subject('x')->text('x');

        MailFromName::rebrand(new MessageSending($email, ['agencyId' => $this->cape->id]));

        self::assertSame('Pat Agent via Cape Town Rentals', $email->getFrom()[0]->getName());
    }

    // ── 2. starter outreach text ──────────────────────────────────────────────────────

    public function test_a_new_agencys_starter_outreach_template_names_no_agency_or_region_of_its_own(): void
    {
        // agency 1 owns an HFC-worded template - the old clone source
        SellerOutreachTemplate::withoutGlobalScopes()->create([
            'agency_id' => $this->hfc->id, 'name' => 'General Marketing — Area Updates', 'channel' => 'whatsapp', 'is_active' => true,
            'is_default_for_channel' => true, 'include_tracking_link' => false,
            'body' => 'Hi {seller_name}, this is {agent_name} from {agency_name} — a registered estate agency on the KZN South Coast. {opt_out_link}',
        ]);

        app(SellerOutreachTemplateDefaultsService::class)->ensureDefaults((int) $this->cape->id);

        $row = SellerOutreachTemplate::withoutGlobalScopes()->where('agency_id', $this->cape->id)->where('channel', 'whatsapp')->first();
        self::assertNotNull($row);
        self::assertDoesNotMatchRegularExpression('/KZN|South Coast|Home Finders|HFC|Uvongo|Margate/i', $row->body);
        self::assertStringContainsString('{agency_name}', $row->body);
        self::assertStringContainsString('{property_town}', $row->body);
        self::assertStringContainsString('{opt_out_link}', $row->body);
        self::assertSame([], app(SellerOutreachTemplateValidator::class)->unknownMergeFields($row->body), 'every merge field in the starter text exists');

        // HFC's own row is exactly as it was
        self::assertStringContainsString('KZN South Coast', (string) SellerOutreachTemplate::withoutGlobalScopes()->where('agency_id', $this->hfc->id)->value('body'));
    }

    public function test_the_migration_neutralises_earlier_clones_and_leaves_agency_one_and_edits_alone(): void
    {
        $old = 'Hi {seller_name}, this is {agent_name} from {agency_name} — a registered estate agency on the KZN South Coast.' . "\n" . 'Own edit line. {opt_out_link}';
        $make = fn (int $agencyId) => SellerOutreachTemplate::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'name' => 'General', 'channel' => 'whatsapp', 'is_active' => true, 'is_default_for_channel' => true,
            'include_tracking_link' => false, 'body' => $old,
        ]);
        // agency 1 is Home Finders Coastal's: its own row is never rewritten
        if (! Agency::withoutGlobalScopes()->find(1)) {
            Agency::forceCreate(['id' => 1, 'name' => 'Agency One', 'slug' => 'agency-one-' . uniqid()]);
        }
        $agencyOneRow = $make(1);
        $capeRow = $make((int) $this->cape->id);

        (require base_path('database/migrations/2026_10_14_150000_neutralise_cloned_hfc_outreach_template.php'))->up();

        self::assertSame($old, $agencyOneRow->fresh()->body, 'agency 1\'s own template is untouched');
        self::assertStringNotContainsString('KZN', (string) $capeRow->fresh()->body);
        self::assertStringContainsString('Own edit line.', (string) $capeRow->fresh()->body, 'the agency\'s own wording is kept');
        self::assertStringContainsString('{property_town}', (string) $capeRow->fresh()->body);
    }

    // ── 3. public link ────────────────────────────────────────────────────────────────

    public function test_a_properties_public_link_is_its_own_agencys_website_else_the_coreX_public_page(): void
    {
        $mk = fn (Agency $a) => Property::forceCreate([
            'agency_id' => $a->id, 'agent_id' => $this->capeAgent->id, 'title' => 'Sea Point Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        // no website setting: CoreX's public property page of THAT agency
        $p = $mk($this->cape);
        self::assertSame(route('public.agency.properties.show', ['agencySlug' => $this->cape->slug, 'property' => $p->id]), $p->public_url);
        self::assertStringNotContainsString('hfcoastal', $p->public_url);

        // with its own website
        DB::table('agencies')->where('id', $this->cape->id)->update(['website_url' => 'https://www.ctr.test/']);
        app()->forgetScopedInstances();
        self::assertSame("https://www.ctr.test/property/sea-point-unit-{$p->id}", $p->fresh()->public_url);

        // HFC's own link is HFC's website (set on its own row, not a install-wide default)
        DB::table('agencies')->where('id', $this->hfc->id)->update(['website_url' => 'https://www.hfcoastal.co.za']);
        app()->forgetScopedInstances();
        $h = $mk($this->hfc);
        self::assertSame("https://www.hfcoastal.co.za/property/sea-point-unit-{$h->id}", $h->public_url);
    }

    public function test_the_install_wide_fallback_is_not_an_agencys_site(): void
    {
        self::assertStringNotContainsString('hfcoastal', (string) config('integrations.public_website_url'));
    }

    // ── 4. agreement district ─────────────────────────────────────────────────────────

    public function test_an_agreements_district_is_the_propertys_else_blank_never_a_default_municipality(): void
    {
        $this->actingAs($this->capeAgent);
        $step = fn (array $property) => ['property' => $property + ['address' => '12 Main Rd', 'suburb' => 'Sea Point'], 'recipients' => ['recipients' => []]];

        $none = app(WebTemplateDataService::class)->resolve(0, $step([]), $this->capeAgent);
        self::assertSame('', $none['district']);
        self::assertSame('', $none['property_district']);

        $own = app(WebTemplateDataService::class)->resolve(0, $step(['district' => 'City of Cape Town']), $this->capeAgent);
        self::assertSame('City of Cape Town', $own['district']);
        self::assertSame('City of Cape Town', $own['property_district']);
    }

    // ── 5. commission ─────────────────────────────────────────────────────────────────

    public function test_a_new_agencys_commission_settings_are_not_hfcs_numbers(): void
    {
        $s = CommissionSetting::forAgency((int) $this->cape->id);

        self::assertEquals(0, $s->annual_cap);
        self::assertEquals(0, $s->monthly_platform_fee);
        self::assertEquals(0, $s->post_cap_transaction_fee);
        self::assertEquals(100, $s->commission_split_agent);
        self::assertEquals(0, $s->commission_split_agency);
        self::assertFalse((bool) $s->revenue_share_enabled);
        self::assertFalse((bool) $s->mentor_program_enabled);
        foreach (range(1, 7) as $t) {
            self::assertEquals(0, $s->{"tier_{$t}_percent"}, "tier {$t} is blank, not HFC's percentage");
        }
        foreach (['annual_cap' => 160000, 'monthly_platform_fee' => 850, 'post_cap_transaction_fee' => 2500, 'tier_1_percent' => 3.5] as $col => $hfcNumber) {
            self::assertNotEquals($hfcNumber, (float) $s->{$col}, "{$col} must not be HFC's {$hfcNumber}");
        }
        self::assertEquals(0, CommissionSetting::forAgency(0)->annual_cap, 'no-agency defaults are neutral too');
    }

    // ── 6. map ────────────────────────────────────────────────────────────────────────

    public function test_the_map_opens_on_the_agencys_own_area_else_the_country_never_the_kzn_south_coast(): void
    {
        AgencyMapSettings::clearCache();

        // nothing set, no stock yet: the country
        $cfg = AgencyMapSettings::forAgency((int) $this->cape->id)->clientConfig();
        self::assertEqualsWithDelta(-28.5, $cfg['center']['lat'], 0.01);
        self::assertSame(5, $cfg['zoom']);
        self::assertNotEqualsWithDelta(-30.70, $cfg['center']['lat'], 0.001);

        // it holds Cape Town stock: its own patch
        Property::forceCreate([
            'agency_id' => $this->cape->id, 'agent_id' => $this->capeAgent->id, 'title' => 'Sea Point Unit', 'status' => 'active',
            'listing_type' => 'rental', 'latitude' => -33.9249, 'longitude' => 18.4241,
        ]);
        AgencyMapSettings::clearCache();
        $own = AgencyMapSettings::forAgency((int) $this->cape->id)->clientConfig();
        self::assertEqualsWithDelta(-33.9249, $own['center']['lat'], 0.01);
        self::assertEqualsWithDelta(18.4241, $own['center']['lng'], 0.01);
        self::assertSame(11, $own['zoom']);
        self::assertGreaterThan($own['center']['lat'], $own['bounds']['north']);
        self::assertLessThan($own['center']['lat'], $own['bounds']['south']);
        self::assertGreaterThan($own['bounds']['south'], $own['bounds']['north']);

        // its own saved view still wins
        AgencyMapSettings::forAgency((int) $this->cape->id)->update(['center_lat' => -26.2, 'center_lng' => 28.0, 'default_zoom' => 9]);
        AgencyMapSettings::clearCache();
        $saved = AgencyMapSettings::forAgency((int) $this->cape->id)->clientConfig();
        self::assertEqualsWithDelta(-26.2, $saved['center']['lat'], 0.001);
        self::assertSame(9, $saved['zoom']);
    }

    public function test_hfcs_own_map_view_is_frozen_on_its_row_so_it_opens_where_it_always_has(): void
    {
        AgencyMapSettings::clearCache();

        (require base_path('database/migrations/2026_10_14_150200_freeze_hfc_map_view.php'))->up();
        AgencyMapSettings::clearCache();

        $cfg = AgencyMapSettings::forAgency((int) $this->hfc->id)->clientConfig();
        self::assertEqualsWithDelta(-30.70, $cfg['center']['lat'], 0.001);
        self::assertEqualsWithDelta(30.45, $cfg['center']['lng'], 0.001);
        self::assertSame(11, $cfg['zoom']);
        self::assertEqualsWithDelta(-30.40, $cfg['bounds']['north'], 0.001);
    }

    // ── 7. scanner ────────────────────────────────────────────────────────────────────

    /** Seed data and showcase pages that belong to agency 1 or to CoreX's own demos (not what a second agency's staff see). */
    private const ALLOWED = [
        '#^config/(south_coast_areas|p24_suburbs|corex-images)\.php$#',                       // HFC's own market data / retired vhost list
        '#^resources/views/(buyer-portal|seller-link)/demo\.blade\.php$#',                      // CoreX's demo showcase pages
        '#^resources/views/docuperfect/web-templates/(cds|imported)/#',                         // HFC's imported documents (generated)
        '#^resources/views/presentations/[0-9a-f]{32}\.php$#',                                   // hash-named legacy view, no caller
    ];

    public function test_no_view_mail_config_or_script_names_hfc_or_the_kzn_south_coast(): void
    {
        $hits = [];
        foreach (['resources/views', 'app/Mail', 'config', 'resources/js', 'public/js'] as $root) {
            $dir = base_path($root);
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                foreach (self::ALLOWED as $allow) {
                    if (preg_match($allow, $rel) === 1) {
                        continue 2;
                    }
                }

                $src = (string) file_get_contents($file->getPathname());
                // comments never reach a person
                $blank = fn (array $m) => preg_replace('/[^\n]/', '', $m[0]);
                $src = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $src) ?? $src;
                $src = preg_replace_callback('#/\*.*?\*/#s', $blank, $src) ?? $src;
                $src = preg_replace_callback('/<!--.*?-->/s', $blank, $src) ?? $src;

                foreach (explode("\n", $src) as $i => $line) {
                    if (preg_match('#^\s*(//|\*|\#)#', $line) === 1) {
                        continue;
                    }
                    if (preg_match('/HFC|KZN|Uvongo|Margate|Port Shepstone|Ray Nkonyeni|KwaZulu|Shelly Beach/', $line) === 1
                        || preg_match('/Home Finders|hfcoastal/i', $line) === 1) {
                        $hits[] = $rel . ':' . ($i + 1) . '  ' . trim(mb_substr($line, 0, 110));
                    }
                }
            }
        }

        self::assertSame([], $hits, "One agency's name or region reaches every agency's staff or clients:\n" . implode("\n", array_slice($hits, 0, 40)));
    }
}
