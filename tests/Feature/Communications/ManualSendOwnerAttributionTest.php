<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\ManualSendOwnerBackfiller;
use App\Services\Communications\OutboundProvisionalLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-246 — manual-send owner attribution.
 *
 * Write path: OutboundProvisionalLogger::log() must stamp owner_user_id (not just
 * bury the sender in source_ref), and a null sender legitimately stays null.
 *
 * Backfill: ManualSendOwnerBackfiller heals ONLY NULL-owner rows whose source_ref
 * is 'manual:user:<digits>' AND whose user still exists — never a non-manual
 * shape, never 'manual:user:unknown', never a row already owned, never a row
 * whose user is gone. Idempotent; revert() re-NULLs exactly the healed set.
 */
final class ManualSendOwnerAttributionTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Attr ' . Str::random(6), 'slug' => 'attr-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->userId = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
        ])->id;
    }

    // ── Write path (fix #1) ───────────────────────────────────────────────

    public function test_log_stamps_owner_user_id_from_the_sending_agent(): void
    {
        $contact = $this->contact();

        $comm = app(OutboundProvisionalLogger::class)
            ->log($contact, Communication::CHANNEL_WHATSAPP, null, 'Hi there', $this->userId);

        $this->assertSame($this->userId, (int) $comm->owner_user_id, 'owner stamped, not just in source_ref');
        $this->assertSame('manual:user:' . $this->userId, $comm->source_ref, 'source_ref still carries the agent');
    }

    public function test_log_with_no_agent_leaves_owner_null(): void
    {
        $contact = $this->contact();

        $comm = app(OutboundProvisionalLogger::class)
            ->log($contact, Communication::CHANNEL_WHATSAPP, null, 'System note', null);

        $this->assertNull($comm->owner_user_id, 'ownerless send stays null (graceful-null contract)');
        $this->assertSame('manual:user:unknown', $comm->source_ref);
    }

    // ── Backfill (fix #2) ─────────────────────────────────────────────────

    public function test_backfill_heals_only_valid_null_manual_rows(): void
    {
        $other = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent'])->id;

        $healable   = $this->comm(null, 'manual:user:' . $this->userId);        // A → healed
        $owned      = $this->comm($other, 'manual:user:' . $this->userId);       // B → untouched (already owned)
        $unknown    = $this->comm(null, 'manual:user:unknown');                  // C → untouched (not digits)
        $goneUser   = $this->comm(null, 'manual:user:999999');                   // D → untouched (user gone)
        $distro     = $this->comm(null, 'deal_distribution:user:' . $this->userId); // E → untouched (not manual)
        $device     = $this->comm(null, 'wa_device:abc123');                     // F → untouched (not manual)

        $result = app(ManualSendOwnerBackfiller::class)->heal();

        $this->assertSame(1, $result['updated'], 'exactly one row healed');
        $this->assertSame(2, $result['total_null_manual'], 'before: A + D are null-owner manual:user:<digits>');
        $this->assertSame(1, $result['skipped_no_user'], 'after: only D remains (user gone)');

        $this->assertSame($this->userId, (int) $this->fresh($healable)->owner_user_id, 'A healed to parsed NN');
        $this->assertSame($other, (int) $this->fresh($owned)->owner_user_id, 'B untouched — never overwrite a real owner');
        $this->assertNull($this->fresh($unknown)->owner_user_id, 'C untouched — unknown is not digits');
        $this->assertNull($this->fresh($goneUser)->owner_user_id, 'D untouched — FK-safe, user gone');
        $this->assertNull($this->fresh($distro)->owner_user_id, 'E untouched — not a manual send');
        $this->assertNull($this->fresh($device)->owner_user_id, 'F untouched — not a manual send');
    }

    public function test_backfill_is_idempotent(): void
    {
        $healable = $this->comm(null, 'manual:user:' . $this->userId);

        $first  = app(ManualSendOwnerBackfiller::class)->heal();
        $second = app(ManualSendOwnerBackfiller::class)->heal();

        $this->assertSame(1, $first['updated']);
        $this->assertSame(0, $second['updated'], 're-run heals nothing');
        $this->assertSame($this->userId, (int) $this->fresh($healable)->owner_user_id);
    }

    public function test_revert_re_nulls_exactly_the_healed_set(): void
    {
        $other    = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent'])->id;
        $healable = $this->comm(null, 'manual:user:' . $this->userId); // healed then reverted
        $owned    = $this->comm($other, 'manual:user:' . $this->userId); // owned throughout

        $backfiller = app(ManualSendOwnerBackfiller::class);
        $backfiller->heal();
        $this->assertSame($this->userId, (int) $this->fresh($healable)->owner_user_id);

        $reverted = $backfiller->revert();

        $this->assertSame(1, $reverted, 'only the one healed row is re-NULLed');
        $this->assertNull($this->fresh($healable)->owner_user_id, 'healed row reverted to null');
        $this->assertSame($other, (int) $this->fresh($owned)->owner_user_id, 'a natively-owned row is never touched by revert');
        $this->assertSame(0, DB::table(ManualSendOwnerBackfiller::MARKER_TABLE)->count(), 'ledger cleared on revert');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function contact(): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'first_name' => 'Thandi', 'last_name' => 'Mkhize',
            'phone' => '+2782' . random_int(1000000, 9999999),
        ]);
    }

    /** Create a communications row with a chosen owner + source_ref (mirrors log()'s NOT-NULL set). */
    private function comm(?int $ownerId, string $sourceRef): int
    {
        $now = now();
        return (int) Communication::create([
            'agency_id'               => $this->agencyId,
            'channel'                 => Communication::CHANNEL_WHATSAPP,
            'direction'               => Communication::DIRECTION_OUTBOUND,
            'external_id'             => 'provisional:' . Str::uuid()->toString(),
            'participant_identifiers' => ['+27820000000'],
            'occurred_at'             => $now,
            'captured_at'             => $now,
            'provisional_at'          => $now,
            'body_text'               => 'body ' . Str::random(6),
            'text_hash'               => hash('sha256', Str::random(12)),
            'has_attachments'         => false,
            'source_ref'              => $sourceRef,
            'owner_user_id'           => $ownerId,
        ])->id;
    }

    private function fresh(int $id): Communication
    {
        return Communication::withoutGlobalScopes()->findOrFail($id);
    }
}
