<?php

namespace App\Console\Commands\Auctions;

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\AuctionLotViewing;
use App\Models\Property;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * AT-432 — demo auctions so the Auctions screens, the public advert and the
 * Auctions → Properties lens can be seen with realistic content.
 *
 * Creates DEDICATED "[DEMO]" properties — it never touches a real listing —
 * plus four auctions (upcoming in-room with outside auctioneer + PDFs, online
 * timed, a completed one with results, and an unpublished draft that shows the
 * publish checks). Re-running is safe: nothing is duplicated. `--purge`
 * archives (soft-deletes) everything this command made and frees the
 * references so it can be seeded again. No hard deletes.
 */
class SeedDemoAuctions extends Command
{
    protected $signature = 'auctions:seed-demo {--agency=1 : Agency id} {--purge : Archive the demo auctions + properties instead}';
    protected $description = 'Create (or --purge) demo auctions, lots and sample PDFs for an agency.';

    public function handle(): int
    {
        $agencyId = (int) $this->option('agency');

        return $this->option('purge') ? $this->purge($agencyId) : $this->seed($agencyId);
    }

    private function seed(int $agencyId): int
    {
        // Archived (purged) copies carry a '~purged-…' suffix and do not count — that is what lets a purge be re-seeded.
        if (Auction::withTrashed()->withoutGlobalScopes()->where('agency_id', $agencyId)->where('reference', 'like', 'DEMO-AUC-%')->where('reference', 'not like', '%~purged-%')->exists()) {
            $this->warn('Demo auctions already exist for this agency. Run with --purge first to start again.');
            return self::SUCCESS;
        }

        $agent = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true)
            ->where('role', 'agent')->whereNotNull('ffc_expiry_date')->where('ffc_expiry_date', '>', now())->orderBy('id')->first()
            ?? User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true)->where('role', '!=', 'super_admin')->orderBy('id')->first();
        if (! $agent) {
            $this->error('No usable agent found for that agency.');
            return self::FAILURE;
        }
        $branchId = $agent->branch_id;

        $docs = $this->pdfs($agencyId);

        // ── Properties (all clearly [DEMO]) ─────────────────────────────
        $p = fn (string $title, array $f) => Property::create(array_merge([
            'title' => '[DEMO] '.$title, 'agency_id' => $agencyId, 'agent_id' => $agent->id, 'branch_id' => $branchId,
            'listing_type' => 'sale', 'status' => 'on_auction', 'sale_method' => 'auction', 'province' => 'KwaZulu-Natal',
            'description' => "Demonstration listing created for the Auctions preview — not a real property.\n\nSolid construction, secure, close to schools and the beach. Sold voetstoots to the highest bidder, subject to the Conditions of Sale.",
        ], $f));

        $villa = $p('Seafront Villa', ['street_number' => '14', 'street_name' => 'Ocean View Drive', 'suburb' => 'Margate', 'city' => 'Margate', 'property_type' => 'House', 'beds' => 4, 'baths' => 3, 'garages' => 2, 'price' => 3850000, 'size_m2' => 320, 'erf_size_m2' => 1050]);
        $flat  = $p('Modern Apartment', ['street_number' => '8', 'street_name' => 'Marine Parade', 'suburb' => 'Uvongo', 'city' => 'Uvongo', 'property_type' => 'Apartment', 'beds' => 2, 'baths' => 2, 'garages' => 1, 'price' => 1450000, 'size_m2' => 96]);
        $home  = $p('Family Home', ['street_number' => '3', 'street_name' => 'Protea Road', 'suburb' => 'Ramsgate', 'city' => 'Ramsgate', 'property_type' => 'House', 'beds' => 3, 'baths' => 2, 'garages' => 2, 'price' => 2200000, 'size_m2' => 210, 'erf_size_m2' => 800]);
        $land  = $p('Vacant Land — 1 200 m²', ['street_name' => 'Erf 4412', 'suburb' => 'Shelly Beach', 'city' => 'Shelly Beach', 'property_type' => 'Vacant Land', 'beds' => 0, 'baths' => 0, 'garages' => 0, 'price' => 650000, 'erf_size_m2' => 1200]);
        $pent  = $p('Beachfront Penthouse', ['street_number' => '1', 'street_name' => 'Beach Road', 'suburb' => 'Southbroom', 'city' => 'Southbroom', 'property_type' => 'Apartment', 'beds' => 3, 'baths' => 3, 'garages' => 2, 'price' => 6900000, 'size_m2' => 240]);
        $dup   = $p('Sea-View Duplex', ['street_number' => '22', 'street_name' => 'Hill Street', 'suburb' => 'Port Edward', 'city' => 'Port Edward', 'property_type' => 'House', 'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 2950000, 'size_m2' => 180]);
        $sold  = $p('Corner Stand House', ['street_number' => '5', 'street_name' => 'Main Road', 'suburb' => 'Hibberdene', 'city' => 'Hibberdene', 'property_type' => 'House', 'status' => 'sold', 'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1720000, 'size_m2' => 150]);
        $pin   = $p('Townhouse', ['street_number' => '17', 'street_name' => 'Sunset Close', 'suburb' => 'Pennington', 'city' => 'Pennington', 'property_type' => 'Apartment', 'status' => 'active', 'beds' => 2, 'baths' => 1, 'garages' => 1, 'price' => 1100000, 'size_m2' => 88]);
        $wd    = $p('Cottage', ['street_number' => '9', 'street_name' => 'Rose Lane', 'suburb' => 'Umtentweni', 'city' => 'Umtentweni', 'property_type' => 'House', 'status' => 'active', 'beds' => 2, 'baths' => 1, 'garages' => 1, 'price' => 980000, 'size_m2' => 110]);
        $draft = $p('Garden Estate Home (draft auction)', ['street_number' => '40', 'street_name' => 'Kingfisher Way', 'suburb' => 'Scottburgh', 'city' => 'Scottburgh', 'property_type' => 'House', 'status' => 'active', 'beds' => 4, 'baths' => 3, 'garages' => 2, 'price' => 3100000, 'size_m2' => 280]);

        $mk = fn (array $f) => Auction::create(array_merge([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'created_by_id' => $agent->id,
        ], $f));
        $ext = ['auctioneer_kind' => 'external', 'auctioneer_company' => 'Coastal Auctioneers (Pty) Ltd', 'auctioneer_licence_no' => 'AUC/KZN/2291',
                'auctioneer_phone' => '039 312 0000', 'auctioneer_email' => 'auctions@coastal-auctioneers.example', 'external_registration_url' => 'https://example.com/register-to-bid'];

        // A — upcoming in-room, outside auctioneer, PDFs, 4 lots.
        $a = $mk($ext + [
            'reference' => 'DEMO-AUC-001', 'title' => 'South Coast Property Auction', 'bidding_mode' => 'in_room',
            'starts_at' => now()->addDays(21)->setTime(10, 0), 'registration_closes_at' => now()->addDays(20)->setTime(12, 0),
            'venue_name' => 'Margate Country Club', 'venue_address' => '1 Golf Course Road, Margate, KwaZulu-Natal',
            'status' => 'registration_open', 'catalogue_published_at' => now()->subDays(2),
            'notes' => "Four quality properties go under the hammer on the South Coast.\nRegistration is required — bring your ID and proof of address.",
        ] + $docs);
        // B — online timed, our own auctioneer.
        $b = $mk([
            'reference' => 'DEMO-AUC-002', 'title' => 'Online Timed Auction — Penthouse & Duplex', 'bidding_mode' => 'online',
            'auctioneer_kind' => 'internal', 'auctioneer_user_id' => $agent->id, 'auctioneer_phone' => '039 312 1111',
            'starts_at' => now()->addDays(10)->setTime(9, 0), 'ends_at' => now()->addDays(12)->setTime(17, 0),
            'registration_closes_at' => now()->addDays(9)->setTime(17, 0), 'is_online_streamed' => false,
            'status' => 'registration_open', 'catalogue_published_at' => now()->subDay(),
        ] + ['conditions_file_path' => $docs['conditions_file_path'], 'conditions_file_name' => $docs['conditions_file_name']]);
        // C — completed, with results.
        $c = $mk($ext + [
            'reference' => 'DEMO-AUC-003', 'title' => 'September Auction (completed)', 'bidding_mode' => 'in_room',
            'starts_at' => now()->subDays(20)->setTime(10, 0), 'venue_name' => 'Margate Country Club', 'venue_address' => '1 Golf Course Road, Margate',
            'status' => 'closed', 'catalogue_published_at' => now()->subDays(40),
        ] + $docs);
        // D — unpublished draft (shows the publish checks).
        $d = $mk($ext + [
            'reference' => 'DEMO-AUC-004', 'title' => 'December Auction (draft — not yet published)', 'bidding_mode' => 'in_room',
            'starts_at' => now()->addDays(45)->setTime(10, 0), 'venue_name' => 'Margate Country Club', 'status' => 'draft',
        ]);

        $lot = fn (Auction $au, Property $pr, int $n, array $f) => AuctionLot::create(array_merge([
            'agency_id' => $agencyId, 'auction_id' => $au->id, 'property_id' => $pr->id, 'lot_number' => $n, 'status' => 'catalogued',
        ], $f));

        $l1 = $lot($a, $villa, 1, ['reserve_price' => 3200000, 'guide_price_min' => 3400000, 'guide_price_max' => 3800000, 'opening_bid' => 2800000, 'bid_increment' => 50000]);
        $l2 = $lot($a, $flat,  2, ['reserve_price' => 1100000, 'guide_price_min' => 1200000, 'guide_price_max' => 1400000, 'opening_bid' => 900000, 'bid_increment' => 25000]);
        $l3 = $lot($a, $home,  3, ['reserve_price' => 0,       'guide_price_min' => 1800000, 'guide_price_max' => 2100000, 'opening_bid' => 1500000, 'bid_increment' => 25000]);
        $l4 = $lot($a, $land,  4, ['reserve_price' => 450000,  'guide_price_min' => 500000,  'guide_price_max' => 600000,  'opening_bid' => 350000, 'bid_increment' => 10000]);
        $lot($b, $pent, 1, ['reserve_price' => 5800000, 'guide_price_min' => 6000000, 'guide_price_max' => 6800000, 'opening_bid' => 5000000, 'bid_increment' => 100000, 'online_closes_at' => now()->addDays(12)->setTime(17, 0)]);
        $lot($b, $dup,  2, ['reserve_price' => 2400000, 'guide_price_min' => 2500000, 'guide_price_max' => 2900000, 'opening_bid' => 2000000, 'bid_increment' => 50000, 'online_closes_at' => now()->addDays(12)->setTime(17, 0)]);
        $lot($c, $sold, 1, ['status' => 'sold', 'reserve_price' => 1500000, 'guide_price_min' => 1500000, 'guide_price_max' => 1700000, 'hammer_price' => 1720000, 'hammer_at' => now()->subDays(20)->setTime(10, 40), 'reserve_met' => true]);
        $lot($c, $pin,  2, ['status' => 'passed_in', 'reserve_price' => 1000000, 'guide_price_min' => 1000000, 'guide_price_max' => 1150000, 'passed_in_at' => now()->subDays(20)->setTime(11, 5), 'reserve_met' => false]);
        $lot($c, $wd,   3, ['status' => 'withdrawn', 'reserve_price' => 900000, 'guide_price_min' => 900000, 'guide_price_max' => 1000000, 'withdrawn_reason' => 'Withdrawn by the seller before the sale.']);
        $lot($d, $draft, 1, ['status' => 'draft', 'guide_price_min' => 2900000, 'guide_price_max' => 3300000]);   // no reserve stated on purpose — the publish check will say so

        // Viewings for the upcoming in-room lots.
        foreach ([$l1, $l2, $l3, $l4] as $i => $l) {
            foreach ([7, 14] as $days) {
                AuctionLotViewing::create(['agency_id' => $agencyId, 'auction_lot_id' => $l->id, 'agent_id' => $agent->id,
                    'starts_at' => now()->addDays($days)->setTime(10 + $i % 2, 0), 'ends_at' => now()->addDays($days)->setTime(12 + $i % 2, 0), 'is_by_appointment' => false,
                    'notes' => $days === 7 ? 'Open viewing' : 'Final open viewing']);
            }
        }

        $this->info('Demo data created: 4 auctions (DEMO-AUC-001…004), 10 [DEMO] properties, sample Rules/Conditions PDFs.');
        $this->line('Agent used for the demo listings: '.$agent->name.' (FFC valid).');
        $this->line('Public page: '.url('/auction-catalogue/'.$a->id));
        return self::SUCCESS;
    }

    private function purge(int $agencyId): int
    {
        $stamp = now()->format('YmdHis');
        $n = 0;
        foreach (Auction::withoutGlobalScopes()->where('agency_id', $agencyId)->where('reference', 'like', 'DEMO-AUC-%')->where('reference', 'not like', '%~purged-%')->get() as $au) {
            AuctionLot::withoutGlobalScopes()->where('auction_id', $au->id)->get()->each(function ($lot) {
                AuctionLotViewing::withoutGlobalScopes()->where('auction_lot_id', $lot->id)->get()->each->delete();
                $lot->delete();
            });
            $au->reference = $au->reference.'~purged-'.$stamp;   // frees the unique reference; the row stays archived
            $au->saveQuietly();
            $au->delete();
            $n++;
        }
        $props = Property::withoutGlobalScopes()->where('agency_id', $agencyId)->where('title', 'like', '[DEMO] %')->whereNull('deleted_at')->get();
        $props->each->delete();
        foreach (["auctions/{$agencyId}/rules/demo-rules-of-auction.pdf", "auctions/{$agencyId}/conditions/demo-conditions-of-sale.pdf"] as $f) {
            Storage::disk('local')->delete($f);
        }
        $this->info("Archived $n demo auction(s) and ".$props->count().' demo propert(ies).');
        return self::SUCCESS;
    }

    /** Writes the two sample PDFs and returns the auction columns that point at them. */
    private function pdfs(int $agencyId): array
    {
        $rules = [
            'RULES OF AUCTION (DEMONSTRATION COPY)', '',
            'This document is sample text for previewing CoreX only. It is not legal advice and',
            'must be replaced by your attorney-approved Rules of Auction before any real sale.', '',
            '1. The auction is conducted in accordance with the Consumer Protection Act 68 of 2008.',
            '2. Every bidder must register, present valid identification and proof of address,',
            '   and receive a bidding paddle before bidding.',
            '3. The auctioneer may refuse any bid, and may regulate the bidding increments.',
            '4. Each lot is sold subject to a reserve price unless announced as "without reserve".',
            '5. A sale is complete at the fall of the hammer.',
            '6. No bids may be placed on behalf of the seller unless the auctioneer announces this.',
            '7. The auctioneer\'s decision on any dispute during the sale is final.',
        ];
        $conditions = [
            'CONDITIONS OF SALE (DEMONSTRATION COPY)', '',
            'Sample text for previewing CoreX only. Replace with attorney-approved conditions.', '',
            '1. The property is sold voetstoots, as it stands, to the highest bidder.',
            '2. The purchaser pays the purchase price and the auctioneer\'s fee as stated in the catalogue.',
            '3. A deposit is payable immediately after the fall of the hammer.',
            '4. The balance is payable on registration of transfer, secured by a bank guarantee',
            '   within the period stated in the catalogue.',
            '5. Transfer is effected by the conveyancer appointed by the seller.',
            '6. Occupation and risk pass to the purchaser as set out in the Memorandum of Agreement.',
        ];
        $disk = Storage::disk('local');
        $disk->put("auctions/{$agencyId}/rules/demo-rules-of-auction.pdf", self::simplePdf($rules));
        $disk->put("auctions/{$agencyId}/conditions/demo-conditions-of-sale.pdf", self::simplePdf($conditions));

        // Run from the CLI (often as root), the folders it just made are not readable by the
        // web server's group — the public document link would 404. Give the web group read access.
        $root = $disk->path("auctions");
        @shell_exec('chgrp -R www-data '.escapeshellarg($root).' 2>/dev/null; chmod -R g+rX '.escapeshellarg($root).' 2>/dev/null');

        return [
            'rules_file_path' => "auctions/{$agencyId}/rules/demo-rules-of-auction.pdf", 'rules_file_name' => 'Rules of Auction (demo).pdf',
            'conditions_file_path' => "auctions/{$agencyId}/conditions/demo-conditions-of-sale.pdf", 'conditions_file_name' => 'Conditions of Sale (demo).pdf',
        ];
    }

    /** A minimal, valid single-page text PDF (Helvetica). Enough for a demo; real documents come from the agency. */
    public static function simplePdf(array $lines): string
    {
        $esc = fn (string $t) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
        $stream = "BT\n/F1 16 Tf\n56 780 Td\n18 TL\n";
        foreach ($lines as $i => $line) {
            if ($i === 1) { $stream .= "/F1 11 Tf\n"; }
            $stream .= '('.$esc($line).") Tj T*\n";
        }
        $stream .= "ET";
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
        ];
        $pdf = "%PDF-1.4\n"; $offsets = [];
        foreach ($objs as $i => $o) { $offsets[$i + 1] = strlen($pdf); $pdf .= ($i + 1)." 0 obj\n".$o."\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) { $pdf .= sprintf("%010d 00000 n \n", $off); }
        $pdf .= "trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return $pdf;
    }
}
