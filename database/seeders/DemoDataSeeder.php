<?php

namespace Database\Seeders;

use App\Models\ContactMatch;
use App\Models\DevSetting;
use App\Models\Docuperfect\Document;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\FicaSubmission;
use App\Models\Presentation;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use App\Services\Presentations\PresentationCompilerService;
use App\Services\Prospecting\ProspectingClaimService;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use App\Services\SellerOutreach\SellerOutreachSenderService;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * DemoDataSeeder — one-command, fully-coherent KZN South Coast demo dataset.
 *
 * Builds an interconnected estate-agency dataset by calling the SAME
 * service-layer methods agents use wherever a CLI-safe service exists
 * (TrackedPropertyMatchOrCreateService, ProspectingClaimService,
 * SellerOutreach*, PresentationCompilerService, DealPipelineService),
 * and raw Model::create()/DB::table()->insert() everywhere the create
 * path is controller-only (Agency/Branch/User/Contact/Property/
 * ContactMatch/ProspectingListing/FICA/OTP/e-sign).
 *
 * SAFETY: local-env only; mail driver asserted log/array; Mail+Queue
 * faked for the whole run. Deterministic RNG so a fresh-DB re-run
 * produces identical structure. Never sends mail / never hits a real
 * external API. Targets agency_id = 1 (reference seeders hardcode it;
 * migrate:fresh already creates agency 1 "HFC Coastal").
 *
 * KNOWN APP BUG routed around in Stage 11: the seeded "Standard Bond
 * Sale" pipeline's "Bond Approved" step has status_trigger='granted',
 * but deals_v2.status is enum('active','completed','cancelled','on_hold')
 * under STRICT_TRANS_TABLES — DealPipelineService::approveStep() would
 * throw writing 'granted'. The seeder replicates approveStep's
 * bookkeeping WITHOUT the invalid status write, then uses the service's
 * own activateDownstreamSteps(). See DEMO_DATA.md.
 */
class DemoDataSeeder extends Seeder
{
    private const AGENCY_ID = 1;

    // NOT 'demo@corexos.co.za'. `users.email` is utf8mb4_unicode_ci (case-
    // INSENSITIVE) under a UNIQUE index, so 'demo@…' and the System Owner's
    // 'Demo@…' (SystemOwnerSeeder::EMAIL) are THE SAME KEY to MySQL. Sharing
    // the string means one account silently overwrites the other: the owner's
    // updateOrCreate would match this tenant admin and null out its agency_id,
    // detaching the whole demo dataset from agency 1. The two identities are
    // kept on separate domains so they can never collide again.
    private const DEMO_LOGIN_EMAIL = 'admin@demo.corexos.co.za';
    private const DEMO_LOGIN_PASSWORD = 'CoreXDemo!2026';
    private const RNG_SEED = 20260518;

    /** Photos dealt to each listing from the committed pool. See stage5b_propertyPhotos(). */
    private const PHOTOS_PER_PROPERTY = 5;

    // ───────────────────────────────────────────────────────────────────
    //  DEMO IDENTITY — single source of truth.
    //
    //  The demo dataset is customer-facing. NOTHING belonging to Home
    //  Finders Coastal may appear in it: not the trading name, not the real
    //  company reg / VAT / FFC / FIC numbers, not the real office address or
    //  phone, not real staff names, and above all not real ID numbers. Those
    //  values are legitimate for the LIVE agency, so the seeders that own
    //  them (HfcRmcpMasterSeeder, PerformanceSettingsSeeder) are left intact
    //  for production — the demo instead pre-seeds these values so those
    //  seeders' "already exists" guards short-circuit, and then asserts in
    //  stageV_verifyDemoIntegrity() that not one HFC string survived.
    //
    //  Every identifier below is deliberately fake and cannot collide with a
    //  real CIPC / SARS / PPRA / FIC registry entry.
    // ───────────────────────────────────────────────────────────────────
    private const DEMO_AGENCY_NAME    = 'CoreX Demo Realty';
    private const DEMO_AGENCY_TRADING = 'CoreX Demo Realty (Pty) Ltd';
    private const DEMO_AGENCY_REG     = '2020/123456/07';
    private const DEMO_AGENCY_VAT     = '4999999999';
    private const DEMO_AGENCY_FFC     = 'DEMO2026001';
    private const DEMO_AGENCY_FIC     = 'DEMO/000000/0000019';
    private const DEMO_AGENCY_ADDRESS = '1 Demo Boulevard, Shelly Beach';
    private const DEMO_AGENCY_TEL     = '(039) 000 0000';
    private const DEMO_EMAIL_DOMAIN   = 'demo.corexos.co.za';

    /** Fictional compliance officer — replaces the real person + real SA ID. */
    private const DEMO_CO_NAME = 'Thandi Mokoena';
    private const DEMO_CO_ID   = '0001010000080'; // structurally valid, registry-impossible

    /**
     * Non-negotiable demo inventory: EVERY agent must own at least this many
     * properties, so no demo agent's My Listings / dashboard is ever empty.
     * Enforced by stage5b_guaranteeMinPerAgent() and asserted in stageV.
     */
    private const MIN_PROPERTIES_PER_AGENT = 5;

    /** Strings that must NEVER appear anywhere in the seeded demo dataset. */
    private const FORBIDDEN_IN_DEMO = [
        'hfcoastal', 'Home Finders', 'HFC Coastal', 'Reichel', 'Elize',
        '2017/431318', '4630287821', '2023116041', 'AI/180629', '7012310053085',
    ];

    /** Real KZN South Coast towns → suburbs. */
    private const TOWN_SUBURBS = [
        'Margate'        => ['Margate', 'Uvongo', 'Manaba Beach', 'Ramsgate'],
        'Shelly Beach'   => ['Shelly Beach', 'St Michaels-on-Sea', 'Southbroom'],
        'Port Shepstone' => ['Port Shepstone', 'Oslo Beach', 'Umtentweni', 'Sea Park'],
    ];

    private array $branchIds = [];
    private array $branchByTown = [];
    private array $agentIds = [];
    private array $bmIds = [];
    private int $adminId = 0;
    private array $agentByBranch = [];
    /** agentId => branchId — reverse of agentByBranch; drives the per-agent inventory guarantee. */
    private array $branchByAgent = [];
    /** Round-robin cursor so property ownership is spread evenly, never randomly clumped. */
    private int $agentCursor = 0;
    /** Spine tracked-property ids that thread the full lifecycle. */
    private array $spine = [];

    /** contact_types ids resolved dynamically by esign_role (NOT hardcoded). */
    private ?int $buyerTypeId = null;
    private ?int $sellerTypeId = null;

    /** Distinct spine street names so the 12 chains map to 12 properties. */
    private const SPINE_STREETS = [
        'Lighthouse Way', 'Sardine Run Crescent', 'Aloe Ridge Close', 'Whale Watch Drive',
        'Coral Reef Lane', 'Milkwood Grove', 'Strelitzia Avenue', 'Dolphin Point Road',
        'Kingfisher Bend', 'Protea Heights', 'Baobab Boulevard', 'Pelican Bay Walk',
    ];

    private function buyerTypeId(): int
    {
        return $this->buyerTypeId ??= (int) (DB::table('contact_types')
            ->where('esign_role', 'buyer')->value('id') ?? 0);
    }

    private function sellerTypeId(): int
    {
        return $this->sellerTypeId ??= (int) (DB::table('contact_types')
            ->where('esign_role', 'seller')->value('id') ?? 0);
    }

    private function contactTypeFor(bool $isBuyer): ?int
    {
        $id = $isBuyer ? $this->buyerTypeId() : $this->sellerTypeId();
        return $id > 0 ? $id : null;
    }

    public function run(): void
    {
        // Double-lock environment gate (see environmentGateRefusal). The
        // --force intent is read from the running console command (demo:seed
        // passes --force through to db:seed; db:seed defines the --force
        // option). When the seeder is invoked without a command context the
        // force intent is false → non-local is refused (the safe direction).
        $forced = (bool) ($this->command?->option('force') ?? false);
        if ($refusal = self::environmentGateRefusal($forced)) {
            throw new \RuntimeException($refusal);
        }

        // HARD STOP: never let the demo seeder write to the real working DB,
        // regardless of environment. Catches `db:seed --class=DemoDataSeeder`
        // and `migrate:fresh --seed --seeder=DemoDataSeeder` on the default
        // (nexus_os) connection. The supported path (demo:seed / explicit
        // --database=demo) switches the default to nexus_os_demo → passes.
        if ($refusal = self::protectedDatabaseRefusal()) {
            throw new \RuntimeException($refusal);
        }

        $this->assertSafeMailDriver();

        // Belt-and-braces: the run never sends mail / dispatches real jobs.
        Mail::fake();
        Queue::fake();
        Bus::fake();

        mt_srand(self::RNG_SEED); // deterministic — identical structure on fresh-DB re-run

        $this->command->info('CoreX demo dataset — KZN South Coast (local, faked mail/queue)');

        $this->stage0_referenceData();
        $this->stage1_agencyBranchesUsers();

        // The permanent System Owner login (Demo@corexos.co.za) — Johan's
        // private door at /demo-owner-login, used to wire the demo up to live.
        // It MUST be re-created by the demo rebuild itself: the documented
        // rebuild is `migrate:fresh --database=demo && demo:seed`, which runs
        // THIS seeder and never DatabaseSeeder — so calling it there alone left
        // the owner account absent from every demo box, and the owner login
        // dead. Safe to run here: this point is already past run()'s double-lock
        // environment + protected-database gates. Idempotent (updateOrCreate).
        // super_admin is NOT in DemoLoginController::ALLOWED_ROLES, so this
        // account is unreachable from the passwordless persona buttons — it is
        // email+password only, which is the point.
        $this->call(SystemOwnerSeeder::class);
        $this->stage2_prospectingAndTracked();
        $this->stage3_claimsAndPitches();
        $this->stage4_contactsAndWishlists();
        $this->stage5_promoteToStock();
        $this->stage5b_propertyPhotos();
        $this->stage6_buyerMatchRecompute();
        $this->stage6b_linkBuyerProperties();
        $this->stage7_presentations();
        $this->stage8_fica();
        $this->stage9_esign();
        $this->stage10_otp();
        $this->stage10b_buyerActivity();
        $this->stage11_deals();
        $this->stage12_calendar();
        $this->stageViewingFeedback_demoShowcase();
        $this->stageSpine_threadFullLifecycle();
        $this->stageZ_demoPresenterCoherence();
        $this->stageV_verifyDemoIntegrity();

        $this->command->info('Demo dataset complete. Login: ' . self::DEMO_LOGIN_EMAIL
            . ' / ' . self::DEMO_LOGIN_PASSWORD);
        $this->command->info('System Owner (/demo-owner-login): ' . SystemOwnerSeeder::EMAIL
            . ' / ' . SystemOwnerSeeder::PASSWORD);
    }

    // ───────────────────────────────────────────────────────────────────
    //  SAFETY
    // ───────────────────────────────────────────────────────────────────

    /**
     * Double-lock environment gate, shared by demo:seed, demo:cleanup and
     * this seeder's own run() guard. Returns NULL when the operation may
     * proceed, or a clear human-readable refusal string otherwise.
     *
     *  - `local` environment      → always allowed (normal dev; no flags).
     *  - any non-local env        → allowed ONLY when BOTH:
     *      (1) --force was passed by the operator, AND
     *      (2) DEMO_SEED_ALLOWED=true is set in THAT environment's .env.
     *
     * The demo SERVER runs APP_ENV=production, so the gate deliberately
     * does NOT key on APP_ENV — a real production box that has not opted
     * in via DEMO_SEED_ALLOWED can NEVER be demo-seeded, even with --force.
     * The env() read fails safe: if config is cached and the var is not
     * visible it evaluates false → refuse (operator runs config:clear).
     */
    public static function environmentGateRefusal(bool $force): ?string
    {
        if (app()->environment('local')) {
            return null;
        }

        $env = app()->environment();

        if (!$force) {
            return "Refusing to run on non-local environment '{$env}'. The safety "
                . "guard stays on by default. To run on a deliberately opted-in DEMO "
                . "environment: set DEMO_SEED_ALLOWED=true in that environment's .env "
                . "AND pass --force.";
        }

        $optedIn = filter_var(env('DEMO_SEED_ALLOWED'), FILTER_VALIDATE_BOOLEAN);
        if (!$optedIn) {
            return "Refusing: --force was given but this environment ('{$env}') has "
                . "NOT opted in. A real production box can never be demo-seeded. Set "
                . "DEMO_SEED_ALLOWED=true in this environment's .env to opt in. "
                . "(If it IS set but config is cached, run `php artisan config:clear` first.)";
        }

        return null;
    }

    /**
     * Real working databases that demo seeding / cleanup must NEVER touch.
     * `migrate:fresh --seed --seeder=DemoDataSeeder` against 'nexus_os' once
     * wiped real local data — this is the hard stop. Demo work belongs in
     * the 'demo' connection (nexus_os_demo).
     *
     * hfc_staging is listed for the same reason: it is a real working database
     * that happens to carry a demo-flagged agency alongside its real one, so a
     * content check alone ("does this DB contain a demo agency?") is NOT enough
     * to keep it safe. Name it, and refuse it outright.
     */
    public const PROTECTED_DATABASES = ['nexus_os', 'hfc_staging'];

    /**
     * Returns NULL when the target database is safe for destructive demo
     * work, or a clear refusal string when it is a protected real DB.
     * Pass an explicit db name, else the current default connection's db is
     * checked (so `db:seed --database=demo` — which switches the default —
     * resolves to nexus_os_demo and passes).
     */
    public static function protectedDatabaseRefusal(?string $databaseName = null): ?string
    {
        $db = $databaseName ?? \Illuminate\Support\Facades\DB::connection()->getDatabaseName();

        if (in_array($db, self::PROTECTED_DATABASES, true)) {
            return "Refusing: demo seeding/cleanup must NOT run against the real "
                . "working database '{$db}'. Demo work belongs in 'nexus_os_demo' "
                . "(the 'demo' connection). Use:  php artisan demo:seed  (auto-targets "
                . "the demo connection), or pass  --database=demo  to db:seed / "
                . "migrate:fresh. See .ai/DEMO_SEEDING.md.";
        }

        return null;
    }

    private function assertSafeMailDriver(): void
    {
        $driver = config('mail.default');
        if (in_array($driver, ['log', 'array'], true)) {
            return;
        }
        if ($driver === 'smtp') {
            $host = (string) config('mail.mailers.smtp.host');
            $localHosts = ['127.0.0.1', 'localhost', '::1', 'mailpit', 'mailhog'];
            if (in_array($host, $localHosts, true)) {
                return;
            }
            throw new \RuntimeException(
                "DemoDataSeeder refuses to run: mail driver is 'smtp' against non-local host '{$host}'. "
                . "Set MAIL_MAILER=log (or array, or smtp→127.0.0.1) before seeding the demo."
            );
        }
        throw new \RuntimeException(
            "DemoDataSeeder refuses to run: unexpected mail driver '{$driver}'. "
            . "Set MAIL_MAILER=log or array for the demo environment."
        );
    }

    private function rngInt(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    private function pick(array $arr)
    {
        return $arr[mt_rand(0, count($arr) - 1)];
    }

    private function allSuburbs(): array
    {
        return array_merge(...array_values(self::TOWN_SUBURBS));
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 0 — reference data
    // ───────────────────────────────────────────────────────────────────

    private function stage0_referenceData(): void
    {
        // Permissions/role_permissions (needs only the roles table — present post-migrate).
        $this->safeSeed('permissions', fn () => \Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]));

        // Reference seeders. BuyerMatchTiersSeeder + CalendarEventClassSeeder
        // are NOT in DatabaseSeeder — the demo invokes them itself.
        // DealPipelineTemplateSeeder needs ≥1 user, so it runs after Stage 1.
        // Each is wrapped so a pre-existing broken reference seeder (e.g.
        // AgencyDocumentTypeConfigSeeder writes a column some fresh-DB
        // schemas lack) cannot abort the whole demo build.
        foreach ([
            ProspectingSetupSeeder::class,
            BuyerMatchTiersSeeder::class,
            CalendarEventClassSeeder::class,
            // Global feedback options (concerns / outcomes / lost reasons)
            // — drives the Capture Feedback modal's Outcome dropdown and
            // Concerns checkboxes (CalendarController::showFeedback reads
            // agency_feedback_options for category=outcome|concern,
            // agency_id NULL ⋃ agency_id=$id). Idempotent: updateOrInsert
            // keyed (agency_id, category, label).
            AgencyFeedbackOptionsSeeder::class,
            // P24 suburbs — Property24ListingMapper::fuzzyLocalMatch maps
            // suburb names to P24 location ids. Empty on demo => every
            // P24 mapping falls through. Idempotent: updateOrCreate by slug.
            P24SuburbSeeder::class,
            // Knowledge base categories — Admin → Knowledge / Ellie KB
            // category dropdown. Idempotent: updateOrInsert by slug.
            KnowledgeCategorySeeder::class,
            // Public holidays (ZA 2026-28) — Leave module + calendar.
            // Idempotent via PublicHolidayService::ensureYearSeeded().
            PublicHolidaySeeder::class,
            // SARS 2026/27 tax rebates + PAYE brackets — Payroll module.
            // Idempotent: updateOrCreate keyed on tax_year_start (+ bracket).
            PayrollTaxRebateSeeder::class,
            PayrollTaxTableSeeder::class,
            // Command-Center automation rules (global; no agency_id).
            // Idempotent: updateOrCreate by name.
            CommandCenterAutomationSeeder::class,
            // performance_settings key/value pairs (vat_rate, company_*,
            // listings_per_sale, marketing_enabled, …). Read by Deal,
            // WorksheetController, MatchPropertyJob via
            // PerformanceSetting::get($key,$default). Admin → Performance
            // Settings renders blank without these. Idempotent by key.
            PerformanceSettingsSeeder::class,
            SuggestedActionThresholdsSeeder::class,
            SellerOutreachTemplatesSeeder::class,
            AgencyDocumentTypeConfigSeeder::class,
            WebTemplateSeeder::class,                   // 6 e-sign web templates
            MarketingPermissionV6Seeder::class,         // + Marketing Permission V6 (sales)
            MarketingPermissionEsignSeeder::class,      // + Marketing Permission Esign (CDS #125, type 23)
            // §19/§20-fixed CDS demo set, captured byte-for-byte from the
            // live rows. SalesMandatoryDisclosureEsignSeeder replaces the old
            // SalesMandatoryDisclosureSeeder (different blade, WITHOUT this
            // session's per-document pagination / disclosure-key fixes) — the
            // old class file is kept but is no longer registered.
            SalesMandatoryDisclosureEsignSeeder::class, // + Sales Mandatory Disclosure (CDS #123, type 11)
            SellerMandatoryAddendumSeeder::class,       // + Seller Mandatory Addendum (CDS #120, type 13)
            ExclusiveAuthorityToSellSeeder::class,      // + Exclusive Authority to Sell (CDS #111, type 1)
            // NOTE: FieldGroupSeeder is NOT here. docuperfect_field_groups
            // .created_by is a NOT-NULL FK→users, but Stage 0 runs BEFORE
            // Stage 1 creates any users — so a Stage-0 FieldGroupSeeder hit
            // its "no user" guard and silently produced 0 groups inside the
            // full chain (worked only standalone, once users existed). It
            // now runs in Stage 1, after users. See stage1_agencyBranchesUsers.
        ] as $seeder) {
            $this->safeSeed(class_basename($seeder), fn () => $this->call([$seeder]));
        }

        // Stage-0 backfills for reference tables that have no canonical
        // seeder file. The exact rows are taken from local nexus_os —
        // do NOT invent. Idempotent: find-or-create by natural key.
        $this->backfillContactTypes();
        $this->backfillPropertyStatusItems();
        $this->backfillDocumentLibraryTypes();
        $this->backfillPropertyTypeOptions();

        // PerformanceSettingsSeeder (above) is correct for the LIVE agency but
        // stamps HFC's real name / address / phone / FFC into the global
        // performance_settings table. Replace with the demo identity.
        $this->overwriteDemoPerformanceSettings();

        $this->command->info('  Stage 0: reference data + permissions seeded');
    }

    /**
     * Add the 1 document_library_types row missing from a fresh demo:
     * 'gaw_reports'. Captured verbatim from local nexus_os.
     * Idempotent keyed on `key`.
     */
    private function backfillDocumentLibraryTypes(): void
    {
        DB::table('document_library_types')->updateOrInsert(
            ['key' => 'gaw_reports'],
            [
                'label'      => 'Gaw Reports',
                'sort_order' => 8,
                'is_active'  => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * Add the 1 property_type_options row missing from a fresh demo:
     * 'smallholding'. Captured verbatim from local (is_active=0, matching
     * local). Idempotent keyed on (agency_id, slug).
     */
    private function backfillPropertyTypeOptions(): void
    {
        DB::table('property_type_options')->updateOrInsert(
            ['agency_id' => self::AGENCY_ID, 'slug' => 'smallholding'],
            [
                'name'          => 'Smallholding',
                'display_order' => 6,
                'is_active'     => 0,
                'updated_at'    => now(),
                'created_at'    => now(),
            ]
        );
    }

    /**
     * Add the 4 contact_types missing from a fresh demo (Lessee, Lessor,
     * Prospect, Tenant). esign_role values come from local nexus_os; they
     * are required for rental/lease esign role resolution. Idempotent
     * keyed on `name`.
     */
    private function backfillContactTypes(): void
    {
        $rows = [
            ['name' => 'Lessee',   'esign_role' => 'lessee'],
            ['name' => 'Lessor',   'esign_role' => 'lessor'],
            ['name' => 'Prospect', 'esign_role' => null],
            ['name' => 'Tenant',   'esign_role' => 'lessee'],
        ];
        $added = 0;
        foreach ($rows as $r) {
            $existing = DB::table('contact_types')->where('name', $r['name'])->whereNull('deleted_at')->exists();
            if ($existing) continue;
            DB::table('contact_types')->insert([
                'name'       => $r['name'],
                'color'      => '#6366f1',
                'sort_order' => 0,
                'esign_role' => $r['esign_role'],
                'is_active'  => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $added++;
        }
        $this->command->info("    contact_types backfill: +{$added} (target Lessee/Lessor/Prospect/Tenant)");
    }

    /**
     * Add the 5 property_setting_items missing from a fresh demo: the
     * "For Sale — *" status nuances + "To Let". Captured verbatim from
     * local nexus_os. Idempotent keyed on (group, name).
     */
    private function backfillPropertyStatusItems(): void
    {
        $rows = [
            ['name' => 'For Sale — Reduced Price',   'sort_order' => 2,  'is_default' => 1],
            ['name' => 'For Sale — Pending',         'sort_order' => 3,  'is_default' => 1],
            ['name' => 'For Sale — Back on Market',  'sort_order' => 4,  'is_default' => 1],
            ['name' => 'For Sale — Raised Price',    'sort_order' => 5,  'is_default' => 1],
            ['name' => 'To Let',                     'sort_order' => 14, 'is_default' => 0],
        ];
        // property_setting_items is AGENCY-SCOPED: agency_id is NOT NULL with no
        // default. Omitting it only ever "worked" against a database where the
        // rows already existed (so the exists() check short-circuited); on a
        // genuinely fresh schema the insert dies with "Field 'agency_id' doesn't
        // have a default value". The scope belongs on the duplicate check too —
        // otherwise one agency's row would suppress the insert for another.
        $added = 0;
        foreach ($rows as $r) {
            $existing = DB::table('property_setting_items')
                ->where('agency_id', self::AGENCY_ID)
                ->where('group', 'property_status')
                ->where('name', $r['name'])
                ->whereNull('deleted_at')
                ->exists();
            if ($existing) continue;
            DB::table('property_setting_items')->insert([
                'agency_id'  => self::AGENCY_ID,
                'group'      => 'property_status',
                'name'       => $r['name'],
                'sort_order' => $r['sort_order'],
                'is_default' => $r['is_default'],
                'active'     => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $added++;
        }
        $this->command->info("    property_setting_items backfill: +{$added} status items");
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE V — VERIFY. The demo is only "seeded" if it is actually populated
    //  and actually free of Home Finders Coastal data. Both were silently false
    //  before: stages are wrapped in safeSeed(), so a broken stage only warns —
    //  demo:seed could report success while leaving 0 properties behind. This
    //  stage turns that into a hard failure.
    // ───────────────────────────────────────────────────────────────────

    private function stageV_verifyDemoIntegrity(): void
    {
        $failures = [];

        // 1. Inventory floor — every agent has a populated My Listings.
        foreach ($this->agentIds as $agentId) {
            $count = DB::table('properties')
                ->where('agency_id', self::AGENCY_ID)
                ->where('agent_id', $agentId)
                ->whereNull('deleted_at')
                ->count();

            if ($count < self::MIN_PROPERTIES_PER_AGENT) {
                $name = DB::table('users')->where('id', $agentId)->value('name');
                $failures[] = "agent '{$name}' (#{$agentId}) owns {$count} propert"
                    . ($count === 1 ? 'y' : 'ies') . ', need >= ' . self::MIN_PROPERTIES_PER_AGENT;
            }
        }

        // 2. Core pillars are actually populated — catches a silently-skipped stage.
        //
        //    `deals` is the LIVE register (DR1 /admin/deals + the new DR2 both read
        //    it). deals_v2 is the prototype AT-219 retired — its register redirects
        //    away, so rows there are invisible to a demo. Assert BOTH: the live
        //    register must not be empty, which is the assertion that caught the demo
        //    seeding 40 deals into a dead table and none into the real one.
        $mustHave = [
            'properties'         => 1,
            'contacts'           => 1,
            'deals'              => 1,   // DR1/DR2 — what the demo actually shows
            'deals_v2'           => 1,
            'deal_user'          => 1,   // agent commission splits
            'tracked_properties' => 1,
            'branches'           => 3,
            'users'              => 10,
            'calendar_events'    => 1,
            'presentations'      => 1,
        ];

        foreach ($mustHave as $table => $min) {
            $count = DB::table($table)->count();
            if ($count < $min) {
                $failures[] = "table '{$table}' has {$count} row(s), expected >= {$min}";
            }
        }

        // 2b. Demo mode must be ON — dev_settings is dropped by migrate:fresh, so a
        //     regression here would silently leave the demo behind a password login.
        if (!DevSetting::bool('demo_mode_enabled')) {
            $failures[] = "dev_settings 'demo_mode_enabled' is not set — the demo would "
                . 'present a normal password login instead of the role picker';
        }

        // 3. Zero Home Finders Coastal data anywhere customer-visible.
        $scan = [
            'agencies'                  => ['name', 'trading_name', 'reg_no', 'vat_no', 'ffc_no', 'fic_no'],
            'users'                     => ['name', 'email'],
            'performance_settings'      => ['value'],
            'rmcp_variables'            => ['value'],
            'fica_officer_appointments' => ['full_name', 'id_number'],
            'prospecting_listings'      => ['agency_name'],
        ];

        foreach ($scan as $table => $columns) {
            if (!\Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (!\Schema::hasColumn($table, $column)) {
                    continue;
                }

                foreach (self::FORBIDDEN_IN_DEMO as $needle) {
                    $hit = DB::table($table)->where($column, 'like', '%' . $needle . '%')->count();
                    if ($hit > 0) {
                        $failures[] = "HFC data leaked: {$table}.{$column} contains '{$needle}' ({$hit} row(s))";
                    }
                }
            }
        }

        if ($failures) {
            throw new \RuntimeException(
                "Demo seed FAILED verification — the dataset is not fit to demo:\n  - "
                . implode("\n  - ", $failures)
            );
        }

        $props = DB::table('properties')->where('agency_id', self::AGENCY_ID)->count();
        $this->command->info('  Stage V: verified — ' . count($this->agentIds) . ' agents, each with >= '
            . self::MIN_PROPERTIES_PER_AGENT . ' properties (' . $props . ' total); no HFC data present');
    }

    private function safeSeed(string $label, \Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->command->warn("    Stage 0: '{$label}' skipped (pre-existing issue) — "
                . Str::limit($e->getMessage(), 160));
        }
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 1 — agency, 3 branches, ~14 users
    // ───────────────────────────────────────────────────────────────────

    private function stage1_agencyBranchesUsers(): void
    {
        // Reuse agency 1 (the base migration creates it as "HFC Coastal") and
        // OVERWRITE it into the fictional demo agency. fic_no is set here on
        // purpose: HfcRmcpMasterSeeder only stamps the real FIC number when
        // agencies.fic_no is empty, so filling it now makes that seeder's
        // guard short-circuit instead of writing HFC's real registration.
        DB::table('agencies')->where('id', self::AGENCY_ID)->update([
            'name'         => self::DEMO_AGENCY_NAME,
            'trading_name' => self::DEMO_AGENCY_TRADING,
            'reg_no'       => self::DEMO_AGENCY_REG,
            'vat_no'       => self::DEMO_AGENCY_VAT,
            'ffc_no'       => self::DEMO_AGENCY_FFC,
            'fic_no'       => self::DEMO_AGENCY_FIC,
            'is_demo'      => 1,
            'is_active'    => 1,
            'updated_at'   => now(),
        ]);

        // 3 branches.
        foreach (array_keys(self::TOWN_SUBURBS) as $i => $town) {
            $bid = DB::table('branches')->insertGetId([
                'agency_id'  => self::AGENCY_ID,
                'name'       => $town,
                'code'       => strtoupper(Str::substr(str_replace(' ', '', $town), 0, 3)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->branchIds[] = $bid;
            $this->branchByTown[$town] = $bid;
        }
        DB::table('agencies')->where('id', self::AGENCY_ID)
            ->update(['default_branch_id' => $this->branchIds[0]]);

        // Users: 1 admin (demo login), 3 BMs (one per branch), 3 agents per
        // branch, 1 viewer = 14 users. Agents/BMs on the demo domain so the
        // e-sign agent-FROM path is exercised without touching a real mailbox.
        // Name pools are wholly fictional — no real HFC staff (see
        // FORBIDDEN_IN_DEMO; 'Johan'/'Reichel' previously leaked in here).
        $firstNames = ['Thabo', 'Lerato', 'Pieter', 'Anele', 'Michelle', 'Sipho', 'Karen',
            'Bongani', 'Tanya', 'Mandla', 'Nomsa', 'Grant', 'Ayanda', 'Wendy'];
        $lastNames = ['Mahlangu', 'Ndlovu', 'van der Merwe', 'Dlamini', 'Botha', 'Mkhize',
            'Joubert', 'Khumalo', 'Pretorius', 'Nkosi', 'Steyn', 'du Plessis', 'Pillay', 'Venter'];

        // Admin / demo login.
        $this->adminId = $this->createUser(
            'Demo Administrator',
            self::DEMO_LOGIN_EMAIL,
            self::DEMO_LOGIN_PASSWORD,
            'admin',
            $this->branchIds[0],
            true
        );

        $n = 1;
        foreach (array_keys(self::TOWN_SUBURBS) as $ti => $town) {
            $bid = $this->branchByTown[$town];

            // Branch manager.
            $bmId = $this->createUser(
                $firstNames[$n] . ' ' . $lastNames[$n],
                'bm.' . Str::slug($town, '') . '@' . self::DEMO_EMAIL_DOMAIN,
                self::DEMO_LOGIN_PASSWORD,
                'branch_manager',
                $bid,
                false
            );
            $this->bmIds[] = $bmId;
            $n++;

            // 3 agents.
            $this->agentByBranch[$bid] = [];
            for ($a = 0; $a < 3; $a++) {
                $aid = $this->createUser(
                    $firstNames[$n] . ' ' . $lastNames[$n],
                    'agent.' . Str::slug($town, '') . ($a + 1) . '@' . self::DEMO_EMAIL_DOMAIN,
                    self::DEMO_LOGIN_PASSWORD,
                    'agent',
                    $bid,
                    false
                );
                $this->agentIds[] = $aid;
                $this->agentByBranch[$bid][] = $aid;
                $this->branchByAgent[$aid] = $bid;
                $n++;
            }
        }

        // One viewer (read-only role) for completeness.
        $this->createUser('Demo Viewer', 'viewer@' . self::DEMO_EMAIL_DOMAIN, self::DEMO_LOGIN_PASSWORD,
            'viewer', $this->branchIds[0], false);

        // DEMO MODE — the passwordless role-picker login page (auth.demo-login),
        // gated by DemoLoginController::isEnabled() = non-production env AND
        // DevSetting 'demo_mode_enabled'. It lives in dev_settings, which
        // migrate:fresh DROPS — so setting it by hand would silently switch demo
        // mode OFF at the next 3-day demo:refresh. It is seeded here so it
        // survives every rebuild. isEnabled() still refuses on production
        // regardless of this row, so seeding it cannot expose a live login.
        DevSetting::set('demo_mode_enabled', '1');

        // DealPipelineTemplateSeeder needs ≥1 user — run it now.
        $this->safeSeed('DealPipelineTemplateSeeder', fn () => $this->call([DealPipelineTemplateSeeder::class]));
        // Field groups: docuperfect_field_groups.created_by is a NOT-NULL
        // FK→users, so this MUST run after Stage 1 users exist (in Stage 0
        // it silently produced 0). Named fields resolved by triple; group
        // ids stable so template fieldGroupId=8 "Seller Name Surname ID"
        // resolves. Independent of the Stage-0 e-sign template seeders.
        $this->safeSeed('FieldGroupSeeder', fn () => $this->call([FieldGroupSeeder::class]));
        // Web pack needs ≥1 user (web_packs.created_by NOT NULL) + the
        // templates from Stage 0 — runs here, after both exist.
        $this->safeSeed('SellerOnboardingPackSeeder', fn () => $this->call([SellerOnboardingPackSeeder::class]));
        $this->safeSeed('MarketingPermissionPackSeeder', fn () => $this->call([MarketingPermissionPackSeeder::class]));

        // Agency-scoped settings backstops. AgencyObserver::created() does
        // firstOrCreate these rows, but DemoDataSeeder updates the
        // pre-existing agency 1 via raw DB::table()->update() (line ~344),
        // which bypasses Eloquent events — so the observer never fires
        // for agency 1. These seeders iterate Agency::all() and ensure
        // both rows exist. Idempotent (firstOrCreate by agency_id [+ role
        // tuple for the matrix]).
        $this->safeSeed('AgencyContactSettingsSeeder', fn () => $this->call([AgencyContactSettingsSeeder::class]));
        $this->safeSeed('AgencyLeaveVisibilityMatrixSeeder', fn () => $this->call([AgencyLeaveVisibilityMatrixSeeder::class]));

        // BCEA-compliant leave types (annual / sick / family-resp / parental
        // / study / unpaid / special) — agency-scoped, firstOrCreate by
        // (agency_id, code).
        $this->safeSeed('LeaveTypeSeeder', fn () => $this->call([LeaveTypeSeeder::class]));
        // SARS earning / deduction types — agency-scoped per Payroll spec
        // §10.3 / §10.4. firstOrCreate by (agency_id, code).
        $this->safeSeed('PayrollEarningTypeSeeder', fn () => $this->call([PayrollEarningTypeSeeder::class]));
        $this->safeSeed('PayrollDeductionTypeSeeder', fn () => $this->call([PayrollDeductionTypeSeeder::class]));
        // Per-branch letterhead values (branch_settings table).
        // Requires branches to exist — runs after the stage-1 branch
        // creation block. Idempotent keyed (branch_id, key).
        $this->safeSeed('BranchSettingsSeeder', fn () => $this->call([BranchSettingsSeeder::class]));
        // RMCP master (rmcp_versions + sections + variables + officer
        // appointment if missing). The section BODIES are generic compliance
        // text worth having in the demo, but the seeder also writes HFC's real
        // compliance officer — including a REAL South African ID number — and
        // HFC's real legal name. Both are gated behind "if not already set",
        // so we seed the fictional demo officer FIRST: the seeder then finds
        // an appointment present and skips that branch entirely. agencies.fic_no
        // was likewise already filled above. The manual RMCP variables it writes
        // unconditionally are overwritten immediately after.
        $this->seedDemoComplianceOfficer();
        $this->safeSeed('HfcRmcpMasterSeeder', fn () => $this->call([HfcRmcpMasterSeeder::class]));
        $this->overwriteRmcpDemoVariables();

        $this->command->info('  Stage 1: 1 agency + ' . count($this->branchIds)
            . ' branches + ' . (count($this->agentIds) + count($this->bmIds) + 2) . ' users');
    }

    /**
     * Fictional FICA compliance officer for the demo agency.
     *
     * Seeded BEFORE HfcRmcpMasterSeeder so its `if (!$coExists)` guard sees an
     * existing primary appointment and never inserts the real officer (whose
     * row carries a real, live South African ID number). Idempotent.
     */
    private function seedDemoComplianceOfficer(): void
    {
        $exists = DB::table('fica_officer_appointments')
            ->where('agency_id', self::AGENCY_ID)
            ->where('role', FicaOfficerAppointment::ROLE_PRIMARY)
            ->whereNull('ended_on')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('fica_officer_appointments')->insert([
            'agency_id'    => self::AGENCY_ID,
            'role'         => FicaOfficerAppointment::ROLE_PRIMARY,
            'user_id'      => $this->adminId ?: null,
            'full_name'    => self::DEMO_CO_NAME,
            'id_number'    => self::DEMO_CO_ID,
            'email'        => self::DEMO_LOGIN_EMAIL,
            'title'        => 'FICA Compliance Officer',
            'appointed_on' => '2026-03-01',
            'notes'        => 'Demo appointment — fictional officer.',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * HfcRmcpMasterSeeder writes its manual RMCP variables with updateOrCreate
     * (unconditional), so they cannot be pre-empted by a guard the way the
     * officer and fic_no can — they are overwritten here instead. agency.region
     * is left as-is: it is public geography, not HFC information.
     */
    private function overwriteRmcpDemoVariables(): void
    {
        $demoVars = [
            'agency.legal_name' => self::DEMO_AGENCY_TRADING,
            'rmcp.prepared_by'  => self::DEMO_CO_NAME,
        ];

        foreach ($demoVars as $key => $value) {
            DB::table('rmcp_variables')
                ->where('agency_id', self::AGENCY_ID)
                ->where('variable_key', $key)
                ->update(['value' => $value, 'updated_at' => now()]);
        }
    }

    /**
     * performance_settings is GLOBAL (no agency_id) and PerformanceSettingsSeeder
     * — correct for the live agency — stamps HFC's real trading name, office
     * address, phone and FFC number into it during Stage 0. Overwrite with the
     * demo identity. Keyed by `key`, matching that seeder's own upsert.
     */
    private function overwriteDemoPerformanceSettings(): void
    {
        $demo = [
            'company_name'     => self::DEMO_AGENCY_NAME,
            'company_address'  => self::DEMO_AGENCY_ADDRESS,
            'company_tel'      => self::DEMO_AGENCY_TEL,
            'company_ffc'      => self::DEMO_AGENCY_FFC,
            'company_logo_url' => '/storage/company/demo-logo.jpg',
        ];

        foreach ($demo as $key => $value) {
            DB::table('performance_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    private function createUser(string $name, string $email, string $password,
        string $role, int $branchId, bool $isAdmin): int
    {
        return DB::table('users')->insertGetId([
            'name'              => $name,
            'email'             => $email,
            'password'          => Hash::make($password),
            'email_verified_at' => now(),
            'role'              => $role,
            'is_admin'          => $isAdmin ? 1 : 0,
            'agency_id'         => self::AGENCY_ID,
            'branch_id'         => $branchId,
            'is_active'         => 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    private function agentForBranch(int $branchId): int
    {
        $pool = $this->agentByBranch[$branchId] ?? $this->agentIds;
        return $this->pick($pool);
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 2 — ~200 prospecting listings → matchOrCreate → tracked
    // ───────────────────────────────────────────────────────────────────

    private array $listingIds = [];
    private array $listingMeta = []; // listingId => [suburb, town, branch_id, price, beds, type, trackedId]

    private function stage2_prospectingAndTracked(): void
    {
        $matcher = app(TrackedPropertyMatchOrCreateService::class);
        $streets = ['Ocean', 'Marine', 'Beach', 'Hibiscus', 'Palm', 'Coral', 'Lighthouse',
            'Dolphin', 'Sunset', 'Seaview', 'Protea', 'Milkwood', 'Sardine', 'Whale', 'Eagle',
            'Kingfisher', 'Heron', 'Pelican', 'Aloe', 'Strelitzia', 'Baobab', 'Jacaranda'];
        $types = ['House', 'House', 'House', 'Apartment', 'Apartment', 'Townhouse',
            'Vacant Land', 'Commercial'];
        $portals = ['p24', 'pp'];
        $agencies = ['Pam Golding', 'RE/MAX Coastal', 'Seeff South Coast', 'Just Property',
            'Harcourts', 'Chas Everitt', self::DEMO_AGENCY_NAME];

        $created = 0;
        $townList = array_keys(self::TOWN_SUBURBS);
        for ($i = 0; $i < 200; $i++) {
            $town = $townList[$i % count($townList)];
            $branchId = $this->branchByTown[$town];
            $suburb = $this->pick(self::TOWN_SUBURBS[$town]);
            $type = $this->pick($types);
            $beds = $type === 'Apartment' ? $this->rngInt(1, 2)
                : ($type === 'Vacant Land' || $type === 'Commercial' ? 0
                : ($type === 'Townhouse' ? $this->rngInt(2, 3) : $this->rngInt(2, 5)));
            $price = $this->priceFor($beds, $type);
            $portal = $portals[$i % 2];
            $streetNo = $this->rngInt(1, 280);
            $street = $this->pick($streets);
            $address = "{$streetNo} {$street} Drive, {$suburb}";
            $capturedBy = $this->agentForBranch($branchId);
            $firstSeen = now()->subDays($this->rngInt(3, 220));

            $listingId = DB::table('prospecting_listings')->insertGetId([
                'agency_id'           => self::AGENCY_ID,
                'captured_by_user_id' => $capturedBy,
                'portal_source'       => $portal,
                'portal_ref'          => 'DEMO-' . strtoupper($portal) . '-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'portal_url'          => 'https://demo.' . $portal . '.example/listing/' . (100000 + $i),
                'address'             => $address,
                'normalized_address'  => \App\Models\ProspectingListing::normalizeAddress($address, $suburb),
                'suburb'              => $suburb,
                'district'            => $town,
                'price'               => $price,
                'bedrooms'            => $beds ?: null,
                'bathrooms'           => $beds ? max(1, $beds - 1) : null,
                'garages'             => $type === 'Apartment' ? 0 : $this->rngInt(0, 2),
                'property_size_m2'    => $type === 'Vacant Land' ? null : $this->rngInt(60, 420),
                'erf_size_m2'         => in_array($type, ['Apartment'], true) ? null : $this->rngInt(280, 2200),
                'property_type'       => $type,
                'agent_name'          => $this->pick($firstNames = ['Tracy', 'Devon', 'Sandile', 'Marius', 'Kerry']),
                'agency_name'         => $this->pick($agencies),
                'first_seen_at'       => $firstSeen,
                'last_seen_at'        => now()->subDays($this->rngInt(0, 3)),
                'is_active'           => $i % 13 === 0 ? 0 : 1,
                'first_seen_email_date' => $firstSeen->copy()->toDateString(),
                'created_at'          => $firstSeen,
                'updated_at'          => now(),
            ]);

            $this->listingIds[] = $listingId;
            $this->listingMeta[$listingId] = compact('suburb', 'town', 'branchId', 'price', 'beds', 'type')
                + ['trackedId' => null, 'street' => $street, 'streetNo' => $streetNo];

            // Universal Match-or-Create — produces / enriches a tracked_property.
            try {
                $tp = $matcher->matchOrCreate(
                    self::AGENCY_ID,
                    [
                        'street_number' => (string) $streetNo,
                        'street_name'   => $street . ' Drive',
                        'suburb'        => $suburb,
                        'town'          => $town,
                        'province'      => 'KwaZulu-Natal',
                        'property_type' => strtolower($type),
                        'bedrooms'      => $beds ?: null,
                        'bathrooms'     => $beds ? max(1, $beds - 1) : null,
                        'last_known_asking_price' => $price,
                        'address'       => $address,
                    ],
                    ['type' => 'demo_' . $portal, 'ref' => 'DEMO-' . $i, 'payload' => ['seed' => true]],
                    $capturedBy
                );
                DB::table('prospecting_listings')->where('id', $listingId)
                    ->update(['tracked_property_id' => $tp->id]);
                $this->listingMeta[$listingId]['trackedId'] = $tp->id;
                $created++;
            } catch (\Throwable $e) {
                $this->command->warn("    listing #{$listingId} matchOrCreate: " . $e->getMessage());
            }
        }

        $tpCount = DB::table('tracked_properties')->where('agency_id', self::AGENCY_ID)->count();
        $this->command->info("  Stage 2: " . count($this->listingIds)
            . " prospecting listings, {$created} matchOrCreate ok, {$tpCount} tracked_properties");
    }

    private function priceFor(int $beds, string $type): int
    {
        if ($type === 'Vacant Land') {
            return $this->rngInt(45, 180) * 10000;
        }
        if ($type === 'Commercial') {
            return $this->rngInt(180, 450) * 10000;
        }
        return match (true) {
            $beds <= 2 => $this->rngInt(65, 160) * 10000,
            $beds === 3 => $this->rngInt(140, 320) * 10000,
            $beds === 4 => $this->rngInt(260, 480) * 10000,
            default     => $this->rngInt(420, 850) * 10000,
        };
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 3 — claims (chip-rule recipes) + seller-outreach pitches
    // ───────────────────────────────────────────────────────────────────

    private array $chipListingIds = [];

    private function stage3_claimsAndPitches(): void
    {
        $claimSvc = app(ProspectingClaimService::class);
        $pool = $this->listingIds;
        shuffle($pool);
        $cursor = 0;
        $owner = $this->adminId;                    // demo login owns chip claims (so all chips visible on one login)
        $otherAgent = $this->agentIds[0];

        // ----- Deterministic chip-rule recipes (one listing per rule) -----
        // R2 — CLAIM EXPIRES SOON (owner, feedback_at null, hours_left < 6)
        $l = $pool[$cursor++];
        $this->makeClaim($l, $owner, 'claimed', now()->subHours(44), null, null, true);

        // R4 — FOLLOW UP CLAIM (owner, status contacted, feedback set, >7d)
        $l = $pool[$cursor++];
        $this->makeClaim($l, $owner, 'contacted', now()->subDays(9), now()->subDays(9), null, true);

        // R1 — FLAG TO BM (manager view, status listing, >14d, not flagged)
        $l = $pool[$cursor++];
        $this->makeClaim($l, $owner, 'listing', now()->subDays(16), now()->subDays(18), null, true);

        // R8 — RESOLVE COLLEAGUE CLAIM (manager, other user, >21d, not 'listing')
        $l = $pool[$cursor++];
        $this->makeClaim($l, $otherAgent, 'contacted', now()->subDays(25), now()->subDays(25), null, true);

        // R3 — LOG OUTCOME (owner pitched a matched property 5d ago, no claim)
        $r3Listing = $pool[$cursor++];

        // R5 — PITCH NOW · HIGH (no claim/pitch, ≥3 strong matches)
        $r5Listing = $pool[$cursor++];
        // R6 — PITCH NOW (no claim/pitch, 1–2 strong matches)
        $r6Listing = $pool[$cursor++];
        // R9 — INVESTIGATE (no claim/pitch, 0 strong, ≥5 mid matches)
        $r9Listing = $pool[$cursor++];
        $this->chipListingIds = compact('r3Listing', 'r5Listing', 'r6Listing', 'r9Listing');

        // ----- Bulk realistic claims (varied states) -----
        $bulk = 0;
        $states = ['claimed', 'contacted', 'contacted', 'meeting_set', 'listing'];
        for ($k = 0; $k < 28; $k++) {
            $l = $pool[$cursor++] ?? null;
            if (!$l) {
                break;
            }
            $st = $this->pick($states);
            $ageD = $this->rngInt(1, 20);
            $agent = $this->pick($this->agentIds);
            $this->makeClaim($l, $agent, $st, now()->subDays($ageD),
                $st === 'claimed' ? null : now()->subDays($ageD), null, false);
            $bulk++;
        }

        // ----- Seller-outreach pitches (service-constructed) -----
        // composeContext needs a Property + Contact in agency 1. Build a
        // small pool of pitch sellers + pitch properties first.
        $pitchPairs = $this->buildPitchSellerProperties(26);
        $composer = app(SellerOutreachComposerService::class);
        $sender = app(SellerOutreachSenderService::class);
        $pitchOk = 0;
        foreach ($pitchPairs as $idx => $pair) {
            /** @var \App\Models\Contact $c */
            $c = $pair['contact'];
            /** @var \App\Models\Property $p */
            $p = $pair['property'];
            $agentModel = User::find($pair['agentId']);
            $channel = $idx % 4 === 0 ? 'email' : 'whatsapp';
            try {
                $ctx = $composer->composeContext(self::AGENCY_ID, $c, $p, $channel, null, $agentModel);
                if (!$ctx->isSendable()) {
                    continue;
                }
                $send = $sender->send($ctx);
                // Back-date some sends so the timeline looks worked.
                DB::table('seller_outreach_sends')->where('id', $send->id)
                    ->update(['sent_at' => now()->subDays($this->rngInt(0, 40))]);
                $pitchOk++;
            } catch (\Throwable $e) {
                $this->command->warn('    pitch #' . $idx . ': ' . $e->getMessage());
            }
        }

        // R3 pitch: pitch a matched property 5d ago from the owner; link the
        // listing to that property; leave no active claim.
        $r3 = $this->buildPitchSellerProperties(1, $owner)[0];
        try {
            $ctx = $composer->composeContext(self::AGENCY_ID, $r3['contact'], $r3['property'],
                'whatsapp', null, User::find($owner));
            if ($ctx->isSendable()) {
                $send = $sender->send($ctx);
                DB::table('seller_outreach_sends')->where('id', $send->id)->update([
                    'sent_at' => now()->subDays(5),
                    'outcome' => 'sent',
                ]);
                DB::table('prospecting_listings')->where('id', $r3Listing)
                    ->update(['matched_property_id' => $r3['property']->id]);
            }
        } catch (\Throwable $e) {
            $this->command->warn('    R3 pitch: ' . $e->getMessage());
        }

        $this->command->info("  Stage 3: " . ($bulk + 8) . " claims (8 chip recipes), "
            . ($pitchOk + 1) . " seller-outreach pitches");
    }

    private function makeClaim(int $listingId, int $userId, string $status, $claimedAt,
        $feedbackAt, $flaggedAt, bool $isChip): void
    {
        $svc = app(ProspectingClaimService::class);
        try {
            $svc->createTempLock($listingId, $userId, self::AGENCY_ID);
            $claim = $svc->consumeLockAsPermanentClaim($listingId, $userId, self::AGENCY_ID, [
                'sent_at'        => $claimedAt,
                'channel'        => 'whatsapp',
                'recipient_name' => 'seller',
            ]);
            if ($status !== 'contacted') {
                $svc->recordActionOnClaim($claim, $status, "Demo: set to {$status}");
            }
            // Adjust timestamps directly so chip recipes are deterministic.
            DB::table('prospecting_claims')->where('id', $claim->id)->update(array_filter([
                'status'          => $status,
                'claimed_at'      => $claimedAt,
                'last_updated_at' => $claimedAt,
                'feedback_at'     => $feedbackAt,
                'flagged_at'      => $flaggedAt,
            ], fn ($v) => $v !== null) + ['feedback_at' => $feedbackAt, 'flagged_at' => $flaggedAt]);
        } catch (\Throwable $e) {
            $this->command->warn("    claim listing #{$listingId}: " . $e->getMessage());
        }
    }

    /**
     * Build N (seller-contact, agency-stock-property) pairs for pitches.
     * Properties are raw-inserted (bypasses website-sync side effect).
     */
    private function buildPitchSellerProperties(int $n, ?int $forUser = null): array
    {
        $pairs = [];
        for ($i = 0; $i < $n; $i++) {
            $town = $this->pick(array_keys(self::TOWN_SUBURBS));
            $branchId = $this->branchByTown[$town];
            $suburb = $this->pick(self::TOWN_SUBURBS[$town]);
            $agentId = $forUser ?? $this->agentForBranch($branchId);
            $beds = $this->rngInt(2, 5);
            $price = $this->priceFor($beds, 'House');

            $cAt = now()->subDays($this->rngInt(10, 90));
            $sellerId = DB::table('contacts')->insertGetId([
                'agency_id'          => self::AGENCY_ID,
                'branch_id'          => $branchId,
                'created_by_user_id' => $agentId,
                'contact_type_id'    => $this->contactTypeFor(false),
                'first_name'         => '[DEMO] ' . $this->pick(['Pieter', 'Thandi', 'Greg', 'Naledi', 'Riaan', 'Zola']),
                'last_name'          => $this->pick(['Naidoo', 'Coetzee', 'Mthembu', 'Fourie', 'Sibeko']),
                'phone'              => '07' . $this->rngInt(10000000, 99999999),
                'email'              => 'seller' . Str::random(5) . '@example.com',
                'is_buyer'           => 0,
                'messaging_opt_out_at' => null,
                'loaded_at'          => $cAt,
                'modified_at'        => now(),
                'created_at'         => $cAt,
                'updated_at'         => now(),
            ]);
            $pid = DB::table('properties')->insertGetId([
                'external_id'   => (string) Str::uuid(),
                'agency_id'     => self::AGENCY_ID,
                'branch_id'     => $branchId,
                'agent_id'      => $agentId,
                'title'         => "[DEMO] {$beds} Bed House in {$suburb}",
                'address'       => "{$this->rngInt(1, 200)} Coastal Way, {$suburb}",
                'suburb'        => $suburb,
                'city'          => $town,
                'province'      => 'KwaZulu-Natal',
                'property_type' => 'house',
                'category'      => 'Residential',
                'listing_type'  => 'sale',
                'status'        => 'available',
                'price'         => $price,
                'beds'          => $beds,
                'baths'         => max(1, $beds - 1),
                'garages'       => $this->rngInt(1, 2),
                'erf_size_m2'   => $this->rngInt(400, 1600),
                'size_m2'       => $this->rngInt(90, 340),
                'created_at'    => now()->subDays($this->rngInt(5, 60)),
                'updated_at'    => now(),
            ]);
            DB::table('contact_property')->insert([
                'contact_id' => $sellerId, 'property_id' => $pid, 'role' => 'owner',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $pairs[] = [
                'contact'  => \App\Models\Contact::withoutGlobalScopes()->find($sellerId),
                'property' => \App\Models\Property::withoutGlobalScopes()->find($pid),
                'agentId'  => $agentId,
            ];
        }
        return $pairs;
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 4 — ~150 contacts (buyers + sellers) + ~45 wishlists
    // ───────────────────────────────────────────────────────────────────

    private array $buyerIds = [];

    private function stage4_contactsAndWishlists(): void
    {
        $firstNames = ['Thabo', 'Lerato', 'Pieter', 'Anele', 'Michelle', 'Sipho', 'Karen',
            'Bongani', 'Tanya', 'Mandla', 'Jaco', 'Nomsa', 'Grant', 'Fatima', 'Craig',
            'Precious', 'Wayne', 'Zanele', 'Derek', 'Thandiwe', 'Rikus', 'Ayanda', 'Wendy',
            'Tshepo', 'Liezel', 'Siyabonga', 'Chantal', 'Mpho', 'Gerhard', 'Nontobeko'];
        $lastNames = ['Ndlovu', 'van der Merwe', 'Dlamini', 'Botha', 'Mkhize', 'Joubert',
            'Khumalo', 'Pretorius', 'Nkosi', 'Steyn', 'Mahlangu', 'du Plessis', 'Zulu', 'Nel',
            'Sithole', 'Smit', 'Pillay', 'Bezuidenhout', 'Molefe', 'Venter'];
        $states = array_merge(array_fill(0, 38, 'new'), array_fill(0, 52, 'warm'),
            array_fill(0, 34, 'cold'), array_fill(0, 26, 'lost'));
        shuffle($states);

        $buyers = 0;
        $sellers = 0;
        for ($i = 0; $i < 150; $i++) {
            $branchId = $this->pick($this->branchIds);
            $agentId = $this->agentForBranch($branchId);
            $isBuyer = $i % 5 !== 0; // ~80% buyers, ~20% pure sellers
            $state = $isBuyer ? ($states[$i] ?? 'warm') : null;
            $createdAt = now()->subDays($this->rngInt(5, 150));
            $lastActivity = $state === 'lost'
                ? $createdAt->copy()->addDays($this->rngInt(5, 30))
                : now()->subDays($this->rngInt(0, 24));

            $contactId = DB::table('contacts')->insertGetId([
                'agency_id'                 => self::AGENCY_ID,
                'branch_id'                 => $branchId,
                'created_by_user_id'        => $agentId,
                'contact_type_id'           => $this->contactTypeFor($isBuyer),
                'first_name'                => '[DEMO] ' . $firstNames[$i % count($firstNames)],
                'last_name'                 => $lastNames[$i % count($lastNames)],
                'phone'                     => '07' . $this->rngInt(10000000, 99999999),
                'email'                     => strtolower($firstNames[$i % count($firstNames)]) . $i . '@example.com',
                'is_buyer'                  => $isBuyer ? 1 : 0,
                'buyer_state'               => $state,
                'last_activity_at'          => $isBuyer ? $lastActivity : null,
                'buyer_pipeline_entered_at' => $isBuyer ? $createdAt : null,
                'loaded_at'                 => $createdAt,
                'modified_at'               => now(),
                'created_at'                => $createdAt,
                'updated_at'                => now(),
            ]);

            if ($isBuyer) {
                $this->buyerIds[] = $contactId;
                $buyers++;
            } else {
                $sellers++;
            }

            // ~45 wishlists across buyers, varied criteria so matching lights up.
            if ($isBuyer && count($this->wishlistContactIds ?? []) < 45) {
                $this->makeWishlist($contactId, $agentId);
            }

            if ($state === 'lost') {
                $reasons = ['found_elsewhere', 'price_too_high', 'area_not_suitable',
                    'financing_failed', 'changed_mind', 'relocation_cancelled'];
                $rc = $this->pick($reasons);
                DB::table('buyer_lost_records')->insert([
                    'contact_id'                 => $contactId,
                    'agency_id'                  => self::AGENCY_ID,
                    'reason_code'                => $rc,
                    'reason_label'               => ucfirst(str_replace('_', ' ', $rc)),
                    'recorded_by_user_id'        => $agentId,
                    'recorded_at'                => $lastActivity,
                    'source'                     => 'manual',
                    'buyer_state_at_loss'        => 'cold',
                    'days_in_pipeline_at_loss'   => $this->rngInt(14, 90),
                    'agent_owner_user_id_at_loss' => $agentId,
                    'branch_id_at_loss'          => $branchId,
                    'preapproval_amount_at_loss' => $this->rngInt(0, 1) ? $this->rngInt(100, 500) * 10000 : null,
                    'created_at'                 => $lastActivity,
                    'updated_at'                 => $lastActivity,
                ]);
            }
        }

        $this->command->info("  Stage 4: {$buyers} buyers + {$sellers} sellers, "
            . count($this->wishlistContactIds) . ' wishlists');
    }

    private array $wishlistContactIds = [];

    private function makeWishlist(int $contactId, int $agentId): void
    {
        $suburbs = $this->allSuburbs();
        $s1 = $this->pick($suburbs);
        $s2 = $this->pick($suburbs);
        $base = $this->pick([800000, 1200000, 1500000, 2000000, 2500000, 3000000, 4000000]);
        $institutions = ['Standard Bank', 'FNB', 'Nedbank', 'ABSA', 'ooba', 'SA Home Loans'];

        if ($this->rngInt(0, 2) === 0) {
            DB::table('contacts')->where('id', $contactId)->update([
                'preapproval_amount'      => $base + $this->rngInt(0, 800000),
                'preapproval_institution' => $this->pick($institutions),
                'preapproval_expires_at'  => now()->addDays($this->rngInt(15, 90))->toDateString(),
            ]);
        }

        try {
            ContactMatch::withoutGlobalScopes()->create([
                'agency_id'             => self::AGENCY_ID,
                'contact_id'            => $contactId,
                'created_by_user_id'    => $agentId,
                'updated_by_user_id'    => $agentId,
                'status'                => ContactMatch::STATUS_ACTIVE,
                'listing_type'          => 'sale',
                'price_min'             => $base,
                'price_max'             => $base + $this->rngInt(500000, 2500000),
                'beds_min'              => $this->rngInt(1, 3),
                'bedrooms_max'          => $this->rngInt(3, 5),
                'suburbs'               => array_values(array_unique([$s1, $s2])),
                'property_types'        => $this->rngInt(0, 1) ? ['House'] : [],
                'must_have_features'    => $this->rngInt(0, 1) ? ['garden'] : [],
                'nice_to_have_features' => [],
                'deal_breakers'         => [],
            ]);
            $this->wishlistContactIds[] = $contactId;
        } catch (\Throwable $e) {
            $this->command->warn("    wishlist contact #{$contactId}: " . $e->getMessage());
        }
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 5 — promoteToStock → properties at varied lifecycle stages
    // ───────────────────────────────────────────────────────────────────

    private array $promotedPropertyIds = [];

    private function stage5_promoteToStock(): void
    {
        $matcher = app(TrackedPropertyMatchOrCreateService::class);
        // Promote ~55 tracked properties to agency stock (My Listings).
        $tps = DB::table('tracked_properties')
            ->where('agency_id', self::AGENCY_ID)
            ->whereNull('promoted_to_property_id')
            ->orderBy('id')
            ->limit(55)
            ->pluck('id')
            ->toArray();

        $ok = 0;
        foreach ($tps as $idx => $tpId) {
            // Round-robin, NOT random: a random pick per property leaves the
            // distribution to chance, and with 9 agents an agent can easily end
            // up with nothing. Every agent must demo a populated My Listings.
            $agentId = $this->nextAgentRoundRobin();
            $branchId = $this->branchByAgent[$agentId];

            // Intended status decided BEFORE any risky call (FIX 4) so a later
            // failure can never leave the property at promoteToStock's 'draft'.
            $status = match (true) {
                $idx % 9 === 0 => 'sold',
                $idx % 5 === 0 => 'draft',
                default        => 'available',
            };
            $listedDays = $this->rngInt(8, 180);

            try {
                $property = $matcher->promoteToStock($tpId, $agentId, [
                    'branch_id' => $branchId,
                ]);
            } catch (\Throwable $e) {
                // promoteToStock is transactional — a throw rolls back the
                // Property create, so no orphan 'draft' property is left.
                $this->command->warn("    promote tp #{$tpId}: " . $e->getMessage());
                continue;
            }

            // Property exists now. Guarantee it reaches its intended status
            // even if the enrichment update fails (FIX 4: log loud + compensate,
            // never silently swallow leaving status='draft').
            try {
                DB::table('properties')->where('id', $property->id)->update([
                    'status'       => $status,
                    'title'        => '[DEMO] ' . $property->title,
                    'listing_type' => 'sale',
                    'category'     => 'Residential',
                    'published_at' => $status === 'draft' ? null : now()->subDays($listedDays),
                    'listed_date'  => $status === 'draft' ? null : now()->subDays($listedDays)->toDateString(),
                    'mandate_type' => $this->pick(['Sole', 'Open', 'Dual']),
                ]);
            } catch (\Throwable $e) {
                $this->command->error("    promote tp #{$tpId}: status enrichment FAILED ("
                    . $e->getMessage() . ") — compensating to '{$status}'");
                DB::table('properties')->where('id', $property->id)->update(['status' => $status]);
            }

            $this->promotedPropertyIds[] = $property->id;

            // FIX 2: PropertyController::store() requires >=1 linked contact.
            // Every promoted property gets an owner contact, like pitch/spine.
            $this->createOwnerContact($property->id, $branchId, $agentId);

            if ($status === 'sold') {
                DB::table('property_sold_records')->insert([
                    'property_id'   => $property->id,
                    'agency_id'     => self::AGENCY_ID,
                    'sold_price'    => (int) ($property->price * ($this->rngInt(90, 103) / 100)),
                    'sold_date'     => now()->subDays($this->rngInt(5, 70))->toDateString(),
                    'days_on_market' => $listedDays,
                    'source'        => 'manual',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }

            // Marketing activities for active properties (~60%) [origin/Staging UNION]
            if ($status === 'available' && rand(0, 2) > 0) {
                $activities = ['portal_listed', 'photos_refreshed', 'price_adjusted', 'show_day_held', 'social_share'];
                for ($a = 0; $a < rand(1, 3); $a++) {
                    DB::table('property_marketing_activities')->insert([
                        'property_id'       => $property->id,
                        'agency_id'         => self::AGENCY_ID,
                        'activity_type'     => $activities[array_rand($activities)],
                        'occurred_at'       => now()->subDays(rand(1, 60)),
                        'logged_by_user_id' => $agentId,
                        'internal_only'     => false,
                        'created_at'        => now(),
                    ]);
                }
            }

            $ok++;
        }

        // A handful of standalone demo properties (not promoted) for variety
        // + to give buyer-activity something to point at.
        $this->seedExtraDemoProperties(14);

        // Promotion is best-effort: promoteToStock() can legitimately decline a
        // tracked property (dedupe/match), so round-robin alone does not
        // GUARANTEE the floor. Count what actually landed and top up.
        $this->stage5b_guaranteeMinPerAgent();

        $total = DB::table('properties')->where('agency_id', self::AGENCY_ID)->count();
        $this->command->info("  Stage 5: {$ok} promoted to stock, {$total} properties total");
    }

    /**
     * Hard floor: every agent owns >= MIN_PROPERTIES_PER_AGENT properties.
     *
     * Reads the REAL post-stage counts rather than assuming the loops above
     * worked, then seeds the exact shortfall per agent. Idempotent and safe to
     * re-run. stageV_verifyDemoIntegrity() asserts the floor held.
     */
    private function stage5b_guaranteeMinPerAgent(): void
    {
        $topUp = 0;

        foreach ($this->agentIds as $agentId) {
            $have = DB::table('properties')
                ->where('agency_id', self::AGENCY_ID)
                ->where('agent_id', $agentId)
                ->whereNull('deleted_at')
                ->count();

            $short = self::MIN_PROPERTIES_PER_AGENT - $have;
            if ($short <= 0) {
                continue;
            }

            $this->seedExtraDemoProperties($short, $agentId);
            $topUp += $short;
        }

        if ($topUp > 0) {
            $this->command->info("  Stage 5b: topped up {$topUp} propert"
                . ($topUp === 1 ? 'y' : 'ies') . ' to hold the >='
                . self::MIN_PROPERTIES_PER_AGENT . '/agent floor');
        }
    }

    /**
     * Even, deterministic spread of property ownership across all agents.
     */
    private function nextAgentRoundRobin(): int
    {
        $agentId = $this->agentIds[$this->agentCursor % count($this->agentIds)];
        $this->agentCursor++;

        return $agentId;
    }

    /**
     * @param int|null $forceAgentId When given, every property is assigned to
     *                               this agent (and their branch) instead of a
     *                               town-derived one — used by the per-agent
     *                               inventory top-up.
     */
    private function seedExtraDemoProperties(int $n, ?int $forceAgentId = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            if ($forceAgentId !== null) {
                $agentId = $forceAgentId;
                $branchId = $this->branchByAgent[$agentId];
                $town = array_search($branchId, $this->branchByTown, true)
                    ?: $this->pick(array_keys(self::TOWN_SUBURBS));
            } else {
                $town = $this->pick(array_keys(self::TOWN_SUBURBS));
                $branchId = $this->branchByTown[$town];
                $agentId = $this->agentForBranch($branchId);
            }
            $suburb = $this->pick(self::TOWN_SUBURBS[$town]);
            $beds = $this->rngInt(2, 5);
            $price = $this->priceFor($beds, 'House');
            $pid = DB::table('properties')->insertGetId([
                'external_id'   => (string) Str::uuid(),
                'agency_id'     => self::AGENCY_ID,
                'branch_id'     => $branchId,
                'agent_id'      => $agentId,
                'title'         => "[DEMO] {$beds} Bed Family Home in {$suburb}",
                'address'       => "{$this->rngInt(1, 240)} {$this->pick(['Ridge', 'Crest', 'Bay', 'Cove'])} Road, {$suburb}",
                'suburb'        => $suburb,
                'city'          => $town,
                'province'      => 'KwaZulu-Natal',
                'property_type' => 'house',
                'category'      => 'Residential',
                'listing_type'  => 'sale',
                'status'        => 'available',
                'price'         => $price,
                'beds'          => $beds,
                'baths'         => max(1, $beds - 1),
                'garages'       => $this->rngInt(1, 3),
                'erf_size_m2'   => $this->rngInt(420, 1900),
                'size_m2'       => $this->rngInt(95, 360),
                'published_at'  => now()->subDays($this->rngInt(10, 120)),
                'listed_date'   => now()->subDays($this->rngInt(10, 120))->toDateString(),
                'mandate_type'  => $this->pick(['Sole', 'Open', 'Dual']),
                'created_at'    => now()->subDays($this->rngInt(15, 130)),
                'updated_at'    => now(),
            ]);
            $this->promotedPropertyIds[] = $pid;
            // FIX 2: every property must have >=1 linked contact (owner).
            $this->createOwnerContact($pid, $branchId, $agentId);
        }
    }

    /**
     * Create a seller/owner Contact (fully populated — contact_type_id,
     * loaded_at, modified_at) and link it to the property via the
     * contact_property pivot with role='owner'. Mirrors the pitch-seller
     * pattern so seeded properties honour PropertyController's invariant
     * that every property has at least one linked contact.
     */
    private function createOwnerContact(int $propertyId, int $branchId, int $agentId): int
    {
        $cAt = now()->subDays($this->rngInt(20, 120));
        $cid = DB::table('contacts')->insertGetId([
            'agency_id'          => self::AGENCY_ID,
            'branch_id'          => $branchId,
            'created_by_user_id' => $agentId,
            'contact_type_id'    => $this->contactTypeFor(false),
            'first_name'         => '[DEMO] ' . $this->pick(['Owner', 'Marius', 'Thuli', 'Estelle', 'Vusi', 'Hennie']),
            'last_name'          => $this->pick(['Owner', 'Naidoo', 'Coetzee', 'Mthembu', 'Fourie', 'Sibeko']),
            'phone'              => '07' . $this->rngInt(10000000, 99999999),
            'email'              => 'owner' . Str::random(6) . '@example.com',
            'is_buyer'           => 0,
            'loaded_at'          => $cAt,
            'modified_at'        => now(),
            'created_at'         => $cAt,
            'updated_at'         => now(),
        ]);
        DB::table('contact_property')->insertOrIgnore([
            'contact_id' => $cid, 'property_id' => $propertyId, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $cid;
    }

    /**
     * STAGE 6b — link every buyer to its top matched properties (role=buyer)
     * so "Properties N" on a buyer is non-zero and coherent. Uses the
     * property_buyer_matches Stage 6 produced; falls back to a property in
     * the buyer's branch so EVERY is_buyer contact gets >=1 link.
     */
    private function stage6b_linkBuyerProperties(): void
    {
        $buyers = DB::table('contacts')
            ->where('agency_id', self::AGENCY_ID)
            ->where('is_buyer', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'branch_id']);

        $linked = 0;
        $fallbacks = 0;
        $views = 0;
        foreach ($buyers as $b) {
            $propIds = DB::table('property_buyer_matches')
                ->where('contact_id', $b->id)
                ->orderByDesc('score')
                ->limit(3)
                ->pluck('property_id')
                ->all();

            if (empty($propIds)) {
                // Fallback: a property in the buyer's branch (else any agency stock).
                $fp = DB::table('properties')->where('agency_id', self::AGENCY_ID)
                    ->where('branch_id', $b->branch_id)->whereNull('deleted_at')
                    ->orderBy('id')->value('id')
                    ?? DB::table('properties')->where('agency_id', self::AGENCY_ID)
                        ->whereNull('deleted_at')->orderBy('id')->value('id');
                if ($fp) {
                    $propIds = [$fp];
                    $fallbacks++;
                }
            }

            foreach ($propIds as $pid) {
                DB::table('contact_property')->insertOrIgnore([
                    'contact_id' => $b->id, 'property_id' => $pid, 'role' => 'buyer',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                // Buyer Pipeline cards render buyerPropertyViews()->count().
                // Seed a view per matched property so the count is non-zero
                // and realistic (these $propIds are the buyer's in-area /
                // price-appropriate matches from property_buyer_matches).
                // Deterministic fields (no rand) + updateOrInsert keyed on
                // (contact_id, property_id) => fully idempotent on re-run.
                DB::table('buyer_property_views')->updateOrInsert(
                    ['contact_id' => $b->id, 'property_id' => $pid],
                    [
                        'agency_id'      => self::AGENCY_ID,
                        'view_count'     => ($pid % 4) + 1,
                        'last_viewed_at' => now()->subDays(($pid % 14) + 1),
                        'updated_at'     => now(),
                        'created_at'     => now(),
                    ]
                );
                $views++;
            }
            if (!empty($propIds)) {
                $linked++;
            }
        }

        $this->command->info("  Stage 6b: {$linked} buyers linked to matched properties "
            . "({$fallbacks} via branch fallback); {$views} buyer property views seeded");
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 6 — buyer-match recompute + chip-rule match rows
    // ───────────────────────────────────────────────────────────────────

    /**
     * Every demo listing gets a real gallery. A property with no photo is the one
     * thing a prospect notices immediately — an empty grid reads as a broken system,
     * not an unfinished dataset.
     *
     * ── WHERE THE FILES GO ──
     *
     * `properties/{id}/{n}.jpg` on the public disk — the CANONICAL path, not a demo
     * sandbox of its own. PropertyImageGuard::belongsToProperty() hard-requires the
     * prefix `properties/{id}/`, and isPersistable() enforces it on every gallery
     * save. Park demo photos anywhere else and they look fine until an agent opens
     * the gallery editor in a walkthrough, saves, and every image is silently
     * rejected. Demo images are first-class or they are a trap.
     *
     * NOTE (shared storage): the demo DB is its own connection, but `storage/app/
     * public` is NOT — on a dev laptop it is the same directory the main dev app
     * uses. Running demo:seed there overwrites `properties/{id}/` for the IDs the
     * demo happens to use. That is acceptable (dev image data is disposable, and the
     * documented local flow is `migrate:fresh && demo:seed`) but it is worth knowing
     * before you go looking for a photo that "vanished". On the demo host the
     * storage directory is dedicated, so nothing else can be touched.
     *
     * ── WHY A POOL ──
     *
     * 108 listings × 5 photos is 540 files. Committing a unique photo per slot would
     * put hundreds of MB in git for a dataset that is regenerated every three days.
     * Instead: 30 licensed photos (Unsplash, commercial use, no attribution) in
     * `database/seeders/data/demo-properties/`, dealt out so each listing gets its
     * own run of the pool. Committed rather than downloaded at seed time, so
     * `demo:seed` still works on a box with no internet and does not rot the day a
     * third-party URL stops resolving.
     *
     * The offset is derived from the property id (not mt_rand), so a listing keeps
     * the same gallery across reseeds and neighbouring listings never look alike.
     */
    private function stage5b_propertyPhotos(): void
    {
        $pool = glob(database_path('seeders/data/demo-properties/*.jpg')) ?: [];
        sort($pool); // glob order is filesystem-dependent; the deal-out must not be.

        if ($pool === []) {
            // Fail loudly. A silent skip here ships a demo full of grey placeholders
            // and nobody finds out until a customer is looking at it.
            throw new \RuntimeException(
                'Demo photo pool is empty (database/seeders/data/demo-properties/*.jpg). '
                . 'The demo cannot be seeded without listing images.'
            );
        }

        $poolCount = count($pool);
        $perProperty = min(self::PHOTOS_PER_PROPERTY, $poolCount);
        $disk = Storage::disk('public');

        $ids = DB::table('properties')->orderBy('id')->pluck('id');
        $written = 0;

        foreach ($ids as $propertyId) {
            // Deterministic, id-derived, and coprime-ish with the pool size so
            // consecutive listings start at different points in the pool.
            $offset = ($propertyId * 7) % $poolCount;
            $urls = [];

            foreach (range(0, $perProperty - 1) as $slot) {
                $source = $pool[($offset + $slot) % $poolCount];
                $relative = "properties/{$propertyId}/" . ($slot + 1) . '.jpg';

                $disk->put($relative, file_get_contents($source));
                $urls[] = $disk->url($relative);
                $written++;
            }

            // images_json AND gallery_images_json hold the SAME ordered set — see
            // Property::allImages(). The internal UI reads the gallery; the public
            // website, mobile API and portal readiness read images_json. Writing only
            // one leaves half the product looking empty.
            DB::table('properties')->where('id', $propertyId)->update([
                'images_json'         => json_encode($urls),
                'gallery_images_json' => json_encode($urls),
                'updated_at'          => now(),
            ]);
        }

        $this->command->info("  Photos: {$written} files across {$ids->count()} listings ({$poolCount} in pool).");
    }

    private function stage6_buyerMatchRecompute(): void
    {
        // Service-driven recompute for every buyer with an active wishlist —
        // prospecting_buyer_matches AND property_buyer_matches.
        \Artisan::call('prospecting:recompute-matches');
        \Artisan::call('matches:recompute');

        // Deterministic chip-rule match rows (direct insert — tier enum is
        // perfect|strong|approximate; score≥50 only).
        $buyers = array_slice($this->buyerIds, 0, 10);
        $insertMatches = function (int $listingId, int $count, int $minScore, int $maxScore, string $tier) use ($buyers) {
            foreach (array_slice($buyers, 0, $count) as $k => $cid) {
                DB::table('prospecting_buyer_matches')->updateOrInsert(
                    ['prospecting_listing_id' => $listingId, 'contact_id' => $cid],
                    [
                        'agency_id'        => self::AGENCY_ID,
                        'score'            => $this->rngInt($minScore, $maxScore),
                        'tier'             => $tier,
                        'matched_features' => json_encode(['breakdown' => ['price' => 22, 'area' => 18]]),
                        'missing_features' => json_encode([]),
                        'matched_at'       => now(),
                        'last_recompute_at' => now(),
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]
                );
            }
        };

        if (!empty($this->chipListingIds)) {
            // R5 — ≥3 strong (score ≥ 80)
            $insertMatches($this->chipListingIds['r5Listing'], 4, 84, 95, 'strong');
            // R6 — 1–2 strong
            $insertMatches($this->chipListingIds['r6Listing'], 2, 82, 90, 'strong');
            // R9 — 0 strong, ≥5 mid (50–79)
            $insertMatches($this->chipListingIds['r9Listing'], 6, 55, 74, 'approximate');
            // R3 listing → ≥1 strong so it surfaces well
            $insertMatches($this->chipListingIds['r3Listing'], 3, 80, 92, 'strong');
        }

        $pbm = DB::table('prospecting_buyer_matches')->where('agency_id', self::AGENCY_ID)->count();
        $bm = DB::table('property_buyer_matches')->where('agency_id', self::AGENCY_ID)->count();
        $this->command->info("  Stage 6: {$pbm} prospecting_buyer_matches, {$bm} property_buyer_matches");
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 7 — presentations (draft/finalized) + compiled versions
    // ───────────────────────────────────────────────────────────────────

    private function stage7_presentations(): void
    {
        $compiler = new PresentationCompilerService();
        $props = DB::table('properties')->where('agency_id', self::AGENCY_ID)
            ->orderBy('id')->limit(30)->get();
        $ok = 0;
        $compiled = 0;
        foreach ($props as $idx => $p) {
            $finalize = $idx % 3 === 0;
            $userId = $this->agentForBranch($p->branch_id ?? $this->branchIds[0]);
            try {
                $pres = Presentation::create([
                    'agency_id'          => self::AGENCY_ID,
                    'branch_id'          => $p->branch_id ?? $this->branchIds[0],
                    'created_by_user_id' => $userId,
                    'listing_id'         => null, // keeps PresentationObserver a no-op
                    'title'              => 'Listing Presentation — ' . $p->suburb,
                    'property_address'   => $p->address,
                    'suburb'             => $p->suburb,
                    'property_type'      => 'house',
                    'bedrooms'           => $p->beds,
                    'bathrooms'          => $p->baths,
                    'asking_price_inc'   => $p->price,
                    'seller_name'        => 'Demo Seller',
                    'status'             => $finalize ? 'finalized' : 'draft',
                    'currency'           => 'ZAR',
                ]);
                $ok++;
                $compiler->compile($pres->id, $userId);
                $compiled++;
            } catch (\Throwable $e) {
                $this->command->warn('    presentation prop #' . $p->id . ': ' . $e->getMessage());
            }
        }
        $this->command->info("  Stage 7: {$ok} presentations, {$compiled} compiled versions");
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 8 — FICA submissions across the pipeline
    // ───────────────────────────────────────────────────────────────────

    private function stage8_fica(): void
    {
        // FICA CO-review/approve pages gate on User::isComplianceOfficer(),
        // which requires an ACTIVE fica_officer_appointments row. The seeder
        // that creates the primary CO (HfcRmcpMasterSeeder) is NOT in the
        // demo chain, so without this NOBODY on the demo can open
        // compliance/fica/{id}/compliance-review (403). Appoint the Demo
        // Administrator (the demo login) as primary FICA Compliance Officer
        // so the agent-verify → CO-review → approve pipeline works end to
        // end on the demo. Idempotent: skip if that active appointment
        // already exists (the model auto-ends a prior primary on create —
        // the guard prevents re-run churn). Gate stays appointment-based;
        // agents/viewers without an appointment still cannot CO-review.
        $adminUser = DB::table('users')->where('id', $this->adminId)->first();
        if ($adminUser) {
            $alreadyCo = \App\Models\Compliance\FicaOfficerAppointment::withoutGlobalScopes()
                ->where('agency_id', self::AGENCY_ID)
                ->where('role', \App\Models\Compliance\FicaOfficerAppointment::ROLE_PRIMARY)
                ->where('user_id', $this->adminId)
                ->whereNull('ended_on')
                ->exists();
            if (! $alreadyCo) {
                \App\Models\Compliance\FicaOfficerAppointment::withoutGlobalScopes()->create([
                    'agency_id'    => self::AGENCY_ID,
                    'role'         => \App\Models\Compliance\FicaOfficerAppointment::ROLE_PRIMARY,
                    'user_id'      => $this->adminId,
                    'full_name'    => $adminUser->name,
                    'id_number'    => '8001015009087',
                    'email'        => $adminUser->email,
                    'title'        => 'FICA Compliance Officer',
                    'appointed_on' => '2026-03-01',
                    'notes'        => 'Demo seed — Demo Administrator appointed primary FICA Compliance Officer so the FICA CO-review pipeline is usable on the demo.',
                ]);
            }
        }

        $contactIds = DB::table('contacts')->where('agency_id', self::AGENCY_ID)
            ->orderBy('id')->limit(20)->pluck('id')->toArray();
        $stages = ['draft', 'submitted', 'under_review', 'agent_approved', 'approved'];
        $made = 0;
        foreach ($contactIds as $i => $cid) {
            $target = $stages[$i % count($stages)];
            try {
                $sub = FicaSubmission::create([
                    'agency_id'    => self::AGENCY_ID,
                    'requested_by' => $this->adminId,
                    'contact_id'   => $cid,
                    'entity_type'  => 'natural',
                    'status'       => 'draft',
                    'created_at'   => now()->subDays($this->rngInt(2, 40)),
                ]);
                if (in_array($target, ['submitted', 'under_review', 'agent_approved', 'approved'], true)) {
                    $sub->update(['status' => 'submitted', 'signed_at' => now()->subDays($this->rngInt(1, 20))]);
                }
                if ($target === 'under_review') {
                    $sub->update(['status' => 'under_review']);
                }
                if (in_array($target, ['agent_approved', 'approved'], true)) {
                    $sub->update([
                        'status'              => 'agent_approved',
                        'risk_rating'         => $this->rngInt(1, 3),
                        'verification_method' => ['method' => 'in_person'],
                        'agent_verified_by'   => $this->pick($this->agentIds),
                        'agent_verified_at'   => now()->subDays($this->rngInt(1, 10)),
                    ]);
                }
                if ($target === 'approved') {
                    $sub->update([
                        'status'         => 'approved',
                        'verified_by'    => $this->adminId,
                        'verified_at'    => now()->subDays($this->rngInt(0, 5)),
                        'fica_expires_at' => now()->addMonths(24)->toDateString(),
                        'co_verified_by' => $this->adminId,
                        'co_verified_at' => now()->subDays($this->rngInt(0, 5)),
                    ]);
                }
                $made++;
            } catch (\Throwable $e) {
                $this->command->warn('    fica contact #' . $cid . ': ' . $e->getMessage());
            }
        }
        $this->command->info("  Stage 8: {$made} FICA submissions across the pipeline");
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 9 — e-sign documents + signature requests (no real mail)
    // ───────────────────────────────────────────────────────────────────

    private function stage9_esign(): void
    {
        // Minimal docuperfect_template so Document.template_id resolves.
        $templateId = DB::table('docuperfect_templates')->value('id');
        if (!$templateId) {
            $templateId = DB::table('docuperfect_templates')->insertGetId([
                'name'       => '[DEMO] Sale Agreement',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $sigService = app(SignatureService::class);
        $props = DB::table('properties')->where('agency_id', self::AGENCY_ID)
            ->orderBy('id')->limit(15)->get();
        $ok = 0;
        foreach ($props as $idx => $p) {
            $owner = User::find($this->agentForBranch($p->branch_id ?? $this->branchIds[0]));
            try {
                $doc = Document::create([
                    'name'             => '[DEMO] Sale Agreement — ' . $p->suburb,
                    'template_id'      => $templateId,
                    'owner_id'         => $owner->id,
                    'branch_id'        => $p->branch_id,
                    'document_type'    => 'sale_agreement',
                    'property_address' => $p->address,
                    'property_id'      => $p->id,
                    'fields_json'      => [],
                ]);
                $tpl = $sigService->createTemplate($doc, $owner);
                $req = $sigService->createSigningRequest(
                    $tpl, 'seller', 'Demo Seller', 'seller.sign' . $idx . '@example.com',
                    null, 'Please sign the sale agreement.', $owner, false
                );
                // Simulate progression WITHOUT sending mail (never call sendSigningRequest()).
                $state = $idx % 3;
                if ($state === 0) {
                    $req->update(['status' => 'completed', 'sent_at' => now()->subDays(6),
                        'completed_at' => now()->subDays($this->rngInt(1, 4))]);
                    $tpl->update(['status' => 'completed', 'completed_at' => now()->subDays(1)]);
                } elseif ($state === 1) {
                    $req->update(['status' => 'pending', 'sent_at' => now()->subDays($this->rngInt(1, 5))]);
                }
                // state 2 → left 'waiting' (drafted, not yet sent)
                $ok++;
            } catch (\Throwable $e) {
                $this->command->warn('    esign prop #' . $p->id . ': ' . $e->getMessage());
            }
        }
        $this->command->info("  Stage 9: {$ok} e-sign documents + signature requests (no mail sent)");
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 10 — OTP rows (verified, for the client-auth flow)
    // ───────────────────────────────────────────────────────────────────

    private function stage10_otp(): void
    {
        $emails = DB::table('contacts')->where('agency_id', self::AGENCY_ID)
            ->whereNotNull('email')->orderBy('id')->limit(12)->pluck('email')->toArray();
        $made = 0;
        foreach ($emails as $i => $email) {
            $code = str_pad((string) $this->rngInt(0, 999999), 6, '0', STR_PAD_LEFT);
            $verified = $i % 3 !== 0;
            DB::table('client_otps')->insert([
                'email'      => $email,
                'purpose'    => $i % 4 === 0 ? 'recovery' : 'activation',
                'code_hash'  => Hash::make($code),
                'expires_at' => $verified ? now()->subMinutes($this->rngInt(2, 20)) : now()->addMinutes(8),
                'used_at'    => $verified ? now()->subMinutes($this->rngInt(1, 15)) : null,
                'attempts'   => $verified ? 1 : 0,
                'created_at' => now()->subMinutes($this->rngInt(20, 120)),
                'updated_at' => now(),
            ]);
            $made++;
        }
        $this->command->info("  Stage 10: {$made} OTP rows (mix verified / pending)");
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 10b — buyer responses + activity log [origin/Staging UNION]
    // ───────────────────────────────────────────────────────────────────

    private function stage10b_buyerActivity(): void
    {
        $demoContactIds = DB::table('contacts')
            ->where('agency_id', self::AGENCY_ID)
            ->where('first_name', 'like', '[DEMO]%')
            ->where('is_buyer', 1)
            ->pluck('id')->toArray();

        $propertyIds = DB::table('properties')
            ->where('agency_id', self::AGENCY_ID)
            ->where('status', 'available')
            ->pluck('id')->toArray();

        if (empty($demoContactIds) || empty($propertyIds)) return;

        $count = 0;
        foreach (array_slice($demoContactIds, 0, 20) as $contactId) {
            for ($r = 0; $r < rand(1, 3); $r++) {
                $propId = $propertyIds[array_rand($propertyIds)];
                DB::table('buyer_property_responses')->insertOrIgnore([
                    'contact_id'   => $contactId,
                    'agency_id'    => self::AGENCY_ID,
                    'property_id'  => $propId,
                    'response'     => ['interested', 'interested', 'viewing_requested', 'not_interested'][rand(0, 3)],
                    'source'       => 'buyer_portal',
                    'responded_at' => now()->subDays(rand(0, 14)),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                $count++;
            }

            // Pick any random agent. The old form was
            //     agentForBranch(array_rand($this->branchByTown))
            // which passes a KEY of branchByTown — a TOWN NAME (string) — into
            // agentForBranch(int $branchId), a fatal TypeError. It never fired
            // because the seeder had never run to completion. $this->agentIds is
            // the flat list the stale comment claimed did not exist.
            $randomAgentId = $this->pick($this->agentIds);

            DB::table('buyer_activity_log')->insert([
                'contact_id'        => $contactId,
                'agency_id'         => self::AGENCY_ID,
                'activity_type'     => ['viewing_completed', 'call_logged', 'email_sent', 'note_added', 'manual'][rand(0, 4)],
                'activity_date'     => now()->subDays(rand(0, 30)),
                'logged_by_user_id' => $randomAgentId,
                'created_at'        => now(),
            ]);
        }
        $this->command->info("  Stage 10b: {$count} buyer responses + activity rows");
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 11 — Deal Register v2 + step progression (~10 → registered)
    // ───────────────────────────────────────────────────────────────────

    private function stage11_deals(): void
    {
        $bondTpl = DB::table('deal_pipeline_templates')
            ->where('deal_type', 'bond')->where('is_default', 1)->value('id')
            ?? DB::table('deal_pipeline_templates')->where('deal_type', 'bond')->value('id');
        $cashTpl = DB::table('deal_pipeline_templates')->where('deal_type', 'cash')->value('id');
        if (!$bondTpl) {
            $this->command->warn('    Stage 11 skipped: no bond pipeline template');
            return;
        }

        $svc = app(DealPipelineService::class);
        $props = DB::table('properties')->where('agency_id', self::AGENCY_ID)
            ->orderBy('id')->limit(40)->get();
        $buyers = $this->buyerIds;
        $sellersPool = DB::table('contacts')->where('agency_id', self::AGENCY_ID)
            ->where('is_buyer', 0)->pluck('id')->toArray();

        $made = 0;
        $registered = 0;
        foreach ($props as $idx => $p) {
            $branchId = $p->branch_id ?? $this->branchIds[0];
            $agent = $this->agentForBranch($branchId);
            $agent2 = $this->pick($this->agentIds);
            $buyer = $buyers[$idx % max(1, count($buyers))] ?? null;
            $seller = $sellersPool[$idx % max(1, count($sellersPool))] ?? null;
            $type = $idx % 4 === 0 ? 'cash' : 'bond';
            $tpl = $type === 'cash' && $cashTpl ? $cashTpl : $bondTpl;
            $type = $tpl === $cashTpl ? 'cash' : 'bond';
            $price = (int) $p->price ?: 1500000;
            $comm = (int) round($price * 0.06);

            try {
                $deal = $svc->createDeal([
                    'deal_type'            => $type,
                    'property_id'          => $p->id,
                    'listing_agent_id'     => $agent,
                    'selling_agent_id'     => $agent2,
                    'pipeline_template_id' => $tpl,
                    'purchase_price'       => $price,
                    'commission_percentage' => 6.0,
                    'commission_amount'    => $comm,
                    'commission_vat'       => (int) round($comm * 0.15),
                    'offer_date'           => now()->subDays($this->rngInt(20, 160))->toDateString(),
                    'branch_id'            => $branchId,
                    'created_by_id'        => $agent,
                    'contacts'             => array_values(array_filter([
                        $buyer ? ['contact_id' => $buyer, 'role' => 'buyer'] : null,
                        $seller ? ['contact_id' => $seller, 'role' => 'seller'] : null,
                    ])),
                    'agents'               => [
                        ['side' => 'listing', 'user_id' => $agent],
                        ['side' => 'selling', 'user_id' => $agent2],
                    ],
                ]);
                $made++;

                // How far along: 0=just created, 1=mid, 2=registered.
                $progress = $idx % 4 === 0 ? 2 : ($idx % 2 === 0 ? 1 : 0);
                if ($progress >= 1) {
                    $this->driveDeal($svc, $deal, User::find($agent),
                        $progress === 2 ? 'registered' : 'mid');
                    if ($progress === 2) {
                        $registered++;
                    }
                }
            } catch (\Throwable $e) {
                $this->command->warn('    deal prop #' . $p->id . ': ' . $e->getMessage());
            }
        }
        $this->command->info("  Stage 11: {$made} deals ({$registered} driven to registered)");

        $this->stage11b_dealRegister();
    }

    /**
     * STAGE 11b — the deal register the demo actually SHOWS.
     *
     * Stage 11 above seeds deals_v2, which AT-219 retired: its register now
     * redirects away. Both live registers — DR1 (/admin/deals) and the new DR2 —
     * read the `deals` table, and nothing in this seeder ever wrote to it. The
     * demo's Deal Register therefore rendered EMPTY while 40 deals sat in a dead
     * prototype. (stageV caught exactly this and it was briefly mistaken for a
     * false alarm — it was not.)
     *
     * Shape mirrors the canonical writer (Admin\DealController::persistDeal and
     * its DR2 twin Dr2\DealRegisterController::persistDeal): a `deals` row plus
     * `deal_user` pivot rows carrying the listing/selling agent split. Both
     * controllers write the same two tables, so the demo does too.
     */
    private function stage11b_dealRegister(): void
    {
        $properties = DB::table('properties')
            ->where('agency_id', self::AGENCY_ID)
            ->whereNull('deleted_at')
            ->whereNotNull('agent_id')
            ->orderBy('id')          // deterministic, like the rest of the seed
            ->limit(30)
            ->get();

        if ($properties->isEmpty()) {
            return;
        }

        $contacts = DB::table('contacts')
            ->where('agency_id', self::AGENCY_ID)
            ->orderBy('id')
            ->limit(80)
            ->get(['first_name', 'last_name']);

        $name = function (int $i) use ($contacts): string {
            if ($contacts->isEmpty()) {
                return '[DEMO] Client';
            }
            $c = $contacts[$i % $contacts->count()];

            return trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
        };

        // deal_type only exists once 2026_07_11_000001 has run (DR2 capture).
        $hasDealType = \Schema::hasColumn('deals', 'deal_type');
        $attorneys = ['Nkosi & Partners Inc', 'Van Wyk Attorneys', 'Coastal Conveyancers',
            'Pillay Naidoo Inc', 'Botha & Steyn Attorneys'];

        $made = 0;

        foreach ($properties as $idx => $p) {
            $listingAgent = (int) $p->agent_id;
            // Selling side is a DIFFERENT agent where possible — a same-agent deal
            // on every row makes the commission split screens look broken.
            $sellingAgent = $this->pick(array_values(array_filter(
                $this->agentIds,
                fn ($a) => $a !== $listingAgent
            )) ?: $this->agentIds);

            $dealDate = now()->subDays($this->rngInt(5, 300));
            $value = (float) ($p->price ?: $this->priceFor(3, 'House'));
            $commission = round($value * 0.06, 2);   // 6% — inside the 5–7.5% SA norm

            // A realistic spread across the register: most accepted, some pending,
            // roughly a third already paid out.
            $accepted = $idx % 7 === 0 ? 'P' : 'A';
            $registered = $accepted === 'A' && $idx % 3 === 0;

            $row = [
                'agency_id'                 => self::AGENCY_ID,
                'branch_id'                 => $p->branch_id,
                'property_id'               => $p->id,
                'deal_no'                   => 1000 + $idx,
                'file_no'                   => 'DEMO-' . str_pad((string) (1000 + $idx), 5, '0', STR_PAD_LEFT),
                'period'                    => $dealDate->format('Y-m'),
                'deal_date'                 => $dealDate->toDateString(),
                'property_address'          => $p->address,
                'seller_name'               => $name($idx),
                'buyer_name'                => $name($idx + 40),
                'attorney_name'             => $this->pick($attorneys),
                'accepted_status'           => $accepted,
                'commission_status'         => $registered ? 'Paid' : 'Not Paid',
                'registration_date'         => $registered ? $dealDate->copy()->addDays(60)->toDateString() : null,
                'property_value'            => $value,
                'sale_price'                => (int) $value,
                'total_commission'          => $commission,
                'listing_split_percent'     => 50.00,
                'selling_split_percent'     => 50.00,
                'listing_our_share_percent' => 100.00,
                'selling_our_share_percent' => 100.00,
                'listing_external'          => 0,
                'selling_external'          => 0,
                'is_demo'                   => 1,
                'created_at'                => $dealDate,
                'updated_at'                => now(),
            ];

            if ($hasDealType) {
                $row['deal_type'] = $this->pick(['bond', 'cash', 'sale_of_2nd']);
            }

            $dealId = DB::table('deals')->insertGetId($row);

            // deal_user — the agent commission split. Each side sums to 100%.
            DB::table('deal_user')->insert([
                [
                    'deal_id'             => $dealId,
                    'user_id'             => $listingAgent,
                    'side'                => 'listing',
                    'agent_split_percent' => 100.00,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ],
                [
                    'deal_id'             => $dealId,
                    'user_id'             => $sellingAgent,
                    'side'                => 'selling',
                    'agent_split_percent' => 100.00,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ],
            ]);

            $made++;
        }

        $this->command->info("  Stage 11b: {$made} deals on the LIVE register (DR1/DR2 `deals` + agent splits)");
    }

    /**
     * Drive a Bond deal along its spine. Routes around the app bug where
     * "Bond Approved" fires status_trigger='granted' (invalid for the
     * deals_v2.status enum) by replicating approveStep's bookkeeping
     * minus the bad status write, then using the service's own
     * activateDownstreamSteps().
     */
    private function driveDeal(DealPipelineService $svc, $deal, User $actor, string $to): void
    {
        $deal->load('stepInstances');
        $byName = fn (string $name) => $deal->stepInstances->firstWhere('name', $name);

        $complete = function (string $name) use ($svc, $byName, $actor, &$deal) {
            $deal->load('stepInstances');
            $step = $deal->stepInstances->firstWhere('name', $name);
            if (!$step || $step->status === 'completed') {
                return;
            }
            if ($step->status === 'not_started') {
                $svc->activateStep($step);
            }
            $svc->completeStep($step, $actor, ['outcome' => 'positive']);
        };

        // OTP Signed → Bond Application Submitted
        $complete('OTP Signed');
        $complete('Bond Application Submitted');

        // Bond Approved: requires_bm_approval + status_trigger='granted'.
        // completeStep leaves it approval_status='pending' (no status write,
        // no downstream). Replicate the SAFE half of approveStep().
        $deal->load('stepInstances');
        $bondApproved = $deal->stepInstances->firstWhere('name', 'Bond Approved');
        if ($bondApproved) {
            if ($bondApproved->status === 'not_started') {
                $svc->activateStep($bondApproved);
            }
            if ($bondApproved->status !== 'completed') {
                $svc->completeStep($bondApproved, $actor, ['outcome' => 'positive']);
            }
            $bondApproved->refresh();
            $bondApproved->update([
                'approval_status' => 'approved',
                'approved_by_id'  => ($this->bmIds[0] ?? $this->adminId),
                'approved_at'     => now(),
                'approval_notes'  => 'Demo: bond grant approved by BM',
            ]);
            // Service-driven downstream activation (no invalid status write).
            $svc->activateDownstreamSteps($bondApproved);
        }

        if ($to === 'mid') {
            return;
        }

        // Continue the spine to Registration. Registration's
        // status_trigger='completed' IS a valid enum value → sets
        // deals_v2.status='completed' + actual_registration via the service.
        foreach (['Attorney Instructed', 'Rates Clearance', 'Deeds Office Lodgement', 'Registration'] as $name) {
            $complete($name);
        }
    }

    // ───────────────────────────────────────────────────────────────────
    //  STAGE 12 — calendar events (recent past + next 3 weeks)
    // ───────────────────────────────────────────────────────────────────

    private function stage12_calendar(): void
    {
        $categories = ['viewing', 'viewing', 'viewing', 'listing_presentation',
            'property_evaluation', 'meeting', 'seller_meeting', 'viewing'];
        $made = 0;
        for ($i = 0; $i < 110; $i++) {
            $agentId = $this->pick($this->agentIds);
            $branchId = DB::table('users')->where('id', $agentId)->value('branch_id');
            $daysOffset = $this->rngInt(-21, 21);
            $hour = $this->rngInt(8, 17);
            $eventDate = now()->addDays($daysOffset)->setTime($hour, 0, 0);
            $cat = $this->pick($categories);
            $status = $daysOffset < -1 ? ($this->rngInt(0, 2) > 0 ? 'completed' : 'pending') : 'pending';

            DB::table('calendar_events')->insert([
                'agency_id'   => self::AGENCY_ID,
                'branch_id'   => $branchId,
                'user_id'     => $agentId,
                'created_by_id' => $agentId,
                'title'       => '[DEMO] ' . ucwords(str_replace('_', ' ', $cat)) . ' — ' . $this->pick($this->allSuburbs()),
                'category'    => $cat,
                'event_type'  => 'manual',
                'source_type' => 'manual:demo',
                'event_date'  => $eventDate,
                'end_date'    => $eventDate->copy()->addHour(),
                'status'      => $status,
                'all_day'     => 0,
                'priority'    => 'normal',
                'created_at'  => $eventDate->copy()->subDays($this->rngInt(1, 7)),
                'updated_at'  => now(),
            ]);
            $made++;
        }
        $this->command->info("  Stage 12: {$made} calendar events (past + next 3 weeks)");
    }

    // ───────────────────────────────────────────────────────────────────
    //  VIEWING FEEDBACK SHOWCASE — one completed multi-property viewing
    //  with per-property feedback, so the buyer "Viewings & Feedback"
    //  tab and the property "Recent Viewings & Feedback" section render
    //  populated on a fresh demo:seed (no manual capture needed).
    //
    //  Writes the EXACT shape CalendarController::storeFeedback() produces
    //  for a per-contact (viewing) event: one calendar_event_feedback row
    //  per property (property_id + contact_id set, feedback_kind=viewing),
    //  matching calendar_event_links (1 buyer_contact + N subject_property),
    //  buyer_property_views, buyer_activity_log + a feedback_captured audit
    //  entry. Fully idempotent — keyed lookups + updateOrInsert.
    // ───────────────────────────────────────────────────────────────────

    private function stageViewingFeedback_demoShowcase(): void
    {
        $buyer = DB::table('contacts')
            ->where('agency_id', self::AGENCY_ID)
            ->where('is_buyer', 1)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first(['id', 'branch_id']);

        if (!$buyer) {
            $this->command->warn('  Viewing feedback showcase: no buyer contact — skipped');
            return;
        }

        // 4 agency-stock properties, preferring the buyer's branch.
        $props = DB::table('properties')
            ->where('agency_id', self::AGENCY_ID)
            ->whereNull('deleted_at')
            ->where('branch_id', $buyer->branch_id)
            ->orderBy('id')->limit(4)->pluck('id')->all();
        if (count($props) < 4) {
            $props = DB::table('properties')
                ->where('agency_id', self::AGENCY_ID)
                ->whereNull('deleted_at')
                ->orderBy('id')->limit(4)->pluck('id')->all();
        }
        if (count($props) < 2) {
            $this->command->warn('  Viewing feedback showcase: <2 stock properties — skipped');
            return;
        }

        $branchId = $buyer->branch_id ?? $this->branchIds[0];
        $agentId  = $this->agentForBranch($branchId) ?: $this->agentIds[0];
        $eventDate = now()->subDays(6)->setTime(10, 0, 0);

        $title = '[DEMO] Multi-Property Viewing — Feedback Showcase';

        // Idempotent event: reuse the stable demo event if it exists.
        $eventId = DB::table('calendar_events')
            ->where('agency_id', self::AGENCY_ID)
            ->where('source_type', 'manual:demo')
            ->where('title', $title)
            ->value('id');

        if (!$eventId) {
            $eventId = DB::table('calendar_events')->insertGetId([
                'agency_id'     => self::AGENCY_ID,
                'branch_id'     => $branchId,
                'user_id'       => $agentId,
                'created_by_id' => $agentId,
                'title'         => $title,
                'category'      => 'viewing',
                'event_type'    => 'manual',
                'source_type'   => 'manual:demo',
                'event_date'    => $eventDate,
                'end_date'      => $eventDate->copy()->addHours(3),
                'status'        => 'completed',
                'all_day'       => 0,
                'priority'      => 'normal',
                'created_at'    => $eventDate->copy()->subDays(2),
                'updated_at'    => now(),
            ]);
        } else {
            DB::table('calendar_events')->where('id', $eventId)
                ->update(['status' => 'completed', 'updated_at' => now()]);
        }

        // Links: 1 buyer_contact + N subject_property (string class names
        // exactly as the read services match on).
        // CAL-3 — calendar_event_links.agency_id is NOT NULL (migration
        // 2026_05_23_080300). updateOrInsert with raw DB::table bypasses
        // BelongsToAgency's creating hook, so agency_id must be supplied
        // in the value array (sibling-class fix per BUILD_STANDARD §6 —
        // the runtime instance was CalendarController::syncEventLinks).
        DB::table('calendar_event_links')->updateOrInsert(
            [
                'calendar_event_id' => $eventId,
                'linkable_type'     => 'App\\Models\\Contact',
                'linkable_id'       => $buyer->id,
                'role'              => 'buyer_contact',
            ],
            [
                'agency_id'          => self::AGENCY_ID,
                'created_by_user_id' => $agentId,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]
        );
        foreach ($props as $pid) {
            DB::table('calendar_event_links')->updateOrInsert(
                [
                    'calendar_event_id' => $eventId,
                    'linkable_type'     => 'App\\Models\\Property',
                    'linkable_id'       => $pid,
                    'role'              => 'subject_property',
                ],
                [
                    'agency_id'          => self::AGENCY_ID,
                    'created_by_user_id' => $agentId,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]
            );
        }

        // Real outcome / concern option ids (global or agency 1).
        $outcomeIds = DB::table('agency_feedback_options')
            ->where('category', 'outcome')->where('is_active', 1)
            ->where(fn ($q) => $q->whereNull('agency_id')->orWhere('agency_id', self::AGENCY_ID))
            ->orderBy('sort_order')->pluck('id')->all();
        $concernIds = DB::table('agency_feedback_options')
            ->where('category', 'concern')->where('is_active', 1)
            ->where(fn ($q) => $q->whereNull('agency_id')->orWhere('agency_id', self::AGENCY_ID))
            ->orderBy('sort_order')->pluck('id')->all();

        $sellerNotes = [
            'Buyer liked the layout and natural light; positive overall.',
            'Felt the asking price was slightly high for the area.',
            'Loved the sea view — wants to bring their partner for a second look.',
            'Garden too small for their needs; otherwise impressed.',
        ];
        $internalNotes = [
            'Strong interest — follow up within 48h.',
            'Price objection — flag to listing agent for seller chat.',
            'Hot lead on this one — schedule second viewing.',
            'Soft no on size — keep on list for similar stock.',
        ];

        $fbRows = 0;
        $bpv = 0;
        foreach (array_values($props) as $i => $pid) {
            $outcomeId = $outcomeIds[$i % max(count($outcomeIds), 1)] ?? null;
            $concerns  = ($i === 1 && !empty($concernIds)) ? [$concernIds[0]] : [];

            DB::table('calendar_event_feedback')->updateOrInsert(
                [
                    'calendar_event_id' => $eventId,
                    'contact_id'        => $buyer->id,
                    'property_id'       => $pid,
                ],
                [
                    'feedback_kind'        => 'viewing',
                    'visibility'           => 'public_to_seller',
                    'outcome_option_id'    => $outcomeId,
                    'concern_option_ids'   => json_encode($concerns),
                    'seller_visible_notes' => $sellerNotes[$i % count($sellerNotes)],
                    'internal_notes'       => $internalNotes[$i % count($internalNotes)],
                    'next_action_notes'    => $i === 0 ? 'Call buyer to gauge offer appetite.' : null,
                    'captured_by_user_id'  => $agentId,
                    'captured_at'          => $eventDate,
                    'agency_id'            => self::AGENCY_ID,
                    'branch_id'            => $branchId,
                    'created_at'           => $eventDate,
                    'updated_at'           => now(),
                ]
            );
            $fbRows++;

            $feedbackId = DB::table('calendar_event_feedback')
                ->where('calendar_event_id', $eventId)
                ->where('contact_id', $buyer->id)
                ->where('property_id', $pid)
                ->value('id');

            // buyer_property_views — same upsert key storeFeedback uses.
            DB::table('buyer_property_views')->updateOrInsert(
                ['contact_id' => $buyer->id, 'property_id' => $pid],
                [
                    'agency_id'      => self::AGENCY_ID,
                    'last_viewed_at' => $eventDate,
                    'view_count'     => 1,
                    'updated_at'     => now(),
                    'created_at'     => now(),
                ]
            );
            $bpv++;

            // buyer_activity_log — timeline tab. Mirrors what
            // CalendarController::storeFeedback() writes for a captured
            // viewing-feedback row. The 'feedback_captured' enum value
            // was added by migration 2026_05_20_000001 (was previously a
            // latent bug — the controller wrote it, the enum lacked it,
            // every save hit SQLSTATE 1265 and rolled back).
            DB::table('buyer_activity_log')->updateOrInsert(
                [
                    'contact_id'          => $buyer->id,
                    'related_event_id'    => $eventId,
                    'related_property_id' => $pid,
                    'activity_type'       => 'feedback_captured',
                ],
                [
                    'agency_id'           => self::AGENCY_ID,
                    'activity_date'       => $eventDate,
                    'related_feedback_id' => $feedbackId,
                    'metadata'            => json_encode([
                        'event_title' => $title,
                        'outcome_id'  => $outcomeId,
                        'captured_by' => 'Demo Agent',
                    ]),
                    'logged_by_user_id'   => $agentId,
                ]
            );
        }

        // feedback_captured audit entry (mirrors storeFeedback).
        \App\Models\CommandCenter\CalendarEventAuditEntry::updateOrCreate(
            ['calendar_event_id' => $eventId, 'action' => 'feedback_captured'],
            [
                'new_values'           => ['contact_count' => $fbRows],
                'performed_by_user_id' => $agentId,
                'performed_at'         => $eventDate,
            ]
        );

        // Contact pillar: bump last_activity_at (coherent with the viewing).
        DB::table('contacts')->where('id', $buyer->id)
            ->update(['last_activity_at' => $eventDate]);

        $this->command->info("  Viewing feedback showcase: 1 completed multi-property viewing"
            . " (contact #{$buyer->id}, {$fbRows} per-property feedback rows, {$bpv} buyer_property_views)");
    }

    // ───────────────────────────────────────────────────────────────────
    //  SPINE — 12 properties threading the FULL lifecycle end-to-end
    // ───────────────────────────────────────────────────────────────────

    private function stageSpine_threadFullLifecycle(): void
    {
        $matcher = app(TrackedPropertyMatchOrCreateService::class);
        $claimSvc = app(ProspectingClaimService::class);
        $composer = app(SellerOutreachComposerService::class);
        $sender = app(SellerOutreachSenderService::class);
        $compiler = new PresentationCompilerService();
        $sigService = app(SignatureService::class);
        $dealSvc = app(DealPipelineService::class);
        $templateId = DB::table('docuperfect_templates')->value('id');
        $bondTpl = DB::table('deal_pipeline_templates')->where('deal_type', 'bond')->value('id');

        $threaded = 0;
        for ($s = 0; $s < 12; $s++) {
            $town = array_keys(self::TOWN_SUBURBS)[$s % 3];
            $branchId = $this->branchByTown[$town];
            $agentId = $this->agentForBranch($branchId);
            $agent = User::find($agentId);
            // FIX 3: fixed (non-random) suburb + a DISTINCT street per spine so
            // matchOrCreate cannot dedupe the 12 chains together — 12 listings
            // → 12 distinct tracked → 12 distinct promoted properties.
            $townSuburbs = self::TOWN_SUBURBS[$town];
            $suburb = $townSuburbs[$s % count($townSuburbs)];
            $street = self::SPINE_STREETS[$s];
            $beds = $this->rngInt(3, 5);
            $price = $this->priceFor($beds, 'House');
            $streetNo = 500 + $s;
            $addr = "{$streetNo} {$street}, {$suburb}";

            try {
                // 1. Prospect listing → 2. tracked property
                $listingId = DB::table('prospecting_listings')->insertGetId([
                    'agency_id' => self::AGENCY_ID, 'captured_by_user_id' => $agentId,
                    'portal_source' => 'p24', 'portal_ref' => 'DEMO-SPINE-' . $s,
                    'portal_url' => 'https://demo.p24.example/spine/' . $s,
                    'address' => $addr, 'normalized_address' => \App\Models\ProspectingListing::normalizeAddress($addr, $suburb),
                    'suburb' => $suburb, 'district' => $town, 'price' => $price,
                    'bedrooms' => $beds, 'bathrooms' => $beds - 1, 'garages' => 2,
                    'property_type' => 'House', 'agent_name' => 'Demo Source',
                    'agency_name' => self::DEMO_AGENCY_NAME,
                    'first_seen_at' => now()->subDays(60), 'last_seen_at' => now(),
                    'is_active' => 1, 'created_at' => now()->subDays(60), 'updated_at' => now(),
                ]);
                $tp = $matcher->matchOrCreate(self::AGENCY_ID, [
                    'street_number' => (string) $streetNo, 'street_name' => $street,
                    'suburb' => $suburb, 'town' => $town, 'province' => 'KwaZulu-Natal',
                    'property_type' => 'house', 'bedrooms' => $beds, 'bathrooms' => $beds - 1,
                    'last_known_asking_price' => $price, 'address' => $addr,
                ], ['type' => 'demo_spine', 'ref' => 'SPINE-' . $s, 'payload' => ['spine' => true]], $agentId);
                DB::table('prospecting_listings')->where('id', $listingId)
                    ->update(['tracked_property_id' => $tp->id]);

                // 3. Claim + pitch
                $claimSvc->createTempLock($listingId, $agentId, self::AGENCY_ID);
                $claim = $claimSvc->consumeLockAsPermanentClaim($listingId, $agentId, self::AGENCY_ID, [
                    'sent_at' => now()->subDays(40), 'channel' => 'whatsapp', 'recipient_name' => 'seller',
                ]);
                $claimSvc->recordActionOnClaim($claim, 'listing', 'Demo spine: mandate secured');

                // Seller contact + 4. buyer contact + wishlist
                $sellerId = DB::table('contacts')->insertGetId([
                    'agency_id' => self::AGENCY_ID, 'branch_id' => $branchId,
                    'created_by_user_id' => $agentId,
                    'contact_type_id' => $this->contactTypeFor(false),
                    'first_name' => '[DEMO] Spine Seller', 'last_name' => "#{$s}",
                    'phone' => '07' . $this->rngInt(10000000, 99999999),
                    'email' => "spine.seller{$s}@example.com", 'is_buyer' => 0,
                    'loaded_at' => now()->subDays(55), 'modified_at' => now(),
                    'created_at' => now()->subDays(55), 'updated_at' => now(),
                ]);
                $buyerId = DB::table('contacts')->insertGetId([
                    'agency_id' => self::AGENCY_ID, 'branch_id' => $branchId,
                    'created_by_user_id' => $agentId,
                    'contact_type_id' => $this->contactTypeFor(true),
                    'first_name' => '[DEMO] Spine Buyer', 'last_name' => "#{$s}",
                    'phone' => '07' . $this->rngInt(10000000, 99999999),
                    'email' => "spine.buyer{$s}@example.com", 'is_buyer' => 1,
                    'buyer_state' => 'warm', 'preapproval_amount' => $price + 200000,
                    'preapproval_institution' => 'Standard Bank',
                    'preapproval_expires_at' => now()->addMonths(3)->toDateString(),
                    'last_activity_at' => now()->subDays(3),
                    'buyer_pipeline_entered_at' => now()->subDays(50),
                    'loaded_at' => now()->subDays(50), 'modified_at' => now(),
                    'created_at' => now()->subDays(50), 'updated_at' => now(),
                ]);
                ContactMatch::withoutGlobalScopes()->create([
                    'agency_id' => self::AGENCY_ID, 'contact_id' => $buyerId,
                    'created_by_user_id' => $agentId, 'updated_by_user_id' => $agentId,
                    'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
                    'price_min' => $price - 300000, 'price_max' => $price + 400000,
                    'beds_min' => $beds - 1, 'bedrooms_max' => $beds + 1,
                    'suburbs' => [$suburb], 'property_types' => ['House'],
                    'must_have_features' => [], 'nice_to_have_features' => [], 'deal_breakers' => [],
                ]);

                // 5. Promote tracked → agency stock
                $property = $matcher->promoteToStock($tp->id, $agentId, ['branch_id' => $branchId]);
                DB::table('properties')->where('id', $property->id)->update([
                    'title' => "[DEMO] SPINE {$beds} Bed House in {$suburb}",
                    'status' => 'available', 'listing_type' => 'sale', 'category' => 'Residential',
                    'published_at' => now()->subDays(38),
                    'listed_date' => now()->subDays(38)->toDateString(), 'mandate_type' => 'Sole',
                ]);
                DB::table('contact_property')->insert([
                    'contact_id' => $sellerId, 'property_id' => $property->id, 'role' => 'owner',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                // Spine buyer is created AFTER stage6b runs, so link it here
                // to its target property (role=buyer) — guarantees every buyer
                // has >=1 property link including spine buyers.
                DB::table('contact_property')->insertOrIgnore([
                    'contact_id' => $buyerId, 'property_id' => $property->id, 'role' => 'buyer',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                // 6. Pitch the buyer's match (seller-outreach) + buyer match row
                $sellerModel = \App\Models\Contact::withoutGlobalScopes()->find($sellerId);
                $propModel = \App\Models\Property::withoutGlobalScopes()->find($property->id);
                try {
                    $ctx = $composer->composeContext(self::AGENCY_ID, $sellerModel, $propModel, 'whatsapp', null, $agent);
                    if ($ctx->isSendable()) {
                        $sender->send($ctx);
                    }
                } catch (\Throwable $e) {
                }
                DB::table('prospecting_buyer_matches')->updateOrInsert(
                    ['prospecting_listing_id' => $listingId, 'contact_id' => $buyerId],
                    ['agency_id' => self::AGENCY_ID, 'score' => 91, 'tier' => 'perfect',
                     'matched_features' => json_encode(['breakdown' => ['price' => 25, 'area' => 20]]),
                     'missing_features' => json_encode([]), 'matched_at' => now(),
                     'last_recompute_at' => now(), 'created_at' => now(), 'updated_at' => now()]
                );

                // 7. Presentation (finalized) + compiled
                $pres = Presentation::create([
                    'agency_id' => self::AGENCY_ID, 'branch_id' => $branchId,
                    'created_by_user_id' => $agentId, 'listing_id' => null,
                    'title' => "Listing Presentation — {$suburb} (spine #{$s})",
                    'property_address' => $addr, 'suburb' => $suburb, 'property_type' => 'house',
                    'bedrooms' => $beds, 'bathrooms' => $beds - 1, 'asking_price_inc' => $price,
                    'seller_name' => 'Spine Seller', 'status' => 'finalized', 'currency' => 'ZAR',
                ]);
                $compiler->compile($pres->id, $agentId);

                // 8. FICA (approved) for the buyer
                $fica = FicaSubmission::create([
                    'agency_id' => self::AGENCY_ID, 'requested_by' => $this->adminId,
                    'contact_id' => $buyerId, 'entity_type' => 'natural', 'status' => 'draft',
                ]);
                $fica->update(['status' => 'submitted', 'signed_at' => now()->subDays(20)]);
                $fica->update(['status' => 'agent_approved', 'risk_rating' => 1,
                    'verification_method' => ['method' => 'in_person'],
                    'agent_verified_by' => $agentId, 'agent_verified_at' => now()->subDays(15)]);
                $fica->update(['status' => 'approved', 'verified_by' => $this->adminId,
                    'verified_at' => now()->subDays(12),
                    'fica_expires_at' => now()->addMonths(24)->toDateString(),
                    'co_verified_by' => $this->adminId, 'co_verified_at' => now()->subDays(12)]);

                // 9. E-sign (completed) + 10. OTP (verified)
                if ($templateId) {
                    $doc = Document::create([
                        'name' => "[DEMO] Sale Agreement — spine #{$s}", 'template_id' => $templateId,
                        'owner_id' => $agentId, 'branch_id' => $branchId,
                        'document_type' => 'sale_agreement', 'property_address' => $addr,
                        'property_id' => $property->id, 'fields_json' => [],
                    ]);
                    $tpl = $sigService->createTemplate($doc, $agent);
                    $req = $sigService->createSigningRequest($tpl, 'buyer', '[DEMO] Spine Buyer',
                        "spine.buyer{$s}@example.com", null, 'Please sign.', $agent, false);
                    $req->update(['status' => 'completed', 'sent_at' => now()->subDays(8),
                        'completed_at' => now()->subDays(7)]);
                    $tpl->update(['status' => 'completed', 'completed_at' => now()->subDays(7)]);
                }
                $otpCode = str_pad((string) $this->rngInt(0, 999999), 6, '0', STR_PAD_LEFT);
                DB::table('client_otps')->insert([
                    'email' => "spine.buyer{$s}@example.com", 'purpose' => 'activation',
                    'code_hash' => Hash::make($otpCode), 'expires_at' => now()->subMinutes(10),
                    'used_at' => now()->subMinutes(8), 'attempts' => 1,
                    'created_at' => now()->subMinutes(30), 'updated_at' => now(),
                ]);

                // 11. Deal register → driven to registered
                if ($bondTpl) {
                    $comm = (int) round($price * 0.06);
                    $deal = $dealSvc->createDeal([
                        'deal_type' => 'bond', 'property_id' => $property->id,
                        'listing_agent_id' => $agentId, 'selling_agent_id' => $agentId,
                        'pipeline_template_id' => $bondTpl, 'purchase_price' => $price,
                        'commission_percentage' => 6.0, 'commission_amount' => $comm,
                        'commission_vat' => (int) round($comm * 0.15),
                        'offer_date' => now()->subDays(90)->toDateString(),
                        'branch_id' => $branchId, 'created_by_id' => $agentId,
                        'contacts' => [['contact_id' => $buyerId, 'role' => 'buyer'],
                                       ['contact_id' => $sellerId, 'role' => 'seller']],
                        'agents' => [['side' => 'listing', 'user_id' => $agentId],
                                     ['side' => 'selling', 'user_id' => $agentId]],
                    ]);
                    $this->driveDeal($dealSvc, $deal, $agent, 'registered');
                }

                $this->spine[] = ['tracked_property_id' => $tp->id, 'property_id' => $property->id,
                    'buyer_contact_id' => $buyerId, 'listing_id' => $listingId];
                $threaded++;
            } catch (\Throwable $e) {
                $this->command->warn("    spine #{$s}: " . $e->getMessage());
            }
        }
        $this->command->info("  Spine: {$threaded} properties threaded prospect → registered");
    }

    /**
     * Demo-presenter coherence (runs LAST — after EVERY ContactMatch is
     * created, incl. the spine). The Core Matches screen
     * (ContactMatchController::index) is scoped to
     * created_by_user_id = the logged-in user. All seeded matches are
     * attributed to agents, so the demo login (Demo Administrator,
     * $this->adminId) sees "No Core Matches saved yet". Re-attribute a
     * deterministic handful to the demo login so the screen is populated
     * for the presenter; the rest stay with their agents (per-agent scope
     * kept realistic). Idempotent: fixed ids by order → re-run re-sets the
     * same value, no duplicates, no new matches. NO controller/scope change.
     */
    private function stageZ_demoPresenterCoherence(): void
    {
        if (! $this->adminId) {
            return;
        }
        $ids = DB::table('contact_matches')
            ->where('agency_id', self::AGENCY_ID)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(10)
            ->pluck('id')
            ->all();
        if (empty($ids)) {
            return;
        }
        DB::table('contact_matches')
            ->whereIn('id', $ids)
            ->update([
                'created_by_user_id' => $this->adminId,
                'updated_by_user_id' => $this->adminId,
                'updated_at'         => now(),
            ]);
        $this->command->info('  Stage Z: ' . count($ids)
            . ' Core Matches attributed to the demo login (Core Matches screen populated)');
    }
}
