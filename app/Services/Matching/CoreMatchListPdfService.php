<?php

declare(strict_types=1);

namespace App\Services\Matching;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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

    /** Embedded photo width (px). Displays ~54px in the table; embed larger so
     *  it stays crisp at print DPI while keeping the PDF light. */
    private const PHOTO_MAX_W = 130;

    /**
     * Build the dompdf instance for the given match's property list.
     *
     * @param  bool  $includeHidden  Include properties the agent hid from this
     *         match. Default false — a working appointment sheet lists only the
     *         properties still in play, mirroring the visible tiles on screen.
     * @param  bool  $withPhotos  Embed each property's photo per row (default).
     *         false → compact text-only sheet (faster to print, saves ink,
     *         denser). Johan's with-photo / without-photo choice.
     */
    public function pdf(Contact $contact, ContactMatch $match, bool $includeHidden = false, bool $withPhotos = true)
    {
        $data = $this->data($contact, $match, $includeHidden, $withPhotos);

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
    public function filename(Contact $contact, ContactMatch $match, bool $withPhotos = true): string
    {
        $buyer = trim((string) ($contact->full_name ?? 'buyer'));
        $slug  = preg_replace('/[^A-Za-z0-9]+/', '-', $buyer) ?: 'buyer';
        $slug  = trim((string) $slug, '-') ?: 'buyer';
        $variant = $withPhotos ? 'with-photos' : 'text-only';

        return 'core-match-list-' . strtolower($slug) . '-' . $match->id . '-' . $variant . '.pdf';
    }

    /**
     * Assemble the view-model. Keeps the Blade dumb: it only echoes.
     *
     * @return array<string,mixed>
     */
    public function data(Contact $contact, ContactMatch $match, bool $includeHidden, bool $withPhotos = true): array
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

        $rows = $properties->map(function (Property $p) use ($match, $accessColumn, $withPhotos) {
            $seller = $this->sellerFor($p);

            return [
                'photo'       => $withPhotos ? $this->photoDataUri($p) : null,
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
            'with_photos'    => $withPhotos,
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
     * A small, self-contained JPEG data-URI thumbnail for the property's
     * primary photo — embedded (isRemoteEnabled=false), downscaled to keep the
     * PDF light and dompdf fast. Best-effort: a missing/undecodable photo
     * returns null and the row simply shows the no-photo placeholder.
     *
     * Mirrors the on-screen tile's image selection (gallery → dawn → noon →
     * dusk) so the paper photo matches what the agent sees.
     */
    private function photoDataUri(Property $p): ?string
    {
        $url = $this->primaryImageUrl($p);
        if ($url === null) {
            return null;
        }
        $bytes = $this->readImageBytes($url);
        if ($bytes === null || $bytes === '') {
            return null;
        }
        return $this->scaledJpegDataUri($bytes, self::PHOTO_MAX_W);
    }

    /** The property's first usable image URL, mirroring the results tile chain. */
    private function primaryImageUrl(Property $p): ?string
    {
        foreach (['gallery_images_json', 'dawn_images_json', 'noon_images_json', 'dusk_images_json'] as $col) {
            $arr = $p->{$col} ?? null;
            if (is_array($arr) && ! empty($arr[0]) && trim((string) $arr[0]) !== '') {
                return (string) $arr[0];
            }
        }
        return null;
    }

    /**
     * Read raw image bytes — local public disk first, then (only for a genuinely
     * external host) a short best-effort HTTP fetch. NEVER fetches our own host
     * (a missing local file returns null instantly rather than hanging on a
     * round-trip back into the app). Same discipline as PropertyBrochureService.
     */
    private function readImageBytes(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if ($path !== '' && str_contains($path, '/storage/')) {
            $rel = ltrim(substr($path, strpos($path, '/storage/') + 9), '/');
            try {
                $disk = Storage::disk('public');
                if ($disk->exists($rel)) {
                    return $disk->get($rel);
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        // Own host with no local file → skip (do not fetch ourselves).
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $isOwn = $host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)
            || ($appHost !== '' && $host === $appHost);
        if ($isOwn) {
            return null;
        }

        if (preg_match('#^https?://#i', $url)) {
            try {
                $ctx = stream_context_create([
                    'http'  => ['timeout' => 4],
                    'https' => ['timeout' => 4],
                    'ssl'   => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $bytes = @file_get_contents($url, false, $ctx);
                return $bytes !== false && $bytes !== '' ? $bytes : null;
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /** GD-downscale to maxW and return a JPEG data-URI; raw-embed on decode fail. */
    private function scaledJpegDataUri(string $bytes, int $maxW): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $this->rawImageDataUri($bytes);
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            // dompdf renders webp/png/jpeg natively — embed raw rather than drop.
            return $this->rawImageDataUri($bytes);
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($maxW > 0 && $w > $maxW && $w > 0) {
            $nh  = max(1, (int) round($h * $maxW / $w));
            $dst = imagecreatetruecolor($maxW, $nh);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $maxW, $nh, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxW, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        ob_start();
        imagejpeg($src, null, 72);
        $out = (string) ob_get_clean();
        imagedestroy($src);

        return 'data:image/jpeg;base64,' . base64_encode($out);
    }

    /** Embed bytes verbatim as a data-URI, sniffing the mime. */
    private function rawImageDataUri(string $bytes): ?string
    {
        $mime = 'image/jpeg';
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $detected = $f ? finfo_buffer($f, $bytes) : false;
            if ($f) {
                finfo_close($f);
            }
            if (is_string($detected) && str_starts_with($detected, 'image/')) {
                $mime = $detected;
            }
        }
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
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
