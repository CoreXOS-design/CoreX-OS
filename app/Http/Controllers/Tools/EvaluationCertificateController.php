<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\EvaluationCertificate;
use App\Models\Property;
use App\Models\Scopes\ContactScope;
use App\Services\ContactDuplicateService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Evaluation Certificate — Phase 1 foundation (/tools/cma redesign).
 * Spec: .ai/specs/EVALUATION_CERTIFICATE_REDESIGN.md
 *
 * Property search + prefill (item 2) and contact link (item 3). Mirrors the
 * DR2 party-picker pattern (Dr2\DealRegisterController::searchProperties /
 * propertyContacts / contactSearch / contactInline) exactly — same query
 * shape, same toSearchResult() primitives, same Match-or-Create contact
 * dedup — but gated on `access_calculators` (the /tools/cma screen's own
 * permission) instead of DR2's `deals.create`/`deals.edit`, since an agent
 * with CMA-tool access has no reason to also hold deal-creation rights.
 */
class EvaluationCertificateController extends Controller
{
    /**
     * Property typeahead — reuses Property::visibleTo()->searchAddress(),
     * the same canonical search primitive the DR2 picker and PDF splitter
     * both use, so results/ranking behave identically everywhere.
     */
    public function searchProperties(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('access_calculators'), 403);

        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $properties = Property::query()
            ->visibleTo($request->user())
            ->searchAddress($q)
            ->with('agent')
            ->latest()
            ->limit(15)
            ->get();

        $results = $properties->map(function (Property $p) {
            return $p->toSearchResult([
                'address'       => $p->buildDisplayAddress(),
                'ref'           => $p->property_number,
                'property_type' => $p->property_type,
                'price'         => $p->price,
                'beds'          => $p->beds,
                'baths'         => $p->baths,
                'garages'       => $p->garages,
            ]);
        });

        return response()->json($results);
    }

    /**
     * The linked property's seller/owner contact, for auto-prefilling the
     * evaluation certificate's contact field "when one exists" (spec item 3).
     * Listing-type-aware (rental -> landlord/lessor) via the same canonical
     * role maps DR2's propertyContacts() uses, so a rental's owner-side
     * party pulls through the same way it would on a deal capture.
     */
    public function propertyContact(Request $request, Property $property): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('access_calculators'), 403);
        abort_unless($property->visibleTo($request->user())->whereKey($property->id)->exists(), 404);

        $sellerRoles = Property::sellerSidePivotRolesForListingType($property->listing_type);

        $contact = $property->contacts()
            ->get()
            ->first(fn ($c) => in_array(strtolower((string) ($c->pivot->role ?? '')), $sellerRoles, true));

        if (! $contact) {
            return response()->json(['contact' => null]);
        }

        return response()->json(['contact' => [
            'id'    => $contact->id,
            'name'  => $contact->full_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ]]);
    }

    /**
     * Contact typeahead — the universal path when the linked property has no
     * seller/owner on file, or the certificate has no property link at all.
     * Same canonical Contact::search() + toSearchResult() the property-page
     * picker and DR2 use; agency-wide (bypasses 'own'/'branch' ContactScope)
     * per Non-Negotiable #10 — never steer the agent into creating a
     * duplicate because their personal scope can't see an existing contact.
     */
    public function searchContacts(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('access_calculators'), 403);

        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $rows = Contact::withoutGlobalScope(ContactScope::class)
            ->with(['phones', 'emails', 'type', 'agent'])
            ->search($q)
            ->limit(15)
            ->get()
            ->map(fn (Contact $c) => $c->toSearchResult($q, [
                'name'  => $c->full_name,
                'email' => $c->email,
                'phone' => $c->phone,
            ]));

        return response()->json($rows);
    }

    /**
     * Add-new contact inline — Match-or-Create (Non-Negotiable #10): an
     * existing contact matching phone/email is REUSED, never duplicated.
     * Does not link to a certificate here — the certificate save sets
     * contact_id directly. Returns {id, name}, or a 409 duplicate payload
     * when the agency's dedupe policy needs a human decision (same contract
     * as DR2's contactInline).
     */
    public function contactInline(Request $request): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('access_calculators'), 403);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'phone'      => ['nullable', 'string', 'max:30'],
            'email'      => ['nullable', 'email', 'max:150'],
            'bypass_duplicate_check' => ['nullable', 'boolean'],
        ]);

        $agencyId = (int) ($user->effectiveAgencyId() ?? 0);
        $bypass   = ! empty($data['bypass_duplicate_check']);
        unset($data['bypass_duplicate_check']);

        $service = app(ContactDuplicateService::class);
        if (! $bypass) {
            $dupes = $service->findDuplicates($data, $agencyId);
            if ($dupes->isNotEmpty()) {
                $mode = $service->resolveMode($agencyId);
                if ($mode === 'auto_link') {
                    $existing = $dupes->first();
                    return response()->json(['id' => $existing->id, 'name' => $existing->full_name, 'matched' => true]);
                }
                return response()->json([
                    'duplicate_detected' => [
                        'duplicates' => $dupes->map(fn ($c) => [
                            'id'    => $c->id,
                            'name'  => $c->full_name,
                            'phone' => $mode === 'hard_block_request' ? null : $c->phone,
                        ])->values()->all(),
                        'mode'         => $mode,
                        'can_override' => $mode === 'hard_block_override' && in_array($user->effectiveRole(), ['admin', 'super_admin', 'owner'], true),
                    ],
                ], 409);
            }
        }

        $data['created_by_user_id'] = $user->id;
        $contact = Contact::create($data);

        return response()->json(['id' => $contact->id, 'name' => $contact->full_name], 201);
    }

    /**
     * Output the evaluation certificate PDF (Phase 2/3 "Download", also serves "Print" via ?inline=1).
     *
     * - Signed + filed: streams the immutable artifact at signed_pdf_path (produced by
     *   cc1's Phase-4 sign flow) — the filed copy, never re-rendered.
     * - Draft/unsigned: renders a live preview with EMPTY signature slots.
     *
     * Filename is "evaluation-certificate-{id}.pdf" (terminology: evaluation, never valuation).
     */
    public function download(Request $request, EvaluationCertificate $certificate): Response
    {
        abort_unless(auth()->user()?->hasPermission('access_calculators'), 403);
        abort_unless((int) $certificate->agency_id === (int) (auth()->user()->effectiveAgencyId() ?? 0), 404);

        $filename = 'evaluation-certificate-' . $certificate->id . '.pdf';
        $inline   = $request->boolean('inline');

        if ($certificate->signed_pdf_path && Storage::exists($certificate->signed_pdf_path)) {
            return $inline
                ? Storage::response($certificate->signed_pdf_path, $filename, ['Content-Disposition' => 'inline; filename="' . $filename . '"'])
                : Storage::download($certificate->signed_pdf_path, $filename);
        }

        $pdf = $this->renderCertificatePdf($certificate);

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /**
     * Render the certificate to a dompdf PDF using the same engine/options as
     * SignaturePdfService (Barryvdh dompdf). Deliberately NOT routed through
     * SignaturePdfService itself — that service is SignatureTemplate-bound
     * (docuperfect) and must not be repurposed here (no docuperfect regression).
     *
     * SIGNATURE-BLOCK SLOTS (cc1 Phase-4): pass the saved-signature PNG data-URIs
     * (data:image/png;base64,...) to bake an immutable signed artifact at sign time.
     * Both null on the unsigned preview path.
     */
    public function renderCertificatePdf(EvaluationCertificate $certificate, ?string $signatureImage = null, ?string $initialImage = null): \Barryvdh\DomPDF\PDF
    {
        $pdf = Pdf::loadView('tools.evaluation-certificate.pdf', [
            'certificate'    => $certificate,
            'signatureImage' => $signatureImage,
            'initialImage'   => $initialImage,
        ])->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);

        return $pdf;
    }
}
