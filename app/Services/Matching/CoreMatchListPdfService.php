<?php

declare(strict_types=1);

namespace App\Services\Matching;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Schema;

/**
 * Core-Match / Buyer-Pipeline wishlist list → printable A4 PDF.
 *
 * Agents want to print the resolved property list for a buyer's wishlist and
 * work on paper during appointment rounds. This renders that SAME list
 * (the `corex.contacts.match-results` surface — reached from Core Matches,
 * the contact page, the property page and the buyer pipeline) as a clean,
 * compact, print-optimised table — one row per property — NOT a screenshot of
 * the web tiles.
 *
 * INTERNAL DOCUMENT. It carries seller PII (name + primary number) and full
 * street addresses, so every page is stamped "Internal use only". It must
 * never be handed to a client — the client-facing artefact is the shared
 * Client Page link, which deliberately omits seller details.
 *
 * Pillars: Property (read), Contact/seller (read), Agent (read). The list is
 * produced by the ONE canonical scorer via {@see ClientMatchResolver} so the
 * paper sheet always mirrors exactly what the agent sees on screen — same
 * filters (the wishlist criteria), same sort (match_score desc).
 *
 * Reuse: dompdf (barryvdh/laravel-dompdf) — the established CoreX PDF path
 * (PropertyBrochureService, ProformaPdfRenderer, ViewingPack). Table-based,
 * inline-CSS, self-contained (isRemoteEnabled=false) — no new library.
 */
class CoreMatchListPdfService
{
    /**
     * Candidate column names for the property's ACCESS / key-arrangements note.
     *
     * cc3 is adding a dedicated "access" field on `properties`. The exact name
     * is not yet pinned, so this resolves the FIRST column that actually exists
     * (prioritised, most-specific first). The moment cc3's migration lands under
     * any of these names, the column is picked up with no further change here.
     * If cc3 chooses a name NOT in this list, add it at the top — one line.
     *
     * @var string[]
     */
    private const ACCESS_COLUMN_CANDIDATES = [
        'access_notes',
        'access_instructions',
        'access_arrangements',
        'key_arrangements',
        'key_notes',
        'entry_instructions',
        'access',
    ];

    /**
     * Build the dompdf instance for the given match's property list.
     *
     * @param  bool  $includeHidden  Include properties the agent hid from this
     *         match. Default false — a working appointment sheet lists only the
     *         properties still in play, mirroring the visible tiles on screen.
     */
    public function pdf(Contact $contact, ContactMatch $match, bool $includeHidden = false)
    {
        $data = $this->data($contact, $match, $includeHidden);

        // Options MUST precede loadView (dompdf reads fontDir/fontCache at
        // construction — ViewingPackPdfSupport gotcha). isRemoteEnabled=false:
        // the sheet is pure text, no remote fetches.
        return Pdf::setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled'    => false,
            'dpi'             => 96,
            'defaultFont'     => 'DejaVu Sans',
        ])
            ->loadView('corex.core-matches.list-pdf', ['d' => $data])
            ->setPaper('a4', 'landscape');
    }

    /**
     * A safe, print-friendly filename for the sheet.
     */
    public function filename(Contact $contact, ContactMatch $match): string
    {
        $buyer = trim((string) ($contact->full_name ?? 'buyer'));
        $slug  = preg_replace('/[^A-Za-z0-9]+/', '-', $buyer) ?: 'buyer';
        $slug  = trim((string) $slug, '-') ?: 'buyer';

        return 'core-match-list-' . strtolower($slug) . '-' . $match->id . '.pdf';
    }

    /**
     * Assemble the view-model. Keeps the Blade dumb: it only echoes.
     *
     * @return array<string,mixed>
     */
    public function data(Contact $contact, ContactMatch $match, bool $includeHidden): array
    {
        $properties = app(ClientMatchResolver::class)->resolve($match, includeHidden: $includeHidden);

        // Mirror match-results.blade.php exactly: hard belt-and-braces filter on
        // listing_type (a sale match never lists rentals, and vice versa), then
        // drop any hidden property when includeHidden is false.
        $properties = $this->filterByListingType($properties, $match->listing_type);
        if (! $includeHidden) {
            $properties = $properties->reject(fn ($p) => $match->isPropertyHidden($p->id))->values();
        }

        // Eager-load the relations each row needs — agent + the seller-side
        // contacts (with their phones) — so the row loop issues no N+1 queries.
        $properties->each(function (Property $p) {
            $p->loadMissing(['agent', 'contacts.phones', 'contacts.primaryPhone']);
        });

        $accessColumn = $this->resolveAccessColumn();

        $rows = $properties->map(function (Property $p) use ($match, $accessColumn) {
            $seller = $this->sellerFor($p);

            return [
                'address'     => $p->buildDisplayAddress(),
                'suburb'      => trim((string) ($p->suburb ?? '')),
                'status'      => ucwords(str_replace('_', ' ', (string) $p->status)),
                'score'       => (int) ($p->match_score ?? 0),
                'tier'        => $p->match_tier ?? MatchingService::tierFor((int) ($p->match_score ?? 0)),
                'price'       => $p->formattedPrice(),
                'beds'        => $p->beds !== null ? (int) $p->beds : null,
                'baths'       => $p->baths !== null ? (float) $p->baths : null,
                'garages'     => $p->garages !== null ? (int) $p->garages : null,
                'size'        => $p->size_m2 ? number_format((int) $p->size_m2) . ' m²' : null,
                'agent_name'  => $p->agent?->name,
                'agent_phone' => $this->agentPhone($p->agent),
                'seller_name' => $seller ? trim((string) $seller->full_name) : null,
                'seller_phone' => $seller ? ($seller->primaryPhone?->phone ?? $seller->phone) : null,
                'access'      => $accessColumn ? trim((string) ($p->{$accessColumn} ?? '')) : '',
                'hidden'      => $match->isPropertyHidden($p->id),
            ];
        })->values()->all();

        return [
            'rows'           => $rows,
            'buyer_name'     => trim((string) ($contact->full_name ?? '')) ?: 'Buyer',
            'buyer_phone'    => $contact->primaryPhone?->phone ?? $contact->phone,
            'buyer_email'    => $contact->primaryEmail?->email ?? $contact->email,
            'wishlist_name'  => trim((string) ($match->name ?? '')),
            'criteria'       => $this->criteriaSummary($match),
            'listing_type'   => $match->listing_type === 'rental' ? 'To Rent' : 'For Sale',
            'agency_name'    => $match->agency?->name ?? $contact->agency?->name ?? config('app.name'),
            'generated_at'   => now()->format('d M Y, H:i'),
            'generated_by'   => auth()->user()?->name,
            'total'          => count($rows),
            'access_shown'   => $accessColumn !== null,
        ];
    }

    /**
     * Belt-and-braces listing-type filter — identical intent to the guard in
     * match-results.blade.php so the sheet can never disagree with the screen.
     *
     * @param  \Illuminate\Support\Collection<int,Property>  $properties
     * @return \Illuminate\Support\Collection<int,Property>
     */
    private function filterByListingType($properties, ?string $matchListingType)
    {
        if (! $matchListingType) {
            return collect($properties)->values();
        }

        $rentalStatuses = ['to_rent', 'torent', 'for_rent', 'forrent', 'rented'];
        $saleStatuses   = ['for_sale', 'forsale', 'sold'];

        return collect($properties)->filter(function ($p) use ($matchListingType, $rentalStatuses, $saleStatuses) {
            $pLt = strtolower((string) ($p->listing_type ?? ''));
            $pSt = strtolower((string) ($p->status ?? ''));
            if ($matchListingType === 'sale') {
                if ($pLt === 'rental') return false;
                if (in_array($pSt, $rentalStatuses, true)) return false;
            }
            if ($matchListingType === 'rental') {
                if ($pLt === 'sale') return false;
                if (in_array($pSt, $saleStatuses, true)) return false;
            }
            return true;
        })->values();
    }

    /**
     * The seller/owner-side contact for a loaded property. Mirrors
     * Property::sellerOwnerContact() but reads the ALREADY-loaded `contacts`
     * relation (no extra query per row).
     */
    private function sellerFor(Property $p): ?Contact
    {
        $contacts = $p->relationLoaded('contacts') ? $p->contacts : $p->contacts()->get();
        if ($contacts->isEmpty()) {
            return null;
        }

        $sellerSide = ['seller', 'owner', 'landlord', 'lessor'];
        $match = $contacts->first(function ($c) use ($sellerSide) {
            $role = strtolower(trim((string) ($c->pivot->role ?? '')));
            return in_array($role, $sellerSide, true);
        });

        if ($match) {
            return $match;
        }

        return $contacts->count() === 1 ? $contacts->first() : null;
    }

    /**
     * The agent's best contact number — primary phone, else cell.
     */
    private function agentPhone(?\App\Models\User $agent): ?string
    {
        if (! $agent) {
            return null;
        }
        $phone = trim((string) ($agent->phone ?? ''));
        if ($phone !== '') {
            return $phone;
        }
        $cell = trim((string) ($agent->cell ?? ''));
        return $cell !== '' ? $cell : null;
    }

    /**
     * The first existing ACCESS column name, or null if none present yet.
     */
    private function resolveAccessColumn(): ?string
    {
        foreach (self::ACCESS_COLUMN_CANDIDATES as $col) {
            if (Schema::hasColumn('properties', $col)) {
                return $col;
            }
        }
        return null;
    }

    /**
     * A one-line human summary of the wishlist criteria for the sheet header.
     */
    private function criteriaSummary(ContactMatch $match): string
    {
        $bits = [];

        if ($match->price_min || $match->price_max) {
            $bits[] = method_exists($match, 'priceRangeLabel')
                ? $match->priceRangeLabel()
                : trim('R ' . number_format((int) ($match->price_min ?? 0)) . ' – R ' . number_format((int) ($match->price_max ?? 0)));
        }

        if ($match->property_type) {
            $bits[] = ucwords(str_replace('_', ' ', (string) $match->property_type));
        }

        $specs = [];
        if ($match->beds_min)    $specs[] = $match->beds_min . '+ bed';
        if ($match->baths_min)   $specs[] = $match->baths_min . '+ bath';
        if ($match->garages_min) $specs[] = $match->garages_min . '+ gar';
        if ($specs) {
            $bits[] = implode(', ', $specs);
        }

        if (method_exists($match, 'suburbList')) {
            $subs = collect($match->suburbList())->filter()->take(4)->implode(', ');
            if ($subs !== '') {
                $bits[] = $subs;
            }
        }

        return implode('  •  ', $bits);
    }
}
