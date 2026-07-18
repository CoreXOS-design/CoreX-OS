<?php

namespace App\Services\Docuperfect;

use App\Mail\Signatures\SignatureReminderMail;
use App\Mail\Signatures\SignedDocumentMail;
use App\Mail\Signatures\SigningRequestMail;
use App\Mail\Signatures\WetInkRejectionMail;
use App\Mail\Signatures\WetInkUploadedNotification;
use App\Models\Docuperfect\AmendmentAcceptance;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\LeaseRecord;
use App\Models\Docuperfect\Signature;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\SignatureZone;
use App\Models\Docuperfect\TemplateSignatureZone;
use App\Models\Docuperfect\WetInkInspection;
use App\Models\User;
use App\Notifications\SignatureActivityNotification;
use App\Services\CandidatePractitionerService;
use App\Services\Docuperfect\SignaturePdfService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SignatureService
{
    protected SignaturePdfService $pdfService;

    public function __construct(SignaturePdfService $pdfService)
    {
        $this->pdfService = $pdfService;
    }

    // ──────────────────────────────────────────────
    // Template lifecycle
    // ──────────────────────────────────────────────

    /**
     * Create a signature template for a document.
     */
    public function createTemplate(Document $document, User $user): SignatureTemplate
    {
        $template = SignatureTemplate::create([
            'document_id' => $document->id,
            'status' => SignatureTemplate::STATUS_DRAFT,
            'created_by' => $user->id,
            'signing_order_json' => ['agent', 'tenant', 'landlord'],
        ]);

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_CREATED,
            SignatureAuditLog::ACTOR_USER,
            $user->name,
            $user->email,
            $user->id,
        );

        return $template;
    }

    // ──────────────────────────────────────────────
    // Field completion validation
    // ──────────────────────────────────────────────

    /**
     * Validate that all required document fields are completed.
     * Checks the document's fields_json for fields marked required=true and verifies
     * they have a non-empty value appropriate to their type.
     *
     * Returns ['valid' => bool, 'total' => int, 'filled' => int, 'missing' => [...labels]]
     */
    public function validateFieldCompletion(Document $document): array
    {
        $documentFields = $document->fields_json ?? [];

        // Build a map of document field values indexed by field ID
        $docFieldMap = [];
        foreach ($documentFields as $field) {
            $id = $field['id'] ?? null;
            if ($id) {
                $docFieldMap[$id] = $field;
            }
        }

        // Use template fields as the source of truth for required flags
        $template = $document->template;
        $templateFields = $template ? ($template->fields_json ?? []) : [];

        $missing = [];
        $total = 0;
        $filled = 0;

        foreach ($templateFields as $tField) {
            if (empty($tField['required'])) {
                continue;
            }

            $fieldId = $tField['id'] ?? null;
            if (!$fieldId) {
                continue;
            }

            // Skip fields assigned to signers — they complete during signing, not document creation
            $assignedTo = $tField['assignedTo'] ?? 'creator';
            if ($assignedTo !== 'creator') {
                continue;
            }

            $total++;

            // Find matching document field by ID
            $docField = $docFieldMap[$fieldId] ?? null;

            $hasValue = false;
            if ($docField) {
                $fieldType = $tField['type'] ?? 'placeholder';
                switch ($fieldType) {
                    case 'placeholder':
                    case 'date':
                        $hasValue = !empty(trim((string) ($docField['value'] ?? '')));
                        break;
                    case 'condition':
                        $hasValue = !empty(trim((string) ($docField['text'] ?? '')));
                        break;
                    case 'selection':
                        $hasValue = !empty($docField['selectedValue']);
                        break;
                    case 'strikethrough':
                        $hasValue = true; // toggles are always "filled"
                        break;
                    case 'initial':
                    case 'signature':
                        $hasValue = true; // handled by signature markers, not field completion
                        break;
                    default:
                        $hasValue = !empty(trim((string) ($docField['value'] ?? '')));
                }
            }

            if ($hasValue) {
                $filled++;
            } else {
                $label = $tField['field_label']
                    ?? $tField['field_name']
                    ?? $tField['named_field_name']
                    ?? ('Field on page ' . (($tField['pageIndex'] ?? 0) + 1));
                $missing[] = $label;
            }
        }

        return [
            'valid' => empty($missing),
            'total' => $total,
            'filled' => $filled,
            'missing' => $missing,
        ];
    }

    // ──────────────────────────────────────────────
    // Document integrity (SHA-256)
    // ──────────────────────────────────────────────

    /**
     * Generate a SHA-256 hash of the document's content for tamper detection.
     */
    public function generateDocumentHash(Document $document): string
    {
        $content = json_encode($document->fields_json ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $content);
    }

    /**
     * Verify that the document hasn't been tampered with since signing started.
     */
    public function verifyDocumentHash(SignatureTemplate $template): bool
    {
        if (!$template->document_hash) {
            return true;
        }

        $currentHash = $this->generateDocumentHash($template->document);
        return hash_equals($template->document_hash, $currentHash);
    }

    // ──────────────────────────────────────────────
    // Markers
    // ──────────────────────────────────────────────

    /**
     * Bulk-save markers for a template (replaces all existing).
     */
    public function saveMarkers(SignatureTemplate $template, array $markers): int
    {
        if (!in_array($template->status, [SignatureTemplate::STATUS_DRAFT, SignatureTemplate::STATUS_READY])) {
            throw new \LogicException('Cannot modify markers — template must be in draft or ready status.');
        }

        return DB::transaction(function () use ($template, $markers) {
            // Only delete manually-placed markers (not zone-expanded ones).
            // Zone-expanded markers (from_zone_id IS NOT NULL) are managed by the zone system.
            $template->markers()->whereNull('from_zone_id')->delete();

            $count = 0;
            foreach ($markers as $i => $data) {
                SignatureMarker::create([
                    'signature_template_id' => $template->id,
                    'page_number' => $data['page_number'],
                    'x_position' => $data['x_position'],
                    'y_position' => $data['y_position'],
                    'width' => $data['width'] ?? 20,
                    'height' => $data['height'] ?? 5,
                    'type' => $data['type'] ?? 'signature',
                    'assigned_party' => $data['assigned_party'],
                    'assigned_email' => $data['assigned_email'] ?? null,
                    'label' => $data['label'] ?? null,
                    'sort_order' => $data['sort_order'] ?? $i,
                    'required' => $data['required'] ?? true,
                ]);
                $count++;
            }

            // Include zone-expanded markers in the total count
            $count += $template->markers()->whereNotNull('from_zone_id')->count();

            return $count;
        });
    }

    // ──────────────────────────────────────────────
    // Template zone → marker conversion
    // ──────────────────────────────────────────────

    /**
     * Convert template signature zones to markers on a signature template.
     * Creates one marker per party per zone. Idempotent — skips existing markers.
     */
    public function convertZonesToMarkers(SignatureTemplate $sigTemplate): int
    {
        $document = $sigTemplate->document;
        $docTemplate = $document ? $document->template : null;

        if (!$docTemplate) {
            return 0;
        }

        $zones = $docTemplate->signatureZones()
            ->orderBy('page_index')
            ->orderBy('sort_order')
            ->get();

        if ($zones->isEmpty()) {
            return 0;
        }

        $count = 0;
        $sortOrder = $sigTemplate->markers()->max('sort_order') ?? -1;

        foreach ($zones as $zone) {
            // Skip zones with no real position (template author didn't position them)
            if ((float) $zone->x_position == 0 && (float) $zone->y_position == 0) {
                continue;
            }

            $parties = $zone->assigned_parties ?? [];

            foreach ($parties as $partyIndex => $party) {
                // Skip if a marker from this zone+party already exists
                $exists = $sigTemplate->markers()
                    ->where('from_template_zone_id', $zone->id)
                    ->where('assigned_party', $party)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $sortOrder++;

                // Offset multiple parties slightly so they don't stack exactly
                $yOffset = $partyIndex * 6;

                SignatureMarker::create([
                    'signature_template_id' => $sigTemplate->id,
                    'page_number' => $zone->page_index + 1, // convert 0-based to 1-based
                    'x_position' => $zone->x_position,
                    'y_position' => min(100 - $zone->height, $zone->y_position + $yOffset),
                    'width' => $zone->width,
                    'height' => $zone->height,
                    'type' => $zone->type,
                    'assigned_party' => $party,
                    'label' => $zone->label ?? ucfirst($party) . ' ' . $zone->type . ' — Page ' . ($zone->page_index + 1),
                    'sort_order' => $sortOrder,
                    'required' => $zone->required,
                    'from_template_zone_id' => $zone->id,
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Create signature markers from fields_json sign/initial entries.
     * Used by the e-sign wizard when no TemplateSignatureZone records exist.
     */
    public function convertFieldsJsonToMarkers(SignatureTemplate $sigTemplate, array $fieldsJson): int
    {
        $count = 0;
        $sortOrder = $sigTemplate->markers()->max('sort_order') ?? -1;

        foreach ($fieldsJson as $field) {
            $type = strtolower($field['type'] ?? '');
            if (!in_array($type, ['sign', 'initial'])) {
                continue;
            }

            $assignedTo = $field['assignedTo'] ?? $field['assigned_to'] ?? 'agent';
            $pageIndex = (int) ($field['pageIndex'] ?? $field['page_index'] ?? 0);
            $markerType = ($type === 'sign') ? SignatureMarker::TYPE_SIGNATURE : SignatureMarker::TYPE_INITIAL;

            $sortOrder++;

            SignatureMarker::create([
                'signature_template_id' => $sigTemplate->id,
                'page_number'           => $pageIndex + 1, // convert 0-based to 1-based
                'x_position'            => $field['x'] ?? 0,
                'y_position'            => $field['y'] ?? 0,
                'width'                 => $field['width'] ?? 10,
                'height'                => $field['height'] ?? 4,
                'type'                  => $markerType,
                'assigned_party'        => $assignedTo,
                'label'                 => $field['label'] ?? $field['named_field_name'] ?? (ucfirst($assignedTo) . ' ' . $type . ' — Page ' . ($pageIndex + 1)),
                'sort_order'            => $sortOrder,
                'required'              => !empty($field['required']),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Create one default signature marker per party on the last page.
     * Used when the template has no sign/initial fields and no signature zones.
     */
    public function createDefaultMarkers(SignatureTemplate $sigTemplate): int
    {
        $document = $sigTemplate->document;
        $docTemplate = $document ? $document->template : null;
        $lastPage = $docTemplate ? $docTemplate->page_count : 1;

        $signingOrder = $sigTemplate->signing_order_json ?? [];
        if (empty($signingOrder)) {
            $signingOrder = ['agent'];
        }

        $count = 0;
        $sortOrder = $sigTemplate->markers()->max('sort_order') ?? -1;

        foreach ($signingOrder as $i => $party) {
            $sortOrder++;
            $yPos = 75 + ($i * 8); // Stack vertically near bottom of page

            SignatureMarker::create([
                'signature_template_id' => $sigTemplate->id,
                'page_number'           => $lastPage,
                'x_position'            => 10,
                'y_position'            => min(92, $yPos),
                'width'                 => 25,
                'height'                => 6,
                'type'                  => SignatureMarker::TYPE_SIGNATURE,
                'assigned_party'        => $party,
                'label'                 => ucfirst($party) . ' Signature',
                'sort_order'            => $sortOrder,
                'required'              => true,
            ]);
            $count++;
        }

        return $count;
    }

    // ──────────────────────────────────────────────
    // Dynamic Signature Zones (V2)
    // ──────────────────────────────────────────────

    /**
     * Create estimated signature zones for PDF templates.
     *
     * NOTE: For web/CDS templates, zones are created client-side from actual
     * DOM positions of data-marker-party elements. This method is only used
     * for PDF templates where no DOM positions are available.
     *
     * @param  SignatureTemplate  $sigTemplate
     * @param  array  $parties  Parties from the signing chain (with role, name, email)
     * @param  int  $pageCount  Total pages in the document
     * @param  bool  $isCandidateFlow  Whether the flow is candidate-originated
     * @return int  Number of markers created
     */
    public function createZonesFromParties(
        SignatureTemplate $sigTemplate,
        array $parties,
        int $pageCount = 1,
        bool $isCandidateFlow = false
    ): int {
        // Don't recreate if zones already exist
        if ($sigTemplate->zones()->count() > 0) {
            return 0;
        }

        $renderer = app(SignatureZoneRenderer::class);

        // Group parties by base role (seller, buyer, agent, landlord, tenant, etc.)
        $roleGroups = [];
        foreach ($parties as $party) {
            $baseRole = preg_replace('/_\d+$/', '', $party['role']);
            $roleGroups[$baseRole][] = $party;
        }

        // Count signature locations per role from the template's blade view.
        // Inline signature-line includes + final signature-block entries give
        // the total number of distinct locations each role must sign.
        $locationsByRole = $this->countSignatureLocationsPerRole($sigTemplate);

        $sortOrder = 0;
        $totalMarkers = 0;

        // Build a flat list of all zone placements (role + location index)
        // so we can space them evenly through the document's signature area.
        $zonePlacements = [];
        foreach ($roleGroups as $baseRole => $roleParties) {
            if ($baseRole === 'supervisor' && !$isCandidateFlow) {
                continue;
            }

            $locationCount = $locationsByRole[$baseRole] ?? 1;
            for ($loc = 0; $loc < $locationCount; $loc++) {
                $zonePlacements[] = [
                    'baseRole' => $baseRole,
                    'roleParties' => $roleParties,
                    'locationIndex' => $loc,
                    'locationCount' => $locationCount,
                    'isFinal' => ($loc === $locationCount - 1),
                ];
            }
        }

        // Sort zones: inline zones first (earlier in doc), then final zones,
        // agent always last (agent signs at the final signature section).
        usort($zonePlacements, function ($a, $b) {
            // Agent final zones always sort last
            $aIsAgentFinal = ($a['baseRole'] === 'agent' && $a['isFinal']);
            $bIsAgentFinal = ($b['baseRole'] === 'agent' && $b['isFinal']);
            if ($aIsAgentFinal !== $bIsAgentFinal) {
                return $aIsAgentFinal ? 1 : -1;
            }
            // Inline zones before final zones
            if ($a['isFinal'] !== $b['isFinal']) {
                return $a['isFinal'] ? 1 : -1;
            }
            // Within same category, preserve role order then location index
            $roleOrder = strcmp($a['baseRole'], $b['baseRole']);
            if ($roleOrder !== 0) {
                return $roleOrder;
            }
            return $a['locationIndex'] <=> $b['locationIndex'];
        });

        // Distribute zones through the signature area of the document.
        // Inline sigs start around 50%, final sigs around 85-92%.
        // With N total zones, space them evenly between 50% and 92%.
        $totalZones = count($zonePlacements);
        $startY = ($totalZones > 1) ? 50 : 85;
        $endY = 92;
        $spacing = ($totalZones > 1) ? ($endY - $startY) / ($totalZones - 1) : 0;

        foreach ($zonePlacements as $idx => $placement) {
            $baseRole = $placement['baseRole'];
            $roleParties = $placement['roleParties'];
            $locIdx = $placement['locationIndex'];
            $locCount = $placement['locationCount'];

            $sortOrder++;

            // Calculate zone Y position — evenly distributed
            $zoneY = $startY + ($idx * $spacing);
            $zoneY = min($zoneY, 94);

            // Zone height — fixed at 6%, hard-clamped at 10%
            $zoneHeight = 6.0;
            $zoneHeight = min($zoneHeight, 10.0);

            // Zone width: multi-party = full width, single-party = half width
            $partyCount = count($roleParties);
            $zoneWidth = $partyCount === 1 ? 45 : 80;
            $zoneX = 5;

            // Label distinguishes inline vs final locations
            $locLabel = $locCount > 1
                ? ($placement['isFinal'] ? ' (Final)' : ' (Inline ' . ($locIdx + 1) . ')')
                : '';

            $zone = SignatureZone::create([
                'signature_template_id' => $sigTemplate->id,
                'zone_type' => SignatureZone::TYPE_SIGNATURE,
                'party_role' => $baseRole,
                'page_number' => $pageCount,
                'x_position' => $zoneX,
                'y_position' => round($zoneY, 2),
                'width' => $zoneWidth,
                'height' => $zoneHeight,
                'is_auto_placed' => true,
                'source' => SignatureZone::SOURCE_TEMPLATE,
                'label' => ucfirst($baseRole) . ' Signature Zone' . $locLabel,
                'sort_order' => $sortOrder,
            ]);

            // Expand zone into individual markers
            $blocks = $renderer->renderZone($zone, $roleParties);
            $totalMarkers += $this->createMarkersFromBlocks($sigTemplate, $zone, $blocks);
        }

        // Create initial zones on every page except the last
        if ($pageCount > 1) {
            $allParties = $parties;
            // Remove supervisor if not candidate flow
            if (!$isCandidateFlow) {
                $allParties = array_filter($allParties, function ($p) {
                    return preg_replace('/_\d+$/', '', $p['role']) !== 'supervisor';
                });
                $allParties = array_values($allParties);
            }

            for ($page = 1; $page < $pageCount; $page++) {
                $sortOrder++;

                $zone = SignatureZone::create([
                    'signature_template_id' => $sigTemplate->id,
                    'zone_type' => SignatureZone::TYPE_INITIAL,
                    'party_role' => 'all', // All parties initial on each page
                    'page_number' => $page,
                    'x_position' => 80,
                    'y_position' => 90,
                    'width' => 15,
                    'height' => 8,
                    'is_auto_placed' => true,
                    'source' => SignatureZone::SOURCE_TEMPLATE,
                    'label' => 'Initials — Page ' . $page,
                    'sort_order' => $sortOrder,
                ]);

                $blocks = $renderer->renderInitialZone($zone, $allParties);
                $totalMarkers += $this->createMarkersFromBlocks($sigTemplate, $zone, $blocks);
            }
        }

        return $totalMarkers;
    }

    /**
     * Expand a single zone into markers based on the current party list.
     * Deletes existing markers for this zone first (idempotent).
     */
    public function expandZone(SignatureZone $zone, array $parties): int
    {
        $renderer = app(SignatureZoneRenderer::class);
        $sigTemplate = $zone->template;

        // Remove existing markers from this zone
        $sigTemplate->markers()->where('from_zone_id', $zone->id)->forceDelete();

        // Get parties matching the zone's assigned roles.
        // assigned_parties (JSON array) supports multi-party zones (e.g. ["agent", "seller"]).
        // Falls back to single party_role for backward compatibility.
        $assignedRoles = $zone->assigned_parties ?? [$zone->party_role];
        if (empty($assignedRoles)) {
            $assignedRoles = [$zone->party_role];
        }

        $matchingParties = [];
        foreach ($assignedRoles as $role) {
            $matchingParties = array_merge($matchingParties, $this->getPartiesForRole($role, $parties));
        }

        if (empty($matchingParties)) {
            return 0;
        }

        if ($zone->zone_type === SignatureZone::TYPE_INITIAL) {
            $blocks = $renderer->renderInitialZone($zone, $matchingParties);
        } else {
            $blocks = $renderer->renderZone($zone, $matchingParties);
        }

        return $this->createMarkersFromBlocks($sigTemplate, $zone, $blocks);
    }

    /**
     * Re-expand all zones on a template (e.g. after parties change).
     */
    public function reExpandAllZones(SignatureTemplate $sigTemplate): int
    {
        $parties = $sigTemplate->parties_json ?? [];
        $zones = $sigTemplate->zones()->orderBy('sort_order')->get();

        $total = 0;
        foreach ($zones as $zone) {
            $total += $this->expandZone($zone, $parties);
        }

        return $total;
    }

    /**
     * Save a zone from the setup screen (user-drawn bounding box).
     */
    public function saveZone(SignatureTemplate $sigTemplate, array $data): SignatureZone
    {
        // Build assigned_parties: use explicit array if provided, else wrap party_role
        $assignedParties = $data['assigned_parties'] ?? [$data['party_role']];
        // Primary party_role remains the first assigned party (for backward compat)
        $primaryRole = $assignedParties[0] ?? $data['party_role'];

        $zone = SignatureZone::create([
            'signature_template_id' => $sigTemplate->id,
            'zone_type' => $data['zone_type'] ?? 'signature',
            'party_role' => $primaryRole,
            'assigned_parties' => $assignedParties,
            'page_number' => $data['page_number'],
            'x_position' => $data['x_position'],
            'y_position' => $data['y_position'],
            'width' => $data['width'],
            'height' => $data['height'],
            'is_auto_placed' => $data['is_auto_placed'] ?? false,
            'source' => $data['source'] ?? SignatureZone::SOURCE_SETUP,
            'label' => $data['label'] ?? (ucfirst($primaryRole) . ' ' . ucfirst($data['zone_type'] ?? 'signature') . ' Zone'),
            'sort_order' => $sigTemplate->zones()->max('sort_order') + 1,
        ]);

        // Immediately expand into markers
        $parties = $sigTemplate->parties_json ?? [];
        $this->expandZone($zone, $parties);

        return $zone;
    }

    /**
     * Update a zone (resize/move) and re-expand its markers.
     */
    public function updateZone(SignatureZone $zone, array $data): SignatureZone
    {
        $zone->update(array_intersect_key($data, array_flip([
            'zone_type', 'party_role', 'assigned_parties', 'page_number',
            'x_position', 'y_position', 'width', 'height', 'label',
        ])));

        // Re-expand markers with new dimensions
        $parties = $zone->template->parties_json ?? [];
        $this->expandZone($zone, $parties);

        return $zone->fresh();
    }

    /**
     * Delete a zone and its expanded markers.
     */
    public function deleteZone(SignatureZone $zone): void
    {
        // Delete expanded markers
        $zone->template->markers()->where('from_zone_id', $zone->id)->forceDelete();
        $zone->delete();
    }

    /**
     * Count signature locations per role by reading the template's blade view.
     *
     * Inline signature-line includes use: ['party' => 'seller']
     * Final signature-block includes use: ["parties" => ["Seller", "Agent"]]
     *
     * Returns ['seller' => 3, 'agent' => 1, ...] — total distinct locations per role.
     */
    protected function countSignatureLocationsPerRole(SignatureTemplate $sigTemplate): array
    {
        $counts = [];

        // Navigate to the Template model via Document
        $document = $sigTemplate->document;
        if (!$document) {
            return $counts;
        }

        $template = $document->template;
        if (!$template || !$template->blade_view) {
            return $counts;
        }

        // Read the blade file content
        $viewPath = str_replace('.', '/', $template->blade_view);
        $bladePath = resource_path("views/{$viewPath}.blade.php");
        if (!file_exists($bladePath)) {
            return $counts;
        }

        $content = file_get_contents($bladePath);

        // Role alias map — blade uses display names, we need base role keys
        $roleAliases = [
            'seller' => 'seller', 'buyer' => 'buyer', 'agent' => 'agent',
            'landlord' => 'landlord', 'tenant' => 'tenant',
            'lessor' => 'landlord', 'lessee' => 'tenant',
            'supervisor' => 'supervisor',
        ];

        // 1. Count inline signature-line includes: signature-line", ['party' => 'seller']
        if (preg_match_all('/signature-line["\'].*?\[\'party\'\s*=>\s*\'(\w+)\'\]/i', $content, $matches)) {
            foreach ($matches[1] as $party) {
                $role = $roleAliases[strtolower($party)] ?? strtolower($party);
                $counts[$role] = ($counts[$role] ?? 0) + 1;
            }
        }

        // 2. Count final signature-block parties: ["parties" => ["Seller", "Agent"]]
        if (preg_match('/signature-block["\'].*?\["parties"\s*=>\s*\[([^\]]+)\]\]/i', $content, $blockMatch)) {
            $partiesStr = $blockMatch[1];
            if (preg_match_all('/["\'](\w+)["\']/i', $partiesStr, $partyMatches)) {
                foreach ($partyMatches[1] as $party) {
                    $role = $roleAliases[strtolower($party)] ?? strtolower($party);
                    $counts[$role] = ($counts[$role] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * Create marker records from block definitions returned by the renderer.
     */
    protected function createMarkersFromBlocks(
        SignatureTemplate $sigTemplate,
        SignatureZone $zone,
        array $blocks
    ): int {
        $sortOrder = $sigTemplate->markers()->max('sort_order') ?? -1;
        $count = 0;

        foreach ($blocks as $block) {
            $sortOrder++;
            $type = $block['zone_type'] === 'initial'
                ? SignatureMarker::TYPE_INITIAL
                : SignatureMarker::TYPE_SIGNATURE;

            $partyRole = $block['party_role'];
            $partyName = $block['party_name'] ?? '';
            $roleDisplay = ucfirst(preg_replace('/_\d+$/', '', $partyRole));
            $typeDisplay = $type === 'initial' ? 'Initial' : 'Signature';

            SignatureMarker::create([
                'signature_template_id' => $sigTemplate->id,
                'page_number' => $zone->page_number,
                'x_position' => $block['x'],
                'y_position' => $block['y'],
                'width' => $block['width'],
                'height' => $block['height'],
                'type' => $type,
                'assigned_party' => $partyRole,
                'assigned_email' => $block['party_email'] ?? null,
                'label' => $partyName
                    ? "{$roleDisplay} — {$partyName} {$typeDisplay}"
                    : "{$roleDisplay} {$typeDisplay}",
                'sort_order' => $sortOrder,
                'required' => true,
                'from_zone_id' => $zone->id,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Get parties matching a zone's role. For 'all' role, returns all parties.
     */
    protected function getPartiesForRole(string $zoneRole, array $parties): array
    {
        if ($zoneRole === 'all') {
            return $parties;
        }

        return array_values(array_filter($parties, function ($p) use ($zoneRole) {
            $baseRole = preg_replace('/_\d+$/', '', $p['role']);
            return $baseRole === $zoneRole;
        }));
    }

    // ──────────────────────────────────────────────
    // Signing requests
    // ──────────────────────────────────────────────

    /**
     * Create a signing request for a party.
     */
    public function createSigningRequest(
        SignatureTemplate $template,
        string $partyRole,
        string $signerName,
        string $signerEmail,
        ?string $signerIdNumber = null,
        ?string $message = null,
        ?User $sentBy = null,
        bool $ficaRequired = false,
        ?int $contactId = null,
        ?int $ficaSubmissionId = null,
        ?int $roleIndex = null,
    ): SignatureRequest {
        $token = $this->generateToken();

        // Recipient Loop Engine B1 — split legacy suffixed party_role into
        // (clean party_role, role_index). Callers that haven't been updated
        // to pass $roleIndex explicitly still emit suffixed strings like
        // 'seller_2'; we split them here so the column-level shape is
        // always clean. Path A semantics: role_index always lives in its
        // own column, party_role is always the base token.
        if ($roleIndex === null && preg_match('/^(.+)_(\d+)$/', $partyRole, $m)) {
            $partyRole = $m[1];
            $roleIndex = (int) $m[2];
        }
        if ($roleIndex === null) {
            $roleIndex = 1;
        }

        // Get the highest existing signing_order for this template, then add 1
        // This ensures co-owners (two landlords) get sequential order numbers
        $maxOrder = SignatureRequest::where('signature_template_id', $template->id)
            ->max('signing_order') ?? 0;
        $signingOrder = $maxOrder + 1;

        $request = SignatureRequest::create([
            'signature_template_id' => $template->id,
            'party_role' => $partyRole,
            'role_index' => $roleIndex,
            'signing_order' => $signingOrder,
            // HD-6 — the group this party signs in, read off the ceremony's own plan. NULL when the
            // ceremony has no plan, which is every ceremony that exists today: an ungrouped party is
            // a group of one and checkpoints alone, exactly as it always has.
            'signing_group' => $template->groupFor($partyRole),
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'signer_id_number' => $signerIdNumber,
            'token' => $token,
            'token_expires_at' => now()->addDays(14),
            'status' => SignatureRequest::STATUS_WAITING,
            'sent_by' => $sentBy?->id,
            'message' => $message,
            'fica_required' => $ficaRequired,
            'contact_id' => $contactId,
            'fica_submission_id' => $ficaSubmissionId,
        ]);

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_CREATED,
            $sentBy ? SignatureAuditLog::ACTOR_USER : SignatureAuditLog::ACTOR_SYSTEM,
            $sentBy ? $sentBy->name : 'System',
            $sentBy?->email,
            $sentBy?->id,
            $request->id,
            metadata: ['party_role' => $partyRole, 'signer_email' => $signerEmail],
        );

        return $request;
    }

    /**
     * Send a signing request (transitions from waiting to pending, sends email).
     */
    public function sendSigningRequest(SignatureRequest $request): void
    {
        // AT-294 — ABSORB an email-less recipient instead of firing a doomed
        // Mail::to('') that throws and is swallowed (silently parking the
        // ceremony as a healthy-looking awaiting_* with no link and no
        // agent-visible error). Route it into the EXISTING deferred machinery:
        // request → DEFERRED, template → AWAITING_DEFERRED — the same
        // recoverable state as "sign later". The token was minted at creation,
        // so nothing is lost; the agent adds an email and resumes via
        // resumeDeferredSigning(). Defence-in-depth with the controller PREVENT
        // guard. Guard the primitive, not one call site (BUILD_STANDARD §6).
        if (trim((string) $request->signer_email) === '') {
            $request->update(['status' => SignatureRequest::STATUS_DEFERRED]);
            $template = $request->template;
            if ($template) {
                $template->update(['status' => SignatureTemplate::STATUS_AWAITING_DEFERRED]);
                SignatureAuditLog::log(
                    $template,
                    'send_skipped_missing_email',
                    SignatureAuditLog::ACTOR_SYSTEM,
                    'System',
                    requestId: $request->id,
                    metadata: ['party_role' => $request->party_role],
                );
            }
            return;
        }

        $request->update([
            'status' => SignatureRequest::STATUS_PENDING,
            'sent_at' => now(),
            'token_expires_at' => now()->addDays(14),
        ]);

        $template = $request->template;

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_SENT,
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            requestId: $request->id,
            metadata: ['signer_email' => $request->signer_email],
        );

        $this->sendSigningRequestEmail($request);
    }

    /**
     * Send the template to the first party (agent) — initiates signing workflow.
     */
    public function sendForSigning(SignatureTemplate $template, User $agent): void
    {
        if (!in_array($template->status, [SignatureTemplate::STATUS_DRAFT, SignatureTemplate::STATUS_READY])) {
            throw new \LogicException('Template must be in draft or ready status to send.');
        }

        DB::transaction(function () use ($template, $agent) {
            // Capture document hash at signing start
            $hash = $this->generateDocumentHash($template->document);
            $template->update([
                'document_hash' => $hash,
                'status' => SignatureTemplate::STATUS_SIGNING,
            ]);

            // Track C (HD-9) — stamp the LEGAL deadline the moment the ceremony goes out. From here
            // on, a signature after this date is void (§11-A), independent of the 14-day link TTL.
            $this->stampLegalDeadline($template);

            // Find or create the agent's request and send it
            $agentRequest = $template->requests()
                ->where('party_role', 'agent')
                ->first();

            if ($agentRequest && $agentRequest->status === SignatureRequest::STATUS_COMPLETED) {
                // Agent already completed (pre-signed wet ink upload) — skip to next party
                $this->advanceToNextParty($template, 'agent');
            } elseif ($agentRequest) {
                // Agent signs in-app — no email needed.
                // Just mark as pending so the signing view knows they are the active signer.
                $agentRequest->update([
                    'status' => SignatureRequest::STATUS_PENDING,
                    'sent_at' => now(),
                ]);
            }
        });
    }

    /**
     * Track C (HD-9) — set the ceremony's legal deadline from its source, at dispatch.
     *
     * A mandate's legal clock is the property's mandate expiry (`properties.expiry_date`, verified to
     * exist). Only a mandate is wired today: an OTP is an alienation document that may not be
     * e-signed at all (ECTA §13(1) — `isEsignBlocked()`), so its irrevocable-date clock has nothing
     * to run against yet, and a lease has no single legal-lapse date. Absorb, never break: if there
     * is no derivable date, leave it null and the ceremony simply never lapses (today's behaviour).
     * Never overwrite a deadline already set (an extension/revival owns it after HD-12).
     */
    private function stampLegalDeadline(SignatureTemplate $template): void
    {
        if ($template->legal_deadline_at !== null) {
            return;
        }

        $document = $template->document;
        $docType  = strtolower((string) ($document?->template?->documentType?->slug
            ?? $document?->template?->template_type ?? ''));

        if ($docType !== 'mandate') {
            return; // Only mandates carry a wired legal clock at launch.
        }

        $property = $document?->property_id ? \App\Models\Property::find($document->property_id) : null;
        $expiry   = $property?->expiry_date;

        if (! $expiry) {
            return; // No mandate expiry on the property → nothing to lapse against.
        }

        // The document is valid for signing UP TO AND INCLUDING the expiry day.
        $template->update([
            'legal_deadline_at' => \Illuminate\Support\Carbon::parse($expiry)->endOfDay(),
            'deadline_source'   => 'mandate_expiry',
        ]);
    }

    // ──────────────────────────────────────────────
    // Signature capture
    // ──────────────────────────────────────────────

    /**
     * Capture a signature on a marker.
     */
    public function captureSignature(
        SignatureMarker $marker,
        ?string $signatureData,
        string $signerName,
        string $signerEmail,
        string $ipAddress,
        ?string $userAgent = null,
        ?SignatureRequest $request = null,
        ?User $signerUser = null,
        string $signatureType = 'drawn',
        ?string $textValue = null
    ): Signature {
        $signature = Signature::create([
            'signature_template_id' => $marker->signature_template_id,
            'signature_marker_id' => $marker->id,
            'signature_request_id' => $request?->id,
            'signer_user_id' => $signerUser?->id,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'signer_ip_address' => $ipAddress,
            'signer_user_agent' => $userAgent,
            'signature_data' => $signatureData,
            'text_value' => $textValue,
            'signature_type' => $signatureType,
            'signed_at' => now(),
        ]);

        $template = $marker->template;

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_SIGNED,
            $signerUser ? SignatureAuditLog::ACTOR_USER : SignatureAuditLog::ACTOR_SIGNER,
            $signerName,
            $signerEmail,
            $signerUser?->id,
            $request?->id,
            $ipAddress,
            $userAgent,
            ['marker_id' => $marker->id, 'marker_type' => $marker->type, 'page' => $marker->page_number],
            $template->document_hash,
        );

        return $signature;
    }

    // ──────────────────────────────────────────────
    // Completion checks
    // ──────────────────────────────────────────────

    /**
     * Check if all required markers for a party have been signed.
     * When $signerEmail is provided, only checks markers assigned to that specific signer
     * (for co-owner support where multiple signers share the same party role).
     */
    public function isPartyComplete(SignatureTemplate $template, string $party, ?string $signerEmail = null): bool
    {
        // All requests for this party role must be completed (handles co-owners)
        $requestQuery = $template->requests()->where('party_role', $party);
        if ($signerEmail) {
            $requestQuery = $requestQuery->where('signer_email', $signerEmail);
        }
        $totalForRole = $requestQuery->count();

        if ($totalForRole === 0) {
            return true; // no requests for this role = not required
        }

        $completedQuery = $template->requests()->where('party_role', $party)
            ->where('status', SignatureRequest::STATUS_COMPLETED);
        if ($signerEmail) {
            $completedQuery = $completedQuery->where('signer_email', $signerEmail);
        }
        $completedForRole = $completedQuery->count();

        if ($completedForRole === $totalForRole) {
            return true;
        }

        // Also check marker-based completion for electronic signing in progress
        $markerQuery = $template->markers()
            ->where('assigned_party', $party)
            ->where('required', true);
        if ($signerEmail) {
            $markerQuery = $markerQuery->where(fn($q) => $q->where('assigned_email', $signerEmail)->orWhereNull('assigned_email'));
        }
        $requiredMarkers = $markerQuery->pluck('id');

        if ($requiredMarkers->isEmpty() && $completedForRole > 0) {
            // No markers but some requests completed — check if all are done
            return $completedForRole === $totalForRole;
        }

        if ($requiredMarkers->isEmpty()) {
            return true;
        }

        $signedMarkerIds = $template->signatures()
            ->whereIn('signature_marker_id', $requiredMarkers)
            ->pluck('signature_marker_id')
            ->unique();

        return $requiredMarkers->diff($signedMarkerIds)->isEmpty();
    }

    /**
     * Check if all parties have completed signing.
     */
    public function isFullyComplete(SignatureTemplate $template): bool
    {
        // Document is fully complete when zero waiting/pending/deferred requests remain
        return !$template->requests()
            ->whereIn('status', [
                SignatureRequest::STATUS_WAITING,
                SignatureRequest::STATUS_PENDING,
                SignatureRequest::STATUS_VIEWED,
                SignatureRequest::STATUS_PARTIALLY_SIGNED,
                SignatureRequest::STATUS_DEFERRED,
            ])
            ->exists();
    }

    /**
     * Handle party completion — if a non-agent party finished, require agent approval
     * before advancing. Agent signing auto-advances to the next external party.
     */
    public function handlePartyCompletion(SignatureTemplate $template, string $completedParty, ?SignatureRequest $completedRequest = null): void
    {
        DB::transaction(function () use ($template, $completedParty, $completedRequest) {
            // Find the specific request that just completed (caller should pass it)
            $request = $completedRequest;

            if (!$request) {
                // Fallback: find any non-completed request for this role and mark it
                $request = $template->requests()
                    ->where('party_role', $completedParty)
                    ->where('status', '!=', SignatureRequest::STATUS_COMPLETED)
                    ->first();
            }

            if ($request && $request->status !== SignatureRequest::STATUS_COMPLETED) {
                $request->update([
                    'status' => SignatureRequest::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
            }

            // If an external party (non-agent, non-supervisor) just completed, require agent approval
            if ($completedParty !== 'agent' && $completedParty !== 'supervisor' && $completedParty !== 'supervisor_final') {

                // HD-5 (§4) — the checkpoint fires between GROUPS, not between people.
                //
                // Joint sellers signing the same mandate are one group: asking the agent to authorise
                // the gap between seller 1 and seller 2 is friction with no decision in it. So if this
                // party's group still has someone to sign, hand straight on to them — no checkpoint.
                //
                // A party with NO group (signing_group NULL) is a group of one and checkpoints on its
                // own, exactly as every ceremony does today. That is the default, so nothing that
                // exists changes behaviour until a ceremony deliberately groups its parties.
                $nextInGroup = $request ? $this->nextWaitingInGroup($template, $request) : null;

                if ($nextInGroup) {
                    SignatureAuditLog::log(
                        $template,
                        'group_member_completed',
                        SignatureAuditLog::ACTOR_SYSTEM,
                        'System',
                        metadata: [
                            'completed_party' => $completedParty,
                            'signer_name'     => $request?->signer_name,
                            'signing_group'   => $request?->signing_group,
                            'next_in_group'   => $nextInGroup->signer_name,
                        ],
                    );

                    // Hand the pen to the next member of the SAME group. Sequential within the group is
                    // deliberate: two people inside one signing view at once is how captured-but-unsaved
                    // signatures get destroyed (STANDARDS, the P0 signing-view invariant).
                    $this->advanceToNextParty($template, $completedParty, $nextInGroup);

                    return;
                }

                // The group is finished (or this party was a group of one) → the agent checkpoint.
                $template->update(['status' => SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL]);

                SignatureAuditLog::log(
                    $template,
                    'pending_agent_approval',
                    SignatureAuditLog::ACTOR_SYSTEM,
                    'System',
                    metadata: [
                        'completed_party' => $completedParty,
                        'signer_name' => $request?->signer_name,
                    ],
                );

                // Notify the agent
                $this->sendAgentApprovalNotification($template, $completedParty, $request);
                return;
            }

            // Supervisor initial review completed — record who authorised, advance to external parties
            if ($completedParty === 'supervisor') {
                // Record authorised_by audit trail on the request
                if ($request) {
                    $request->update([
                        'authorised_by' => $request->authorised_by ?? auth()->id(),
                        'authorised_at' => $request->authorised_at ?? now(),
                    ]);
                }

                $authoriserName = $request?->authorised_by
                    ? (User::find($request->authorised_by)?->name ?? 'Authoriser')
                    : ($request?->signer_name ?? 'Authoriser');

                SignatureAuditLog::log(
                    $template,
                    'supervisor_authorised',
                    SignatureAuditLog::ACTOR_USER,
                    $authoriserName,
                    metadata: [
                        'completed_party' => 'supervisor',
                        'authorised_by' => $request?->authorised_by,
                    ],
                );
                $this->advanceToNextParty($template, $completedParty);
                return;
            }

            // Supervisor final sign-off — record who authorised, complete the document
            if ($completedParty === 'supervisor_final') {
                // Record authorised_by audit trail on the request
                if ($request) {
                    $request->update([
                        'authorised_by' => $request->authorised_by ?? auth()->id(),
                        'authorised_at' => $request->authorised_at ?? now(),
                    ]);
                }

                $authoriserName = $request?->authorised_by
                    ? (User::find($request->authorised_by)?->name ?? 'Authoriser')
                    : ($request?->signer_name ?? 'Authoriser');

                SignatureAuditLog::log(
                    $template,
                    'supervisor_final_signoff',
                    SignatureAuditLog::ACTOR_USER,
                    $authoriserName,
                    metadata: [
                        'completed_party' => 'supervisor_final',
                        'authorised_by' => $request?->authorised_by,
                    ],
                );
                $this->completeDocument($template);
                return;
            }

            // Agent just finished signing
            if ($template->is_candidate_flow) {
                // Candidate flow: route to supervisor for initial review (not directly to external parties)
                $this->advanceToSupervisor($template);
            } else {
                // Full status flow: auto-advance to the first external party
                $this->advanceToNextParty($template, $completedParty);
            }
        });
    }

    /**
     * Agent approves and advances to the next party (or completes the document).
     */
    public function approveAndAdvance(SignatureTemplate $template): array
    {
        return DB::transaction(function () use ($template) {
            // Find next waiting request by signing_order (not by role name)
            // This correctly handles co-owners who share the same party_role
            $nextRequest = $template->requests()
                ->where('status', SignatureRequest::STATUS_WAITING)
                ->orderBy('signing_order', 'asc')
                ->first();

            if ($nextRequest) {
                // Recalculate hash before sending to next external party
                $template->update([
                    'document_hash' => $this->generateDocumentHash($template->document),
                ]);

                // Transition to next party
                $statusMap = [
                    'tenant' => SignatureTemplate::STATUS_AWAITING_TENANT,
                    'landlord' => SignatureTemplate::STATUS_AWAITING_LANDLORD,
                    'buyer' => SignatureTemplate::STATUS_AWAITING_BUYER,
                    'seller' => SignatureTemplate::STATUS_AWAITING_SELLER,
                    'supervisor' => SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
                    'supervisor_final' => SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
                ];
                $newStatus = $statusMap[$nextRequest->party_role] ?? SignatureTemplate::STATUS_SIGNING;
                $template->update(['status' => $newStatus]);

                // Supervisor steps: notify all eligible authorisers (shared queue)
                if (in_array($nextRequest->party_role, ['supervisor', 'supervisor_final'])) {
                    $nextRequest->update([
                        'status'  => SignatureRequest::STATUS_PENDING,
                        'sent_at' => now(),
                    ]);
                    $notifyType = $nextRequest->party_role === 'supervisor_final' ? 'final_signoff' : 'initial_review';
                    $this->notifyEligibleAuthorisers($template, $notifyType);
                } else {
                    $this->sendSigningRequest($nextRequest);
                }

                SignatureAuditLog::log(
                    $template,
                    'agent_approved_advance',
                    SignatureAuditLog::ACTOR_USER,
                    $template->creator?->name ?? 'Agent',
                    $template->creator?->email,
                    $template->created_by,
                    metadata: ['next_party' => $nextRequest->party_role],
                );

                return ['action' => 'sent', 'next_party' => $nextRequest->party_role, 'next_name' => $nextRequest->signer_name];
            }

            // Candidate flow: route to authorisation queue for final sign-off instead of completing
            if ($template->is_candidate_flow) {
                $supervisorFinalRequest = $template->requests()
                    ->where('party_role', 'supervisor_final')
                    ->whereIn('status', [SignatureRequest::STATUS_WAITING, SignatureRequest::STATUS_PENDING])
                    ->first();

                if ($supervisorFinalRequest && $supervisorFinalRequest->status !== SignatureRequest::STATUS_COMPLETED) {
                    $template->update([
                        'status' => SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
                        'document_hash' => $this->generateDocumentHash($template->document),
                    ]);

                    // Mark request as pending (shared queue — no specific person)
                    $supervisorFinalRequest->update([
                        'status'  => SignatureRequest::STATUS_PENDING,
                        'sent_at' => now(),
                    ]);

                    // Notify ALL eligible authorisers
                    $this->notifyEligibleAuthorisers($template, 'final_signoff');

                    SignatureAuditLog::log(
                        $template,
                        'candidate_routed_to_authorisation_queue_final',
                        SignatureAuditLog::ACTOR_SYSTEM,
                        'System',
                        metadata: ['notification' => 'all_eligible_authorisers'],
                    );

                    return ['action' => 'sent', 'next_party' => 'supervisor_final', 'next_name' => 'Authorisation Queue'];
                }
            }

            // Check for deferred requests — pause flow if next party is deferred
            $deferredRequest = $template->requests()
                ->where('status', SignatureRequest::STATUS_DEFERRED)
                ->orderBy('signing_order', 'asc')
                ->first();

            if ($deferredRequest) {
                $template->update([
                    'status' => SignatureTemplate::STATUS_AWAITING_DEFERRED,
                ]);

                SignatureAuditLog::log(
                    $template,
                    'flow_paused_deferred',
                    SignatureAuditLog::ACTOR_SYSTEM,
                    'System',
                    metadata: [
                        'deferred_party' => $deferredRequest->party_role,
                        'reason' => 'Party details not yet known',
                    ],
                );

                return ['action' => 'deferred', 'deferred_party' => $deferredRequest->party_role];
            }

            // All parties done — complete the document
            $this->completeDocument($template);

            SignatureAuditLog::log(
                $template,
                'agent_approved_complete',
                SignatureAuditLog::ACTOR_USER,
                $template->creator?->name ?? 'Agent',
                $template->creator?->email,
                $template->created_by,
            );

            return ['action' => 'completed'];
        });
    }

    /**
     * Resume a deferred signing request — agent provides party details, flow picks up.
     */
    public function resumeDeferredSigning(
        SignatureTemplate $template,
        SignatureRequest $deferredRequest,
        string $name,
        string $email,
        ?string $idNumber = null,
        ?string $cell = null
    ): array {
        return DB::transaction(function () use ($template, $deferredRequest, $name, $email, $idNumber, $cell) {
            // Update the deferred request with the new party details
            $deferredRequest->update([
                'signer_name' => $name,
                'signer_email' => $email,
                'signer_id_number' => $idNumber,
                'status' => SignatureRequest::STATUS_WAITING,
            ]);

            // Update the parties_json to reflect the new details
            $parties = $template->parties_json ?? [];
            foreach ($parties as &$party) {
                if ($party['role'] === $deferredRequest->party_role) {
                    $party['name'] = $name;
                    $party['email'] = $email;
                    $party['id_number'] = $idNumber ?? '';
                    break;
                }
            }
            unset($party);
            $template->update(['parties_json' => $parties]);

            SignatureAuditLog::log(
                $template,
                'deferred_signing_resumed',
                SignatureAuditLog::ACTOR_USER,
                auth()->user()?->name ?? 'Agent',
                auth()->user()?->email,
                auth()->id(),
                $deferredRequest->id,
                metadata: [
                    'party_role' => $deferredRequest->party_role,
                    'signer_name' => $name,
                    'signer_email' => $email,
                ],
            );

            // Now advance — the request is "waiting", so advanceToNextParty will pick it up
            $this->advanceToNextParty($template, 'deferred_resume');

            return ['action' => 'resumed', 'party_role' => $deferredRequest->party_role, 'signer_name' => $name];
        });
    }

    /**
     * Advance to next party in signing order (used after agent signs).
     */
    /**
     * HD-5 — the next party still to sign INSIDE this request's group, or null if the group is done.
     *
     * NULL `signing_group` is not "group zero" — it means the party stands alone, so it never has a
     * next-in-group and always reaches the agent checkpoint. This is what makes the feature opt-in
     * and every existing ceremony byte-for-byte unchanged.
     */
    private function nextWaitingInGroup(SignatureTemplate $template, SignatureRequest $completed): ?SignatureRequest
    {
        if ($completed->signing_group === null) {
            return null;
        }

        return $template->requests()
            ->where('signing_group', $completed->signing_group)
            ->where('status', SignatureRequest::STATUS_WAITING)
            ->orderBy('signing_order', 'asc')
            ->first();
    }

    /**
     * @param  SignatureRequest|null  $only  HD-5 — release THIS request specifically (the next member of
     *                                       the completing party's group). Without it the method takes
     *                                       the next waiting request globally, which is right at a group
     *                                       boundary but would skip a group whose members are not
     *                                       contiguous in signing_order. Passing the target explicitly
     *                                       means the caller's intent cannot be lost to a data ordering.
     */
    private function advanceToNextParty(SignatureTemplate $template, string $completedParty, ?SignatureRequest $only = null): void
    {
        // Find the next WAITING request by signing_order — not by role name
        // This correctly handles co-owners who share the same party_role string
        $nextRequest = $only ?: $template->requests()
            ->where('status', SignatureRequest::STATUS_WAITING)
            ->orderBy('signing_order', 'asc')
            ->first();

        // If no waiting request, check for deferred requests (sign later)
        if (!$nextRequest) {
            $deferredRequest = $template->requests()
                ->where('status', SignatureRequest::STATUS_DEFERRED)
                ->orderBy('signing_order', 'asc')
                ->first();

            if ($deferredRequest) {
                // Flow pauses — document is partial, awaiting deferred party details
                $template->update([
                    'status' => SignatureTemplate::STATUS_AWAITING_DEFERRED,
                ]);

                SignatureAuditLog::log(
                    $template,
                    'flow_paused_deferred',
                    SignatureAuditLog::ACTOR_SYSTEM,
                    'System',
                    metadata: [
                        'deferred_party' => $deferredRequest->party_role,
                        'reason' => 'Party details not yet known',
                    ],
                );
                return;
            }

            if ($this->isFullyComplete($template)) {
                $this->completeDocument($template);
            }
            return;
        }

        $statusMap = [
            'tenant'           => SignatureTemplate::STATUS_AWAITING_TENANT,
            'landlord'         => SignatureTemplate::STATUS_AWAITING_LANDLORD,
            'buyer'            => SignatureTemplate::STATUS_AWAITING_BUYER,
            'seller'           => SignatureTemplate::STATUS_AWAITING_SELLER,
            'supervisor'       => SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
            'supervisor_final' => SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
        ];
        $newStatus = $statusMap[$nextRequest->party_role] ?? SignatureTemplate::STATUS_SIGNING;

        $template->update([
            'status'        => $newStatus,
            'document_hash' => $this->generateDocumentHash($template->document),
        ]);

        // Supervisor steps: notify all eligible authorisers (shared queue)
        if (in_array($nextRequest->party_role, ['supervisor', 'supervisor_final'])) {
            $nextRequest->update([
                'status'  => SignatureRequest::STATUS_PENDING,
                'sent_at' => now(),
            ]);
            $notifyType = $nextRequest->party_role === 'supervisor_final' ? 'final_signoff' : 'initial_review';
            $this->notifyEligibleAuthorisers($template, $notifyType);
        } else {
            $this->sendSigningRequest($nextRequest);
        }
    }

    /**
     * Candidate flow: advance to authorisation queue after candidate signs.
     * Shared queue: emails ALL eligible authorisers in the branch.
     */
    private function advanceToSupervisor(SignatureTemplate $template): void
    {
        $supervisorRequest = $template->requests()
            ->where('party_role', 'supervisor')
            ->where('status', SignatureRequest::STATUS_WAITING)
            ->first();

        if ($supervisorRequest) {
            $template->update([
                'status'        => SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
                'document_hash' => $this->generateDocumentHash($template->document),
            ]);

            // Mark request as pending (but don't send to a single person)
            $supervisorRequest->update([
                'status'  => SignatureRequest::STATUS_PENDING,
                'sent_at' => now(),
            ]);

            // Notify ALL eligible authorisers in the branch
            $this->notifyEligibleAuthorisers($template, 'initial_review');

            SignatureAuditLog::log(
                $template,
                'candidate_routed_to_authorisation_queue',
                SignatureAuditLog::ACTOR_SYSTEM,
                'System',
                metadata: [
                    'candidate_name' => $template->creator?->name,
                    'notification' => 'all_eligible_authorisers',
                ],
            );
        } else {
            // Supervisor already completed — advance to external parties
            $this->advanceToNextParty($template, 'supervisor');
        }
    }

    /**
     * Notify all eligible authorisers in the candidate's branch.
     * Shared queue: any of them can review and authorise from the dashboard.
     */
    private function notifyEligibleAuthorisers(SignatureTemplate $template, string $type = 'initial_review'): void
    {
        try {
            $candidateUser = $template->creator;
            if (!$candidateUser) {
                return;
            }

            $candidateService = app(CandidatePractitionerService::class);
            $authorisers = $candidateService->getEligibleAuthorisers($candidateUser);
            $documentName = $template->document->name ?? 'Document';
            $dashboardUrl = route('docuperfect.rental');

            // ES-7 — dedicated SupervisorApprovalMail (replaces placeholder
            // copy that previously rode on SigningRequestMail with a
            // subject like "Please sign: [Candidate Authorisation] ...").
            $document        = $template->document;
            $documentType    = $document?->document_type ?? null;
            $documentTypeLbl = $documentType
                ? ucwords(str_replace('_', ' ', $documentType))
                : null;

            // Best-effort recipient + property surfacing for the email body
            $firstRequest = $template->requests()
                ->whereNotIn('party_role', ['agent', 'supervisor', 'supervisor_final', 'witness'])
                ->orderBy('signing_order')
                ->first();
            $contactName     = $firstRequest?->signer_name;
            $propertyAddress = $document?->property_address;

            foreach ($authorisers as $authoriser) {
                try {
                    Mail::to($authoriser->email)->send(
                        (new \App\Mail\Signatures\SupervisorApprovalMail(
                            supervisorName:    $authoriser->name,
                            candidateName:     $candidateUser->name,
                            documentName:      $documentName,
                            documentTypeLabel: $documentTypeLbl,
                            contactName:       $contactName,
                            propertyAddress:   $propertyAddress,
                            candidatePhone:    $candidateUser->phone ?? $candidateUser->cell ?? null,
                            reviewUrl:         $dashboardUrl,
                            expiresAt:         now()->addDays(7),
                            reviewType:        $type,
                        ))->fromAgent($candidateUser)
                    );
                } catch (\Throwable $e) {
                    Log::error('Failed to send authorisation notification', [
                        'authoriser_id' => $authoriser->id,
                        'template_id' => $template->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            SignatureAuditLog::log(
                $template,
                'authorisation_notifications_sent',
                SignatureAuditLog::ACTOR_SYSTEM,
                'System',
                metadata: [
                    'type' => $type,
                    'notified_count' => $authorisers->count(),
                    'notified_users' => $authorisers->pluck('name')->toArray(),
                ],
            );
        } catch (\Throwable $e) {
            Log::error('Failed to notify eligible authorisers', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return a document from supervisor to candidate with notes.
     * Candidate practitioner flow only.
     */
    public function returnToCandidate(SignatureTemplate $template, string $notes, User $supervisor): array
    {
        return DB::transaction(function () use ($template, $notes, $supervisor) {
            $candidateUser = $template->creator;
            $candidateName = $candidateUser?->name ?? 'Candidate';

            // Set the supervisor's request back to waiting
            $supervisorRequest = $template->requests()
                ->where('party_role', 'supervisor')
                ->where('status', SignatureRequest::STATUS_COMPLETED)
                ->first();

            if ($supervisorRequest) {
                $supervisorRequest->update([
                    'status' => SignatureRequest::STATUS_WAITING,
                    'completed_at' => null,
                    'returned_notes' => $notes,
                ]);
            }

            // Set the candidate's (agent) request to a returned state
            $candidateRequest = $template->requests()
                ->where('party_role', 'agent')
                ->first();

            if ($candidateRequest) {
                $candidateRequest->update([
                    'returned_notes' => $notes,
                ]);
            }

            // Update template status
            $template->update([
                'status' => SignatureTemplate::STATUS_RETURNED_TO_CANDIDATE,
            ]);

            SignatureAuditLog::log(
                $template,
                'supervisor_returned_to_candidate',
                SignatureAuditLog::ACTOR_USER,
                $supervisor->name,
                $supervisor->email,
                $supervisor->id,
                metadata: [
                    'notes' => $notes,
                    'candidate_name' => $candidateName,
                ],
            );

            // TODO: Send email notification to candidate about the return
            // Mail::to($candidateUser->email)->send(new SupervisorReturnedDocumentMail(...));

            return [
                'candidate_name' => $candidateName,
                'notes' => $notes,
            ];
        });
    }

    /**
     * Advance to next party after wet-ink approval. The wet-ink review
     * itself serves as the agent's approval, so we skip pending_agent_approval.
     */
    private function advanceAfterWetInkApproval(SignatureTemplate $template, string $completedParty): void
    {
        // Find next waiting request by signing_order (handles co-owners)
        $nextRequest = $template->requests()
            ->where('status', SignatureRequest::STATUS_WAITING)
            ->orderBy('signing_order', 'asc')
            ->first();

        if ($nextRequest) {
            $template->update([
                'document_hash' => $this->generateDocumentHash($template->document),
            ]);

            $statusMap = [
                'tenant'   => SignatureTemplate::STATUS_AWAITING_TENANT,
                'landlord' => SignatureTemplate::STATUS_AWAITING_LANDLORD,
                'buyer'    => SignatureTemplate::STATUS_AWAITING_BUYER,
                'seller'   => SignatureTemplate::STATUS_AWAITING_SELLER,
            ];
            $newStatus = $statusMap[$nextRequest->party_role] ?? SignatureTemplate::STATUS_SIGNING;
            $template->update(['status' => $newStatus]);

            $this->sendSigningRequest($nextRequest);

            SignatureAuditLog::log(
                $template,
                'wet_ink_approved_advance',
                SignatureAuditLog::ACTOR_SYSTEM,
                'System',
                metadata: [
                    'completed_party' => $completedParty,
                    'next_party' => $nextRequest->party_role,
                ],
            );
        } elseif ($this->isFullyComplete($template)) {
            $this->completeDocument($template);
        }
    }

    /**
     * Mark the document as fully signed, generate PDF, and email all parties.
     */
    public function completeDocument(SignatureTemplate $template): void
    {
        // 1. Lock the document
        $template->update([
            'status' => SignatureTemplate::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_COMPLETED,
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            documentHash: $template->document_hash,
        );

        // Steps 2-6 run AFTER the completion commits. Completion (status +
        // audit above) is the legal record and must be durable on its own.
        // PDF generation (Puppeteer) is slow/external-failure-prone and was
        // previously executed INSIDE approveAndAdvance's DB::transaction, so
        // a 2-minute Puppeteer hang + force-close rolled back a legally
        // completed signing and made retries hang identically. Deferring via
        // DB::afterCommit guarantees: completion is committed first; a slow
        // or failing PDF/file/email NEVER undoes completion; failures are
        // logged and recoverable. (No active transaction => runs inline,
        // same effect, still after the status write.)
        DB::afterCommit(function () use ($template) {
            try {
                // 2. Generate both signed PDF versions (internal + client)
                $pdfPaths = $this->pdfService->generate($template);

                if ($pdfPaths) {
                    $template->update([
                        'signed_pdf_path' => $pdfPaths['internal'],
                        'signed_pdf_client_path' => $pdfPaths['client'],
                    ]);

                    SignatureAuditLog::log(
                        $template,
                        SignatureAuditLog::ACTION_DOCUMENT_COMPLETED,
                        SignatureAuditLog::ACTOR_SYSTEM,
                        'System',
                        metadata: [
                            'signed_pdf_path' => $pdfPaths['internal'],
                            'signed_pdf_client_path' => $pdfPaths['client'],
                            'total_signatures' => $template->signatures()->count(),
                            'parties_completed' => $template->partyProgress(),
                        ],
                        documentHash: $template->document_hash,
                    );
                } else {
                    Log::error('SignatureService: Signed PDF generation failed, emails will NOT include PDF attachment', [
                        'template_id' => $template->id,
                        'document_id' => $template->document_id,
                        'document_name' => $template->document->name ?? 'unknown',
                        'has_flattened_pages' => !empty($template->flattened_pages_json),
                        'page_count' => $template->document->template?->page_count ?? 0,
                    ]);
                }

                // 3. Link document to contacts via pivot (FICA / compliance)
                $this->linkDocumentToContacts($template, $pdfPaths);

                // 4. Auto-file signed document to Contact + Property Drive.
                //
                // HD-7 (§11-B) — THIS NOW RUNS BEFORE THE EMAIL, AND THAT REORDER *IS* THE FIX.
                // Filing is what produces the per-document PDFs; emailing used to run first, so the
                // only file that existed when the parties were written to was the one merged PDF of
                // the whole pack. The ceremony was not CHOOSING to send a stapled document — it was
                // sending the only thing that existed yet. File first, and the documents are there.
                $signedDocuments = $this->autoFileSignedDocument($template, $pdfPaths);

                // 5. Email signed copies — client to signers, internal to agent.
                $this->sendCompletionEmails($template, $pdfPaths, $signedDocuments);

                // 6. Extract lease data if this is a lease/rental document
                if ($this->isLeaseDocument($template)) {
                    $this->createLeaseRecord($template);
                }
            } catch (\Throwable $e) {
                // The signing is already COMPLETED and committed. Post-
                // completion delivery/filing failure is logged and
                // recoverable — it must NOT surface as a rollback or a
                // 500 that implies the signing failed.
                Log::error('SignatureService: post-completion (PDF/file/email) step failed — document remains COMPLETED', [
                    'template_id' => $template->id,
                    'document_id' => $template->document_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });
    }

    /**
     * Link completed document to all signing party contacts via pivot.
     */
    private function linkDocumentToContacts(SignatureTemplate $template, ?array $pdfPaths): void
    {
        $document = $template->document;
        if (!$document) return;

        $docTemplate = $document->template;
        $documentType = $docTemplate?->template_type ?? $document->document_type ?? 'other';

        // Determine if this is a FICA document
        $isFica = false;
        $docName = strtolower($document->name ?? '');
        if (str_contains($docName, 'fica') || str_contains($docName, 'kyc')) {
            $isFica = true;
            $documentType = 'fica';
        }

        foreach ($template->requests as $request) {
            if (!$request->signer_email || $request->party_role === 'agent') continue;

            // Find matching contact by email
            // AT-125 — resolve against ALL of a contact's emails (child tables), not just the mirror.
            $contact = app(\App\Services\Communications\ContactIdentifierResolver::class)
                ->resolve($request->signer_email, (int) $template->agency_id);
            if (!$contact) continue;

            // Link or update — atomic to prevent duplicate entry on concurrent requests
            \Illuminate\Support\Facades\DB::table('document_contact')->updateOrInsert(
                [
                    'document_id' => $document->id,
                    'contact_id' => $contact->id,
                    'party_role' => $request->party_role,
                ],
                [
                    'document_type' => $documentType,
                    'is_signed' => true,
                    'signed_at' => $request->completed_at ?? now(),
                    'signed_pdf_path' => $pdfPaths['client'] ?? null,
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Auto-file signed document to Contact Drive and Property Drive.
     * Creates ONE Document record, links to all signing contacts and property via pivots.
     */
    /**
     * @return array<int,array{path:string,name:string,template_id:?int,is_signed_document:bool}>
     *   HD-7 — the documents this actually FILED. It already wrote them; it simply threw the paths
     *   away, which is why completion had nothing to attach but the merged PDF. Now it hands them back.
     */
    private function autoFileSignedDocument(SignatureTemplate $template, ?array $pdfPaths): array
    {
        if (!$pdfPaths || empty($pdfPaths['client'])) return [];

        $document = $template->document;
        if (!$document) return [];

        $webTemplateData = $document->web_template_data ?? [];
        $templateIds = $webTemplateData['template_ids'] ?? [];
        // §19 Option 2 — split/file from the EXACT signed-and-paginated DOM
        // (per-document .corex-a4-page + per-page initials, as the signer
        // saw). Fall back to canonical merged_html for legacy / never-web-
        // signed documents. The server never re-paginates here.
        $signedPaginated = $document->signed_paginated_html;
        $mergedHtml = (is_string($signedPaginated) && trim($signedPaginated) !== '')
            ? $signedPaginated
            : ($webTemplateData['merged_html'] ?? '');
        $propertyId = $document->property_id;

        // Resolve signing contacts once (shared across all filed documents)
        $contactLinks = $this->resolveSigningContacts($template);

        // Pack flow: split into individual documents per template
        if (count($templateIds) > 1 && $mergedHtml) {
            return $this->filePackDocuments($template, $document, $templateIds, $mergedHtml, $propertyId, $contactLinks, $pdfPaths);
        }

        // Single template: file one document using the merged PDF
        $filed = $this->fileSingleDocument($template, $document, $pdfPaths['client'], $propertyId, $contactLinks);

        return $filed ? [$filed] : [];
    }

    /**
     * File a single document (non-pack or single-template pack).
     *
     * @return array{path:string,name:string,template_id:?int,is_signed_document:bool}|null
     */
    private function fileSingleDocument(
        SignatureTemplate $template,
        $document,
        string $pdfPath,
        ?int $propertyId,
        array $contactLinks,
    ): ?array {
        // Avoid duplicate filings
        if (\App\Models\Document::where('storage_path', $pdfPath)->where('source_type', 'esign')->exists()) {
            return null;
        }

        $docTemplate = $document->template;
        $docName = ($document->name ?? 'Signed Document') . ' (Signed).pdf';

        // FIX 2 — never file a Document that points at a non-existent PDF.
        // Validate via the SAME disk Document::downloadResponse() reads
        // (Storage::disk('local')) so a guard pass GUARANTEES the download
        // works. is_file(storage_path('app/..')) checked the wrong root
        // (one dir outside the disk) → guard passed, download 500'd.
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (!$disk->exists($pdfPath)) {
            Log::error('Auto-file: refusing to create a Document for a missing PDF', [
                'template_id'  => $template->id,
                'document_id'  => $document->id ?? null,
                'storage_path' => $pdfPath,
                'recoverable'  => true,
            ]);
            return null;
        }

        $filedDoc = \App\Models\Document::create([
            'original_name'    => $docName,
            'storage_path'     => $pdfPath,
            'disk'             => 'local',
            'mime_type'        => 'application/pdf',
            'size'             => $disk->size($pdfPath),
            'document_type_id' => $docTemplate?->document_type_id,
            'source_type'      => 'esign',
            'source_id'        => $template->id,
            'uploaded_by'      => $template->created_by,
        ]);

        $this->linkFiledDocumentToContactsAndProperty($filedDoc, $contactLinks, $propertyId);
        $this->linkFiledDocumentToDeal($filedDoc, $propertyId, $template->created_by);

        Log::info('Auto-filed signed document', [
            'filed_doc_id' => $filedDoc->id,
            'document_name' => $docName,
            'document_type_id' => $docTemplate?->document_type_id,
            'property_id' => $propertyId,
            'contact_count' => count($contactLinks),
        ]);

        return [
            'path'               => $pdfPath,
            'name'               => $docName,
            'template_id'        => $docTemplate?->id,
            'is_signed_document' => true,
        ];
    }

    /**
     * File individual documents for each template in a web pack.
     * Splits the merged HTML, generates individual PDFs, creates one Document record per template.
     */
    /**
     * @return array<int,array{path:string,name:string,template_id:?int,is_signed_document:bool}>
     */
    private function filePackDocuments(
        SignatureTemplate $template,
        $document,
        array $templateIds,
        string $mergedHtml,
        ?int $propertyId,
        array $contactLinks,
        array $pdfPaths,
    ): array {
        $filed = [];

        $htmlFragments = $this->splitMergedHtml($mergedHtml, count($templateIds));

        if (count($htmlFragments) !== count($templateIds)) {
            Log::warning('Auto-file pack: HTML fragment count does not match template_ids count, filing merged PDF as fallback', [
                'template_id' => $template->id,
                'template_ids' => $templateIds,
                'fragments' => count($htmlFragments),
                'expected' => count($templateIds),
            ]);
            $single = $this->fileSingleDocument($template, $document, $pdfPaths['client'], $propertyId, $contactLinks);

            return $single ? [$single] : [];
        }

        $signingController = app(\App\Http\Controllers\Docuperfect\SigningController::class);
        // Write under the 'local' disk ROOT so the filed Document
        // (Storage::disk('local')->download) resolves the exact file.
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $baseDir = "docuperfect/signed-documents/{$template->id}/individual";
        $disk->makeDirectory($baseDir);

        foreach ($templateIds as $idx => $tplId) {
            $tpl = \App\Models\Docuperfect\Template::find($tplId);
            if (!$tpl) continue;

            $individualPdfPath = "{$baseDir}/{$tplId}_client.pdf";
            $fullStoragePath = $disk->path($individualPdfPath);

            // Dedup check
            if (\App\Models\Document::where('storage_path', $individualPdfPath)->where('source_type', 'esign')->exists()) {
                continue;
            }

            // Generate individual PDF from this template's HTML fragment
            $fragmentHtml = $htmlFragments[$idx];
            try {
                $tempPdfPath = $signingController->generatePdfFromHtml($fragmentHtml, $document->id);
                if ($tempPdfPath && file_exists($tempPdfPath)) {
                    rename($tempPdfPath, $fullStoragePath);
                } else {
                    Log::warning('Auto-file pack: Individual PDF generation failed', [
                        'template_id' => $template->id,
                        'pack_template_id' => $tplId,
                        'template_name' => $tpl->name,
                    ]);
                    continue;
                }
            } catch (\Throwable $e) {
                Log::error('Auto-file pack: Individual PDF exception', [
                    'template_id' => $template->id,
                    'pack_template_id' => $tplId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $docName = ($tpl->name ?? 'Document') . ' (Signed).pdf';

            // FIX 2 — validate via the SAME disk Document::downloadResponse()
            // reads (Storage::disk('local')) so a guard pass GUARANTEES the
            // download works. is_file(storage_path('app/..')) checked a path
            // one dir outside the disk root → guard passed, download 500'd.
            if (!$disk->exists($individualPdfPath)) {
                Log::error('Auto-file pack: refusing to create a Document for a missing PDF', [
                    'template_id'      => $template->id,
                    'pack_template_id' => $tplId,
                    'template_name'    => $tpl->name,
                    'storage_path'     => $individualPdfPath,
                    'recoverable'      => true,
                ]);
                continue;
            }
            $fileSize = $disk->size($individualPdfPath);

            $filedDoc = \App\Models\Document::create([
                'original_name'    => $docName,
                'storage_path'     => $individualPdfPath,
                'disk'             => 'local',
                'mime_type'        => 'application/pdf',
                'size'             => $fileSize,
                'document_type_id' => $tpl->document_type_id,
                'source_type'      => 'esign',
                'source_id'        => $template->id,
                'uploaded_by'      => $template->created_by,
            ]);

            $this->linkFiledDocumentToContactsAndProperty($filedDoc, $contactLinks, $propertyId);
            $this->linkFiledDocumentToDeal($filedDoc, $propertyId, $template->created_by);

            Log::info('Auto-filed individual pack document', [
                'filed_doc_id' => $filedDoc->id,
                'pack_template_id' => $tplId,
                'template_name' => $tpl->name,
                'document_type_id' => $tpl->document_type_id,
                'property_id' => $propertyId,
                'contact_count' => count($contactLinks),
                'pdf_size' => $fileSize,
            ]);

            $filed[] = [
                'path'               => $individualPdfPath,
                'name'               => $docName,
                'template_id'        => (int) $tplId,
                // HD-7 §11-B — the EXPLICIT marker that this is a document the parties SIGNED, and
                // therefore may be mailed back to all of them. It is not "true because everything in
                // this array happens to be signed" — that is luck, and luck is one refactor away from
                // mailing one party's FICA evidence to every other signer. Distribution filters on
                // this flag, so a supporting attachment can never be swept into the loop by accident.
                'is_signed_document' => true,
            ];
        }

        return $filed;
    }

    /**
     * Split merged pack HTML into individual template fragments.
     * Each fragment contains the style blocks + one .corex-document-wrapper div.
     */
    private function splitMergedHtml(string $mergedHtml, int $expectedCount): array
    {
        // Extract all <style> blocks (shared across all templates)
        $styles = '';
        if (preg_match_all('/<style[^>]*>.*?<\/style>/si', $mergedHtml, $styleMatches)) {
            $styles = implode("\n", $styleMatches[0]);
        }

        // Split at .corex-document-wrapper boundaries.
        // §20: stampDisclosureDocKeys() (ESignWizardController) inserts
        // data-disclosure-doc="..." BETWEEN <div and class=, so the real tag
        // is `<div data-disclosure-doc="..." class="corex-document-wrapper"`.
        // A literal '<div class="corex-document-wrapper"' strpos therefore
        // matches NOTHING → 0 fragments → filePackDocuments fallback fires →
        // the whole pack is mis-filed as one document. Detect the wrapper's
        // opening <div> by the SAME attribute-order-independent regex that
        // stampDisclosureDocKeys() uses to find wrappers — one shared rule,
        // so the stamp (or any future added attribute) can never desync the
        // split again.
        $fragments = [];
        $offset = 0;
        $wrapperRe = '/<div\b[^>]*\bclass\s*=\s*"[^"]*\bcorex-document-wrapper\b[^"]*"[^>]*>/i';

        while (preg_match($wrapperRe, $mergedHtml, $m, PREG_OFFSET_CAPTURE, $offset)
            && ($pos = $m[0][1]) !== false) {
            // Find the matching closing </div> — count nested divs
            $depth = 0;
            $searchPos = $pos;
            $endPos = null;

            while ($searchPos < strlen($mergedHtml)) {
                $nextOpen = strpos($mergedHtml, '<div', $searchPos);
                $nextClose = strpos($mergedHtml, '</div>', $searchPos);

                if ($nextClose === false) break;

                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    $depth++;
                    $searchPos = $nextOpen + 4;
                } else {
                    $depth--;
                    if ($depth === 0) {
                        $endPos = $nextClose + 6; // length of '</div>'
                        break;
                    }
                    $searchPos = $nextClose + 6;
                }
            }

            if ($endPos !== null) {
                $wrapperHtml = substr($mergedHtml, $pos, $endPos - $pos);
                $fragments[] = $styles . "\n" . $wrapperHtml;
                $offset = $endPos;
            } else {
                break;
            }
        }

        return $fragments;
    }

    /**
     * Resolve signing contacts from signature requests (excluding agent).
     * Returns array of [contact_id => party_role] for linking.
     */
    private function resolveSigningContacts(SignatureTemplate $template): array
    {
        $links = [];
        foreach ($template->requests as $request) {
            if (!$request->signer_email || $request->party_role === 'agent') continue;

            // AT-125 — resolve against ALL of a contact's emails (child tables), not just the mirror.
            $contact = app(\App\Services\Communications\ContactIdentifierResolver::class)
                ->resolve($request->signer_email, (int) $template->agency_id);
            if (!$contact) continue;

            $links[$contact->id] = $request->party_role;
        }
        return $links;
    }

    /**
     * Link a filed Document to contacts and property via pivots.
     */
    private function linkFiledDocumentToContactsAndProperty(\App\Models\Document $filedDoc, array $contactLinks, ?int $propertyId): void
    {
        foreach ($contactLinks as $contactId => $partyRole) {
            $filedDoc->contacts()->syncWithoutDetaching([
                $contactId => ['party_role' => $partyRole],
            ]);
        }

        if ($propertyId) {
            $filedDoc->properties()->syncWithoutDetaching([$propertyId]);
        }
    }

    /**
     * AT-158 WS3 (D4) — anchor a freshly-filed signed Document to its DR2 deal.
     *
     * When the signed document's property maps to a single active deal, the
     * document is filed against that deal (reachable from the deal register too)
     * and any matching document_signed / document_upload pipeline step is
     * auto-completed — closing the "signed → step done" loop. Fully guarded and
     * non-fatal: a signing is already legally COMPLETED by the time we get here,
     * so a deal-link failure must never surface as an error.
     */
    private function linkFiledDocumentToDeal(\App\Models\Document $filedDoc, ?int $propertyId, ?int $actorUserId): void
    {
        try {
            if (! $propertyId) {
                return;
            }
            $actor = $actorUserId ? \App\Models\User::find($actorUserId) : null;
            app(\App\Services\DealV2\DealDocumentService::class)
                ->attachSignedDocumentToDeal($filedDoc, $propertyId, $actor);
        } catch (\Throwable $e) {
            Log::warning('Auto-file: signed document → deal link skipped (non-fatal)', [
                'filed_doc_id' => $filedDoc->id ?? null,
                'property_id'  => $propertyId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────
    // Wet ink
    // ──────────────────────────────────────────────

    /**
     * Handle wet ink upload from signer.
     */
    public function handleWetInkUpload(SignatureRequest $request, UploadedFile $file): void
    {
        $path = $file->store('docuperfect/wet-ink-uploads', 'local');

        $request->update([
            'signing_method' => 'wet_ink',
            'wet_ink_upload_path' => $path,
            'wet_ink_status' => SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW,
        ]);

        SignatureAuditLog::log(
            $request->template,
            SignatureAuditLog::ACTION_WET_INK_UPLOADED,
            SignatureAuditLog::ACTOR_SIGNER,
            $request->signer_name,
            $request->signer_email,
            requestId: $request->id,
        );

        $this->sendWetInkUploadedNotification($request);
    }

    /**
     * Submit wet ink inspection result.
     */
    public function submitInspection(
        SignatureRequest $request,
        User $inspector,
        string $result,
        array $checklist,
        ?string $notes = null
    ): WetInkInspection {
        return DB::transaction(function () use ($request, $inspector, $result, $checklist, $notes) {
            $inspection = WetInkInspection::create([
                'signature_request_id' => $request->id,
                'inspector_user_id' => $inspector->id,
                'checklist_json' => $checklist,
                'result' => $result,
                'notes' => $notes,
            ]);

            $template = $request->template;
            $action = $result === WetInkInspection::RESULT_APPROVED
                ? SignatureAuditLog::ACTION_WET_INK_APPROVED
                : SignatureAuditLog::ACTION_WET_INK_REJECTED;

            SignatureAuditLog::log(
                $template,
                $action,
                SignatureAuditLog::ACTOR_USER,
                $inspector->name,
                $inspector->email,
                $inspector->id,
                $request->id,
            );

            if ($result === WetInkInspection::RESULT_APPROVED) {
                $request->update([
                    'wet_ink_status' => SignatureRequest::WET_INK_APPROVED,
                    'reviewed_by' => $inspector->id,
                    'reviewed_at' => now(),
                    'status' => SignatureRequest::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

                // Replace flattened pages with the uploaded wet-ink scan so
                // the next signing party sees the physical signatures.
                $this->replaceWithWetInkScan($template, $request);

                // Wet-ink review IS the agent approval — advance directly
                // without going through handlePartyCompletion (which would
                // set pending_agent_approval and require a second review).
                $this->advanceAfterWetInkApproval($template, $request->party_role);
            } else {
                $request->update([
                    'wet_ink_status' => SignatureRequest::WET_INK_REJECTED,
                    'wet_ink_rejection_note' => $notes,
                    'reviewed_by' => $inspector->id,
                    'reviewed_at' => now(),
                    'wet_ink_upload_path' => null,
                ]);

                $this->sendWetInkRejectionEmail($request);
            }

            return $inspection;
        });
    }

    /**
     * Upload on behalf: approve a wet-ink upload immediately (no separate review step).
     *
     * Used when an agent receives a signed document via WhatsApp/email/in-person
     * and uploads it directly from the dashboard. The agent has already verified
     * the signatures, so we skip the inspection checklist.
     */
    public function approveUploadOnBehalf(SignatureRequest $request, User $approver): void
    {
        DB::transaction(function () use ($request, $approver) {
            $request->update([
                'wet_ink_status'  => SignatureRequest::WET_INK_APPROVED,
                'reviewed_by'    => $approver->id,
                'reviewed_at'    => now(),
                'status'         => SignatureRequest::STATUS_COMPLETED,
                'completed_at'   => now(),
            ]);

            $template = $request->template;

            SignatureAuditLog::log(
                $template,
                SignatureAuditLog::ACTION_WET_INK_APPROVED,
                SignatureAuditLog::ACTOR_USER,
                $approver->name,
                $approver->email,
                $approver->id,
                $request->id,
                metadata: ['upload_on_behalf_auto_approved' => true],
            );

            $this->replaceWithWetInkScan($template, $request);
            $this->advanceAfterWetInkApproval($template, $request->party_role);
        });
    }

    /**
     * Convert the wet-ink uploaded scan into flattened page images.
     *
     * The uploaded scan (PDF or images) replaces the current flattened pages
     * so subsequent signing parties see the physical signatures.
     */
    private function replaceWithWetInkScan(SignatureTemplate $template, SignatureRequest $request): void
    {
        $rawPath = $request->wet_ink_upload_path;
        if (!$rawPath) {
            return;
        }

        // wet_ink_upload_path may be a JSON array or a plain string
        $decoded = json_decode($rawPath, true);
        $uploadPaths = is_array($decoded) ? $decoded : [$rawPath];

        if (empty($uploadPaths)) {
            return;
        }

        $flattener = app(DocumentFlattener::class);
        $flattener->flattenWetInkScan($template, $uploadPaths);

        // Reload so advanceAfterWetInkApproval sees updated flattened_pages_json
        $template->refresh();
    }

    // ──────────────────────────────────────────────
    // Decline
    // ──────────────────────────────────────────────

    /**
     * Decline a signing request.
     */
    public function declineRequest(SignatureRequest $request, ?string $reason = null, ?string $ip = null, ?string $ua = null): void
    {
        DB::transaction(function () use ($request, $reason, $ip, $ua) {
            $request->update([
                'status' => SignatureRequest::STATUS_DECLINED,
                'ip_address' => $ip,
                'user_agent' => $ua,
            ]);

            $template = $request->template;
            $template->update(['status' => SignatureTemplate::STATUS_DECLINED]);

            SignatureAuditLog::log(
                $template,
                SignatureAuditLog::ACTION_DECLINED,
                SignatureAuditLog::ACTOR_SIGNER,
                $request->signer_name,
                $request->signer_email,
                requestId: $request->id,
                ip: $ip,
                ua: $ua,
                metadata: ['reason' => $reason],
            );
        });
    }

    // ──────────────────────────────────────────────
    // Reminders / expiry
    // ──────────────────────────────────────────────

    /**
     * Send a reminder for a pending request.
     */
    public function resendNotification(SignatureRequest $request): void
    {
        $request->update([
            'reminder_count' => $request->reminder_count + 1,
            'reminder_sent_at' => now(),
        ]);

        SignatureAuditLog::log(
            $request->template,
            SignatureAuditLog::ACTION_REMINDER_SENT,
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            requestId: $request->id,
            metadata: ['reminder_number' => $request->reminder_count],
        );

        $this->sendReminderEmail($request);
    }

    /**
     * Send a manual reminder (agent-triggered). Does NOT increment reminder_count
     * so it won't interfere with the automatic escalation schedule.
     */
    public function sendManualReminder(SignatureRequest $request, User $sentBy): void
    {
        $request->update(['reminder_sent_at' => now()]);

        SignatureAuditLog::log(
            $request->template,
            SignatureAuditLog::ACTION_MANUAL_REMINDER_SENT,
            SignatureAuditLog::ACTOR_USER,
            $sentBy->name,
            $sentBy->email,
            $sentBy->id,
            $request->id,
            metadata: [
                'signer_name' => $request->signer_name,
                'signer_email' => $request->signer_email,
            ],
        );

        $this->sendManualReminderEmail($request);
    }

    /**
     * Expire all outstanding requests past their expiry date.
     * Returns the number of expired requests.
     */
    /**
     * Track C (HD-11) — transition ceremonies whose LEGAL deadline has passed to a recorded lapse.
     *
     * The pen is already stopped the instant the deadline passes (HD-10, computed isLapsed()). This
     * is the RECORD of that fact: a nightly sweep that moves a past-deadline live ceremony to
     * 'lapsed' — or 're_lapsed' if it had been revived and lapsed again — with an audit row, so the
     * tracker shows "Lapsed" and the evidence timeline (HD-13) can attribute the delay. A lapse is a
     * transition, never a silent expiry.
     */
    public function lapseExpiredCeremonies(): int
    {
        $lapsed = 0;

        // Live, past-deadline, and not already recorded as lapsed. Terminal states are excluded — a
        // past date means nothing once a ceremony is done.
        $alreadyRecorded = [SignatureTemplate::STATUS_LAPSED, SignatureTemplate::STATUS_RE_LAPSED];
        $skip = array_merge(SignatureTemplate::TERMINAL_STATUSES, $alreadyRecorded);

        $candidates = SignatureTemplate::query()
            ->whereNotNull('legal_deadline_at')
            ->where('legal_deadline_at', '<', now())
            ->whereNotIn('status', $skip)
            ->get();

        foreach ($candidates as $template) {
            $wasRevived = $template->status === SignatureTemplate::STATUS_REVIVED;
            $newStatus  = $wasRevived ? SignatureTemplate::STATUS_RE_LAPSED : SignatureTemplate::STATUS_LAPSED;
            $fromStatus = $template->status;

            $template->update(['status' => $newStatus]);

            SignatureAuditLog::log(
                $template,
                'ceremony_lapsed',
                SignatureAuditLog::ACTOR_SYSTEM,
                'System',
                metadata: [
                    'from_status'       => $fromStatus,
                    'to_status'         => $newStatus,
                    'legal_deadline_at' => optional($template->legal_deadline_at)->toDateTimeString(),
                    'deadline_source'   => $template->deadline_source,
                ],
                documentHash: $template->document_hash,
            );

            $lapsed++;
        }

        return $lapsed;
    }

    public function expireOutstandingRequests(): int
    {
        $expired = 0;

        $requests = SignatureRequest::expirable()->with('template')->get();

        foreach ($requests as $request) {
            $request->update(['status' => SignatureRequest::STATUS_EXPIRED]);

            $template = $request->template;

            // Check if all requests for this template are expired/declined
            $hasActiveRequests = $template->requests()
                ->whereNotIn('status', [
                    SignatureRequest::STATUS_EXPIRED,
                    SignatureRequest::STATUS_DECLINED,
                    SignatureRequest::STATUS_COMPLETED,
                ])
                ->exists();

            if (!$hasActiveRequests && $template->status !== SignatureTemplate::STATUS_COMPLETED) {
                $template->update(['status' => SignatureTemplate::STATUS_EXPIRED]);
            }

            SignatureAuditLog::log(
                $template,
                SignatureAuditLog::ACTION_EXPIRED,
                SignatureAuditLog::ACTOR_SYSTEM,
                'System',
                requestId: $request->id,
            );

            $expired++;
        }

        return $expired;
    }

    // ──────────────────────────────────────────────
    // Rental dashboard data
    // ──────────────────────────────────────────────

    /**
     * Get data for the rental documents dashboard.
     */
    public function getRentalDashboardData(User $user): array
    {
        // Get all rental documents visible to this user
        // Include both template-based rentals AND standalone upload-and-send documents
        $rentalDocuments = Document::active()
            ->visibleTo($user)
            ->where(function ($q) {
                $q->whereHas('template', function ($tq) {
                    $tq->where('template_type', 'rental');
                })->orWhere('document_type', 'rental_upload_send');
            })
            ->with(['template.documentType', 'owner'])
            ->get();

        $documentIds = $rentalDocuments->pluck('id');

        // Get signature templates for these documents
        $signatureTemplates = SignatureTemplate::whereIn('document_id', $documentIds)
            ->with(['requests', 'rejectedBy'])
            ->get()
            ->keyBy('document_id');

        // Group documents by status
        $groups = [
            'pending_approval' => collect(),
            'draft' => collect(),
            'ready_to_sign' => collect(),
            'awaiting_signatures' => collect(),
            'completed' => collect(),
            'rejected' => collect(),
        ];

        $fieldStatus = [];

        foreach ($rentalDocuments as $doc) {
            $sigTemplate = $signatureTemplates->get($doc->id);

            if (!$sigTemplate) {
                // No signature template yet — check field completion
                $validation = $this->validateFieldCompletion($doc);

                $fieldStatus[$doc->id] = [
                    'valid' => $validation['valid'],
                    'total' => $validation['total'],
                    'filled' => $validation['filled'],
                    'missing' => $validation['missing'],
                ];

                if ($validation['valid']) {
                    $groups['ready_to_sign']->push($doc);
                } else {
                    $groups['draft']->push($doc);
                }
                continue;
            }

            // Check if any request has a wet-ink upload pending agent review
            $hasWetInkPendingReview = $sigTemplate->requests
                ->contains(fn($r) => $r->wet_ink_status === 'uploaded_pending_review');

            if ($hasWetInkPendingReview && in_array($sigTemplate->status, [
                SignatureTemplate::STATUS_SIGNING,
                SignatureTemplate::STATUS_AWAITING_TENANT,
                SignatureTemplate::STATUS_AWAITING_LANDLORD,
            ])) {
                $groups['pending_approval']->push($doc);
            } else {
                match ($sigTemplate->status) {
                    SignatureTemplate::STATUS_COMPLETED => $groups['completed']->push($doc),
                    SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL => $groups['pending_approval']->push($doc),
                    // Candidate flow: awaiting authorisation goes to pending_approval (shared queue)
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL => $groups['pending_approval']->push($doc),
                    SignatureTemplate::STATUS_REJECTED => $groups['rejected']->push($doc),
                    SignatureTemplate::STATUS_SIGNING,
                    SignatureTemplate::STATUS_AWAITING_TENANT,
                    SignatureTemplate::STATUS_AWAITING_LANDLORD,
                    SignatureTemplate::STATUS_AWAITING_BUYER,
                    SignatureTemplate::STATUS_AWAITING_SELLER,
                    SignatureTemplate::STATUS_AWAITING_DEFERRED,
                    SignatureTemplate::STATUS_PARTIAL => $groups['awaiting_signatures']->push($doc),
                    SignatureTemplate::STATUS_READY,
                    SignatureTemplate::STATUS_DRAFT => $groups['ready_to_sign']->push($doc),
                    default => $groups['draft']->push($doc),
                };
            }
        }

        // Lease renewal data
        $upcomingRenewals = LeaseRecord::visibleTo($user)
            ->whereIn('status', [LeaseRecord::STATUS_ACTIVE, LeaseRecord::STATUS_EXPIRING_SOON])
            ->where('lease_end_date', '<=', now()->addDays(90))
            ->orderBy('lease_end_date')
            ->get();

        $expiredLeases = LeaseRecord::visibleTo($user)
            ->where('status', LeaseRecord::STATUS_EXPIRED)
            ->orderBy('lease_end_date', 'desc')
            ->limit(10)
            ->get();

        $activeLeases = LeaseRecord::visibleTo($user)
            ->where('status', LeaseRecord::STATUS_ACTIVE)
            ->with(['document', 'signatureTemplate'])
            ->orderBy('lease_end_date')
            ->get();

        // Compute last update timestamp for polling
        $lastUpdate = $signatureTemplates->max('updated_at');

        return [
            'groups' => $groups,
            'rejected' => $groups['rejected'],
            'signatureTemplates' => $signatureTemplates,
            'fieldStatus' => $fieldStatus,
            'counts' => [
                'pending_approval' => $groups['pending_approval']->count(),
                'draft' => $groups['draft']->count(),
                'ready_to_sign' => $groups['ready_to_sign']->count(),
                'awaiting_signatures' => $groups['awaiting_signatures']->count(),
                'completed' => $groups['completed']->count(),
            ],
            'upcomingRenewals' => $upcomingRenewals,
            'expiredLeases' => $expiredLeases,
            'activeLeases' => $activeLeases,
            'activeLeaseCount' => $activeLeases->count(),
            'lastUpdate' => $lastUpdate?->toIso8601String(),
        ];
    }

    // ──────────────────────────────────────────────
    // Lease record extraction
    // ──────────────────────────────────────────────

    /**
     * Check if the signed document is a lease/rental document.
     */
    private function isLeaseDocument(SignatureTemplate $template): bool
    {
        $template->loadMissing('document.template.documentType');
        $document = $template->document;

        if (!$document) {
            return false;
        }

        // Check document type name for rental/lease keywords
        $docType = $document->template->documentType->name ?? '';
        $docName = $document->name ?? '';

        $keywords = ['lease', 'rental', 'tenancy', 'rent'];

        foreach ($keywords as $keyword) {
            if (stripos($docType, $keyword) !== false || stripos($docName, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a lease record from a completed signature template.
     */
    public function createLeaseRecord(SignatureTemplate $template): LeaseRecord
    {
        $template->loadMissing(['document', 'requests']);
        $document = $template->document;
        $parties = $template->parties_json ?? [];

        // Extract party details from parties_json
        $tenant = collect($parties)->firstWhere('role', 'tenant');
        $landlord = collect($parties)->firstWhere('role', 'landlord');

        // Extract lease-specific fields from document fields_json
        $fields = $this->extractLeaseFields($document);

        $record = LeaseRecord::create([
            'document_id' => $document->id,
            'signature_template_id' => $template->id,
            'property_id' => $fields['property_id'],
            'property_address' => $fields['property_address'] ?? $document->name,
            'tenant_name' => $tenant['name'] ?? '',
            'tenant_email' => $tenant['email'] ?? '',
            'landlord_name' => $landlord['name'] ?? '',
            'landlord_email' => $landlord['email'] ?? '',
            'rental_amount' => $fields['rental_amount'] ?? 0,
            'lease_start_date' => $fields['lease_start_date'] ?? now()->toDateString(),
            'lease_end_date' => $fields['lease_end_date'] ?? now()->addYear()->toDateString(),
            'status' => LeaseRecord::STATUS_ACTIVE,
        ]);

        SignatureAuditLog::log(
            $template,
            'lease_record_created',
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            metadata: [
                'lease_record_id' => $record->id,
                'lease_start' => $record->lease_start_date->toDateString(),
                'lease_end' => $record->lease_end_date->toDateString(),
                'rental_amount' => $record->rental_amount,
            ],
        );

        return $record;
    }

    /**
     * Extract lease-specific fields from a document's fields_json.
     */
    private function extractLeaseFields(Document $document): array
    {
        $fields = $document->fields_json ?? [];

        return [
            'property_address' => $fields['property_address'] ?? $fields['address'] ?? $fields['premises_address'] ?? null,
            'property_id' => $fields['property_id'] ?? $fields['erf_number'] ?? null,
            'rental_amount' => (float) ($fields['monthly_rental'] ?? $fields['rental_amount'] ?? $fields['rent'] ?? 0),
            'lease_start_date' => $this->parseLeaseDate($fields['lease_start_date'] ?? $fields['commencement_date'] ?? $fields['start_date'] ?? null),
            'lease_end_date' => $this->parseLeaseDate($fields['lease_end_date'] ?? $fields['termination_date'] ?? $fields['end_date'] ?? null),
        ];
    }

    /**
     * Safely parse a date value for lease records.
     */
    private function parseLeaseDate($value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }

    // ──────────────────────────────────────────────
    // Email methods
    // ──────────────────────────────────────────────

    /**
     * Send signing request email — FROM the agent.
     */
    private function sendSigningRequestEmail(SignatureRequest $request): void
    {
        try {
            $request->loadMissing(['template.document', 'template.creator']);
            $template = $request->template;
            $agent = $template->creator;
            $documentName = $template->document->name ?? 'Document';
            $signingUrl = route('signatures.external', $request->token);

            Mail::to($request->signer_email)->send(
                (new SigningRequestMail(
                    signerName: $request->signer_name,
                    documentName: $documentName,
                    signingUrl: $signingUrl,
                    personalMessage: $request->message,
                    expiresAt: $request->token_expires_at,
                ))->fromAgent($agent)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send signing request email', [
                'request_id' => $request->id,
                'signer_email' => $request->signer_email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send reminder email — FROM the agent.
     */
    public function sendReminderEmail(SignatureRequest $request): void
    {
        // AT-294 — a reminder to an email-less party would hit Mail::to('') and
        // be swallowed; skip cleanly (the party is parked deferred until an
        // email is added — nothing to remind yet).
        if (trim((string) $request->signer_email) === '') {
            Log::warning('Reminder skipped — recipient has no email', ['request_id' => $request->id]);
            return;
        }
        try {
            $request->loadMissing(['template.document', 'template.creator']);
            $template = $request->template;
            $agent = $template->creator;
            $documentName = $template->document->name ?? 'Document';
            $signingUrl = route('signatures.external', $request->token);

            Mail::to($request->signer_email)->send(
                (new SignatureReminderMail(
                    signerName: $request->signer_name,
                    documentName: $documentName,
                    signingUrl: $signingUrl,
                    expiresAt: $request->token_expires_at,
                    reminderNumber: $request->reminder_count,
                ))->fromAgent($agent)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send reminder email', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send manual reminder email — FROM the agent, with 'manual' tone.
     */
    private function sendManualReminderEmail(SignatureRequest $request): void
    {
        // AT-294 — see sendReminderEmail: skip an email-less party cleanly.
        if (trim((string) $request->signer_email) === '') {
            Log::warning('Manual reminder skipped — recipient has no email', ['request_id' => $request->id]);
            return;
        }
        try {
            $request->loadMissing(['template.document', 'template.creator']);
            $template = $request->template;
            $agent = $template->creator;
            $documentName = $template->document->name ?? 'Document';
            $signingUrl = route('signatures.external', $request->token);

            Mail::to($request->signer_email)->send(
                (new SignatureReminderMail(
                    signerName: $request->signer_name,
                    documentName: $documentName,
                    signingUrl: $signingUrl,
                    expiresAt: $request->token_expires_at,
                    reminderNumber: $request->reminder_count,
                    forceTone: 'manual',
                ))->fromAgent($agent)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send manual reminder email', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send wet ink rejection email — FROM the agent.
     */
    private function sendWetInkRejectionEmail(SignatureRequest $request): void
    {
        try {
            $request->loadMissing(['template.document', 'template.creator']);
            $template = $request->template;
            $agent = $template->creator;
            $documentName = $template->document->name ?? 'Document';
            $signingUrl = route('signatures.external', $request->token);

            Mail::to($request->signer_email)->send(
                (new WetInkRejectionMail(
                    signerName: $request->signer_name,
                    documentName: $documentName,
                    signingUrl: $signingUrl,
                    rejectionNote: $request->wet_ink_rejection_note,
                ))->fromAgent($agent)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send wet ink rejection email', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Public accessor for wet ink upload notification (called by SigningController after multi-file upload).
     */
    public function notifyWetInkUploaded(SignatureRequest $request): void
    {
        $this->sendWetInkUploadedNotification($request);
    }

    /**
     * Send wet ink uploaded notification to the agent (internal — from system).
     */
    private function sendWetInkUploadedNotification(SignatureRequest $request): void
    {
        try {
            $request->loadMissing(['template.document', 'template.creator']);
            $template = $request->template;
            $agent = $template->creator;

            if (!$agent) {
                return;
            }

            $documentName = $template->document->name ?? 'Document';
            $inspectUrl = url("/docuperfect/documents/{$template->document_id}/signatures/inspect/{$request->id}");

            // In-app notification only — no email to agents
            $agent->notify(SignatureActivityNotification::wetInkUploaded(
                $request->signer_name, $documentName, $template->document_id, $inspectUrl,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send wet ink uploaded notification', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send notification to agent that a party has completed signing and needs approval.
     */
    private function sendAgentApprovalNotification(SignatureTemplate $template, string $completedParty, ?SignatureRequest $request): void
    {
        try {
            $template->loadMissing(['document', 'creator']);
            $agent = $template->creator;

            if (!$agent) {
                return;
            }

            $documentName = $template->document->name ?? 'Document';
            $reviewUrl = url("/docuperfect/documents/{$template->document_id}/signatures/review");

            // In-app notification only — no email to agents
            $agent->notify(SignatureActivityNotification::partySigned(
                $request?->signer_name ?? ucfirst($completedParty),
                $completedParty,
                $documentName,
                $template->document_id,
                $reviewUrl,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send agent approval notification', [
                'template_id' => $template->id,
                'completed_party' => $completedParty,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send completion emails to all signers + the agent, with signed PDF attached.
     */
    /**
     * @param  array<int,array{path:string,name:string,template_id:?int,is_signed_document:bool}>  $signedDocuments
     *   HD-7 (§11-B) — what `autoFileSignedDocument()` actually filed, which now runs FIRST. A pack
     *   signed as one ceremony is distributed as the many documents it really is.
     */
    private function sendCompletionEmails(SignatureTemplate $template, ?array $pdfPaths = null, array $signedDocuments = []): void
    {
        try {
            $template->loadMissing(['document', 'creator', 'requests']);
            $agent = $template->creator;
            $documentName = $template->document->name ?? 'Document';
            $viewUrl = url("/docuperfect/documents/{$template->document_id}/signatures/audit");
            $progress = $template->partyProgress();

            // Resolve via the 'local' disk (where signed PDFs are written)
            // so attachments survive the disk-root path fix.
            $disk = \Illuminate\Support\Facades\Storage::disk('local');

            // Client copy — for external signers (no audit trail)
            $clientPdfPath = ($pdfPaths && !empty($pdfPaths['client']) && $disk->exists($pdfPaths['client']))
                ? $disk->path($pdfPaths['client']) : null;

            $pdfFilename = "Signed - {$documentName}.pdf";

            // HD-7 — the attachment set: every document the parties SIGNED, each under its own name.
            //
            // The filter on `is_signed_document` is deliberate and load-bearing (§11-B). A pack holds
            // supporting ATTACHMENT slots as well as signed documents — a party's FICA evidence, an ID
            // copy. Those are attached to the ceremony; they are not products of it, and mailing them
            // to every other signer would be a POPIA breach delivered by the system itself. Excluding
            // them because "they don't happen to be in this array" is luck. This is the explicit
            // refusal, and it survives whatever anyone later files.
            $attachments = [];
            foreach ($signedDocuments as $doc) {
                if (($doc['is_signed_document'] ?? false) !== true) {
                    continue; // Not a signed product of this ceremony — it is never distributed.
                }
                if (empty($doc['path']) || ! $disk->exists($doc['path'])) {
                    continue; // A missing file never blocks a completed signing's delivery.
                }

                $attachments[] = [
                    'path' => $disk->path($doc['path']),
                    'name' => $doc['name'] ?? $pdfFilename,
                ];
            }

            // Fallback — a single-template signing, or a pack whose split failed and was filed merged.
            // One document IS the honest answer there; this is not a degraded path.
            if (empty($attachments) && $clientPdfPath) {
                $attachments = [['path' => $clientPdfPath, 'name' => $pdfFilename]];
            }

            // Email external signers only — attach client copies (no audit trail)
            foreach ($template->requests as $request) {
                if ($request->status !== SignatureRequest::STATUS_COMPLETED) {
                    continue;
                }
                // Skip agent — agents get in-app notification only
                if ($request->party_role === 'agent') {
                    continue;
                }

                $mail = (new SignedDocumentMail(
                    recipientName: $request->signer_name,
                    documentName: $documentName,
                    envelopeUrl: null, // External parties cannot access Nexus
                    progress: $progress,
                    pdfPath: $clientPdfPath,
                    pdfFilename: $clientPdfPath ? $pdfFilename : null,
                    documents: $attachments,
                ))->fromAgent($agent);

                Mail::to($request->signer_email)->send($mail);

                // HD-7 — one audit row PER DOCUMENT per recipient, so the evidence timeline can answer
                // "was the Disclosure sent to the purchaser?" and not merely "was something sent?".
                if (empty($attachments)) {
                    SignatureAuditLog::log(
                        $template,
                        SignatureAuditLog::ACTION_SIGNED_PDF_EMAILED,
                        SignatureAuditLog::ACTOR_SYSTEM,
                        'System',
                        metadata: [
                            'recipient_role'  => $request->party_role,
                            'recipient_name'  => $request->signer_name,
                            'recipient_email' => $request->signer_email,
                            'pdf_attached'    => false,
                            'pdf_version'     => 'client',
                        ],
                    );
                    continue;
                }

                foreach ($attachments as $attachment) {
                    SignatureAuditLog::log(
                        $template,
                        SignatureAuditLog::ACTION_SIGNED_PDF_EMAILED,
                        SignatureAuditLog::ACTOR_SYSTEM,
                        'System',
                        metadata: [
                            'recipient_role'  => $request->party_role,
                            'recipient_name'  => $request->signer_name,
                            'recipient_email' => $request->signer_email,
                            'pdf_attached'    => true,
                            'pdf_version'     => 'client',
                            'document_name'   => $attachment['name'],
                        ],
                    );
                }
            }

            // In-app notification to agent — no email
            if ($agent) {
                $agent->notify(SignatureActivityNotification::documentCompleted(
                    $documentName, $template->document_id, $viewUrl,
                ));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send completion emails', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Generate a secure unique 64-char token.
     */
    private function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (SignatureRequest::where('token', $token)->exists());

        return $token;
    }

    // ──────────────────────────────────────────────
    // Amendment Detection & Flow
    // ──────────────────────────────────────────────

    /**
     * Detect if the signing party added Other Conditions content.
     * Returns the new text if an amendment is detected, null otherwise.
     */
    public function detectAmendment(SignatureTemplate $template, string $newOtherConditionsText): ?string
    {
        $previousText = $template->other_conditions_text ?? '';
        $newText = trim($newOtherConditionsText);

        if ($newText === '' || $newText === $previousText) {
            return null;
        }

        return $newText;
    }

    /**
     * Create an amendment record and trigger the re-signing flow.
     * Returns the created DocumentAmendment, or null if no amendment needed.
     */
    public function createAmendment(
        SignatureTemplate $template,
        SignatureRequest $amendingRequest,
        string $newConditionsText,
        ?string $originalText = null
    ): ?DocumentAmendment {
        $document = $template->document;
        if (!$document) {
            return null;
        }

        $hashBefore = $this->generateDocumentHash($document);
        $currentVersion = $template->document_version ?? 1;
        $newVersion = $currentVersion + 1;

        // Determine amendment type
        $amendmentType = empty($originalText) ? 'addition' : 'modification';

        $amendment = DocumentAmendment::create([
            'document_id' => $document->id,
            'signature_template_id' => $template->id,
            'amended_by_request_id' => $amendingRequest->id,
            'amendment_type' => $amendmentType,
            'section_reference' => 'Other Conditions',
            'original_text' => $originalText,
            'new_text' => $newConditionsText,
            'document_version_before' => $currentVersion,
            'document_version_after' => $newVersion,
            'document_hash_before' => $hashBefore,
            'document_hash_after' => null, // Will be set after conditions stored
            'status' => DocumentAmendment::STATUS_PENDING,
        ]);

        // Update template version and store new conditions text
        $template->update([
            'document_version' => $newVersion,
            'other_conditions_text' => $newConditionsText,
            'amendment_status' => 'pending_review',
        ]);

        // Recalculate hash after update
        $document->refresh();
        $amendment->update([
            'document_hash_after' => $this->generateDocumentHash($document),
        ]);

        SignatureAuditLog::log(
            $template,
            'amendment_detected',
            SignatureAuditLog::ACTOR_SIGNER,
            $amendingRequest->signer_name ?? 'Unknown',
            metadata: [
                'amendment_id' => $amendment->id,
                'amendment_type' => $amendmentType,
                'amended_by_role' => $amendingRequest->party_role,
                'version_before' => $currentVersion,
                'version_after' => $newVersion,
            ],
        );

        return $amendment;
    }

    /**
     * Handle the amendment flow: halt forward progress, notify previous signers.
     * Creates amendment acceptance records for each previous signer.
     */
    public function handleAmendment(SignatureTemplate $template, DocumentAmendment $amendment, SignatureRequest $amendingRequest): void
    {
        DB::transaction(function () use ($template, $amendment, $amendingRequest) {
            // Put template into amendment review status
            $template->update([
                'status' => SignatureTemplate::STATUS_AMENDMENT_REVIEW,
            ]);

            // Find all PREVIOUS signers (completed before the amending party)
            $previousSigners = $template->requests()
                ->where('status', SignatureRequest::STATUS_COMPLETED)
                ->where('id', '!=', $amendingRequest->id)
                ->where('signing_order', '<', $amendingRequest->signing_order)
                ->get();

            foreach ($previousSigners as $previousRequest) {
                // Create acceptance record for each previous signer per amendment
                AmendmentAcceptance::create([
                    'amendment_id' => $amendment->id,
                    'signature_request_id' => $previousRequest->id,
                    'accepted' => false,
                    'rejected' => false,
                ]);

                // Generate new token for re-signing
                $resignToken = $this->generateToken();
                $previousRequest->update([
                    'token' => $resignToken,
                    'token_expires_at' => now()->addDays(14),
                    'status' => SignatureRequest::STATUS_PENDING,
                ]);

                // Send notification email
                try {
                    $signingUrl = route('signatures.external.amendment-review', $resignToken);
                    // AT-291 ITEMS 1+2 — stamp the acting agent so the
                    // amendment re-send carries the agent From (subject to the
                    // company-domain SPF/DKIM rule) and the agent Reply-To,
                    // matching every other send site. Without ->fromAgent()
                    // both headers collapse to the system default.
                    Mail::to($previousRequest->signer_email)->send(
                        (new SigningRequestMail(
                            signerName: $previousRequest->signer_name,
                            documentName: $template->document->name ?? 'Document',
                            signingUrl: $signingUrl,
                            personalMessage: "{$amendingRequest->signer_name} has added conditions to this document. Please review and initial each amendment to continue.",
                            expiresAt: $previousRequest->token_expires_at,
                        ))->fromAgent($template->creator)
                    );

                    SignatureAuditLog::log(
                        $template,
                        'amendment_review_sent',
                        SignatureAuditLog::ACTOR_SYSTEM,
                        'System',
                        metadata: [
                            'amendment_id' => $amendment->id,
                            'sent_to' => $previousRequest->signer_name,
                            'sent_to_email' => $previousRequest->signer_email,
                            'party_role' => $previousRequest->party_role,
                        ],
                    );
                } catch (\Throwable $e) {
                    Log::error('Failed to send amendment review notification', [
                        'amendment_id' => $amendment->id,
                        'request_id' => $previousRequest->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Also notify the agent
            $this->sendAgentAmendmentNotification($template, $amendment, $amendingRequest);
        });
    }

    /**
     * ES-3 — Initialing Cascade.
     *
     * Replaces the full re-sign cascade for amendment review approvals.
     * After the agent approves a proposed amendment (a new condition or a
     * strikethrough override), this requeues every previously-signed party
     * for a focused initialing view that shows ONLY the changed regions.
     *
     * Original signatures are preserved verbatim — they don't get
     * superseded — only the new conditions/strikethroughs introduced by
     * the amendment need fresh initials.
     *
     * Spec: .ai/specs/esign-v3-complete-spec.md §7.5.7, §8
     */
    public function requeueAllPartiesForInitialing(
        SignatureTemplate $template,
        DocumentAmendment $amendment
    ): void {
        DB::transaction(function () use ($template, $amendment) {
            // 1. Move template into initialing-cascade state. The amendment
            //    table row already exists (created when the condition or
            //    strikethrough was proposed) — we just flip it to ACCEPTED-
            //    by-agent and start the cascade.
            $template->update([
                'status'           => SignatureTemplate::STATUS_AMENDMENT_INITIALING,
                'amendment_status' => SignatureTemplate::AMENDMENT_STATUS_INITIALING,
            ]);
            $amendment->update(['status' => DocumentAmendment::STATUS_ACCEPTED]);

            // 2. Find all previous signers. Same query as the legacy
            //    handleAmendment() flow, but we route them into the
            //    initialing view rather than full re-sign.
            $previousSigners = $template->requests()
                ->where('status', SignatureRequest::STATUS_COMPLETED)
                ->orderBy('signing_order')
                ->get();

            foreach ($previousSigners as $previousRequest) {
                // Skip duplicate acceptance rows for this amendment
                $existing = AmendmentAcceptance::where('amendment_id', $amendment->id)
                    ->where('signature_request_id', $previousRequest->id)
                    ->first();
                if (! $existing) {
                    AmendmentAcceptance::create([
                        'amendment_id'         => $amendment->id,
                        'signature_request_id' => $previousRequest->id,
                        'accepted'             => false,
                        'rejected'             => false,
                    ]);
                }

                // Mint a fresh token so the party can land on the focused
                // initialing view (existing request rows are re-issued —
                // signed_at / original signatures NOT touched).
                $initialingToken = $this->generateToken();
                $previousRequest->update([
                    'token'            => $initialingToken,
                    'token_expires_at' => now()->addDays(14),
                ]);

                // Best-effort email send. Existing amendment-review route is
                // used so the previous signer lands on the focused initialing
                // view we render server-side from the same surface.
                try {
                    $url = route('signatures.external.amendment-review', $initialingToken);
                    // AT-291 ITEMS 1+2 — stamp the acting agent (From +
                    // Reply-To) on the initialing re-send, matching every
                    // other send site; without it both headers fall back to
                    // the system default.
                    Mail::to($previousRequest->signer_email)->send(
                        (new SigningRequestMail(
                            signerName:      $previousRequest->signer_name,
                            documentName:    $template->document->name ?? 'Document',
                            signingUrl:      $url,
                            personalMessage: 'A change to this document was approved. Please initial the changed sections to confirm — your original signature stays in place.',
                            expiresAt:       $previousRequest->token_expires_at,
                        ))->fromAgent($template->creator)
                    );
                } catch (\Throwable $e) {
                    Log::warning('Initialing cascade — mail send failed', [
                        'amendment_id' => $amendment->id,
                        'request_id'   => $previousRequest->id,
                        'error'        => $e->getMessage(),
                    ]);
                }

                SignatureAuditLog::log(
                    $template,
                    'amendment_initialing_invited',
                    SignatureAuditLog::ACTOR_SYSTEM,
                    'System',
                    metadata: [
                        'amendment_id'  => $amendment->id,
                        'party_role'    => $previousRequest->party_role,
                        'signer_name'   => $previousRequest->signer_name,
                    ],
                );
            }
        });
    }

    /**
     * ES-3 — Reject the amendment but keep the document alive.
     * Restores prior amendment_status and clears any proposed conditions
     * tied to this amendment so the original document continues.
     */
    public function rejectAmendmentChange(
        SignatureTemplate $template,
        DocumentAmendment $amendment,
        ?string $reason = null
    ): void {
        DB::transaction(function () use ($template, $amendment, $reason) {
            $amendment->update([
                'status' => DocumentAmendment::STATUS_REJECTED,
            ]);

            // Mark conditions tied to this amendment as superseded (soft-
            // delete so they remain auditable).
            \App\Models\Docuperfect\DocumentCondition::where('amendment_id', $amendment->id)
                ->update([
                    'superseded_at' => now(),
                ]);
            \App\Models\Docuperfect\DocumentCondition::where('amendment_id', $amendment->id)->delete();

            \App\Models\Docuperfect\DocumentClauseStrikethrough::where('amendment_id', $amendment->id)
                ->update([
                    'status'               => \App\Models\Docuperfect\DocumentClauseStrikethrough::STATUS_REJECTED,
                    'rejected_by_agent_at' => now(),
                    'rejection_reason'     => $reason,
                ]);

            // Return template to its prior status (best guess — back to
            // signing). Calling code may override.
            $template->update([
                'status'           => SignatureTemplate::STATUS_SIGNING,
                'amendment_status' => SignatureTemplate::AMENDMENT_STATUS_REJECTED,
            ]);

            SignatureAuditLog::log(
                $template,
                'amendment_change_rejected',
                SignatureAuditLog::ACTOR_USER,
                \Illuminate\Support\Facades\Auth::user()?->name ?? 'Agent',
                metadata: [
                    'amendment_id' => $amendment->id,
                    'reason'       => $reason,
                ],
            );
        });
    }

    /**
     * ES-3 — Hard-reject the document. Terminal state.
     */
    public function rejectAmendmentDocument(
        SignatureTemplate $template,
        DocumentAmendment $amendment,
        ?string $reason = null
    ): void {
        DB::transaction(function () use ($template, $amendment, $reason) {
            $amendment->update(['status' => DocumentAmendment::STATUS_REJECTED]);
            $template->update([
                'status'            => SignatureTemplate::STATUS_REJECTED,
                'rejected_at'       => now(),
                'rejected_by'       => \Illuminate\Support\Facades\Auth::id(),
                'rejection_reason'  => $reason,
                'amendment_status'  => SignatureTemplate::AMENDMENT_STATUS_REJECTED,
            ]);

            SignatureAuditLog::log(
                $template,
                'amendment_document_rejected',
                SignatureAuditLog::ACTOR_USER,
                \Illuminate\Support\Facades\Auth::user()?->name ?? 'Agent',
                metadata: [
                    'amendment_id' => $amendment->id,
                    'reason'       => $reason,
                ],
            );
        });
    }

    /**
     * Accept an amendment (one party initials one amendment).
     */
    public function acceptAmendment(
        DocumentAmendment $amendment,
        SignatureRequest $signerRequest,
        ?string $initialImage = null
    ): AmendmentAcceptance {
        $acceptance = AmendmentAcceptance::where('amendment_id', $amendment->id)
            ->where('signature_request_id', $signerRequest->id)
            ->firstOrFail();

        $acceptance->update([
            'accepted' => true,
            'rejected' => false,
            'initial_image' => $initialImage,
        ]);

        SignatureAuditLog::log(
            $amendment->template,
            'amendment_accepted',
            SignatureAuditLog::ACTOR_SIGNER,
            $signerRequest->signer_name ?? 'Unknown',
            metadata: [
                'amendment_id' => $amendment->id,
                'party_role' => $signerRequest->party_role,
            ],
        );

        // Check if all amendments are fully accepted — if so, resume normal flow
        $this->checkAmendmentResolution($amendment->template);

        return $acceptance;
    }

    /**
     * Reject an amendment (one party rejects with reason).
     */
    public function rejectAmendment(
        DocumentAmendment $amendment,
        SignatureRequest $signerRequest,
        string $reason
    ): AmendmentAcceptance {
        $acceptance = AmendmentAcceptance::where('amendment_id', $amendment->id)
            ->where('signature_request_id', $signerRequest->id)
            ->firstOrFail();

        $acceptance->update([
            'accepted' => false,
            'rejected' => true,
            'rejection_reason' => $reason,
        ]);

        $amendment->update(['status' => DocumentAmendment::STATUS_REJECTED]);

        SignatureAuditLog::log(
            $amendment->template,
            'amendment_rejected',
            SignatureAuditLog::ACTOR_SIGNER,
            $signerRequest->signer_name ?? 'Unknown',
            metadata: [
                'amendment_id' => $amendment->id,
                'party_role' => $signerRequest->party_role,
                'reason' => $reason,
            ],
        );

        // Notify the agent about the rejection
        $this->sendAgentAmendmentNotification($amendment->template, $amendment, $signerRequest, 'rejected');

        return $acceptance;
    }

    /**
     * Agent accepts/rejects an amendment on behalf of the agency.
     */
    public function agentAmendmentAction(
        DocumentAmendment $amendment,
        string $action,
        ?string $reason = null
    ): void {
        if ($action === 'accept') {
            $amendment->update(['status' => DocumentAmendment::STATUS_ACCEPTED]);

            // Mark all pending acceptances for this amendment as accepted (agent override)
            AmendmentAcceptance::where('amendment_id', $amendment->id)
                ->where('accepted', false)
                ->where('rejected', false)
                ->update(['accepted' => true]);

            SignatureAuditLog::log(
                $amendment->template,
                'amendment_agent_accepted',
                SignatureAuditLog::ACTOR_USER,
                auth()->user()?->name ?? 'Agent',
                metadata: [
                    'amendment_id' => $amendment->id,
                ],
            );
        } else {
            $amendment->update([
                'status' => DocumentAmendment::STATUS_REJECTED,
            ]);

            SignatureAuditLog::log(
                $amendment->template,
                'amendment_agent_rejected',
                SignatureAuditLog::ACTOR_USER,
                auth()->user()?->name ?? 'Agent',
                metadata: [
                    'amendment_id' => $amendment->id,
                    'reason' => $reason,
                ],
            );
        }

        $this->checkAmendmentResolution($amendment->template);
    }

    /**
     * Check if all pending amendments are resolved. If so, resume normal signing flow.
     */
    private function checkAmendmentResolution(SignatureTemplate $template): void
    {
        $template->refresh();

        $pendingAmendments = $template->amendments()
            ->where('status', DocumentAmendment::STATUS_PENDING)
            ->count();

        if ($pendingAmendments > 0) {
            return; // Still amendments pending
        }

        // Check if all accepted amendments have full acceptance from all parties
        $acceptedAmendments = $template->amendments()
            ->where('status', DocumentAmendment::STATUS_ACCEPTED)
            ->orWhere(function ($q) use ($template) {
                $q->where('signature_template_id', $template->id)
                  ->where('status', DocumentAmendment::STATUS_PENDING);
            })
            ->get();

        foreach ($acceptedAmendments as $amendment) {
            $pendingAcceptances = $amendment->acceptances()
                ->where('accepted', false)
                ->where('rejected', false)
                ->count();

            if ($pendingAcceptances > 0) {
                return; // Still waiting for party acceptances
            }
        }

        // All amendments resolved — mark accepted ones
        $template->amendments()
            ->where('status', DocumentAmendment::STATUS_PENDING)
            ->update(['status' => DocumentAmendment::STATUS_ACCEPTED]);

        $template->update([
            'amendment_status' => 'resolved',
            'status' => SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL,
        ]);

        // Re-mark previous signers as completed (they've now re-signed)
        $template->requests()
            ->where('status', SignatureRequest::STATUS_PENDING)
            ->whereHas('sectionAcceptances') // only if they had acceptances
            ->update(['status' => SignatureRequest::STATUS_COMPLETED]);

        SignatureAuditLog::log(
            $template,
            'all_amendments_resolved',
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            metadata: [
                'total_amendments' => $template->amendments()->count(),
            ],
        );
    }

    /**
     * Send the agent a notification about an amendment.
     */
    private function sendAgentAmendmentNotification(
        SignatureTemplate $template,
        DocumentAmendment $amendment,
        SignatureRequest $amendingRequest,
        string $type = 'detected'
    ): void {
        try {
            $agentUser = $template->creator;
            if (!$agentUser) {
                return;
            }

            $documentName = $template->document->name ?? 'Document';
            $reviewUrl = route('docuperfect.signatures.review', $template->document_id);

            // In-app notification only — no email to agents
            $agentUser->notify(SignatureActivityNotification::amendmentDetected(
                $documentName, $template->document_id, $reviewUrl,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send agent amendment notification', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get all amendments for a template with their acceptance status.
     */
    public function getAmendmentsWithStatus(SignatureTemplate $template): array
    {
        $amendments = $template->amendments()
            ->with(['amendedByRequest', 'acceptances.signingRequest'])
            ->orderBy('created_at', 'desc')
            ->get();

        return $amendments->map(function ($amendment) {
            $acceptances = $amendment->acceptances->map(function ($acc) {
                return [
                    'id' => $acc->id,
                    'signer_name' => $acc->signingRequest->signer_name ?? 'Unknown',
                    'party_role' => $acc->signingRequest->party_role ?? '',
                    'accepted' => $acc->accepted,
                    'rejected' => $acc->rejected,
                    'rejection_reason' => $acc->rejection_reason,
                    'has_initial' => !empty($acc->initial_image),
                    'created_at' => $acc->created_at?->format('Y-m-d H:i'),
                ];
            });

            return [
                'id' => $amendment->id,
                'type' => $amendment->amendment_type,
                'section' => $amendment->section_reference,
                'original_text' => $amendment->original_text,
                'new_text' => $amendment->new_text,
                'status' => $amendment->status,
                'amended_by' => $amendment->amendedByRequest->signer_name ?? 'Unknown',
                'amended_by_role' => $amendment->amendedByRequest->party_role ?? '',
                'version_before' => $amendment->document_version_before,
                'version_after' => $amendment->document_version_after,
                'created_at' => $amendment->created_at?->format('Y-m-d H:i'),
                'acceptances' => $acceptances,
            ];
        })->toArray();
    }
}
