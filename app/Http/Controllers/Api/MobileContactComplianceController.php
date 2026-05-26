<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mobile API — Contact compliance surface.
 *
 * Mirrors three sections of the web Contact page (resources/views/corex/contacts/show.blade.php)
 * for the mobile app:
 *   1. Consent  — POPIA/CPA consent records ("consent to sync to the app")
 *   2. Drive    — contact documents grouped by property + document-type catalog + link-to-property
 *   3. FICA     — FICA compliance status, submissions and legacy signed docs
 *
 * Auth: auth:sanctum (corex-mobile token). Ownership mirrors MobileContactController:
 * the contact must belong to the authenticated agent.
 */
class MobileContactComplianceController extends Controller
{
    /** The 7 consent types (must stay in sync with the web ContactController + migration enum). */
    private const CONSENT_TYPES = [
        'fica_processing'          => 'FICA Processing',
        'marketing_communications' => 'Marketing Communications',
        'data_sharing'             => 'Data Sharing',
        'channel_email'            => 'Email Channel',
        'channel_sms'              => 'SMS Channel',
        'channel_whatsapp'         => 'WhatsApp Channel',
        'channel_call'             => 'Phone Call Channel',
    ];

    private const CONSENT_RULE = 'required|in:fica_processing,marketing_communications,data_sharing,channel_email,channel_sms,channel_whatsapp,channel_call';

    // ════════════════════════════════════════════════════════════════
    // 1. CONSENT
    // ════════════════════════════════════════════════════════════════

    /** GET /api/mobile/contacts/{contact}/consent */
    public function consentIndex(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeContact($request->user(), $contact);

        $records = $contact->consentRecords()->with(['givenBy:id,name', 'revokedBy:id,name'])->get();

        $types = [];
        foreach (self::CONSENT_TYPES as $key => $label) {
            $active = $records->firstWhere(fn ($r) => $r->consent_type === $key && $r->revoked_at === null);
            $types[] = [
                'consent_type' => $key,
                'label'        => $label,
                'is_active'    => (bool) $active,
                'given_at'     => $active?->given_at?->toIso8601String(),
                'given_by'     => $active?->givenBy?->name,
                'method'       => $active?->method,
            ];
        }

        return response()->json([
            'consent' => $types,
            'history' => $records->map(fn ($r) => [
                'id'            => $r->id,
                'consent_type'  => $r->consent_type,
                'label'         => self::CONSENT_TYPES[$r->consent_type] ?? $r->consent_type,
                'method'        => $r->method,
                'given_at'      => $r->given_at?->toIso8601String(),
                'given_by'      => $r->givenBy?->name,
                'revoked_at'    => $r->revoked_at?->toIso8601String(),
                'revoked_by'    => $r->revokedBy?->name,
                'revoked_reason'=> $r->revoked_reason,
            ])->values(),
        ]);
    }

    /** POST /api/mobile/contacts/{contact}/consent  — record consent */
    public function consentRecord(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeContact($request->user(), $contact);

        $data = $request->validate([
            'consent_type' => self::CONSENT_RULE,
            'method'       => 'nullable|in:verbal,written,electronic,signed_document',
        ]);

        $contact->recordConsent(
            $data['consent_type'],
            $data['method'] ?? 'electronic',
            $request->user()->id
        );

        // Keep channel opt-out columns in sync (matches web behaviour)
        if (method_exists($contact, 'recomputeChannelConsent')) {
            $contact->recomputeChannelConsent();
        }

        return $this->consentIndex($request, $contact->fresh());
    }

    /** POST /api/mobile/contacts/{contact}/consent/revoke */
    public function consentRevoke(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeContact($request->user(), $contact);

        $data = $request->validate([
            'consent_type' => self::CONSENT_RULE,
            'reason'       => 'nullable|string|max:500',
        ]);

        $contact->revokeConsent($data['consent_type'], $request->user()->id, $data['reason'] ?? null);

        if (method_exists($contact, 'recomputeChannelConsent')) {
            $contact->recomputeChannelConsent();
        }

        return $this->consentIndex($request, $contact->fresh());
    }

    // ════════════════════════════════════════════════════════════════
    // 2. DRIVE (documents)
    // ════════════════════════════════════════════════════════════════

    /** GET /api/mobile/contacts/{contact}/drive */
    public function driveIndex(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeContact($request->user(), $contact);

        $contact->load(['documents.documentType', 'documents.uploader:id,name', 'documents.properties', 'properties']);

        $linkedGroups = [];
        $unlinked     = [];
        foreach ($contact->documents as $doc) {
            $prop = $doc->properties->first();
            $row  = $this->shapeDocument($doc);
            if ($prop) {
                $linkedGroups[$prop->id]['property'] = [
                    'id'      => $prop->id,
                    'address' => $prop->buildDisplayAddress(),
                ];
                $linkedGroups[$prop->id]['documents'][] = $row;
            } else {
                $unlinked[] = $row;
            }
        }

        return response()->json([
            'linked_groups' => array_values($linkedGroups),
            'unlinked'      => $unlinked,
            // Catalog the picker UI needs: all active document types + this contact's properties
            'document_types' => DocumentType::where('is_active', true)
                ->orderBy('sort_order')->orderBy('label')
                ->get(['id', 'slug', 'label']),
            'properties' => $contact->properties->map(fn ($p) => [
                'id'      => $p->id,
                'address' => $p->buildDisplayAddress(),
                'role'    => $p->pivot->role ?? null,
            ])->values(),
        ]);
    }

    /** POST /api/mobile/contacts/{contact}/drive  — upload a document */
    public function driveStore(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeContact($request->user(), $contact);

        $request->validate([
            'file'             => 'required|file|max:20480',
            'document_type_id' => 'nullable|exists:document_types,id',
            'property_id'      => 'nullable|exists:properties,id',
        ]);

        $file = $request->file('file');
        $ext  = $file->getClientOriginalExtension();
        $path = $file->storeAs(
            'contact-documents/' . $contact->id,
            Str::uuid() . ($ext ? ".{$ext}" : ''),
            'local'
        );

        $doc = Document::create([
            'original_name'    => $file->getClientOriginalName(),
            'storage_path'     => $path,
            'disk'             => 'local',
            'mime_type'        => $file->getMimeType(),
            'size'             => $file->getSize(),
            'document_type_id' => $request->input('document_type_id') ?: null,
            'source_type'      => 'upload',
            'uploaded_by'      => $request->user()->id,
        ]);

        $doc->contacts()->attach($contact->id);

        if ($request->filled('property_id')) {
            $doc->properties()->attach($request->input('property_id'));
        }

        return response()->json([
            'document' => $this->shapeDocument($doc->fresh(['documentType', 'uploader:id,name', 'properties'])),
        ], 201);
    }

    /** PUT /api/mobile/contacts/{contact}/drive/{document}  — re-tag / link-to-property */
    public function driveUpdate(Request $request, Contact $contact, Document $document): JsonResponse
    {
        $this->authorizeContact($request->user(), $contact);
        abort_unless($document->contacts()->where('contacts.id', $contact->id)->exists(), 404, 'Document not on this contact.');

        $request->validate([
            'document_type_id' => 'nullable|exists:document_types,id',
            'property_id'      => 'nullable|exists:properties,id',
        ]);

        $document->update(['document_type_id' => $request->input('document_type_id') ?: null]);

        $newPropertyId = $request->input('property_id') ?: null;
        $document->properties()->sync($newPropertyId ? [$newPropertyId] : []);

        return response()->json([
            'document' => $this->shapeDocument($document->fresh(['documentType', 'uploader:id,name', 'properties'])),
        ]);
    }

    /** GET /api/mobile/contacts/{contact}/drive/{document}/download */
    public function driveDownload(Request $request, Contact $contact, Document $document): StreamedResponse
    {
        $this->authorizeContact($request->user(), $contact);
        abort_unless($document->contacts()->where('contacts.id', $contact->id)->exists(), 404, 'Document not on this contact.');

        return $document->downloadResponse();
    }

    /** DELETE /api/mobile/contacts/{contact}/drive/{document}  — soft delete */
    public function driveDestroy(Request $request, Contact $contact, Document $document): JsonResponse
    {
        $this->authorizeContact($request->user(), $contact);
        abort_unless($document->contacts()->where('contacts.id', $contact->id)->exists(), 404, 'Document not on this contact.');

        $document->contacts()->detach($contact->id);

        if ($document->contacts()->count() === 0 && $document->properties()->count() === 0) {
            $document->delete(); // SoftDeletes — recoverable by admin (no hard deletes)
        }

        return response()->json(['deleted' => true]);
    }

    // ════════════════════════════════════════════════════════════════
    // 3. FICA COMPLIANCE
    // ════════════════════════════════════════════════════════════════

    /** GET /api/mobile/contacts/{contact}/fica */
    public function ficaIndex(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeContact($request->user(), $contact);

        $status = $contact->ficaStatus(); // 'complete' | 'expiring' | 'incomplete'

        $submissions = $contact->ficaSubmissions()
            ->with(['documents', 'verifiedBy:id,name'])
            ->get()
            ->map(fn (FicaSubmission $s) => [
                'id'           => $s->id,
                'entity_type'  => $s->entity_type,
                'status'       => $s->status,
                'risk_rating'  => $s->risk_rating,
                'verified_by'  => $s->verifiedBy?->name,
                'verified_at'  => $s->verified_at?->toIso8601String(),
                'fica_expires_at' => $s->fica_expires_at?->toDateString(),
                'has_pdf'      => (bool) $s->pdf_path,
                'documents'    => $s->documents->map(fn ($d) => [
                    'id'            => $d->id,
                    'document_type' => $d->document_type,
                    'file_name'     => $d->file_name,
                    'status'        => $d->status,
                    'uploaded_at'   => $d->uploaded_at?->toIso8601String(),
                ])->values(),
            ])->values();

        // Legacy e-sign FICA documents (pivot document_type = 'fica')
        $legacy = $contact->ficaDocuments()->get()->map(fn ($d) => [
            'id'        => $d->id,
            'name'      => $d->name ?? $d->original_name ?? 'FICA Document',
            'signed_at' => optional($d->pivot->signed_at) ? \Carbon\Carbon::parse($d->pivot->signed_at)->toIso8601String() : null,
            'status'    => $d->pivot->status ?? null,
        ])->values();

        return response()->json([
            'status'      => $status,
            'status_label' => match ($status) {
                'complete' => 'Complete',
                'expiring' => 'Expiring Soon',
                default    => 'No FICA on File',
            },
            'submissions'    => $submissions,
            'legacy_documents' => $legacy,
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function authorizeContact(User $user, Contact $contact): void
    {
        abort_unless($contact->created_by_user_id === $user->id, 403, 'Not your contact.');
    }

    private function shapeDocument(Document $d): array
    {
        return [
            'id'            => $d->id,
            'original_name' => $d->original_name,
            'mime_type'     => $d->mime_type,
            'size'          => (int) $d->size,
            'human_size'    => $d->human_size ?? null,
            'is_image'      => method_exists($d, 'isImage') ? $d->isImage() : false,
            'source_type'   => $d->source_type,
            'document_type' => $d->documentType ? [
                'id'    => $d->documentType->id,
                'slug'  => $d->documentType->slug,
                'label' => $d->documentType->label,
            ] : null,
            'property_id'   => $d->properties->first()?->id,
            'uploaded_by'   => $d->uploader?->name,
            'created_at'    => $d->created_at?->toIso8601String(),
        ];
    }
}
