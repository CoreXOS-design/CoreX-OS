<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\AI\EllieReferenceSource;
use App\Services\AI\EllieReferenceSourceFetchService;

/**
 * Ellie orphan-list fix (2026-09-03, cc6's risk list, coordinator-assigned) —
 * /admin/ellie/reference-sources rendered "No reference sources." — its own
 * bad look, independent of whether Ellie itself is switched on (Ellie is
 * being left OFF per Johan's decision; this is a data-only fix that stands
 * on its own).
 *
 * `ellie_reference_sources` is a GLOBAL, platform-wide table (no agency_id
 * column) — this is the single admin-approved allowlist every agency's
 * Ellie searches, not a per-agency setting. Seeded with real, legitimate
 * South African property-industry regulatory/reference sites — the kind of
 * external pages a real agency would actually want Ellie able to check
 * (PPRA, SARS transfer duty, CSOS, Rental Housing Tribunal, FIC) — genuine
 * URLs, not placeholders.
 *
 * Runs the SAME production fetch pipeline the admin "Add" button uses
 * (EllieReferenceSourceFetchService::refresh() — fetch → extract → chunk →
 * embed), so the screen shows real fetch status and real chunk counts, not
 * a bare URL with 0 chunks. The fetch pipeline has no dependency on the
 * Anthropic API key (it's a plain HTTP fetch + text chunker; embedding
 * failure degrades to has_embedding=false per chunk rather than failing the
 * whole source — confirmed by reading EllieReferenceSourceFetchService::
 * refresh()), so this works whether or not Ellie's own API key is ever set.
 *
 * IDEMPOTENT BY CONSTRUCTION — skips any URL that already exists (same
 * guard EllieReferenceSourceController::store() uses).
 */
class DemoEllieReferenceSourcesSeeder
{
    private const SOURCES = [
        ['url' => 'https://www.ppra.org.za', 'title' => 'PPRA — Property Practitioners Regulatory Authority'],
        ['url' => 'https://www.sars.gov.za/tax-rates/transfer-duty/', 'title' => 'SARS — Transfer Duty Rates'],
        ['url' => 'https://www.csos.org.za', 'title' => 'CSOS — Community Schemes Ombud Service'],
        ['url' => 'https://www.fic.gov.za', 'title' => 'FIC — Financial Intelligence Centre (FICA guidance)'],
        ['url' => 'https://www.ncr.org.za', 'title' => 'NCR — National Credit Regulator'],
    ];

    /** @return array{added:int, indexed:int, note:string} */
    public function run(): array
    {
        $fetcher = app(EllieReferenceSourceFetchService::class);

        $added = 0;
        $indexed = 0;

        foreach (self::SOURCES as $row) {
            if (EllieReferenceSource::where('url', $row['url'])->exists()) {
                continue;
            }

            $source = EllieReferenceSource::create([
                'url'              => $row['url'],
                'title'            => $row['title'],
                'added_by_user_id' => 1, // Demo Administrator
                'is_active'        => true,
            ]);
            $added++;

            $fetcher->refresh($source);
            $source->refresh();

            if ($source->last_fetch_status === EllieReferenceSource::STATUS_OK) {
                $indexed++;
            }
        }

        $note = "Ellie reference sources: +{$added} added, {$indexed} fetched+indexed successfully.";

        return ['added' => $added, 'indexed' => $indexed, 'note' => $note];
    }
}
