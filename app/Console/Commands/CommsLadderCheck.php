<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationFilingSuspense;
use App\Models\Deal;
use App\Models\User;
use App\Services\Communications\AttorneyCorrespondenceResolver;
use App\Services\Communications\CorrespondenceFilingService;
use App\Services\Communications\CorrespondenceMatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Standing COMMS regression check (Phase 2). Builds a fully DISPOSABLE deal + parties (seller / buyer /
 * transferring attorney / work-order supplier) inside one transaction, drives the REAL correspondence
 * services, asserts the rules that must not regress, and ROLLS BACK — touching nothing real. Mirrors
 * the esign:regression-walk pattern.
 *
 * Asserts: the confidence ladder (T1 all-3 -> HIGH, T2 buyer+seller -> HIGH, T3 supplier+one-side ->
 * HIGH via uniqueness, T4 single party -> LOW, [CX-D] token -> HIGH), the G1=A park gate (any party
 * present incl. a party in cc with an unknown sender -> PARK; random-only -> drop), the compounding win
 * (verify learns subject_exact -> an identical-subject next mail auto-files; a "Re:" mutation falls back
 * to suspense), and shared-state (one email = one row/link via two unique keys; double-approve guarded).
 *
 * QA-only: refuses to run on production.
 *
 *   php artisan comms:ladder-check
 */
final class CommsLadderCheck extends Command
{
    protected $signature = 'comms:ladder-check {--agency=1 : Agency id to build the disposable fixture in} {--user=22 : Approving user id}';
    protected $description = 'Standing comms regression check: confidence ladder, G1=A park gate, compounding-win auto-file, shared-state — on disposable, rolled-back data.';

    /** @var array<int,array{0:string,1:bool,2:string}> */
    private array $results = [];

    public function handle(
        CorrespondenceMatchService $matcher,
        AttorneyCorrespondenceResolver $resolver,
        CorrespondenceFilingService $filing
    ): int {
        if (app()->environment('production')) {
            $this->error('Refusing to run on production — QA/disposable only.');
            return self::FAILURE;
        }

        $agencyId = (int) $this->option('agency');
        $user     = User::find((int) $this->option('user'));
        if (! $user) {
            $this->error('Approving user not found (--user).');
            return self::FAILURE;
        }
        $branchId = (int) (DB::table('branches')->where('agency_id', $agencyId)->value('id') ?? 0);
        if ($branchId === 0) {
            $this->error("No branch found for agency {$agencyId} — cannot build the fixture.");
            return self::FAILURE;
        }

        // Distinct fixture addresses.
        $SELLER   = 'seller@laddercheck.test';
        $BUYER    = 'buyer@laddercheck.test';
        $ATTORNEY = 'attorney@laddercheck.test';
        $SUPPLIER = 'supplier@laddercheck.test';
        $STRANGER = 'stranger@nowhere.test';

        DB::beginTransaction();
        try {
            // ── DISPOSABLE FIXTURE ─────────────────────────────────────────────
            $now = now();
            $mkContact = fn (string $first, string $email) => DB::table('contacts')->insertGetId([
                'agency_id' => $agencyId, 'branch_id' => $branchId, 'first_name' => $first,
                'last_name' => 'LadderCheck', 'email' => $email, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $sellerCid = $mkContact('Seller', $SELLER);
            $buyerCid  = $mkContact('Buyer', $BUYER);

            $attorneyPid = DB::table('agency_service_providers')->insertGetId([
                'agency_id' => $agencyId, 'name' => 'LadderCheck Attorneys', 'email' => $ATTORNEY,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $supplierPid = DB::table('agency_service_providers')->insertGetId([
                'agency_id' => $agencyId, 'name' => 'LadderCheck Supplier', 'email' => $SUPPLIER,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $dealId = DB::table('deals')->insertGetId([
                'agency_id' => $agencyId, 'deal_no' => null, // integer column; disposable, left null
                'period' => $now->format('Y-m'), 'deal_date' => $now->toDateString(),
                'property_value' => 1000000, 'total_commission' => 50000,
                'seller_name' => 'Seller LadderCheck', 'buyer_name' => 'Buyer LadderCheck',
                'property_address' => '1 LadderCheck Road, Testville',
                'attorney_provider_id' => $attorneyPid, 'accepted_status' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('deal_contacts')->insert([
                ['deal_id' => $dealId, 'contact_id' => $sellerCid, 'role' => 'seller'],
                ['deal_id' => $dealId, 'contact_id' => $buyerCid, 'role' => 'buyer'],
            ]);

            // A work order makes the supplier a party on THIS deal (Phase-1 supplier path).
            $stepId = DB::table('deal_step_instances')->insertGetId([
                'agency_id' => $agencyId, 'dr1_deal_id' => $dealId, 'name' => 'LadderCheck COC',
                'trigger_type' => 'manual', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('deal_step_work_orders')->insert([
                'agency_id' => $agencyId, 'dr1_deal_id' => $dealId, 'deal_step_instance_id' => $stepId,
                'service_provider_id' => $supplierPid, 'service_type' => 'electrical_coc', 'status' => 'pending',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // ── HELPERS ────────────────────────────────────────────────────────
            $resolveTier = function (string $sender, array $participants, string $subject = 'LadderCheck update')
                use ($matcher, $resolver, $agencyId) {
                $att = $resolver->resolveSender($sender, $agencyId) ?? ['provider' => null, 'contact' => null];
                return $matcher->resolve($agencyId, [
                    'subject' => $subject, 'body_text' => 'body', 'counterpart' => $sender,
                    'participants' => $participants, 'thread_key' => '',
                ], $att);
            };
            $HIGH = CorrespondenceMatchService::TIER_HIGH;
            $MED  = CorrespondenceMatchService::TIER_MEDIUM;
            $LOW  = CorrespondenceMatchService::TIER_LOW;
            $AUTO = CorrespondenceMatchService::TIER_AUTO;

            // ── LADDER ─────────────────────────────────────────────────────────
            $t1 = $resolveTier($ATTORNEY, [$SELLER, $BUYER, $ATTORNEY]);
            $this->assert('Ladder T1: seller+buyer+attorney -> HIGH', $t1['tier'] === $HIGH && (int) $t1['deal_id'] === $dealId, "tier={$t1['tier']} deal=" . var_export($t1['deal_id'], true));

            $t2 = $resolveTier($SELLER, [$SELLER, $BUYER]);
            $this->assert('Ladder T2: buyer+seller -> HIGH', $t2['tier'] === $HIGH && (int) $t2['deal_id'] === $dealId, "tier={$t2['tier']} deal=" . var_export($t2['deal_id'], true));

            $t3 = $resolveTier($SUPPLIER, [$SUPPLIER, $SELLER]);
            $this->assert('Ladder T3: supplier+one-side -> HIGH (seller unique to one deal)', $t3['tier'] === $HIGH && (int) $t3['deal_id'] === $dealId, "tier={$t3['tier']} deal=" . var_export($t3['deal_id'], true));

            $t4 = $resolveTier($SELLER, [$SELLER]);
            $this->assert('Ladder T4: single party -> LOW', $t4['tier'] === $LOW && (int) $t4['deal_id'] === $dealId, "tier={$t4['tier']} deal=" . var_export($t4['deal_id'], true));

            $tok = $resolveTier($SUPPLIER, [$SUPPLIER], "Re: documents [CX-D{$dealId}]");
            $this->assert('Ladder TOKEN: [CX-D###] -> HIGH', $tok['tier'] === $HIGH && (int) $tok['deal_id'] === $dealId && $tok['signal_type'] === 'cx_token', "tier={$tok['tier']} deal=" . var_export($tok['deal_id'], true) . " signal={$tok['signal_type']}");

            $none = $resolveTier($STRANGER, [$STRANGER], 'Totally unrelated subject');
            $this->assert('Ladder NO-PARTY: unrelated -> LOW, no deal', $none['tier'] === $LOW && $none['deal_id'] === null, "tier={$none['tier']} deal=" . var_export($none['deal_id'], true));

            // ── G1=A GATE ──────────────────────────────────────────────────────
            $this->assert('Gate: seller present -> PARK', $filing->hasKnownParty([$SELLER], $agencyId), 'hasKnownParty([seller])');
            $this->assert('Gate: supplier present -> PARK', $filing->hasKnownParty([$SUPPLIER], $agencyId), 'hasKnownParty([supplier])');
            $this->assert('Gate: party in CC, unknown sender -> PARK', $filing->hasKnownParty([$STRANGER, $SELLER], $agencyId), 'hasKnownParty([stranger, seller])');
            $this->assert('Gate: random only -> DROP', ! $filing->hasKnownParty([$STRANGER, 'nobody@nowhere.test'], $agencyId), 'hasKnownParty([random...]) == false');

            // ── COMPOUNDING WIN ────────────────────────────────────────────────
            $subject = 'Guarantees for 1 LadderCheck Road Unit 7';
            $commA = $this->mkComm($agencyId, $SUPPLIER, [$SELLER], $subject);
            $filing->park($commA, $this->msg($SUPPLIER, [$SELLER], $subject), $resolver->resolveSender($SUPPLIER, $agencyId) ?? ['provider' => null, 'contact' => null]);
            $suspA = CommunicationFilingSuspense::where('communication_id', $commA->id)->first();
            $this->assert('Compounding: first mail parks with a suggestion', $suspA && (int) $suspA->suggested_deal_id === $dealId && $suspA->matched_signal_type === 'subject_exact', 'suggested=' . ($suspA->suggested_deal_id ?? '-') . " signal=" . ($suspA->matched_signal_type ?? '-'));

            $filing->verify($suspA, $dealId, $user);
            $learned = DB::table('communication_learned_refs')->where('agency_id', $agencyId)
                ->where('signal_type', 'subject_exact')->where('deal_id', $dealId)->where('is_verified', 1)->exists();
            $this->assert('Compounding: verify learns the subject_exact ref', $learned, 'learned_ref(subject_exact -> deal) exists & verified');

            $b = $resolveTier($STRANGER, [], $subject);
            $this->assert('Compounding: identical subject next mail AUTO-files', $b['tier'] === $AUTO && (int) $b['deal_id'] === $dealId, "tier={$b['tier']} deal=" . var_export($b['deal_id'], true));

            $c = $resolveTier($STRANGER, [], 'Re: ' . $subject);
            $this->assert('Compounding: "Re:" mutation FALLS BACK (not auto)', $c['tier'] !== $AUTO, "tier={$c['tier']} deal=" . var_export($c['deal_id'], true));

            // ── SHARED STATE ───────────────────────────────────────────────────
            $ext = 'ladderchk:' . bin2hex(random_bytes(5));
            $this->mkComm($agencyId, $SUPPLIER, [$SELLER], 'dup-key test', $ext);
            $dupCommThrew = false;
            try {
                $this->mkComm($agencyId, $SUPPLIER, [$SELLER], 'dup-key test 2', $ext);
            } catch (\Throwable $e) {
                $dupCommThrew = true;
            }
            $this->assert('Shared-state: one email = one Communication (agency+Message-ID unique)', $dupCommThrew, 'a 2nd comm with the same (agency, external_id) is rejected');

            $dupSuspThrew = false;
            try {
                CommunicationFilingSuspense::create([
                    'agency_id' => $agencyId, 'communication_id' => $commA->id, 'channel' => 'email',
                    'status' => CommunicationFilingSuspense::STATUS_PENDING,
                ]);
            } catch (\Throwable $e) {
                $dupSuspThrew = true; // commA already has a suspense row (from park above)
            }
            $this->assert('Shared-state: one email = one suspense row (agency+communication_id unique)', $dupSuspThrew, 'a 2nd suspense row for the same communication is rejected');

            // Double-approve: verifying an already-verified row no-ops (first approval wins).
            $before = $suspA->fresh()->status;
            $filing->verify($suspA->fresh(), $dealId, $user); // 2nd verify — must not throw / re-file
            $after = $suspA->fresh()->status;
            $this->assert('Shared-state: double-approve guarded (2nd verify no-ops)', $before === CommunicationFilingSuspense::STATUS_VERIFIED && $after === $before, "status before/after 2nd verify = {$before}/{$after}");
        } catch (\Throwable $e) {
            $this->assert('HARNESS ran without error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            DB::rollBack(); // nothing persists — the whole fixture + all writes are discarded
        }

        // ── SUMMARY ────────────────────────────────────────────────────────────
        $this->newLine();
        $this->line('=== COMMS LADDER / SUSPENSE REGRESSION CHECK ===');
        $fail = 0;
        foreach ($this->results as [$name, $pass, $art]) {
            $this->line(($pass ? '<info>[PASS]</info>' : '<error>[FAIL]</error>') . " {$name}");
            $this->line("        {$art}");
            if (! $pass) {
                $fail++;
            }
        }
        $this->newLine();
        $this->line($fail === 0
            ? '<info>ALL ' . count($this->results) . ' PASS</info> (disposable data rolled back — nothing real touched)'
            : "<error>{$fail} FAILED</error> (disposable data rolled back — nothing real touched)");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function mkComm(int $agencyId, string $from, array $participants, string $subject, ?string $ext = null): Communication
    {
        return Communication::create([
            'agency_id' => $agencyId, 'channel' => Communication::CHANNEL_EMAIL, 'direction' => Communication::DIRECTION_INBOUND,
            'external_id' => $ext ?? ('ladderchk:' . bin2hex(random_bytes(6))), 'subject' => $subject,
            'from_identifier' => $from, 'participant_identifiers' => $participants, 'body' => 'body',
            'captured_at' => now(), 'occurred_at' => now(), 'thread_key' => '',
        ]);
    }

    /** @return array<string,mixed> the $msg shape CorrespondenceMatchService::resolve expects. */
    private function msg(string $from, array $participants, string $subject): array
    {
        return ['subject' => $subject, 'body_text' => 'body', 'counterpart' => $from, 'participants' => $participants, 'thread_key' => ''];
    }

    private function assert(string $name, bool $pass, string $artifact): void
    {
        $this->results[] = [$name, $pass, $artifact];
    }
}
