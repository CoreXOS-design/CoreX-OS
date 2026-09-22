<?php

namespace App\Services\Rentals;

use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionForm;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The printable tick-box inspection form — part 1 of a two-part job (a
 * separate lane builds the OMR scan reader against this service's own
 * output). "We read the form back by OMR — COLUMN MARKING ONLY. We do NOT
 * read handwriting, ever" (Johan). Every condition rating is a tick-box
 * column; free text is for the human only and is never geometry-tracked.
 *
 * ONE PHP-computed layout (buildLayout()) drives both what gets DRAWN (the
 * Blade view iterates it to render the PDF) and what gets RECORDED (
 * buildManifest() serializes the same arrays into the coordinate contract
 * persisted on RentalInspectionForm.manifest_json). This is deliberate and
 * load-bearing: the PDF and the manifest can never describe two different
 * geometries, because there is only one geometry computation, not two.
 *
 * Coordinate space (see buildManifest()'s own 'coordinate_space' block —
 * this is the authoritative statement, repeated here for anyone reading
 * the code rather than the JSON): origin top-left of each page, x
 * increases rightward, y increases downward, unit = PDF point (1/72 inch,
 * DPI-independent), page = A4 portrait. DPI is deliberately NOT part of
 * the coordinate contract — a scan's actual resolution is unknown and
 * unconstrained; the four fiducial marks on every page are what let a
 * reader calibrate an arbitrary scan's pixels back into this same
 * physical, DPI-independent space. A minimum recommended scan quality is
 * still stated (RECOMMENDED_MIN_SCAN_DPI) as a print/detectability note,
 * not a coordinate assumption.
 *
 * No barcode/QR library — none was already in composer.json, and Johan's
 * instruction was explicit: use what's there or stop and ask. The page
 * identifier is instead a small OMR bit-grid, read by the EXACT SAME
 * column-marking mechanism as every condition tick-box — one detection
 * pipeline for the whole form, nothing the reader lane needs a second
 * library for either.
 */
class RentalInspectionFormPdfService
{
    public const PAGE_WIDTH = 595.28;
    public const PAGE_HEIGHT = 841.89;
    private const MARGIN = 36.0;

    private const FIDUCIAL_SIZE = 10.0;
    private const FIDUCIAL_INSET = 12.0;

    // Page-identifier OMR grid — fixed position on EVERY page (top-right,
    // just under the human-readable identifier line), fixed bit layout, so
    // a reader can locate and decode it WITHOUT first knowing which
    // manifest to consult — the manifest's own page_identifiers block
    // repeats the same coordinates for direct, guess-free confirmation.
    private const ID_BIT_SIZE = 6.0;
    private const ID_BIT_GAP = 2.0;
    private const ID_GRID_COLS = 8;
    private const ID_BITS_INSPECTION = 24; // up to 16,777,215 inspections
    private const ID_BITS_PAGE = 8;        // up to 255 pages
    private const ID_BITS_VERSION = 8;     // up to 255 form versions

    private const HEADER_HEIGHT = 132.0; // page 1 only
    private const ROOM_HEADING_HEIGHT = 16.0;
    private const CONDITION_HEADER_HEIGHT = 14.0;
    private const ROW_HEIGHT = 20.0;
    private const ITEM_LABEL_WIDTH = 130.0;
    private const NOTES_COL_WIDTH = 110.0;
    private const BOX_SIZE = 10.0;
    private const BOX_GAP = 10.0;
    private const SIGNATURE_BLOCK_HEIGHT = 74.0;
    private const SIGNATURE_BLOCK_GAP = 10.0;

    /** Print/detectability note only — never part of the coordinate contract itself (see class docblock). */
    public const RECOMMENDED_MIN_SCAN_DPI = 150;

    public const MANIFEST_VERSION = 1;

    /**
     * The whole job: compute the layout, render the PDF, persist PDF +
     * manifest together as one new RentalInspectionForm row — UNLESS the
     * inspection's room/item/condition shape hasn't changed since the
     * current version, in which case that version is returned unchanged
     * (never a needless new version on every re-download click).
     */
    public function generate(RentalInspection $inspection, ?User $by = null): RentalInspectionForm
    {
        $inspection->loadMissing(['property', 'lease.tenants.contact']);
        $roomGroups = $this->roomGroupsFor($inspection);
        $conditionStates = RentalInspectionSetting::conditionStatesFor($inspection->agency_id);

        $contentHash = $this->contentHash($inspection, $roomGroups, $conditionStates);

        $existing = RentalInspectionForm::currentFor($inspection->id)->first();
        if ($existing && $existing->content_hash === $contentHash) {
            return $existing;
        }

        $version = ($existing->version ?? 0) + 1;

        $layout = $this->buildLayout($inspection, $roomGroups, $conditionStates, $version);

        $pdf = Pdf::loadView('corex.rental-inspections.form-pdf', [
            'inspection' => $inspection,
            'layout' => $layout,
        ])->setPaper('a4', 'portrait');
        $this->applyOptions($pdf);

        $pdfBytes = $pdf->output();

        $path = "properties/{$inspection->property_id}/rental-inspection-forms/inspection-{$inspection->id}-v{$version}.pdf";
        Storage::disk('local')->put($path, $pdfBytes);

        $manifest = $this->buildManifest($inspection, $layout, $version, $contentHash);

        return RentalInspectionForm::create([
            'agency_id' => $inspection->agency_id,
            'branch_id' => $inspection->property?->branch_id,
            'rental_inspection_id' => $inspection->id,
            'version' => $version,
            'content_hash' => $contentHash,
            'pdf_storage_path' => $path,
            'manifest_json' => $manifest,
            'page_count' => $layout['page_count'],
            'box_count' => count($layout['boxes']),
            'generated_by_user_id' => $by?->id,
        ]);
    }

    public function filenameFor(RentalInspectionForm $form): string
    {
        $address = $form->inspection?->property?->buildDisplayAddress() ?: 'Property ' . $form->rental_inspection_id;
        $name = trim((string) preg_replace('/[\/\\\\:*?"<>|]+/', ' ', "Inspection Form - {$address} - v{$form->version}"));

        return trim((string) preg_replace('/\s+/', ' ', $name)) . '.pdf';
    }

    // ── Data shape ──────────────────────────────────────────────────────

    /**
     * Rooms in the same order the on-screen recording panel groups them
     * (sort_order then id — rental-inspection-recording.blade.php's own
     * roomGroups()), one entry per room plus a trailing roomless "General"
     * group for meters/legacy items. Retired items/rooms are excluded — a
     * blank form is for recording NEW observations, not a history report.
     *
     * @return Collection<int, array{room: ?PropertyRoom, items: Collection<int, RentalInspectionItem>}>
     */
    private function roomGroupsFor(RentalInspection $inspection): Collection
    {
        $items = RentalInspectionItem::where('property_id', $inspection->property_id)
            ->where('is_retired', false)
            ->with('room')
            ->get();

        $withRoom = $items->filter(fn (RentalInspectionItem $i) => $i->room && ! $i->room->is_retired);
        $byRoom = $withRoom->groupBy('property_room_id')->map(function (Collection $groupItems) {
            return [
                'room' => $groupItems->first()->room,
                'items' => $groupItems->sortBy(fn (RentalInspectionItem $i) => [$i->sort_order, $i->id])->values(),
            ];
        })->sortBy(fn (array $g) => [$g['room']->sort_order, $g['room']->id])->values();

        $general = $items->filter(fn (RentalInspectionItem $i) => ! $i->room)->sortBy('id')->values();
        if ($general->isNotEmpty()) {
            $byRoom->push(['room' => null, 'items' => $general]);
        }

        return $byRoom;
    }

    /** Any change here — a room/item added, removed, renamed, reordered, or the condition vocabulary changing — must produce a new version. */
    private function contentHash(RentalInspection $inspection, Collection $roomGroups, array $conditionStates): string
    {
        $shape = [
            'property_id' => $inspection->property_id,
            'rooms' => $roomGroups->map(fn (array $g) => [
                'room_id' => $g['room']?->id,
                'room_label' => $g['room']?->label,
                'items' => $g['items']->map(fn (RentalInspectionItem $i) => [$i->id, $i->label])->all(),
            ])->all(),
            'condition_states' => array_map(fn ($s) => [$s['key'], $s['label']], $conditionStates),
        ];

        return hash('sha256', json_encode($shape));
    }

    // ── Layout — one computation, drawn AND recorded from the same arrays ──

    /**
     * @return array{
     *   page_count: int, header: array, room_headings: array, condition_header_rows: array,
     *   item_rows: array, boxes: array, fiducials: array, page_identifiers: array, signature_blocks: array
     * }
     */
    private function buildLayout(RentalInspection $inspection, Collection $roomGroups, array $conditionStates, int $version): array
    {
        $page = 1;
        $y = self::MARGIN;

        $roomHeadings = [];
        $conditionHeaderRows = [];
        $itemRows = [];
        $boxes = [];

        $ensureSpace = function (float $height) use (&$page, &$y) {
            if ($y + $height > self::PAGE_HEIGHT - self::MARGIN) {
                $page++;
                $y = self::MARGIN;

                return true;
            }

            return false;
        };

        $drawConditionHeader = function () use (&$page, &$y, $conditionStates, &$conditionHeaderRows) {
            $x = self::MARGIN + self::ITEM_LABEL_WIDTH;
            $labels = [];
            foreach ($conditionStates as $state) {
                $labels[] = ['key' => $state['key'], 'label' => $state['label'], 'x' => $x, 'y' => $y];
                $x += self::BOX_SIZE + self::BOX_GAP;
            }
            $conditionHeaderRows[] = ['page' => $page, 'y' => $y, 'labels' => $labels];
            $y += self::CONDITION_HEADER_HEIGHT;
        };

        $header = [
            'page' => 1,
            'y' => $y,
            'address' => $inspection->property?->buildDisplayAddress() ?: '—',
            'type' => ucfirst(str_replace('_', '-', $inspection->type)) . '-Inspection',
            'date' => optional($inspection->completed_at ?? $inspection->created_at)->format('Y-m-d') ?: now()->format('Y-m-d'),
            'landlord' => $inspection->property?->sellerOwnerContact()?->first_name . ' ' . $inspection->property?->sellerOwnerContact()?->last_name,
            'tenants' => $inspection->lease?->tenants->map(fn ($t) => trim(($t->contact?->first_name ?? '') . ' ' . ($t->contact?->last_name ?? '')))->filter()->values()->all() ?? [],
            'agent' => $inspection->createdBy?->name,
        ];
        $y += self::HEADER_HEIGHT;

        foreach ($roomGroups as $group) {
            /** @var ?PropertyRoom $room */
            $room = $group['room'];
            $label = $room?->label ?? 'General (meters and unassigned items)';

            $ensureSpace(self::ROOM_HEADING_HEIGHT + self::CONDITION_HEADER_HEIGHT + self::ROW_HEIGHT);
            $roomStartedOnPage = $page;
            $roomHeadings[] = ['page' => $page, 'y' => $y, 'label' => $label];
            $y += self::ROOM_HEADING_HEIGHT;
            $drawConditionHeader();

            foreach ($group['items'] as $item) {
                $paged = $ensureSpace(self::ROW_HEIGHT);
                if ($paged) {
                    // Room continues onto a fresh page — repeat the heading
                    // and the condition-column header so every page is
                    // self-describing; a reader (or a human) never has to
                    // flip back to know which room/columns a page shows.
                    $roomHeadings[] = ['page' => $page, 'y' => $y, 'label' => $label . ' (cont.)'];
                    $y += self::ROOM_HEADING_HEIGHT;
                    $drawConditionHeader();
                }

                $itemRows[] = [
                    'page' => $page, 'y' => $y,
                    'rental_inspection_item_id' => $item->id,
                    'label' => $item->label,
                    'label_x' => self::MARGIN,
                    'notes_x' => self::MARGIN + self::ITEM_LABEL_WIDTH + count($conditionStates) * (self::BOX_SIZE + self::BOX_GAP),
                    'notes_width' => self::NOTES_COL_WIDTH,
                ];

                $x = self::MARGIN + self::ITEM_LABEL_WIDTH;
                foreach ($conditionStates as $state) {
                    $boxY = $y + (self::ROW_HEIGHT - self::BOX_SIZE) / 2;
                    $boxes[] = [
                        'page' => $page,
                        'rental_inspection_item_id' => $item->id,
                        'room_label' => $label,
                        'item_label' => $item->label,
                        'condition_key' => $state['key'],
                        'condition_label' => $state['label'],
                        'x' => round($x, 2), 'y' => round($boxY, 2),
                        'width' => self::BOX_SIZE, 'height' => self::BOX_SIZE,
                    ];
                    $x += self::BOX_SIZE + self::BOX_GAP;
                }

                $y += self::ROW_HEIGHT;
            }

            $y += 6.0; // breathing room between room tables
        }

        // Signature block — three parties (§15: tenant(s), landlord, agent),
        // never split across a page break.
        $tenants = $inspection->lease?->tenants ?? collect();
        $partyCount = max(1, $tenants->count()) + 2; // tenant(s) + landlord + agent
        $signatureHeight = self::SIGNATURE_BLOCK_HEIGHT + self::SIGNATURE_BLOCK_GAP;
        $ensureSpace($signatureHeight);

        $signatureBlocks = [];
        $blockWidth = (self::PAGE_WIDTH - 2 * self::MARGIN - ($partyCount - 1) * self::SIGNATURE_BLOCK_GAP) / $partyCount;
        $x = self::MARGIN;
        foreach ($tenants as $tenant) {
            $signatureBlocks[] = [
                'page' => $page, 'party_role' => 'tenant', 'party_contact_id' => $tenant->contact_id,
                'label' => trim(($tenant->contact?->first_name ?? '') . ' ' . ($tenant->contact?->last_name ?? '')) ?: 'Tenant',
                'x' => round($x, 2), 'y' => $y, 'width' => round($blockWidth, 2), 'height' => self::SIGNATURE_BLOCK_HEIGHT,
            ];
            $x += $blockWidth + self::SIGNATURE_BLOCK_GAP;
        }
        if ($tenants->isEmpty()) {
            $signatureBlocks[] = [
                'page' => $page, 'party_role' => 'tenant', 'party_contact_id' => null, 'label' => 'Tenant',
                'x' => round($x, 2), 'y' => $y, 'width' => round($blockWidth, 2), 'height' => self::SIGNATURE_BLOCK_HEIGHT,
            ];
            $x += $blockWidth + self::SIGNATURE_BLOCK_GAP;
        }
        $landlord = $inspection->property?->sellerOwnerContact();
        $signatureBlocks[] = [
            'page' => $page, 'party_role' => 'landlord', 'party_contact_id' => $landlord?->id,
            'label' => trim(($landlord?->first_name ?? '') . ' ' . ($landlord?->last_name ?? '')) ?: 'Landlord',
            'x' => round($x, 2), 'y' => $y, 'width' => round($blockWidth, 2), 'height' => self::SIGNATURE_BLOCK_HEIGHT,
        ];
        $x += $blockWidth + self::SIGNATURE_BLOCK_GAP;
        $signatureBlocks[] = [
            'page' => $page, 'party_role' => 'agent', 'party_contact_id' => null,
            'label' => $inspection->createdBy?->name ?: 'Agent',
            'x' => round($x, 2), 'y' => $y, 'width' => round($blockWidth, 2), 'height' => self::SIGNATURE_BLOCK_HEIGHT,
        ];

        $pageCount = $page;

        // Fiducials + page identifiers — computed once the final page count
        // is known, identically for every page.
        $fiducials = [];
        $pageIdentifiers = [];
        for ($p = 1; $p <= $pageCount; $p++) {
            $fiducials = array_merge($fiducials, $this->fiducialsForPage($p));
            $pageIdentifiers[] = $this->pageIdentifierFor($inspection->id, $p, $pageCount, $version);
        }

        return [
            'page_count' => $pageCount,
            'header' => $header,
            'room_headings' => $roomHeadings,
            'condition_header_rows' => $conditionHeaderRows,
            'item_rows' => $itemRows,
            'boxes' => $boxes,
            'fiducials' => $fiducials,
            'page_identifiers' => $pageIdentifiers,
            'signature_blocks' => $signatureBlocks,
        ];
    }

    /** Four filled-square registration marks, same fixed inset on every page — a scan de-skews/scales against these. */
    private function fiducialsForPage(int $page): array
    {
        $near = self::FIDUCIAL_INSET;
        $right = self::PAGE_WIDTH - $near - self::FIDUCIAL_SIZE;
        $bottom = self::PAGE_HEIGHT - $near - self::FIDUCIAL_SIZE;

        return [
            ['page' => $page, 'corner' => 'top_left', 'x' => $near, 'y' => $near, 'size' => self::FIDUCIAL_SIZE, 'shape' => 'filled_square'],
            ['page' => $page, 'corner' => 'top_right', 'x' => $right, 'y' => $near, 'size' => self::FIDUCIAL_SIZE, 'shape' => 'filled_square'],
            ['page' => $page, 'corner' => 'bottom_left', 'x' => $near, 'y' => $bottom, 'size' => self::FIDUCIAL_SIZE, 'shape' => 'filled_square'],
            // Bottom-right is intentionally a different shape (filled circle) — a
            // deliberate asymmetry so a reader can resolve a 180-degree-rotated
            // scan (all four squares alone are rotation-symmetric) without
            // needing to read the page-identifier bits first just to orient it.
            ['page' => $page, 'corner' => 'bottom_right', 'x' => $right, 'y' => $bottom, 'size' => self::FIDUCIAL_SIZE, 'shape' => 'filled_circle'],
        ];
    }

    /**
     * Page-identifier OMR grid. Fixed position on every page (top-right,
     * under the printed human-readable line) — a reader locates it without
     * consulting any manifest first, decodes the three fields below, THEN
     * fetches this inspection's manifest for everything else. The manifest
     * repeats these same bit coordinates so nothing here is ever a second,
     * undocumented source of truth.
     */
    private function pageIdentifierFor(int $inspectionId, int $page, int $pageCount, int $version): array
    {
        $bitsInspection = $this->toBits($inspectionId, self::ID_BITS_INSPECTION);
        $bitsPage = $this->toBits($page, self::ID_BITS_PAGE);
        $bitsVersion = $this->toBits($version, self::ID_BITS_VERSION);
        $allBits = array_merge($bitsInspection, $bitsPage, $bitsVersion);

        $gridWidth = self::ID_GRID_COLS * self::ID_BIT_SIZE + (self::ID_GRID_COLS - 1) * self::ID_BIT_GAP;
        $gridX = self::PAGE_WIDTH - self::MARGIN - $gridWidth;
        $gridY = self::MARGIN + 12.0;

        $bits = [];
        foreach ($allBits as $index => $value) {
            $col = $index % self::ID_GRID_COLS;
            $row = intdiv($index, self::ID_GRID_COLS);
            $bits[] = [
                'index' => $index,
                'value' => $value,
                'x' => round($gridX + $col * (self::ID_BIT_SIZE + self::ID_BIT_GAP), 2),
                'y' => round($gridY + $row * (self::ID_BIT_SIZE + self::ID_BIT_GAP), 2),
                'width' => self::ID_BIT_SIZE, 'height' => self::ID_BIT_SIZE,
            ];
        }

        return [
            'page' => $page,
            'text' => "INSP #{$inspectionId} \u{00B7} Page {$page}/{$pageCount} \u{00B7} v{$version}",
            'text_x' => $gridX,
            'text_y' => self::MARGIN,
            'grid_x' => $gridX, 'grid_y' => $gridY, 'grid_cols' => self::ID_GRID_COLS, 'grid_rows' => (int) ceil(count($allBits) / self::ID_GRID_COLS),
            'fields' => [
                ['name' => 'rental_inspection_id', 'bit_length' => self::ID_BITS_INSPECTION, 'value' => $inspectionId],
                ['name' => 'page_number', 'bit_length' => self::ID_BITS_PAGE, 'value' => $page],
                ['name' => 'form_version', 'bit_length' => self::ID_BITS_VERSION, 'value' => $version],
            ],
            'bits' => $bits,
        ];
    }

    /**
     * MSB-first, fixed bit_length — the reader is told the exact length per
     * field, it never infers width from the value.
     *
     * Throws rather than truncating. A silently-dropped high bit would
     * encode a wrapped, WRONG id that still decodes to something — a form
     * that scans back onto the wrong inspection with no error anywhere.
     * Failing loudly here, at generation time, is the only place this can
     * be caught before a page is printed.
     */
    private function toBits(int $value, int $length): array
    {
        $max = (2 ** $length) - 1;
        if ($value < 0 || $value > $max) {
            throw new \RuntimeException(
                "RentalInspectionFormPdfService: value {$value} does not fit in {$length} bits (max {$max}). "
                . 'Refusing to generate a form whose page identifier would silently wrap onto another id.'
            );
        }

        $bits = [];
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }

        return $bits;
    }

    // ── The manifest — the reader lane's whole contract ────────────────

    private function buildManifest(RentalInspection $inspection, array $layout, int $version, string $contentHash): array
    {
        $conditionStates = RentalInspectionSetting::conditionStatesFor($inspection->agency_id);

        return [
            'manifest_version' => self::MANIFEST_VERSION,
            'rental_inspection_id' => $inspection->id,
            'form_version' => $version,
            'agency_id' => $inspection->agency_id,
            'content_hash' => $contentHash,
            'generated_at' => now()->toIso8601String(),
            'page_count' => $layout['page_count'],
            'page_size' => ['name' => 'A4', 'width_pt' => self::PAGE_WIDTH, 'height_pt' => self::PAGE_HEIGHT],
            'coordinate_space' => [
                'origin' => 'top-left',
                'x_axis' => 'increases rightward from the left edge of the page',
                'y_axis' => 'increases downward from the top edge of the page',
                'unit' => 'pt (PDF point, 1/72 inch — DPI-independent)',
                'recommended_min_scan_dpi' => self::RECOMMENDED_MIN_SCAN_DPI,
                'note' => 'Coordinates are physical, page-relative positions, not pixel positions in any particular scan. Use the four fiducial marks on each page to compute that scan\'s own scale/rotation/offset, then map detected pixel positions back into this space before comparing against the box coordinates below.',
            ],
            'condition_states' => array_map(fn ($s) => ['key' => $s['key'], 'label' => $s['label']], $conditionStates),
            'fiducials' => $layout['fiducials'],
            'page_identifiers' => array_map(function (array $pid) {
                return [
                    'page' => $pid['page'],
                    'encoding' => 'binary_omr_grid',
                    'bit_order' => 'row_major_left_to_right_top_to_bottom_msb_first_per_field',
                    'grid' => ['x' => $pid['grid_x'], 'y' => $pid['grid_y'], 'cols' => $pid['grid_cols'], 'rows' => $pid['grid_rows']],
                    'fields' => $pid['fields'],
                    'bits' => $pid['bits'],
                ];
            }, $layout['page_identifiers']),
            'boxes' => array_map(fn (array $b) => [
                'page' => $b['page'],
                'rental_inspection_item_id' => $b['rental_inspection_item_id'],
                'room_label' => $b['room_label'],
                'item_label' => $b['item_label'],
                'condition_key' => $b['condition_key'],
                'condition_label' => $b['condition_label'],
                'x' => $b['x'], 'y' => $b['y'], 'width' => $b['width'], 'height' => $b['height'],
            ], $layout['boxes']),
            // Not OMR-read (never marked, never machine-decided) — geometry
            // only, so a downstream step MAY crop the signed region and file
            // it as this party's wet-ink upload (RentalInspectionSignature::
            // storeWetInkUpload(), already built) without re-deriving where
            // on the page it is.
            'signature_blocks' => $layout['signature_blocks'],
        ];
    }

    /** Same dompdf convention as RentalDocumentPdfService::applyOptions() — one convention, not two. */
    private function applyOptions($pdf): void
    {
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isPhpEnabled', false);
        $pdf->setOption('dpi', 96);

        $fontDir = storage_path('app/dompdf-fonts');
        if (! is_dir($fontDir)) {
            @mkdir($fontDir, 0775, true);
        }
        if (is_dir($fontDir) && is_writable($fontDir)) {
            $pdf->setOption('fontDir', $fontDir);
            $pdf->setOption('fontCache', $fontDir);
        }
    }
}
