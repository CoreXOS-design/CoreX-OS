<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\EvaluationCertificate;
use App\Models\Property;
use App\Models\Scopes\ContactScope;
use App\Services\AgentSignatureService;
use App\Services\CandidatePractitionerService;
use App\Services\ContactDuplicateService;
use App\Support\Impersonation;
use App\Support\WhatsAppNumberFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
     * Persist a NEW evaluation certificate (spec item 2). The evaluation is its own
     * record: property_id/contact_id are LINKS, while the fields are an independent,
     * editable copy — prefilled from the property at creation time and never written
     * back to the source property.
     */
    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('access_calculators'), 403);

        $data = $this->validateCertificateInput($request);
        $data['agency_id']          = (int) ($user->effectiveAgencyId() ?? 0);
        $data['created_by_user_id'] = $user->id;
        $data['status']             = EvaluationCertificate::STATUS_DRAFT;

        $certificate = EvaluationCertificate::create($data);

        return response()->json($this->certificatePayload($certificate), 201);
    }

    /**
     * Update an existing, UNSIGNED certificate. A signed certificate is the immutable
     * legal artifact and can never be edited (409).
     */
    public function update(Request $request, EvaluationCertificate $certificate): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('access_calculators'), 403);
        abort_unless((int) $certificate->agency_id === (int) ($user->effectiveAgencyId() ?? 0), 404);
        abort_if($certificate->isAuthorised(), 409, 'A signed evaluation certificate cannot be edited.');

        $certificate->update($this->validateCertificateInput($request));

        return response()->json($this->certificatePayload($certificate->fresh()));
    }

    /**
     * Shared validation for store/update. Beds/baths/parking are integers (cc3's
     * tinyint columns). Any property/contact link is re-checked against what THIS
     * agency can actually see — a posted id is never trusted (Non-Negotiable #7).
     */
    private function validateCertificateInput(Request $request): array
    {
        $data = $request->validate([
            'address'                => ['required', 'string', 'max:255'],
            'property_type'          => ['nullable', 'string', 'max:100'],
            'analysis_date'          => ['nullable', 'date'],
            'estimated_market_value' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'bedrooms'               => ['nullable', 'integer', 'min:0', 'max:255'],
            'bathrooms'              => ['nullable', 'integer', 'min:0', 'max:255'],
            'parking'                => ['nullable', 'integer', 'min:0', 'max:255'],
            'key_features'           => ['nullable', 'string', 'max:5000'],
            'property_id'            => ['nullable', 'integer'],
            'contact_id'             => ['nullable', 'integer'],
        ]);

        if (! empty($data['property_id'])) {
            abort_unless(
                Property::query()->visibleTo($request->user())->whereKey($data['property_id'])->exists(),
                422, 'That property is not available.'
            );
        }
        if (! empty($data['contact_id'])) {
            $agencyId = (int) ($request->user()->effectiveAgencyId() ?? 0);
            abort_unless(
                Contact::withoutGlobalScope(ContactScope::class)->whereKey($data['contact_id'])->where('agency_id', $agencyId)->exists(),
                422, 'That contact is not available.'
            );
        }

        return $data;
    }

    /**
     * The certificate's linked contact, resolved WITHOUT the personal ContactScope
     * ('own'/'branch') but strictly within the certificate's agency. The link is
     * authoritative — often an auto-linked seller/owner created by someone else — so
     * it must resolve for display/share regardless of who is viewing (Non-Negotiable
     * #10 spirit: never re-surface a "no contact" just because personal scope can't
     * see it), while never crossing an agency boundary.
     */
    private function linkedContact(EvaluationCertificate $certificate): ?Contact
    {
        if (! $certificate->contact_id) {
            return null;
        }

        return Contact::withoutGlobalScope(ContactScope::class)
            ->where('agency_id', $certificate->agency_id)
            ->find($certificate->contact_id);
    }

    /** The JSON the /tools/cma screen needs back after a save/sign. */
    private function certificatePayload(EvaluationCertificate $certificate): array
    {
        return [
            'id'           => $certificate->id,
            'status'       => $certificate->status,
            'is_signed'    => $certificate->isAuthorised(),
            'signed_by'    => $certificate->signedBy?->name,
            'download_url' => route('tools.cma.evaluation.download', $certificate),
            'print_url'    => route('tools.cma.evaluation.download', $certificate) . '?inline=1',
        ];
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

    /**
     * Phase 4 — the ONE PIN-sign surface for the evaluation certificate.
     *
     * This is the FINALISING signature that produces the immutable authorised
     * artifact. It is reached two ways (cc5's Phase-4b state machine decides WHO
     * reaches it; this endpoint does not fork on that):
     *   • a full-status practitioner signing their own certificate directly, and
     *   • a full-status AUTHORISER accepting + signing a candidate's certificate.
     * Either way the signer must be full-status — a candidate cannot finalise alone.
     *
     * The saved signature/initial (encrypted, PIN-gated in AgentSignatureService)
     * are pulled SERVER-SIDE only and baked into cc6's dompdf render, so the filed
     * PDF is an immutable snapshot of the mark as it was at signing — unaffected by
     * any later change to the agent's My-Portal signature. Impersonation can never
     * reach the pixels (guarded here up-front AND inside the service).
     */
    public function sign(Request $request, EvaluationCertificate $certificate, AgentSignatureService $signatures, CandidatePractitionerService $practitioners): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('access_calculators'), 403);

        // Agency isolation — never sign another agency's certificate.
        abort_unless((int) $certificate->agency_id === (int) ($user->effectiveAgencyId() ?? 0), 404);

        // Impersonation hard-block: a switch-user session may never place a saved
        // signature. AgentSignatureService also guards internally; this is the clean
        // up-front 403 so we never even render.
        abort_if(Impersonation::actingAdminId() !== null, 403, 'Saved signatures are unavailable while acting as another user.');

        // Only a full-status practitioner may finalise. The candidate flow is cc5's
        // Phase-4b queue, which routes a full-status authoriser into THIS endpoint.
        abort_if($practitioners->isCandidate($user), 403, 'A candidate practitioner cannot finalise an evaluation certificate — it must be authorised by a full-status practitioner.');

        // Must have a saved signature + PIN set up (My Portal) to place one.
        abort_unless($signatures->isConfigured($user), 422, 'Set up your saved signature and signing PIN in My Portal before signing.');

        // A certificate is signed exactly once — the filed artifact is immutable.
        abort_if($certificate->isAuthorised(), 409, 'This evaluation certificate is already signed.');

        $contextKey = 'evalcert:' . $certificate->id;

        // Unlock: accept the signing PIN inline (verify + unlock), or honour an
        // unlock already established this session.
        $pin = (string) $request->input('pin', '');
        if ($pin !== '') {
            if (! $signatures->verifyPinAndUnlock($user, $pin, $contextKey)) {
                return response()->json(['message' => 'Incorrect signing PIN.'], 422);
            }
        } elseif (! $signatures->isUnlocked($user, $contextKey)) {
            return response()->json(['message' => 'Enter your signing PIN to place your saved signature.'], 422);
        }

        // Decrypted saved marks — server-side only, never echoed to the browser.
        $signatureImage = $signatures->image($user, 'signature', $contextKey);
        $initialImage   = $signatures->image($user, 'initial', $contextKey);

        // Stamp the signer before rendering so cc6's view shows signedBy->name.
        // setRelation avoids a stale/lazy null on the freshly-set foreign key.
        $certificate->signed_by_user_id = $user->id;
        $certificate->setRelation('signedBy', $user);
        // Ensure the filed PDF shows the linked contact even if it sits outside the
        // signer's personal contact scope (auto-linked seller/owner is common).
        $certificate->setRelation('contact', $this->linkedContact($certificate));
        // Candidate flow: a certificate that was queued for authorisation is now
        // being accepted+signed by a full-status authoriser — record them as such.
        if ($certificate->isPendingAuthorisation()) {
            $certificate->authorised_by_user_id = $user->id;
        }
        $certificate->status = EvaluationCertificate::STATUS_AUTHORISED;

        // Bake the immutable signed PDF and file it (Phase-5 picks up signed_pdf_path
        // to attach to the property drive; download() streams this exact artifact).
        $pdf  = $this->renderCertificatePdf($certificate, $signatureImage, $initialImage);
        $path = 'evaluation-certificates/' . (int) $certificate->agency_id . '/' . $certificate->id . '-signed.pdf';
        Storage::put($path, $pdf->output());
        $certificate->signed_pdf_path = $path;

        $certificate->save();

        // One certificate, one signature — drop the unlock; nothing lingers unlocked.
        $signatures->lock($user, $contextKey);

        return response()->json([
            'ok'           => true,
            'status'       => $certificate->status,
            'signed_by'    => $user->name,
            'download_url' => route('tools.cma.evaluation.download', $certificate),
        ]);
    }

    /**
     * Share metadata for the "Share via WhatsApp" action (Phase 3). Returns the
     * LINKED contact's deep-link WhatsApp number, a PUBLIC signed link the client
     * can actually open (the Download route is agent-only), and the personalised
     * message. Recording of the send reuses the contact page's increment/mark-sent
     * (the standard did-you-send model — same as Core Matches, AT-323).
     */
    public function shareMeta(Request $request, EvaluationCertificate $certificate): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('access_calculators'), 403);
        abort_unless((int) $certificate->agency_id === (int) ($user->effectiveAgencyId() ?? 0), 404);

        $contact = $this->linkedContact($certificate);
        if (! $contact) {
            return response()->json(['message' => 'Link a contact before sharing.'], 422);
        }

        $waPhoneRecord = $contact->whatsAppPhone();
        $waPhone = WhatsAppNumberFormatter::forDeepLink(
            $waPhoneRecord?->phone ?? $contact->phone,
            $waPhoneRecord?->dial_code ?? $contact->primaryPhone?->dial_code
        );

        // A time-limited signed URL — the only way a non-agent can open the certificate.
        $shareUrl  = URL::temporarySignedRoute('tools.cma.evaluation.public', now()->addDays(30), ['certificate' => $certificate->id]);
        $firstName = $contact->first_name ?: $contact->full_name;
        $message   = "Hi {$firstName}!\n\nHere is your property evaluation certificate:\n{$shareUrl}\n\nPlease reach out if you have any questions.";

        return response()->json([
            'contact_id'     => $contact->id,
            'wa_phone'       => $waPhone,
            'share_url'      => $shareUrl,
            'message'        => $message,
            'increment_url'  => route('corex.contacts.increment', $contact),
            'mark_sent_base' => url('corex/contacts/' . $contact->id . '/communications'),
        ]);
    }

    /**
     * PUBLIC certificate view — reachable only via a valid temporary SIGNED URL
     * (the 'signed' middleware 403s otherwise). Streams the filed signed artifact
     * when present, else a live preview. This is what a shared client link opens.
     */
    public function publicView(Request $request, EvaluationCertificate $certificate): Response
    {
        $filename = 'evaluation-certificate-' . $certificate->id . '.pdf';

        if ($certificate->signed_pdf_path && Storage::exists($certificate->signed_pdf_path)) {
            return Storage::response($certificate->signed_pdf_path, $filename, ['Content-Disposition' => 'inline; filename="' . $filename . '"']);
        }

        $certificate->setRelation('contact', $this->linkedContact($certificate));

        return $this->renderCertificatePdf($certificate)->stream($filename);
    }
}
