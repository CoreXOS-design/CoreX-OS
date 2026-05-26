<?php

namespace App\Services\CommandCenter\Calendar\Sources;

use App\Contracts\CalendarSourceContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lights up 5 property-domain event classes:
 *   mandate_expiry           — properties.expiry_date
 *   lease_expiry (fallback)  — properties.lease_end_date for properties
 *                              WITHOUT a lease_records row
 *   property_showday         — property_showdays.start_date
 *   portal_listing_expiry    — listing_stocks.expires_at
 *   filed_document_expiry    — document_filing_register.expiry_date
 *                              (excluding mandate types EA/OA)
 *
 * listing_stocks has no property_id FK — resolves agent via user_id,
 * agency via branch_id → branches.agency_id.
 *
 * document_filing_register has no property_id FK — uses property_address
 * string, agent_id, branch_id directly.
 */
class PropertyCalendarSource implements CalendarSourceContract
{
    /** Property statuses considered "on market" for mandate events. */
    private const ACTIVE_STATUSES = ['active', 'for_sale', 'draft', 'to_let'];

    /** Document types that represent mandates — excluded from filed_document_expiry. */
    private const MANDATE_DOC_TYPES = ['EA', 'OA'];

    public function name(): string
    {
        return 'PropertyCalendarSource';
    }

    public function syncAll(): Collection
    {
        return collect()
            ->merge($this->mandateExpiry())
            ->merge($this->leaseExpiryFallback())
            ->merge($this->propertyShowday())
            ->merge($this->portalListingExpiry())
            ->merge($this->filedDocumentExpiry());
    }

    private function mandateExpiry(): Collection
    {
        return DB::table('properties')
            ->whereNull('deleted_at')
            ->whereNotNull('expiry_date')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->select('id', 'expiry_date', 'agent_id', 'agency_id', 'branch_id', 'address', 'suburb')
            ->get()
            ->map(fn ($p) => [
                'event_type'  => 'property',
                'category'    => 'mandate_expiry',
                'title'       => $this->propertyTitle($p, 'Mandate expires'),
                'event_date'  => Carbon::parse($p->expiry_date)->startOfDay(),
                'source_type' => \App\Models\Property::class,
                'source_id'   => $p->id,
                'user_id'     => $p->agent_id,
                'agency_id'   => $p->agency_id,
                'branch_id'   => $p->branch_id,
                'property_id' => $p->id,
            ]);
    }

    /**
     * Lease expiry fallback: properties with lease_end_date but NO
     * matching lease_records row. Canonical lease_expiry is emitted by
     * RentalCalendarSource from lease_records.
     */
    private function leaseExpiryFallback(): Collection
    {
        return DB::table('properties')
            ->whereNull('deleted_at')
            ->whereNotNull('lease_end_date')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                  ->from('lease_records')
                  ->whereColumn('lease_records.property_id', 'properties.id')
                  ->whereNull('lease_records.deleted_at');
            })
            ->select('id', 'lease_end_date', 'agent_id', 'agency_id', 'branch_id', 'address', 'suburb')
            ->get()
            ->map(fn ($p) => [
                'event_type'  => 'property',
                'category'    => 'lease_expiry',
                'title'       => $this->propertyTitle($p, 'Lease expires'),
                'event_date'  => Carbon::parse($p->lease_end_date)->startOfDay(),
                'source_type' => \App\Models\Property::class,
                'source_id'   => $p->id,
                'user_id'     => $p->agent_id,
                'agency_id'   => $p->agency_id,
                'branch_id'   => $p->branch_id,
                'property_id' => $p->id,
            ]);
    }

    private function propertyShowday(): Collection
    {
        return DB::table('property_showdays as ps')
            ->whereNull('ps.deleted_at')
            ->whereNotNull('ps.start_date')
            ->where('ps.start_date', '>=', now()->subDays(2))
            ->join('properties as p', 'p.id', '=', 'ps.property_id')
            ->whereNull('p.deleted_at')
            ->select(
                'ps.id',
                'ps.start_date',
                'ps.property_id',
                'p.agent_id',
                'p.agency_id',
                'p.branch_id',
                'p.address',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'property',
                'category'    => 'property_showday',
                'title'       => 'Show day — ' . ($r->address ?: "property #{$r->property_id}"),
                'event_date'  => Carbon::parse($r->start_date),
                'source_type' => \App\Models\PropertyShowday::class,
                'source_id'   => $r->id,
                'user_id'     => $r->agent_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => $r->property_id,
            ]);
    }

    /**
     * Portal listing expiry from listing_stocks.
     * listing_stocks has no property_id — uses user_id as agent,
     * branch_id for branch, agency resolved via branches.agency_id.
     */
    private function portalListingExpiry(): Collection
    {
        return DB::table('listing_stocks as ls')
            ->whereNull('ls.deleted_at')
            ->whereNotNull('ls.expires_at')
            ->leftJoin('branches as b', 'b.id', '=', 'ls.branch_id')
            ->select(
                'ls.id',
                'ls.expires_at',
                'ls.user_id',
                'ls.branch_id',
                'ls.property',
                'ls.external_ref',
                'b.agency_id',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'property',
                'category'    => 'portal_listing_expiry',
                'title'       => 'Portal listing expires — ' . ($r->property ?: $r->external_ref ?: "listing #{$r->id}"),
                'event_date'  => Carbon::parse($r->expires_at)->startOfDay(),
                'source_type' => \App\Models\ListingStock::class,
                'source_id'   => $r->id,
                'user_id'     => $r->user_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => null,
            ]);
    }

    /**
     * Filed document expiry from document_filing_register.
     * Excludes mandate types (EA, OA) — those duplicate mandate_expiry.
     * No property_id FK — uses agent_id, branch_id directly.
     */
    private function filedDocumentExpiry(): Collection
    {
        return DB::table('document_filing_register as dfr')
            ->whereNull('dfr.deleted_at')
            ->whereNotNull('dfr.expiry_date')
            ->whereNotIn('dfr.document_type', self::MANDATE_DOC_TYPES)
            ->leftJoin('branches as b', 'b.id', '=', 'dfr.branch_id')
            ->select(
                'dfr.id',
                'dfr.expiry_date',
                'dfr.document_type',
                'dfr.agent_id',
                'dfr.branch_id',
                'dfr.property_address',
                'b.agency_id',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'property',
                'category'    => 'filed_document_expiry',
                'title'       => trim(
                    ($r->document_type ?: 'Filed document') . ' expires'
                    . ($r->property_address ? " — {$r->property_address}" : '')
                ),
                'event_date'  => Carbon::parse($r->expiry_date)->startOfDay(),
                'source_type' => \App\Models\DocumentFiling::class,
                'source_id'   => $r->id,
                'user_id'     => $r->agent_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
                'property_id' => null,
                'metadata'    => ['document_type' => $r->document_type],
            ]);
    }

    private function propertyTitle($p, string $verb): string
    {
        $addr = $p->address ?: ($p->suburb ?: "property #{$p->id}");
        return "{$verb} — {$addr}";
    }
}
