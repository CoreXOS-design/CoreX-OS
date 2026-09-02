<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Contact;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo deeds-capture data — so a demo walkthrough never needs a real
 * TVA/deeds-office scrape to show a populated Deeds Capture screen.
 *
 * Stamps a batch of EXISTING tracked_properties rows with realistic deeds
 * fields (erf, title deed number, sale price/date, bond, sale type) and
 * links each to a BRAND NEW, wholly fictional owner Contact — never a real
 * or borrowed identity. Bank names (bond_holder) are real SA banks, which is
 * fine — that's not personal data.
 *
 * IDEMPOTENT BY CONSTRUCTION (same shape as DemoMicEnrichmentSeeder's
 * top-up pattern, and DemoSchemeOwnersSeeder's firstOrCreate guard):
 *   - Only tracked_properties WITHOUT capture_kind='deeds_capture' are
 *     eligible, picked deterministically (orderBy id), up to the shortfall
 *     against the target. Once the target is met, a re-run finds 0 rows
 *     left to touch.
 *   - The owner Contact per row is created via firstOrCreate keyed on a
 *     deterministic (agency_id, email) pair derived from the
 *     tracked_property's own id — re-running can never produce a second
 *     contact for the same row.
 *   - Every write is an UPDATE of an existing tracked_properties row or an
 *     INSERT of exactly one new Contact. Nothing is ever deleted.
 */
class DemoDeedsSeeder extends Seeder
{
    private const TARGET_TOTAL = 20;
    /** Of the target, how many get EVERY field populated (a "textbook perfect" showcase example). */
    private const TARGET_FULL = 5;
    /** Of the target, how many are sectional title (scheme_name/number/section set). */
    private const TARGET_SECTIONAL = 4;

    private const DEEDS_OFFICE = 'PIETERMARITZBURG';
    private const SALE_TYPES = ['PRIVATE TREATY', 'PRIVATE TREATY', 'PRIVATE TREATY', 'COURT ORDER'];
    private const BOND_HOLDERS = ['ABSA BANK', 'STANDARD BANK', 'NEDBANK', 'FIRSTRAND BANK (FNB)', 'INVESTEC BANK'];
    private const SCHEME_NAMES = ['Sea Breeze Villas', 'Dolphin Court', 'Whale Rock Estate', 'Sunset Palms Complex'];

    /** @return array{deeds_captured:int, owners_created:int, note?:string} */
    public function run(int $agencyId = 1): array
    {
        // whereNull('promoted_to_property_id') matters: DeedsCaptureController@index
        // is a SUSPENSE screen — it excludes any capture already promoted to real
        // stock (found via testing: seeding onto already-promoted rows produced 20
        // captures the screen correctly hides as "already resolved", i.e. an
        // invisible demo). "Already present" must mean "already present AND
        // visible", or the target check falls permanently short-circuited on rows
        // that can never satisfy the actual ask.
        $already = DB::table('tracked_properties')
            ->where('agency_id', $agencyId)
            ->where('capture_kind', 'deeds_capture')
            ->whereNull('promoted_to_property_id')
            ->whereNull('deleted_at')
            ->count();

        $need = max(0, self::TARGET_TOTAL - $already);
        if ($need === 0) {
            $note = "Deeds: {$already}/" . self::TARGET_TOTAL . ' already present — nothing to do.';
            $this->note($note);
            return ['deeds_captured' => 0, 'owners_created' => 0, 'note' => $note];
        }

        $branchIds = DB::table('branches')->where('agency_id', $agencyId)->whereNull('deleted_at')->pluck('id')->all();
        $userId = DB::table('users')->where('agency_id', $agencyId)
            ->whereIn('role', ['admin', 'agent', 'branch_manager'])
            ->orderBy('id')->value('id');

        if (!$branchIds || !$userId) {
            $note = 'Skipped — agency has no branches/users yet.';
            $this->note($note);
            return ['deeds_captured' => 0, 'owners_created' => 0, 'note' => $note];
        }

        $candidates = DB::table('tracked_properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNull('promoted_to_property_id')
            ->where(function ($q) {
                $q->whereNull('capture_kind')->orWhere('capture_kind', '!=', 'deeds_capture');
            })
            ->orderBy('id')
            ->limit($need)
            ->get(['id', 'suburb', 'town']);

        $captured = 0;
        $ownersCreated = 0;
        $i = $already; // continue the deterministic full/sectional sequence across runs

        foreach ($candidates as $tp) {
            $isFull = $i < self::TARGET_FULL;
            $isSectional = ($i - self::TARGET_FULL) >= 0 && ($i - self::TARGET_FULL) < self::TARGET_SECTIONAL;

            $ownerName = DemoNames::name('demo-deeds-owner-tp-' . $tp->id);
            [$first, $last] = $this->splitName($ownerName);
            $email = 'deeds-owner-' . $tp->id . '@example.com';

            $contact = Contact::firstOrCreate(
                ['agency_id' => $agencyId, 'email' => $email],
                [
                    'branch_id'             => $branchIds[$i % count($branchIds)],
                    'created_by_user_id'    => $userId,
                    'first_name'            => $first,
                    'last_name'             => $last,
                    'phone'                 => $this->fakePhone($tp->id),
                    'id_type'               => 'sa_id',
                    'id_number'             => $this->fakeSaId($tp->id),
                    'id_number_captured_at' => now(),
                    'id_number_source'      => 'demo_seed',
                ]
            );
            if ($contact->wasRecentlyCreated) {
                $ownersCreated++;
            }

            $erf = 100 + ($tp->id % 4000);
            $deedYear = 2015 + ($tp->id % 11);
            $deedSeq = 10000 + ($tp->id % 89999);
            $soldPrice = $this->fakeSoldPrice($tp->id);
            $soldDate = Carbon::create($deedYear, 1 + ($tp->id % 12), 1 + ($tp->id % 27));

            $existingChain = DB::table('tracked_properties')->where('id', $tp->id)->value('source_chain');
            $chain = $existingChain ? (json_decode((string) $existingChain, true) ?: []) : [];
            $chain[] = [
                'type' => 'demo_seed',
                'ref'  => 'deeds-capture-demo',
                'date' => now()->toIso8601String(),
                'fields_contributed' => ['erf_number', 'title_deed_number', 'last_known_sold_price', 'last_known_sold_date'],
            ];

            $update = [
                'capture_kind'              => 'deeds_capture',
                'erf_number'                => (string) $erf,
                'title_deed_number'         => 'T' . $deedSeq . '/' . $deedYear,
                'last_known_sold_price'     => $soldPrice,
                'last_known_sold_date'      => $soldDate->toDateString(),
                'deeds_office'              => self::DEEDS_OFFICE,
                'sale_type'                 => self::SALE_TYPES[$tp->id % count(self::SALE_TYPES)],
                'deeds_registered_date'     => $soldDate->copy()->addDays(30 + ($tp->id % 60))->toDateString(),
                'owner_contact_id'          => $contact->id,
                'source_chain'              => json_encode($chain),
                'deeds_captured_at'         => now(),
                'deeds_captured_by_user_id' => $userId,
                // The index orders by last_enriched_at DESC — bumping it means these
                // freshly-captured demo rows sort to the top, exactly like a real capture.
                'last_enriched_at'          => now(),
                'last_enrichment_source'    => 'demo_seed_deeds_capture',
                'updated_at'                => now(),
            ];

            if ($isFull) {
                $update['cadastral_extent'] = 250 + ($tp->id % 1500);
                $update['municipal_valuation'] = round($soldPrice * 0.85, 2);
                $update['municipal_valuation_year'] = 2025;
                $update['bond_holder'] = self::BOND_HOLDERS[$tp->id % count(self::BOND_HOLDERS)];
                $update['bond_amount'] = round($soldPrice * 0.8, 2);
            }

            if ($isSectional) {
                $update['scheme_name'] = self::SCHEME_NAMES[$tp->id % count(self::SCHEME_NAMES)];
                $update['scheme_number'] = 'SS' . (100 + ($tp->id % 800)) . '/' . $deedYear;
                $update['section_number'] = (string) (1 + ($tp->id % 24));
                $update['section_extent_m2'] = 60 + ($tp->id % 120);
            }

            DB::table('tracked_properties')->where('id', $tp->id)->update($update);
            $captured++;
            $i++;
        }

        $note = "Deeds: +{$captured} tracked_properties captured (+{$ownersCreated} new owner contacts), now "
            . ($already + $captured) . '/' . self::TARGET_TOTAL;
        $this->note($note);

        return ['deeds_captured' => $captured, 'owners_created' => $ownersCreated, 'note' => $note];
    }

    /** "Van der Merwe"-style surnames split correctly — first word is the first name, the rest is the surname. */
    private function splitName(string $full): array
    {
        $parts = explode(' ', trim($full), 2);
        return [$parts[0], $parts[1] ?? 'Demo'];
    }

    /**
     * Structurally-valid-shaped, registry-impossible SA ID — same convention
     * as DemoDataSeeder::DEMO_CO_ID ('0001010000080'): all-zero sequence +
     * fixed citizenship/gender/checksum tail, only the birthdate varies, so
     * every generated ID is instantly recognisable as a placeholder, never
     * mistakable for a real assigned number.
     */
    private function fakeSaId(int $seed): string
    {
        $year = str_pad((string) ($seed % 100), 2, '0', STR_PAD_LEFT);
        $month = str_pad((string) (1 + ($seed % 12)), 2, '0', STR_PAD_LEFT);
        $day = str_pad((string) (1 + ($seed % 28)), 2, '0', STR_PAD_LEFT);
        return $year . $month . $day . '0000080';
    }

    private function fakePhone(int $seed): string
    {
        return '082' . str_pad((string) (1000000 + ($seed * 37) % 8999999), 7, '0', STR_PAD_LEFT);
    }

    private function fakeSoldPrice(int $seed): float
    {
        return (float) (650000 + (($seed * 8191) % 30) * 65000);
    }

    private function note(string $message): void
    {
        $this->command?->info('    ' . $message);
    }
}
