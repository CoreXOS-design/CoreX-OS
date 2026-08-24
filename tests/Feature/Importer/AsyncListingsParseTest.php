<?php

declare(strict_types=1);

namespace Tests\Feature\Importer;

use App\Jobs\ParseP24ListingsImportJob;
use App\Models\Agency;
use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\P24OnboardingPortal;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Async listings+images parse — .ai/specs/importer-async-parse.md
 *
 * uploadListings() used to parse both CSVs and create every P24ImportRow
 * fully synchronously inside the HTTP request — for a large agency that
 * request could run long enough to interact badly with session/CSRF
 * handling under load (observed live 2026-08-14, 4,753 listings). Parsing
 * now runs in ParseP24ListingsImportJob; the controller returns as soon as
 * the portal exists and the job is dispatched.
 */
final class AsyncListingsParseTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        return Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
    }

    private function systemOwner(): User
    {
        $ownerRole = Role::withoutGlobalScopes()->whereNull('agency_id')->where('is_owner', true)->first();
        if (! $ownerRole) {
            $ownerRole = new Role();
            $ownerRole->forceFill([
                'name' => 'owner', 'label' => 'CoreX System Owner', 'is_owner' => true,
                'agency_id' => null, 'sort_order' => 0,
            ])->save();
        }
        Role::clearCache();

        return User::factory()->create(['name' => 'CoreX System Owner', 'agency_id' => null, 'role' => $ownerRole->name]);
    }

    private function listingsCsv(array $rows): UploadedFile
    {
        $header = ['ListingNumber', 'ListingType', 'Status', 'Price', 'ContactAgentIds', 'StreetNumber', 'StreetName'];
        $lines = [implode(',', $header)];
        foreach ($rows as $r) {
            $lines[] = implode(',', [$r['number'], 'Sale', 'Active', '1500000', '', '1', 'Main Road']);
        }
        return UploadedFile::fake()->createWithContent('listings.csv', implode("\n", $lines));
    }

    private function imagesCsv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('images.csv', "ListingNumber,Caption,Ordinal,Prop24ImageUrl\n");
    }

    /** THE fix: the request must not block on parsing thousands of rows. */
    public function test_upload_returns_before_any_row_is_parsed(): void
    {
        Queue::fake();
        Storage::fake('local');
        $agency = $this->makeAgency();
        $owner = $this->systemOwner();
        P24ImportRun::create(['agency_id' => $agency->id, 'kind' => 'agents', 'status' => 'completed']);

        $response = $this->actingAs($owner)->post(route('admin.importer.listings.upload'), [
            'agency_id'    => $agency->id,
            'listings_csv' => $this->listingsCsv([['number' => '100001'], ['number' => '100002']]),
            'images_csv'   => $this->imagesCsv(),
        ]);

        $response->assertSessionDoesntHaveErrors();
        Queue::assertPushed(ParseP24ListingsImportJob::class);
        // The job was dispatched, not executed inline — with Queue::fake() nothing
        // runs it, so if the controller had parsed synchronously these would exist.
        $this->assertSame(0, P24ImportRow::count(), 'parsing must not have happened inline');
    }

    /** The portal exists and its link is returned even though parsing hasn't started. */
    public function test_the_portal_and_link_are_available_immediately(): void
    {
        Queue::fake();
        Storage::fake('local');
        $agency = $this->makeAgency();
        $owner = $this->systemOwner();
        P24ImportRun::create(['agency_id' => $agency->id, 'kind' => 'agents', 'status' => 'completed']);

        $response = $this->actingAs($owner)
            ->postJson(route('admin.importer.listings.upload'), [
                'agency_id'    => $agency->id,
                'listings_csv' => $this->listingsCsv([['number' => '100001']]),
                'images_csv'   => $this->imagesCsv(),
            ]);

        $response->assertOk()->assertJsonStructure(['redirect', 'portal_url']);
        $this->assertSame(1, P24OnboardingPortal::where('agency_id', $agency->id)->count());
    }

    /** The job itself does the parsing the controller used to do inline. */
    public function test_the_job_parses_rows_and_completes_the_run(): void
    {
        Storage::fake('local');
        $agency = $this->makeAgency();
        $owner = $this->systemOwner();

        $listingsPath = $this->listingsCsv([['number' => '100001'], ['number' => '100002']])
            ->store('imports/p24/listings');
        $imagesPath = $this->imagesCsv()->store('imports/p24/images');

        $run = P24ImportRun::create([
            'user_id' => $owner->id, 'agency_id' => $agency->id, 'kind' => 'listings_images',
            'status' => 'parsing', 'listings_csv_path' => $listingsPath, 'images_csv_path' => $imagesPath,
        ]);

        (new ParseP24ListingsImportJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('pending_confirm', $run->status);
        $this->assertSame(2, P24ImportRow::where('run_id', $run->id)->count());
        $this->assertSame(2, $run->counts_json['listings'] ?? null);
    }

    /** A malformed CSV surfaces on the run, not as a controller-level error (the controller already returned). */
    public function test_a_missing_csv_file_marks_the_run_failed(): void
    {
        $agency = $this->makeAgency();
        $owner = $this->systemOwner();

        $run = P24ImportRun::create([
            'user_id' => $owner->id, 'agency_id' => $agency->id, 'kind' => 'listings_images',
            'status' => 'parsing',
            'listings_csv_path' => 'imports/p24/listings/does-not-exist.csv',
            'images_csv_path'   => 'imports/p24/images/does-not-exist.csv',
        ]);

        (new ParseP24ListingsImportJob($run->id))->handle();

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->error_message);
        $this->assertSame(0, P24ImportRow::where('run_id', $run->id)->count());
    }

    /** A redelivered job (simulated worker crash) must not duplicate rows. */
    public function test_redelivery_does_not_duplicate_rows(): void
    {
        Storage::fake('local');
        $agency = $this->makeAgency();
        $owner = $this->systemOwner();

        $listingsPath = $this->listingsCsv([['number' => '100001'], ['number' => '100002']])
            ->store('imports/p24/listings');
        $imagesPath = $this->imagesCsv()->store('imports/p24/images');

        $run = P24ImportRun::create([
            'user_id' => $owner->id, 'agency_id' => $agency->id, 'kind' => 'listings_images',
            'status' => 'parsing', 'listings_csv_path' => $listingsPath, 'images_csv_path' => $imagesPath,
        ]);

        (new ParseP24ListingsImportJob($run->id))->handle();
        // Simulate the run being re-opened for a redelivered attempt (mirrors what
        // a genuinely crashed-mid-parse worker would leave: status back to parsing).
        $run->update(['status' => 'parsing']);
        (new ParseP24ListingsImportJob($run->id))->handle();

        $this->assertSame(2, P24ImportRow::where('run_id', $run->id)->count(), 'redelivery must not duplicate rows');
    }

    /** The status endpoint reports live parse progress while the job is running. */
    public function test_status_endpoint_reports_parsing_progress(): void
    {
        $agency = $this->makeAgency();
        $run = P24ImportRun::create([
            'agency_id' => $agency->id, 'kind' => 'listings_images', 'status' => 'parsing',
            'counts_json' => ['listings_parsed_so_far' => 1750],
        ]);
        $portal = P24OnboardingPortal::create([
            'agency_id' => $agency->id, 'token' => P24OnboardingPortal::generateToken(),
            'slug' => Str::slug('test-portal-' . uniqid()), 'expires_at' => now()->addDays(30),
            'run_ids_json' => [$run->id],
        ]);

        $this->getJson("/onboarding/{$portal->token}/status")
            ->assertOk()
            ->assertJson(['parse' => ['status' => 'parsing', 'parsed_so_far' => 1750, 'error' => null]]);
    }

    /** The status endpoint surfaces a failed parse so the portal can show it. */
    public function test_status_endpoint_reports_a_failed_parse(): void
    {
        $agency = $this->makeAgency();
        $run = P24ImportRun::create([
            'agency_id' => $agency->id, 'kind' => 'listings_images', 'status' => 'failed',
            'error_message' => 'Cannot open listings CSV.',
        ]);
        $portal = P24OnboardingPortal::create([
            'agency_id' => $agency->id, 'token' => P24OnboardingPortal::generateToken(),
            'slug' => Str::slug('test-portal-' . uniqid()), 'expires_at' => now()->addDays(30),
            'run_ids_json' => [$run->id],
        ]);

        $this->getJson("/onboarding/{$portal->token}/status")
            ->assertOk()
            ->assertJson(['parse' => ['status' => 'failed', 'error' => 'Cannot open listings CSV.']]);
    }

    /** Once parsing is done, the status endpoint reports no parse state — normal behaviour resumes. */
    public function test_status_endpoint_reports_null_parse_once_pending_confirm(): void
    {
        $agency = $this->makeAgency();
        $run = P24ImportRun::create([
            'agency_id' => $agency->id, 'kind' => 'listings_images', 'status' => 'pending_confirm',
        ]);
        $portal = P24OnboardingPortal::create([
            'agency_id' => $agency->id, 'token' => P24OnboardingPortal::generateToken(),
            'slug' => Str::slug('test-portal-' . uniqid()), 'expires_at' => now()->addDays(30),
            'run_ids_json' => [$run->id],
        ]);

        $this->getJson("/onboarding/{$portal->token}/status")
            ->assertOk()
            ->assertJson(['parse' => ['status' => null, 'parsed_so_far' => null, 'error' => null]]);
    }
}
