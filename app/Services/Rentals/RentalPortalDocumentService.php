<?php

namespace App\Services\Rentals;

use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Services\ClientAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * .ai/specs/rental-portal-access.md §19 — the tenant's and the owner's Documents area.
 *
 * Johan, 7 Oct 2026: "we are a bit shy on data on these links … we can put the lease agreement here, we can put the
 * inspection report here." One service builds the list for BOTH audiences and is the ONLY thing that decides which file a
 * portal person may open — the list and the file route share `findFor()`, so a document id that is not in a person's own
 * list is a 404 on the file route too (never a guess at a path, never a public URL).
 *
 * What is shown (and nothing else):
 *   1. LEASE AGREEMENTS — the SIGNED agreement (e-signed final PDF, or the uploaded wet-ink signed copy) of every live lease
 *      the person is a party to (tenant: their lease_tenants rows; owner: every lease on a property they own). A lease that is
 *      not signed is not shown, ever; a signed renewal is its own row; a cancelled/ended/renewed lease stays as history.
 *      An ARCHIVED lease is invisible to the portal — the standing rule (§6, 6 Oct 2026) — so its documents are too.
 *   2. INSPECTION REPORTS — only reports that have been DISTRIBUTED (never a draft, one in progress, one still in signing or
 *      a cancelled one): tenant = reports on their own lease(s), owner = reports on their own properties.
 *   3. Documents the agency has deliberately shared with the person (the pre-existing tenant/landlord_portal_visible flag
 *      + contact attachment) — unchanged, merged into the same list.
 * Soft-deleted documents never show. Every query pins agency_id and deleted_at explicitly (a portal request has no staff user,
 * so global scopes are stripped, exactly like RentalPortalScopeService).
 */
class RentalPortalDocumentService
{
    public const ROLE_TENANT = 'tenant';
    public const ROLE_LANDLORD = 'landlord';

    public const KIND_LEASE = 'lease_agreement';
    public const KIND_INSPECTION = 'inspection_report';
    public const KIND_SHARED = 'shared_document';

    public const KINDS = [self::KIND_LEASE => 'Lease agreement', self::KIND_INSPECTION => 'Inspection report', self::KIND_SHARED => 'Other document'];

    /** Rendered in the browser tab; everything else is forced to a download. */
    private const INLINE_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    private const INSPECTION_TYPE_LABELS = [
        RentalInspection::TYPE_IN => 'Move-in',
        RentalInspection::TYPE_OUT => 'Move-out',
        RentalInspection::TYPE_INTERIM => 'Interim',
        RentalInspection::TYPE_AD_HOC => 'Ad hoc',
    ];

    public function __construct(private readonly RentalPortalScopeService $scope)
    {
    }

    // ── What a person may see ────────────────────────────────────────────────────────────────

    /**
     * Every document this person may open, newest first, one row per document.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function rowsFor(string $role, Contact $contact): Collection
    {
        $tenant = $role === self::ROLE_TENANT;
        $leases = $tenant ? $this->scope->tenantLeases($contact) : $this->scope->landlordLeases($contact);
        $inspections = $tenant ? $this->scope->tenantInspections($contact) : $this->scope->landlordInspections($contact);
        $shared = $tenant ? $this->scope->tenantDocuments($contact) : $this->scope->landlordDocuments($contact);

        $properties = Property::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('id', $leases->pluck('property_id')->merge($inspections->pluck('property_id'))->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        $rows = collect();
        $leaseById = $leases->keyBy('id');

        foreach ($leases as $lease) {
            if (! $this->leaseIsSigned($lease)) {
                continue;
            }
            $document = $this->leaseDocument($lease, (int) $contact->agency_id);
            if ($document) {
                $rows->push($this->leaseRow($lease, $document, $properties->get($lease->property_id)));
            }
        }

        foreach ($inspections as $inspection) {
            // An inspection belongs to a lease. When that lease is archived (invisible to the portal, §6) its reports go with it —
            // for the owner too, whose own query is by property and would otherwise still reach it.
            if (! $leaseById->has($inspection->lease_id) || ! $this->inspectionIsShareable($inspection)) {
                continue;
            }
            $document = $this->inspectionDocument($inspection, (int) $contact->agency_id);
            if ($document) {
                $rows->push($this->inspectionRow($inspection, $document, $properties->get($inspection->property_id), $leaseById->get($inspection->lease_id)));
            }
        }

        foreach ($shared as $document) {
            $rows->push($this->sharedRow($document));
        }

        // One row per document: a filed e-sign PDF that is also flagged "shared" appears once, as the lease agreement.
        return $rows->unique('id')->sortByDesc('date_sort')->values();
    }

    /** The one authorised lookup: the row for $documentId, or null when it is not in this person's own list. */
    public function findFor(string $role, Contact $contact, int $documentId): ?array
    {
        return $this->rowsFor($role, $contact)->firstWhere('id', $documentId);
    }

    // ── Search / sort / filter / pagination (BUILD_STANDARD §1a) ─────────────────────────────

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @param  array{q?:?string,type?:?string,from?:?string,to?:?string,sort?:?string,dir?:?string,page?:mixed,per_page?:mixed}  $params
     * @param  string  $fileRoute  the route name of the authorised file route for this audience (each row gets its view + download link)
     * @return array{documents:array<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    public function query(Collection $rows, array $params, string $fileRoute): array
    {
        $total = $rows->count();
        $q = mb_strtolower(trim((string) ($params['q'] ?? '')));
        $type = (string) ($params['type'] ?? '');
        $from = $this->parseDate($params['from'] ?? null);
        $to = $this->parseDate($params['to'] ?? null);

        $filtered = $rows->filter(function (array $r) use ($q, $type, $from, $to) {
            if ($type !== '' && $r['kind'] !== $type) {
                return false;
            }
            if ($q !== '' && ! str_contains(mb_strtolower($r['name'] . ' ' . $r['type'] . ' ' . ($r['subtype'] ?? '') . ' ' . ($r['belongs_to']['address'] ?? '') . ' ' . ($r['belongs_to']['lease_label'] ?? '')), $q)) {
                return false;
            }
            if ($from && $r['date_sort'] < $from->startOfDay()->timestamp) {
                return false;
            }
            if ($to && $r['date_sort'] > $to->endOfDay()->timestamp) {
                return false;
            }

            return true;
        });

        $sort = in_array($params['sort'] ?? '', ['date', 'name', 'type'], true) ? $params['sort'] : 'date';
        $dir = ($params['dir'] ?? ($sort === 'date' ? 'desc' : 'asc')) === 'asc' ? 'asc' : 'desc';
        $sorted = $filtered->sortBy(fn (array $r) => match ($sort) {
            'name' => mb_strtolower($r['name']),
            'type' => mb_strtolower($r['type'] . ' ' . ($r['subtype'] ?? '')),
            default => $r['date_sort'],
        }, SORT_REGULAR, $dir === 'desc')->values();

        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 25)));
        $pages = max(1, (int) ceil($sorted->count() / $perPage));
        $page = max(1, min($pages, (int) ($params['page'] ?? 1)));

        return [
            'documents' => $sorted->slice(($page - 1) * $perPage, $perPage)->map(fn (array $r) => $this->publicShape($r, $fileRoute))->values()->all(),
            'meta' => [
                'total' => $filtered->count(), 'all_total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage,
                'sort' => $sort, 'dir' => $dir, 'q' => $q === '' ? null : $q, 'type' => $type === '' ? null : $type,
                'from' => $params['from'] ?? null, 'to' => $params['to'] ?? null,
                'kinds' => self::KINDS,
            ],
        ];
    }

    // ── Serving the file ─────────────────────────────────────────────────────────────────────

    /**
     * Stream one authorised document (inline for PDF/images when viewing, an attachment otherwise or when downloading) and
     * leave an audit row. Returns null when the file is not in this person's list or is missing from disk.
     */
    public function respond(string $role, Contact $contact, ClientUser $client, int $documentId, bool $download, Request $request): ?Response
    {
        $row = $this->findFor($role, $contact, $documentId);
        /** @var Document|null $document */
        $document = $row['_document'] ?? null;
        if (! $document || ! $document->storage_path) {
            return null;
        }
        $disk = Storage::disk($document->disk ?: 'local');
        if (! $disk->exists($document->storage_path)) {
            return null;
        }

        $mime = $document->mime_type ?: ($disk->mimeType($document->storage_path) ?: 'application/octet-stream');
        $inline = ! $download && in_array(strtolower($mime), self::INLINE_MIMES, true);
        $headers = [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ];

        app(ClientAuthService::class)->log($client, (int) $contact->agency_id, $contact->id, $inline ? 'document_viewed' : 'document_downloaded', $request, [
            'document_id' => $document->id, 'kind' => $row['kind'], 'role' => $role,
        ]);

        $name = $this->downloadName($row, $document);

        return $inline
            ? $disk->response($document->storage_path, $name, $headers, 'inline')
            : $disk->download($document->storage_path, $name, $headers);
    }

    // ── Rows ─────────────────────────────────────────────────────────────────────────────────

    /** A lease is signed when it was signed and accepted, signed on paper, or created from a completed signed agreement. */
    public function leaseIsSigned(Lease $lease): bool
    {
        return $lease->signed_at !== null
            || in_array($lease->signing_status, [Lease::SIGNING_SIGNED, Lease::SIGNING_SIGNED_ON_PAPER], true)
            || in_array($lease->source, [Lease::SOURCE_ESIGN_DOCUMENT, Lease::SOURCE_UPLOADED_SIGNED_COPY], true);
    }

    /**
     * The same document Lease::signedDocument() names (the filed e-sign copy of the lease's envelope, else the attached
     * signed paper copy), read scope-free and pinned to the agency because a portal request has no staff user.
     */
    private function leaseDocument(Lease $lease, int $agencyId): ?Document
    {
        $base = fn () => Document::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at');

        if ($lease->signature_template_id) {
            $filed = $base()->where('source_type', 'esign')->where('source_id', $lease->signature_template_id)->latest('id')->first();
            if ($filed) {
                return $filed;
            }
        }

        return $base()->where('source_type', 'lease')->where('source_id', $lease->id)->latest('id')->first();
    }

    /**
     * "Distributed" is cc6's definition and nobody else's (rental-inspections.md §47): RentalInspection::isDistributed() — the
     * moment copies are sent, i.e. Completed, or an email copy logged as sent to a tenant or landlord. The portal asks the model;
     * it keeps no rule of its own. A cancelled inspection is never shown (its public link is dead too, §44a).
     */
    private function inspectionIsShareable(RentalInspection $inspection): bool
    {
        return $inspection->status !== RentalInspection::STATUS_CANCELLED && $inspection->isDistributed();
    }

    /** The signed report filed by SignedDocumentDistributionService::fileToProperty() (source `rental_inspection_report`). */
    private function inspectionDocument(RentalInspection $inspection, int $agencyId): ?Document
    {
        return Document::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('source_type', $inspection->distributionSourceType())
            ->where('source_id', $inspection->distributionSourceId())
            ->latest('id')
            ->first();
    }

    private function leaseRow(Lease $lease, Document $document, ?Property $property): array
    {
        $address = $property?->buildDisplayAddress() ?: ('Property #' . $lease->property_id);
        $renewal = $lease->previous_lease_id !== null;
        $date = $lease->signed_at ?? $document->created_at;

        return $this->row($document, self::KIND_LEASE, $renewal ? 'Lease renewal' : 'Lease agreement', $renewal ? 'Renewal' : null,
            ($renewal ? 'Lease renewal' : 'Lease agreement') . " — {$address}", $date, $property, $lease);
    }

    private function inspectionRow(RentalInspection $inspection, Document $document, ?Property $property, ?Lease $lease): array
    {
        $address = $property?->buildDisplayAddress() ?: ('Property #' . $inspection->property_id);
        $label = self::INSPECTION_TYPE_LABELS[$inspection->type] ?? 'Inspection';

        return $this->row($document, self::KIND_INSPECTION, 'Inspection report', $label,
            "{$label} inspection report — {$address}", $inspection->completed_at ?? $document->created_at, $property, $lease);
    }

    private function sharedRow(Document $document): array
    {
        return $this->row($document, self::KIND_SHARED, $document->documentType?->name ?: 'Document', null,
            $document->original_name ?: 'Document', $document->created_at, null, null);
    }

    private function row(Document $document, string $kind, string $type, ?string $subtype, string $name, $date, ?Property $property, ?Lease $lease): array
    {
        $leaseLabel = null;
        if ($lease) {
            $leaseLabel = $lease->is_month_to_month || ! $lease->end_date
                ? 'Lease from ' . $lease->start_date?->format('j M Y')
                : 'Lease ' . $lease->start_date?->format('j M Y') . ' – ' . $lease->end_date->format('j M Y');
        }

        return [
            'id' => $document->id,
            'kind' => $kind,
            'type' => $type,
            'subtype' => $subtype,
            'name' => $name,
            'file_name' => $document->original_name,
            'date' => $date?->toIso8601String(),
            'date_sort' => $date?->timestamp ?? 0,
            'mime' => $document->mime_type,
            'size' => $document->size,
            'belongs_to' => [
                'property_id' => $property?->id,
                'address' => $property?->buildDisplayAddress(),
                'lease_id' => $lease?->id,
                'lease_label' => $leaseLabel,
            ],
            '_document' => $document,
        ];
    }

    /** What the API returns: no model, no sort helper, plus the two authorised (session- or token-protected) links. */
    private function publicShape(array $r, string $fileRoute): array
    {
        $url = route($fileRoute, ['document' => $r['id']], false);
        unset($r['_document'], $r['date_sort']);

        return $r + ['uploaded_at' => $r['date'], 'view_url' => $url, 'download_url' => $url . '?download=1'];
    }

    private function downloadName(array $row, Document $document): string
    {
        $ext = pathinfo((string) ($document->original_name ?: $document->storage_path), PATHINFO_EXTENSION) ?: 'pdf';
        $base = trim((string) preg_replace('/[^A-Za-z0-9 ,._()-]+/', '', (string) $row['name']));

        return ($base !== '' ? $base : 'document') . '.' . strtolower($ext);
    }

    private function parseDate(mixed $value): ?\Carbon\Carbon
    {
        try {
            return $value ? \Carbon\Carbon::parse((string) $value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
