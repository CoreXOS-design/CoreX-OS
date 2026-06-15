<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationPending;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-43 Fix 2 — communications:purge-pending-by-filter. Soft-purges never-business
 * pending rows (no-reply/bank/service, no contact); keeps contact-matching and
 * ordinary senders. Dry-run writes nothing.
 */
final class PurgePendingByFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]));
    }

    private function pending(string $from): CommunicationPending
    {
        return CommunicationPending::create([
            'agency_id'               => $this->agencyId,
            'channel'                 => Communication::CHANNEL_EMAIL,
            'direction'               => Communication::DIRECTION_INBOUND,
            'external_id'             => '<' . Str::random(10) . '@x.test>',
            'from_identifier'         => $from,
            'participant_identifiers' => [$from],
            'occurred_at'             => now()->subDay(),
            'captured_at'             => now()->subDay(),
            'subject'                 => 'S',
            'raw_path'                => 'communications/x/email/' . Str::random(8),
            'content_hash'            => hash('sha256', $from),
            'has_attachments'         => false,
            'source_ref'              => 'mailbox:1',
            'expires_at'              => now()->addDays(4),
        ]);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $bank = $this->pending('incontact@fnb.co.za');
        $noreply = $this->pending('no-reply@x.test');

        $this->artisan('communications:purge-pending-by-filter --dry-run')->assertSuccessful();

        $this->assertNull($bank->fresh()->purged_at, 'dry-run must not purge');
        $this->assertNull($noreply->fresh()->purged_at);
        $this->assertSame(2, CommunicationPending::whereNull('purged_at')->count());
    }

    public function test_purges_service_and_noreply_keeps_contact_and_ordinary(): void
    {
        // A contact on a blocklist domain — must be KEPT (contact wins).
        Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Rel', 'last_name' => 'Mgr',
            'phone' => '', 'email' => 'rm@fnb.co.za',
        ]);

        $bank      = $this->pending('incontact@fnb.co.za');       // purge (service, no contact)
        $noreply   = $this->pending('no-reply@portal.test');      // purge (no-reply, no contact)
        $contactly = $this->pending('rm@fnb.co.za');              // KEEP (contact match beats blocklist)
        $ordinary  = $this->pending('jane@privateclient.test');   // KEEP (not on droplist)

        $this->artisan('communications:purge-pending-by-filter')->assertSuccessful();

        $this->assertSoftDeleted('communication_pending', ['id' => $bank->id]);
        $this->assertSoftDeleted('communication_pending', ['id' => $noreply->id]);
        $this->assertNotNull($bank->fresh()->purged_at);
        $this->assertSame('ingest_filter_service_domain', $bank->fresh()->purged_reason);
        $this->assertSame('ingest_filter_no_reply_pattern', $noreply->fresh()->purged_reason);

        $this->assertNull($contactly->fresh()->purged_at, 'contact match kept');
        $this->assertNull($ordinary->fresh()->purged_at, 'ordinary sender kept');
        $this->assertSame(2, CommunicationPending::whereNull('purged_at')->count());
    }
}
