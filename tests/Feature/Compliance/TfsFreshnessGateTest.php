<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\SanctionsListImport;
use App\Models\FicaSubmission;
use App\Models\User;
use App\Services\Compliance\Tfs\TfsScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Live bug (2026-08-10): the FIC UN Consolidated list was successfully re-fetched
 * and re-verified EVERY DAY (HTTP 200, byte-identical content -> status='unchanged'),
 * but the staleness gate only trusted status='success' rows, so it read stale off a
 * content-change that was 8 days old even though the list had been confirmed current
 * every single day. Fixed in TfsScreeningService::freshestImport() by accepting
 * status IN ('success','unchanged') — a fetch that ran and reconfirmed the content
 * is a valid re-verification, not a failure.
 *
 * This test proves BOTH directions: a daily unchanged-reverification chain must read
 * FRESH (the false-stale bug), and a genuinely broken/failing fetch pipeline must
 * still read STALE (the compliance-critical guarantee — never a false-fresh).
 */
final class TfsFreshnessGateTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = 'fic_un_consolidated';

    public function test_daily_unchanged_reverification_keeps_the_gate_fresh(): void
    {
        $submission = $this->submission();

        // Content last CHANGED 10 days ago (status=success), then re-fetched and
        // re-confirmed byte-identical every day since, most recently yesterday.
        $this->import('success', now()->subDays(10), 'sha-a');
        for ($daysAgo = 9; $daysAgo >= 1; $daysAgo--) {
            $this->import('unchanged', now()->subDays($daysAgo), 'sha-a');
        }

        $screening = app(TfsScreeningService::class)->screen($submission);

        $this->assertNotSame('list_stale', $screening->reason, 'a daily-reverified list must never read stale');
        $this->assertSame('passed', $screening->outcome, 'clean + fresh (via unchanged chain) must pass, not error');
    }

    public function test_genuinely_stale_when_fetches_have_been_failing(): void
    {
        $submission = $this->submission();

        // Last real success/unchanged confirmation was 10 days ago; every fetch
        // since has genuinely FAILED (FIC source down / geo-block / parse error).
        $this->import('success', now()->subDays(10), 'sha-a');
        foreach ([9, 7, 5, 3, 1] as $daysAgo) {
            $this->import('failed', now()->subDays($daysAgo), null);
        }

        $screening = app(TfsScreeningService::class)->screen($submission);

        $this->assertSame('error', $screening->outcome, 'a genuinely broken fetch pipeline must never auto-pass');
        $this->assertSame('list_stale', $screening->reason);
    }

    public function test_genuinely_stale_when_cron_has_stopped_entirely(): void
    {
        $submission = $this->submission();

        // The daily cron itself stopped running — no rows of any status since.
        $this->import('success', now()->subDays(10), 'sha-a');

        $screening = app(TfsScreeningService::class)->screen($submission);

        $this->assertSame('error', $screening->outcome);
        $this->assertSame('list_stale', $screening->reason, 'a stalled cron (no new rows at all) must still read stale');
    }

    public function test_force_download_route_reingests_and_rescreens_in_one_click(): void
    {
        [$agencyId, $actor] = $this->fixture();
        $submission = $this->submission($agencyId, $actor->id);

        // Nothing ingested yet at all -> currently unscreenable (no_list).
        $this->assertSame(0, SanctionsListImport::query()->count());

        $xml = '<?xml version="1.0"?><NewDataSet><Individual>'
            . '<ReferenceNumber>TEST.001</ReferenceNumber><FullName>Nobody Matching</FullName>'
            . '</Individual></NewDataSet>';
        Http::fake(['tfs.fic.gov.za/*' => Http::response($xml, 200)]);

        $resp = $this->actingAs($actor)
            ->post(route('compliance.fica.tfs-force-download', $submission));

        $resp->assertRedirect();
        $resp->assertSessionHas('success');
        $this->assertStringContainsString('Force download', session('success'));
        $this->assertStringContainsString('Re-screened:', session('success'));

        $import = SanctionsListImport::where('source_feed', self::FEED)->first();
        $this->assertNotNull($import, 'force download must have created an import row');
        $this->assertSame('success', $import->status);
        $this->assertSame(1, $import->record_count);

        $screening = $submission->fresh()->latestTfsScreening();
        $this->assertNotNull($screening, 'force download must have run a screening for this submission');
        $this->assertSame('passed', $screening->outcome, 'no name in the fetched list should match -> clean pass');
    }

    // ── Harness ───────────────────────────────────────────────────────────

    private function import(string $status, $finishedAt, ?string $sha): void
    {
        SanctionsListImport::create([
            'source_feed'     => self::FEED,
            'source_label'    => 'FIC UN Consolidated Sanctions List (XML)',
            'fetch_method'    => 'http_post',
            'http_status'     => $status === 'failed' ? 500 : 200,
            'content_sha256'  => $sha ? hash('sha256', $sha) : null,
            'record_count'    => $status === 'failed' ? 0 : 1002,
            'status'          => $status,
            'started_at'      => $finishedAt,
            'finished_at'     => $finishedAt,
        ]);
    }

    private function submission(?int $agencyId = null, ?int $requestedBy = null): FicaSubmission
    {
        if ($agencyId === null) {
            [$agencyId, $actor] = $this->fixture();
            $requestedBy = $actor->id;
        }

        return FicaSubmission::create([
            'agency_id'    => $agencyId,
            'branch_id'    => $agencyId,
            'requested_by' => $requestedBy,
            'token'        => Str::random(40),
            'token_expires_at' => now()->addDays(30),
            'entity_type'  => 'natural',
            'status'       => 'submitted',
            'form_data'    => [
                'personal' => [
                    'full_name' => 'Some Nobody ' . Str::random(6),
                    'id_number' => (string) random_int(1000000000000, 9999999999999),
                ],
            ],
        ]);
    }

    /** @return array{0:int,1:User} */
    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'TFS Test ' . Str::random(6), 'slug' => 'tfs-test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $actor = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);

        return [$agencyId, $actor];
    }
}
