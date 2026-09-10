<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\Outreach\OutreachQueueService;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use App\Services\SellerOutreach\SellerOutreachSenderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Webinar day 2026-09-03 (cc-coordinator escalation) — "Seller Outreach
 * sends = 0 / Outreach Queue = 0" despite stage3_claimsAndPitches containing
 * real SellerOutreachComposerService/SenderService calls.
 *
 * ROOT CAUSE (confirmed by direct reproduction, not guessed): demo's
 * `seller_outreach_templates` table had ZERO rows. SellerOutreachComposer
 * Service::composeContext() falls back to an EMPTY body when no template
 * resolves ($template?->body ?? ''), which can never contain the required
 * {tracking_link} merge token. isSendable() then deterministically fails
 * validation ('no_tracking_link') for every single pitch, every time — and
 * stage3's own `if (!$ctx->isSendable()) continue;` skips it SILENTLY: no
 * exception thrown, no warning logged, nothing visible anywhere. This is
 * why cc6 found 0 rows but no error trail to follow.
 *
 * NOT a Bus::fake()/Queue::fake() issue — SellerOutreachSenderService::send()
 * is fully synchronous, no dispatch/queue involved anywhere in the path.
 * NOT a later stage wiping rows — the rows were simply never created.
 *
 * Fix, two parts:
 *   1. SellerOutreachTemplatesSeeder + HfcConsentTemplatesSeeder (both
 *      pre-existing, unchanged, verified byte-identical to Staging) are now
 *      wired into DemoDataSeeder as stage2c, running before stage3 — so the
 *      NEXT full pipeline run will populate this correctly on its own.
 *   2. `demo:seed` itself is a ONE-TIME bootstrap command (Stage 1 does an
 *      unconditional INSERT of admin@demo.corexos.co.za with no existence
 *      check) — it cannot be re-run against an already-seeded database to
 *      validate the fix (confirmed: it crashes on a duplicate-key the
 *      instant it's attempted). This seeder is the self-contained
 *      equivalent of stage3's outreach loop — same real composer/sender
 *      calls, own dedicated seller/property pairs — so the Outreach Queue
 *      and Seller Outreach screens have real, correct data TONIGHT without
 *      needing a full pipeline re-run.
 *
 * Idempotent: archives-then-recreates by a stable marker in the seller
 * contact's notes field.
 */
final class DemoSellerOutreachSeeder
{
    private const MARKER = '[DEMO-OUTREACH-BATCH]';

    public function run(int $agencyId): array
    {
        $this->archivePriorBatch($agencyId);

        $branchIds = DB::table('branches')->where('agency_id', $agencyId)->whereNull('deleted_at')->pluck('id')->all();
        $agentIds = DB::table('users')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereIn('role', ['agent', 'branch_manager'])->orderBy('id')->pluck('id')->all();
        $adminId = DB::table('users')->where('agency_id', $agencyId)->where('role', 'admin')->value('id');

        if (empty($branchIds) || empty($agentIds)) {
            return ['sends' => 0, 'note' => "Skipped — agency {$agencyId} lacks branches or agents."];
        }

        $suburbs = ['Uvongo', 'Margate', 'Ramsgate', 'Southbroom', 'Shelly Beach', 'St Michaels-on-Sea', 'Port Shepstone'];
        $firstNames = ['Pieter', 'Thandi', 'Greg', 'Naledi', 'Riaan', 'Zola', 'Bongani', 'Chantal'];
        $lastNames = ['Naidoo', 'Coetzee', 'Mthembu', 'Fourie', 'Sibeko', 'Van Wyk', 'Dlamini'];

        $composer = app(SellerOutreachComposerService::class);
        $sender = app(SellerOutreachSenderService::class);

        $sends = 0;
        $skipped = 0;
        $total = 22;

        for ($i = 0; $i < $total; $i++) {
            $branchId = $branchIds[$i % count($branchIds)];
            $agentId = $agentIds[$i % count($agentIds)];
            $agent = User::find($agentId);
            $suburb = $suburbs[$i % count($suburbs)];
            $beds = 2 + ($i % 3);

            $sellerId = DB::table('contacts')->insertGetId([
                'agency_id' => $agencyId,
                'branch_id' => $branchId,
                'created_by_user_id' => $agentId,
                'first_name' => '[DEMO] ' . $firstNames[$i % count($firstNames)],
                'last_name' => $lastNames[$i % count($lastNames)],
                'phone' => '07' . random_int(10000000, 99999999),
                'email' => 'seller' . Str::random(6) . '@example.com',
                'is_buyer' => 0,
                'notes' => self::MARKER,
                'messaging_opt_out_at' => null,
                'loaded_at' => now()->subDays(random_int(5, 60)),
                'modified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $propertyId = DB::table('properties')->insertGetId([
                'external_id' => (string) Str::uuid(),
                'agency_id' => $agencyId,
                'branch_id' => $branchId,
                'agent_id' => $agentId,
                'title' => self::MARKER . " {$beds} Bed House in {$suburb}",
                'address' => random_int(1, 200) . ' Coastal Way, ' . $suburb,
                'suburb' => $suburb,
                'city' => $suburb,
                'province' => 'KwaZulu-Natal',
                'property_type' => 'House',
                'category' => 'residential',
                'listing_type' => 'sale',
                'mandate_type' => 'sole',
                'status' => 'draft',
                'beds' => $beds,
                'baths' => max(1, $beds - 1),
                'garages' => 1,
                'price' => 1_200_000 + ($beds * 350_000),
                'is_demo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $contact = Contact::find($sellerId);
            $property = Property::find($propertyId);
            $channel = $i % 4 === 0 ? 'email' : 'whatsapp';

            try {
                $ctx = $composer->composeContext($agencyId, $contact, $property, $channel, null, $agent);
                if (! $ctx->isSendable()) {
                    $skipped++;
                    continue;
                }

                // Last quarter of the batch goes to the OUTREACH QUEUE (not yet
                // sent) instead of being sent immediately — a real agency has
                // pending pitches waiting for an agent to dispatch, not just a
                // sent log. Same real service (OutreachQueueService), not a
                // fabricated row shape.
                if ($i >= $total - 6) {
                    $queueSvc = app(OutreachQueueService::class);
                    $agencyModel = Agency::find($agencyId);
                    $res = $queueSvc->enqueue($agencyModel, $contact, $agent, $channel, 'contact', $ctx->renderedBody, $property);
                    if ($res['ok']) {
                        $sends++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                $send = $sender->send($ctx);
                DB::table('seller_outreach_sends')->where('id', $send->id)->update([
                    'sent_at' => now()->subDays(random_int(0, 35)),
                ]);
                $sends++;
            } catch (\Throwable $e) {
                $skipped++;
            }
        }

        return ['sends' => $sends, 'skipped' => $skipped];
    }

    private function archivePriorBatch(int $agencyId): void
    {
        DB::transaction(function () use ($agencyId) {
            $now = now();
            $sellerIds = DB::table('contacts')
                ->where('agency_id', $agencyId)
                ->where('notes', self::MARKER)
                ->whereNull('deleted_at')
                ->pluck('id');

            if ($sellerIds->isEmpty()) {
                return;
            }

            $sendIds = DB::table('seller_outreach_sends')
                ->whereIn('contact_id', $sellerIds)
                ->whereNull('deleted_at')
                ->pluck('id');
            if ($sendIds->isNotEmpty()) {
                DB::table('seller_outreach_sends')->whereIn('id', $sendIds)
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }

            $queueIds = DB::table('outreach_queue')
                ->whereIn('contact_id', $sellerIds)
                ->whereNull('deleted_at')
                ->pluck('id');
            if ($queueIds->isNotEmpty()) {
                DB::table('outreach_queue')->whereIn('id', $queueIds)
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }

            $propertyIds = DB::table('properties')
                ->where('agency_id', $agencyId)
                ->where('title', 'like', self::MARKER . '%')
                ->whereNull('deleted_at')
                ->pluck('id');
            if ($propertyIds->isNotEmpty()) {
                DB::table('properties')->whereIn('id', $propertyIds)
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }

            DB::table('contacts')->whereIn('id', $sellerIds)
                ->update(['deleted_at' => $now, 'updated_at' => $now]);
        });
    }
}
